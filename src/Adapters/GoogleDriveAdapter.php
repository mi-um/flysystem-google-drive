<?php
namespace mium\GoogleDrive\Adapters;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Config;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Util;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use Psr\Http\Message\RequestInterface;
use Google_Service_Drive;
use Google_Service_Drive_Drive;
use Google_Service_Drive_DriveFile;
use Google_Service_Drive_FileList;
use GuzzleHttp;

/**
 * The Google Drive Adapter to use with the FlySystem storage.
 * Instead of having to know the object ID withing Google Drive, it acts more
 * like a "regular" Storage where one can address files and directories directly.
 * It is assumed that each file and directory exist only once. So the "feature"
 * within Google Drive that files and folders can exist more than once is NOT
 * supported by decission.
 *
 * @author umschlag
 * @todo Decide if Google Exceptions shall be caught here?
 * @todo To treat a shared drive as a file / direcotory like others sounds convenient.
 *       However, is it worth the hassle? It requires some juggling here...
 * @todo Test if / how a subdirectory of a SharedDrive can be used as root
 * @todo Implement getVisibilty and setVisibility
 * @todo Implment updating an existing file to 0 length
 */
class GoogleDriveAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;
    
    /**
     * The mime-type GDrive uses for directories
     *
     * @var string
     */
    const GDRIVE_MIMETYPE_DIR = 'application/vnd.google-apps.folder';
    
    /**
     * The ID used for the / directory.
     * It is treated a bit differently because
     * on / level there only exist Drives and no files are directories.
     * This "root" has nothing to do with the "root" directory Google Drive
     * support via their API. Instead this here is just used internally to
     * organized tree, caches, and the like.
     *
     * @todo it's probably better to set this to the pathPrefix. That way it is
     *       probably easier to also support a folder ID from GDrive as root.
     *
     * @var string
     */
    const DIRECTORY_ID_ROOT = 'root';
    
    /**
     * The array key used within the cached objects to hold the children.
     * Those children also prepresent the content of a directory.
     *
     * @var string
     */
    const CACHE_ID_CHILDREN = 'children';
    
    /**
     * The GDrive documentation says they allow up to 5MB for a file upload.
     *
     * @var integer
     */
    const GDRIVE_MAX_UPLOAD = 5242880;
    
    /**
     * Google_Service_Drive instance
     *
     * @var Google_Service_Drive
     */
    protected $service = null;
    
    /**
     * The options passed to the Storage Adapter
     *
     * @var Config
     */
    protected $config = null;
    
    /**
     * The request parameters to call the file API
     *
     * @var array
     */
    protected $gdFileParams = [
        //'enforceSingleParent' => true,
        'supportsAllDrives' => true,
        'fields' => '*'
    ];
    
    /**
     *
     * @param Google_Service_Drive $service
     * @param array $config
     */
    public function __construct(Google_Service_Drive $service, Config $config)
    {
        // Keep our service instance
        $this->setService($service);
        
        // Was a different root given?
        $this->setPathPrefix($config->get('folderId'));
        
        // Keep the config handy
        $this->config = $config;
    }
    
    /**
     * *************************************************************************
     * Implementations for League\Flysystem\ReadInterface
     * *************************************************************************
     */
    
    /**
     * Check whether a file exists.
     *
     * {@inheritdoc}
     * @see \League\Flysystem\ReadInterface::has()
     */
    public function has($path)
    {
        try {
            // Get the ID for the given path
            $id = $this->getFileIdByNameCache($path);
        } catch (FileNotFoundException $e) {
            Log::debug('Has file NOT', [
                'path' => $path
            ]);
            return false;
        }
        
        // That's all we need to decide if the path exists or not, don't we?
        Log::debug('Has file YES', [
            'path' => $path
        ]);
        return strlen($id) > 0;
    }
    
    /**
     * Read a file, get its content.
     *
     * {@inheritdoc}
     * @see \League\Flysystem\ReadInterface::read()
     */
    public function read($path)
    {
        // Get file object from GDrive, needed for the size
        $fileObj = $this->getFileObjByName($path);
        
        // Get the stream interface
        $response = $this->readStream($path)['stream'];
        
        // And return the content from that stream
        if (is_resource($response)) {
            if ($fileObj->getSize() > 0)
                return [
                    'contents' => fread($response, $fileObj->getSize())
                ];
                else
                    return [
                        'contents' => ''
                    ];
        }
        
        return false;
    }
    
    /**
     * The a stream to the given file
     *
     * {@inheritdoc}
     * @see \League\Flysystem\ReadInterface::readStream()
     */
    public function readStream($path)
    {
        // Get file object from GDrive
        $fileObj = $this->getFileObjByName($path);
        
        // Get the GuzzleStream
        $response = $this->gdGetStream($fileObj);
        
        // And return it as a resource
        return [
            'stream' => StreamWrapper::getResource($response)
        ];
    }
    
    /**
     * List contents of a directory.
     *
     * {@inheritdoc}
     * @see \League\Flysystem\ReadInterface::listContents()
     */
    public function listContents($directory = '', $recursive = false)
    {
        // The result that we enventually will find
        $result = [];
        
        // First get the ID for the requested directory
        $id = $this->getFileIdByNameCache($directory);
        
        // With the ID the content can be fetched
        $children = $this->getChildrenFromIdCache($id);
        
        // Beautify and flatten the result, go recursive if needed
        foreach ($children as $driveFile) {
            $result[] = $this->standardFileAttributes($driveFile);
            
            // Was a recursive result requested?
            if ($recursive == true && $this->isDir($driveFile)) {
                $result = array_merge($result, $this->listContents($driveFile->absname, $recursive));
            }
        }
        
        return $result;
    }
    
    /**
     * Get all the meta data of a file or directory.
     *
     * {@inheritdoc}
     * @see \League\Flysystem\ReadInterface::getMetadata()
     */
    public function getMetadata($path)
    {
        // First get / find the ID of the given file / directory
        $id = $this->getFileIdByNameCache($path);
        
        // Then get the information for that file / directory ID
        $object = $this->getFileObjByIdCache($id);
        
        // Flatten the object
        return $this->normalizeObject($object);
    }
    
    /**
     * Get the size of the given file.
     * If it does not exist, false is returned.
     * If the file does not have a size (e.g. a directory), 0 is returned.
     *
     * {@inheritdoc}
     * @see \League\Flysystem\ReadInterface::getSize()
     */
    public function getSize($path)
    {
        // Is already checked by the Flysystem adapter
        // if (false == $this->has($path))
        // return false;
        $meta = $this->getMetadata($path);
        if (array_key_exists('size', $meta))
            return [
                'size' => $meta['size']
            ];
            
            return 0;
    }
    
    /**
     * Get the mimetype of a file.
     * If the file does not exist, false is returned.
     * If the file does not have a mime-type, an empty array is returned.
     *
     * {@inheritdoc}
     * @see \League\Flysystem\ReadInterface::getMimetype()
     */
    public function getMimetype($path)
    {
        // Is already checked by the Flysystem adapter
        // if (false == $this->has($path))
        // return false;
        $meta = $this->getMetadata($path);
        if (array_key_exists('mimeType', $meta))
            return [
                'mimetype' => $meta['mimeType']
            ];
            
            return [];
    }
    
    /**
     * Get the last modified time of a file as a timestamp.
     *
     * {@inheritdoc}
     * @see \League\Flysystem\ReadInterface::getTimestamp()
     */
    public function getTimestamp($path)
    {
        // Is already checked by the Flysystem adapter
        // if (false == $this->has($path))
        // return false;
        $meta = $this->getMetadata($path);
        
        if (array_key_exists('modifiedTime', $meta))
            return [
                'timestamp' => strtotime($meta['modifiedTime'])
            ];
            
            // Shared drives do not have a modifiedTime...
            // if (array_key_exists('createdTime', $meta))
            // return [
            // 'timestamp' => strtotime($meta['createdTime'])
            // ];
            
            return [];
    }
    
    /**
     * *************************************************************************
     * The implementation for the League\Flysystem\AdapterInterface
     * ************************************************************************
     */
    
    /**
     * Write the given contents to the given path.
     *
     * {@inheritdoc}
     * @see \League\Flysystem\AdapterInterface::write()
     */
    public function write($path, $contents, Config $config)
    {
        // Create resource from the $contents to upload...
        $stream = GuzzleHttp\Psr7\stream_for($contents);
        // ... and create a resource from it...
        $resource = StreamWrapper::getResource($stream);
        // ... and pass the rest on to the writeStream function
        return $this->writeStream($path, $resource, $config);
    }
    
    /**
     *
     * {@inheritdoc}
     * @see \League\Flysystem\AdapterInterface::writeStream()
     */
    public function writeStream($path, $resource, Config $config)
    {
        // Get the information from the given path
        $pathinfo = Util::pathinfo($path);
        
        // Get the parent directory for the file
        $dirname = $pathinfo['dirname'];
        
        // If the parent does not exist yet, create it
        if (false == $this->has($dirname))
            $this->createDir($dirname, $config);
            
            // Get the parent
            $parent = $this->getFileObjByName($dirname);
            
            // League\Flysystem\Filesystem::write() already verified that the file
            // does not exist yet. So create a new one here.
            $fileObj = new \Google_Service_Drive_DriveFile();
            $fileObj->setName($pathinfo['basename']);
            $fileObj->setParents([
                $parent->getId()
            ]);
            
            // Create the file
            $result = $this->gdCreateFile($fileObj);
            
            // Let the parent know about the new born child
            if ($result instanceof \Google_Service_Drive_DriveFile) {
                $this->addChildrenToParentCache($parent, [
                    $result
                ]);
            } else {
                return false;
            }
            
            // If there is (big) content to upload, let the update method do that
            if (fstat($resource)['size'] > 0) {
                return $this->updateStream($path, $resource, $config);
            }
            
            return true;
            
            // Do we need to upload content or is the file lenght 0?
            // That's mainly a work-around for Google_Http_MediaFileUpload which
            // cannot handle uploads of 0 length - which kind of makes sense.
            if (fstat($resource)['size'] < 1) {
                $this->getService()
                ->getClient()
                ->setDefer(false);
            } else {
                // For the (big) data upload we first only need a request object
                $this->getService()
                ->getClient()
                ->setDefer(true);
                $request = $this->gdCreateFile($fileObj);
                // Then upload the (big) data using the file-request object
                $result = $this->gdUploadFile($request, $resource);
            }
            
            // Get a RequestInterface instance to create the file
            // $request = $this->getService()->files->create($fileObj, $meta);
            
            // Update the content to the file
            
            if ($result instanceof \Google_Service_Drive_DriveFile) {
                $this->addChildrenToParentCache($parent, [
                    $result
                ]);
                
                return true;
            }
            
            return false;
    }
    
    /**
     * Update the given file with the given content.
     *
     * {@inheritdoc}
     * @see \League\Flysystem\AdapterInterface::update()
     */
    public function update($path, $contents, Config $config)
    {
        // Create resource from the $contents to upload...
        $stream = GuzzleHttp\Psr7\stream_for($contents);
        // ... and create a resource from it...
        $resource = StreamWrapper::getResource($stream);
        // ... and pass the rest on to the updateStream function
        return $this->updateStream($path, $resource, $config);
    }
    
    /**
     * Update the given file with the content from the given resource.
     *
     * {@inheritdoc}
     * @see \League\Flysystem\AdapterInterface::updateStream()
     */
    public function updateStream($path, $resource, Config $config)
    {
        // Get the file object
        $fileObj = $this->getFileObjByName($path);
        
        // Create a new file object that is used for the update request
        $newFileObj = new \Google_Service_Drive_DriveFile();
        $newFileObj->setName($fileObj->getName());
        
        // The information of the resource handler (eg the size of the content)
        $fstat = fstat($resource);
        
        // If the content is empty, call only the update API
        if ($fstat['size'] < 1) {
            $result = $this->gdUpdateFile($fileObj->getId(), $newFileObj);
        } else {
            // Get an update file request object (set defer is set to true).
            $request = $this->gdUpdateFileRequest($fileObj->getId(), $newFileObj);
            // Then upload the (big) content
            $result = $this->gdUploadFile($request, $resource);
        }
        
        // Update the new file in the cache
        if ($result instanceof \Google_Service_Drive_DriveFile) {
            $parentObj = $this->getFileObjByIdCache(current($fileObj->getParents()));
            $this->addChildrenToParentCache($parentObj, [
                $this->gdGetFileById($result->getId())
            ]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Rename (move) the old file to the new one.
     * The League\Flysystem\Filesystem already makes sure they (not) exist.
     *
     * {@inheritdoc}
     * @see \League\Flysystem\AdapterInterface::rename()
     */
    public function rename($path, $newpath)
    {
        // Get the information about the new path
        $newPathinfo = Util::pathinfo($newpath);
        
        // If the new parent folder does not exist, create it
        if (false == $this->has($newPathinfo['dirname']))
            $this->createDir($newPathinfo['dirname'], new Config());
            
            // Get meta information about the new parent
            $newFileObjParent = $this->getFileObjByName($newPathinfo['dirname']);
            
            // Get meta information about the old file
            $oldFileObj = $this->getFileObjByName($path);
            
            // Create a new fileObj (DriveFile) and set the new values
            $meta = [
                //'enforceSingleParent' => true,
                'supportsAllDrives' => true,
                'fields' => '*'
            ];
            $newFileObj = new \Google_Service_Drive_DriveFile();
            $newFileObj->setName($newPathinfo['basename']);
            
            // Does the parent have to get changed?
            if (false == in_array($newFileObjParent->getId(), $oldFileObj->getParents())) {
                $meta['addParents'] = $newFileObjParent->getId();
                $meta['removeParents'] = implode(',', $oldFileObj->getParents());
            }
            
            // Call GDrive to update the file
            $updatedFile = $this->getService()->files->update($oldFileObj->getId(), $newFileObj, $meta);
            
            // Update the caches
            if ($updatedFile instanceof \Google_Service_Drive_DriveFile) {
                
                // Also remove the child from the parent's cache
                foreach ($oldFileObj->getParents() as $parent) {
                    $this->removeChildrenFromParentCache($this->getFileObjByIdCache($parent), [
                        $oldFileObj->getId()
                    ]);
                }
                
                // Let the new parent know about its new child
                $this->addChildrenToParentCache($newFileObjParent, [
                    $updatedFile
                ]);
                
                // Remove the old file from the cache
                $this->cacheNames($oldFileObj->absname, '', true);
                
                return true;
            }
            
            return false;
    }
    
    /**
     * Copy a file to a new file
     *
     * {@inheritdoc}
     * @see \League\Flysystem\AdapterInterface::copy()
     */
    public function copy($path, $newpath)
    {
        // Get the object ID for the source file
        $srcObj = $this->getFileObjByName($path);
        
        // Get the parent folder for the new file object
        $pathinfo = Util::pathinfo($newpath);
        $desParObj = $this->getFileObjByName($pathinfo['dirname']);
        
        // Create a new file object
        $desObj = new \Google_Service_Drive_DriveFile();
        // ... and change the parent
        $desObj->setParents([
            $desParObj->getId()
        ]);
        // ... and set the new name
        $desObj->setName($pathinfo['basename']);
        // .. and copy other attributes
        $desObj->setAppProperties($srcObj->getAppProperties());
        $desObj->setCopyRequiresWriterPermission($srcObj->getCopyRequiresWriterPermission());
        $desObj->setDescription($srcObj->getDescription());
        $desObj->setModifiedTime($srcObj->getModifiedTime());
        $desObj->setProperties($srcObj->getProperties());
        $desObj->setWritersCanShare($srcObj->getWritersCanShare());
        
        // Call the GDrive API to run its magic
        $response = $this->getService()->files->copy($srcObj->getId(), $desObj,
            [
                //'enforceSingleParent' => true,
                'fields' => '*',
                'supportsAllDrives' => true
            ]);
        
        // If the response is right update our local cache and return true
        if ($response instanceof \Google_Service_Drive_DriveFile) {
            $this->addChildrenToParentCache($desParObj, [
                $response
            ]);
            return true;
        }
        
        // If the response is not a FileDrive instance something went wrong
        return false;
    }
    
    /**
     * Trash the given file.
     * If the config flag skipTrash is set to true, the file is deleted instead
     * but the result still needs to be tested.
     *
     * @todo test result of (permanent) delete.
     *
     * {@inheritdoc}
     * @see \League\Flysystem\AdapterInterface::delete()
     */
    public function delete($path)
    {
        // Get the file object
        $fileObj = $this->getFileObjByName($path);
        
        // The parent object
        $pareObj = $this->getFileObjByIdCache($fileObj->getParents()[0]);
        
        // If the trash shall be skipped, delete the file permanently
        if ($this->config->get('skipTrash')) {
            // @todo @fixme what's the result here?
            $result = $this->gdDelete($fileObj->getId());
        }
        
        // Create DriveFile with the trashed flag on
        $newObj = new \Google_Service_Drive_DriveFile();
        $newObj->setTrashed(true);
        
        $result = $this->gdUpdateFile($fileObj->getId(), $newObj);
        
        if ($result instanceof \Google_Service_Drive_DriveFile) {
            $this->cacheFiles($fileObj->getId(), '', true);
            $this->cacheNames($path, '', true);
            
            $this->removeChildrenFromParentCache($pareObj, [
                $fileObj->getId()
            ]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Trash the given directory (see delete()).
     *
     * {@inheritdoc}
     * @see \League\Flysystem\AdapterInterface::deleteDir()
     * @see GoogleDriveAdapter::delete()
     */
    public function deleteDir($dirname)
    {
        // Get the file object
        $fileObj = $this->getFileObjByName($dirname);
        
        // Delete it
        $result = $this->delete($dirname);
        
        // Let the cache know about the deleted files
        if ($result == true) {
            $this->removeChildrenFromParentCache($fileObj, [
                '*'
            ]);
        }
        
        return $result;
    }
    
    /**
     * Recursively create a directory
     *
     * {@inheritdoc}
     * @see \League\Flysystem\AdapterInterface::createDir()
     */
    public function createDir($dirname, Config $config)
    {
        // Is there anything to do?
        if ($this->has($dirname))
            return true;
            
            // Get the directories as array...
            $dirs = $this->splitPath($dirname);
            
            // ... and let's see how far we can get
            reset($dirs);
            $absdir = '';
            while ($this->has($absdir . current($dirs)) && current($dirs) !== false) {
                $absdir .= current($dirs) . $this->pathSeparator;
                next($dirs);
            }
            
            // ... and start creating directories from there
            $parObj = $this->getFileObjByName($absdir);
            
            // Create a new directory for each remaining
            while (current($dirs) !== false) {
                // The file object
                $newFile = new \Google_Service_Drive_DriveFile();
                $newFile->setParents([
                    $parObj->getId()
                ]);
                $newFile->setName(current($dirs));
                $newFile->setMimeType(self::GDRIVE_MIMETYPE_DIR);
                
                // Call the GDrive API to create the directory
                $newFile = $this->gdCreateFile($newFile);
                
                // What the absolute name of the new file
                $absdir .= current($dirs) . $this->pathSeparator;
                
                // Bring the new born child to its parent
                $this->addChildrenToParentCache($parObj, [
                    $newFile
                ]);
                
                // Move on to the next directory
                next($dirs);
                
                // The new directory is the new parent
                // $parObj = $this->getFileObjByName($absdir);
                $parObj = $newFile;
            }
            
            // Return if the new directory exists
            return $this->has($dirname);
    }
    
    /**
     * *************************************************************************
     * Methods that help to "abstract" the GDrive(s) and other useful methods
     * that are used in various other methods.
     * ************************************************************************
     */
    
    /**
     * Cache the given driveFile and / or return what's stored for that file ID.
     *
     * @param string $id
     * @param \Google_Service_Drive_Drive|\Google_Service_Drive_DriveFile $driveObject
     * @param bool $forget
     * @return \Google_Service_Drive_Drive|\Google_Service_Drive_DriveFile|null
     */
    protected function cacheFiles(string $id, $driveObject = null, bool $forget = false)
    {
        if ($this->cacheExpireGet() < 1) {
            return null;
        }
        
        $id = sprintf('%s-%s-%s', $this->cacheIdGet(), __FUNCTION__, $id);
        
        // If there was a new value given, add it to the cache
        if ($driveObject != null) {
            $this->cache()
            ->put($id, $driveObject, $this->cacheExpireGet());
        }
        
        // Shall the ID be forgotten?
        if ($forget == true) {
            $this->cache()
            ->forget($id);
        }
        
        // Get what shall be returned
        $driveObject = $this->cache()
        ->get($id);
        
        // Some debugging
        Log::debug('cacheFiles return', [
            'id' => $id,
            'isObject' => is_object($driveObject)
        ]);
        
        return $driveObject;
    }
    
    /**
     * Get the cached drives
     *
     * @param array $driveObjects
     * @return array
     */
    protected function cacheDrives(array $driveObjects = null): array
    {
        // If the cache is disabled return what was given
        if ($this->cacheExpireGet() < 1) {
            return [];
        }
        
        // The ID under which the drives are cached
        $id = sprintf('%s-%s', $this->cacheIdGet(), __FUNCTION__);
        
        // Store the new value if given
        if (is_array($driveObjects)) {
            $this->cache()
            ->put($id, $driveObjects, $this->cacheExpireGet());
        }
        
        // The drives to return
        $driveObjects = (array) $this->cache()
        ->get($id);
        
        // Some debugging
        Log::debug('cacheDrives return', [
            'drives' => $driveObjects
        ]);
        
        return $driveObjects;
    }
    
    /**
     * Cache the given ID under its given file name
     *
     * @param string $path
     * @param \Google_Service_Drive_Drive $id
     * @param bool $forget
     * @return string
     */
    protected function cacheNames(string $path, string $id = '', bool $forget = false): string
    {
        if ($this->cacheExpireGet() < 1) {
            return '';
        }
        
        // Get a unique ID
        // @todo the Drive name should be in there too
        $path = sprintf('%s-%s-%s', $this->cacheIdGet(), __FUNCTION__, $path);
        
        if ($id != '') {
            $this->cache()
            ->put($path, $id, $this->cacheExpireGet());
        }
        
        if ($forget == true) {
            $this->cache()
            ->forget($path);
        }
        
        // Update the ID with the new value
        $id = $this->cache()
        ->get($path, '');
        
        // Logging for debugging
        Log::debug('cacheNames return', [
            'path' => $path,
            'id' => $this->cache()
            ->get($path, '')
        ]);
        
        return (string) $id;
    }
    
    /**
     * Get the configured cache ID for this store adapter or a default ID.
     *
     * @return string
     */
    protected function cacheIdGet()
    {
        return $this->config->get('cacheId');
    }
    
    /**
     * Return the ID of cache to use (options.cacheStore) or empty string.
     * That should match a configured cache instance from Laravel and passed as
     * config options to this apdater (key cacheStore).
     *
     * @return string
     */
    protected function cacheStoreIdGet(): string
    {
        return $this->config->get('cacheStore');
    }
    
    /**
     * Recursively remove all files from the caches starting with the given ID.
     *
     * @param array $objIds
     */
    protected function cacheForget(array $objIds)
    {
        foreach ($objIds as $id) {
            $obj = $this->getFileObjByIdCache($id);
            
            // Forget all children...
            foreach ($this->getChildrenFromIdCache($obj->getId()) as $child) {
                $this->cacheNames($child->absname, '', true);
                $this->cacheFiles($child->getId(), '', true);
            }
            
            // ... and the parent itself
            $this->cacheNames($obj->absname, '', true);
            $this->cacheFiles($obj->getId(), '', true);
        }
    }
    
    /**
     * Get a cache instance
     *
     * @return \Illuminate\Contracts\Cache\Store
     */
    protected function cache()
    {
        // If there was a specific one configure get that
        return Cache::getStore($this->cacheStoreIdGet());
    }
    
    /**
     * Get the number of seconds after which cache entries shall expire.
     *
     * @return number
     */
    protected function cacheExpireGet()
    {
        return (int) $this->config->get('cacheExpire');
    }
    
    /**
     * Remove a possible separator from the end of the directory, except if the
     * directory is the separator itself.
     * For example:
     * '/dir1/subdir2/' => '/dir1/subdir2' (trailing slash removed)
     * '/dir1/subdir2' => '/dir1/subdir2' (all good - nothing changed)
     * '/' => '/' (root itself - not changed)
     *
     * If the directory is an empty string, make it the root directory.
     *
     * @param string $directory
     * @return string
     */
    protected function normalizeDirectory(string $directory): string
    {
        if ($directory == '')
            return $this->pathSeparator;
            
            return rtrim($directory, $this->pathSeparator);
    }
    
    /**
     * Return true if the given object is a "directory".
     * That can either be a
     * Shared Drive or a file with the according mime-type. False otherwise.
     *
     * @param \Google_Service_Drive_Drive|\Google_Service_Drive_DriveFile $driveFile
     * @return bool
     */
    protected function isDir(object $driveFile): bool
    {
        // Default false
        $isDir = false;
        
        // Treat Shared Drives as directories
        if ($driveFile instanceof \Google_Service_Drive_Drive)
            $isDir = true;
            elseif ($driveFile instanceof \Google_Service_Drive_DriveFile)
            if ($driveFile->getMimeType() == self::GDRIVE_MIMETYPE_DIR)
                $isDir = true;
                
                return $isDir;
    }
    
    /**
     * Get an array with all directories in the given path.
     * For example:
     * /dir1/sub2/subsub4 returns [0=>'/', 1=>'dir1', 2=>'sub2', 3=>'subsub4']
     *
     * @param string $path
     * @return array
     */
    protected function splitPath(string $path): array
    {
        $dirs = explode($this->pathSeparator, $path);
        return $dirs;
    }
    
    /**
     * *************************************************************************
     * Setter and getter for the Google APIClient Services.
     * Only the Drive Services are used but they come with the same bundle.
     * *************************************************************************
     */
    
    /**
     * Setter for the Google Service instance.
     * Probably useful to unit tests.
     *
     * @param Google_Service_Drive $service
     * @return \mium\GoogleDrive\Adapters\GoogleDriveAdapter
     */
    protected function setService(Google_Service_Drive $service)
    {
        $this->service = $service;
        return $this;
    }
    
    /**
     * Gets the service (Google_Service_Drive)
     *
     * @return object Google_Service_Drive
     */
    protected function getService()
    {
        return $this->service;
    }
    
    /**
     * *************************************************************************
     * Methods that perform the GDrive actions via the Google APIClient Services
     * *************************************************************************
     */
    
    /**
     * Call the Google Drive API and return available (shared) Drives
     *
     * @return \Google_Service_Drive_Drive[]
     */
    protected function gdGetDrives(): array
    {
        Log::info('gDrive requesting drive list');
        
        // The drives that will be returned
        $result = [];
        
        // Get the Drive Service instance and ask for all available drives
        $gDrives = $this->getService()->drives;
        
        if ($gDrives instanceof \Google_Service_Drive_Resource_Drives) {
            foreach ($gDrives->listDrives([
                'fields' => '*'
            ]) as $drive) {
                
                // Keep them all indexed by their name. Not sure (yet) if all
                // kept information will be needed but should not hurt too much
                // for now.
                $result[] = $drive;
            }
        }
        
        // Return what we got
        return $result;
    }
    
    /**
     * Get the meta data for the given file (or directory) ID
     *
     * @param string $id
     * @return Google_Service_Drive_DriveFile
     */
    protected function gdGetFileById(string $id)
    {
        Log::info('gDrive requesting file', [
            'id' => $id
        ]);
        
        // Get connection to the files endpoint
        $sFiles = $this->getService()->files;
        
        $fileObj = $sFiles->get($id, [
            'supportsAllDrives' => true,
            'fields' => '*'
        ]);
        return $fileObj;
    }
    
    /**
     * Call the files.list endpoint with the given query parameters.
     *
     * The 'driveId' must be given.
     * Default corpora is 'drive'.
     *
     * @param array $query
     * @return Google_Service_Drive_FileList
     */
    protected function gdListFiles(array $query = [])
    {
        Log::info('gDrive requesting file list', [
            'query' => $query
        ]);
        
        // Merge the given query parameters into some default ones.
        $query = array_merge(
            [
                'corpora' => 'drive',
                'driveId' => 'required-to-be-set',
                
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
                'fields' => '*'
            ], $query);
        
        // The service adapter
        $sFiles = $this->getService()->files;
        
        return $sFiles->listFiles($query);
    }
    
    /**
     * Create the directory on the Google Drive
     *
     * @param string $name
     * @param string $parentId
     *
     * @return Google_Service_Drive_DriveFile|false
     */
    protected function gdCreateFile(\Google_Service_Drive_DriveFile $fileObj): \Google_Service_Drive_DriveFile
    {
        Log::info('gDrive creating file', [
            'parents' => $fileObj->getParents(),
            'name' => $fileObj->getName()
        ]);
        
        // Keep the old defer status to set it back after the API call
        $oldDefer = $this->getService()
        ->getClient()
        ->shouldDefer();
        
        // The file shall be created right away
        $this->getService()
        ->getClient()
        ->setDefer(false);
        
        $result = $this->getService()->files->create($fileObj, $this->gdFileParams);
        
        // Reset the defer status to its old value
        $this->getService()
        ->getClient()
        ->setDefer($oldDefer);
        
        return $result;
    }
    
    /**
     *
     * @param \Google_Service_Drive_DriveFile $fileObj
     * @return\GuzzleHttp\Psr7\Stream
     */
    /**
     *
     * @param \Google_Service_Drive_DriveFile $fileObj
     * @return boolean|\GuzzleHttp\Psr7\Stream
     */
    protected function gdGetStream(\Google_Service_Drive_DriveFile $fileObj)
    {
        Log::info('gDrive getting stream for file', [
            'id' => $fileObj->id
        ]);
        
        $fileParams = $this->gdFileParams;
        $fileParams['acknowledgeAbuse'] = false;
        $fileParams['alt'] = 'media';
        $fileParams['uploadType'] = 'multipart';
        
        // Get a service instance
        $response = $this->getService()->files->get($fileObj->id, $fileParams);
        
        if ($response->getStatusCode() != 200)
            return false;
            
            return $response->getBody();
    }
    
    /**
     * Update the file with the given ID with the attributes from the given new
     * file object.
     *
     * @param string $id
     * @param \Google_Service_Drive_DriveFile $new
     * @return \Google_Service_Drive_DriveFile
     */
    protected function gdUpdateFile(string $id, \Google_Service_Drive_DriveFile $new): \Google_Service_Drive_DriveFile
    {
        Log::info('gDrive updating file', [
            'id' => $id
        ]);
        
        // Keep the defer status to set it back afterwards
        $oldDefer = $this->getService()
        ->getClient()
        ->shouldDefer();
        
        // The file shall be updated right away
        $this->getService()
        ->getClient()
        ->setDefer(false);
        
        // Create and get the DriveFile object
        $result = $this->getService()->files->update($id, $new, $this->gdFileParams);
        
        // Reset the old status
        $this->getService()
        ->getClient()
        ->setDefer($oldDefer);
        
        return $result;
    }
    
    /**
     * Get a request object that can be used to update the given file
     *
     * @param string $id
     * @param \Google_Service_Drive_DriveFile $new
     * @return Request
     */
    protected function gdUpdateFileRequest(string $id, \Google_Service_Drive_DriveFile $new): Request
    {
        // Keep the defer status to set it back afterwards
        $oldDefer = $this->getService()
        ->getClient()
        ->shouldDefer();
        
        // The file shall be updated right away
        $this->getService()
        ->getClient()
        ->setDefer(true);
        
        // Create and get the DriveFile object
        $result = $this->getService()->files->update($id, $new, $this->gdFileParams);
        
        // Reset the old status
        $this->getService()
        ->getClient()
        ->setDefer($oldDefer);
        
        return $result;
    }
    
    /**
     * Upload the content from the resource to the given sFile.
     *
     * @param RequestInterface $request
     * @param resource $resource
     * @throws \InvalidArgumentException
     * @return boolean
     */
    protected function gdUploadFile(RequestInterface $request, $resource)
    {
        Log::info('gDrive uploading file', [
            'body' => $request->getBody()
        ]);
        
        // Make sure we got a resource
        if (false == is_resource($resource))
            throw new \InvalidArgumentException('Argument is not a resource');
            
            // Get the Google_Client
            $client = $this->getService()
            ->getClient();
            
            // The client shall execute the request directly
            $client->setDefer(false);
            
            // Create an upload request
            $upload = new \Google_Http_MediaFileUpload($client, $request, '', '', true, self::GDRIVE_MAX_UPLOAD);
            $upload->setFileSize(fstat($resource)['size']);
            
            // Upload the chunks until the end
            $status = false;
            while (false == feof($resource) && false == $status) {
                $status = $upload->nextChunk(fread($resource, self::GDRIVE_MAX_UPLOAD));
            }
            return $status;
    }
    
    /**
     *
     * @param string $id
     */
    protected function gdDelete(string $id)
    {
        Log::info('gDrive deleting file', [
            'id' => $id
        ]);
        
        return $this->getService()->files->delete($id, $this->gdFileParams);
    }
    
    /**
     * Normalize the given object recursively into a dot syntax.
     * It does that by
     * passing the object's variables to normalizeArray().
     *
     * @see normaliseArray($array).
     * @param object|array $object
     * @return array The flattened / normalized object
     *
     * @todo might want go through the object recursively and e.g. use Arr::dot()
     */
    protected function normalizeObject($object)
    {
        return $this->normalizeArray(get_object_vars($object));
    }
    
    /**
     * Normalize / flatten the given array.
     * Attributes from other
     * objects are added in dot notation via Arr::dot.
     *
     * @param object|array $object
     *
     * @param array $array
     * @return array|iterable[]
     */
    protected function normalizeArray($array)
    {
        $result = [];
        
        foreach ($array as $index => $value) {
            if (is_scalar($value)) {
                $result[$index] = $value;
            } elseif (is_object($value)) {
                $result[$index] = $this->normalizeObject($value);
            } elseif (is_array($value)) {
                $result[$index] = $this->normalizeArray($value);
            }
        }
        
        return Arr::dot($result);
    }
    
    /**
     * Get the attributes that shall be return in listContents.
     *
     * @param object $driveFile
     * @return string[]
     */
    protected function standardFileAttributes(object $driveFile)
    {
        return [
            'path' => $driveFile->absname,
            'id' => $driveFile->id,
            'kind' => $driveFile->kind,
            'createdTime' => $driveFile->createdTime,
        ];
    }
    
    /*
     * Here some new stuff starts
     */
    
    /**
     * Get the list of (shared) Drives.
     * Uses chached results.
     *
     * @return array
     */
    protected function getDrivesCache(): array
    {
        // Fill the cache drive if not done yet
        if (count($this->cacheDrives()) < 1) {
            foreach ($this->getDrives() as $drive)
                $driveObjects[$drive->getId()] = $drive;
                $this->cacheDrives($driveObjects);
        }
        
        return $this->cacheDrives();
    }
    
    /**
     * Get the fresh list of shared drives from GDrive
     *
     * @return array
     */
    protected function getDrives(): array
    {
        // Nothing more to do as what GDrive returns
        return $this->gdGetDrives();
    }
    
    /**
     * Get the meta data for the given file or directory ID
     *
     * @param string $id
     * @return Google_Service_Drive_DriveFile|Google_Service_Drive_Drive
     */
    protected function getFileObjByIdCache(string $id): object
    {
        // If it's in the cache already return that
        if (($file = $this->cacheFiles($id)) != null)
            return $file;
            
            // If root is requested, let's use a fake one for now
            if ($id == self::DIRECTORY_ID_ROOT) {
                $file = new \Google_Service_Drive_DriveFile();
                $file->id = self::DIRECTORY_ID_ROOT;
                $file->name = '';
                $file->absname = '';
                
                // Add a cache record for our newly created root
                $this->cacheNames($this->pathSeparator, $id);
            } else {
                // Get the file object without using the cache
                $file = $this->getFileObjById($id);
            }
            
            // Add the found file to the cache and return it
            return $this->cacheFiles($id, $file);
    }
    
    /**
     * Get the object with the given ID from the GDrive
     *
     * @param string $id
     * @return object
     */
    protected function getFileObjById(string $id): object
    {
        return $this->gdGetFileById($id);
    }
    
    /**
     * Get the file by its name.
     * It's simply a wrapper to first get the ID for the given path, then return
     * the object for the found file.
     *
     * @param string $path
     * @return object
     */
    protected function getFileObjByName(string $path): object
    {
        $id = $this->getFileIdByNameCache($path);
        // This get the file by the found ID with checking the cache first
        return $this->getFileObjByIdCache($id);
        
        // This gets the file directly from GDrive
        // return $this->gdGetFileById($id);
    }
    
    /**
     * Get the ID of the given file / directory.
     * For example '/drive1/directory1/subdir23' might return 'dkdh4ddfDddDSE'.
     *
     * Either use a cached result or find it on Google Drive.
     *
     * @param string $path
     * @return string
     */
    protected function getFileIdByNameCache(string $path): string
    {
        // Make sure the directory has no trailing separator
        $path = $this->normalizeDirectory($path);
        
        // What do we have in the cache for the that name?
        $id = $this->cacheNames($path);
        
        // Nothing? Then find it.
        if ($id == '') {
            $id = $this->findFileIdByNameCache($path);
            $this->cacheNames($path, $id);
        }
        
        return $id;
    }
    
    /**
     * Return the content from the given ID.
     * For directories that is the listing of the directory (files and folders).
     *
     * @param string $id
     * @return \Google_Service_Drive_Drive[]|\Google_Service_Drive_DriveFile[]
     */
    protected function getChildrenFromIdCache(string $id): array
    {
        // Try to get the file object from the cache
        $fileObj = $this->cacheFiles($id);
        
        // If the object was in the cache, and already has the children assigned
        // return those.
        if ($fileObj != null) {
            if (property_exists($fileObj, self::CACHE_ID_CHILDREN)) {
                return $fileObj[self::CACHE_ID_CHILDREN];
            }
        }
        
        // Do we have the meta-data object itself it not in the cache earlier
        if ($fileObj == null) {
            $fileObj = $this->getFileObjByIdCache($id);
        }
        
        // Get the children for that file object
        $children = $this->getChildrenFromId($fileObj);
        
        // Teach the children their names, add them to their parent, and cache
        // the result for the next time.
        $fileObj = $this->addChildrenToParentCache($fileObj, $children);
        
        // Return the array with the children
        return $fileObj->{self::CACHE_ID_CHILDREN};
    }
    
    /**
     * Prepare the query to ask GDrive for files that belong to the given one.
     * Call the query and return the DriveFile objects as array; if found.
     *
     * @param string $driveFile
     * @return \Google_Service_Drive_DriveFile|\Google_Service_Drive_Drive
     */
    protected function getChildrenFromId(object $driveFile): array
    {
        // For root (/) the children are the drives
        if ($driveFile->id == self::DIRECTORY_ID_ROOT) {
            return $this->getDrivesCache();
        }
        
        // Otherwise query the API endpoint to return results for the parent
        
        // Add / build the query parameters needed to get the children
        $q = [
            // The $id in the parent of what we want to find
            sprintf('"%s" in parents', $driveFile->getId()),
            'trashed = false'
        ];
        
        // What drive needs to be searched? That more performant than setting
        // corpora in the search query to 'allDrives'.
        if ($driveFile instanceof \Google_Service_Drive_Drive)
            $driveId = $driveFile->getId();
            elseif ($driveFile instanceof \Google_Service_Drive_DriveFile)
            $driveId = $driveFile->getDriveId();
            
            $result = $this->gdListFiles([
                'driveId' => $driveId,
                'q' => implode(' and ', $q)
            ]);
            
            // Get every DriveFile from the result
            if ($result instanceof \Google_Service_Drive_FileList) {
                $children = [];
                
                foreach ($result->getFiles() as $child) {
                    $children[$child->getId()] = $child;
                }
                
                return $children;
            }
            
            return [];
    }
    
    /**
     * Adjust the children before they are added to the given parent.
     * Store them in the caches.
     * Returns the adjusted parent with the new children.
     *
     * @param object $parent
     * @param array $children
     * @return \Google_Service_Drive_Drive|\Google_Service_Drive_DriveFile
     */
    protected function addChildrenToParentCache(object $parent, array $children)
    {
        // If the parent has no children array yet add one
        if (false == property_exists($parent, self::CACHE_ID_CHILDREN))
            $parent->{self::CACHE_ID_CHILDREN} = [];
            
            foreach ($children as $child) {
                // Add the absolute filename to the child
                if ($parent->absname != '')
                    $child->absname = $parent->absname . $this->pathSeparator . $child->name;
                    else
                        $child->absname = $child->name;
                        
                        // Bring the child to its parent
                        $parent->{self::CACHE_ID_CHILDREN}[$child->getId()] = $child;
                        
                        // Add the child object to the file object cache
                        $this->cacheFiles($child->getId(), $child);
                        
                        // Also keep a link from the child filename to the object ID
                        $this->cacheNames($child->absname, $child->getId());
            }
            
            // Finally update the parent object in the cache
            $this->cacheFiles($parent->getId(), $parent);
            
            return $parent;
    }
    
    /**
     * Remove the given array of child IDs from the parent.
     * If the parent has not children, nothing is done.
     * If an asterix (*) in the list of IDs, all children are removed.
     * Otherwise, all given child IDs are removed from the parents children.
     *
     * @param object $parent
     * @param array $children
     * @return object
     *
     * @todo stop the recursion when nothing is in the cache
     */
    protected function removeChildrenFromParentCache(object $parent, array $children): object
    {
        // If the parent does not have children, there is nothing to do
        if (false == property_exists($parent, self::CACHE_ID_CHILDREN))
            return $parent;
            
            // Simply delete the whole children list
            // unset($parent->{self::CACHE_ID_CHILDREN});
            // $this->cacheFiles($parent->getId(), $parent);
            // return $parent;
            
            // If all children shall be removed, collect all their IDs and call this
            // function here again. That way the logic to remove a child is kept in
            // one area and does not have to be duplicated in here, or elsewhere.
            if (in_array('*', $children)) {
                
                // Get all children ...
                $childObjs = $this->getChildrenFromIdCache($parent->getId());
                
                // ... and collect their IDs
                $childIds = [];
                
                foreach ($childObjs as $childObj) {
                    $childIds[] = $childObj->getId();
                }
                
                // To then call remove them one by one to have one place for that
                return $this->removeChildrenFromParentCache($parent, $childIds);
            }
            
            // The children should be kept in an array...
            if (is_array($parent->{self::CACHE_ID_CHILDREN})) {
                
                // ... so go through each requested child
                foreach ($children as $child) {
                    
                    // ... to see if it exists
                    if (array_key_exists($child, $parent->{self::CACHE_ID_CHILDREN})) {
                        
                        // ... if it's a directory itself, remove those children as
                        // well, because they contain the absname (abs filename).
                        // @todo only do that when there is something in the cache
                        $childObj = $this->getFileObjByIdCache($child);
                        Log::debug('REMOVING CHILD', [
                            'child' => $childObj
                        ]);
                        if ($this->fileObjIsDirectory($childObj))
                            $this->removeChildrenFromParentCache($childObj, [
                                '*'
                            ]);
                            
                            // ... and delete it from the parent
                            unset($parent->{self::CACHE_ID_CHILDREN}[$child]);
                            
                            // ... and remove it from the caches
                            if (property_exists($childObj, 'absname'))
                                $this->cacheNames($childObj->absname, '', true);
                                $this->cacheFiles($childObj->getId(), null, true);
                    }
                    
                    // If there are no children left, remove the attribute
                    if (count($parent->{self::CACHE_ID_CHILDREN}) < 1)
                        unset($parent->{self::CACHE_ID_CHILDREN});
                }
                
                // After all children are done, update the cache with the new one.
                $this->cacheFiles($parent->getId(), $parent);
            }
            
            // Return the updated parent
            return $parent;
    }
    
    /**
     * Check if the given DriveFile object is a directory.
     *
     * @param \Google_Service_Drive_DriveFile|\Google_Service_Drive_Drive $fileObj
     * @return bool
     */
    protected function fileObjIsDirectory(object $fileObj): bool
    {
        return $fileObj->getMimeType() == self::GDRIVE_MIMETYPE_DIR;
    }
    
    /**
     * Run through each directory (file) in the given path to find the ID of the
     * last directory (file).
     * That ID can then be used to further access it.
     *
     * For example, if path is '/some/dir/here' the ID for the 'here' directory
     * is returned.
     *
     * @param string $filename
     * @return string
     */
    protected function findFileIdByNameCache(string $filename): string
    {
        // Root (/) is special but simple
        if ($filename == $this->pathSeparator)
            return self::DIRECTORY_ID_ROOT;
            
            // Get each directory name in an array that can be gone through
            $paths = $this->splitPath($filename);
            
            // Start searching from the very beginning (root)
            $driveObjects = $this->getChildrenFromIdCache(self::DIRECTORY_ID_ROOT);
            
            // Run through all paths until we eventually find the $filename
            foreach ($paths as $directory) {
                
                // Find the ID within the list of objects
                $id = $this->findFileIdInObjects($directory, $driveObjects);
                
                // Whoops, file not found
                if ($id == '')
                    throw new FileNotFoundException(sprintf('File "%s" not found', $filename));
                    
                    // Get the listing for the next loop... @todo do that only when needed
                    $driveObjects = $this->getChildrenFromIdCache($id);
            }
            
            // Return the last ID that was found
            return (string) $id;
    }
    
    /**
     * Find the given file in the list of Google Drive objects
     *
     * @param string $file
     * @param array $object
     * @return string
     */
    protected function findFileIdInObjects(string $file, array $objects): string
    {
        foreach ($objects as $driveObject) {
            if ($driveObject->name == $file)
                return $driveObject->id;
        }
        // not found...
        return '';
    }
}



/**/