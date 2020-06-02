<?php
namespace mium\GoogleDrive\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use mium\GoogleDrive\Adapters\GoogleDriveAdapter;
use League\Flysystem\Config;

/**
 * A default Service Provider to add a google storage.
 *
 * If you prefer to add your own Provider, disable auto discovery for this one
 * here by adding it to the dont-discover list.
 * See e.g. https://laravel.com/docs/7.x/packages#package-discovery
 *
 * @author umschlag
 *        
 */
class GoogleDriveServiceProvider extends ServiceProvider
{

    /**
     * The Google Client instance
     *
     * @var \Google_Client|null
     */
    public $googleClient = null;

    /**
     * The Google Drive Service instance.
     *
     * @var \Google_Service_Drive|null
     */
    public $googleServiceDrive = null;

    /**
     * The configuration object for our GDrive Adpater
     *
     * @var Config|null
     */
    public $config = null;

    /**
     * The defaut / fallback configuration of our GDrive Adapter.
     * They are passed to the Config instance.
     *
     * @var array
     */
    public $configFallback = [
        // The root folder of our Storage
        'folderId' => '/',
        // A unique cache ID for the Storage Adapter
        'cacheId' => 'fly-gdrive-0',
        // For how long are files kept in the local cache (in seconds)
        'cacheExpire' => 30,
        // What configured Laravel cache instance shall we use
        'cacheStore' => 'default',
        // Shall files be trashed on delete or permanentely deleted
        // @todo implement / test that. Most likely the cache will get confused
        'skipTrash' => false,
        // The json file with the serivce account configuration
        'serviceConf' => 'gdrive_service_conf.json',
    ];

    /**
     * Bootstrap the application services.
     *
     * @return void
     * @todo See what (if) can be moved to the register() method instead.
     */
    public function boot()
    {
        /*
         * Add a "google" driver for the FilesystemAdapter
         */
        Storage::extend('google',
            function ($app, $config) {
                
                // Get a configuration object
                $config = $this->getConfig($config, $this->configFallback);
                

                // Get the Google_Client instance
                $client = $this->getGoogleClient();

                // Configure the client
                $client->setApplicationName('YPulse-Storage');
                $client->setAuthConfig( $config->get('serviceConf') );
                $client->addScope('https://www.googleapis.com/auth/drive');

                // if(env('GOOGLE_DRIVE_API_KEY') != '') {
                // $client->setDeveloperKey(env('GOOGLE_DRIVE_API_KEY'));
                // } else {
                // return logs()->error('GOOGLE_DRIVE_API_KEY not set');
                // $client->setClientId($config['clientId']);
                // $client->setClientSecret($config['clientSecret']);
                // $client->refreshToken($config['refreshToken']);
                // }

                // Pass the Client to the Service
                $service = $this->getGoogleServiceDrive($client);


                // Initiate our GDrive Adapter
                $adapter = new GoogleDriveAdapter($service, $config);

                // Finally get the Flysystem with our Adapter
                return new \League\Flysystem\Filesystem($adapter);
            });
    }

    /**
     * Get the Google_Client instance.
     *
     * @return \Google_Client
     */
    public function getGoogleClient()
    {
        if ($this->googleClient == null)
            $this->googleClient = new \Google_Client();
        return $this->googleClient;
    }

    /**
     * Set the Google_Client instance.
     *
     * @param \Google_Client $googleClient
     * @return \mium\GoogleDrive\Providers\GoogleDriveServiceProvider
     */
    public function setGoogleClient($googleClient)
    {
        $this->googleClient = $googleClient;
        return $this;
    }

    /**
     * Get the Google Drive Service instance.
     *
     * @param \Google_Client $client
     * @return \Google_Service_Drive|NULL
     */
    public function getGoogleServiceDrive($client)
    {
        if ($this->googleServiceDrive == null)
            $this->googleServiceDrive = new \Google_Service_Drive($client);
        return $this->googleServiceDrive;
    }

    /**
     * Set the set the Google Drivce Service instance.
     *
     * @param \Google_Service_Drive $service
     * @return \mium\GoogleDrive\Providers\GoogleDriveServiceProvider
     */
    public function setGoogleServiceDrive($service)
    {
        $this->googleServiceDrive = $service;
        return $this;
    }

    /**
     * Return the configuration for the Adpater
     *
     * @param array $config
     * @param array $fallback
     * @return \League\Flysystem\Config|NULL
     */
    public function getConfig(array $config, array $fallback)
    {
        if ($this->config == null) {
            $this->config = new Config($config);
            $this->config->setFallback(new Config($fallback));
        }

        return $this->config;
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}