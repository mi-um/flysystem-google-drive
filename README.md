# Flysystem Adapter for Google Drive

The idea is to have an Flysystem adapter for Google Drive. That driver shall
provide the same interface that other adpaters do and make the use seemlessly.

Instead of having to know and deal with the IDs of the files and directories,
the adapter takes care of that. From the outside, the files can be used like
other files systems by referencing the file and directory name itself. The
adapter will find the ID needed for the interaction with the Google Drive API.

The Service Provider **should** make it easy to include it in Laravel projects.

By design, Google Drive specialities like having files or folders with the same
name is not supported. Instead treat the Google Drive like "other" filesystems.

It needs a Google Service account.

# Status
This is a "works for me" version. Not tested and most likely full of bugs.

# Background / motivation
A Laravel project switched from DropBox to Google Drive and team storage. For
that a storage adapter was needed that behaved more like the other storages in
Laravel. There was no front-end where the IDs for the Google Drive files or
folders were useful. 

## TODO
* Test
* Document
* Support sub-directories within Google Drive as the root for the storage.
* UnitTests
* Testing in general
* Fix the @todo and @fixme in the code
* Reduce dependencies

## Installation

## Configuration
A [Google Service account](https://cloud.google.com/docs/authentication/production)
is required to grand server to server access to the adapter.

### Laravel Service Provider
It will provide the authentication creditials as JSON file. The file should be
downloaded for later use.

To configure the storage adapter one can either provide the file path: 
`GOOGLE_SERVICE_CONF_FILE="service-account.json"`
or provide its content base base 64 encoded json string:
`GOOGLE_SERVICE_CONF="[LONG-BASE64-STRING-HERE]"`

To create a storage device in a Laravel project, the following code can be added
to `config/filesystems.php`:

```php
'gdrive' => [
  'driver' => 'gdrive',
  'serviceConf' => env('GOOGLE_SERVICE_CONF', ''),
  'serviceConfFile' => env('GOOGLE_SERVICE_CONF_FILE', ''),
],
```

This way 
`Storage::disk('gdrive')`
allows to access a gdrive disk.

For example:

`Storage::disk('gdrive')->getMetadata('dir/file.txt');`

lists meta data of a file `dir/file.txt`.

## Usage
