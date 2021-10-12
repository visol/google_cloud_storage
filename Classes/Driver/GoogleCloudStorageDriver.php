<?php

namespace Visol\GoogleCloudStorage\Driver;

/*
 * This file is part of the Visol/GoogleCloudStorage project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */


use Google\Cloud\Core\ServiceBuilder;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\ObjectIterator;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use Visol\GoogleCloudStorage\Services\ConfigurationService;
use Visol\GoogleCloudStorage\Cache\GoogleCloudStorageTypo3Cache;
use Visol\GoogleCloudStorage\Utility\GooglePathUtility;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Exception;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Class GoogleCloudStorageDriver
 */
class GoogleCloudStorageDriver extends AbstractHierarchicalFilesystemDriver
{

    public const DRIVER_TYPE = 'VisolGoogleCloudStorage';
    const ROOT_FOLDER_IDENTIFIER = '/';
    const UNSAFE_FILENAME_CHARACTER_EXPRESSION = '\\x00-\\x2C\\/\\x3A-\\x3F\\x5B-\\x60\\x7B-\\xBF';
    private const GCS_BASE_URL = 'https://storage.cloud.google.com';
    private const GCS_DOWNLOAD_URL = 'https://www.googleapis.com/download/storage/v1/b';

    /**
     * The base URL that points to this driver's storage. As long is this is not set, it is assumed that this folder
     * is not publicly available
     *
     * @var string
     */
    protected $baseUrl = '';

    /**
     * @var array[]
     */
    protected $cachedGoogleCloudStorageResources = [];

    /**
     * @var array
     */
    protected $cachedFolders = [];

    /**
     * Object permissions are cached here in subarrays like:
     * $identifier => ['r' => bool, 'w' => bool]
     *
     * @var array
     */
    protected $cachedPermissions = [];

    /**
     * @var \TYPO3\CMS\Core\Resource\ResourceStorage
     */
    protected $storage = null;

    /**
     * @var \TYPO3\CMS\Core\Charset\CharsetConverter
     */
    protected $charsetConversion = null;

    /**
     * @var string
     */
    protected $languageFile = 'LLL:EXT:google_cloud_storage/Resources/Private/Language/backend.xlf';

    /**
     * @var Dispatcher
     */
    protected $signalSlotDispatcher;

    /**
     * @var GoogleCloudStorageTypo3Cache
     */
    protected $googleCloudStorageTypo3Cache;

    /**
     * @param array $configuration
     */
    public function __construct(array $configuration = [])
    {
        $this->configuration = $configuration;
        $this->configuration['bucketName'] = '';
        parent::__construct($configuration);

        // The capabilities default of this driver. See CAPABILITY_* constants for possible values
        $this->capabilities =
            ResourceStorage::CAPABILITY_BROWSABLE
            | ResourceStorage::CAPABILITY_PUBLIC
            | ResourceStorage::CAPABILITY_WRITABLE;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    public function getConfiguration(string $key): string
    {
        /** @var ConfigurationService $configurationService */
        $configurationService = GeneralUtility::makeInstance(
            ConfigurationService::class,
            $this->configuration
        );
        return $configurationService->get($key);
    }

    /**
     * @return void
     */
    public function processConfiguration()
    {
    }

    /**
     * @return void
     */
    public function initialize()
    {
        // Test connection if we are in the edit view of this storage
        if (TYPO3_MODE === 'BE' && !empty($_GET['edit']['sys_file_storage'])) {
            $this->testConnection();
        }
    }

    /**
     * @param string $fileIdentifier
     *
     * @return string
     */
    public function getPublicUrl($fileIdentifier)
    {
        $object = $this->getObjectData($fileIdentifier);

        $baseUri = $this->getConfiguration('baseUri');

        // Default value
        $publicUrl = $object['mediaLink'];

        // We could have a configured base URL.
        if ($baseUri) {

            if (strpos($object['mediaLink'], self::GCS_BASE_URL) === 0) {
                $publicUrl = str_replace(
                    self::GCS_BASE_URL . DIRECTORY_SEPARATOR . $object['bucket'],
                    rtrim($baseUri, DIRECTORY_SEPARATOR),
                    $object['mediaLink']
                );
            } elseif (strpos($object['mediaLink'], self::GCS_DOWNLOAD_URL) === 0) {
                $publicUrl = str_replace(
                    self::GCS_DOWNLOAD_URL . DIRECTORY_SEPARATOR . $object['bucket'] . DIRECTORY_SEPARATOR . 'o',
                    rtrim($baseUri, DIRECTORY_SEPARATOR),
                    strtok( // remove the query part of the URL e.g "?"
                        urldecode($object['mediaLink']), // replace the encoded slash "/"
                        '?'
                    )
                );
            }
        }
        return $publicUrl;
    }

    /**
     * @param string $message
     * @param array $arguments
     */
    public function log(string $message, array $arguments = [])
    {
        /** @var \TYPO3\CMS\Core\Log\Logger $logger */
        $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
        $logger->log(
            LogLevel::INFO,
            vsprintf('[DRIVER] ' . $message, $arguments)
        );
    }

    /**
     * Creates a (cryptographic) hash for a file.
     *
     * @param string $fileIdentifier
     * @param string $hashAlgorithm
     *
     * @return string
     */
    public function hash($fileIdentifier, $hashAlgorithm)
    {
        return $this->hashIdentifier($fileIdentifier);
    }

    /**
     * Returns the identifier of the default folder new files should be put into.
     *
     * @return string
     */
    public function getDefaultFolder()
    {
        return $this->getRootLevelFolder();
    }

    /**
     * Returns the identifier of the root level folder of the storage.
     *
     * @return string
     */
    public function getRootLevelFolder()
    {
        return DIRECTORY_SEPARATOR;
    }

    /**
     * Returns information about a file.
     *
     * @param string $fileIdentifier
     * @param array $propertiesToExtract Array of properties which are be extracted
     *                                    If empty all will be extracted
     *
     * @return array
     * @throws \Exception
     */
    public function getFileInfoByIdentifier($fileIdentifier, array $propertiesToExtract = [])
    {
        if (!$this->objectExists($fileIdentifier)) {
            throw new \Exception(
                sprintf(
                    'I could not retrieve the file info since no file was found for identifier "%s"',
                    $fileIdentifier
                ),
                1584365914
            );

            // See if this code is required...
            // Make a new attempt since caching may interfere...
            #$this->log('[REMOTE] method "getFileInfoByIdentifier": fetch resource with identifier "%s"', [$fileIdentifier]);

            #$object = $this->getBucket()->object($fileIdentifier);
            #$objectData = $object->info();
            #$this->flushFileCache(); // We flush the cache again....
        } else {
            $objectData = $this->getObjectData($fileIdentifier);
        }

        $canonicalFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier(dirname($fileIdentifier));
        return [
            'identifier_hash' => $this->hashIdentifier($fileIdentifier),
            'folder_hash' => sha1($canonicalFolderIdentifier),
            'creation_date' => strtotime($objectData['timeCreated']),
            'modification_date' => strtotime($objectData['updated']),
            'mime_type' => $objectData['contentType'],
            'extension' => pathinfo($objectData['name'], PATHINFO_EXTENSION),
            'size' => $objectData['size'],
            'storage' => $this->storageUid,
            'identifier' => $fileIdentifier,
            'name' => PathUtility::basename($objectData['name']),
        ];
    }

    /**
     * Checks if a file exists
     *
     * @param string $identifier
     *
     * @return bool
     */
    public function fileExists($identifier)
    {
        if (substr($identifier, -1) === DIRECTORY_SEPARATOR || $identifier === '') {
            return false;
        }
        return $this->objectExists($identifier);
    }

    /**
     * Checks if a folder exists
     *
     * @param string $folderIdentifier
     *
     * @return bool
     */
    public function folderExists($folderIdentifier)
    {
        if ($folderIdentifier === self::ROOT_FOLDER_IDENTIFIER) {
            return true;
        }

        // Normal case
        return $this
            ->getObject(GooglePathUtility::getFinalFolderIdentifier($folderIdentifier))
            ->exists();
    }

    /**
     * @param string $fileName
     * @param string $folderIdentifier
     *
     * @return bool
     */
    public function fileExistsInFolder($fileName, $folderIdentifier)
    {
        $fileIdentifier = $folderIdentifier . $fileName;
        return $this->objectExists($fileIdentifier);
    }

    /**
     * Checks if a folder exists inside a storage folder
     *
     * @param string $folderName
     * @param string $folderIdentifier
     *
     * @return bool
     */
    public function folderExistsInFolder($folderName, $folderIdentifier)
    {
        return $this->folderExists(
            GooglePathUtility::combineFolderAndFolderName($folderIdentifier, $folderName)
        );
    }

    /**
     * Returns the Identifier for a folder within a given folder.
     *
     * @param string $folderName The name of the target folder
     * @param string $folderIdentifier
     *
     * @return string
     */
    public function getFolderInFolder($folderName, $folderIdentifier)
    {
        return $folderIdentifier . DIRECTORY_SEPARATOR . $folderName;
    }

    /**
     * @param string $localFilePath (within PATH_site)
     * @param string $targetFolderIdentifier
     * @param string $newFileName optional, if not given original name is used
     * @param bool $removeOriginal if set the original file will be removed
     *                                after successful operation
     *
     * @return string the identifier of the new file
     * @throws \Exception
     */
    public function addFile($localFilePath, $targetFolderIdentifier, $newFileName = '', $removeOriginal = true)
    {
        // Necessary to happen in an early stage.
        $this->flushFileCache();

        $fileName = $this->sanitizeFileName(
            $newFileName !== ''
                ? $newFileName :
                PathUtility::basename($localFilePath)
        );

        $object = $this->getBucket()->upload(
            file_get_contents($localFilePath),
            [
                'name' => GooglePathUtility::combineFolderAndFile($targetFolderIdentifier, $fileName)
            ]
        );

        // return the file identifier
        return $object
            ? GooglePathUtility::computeFileIdentifier(
                $object->info()['name']
            )
            : '';
    }

    /**
     * @param string $fileIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFileName
     *
     * @return string
     */
    public function moveFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $newFileName)
    {
        $targetIdentifier = $targetFolderIdentifier . $newFileName;
        return $this->renameFile($fileIdentifier, $targetIdentifier);
    }

    /**
     * Copies a file *within* the current storage.
     * Note that this is only about an inner storage copy action,
     * where a file is just copied to another folder in the same storage.
     *
     * @param string $fileIdentifier
     * @param string $targetFolderIdentifier
     * @param string $fileName
     *
     * @return string the Identifier of the new file
     */
    public function copyFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $fileName)
    {
        // Flush the file cache entries
        $this->flushFileCache();

        $newObject = $this
            ->getObject($fileIdentifier)
            ->copy(
                $this->getBucket()->name(),
                [
                    'name' => GooglePathUtility::combineFolderAndFile($targetFolderIdentifier, $fileName)
                ]
            );

        return GooglePathUtility::computeFileIdentifier($newObject->name());
    }

    /**
     * Replaces a file with file in local file system.
     *
     * @param string $fileIdentifier
     * @param string $localFilePath
     *
     * @return bool TRUE if the operation succeeded
     */
    public function replaceFile($fileIdentifier, $localFilePath)
    {
        $fileIdentifier = $this->addFile(
            $localFilePath,
            dirname($fileIdentifier),
            PathUtility::basename($fileIdentifier)
        );
        return (bool)$fileIdentifier;
    }

    /**
     * Removes a file from the filesystem. This does not check if the file is
     * still used or if it is a bad idea to delete it for some other reason
     * this has to be taken care of in the upper layers (e.g. the Storage)!
     *
     * @param string $fileIdentifier
     *
     * @return bool TRUE if deleting the file succeeded
     */
    public function deleteFile($fileIdentifier)
    {
        // Necessary to happen in an early stage.
        $this->flushFileCache();

        $this->log('[REMOTE] method "deleteFile": delete resource with identifier "%s"', [$fileIdentifier]);

        $object = $this->getBucket()->object(
            GooglePathUtility::normalizeGooglePath($fileIdentifier)
        );
        $object->delete(); // Will throw an exception if something wrong happens...
        return true;
    }

    /**
     * Removes a folder in filesystem.
     *
     * @param string $folderIdentifier
     * @param bool $deleteRecursively
     *
     * @return bool
     */
    public function deleteFolder($folderIdentifier, $deleteRecursively = false)
    {
        // Disable cache to speed things up.
        $this->getCache()->disable();

        $recursiveFolders = $this->getRecursiveDataFolders($folderIdentifier);
        $recursiveFolders[] = GooglePathUtility::normalizeGoogleFolderPath($folderIdentifier);
        foreach ($recursiveFolders as $folderPath) {

            /** @var StorageObject $object */
            foreach ($this->getObjects($folderPath) as $object) {
                $this->log(
                    '[REMOTE] method "deleteFolder": delete from "%s" file "%s"',
                    [
                        $folderPath,
                        $object->name()
                    ]
                );
                $object->delete();
            }
        }

        // Flush the cache
        $this->getCache()->enable();

        // Flush the whole cache
        $this->flushCache();

        return true;
    }

    /**
     * Returns a path to a local copy of a file for processing it. When changing the
     * file, you have to take care of replacing the current version yourself!
     * The file will be removed by the driver automatically on destruction.
     *
     * @param string $fileIdentifier
     * @param bool $writable Set this to FALSE if you only need the file for read
     *                         operations. This might speed up things, e.g. by using
     *                         a cached local version. Never modify the file if you
     *                         have set this flag!
     *
     * @return string The path to the file on the local disk
     * @throws \RuntimeException
     */
    public function getFileForLocalProcessing($fileIdentifier, $writable = true)
    {
        $temporaryPath = $this->getTemporaryPathForFile($fileIdentifier);

        if ((!is_file($temporaryPath) || !filesize($temporaryPath)) && $this->objectExists($fileIdentifier)) {
            $this->log('Downloading for local processing file "%s"', [$fileIdentifier]);
            $url = $this->getPublicUrl($fileIdentifier) . '?' . time();
            $this->log('Public URL "%s"', [$url]);
            $this->log('Temporary path "%s"', [$temporaryPath]);

            // We have cache problem with this approach
            //$this->getObject($fileIdentifier)
            //    ->downloadToFile($temporaryPath);

            file_put_contents($temporaryPath, file_get_contents($url));
        }

        return $temporaryPath;
    }

    /**
     * Creates a new (empty) file and returns the identifier.
     *
     * @param string $fileName
     * @param string $parentFolderIdentifier
     *
     * @return string
     */
    public function createFile($fileName, $parentFolderIdentifier)
    {
        throw new \RuntimeException('Not implemented "createFile" action!', 1570728207);
    }

    /**
     * Creates a folder, within a parent folder.
     * If no parent folder is given, a root level folder will be created
     *
     * @param string $newFolderName
     * @param string $parentFolderIdentifier
     * @param bool $recursive
     *
     * @return string the Identifier of the new folder
     */
    public function createFolder($newFolderName, $parentFolderIdentifier = '', $recursive = false)
    {
        // Flush the folder cache entries
        $this->flushFolderCache();

        $normalizedFolderIdentifier = GooglePathUtility::combineFolderAndFolderName(
            $parentFolderIdentifier,
            $newFolderName
        );

        $this->log('[REMOTE] method "createFolder": create folder with identifier "%s"', [$normalizedFolderIdentifier]);

        $object = $this->getBucket()->upload(
            '',
            [
                'name' => GooglePathUtility::getFinalFolderIdentifier($normalizedFolderIdentifier),
            ]
        );

        return $normalizedFolderIdentifier;
    }

    /**
     * Returns the contents of a file. Beware that this requires to load the
     * complete file into memory and also may require fetching the file from an
     * external location. So this might be an expensive operation (both in terms
     * of processing resources and money) for large files.
     *
     * @param string $fileIdentifier
     *
     * @return string The file contents
     */
    public function getFileContents($fileIdentifier)
    {
        return $this->getObject($fileIdentifier)->downloadAsString();
    }

    /**
     * Sets the contents of a file to the specified value.
     *
     * @param string $fileIdentifier
     * @param string $contents
     *
     * @return int
     */
    public function setFileContents($fileIdentifier, $contents)
    {
        throw new \RuntimeException('Not implemented "setFileContents" action!', 1570728206);
    }

    /**
     * Renames a file in this storage.
     *
     * @param string $fileIdentifier
     * @param string $newFileIdentifier The target path (including the file name!)
     *
     * @return string The identifier of the file after renaming
     */
    public function renameFile($fileIdentifier, $newFileIdentifier)
    {
        if (!$this->isFileIdentifier($newFileIdentifier)) {
            $newFileIdentifier = $this->computeNewFileNameAndIdentifier($fileIdentifier, PathUtility::basename($newFileIdentifier));
        }

        $newFileIdentifier = $this->copyFileWithinStorage(
            $fileIdentifier,
            dirname($newFileIdentifier),
            PathUtility::basename($newFileIdentifier)
        );
        $this->deleteFile($fileIdentifier);

        return $newFileIdentifier;
    }

    /**
     * @param string $newFileIdentifier
     *
     * @return bool
     */
    public function isFileIdentifier(string $newFileIdentifier): bool
    {
        return false !== strpos($newFileIdentifier, DIRECTORY_SEPARATOR);
    }

    /**
     * Renames a folder in this storage.
     *
     * @param string $folderIdentifier
     * @param string $newFolderName
     *
     * @return array A map of old to new file identifiers of all affected resources
     */
    public function renameFolder($folderIdentifier, $newFolderName)
    {
        $renamedFiles = [];
        $pathSegments = GeneralUtility::trimExplode(DIRECTORY_SEPARATOR, $folderIdentifier);
        $numberOfSegments = count($pathSegments);
        if ($numberOfSegments > 1) {
            // Replace last folder name by the new folder name
            unset($pathSegments[$numberOfSegments - 2]);
            $targetFolderIdentifier = implode('/', $pathSegments);

            $renamedFiles = $this->moveFolderWithinStorage($folderIdentifier, $targetFolderIdentifier, $newFolderName);
        }
        return $renamedFiles;
    }

    /**
     * @param string $sourceFolderIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFolderName
     *
     * @return array All files which are affected, map of old => new file identifiers
     */
    public function moveFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName)
    {
        // Disable cache to speed things up.
        $this->getCache()->disable();

        $movedFiles = $this->copyOrMoveFolderWithinStorage(
            $sourceFolderIdentifier,
            $targetFolderIdentifier,
            $newFolderName,
            $deleteFileAfterCopy = true
        );

        // Flush the cache
        $this->getCache()->enable();

        // Flush the folder cache.
        $this->flushFolderCache();

        return $movedFiles;
    }

    /**
     * @param string $sourceFolderIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFolderName
     *
     * @return bool
     */
    public function copyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName)
    {
        // Disable cache to speed things up.
        $this->getCache()->disable();

        $this->copyOrMoveFolderWithinStorage(
            $sourceFolderIdentifier,
            $targetFolderIdentifier,
            $newFolderName,
            $deleteFileAfterCopy = false
        );

        // Enable cache again
        $this->getCache()->enable();

        // Flush the whole cache.
        $this->flushCache();

        return true;
    }

    /**
     * @param string $sourceFolderIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFolderName
     * @param bool $deleteFileAfterCopy
     *
     * @return array
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function copyOrMoveFolderWithinStorage(
        string $sourceFolderIdentifier,
        string $targetFolderIdentifier,
        string $newFolderName,
        bool $deleteFileAfterCopy = false
    ): array {
        $touchFiles = [];

        // Compute the new folder identifier and then create it.
        $newTargetFolderIdentifier = $targetFolderIdentifier . $newFolderName . DIRECTORY_SEPARATOR;
        if (!$this->folderExists($newTargetFolderIdentifier)) {
            $this->createFolder($newTargetFolderIdentifier);
        }

        $recursiveFolders = $this->getRecursiveDataFolders($sourceFolderIdentifier);
        $recursiveFolders[] = GooglePathUtility::normalizeGoogleFolderPath($sourceFolderIdentifier);
        foreach ($recursiveFolders as $folderPath) {
            $files = $this->getObjects($folderPath);

            foreach ($files as $object) {
                $targetFileIdentifier = str_replace(
                    GooglePathUtility::normalizeGooglePath($sourceFolderIdentifier),
                    GooglePathUtility::normalizeGooglePath($newTargetFolderIdentifier),
                    $object->name()
                );

                // Moving file in a Google storage boils down to copy + delete the file.
                $object->copy(
                    $this->getBucket()->name(),
                    [
                        'name' => $targetFileIdentifier
                    ]
                );

                if ($deleteFileAfterCopy) {
                    $object->delete();
                }

                if (!$this->isKeepFile($object->name())) {
                    $oldFileIdentifier = GooglePathUtility::computeFileIdentifier($object->name());
                    $touchFiles[$oldFileIdentifier] = GooglePathUtility::computeFileIdentifier($targetFileIdentifier);
                }
            }
        }
        return $touchFiles;
    }

    /**
     * Checks if a folder contains files and (if supported) other folders.
     *
     * @param string $folderIdentifier
     *
     * @return bool TRUE if there are no files and folders within $folder
     */
    public function isFolderEmpty($folderIdentifier)
    {
        $normalizedIdentifier = GooglePathUtility::normalizeGooglePath($folderIdentifier);
        $this->log('[REMOTE] method "isFolderEmpty": fetch files with identifier "%s"', [$normalizedIdentifier]);

        $dataObject = $this->getDataObjects($folderIdentifier);
        return empty($dataObject);
    }

    /**
     * Checks if a given identifier is within a container, e.g. if
     * a file or folder is within another folder.
     * This can e.g. be used to check for web-mounts.
     *
     * Hint: this also needs to return TRUE if the given identifier
     * matches the container identifier to allow access to the root
     * folder of a filemount.
     *
     * @param string $folderIdentifier
     * @param string $identifier identifier to be checked against $folderIdentifier
     *
     * @return bool TRUE if $content is within or matches $folderIdentifier
     */
    public function isWithin($folderIdentifier, $identifier)
    {
        $folderIdentifier = $this->canonicalizeAndCheckFileIdentifier($folderIdentifier);
        $entryIdentifier = $this->canonicalizeAndCheckFileIdentifier($identifier);
        if ($folderIdentifier === $entryIdentifier) {
            return true;
        }

        // File identifier canonicalization will not modify a single slash so
        // we must not append another slash in that case.
        if ($folderIdentifier !== DIRECTORY_SEPARATOR) {
            $folderIdentifier .= DIRECTORY_SEPARATOR;
        }

        return GeneralUtility::isFirstPartOfStr($entryIdentifier, $folderIdentifier);
    }

    /**
     * Returns information about a file.
     *
     * @param string $folderIdentifier
     *
     * @return array
     */
    public function getFolderInfoByIdentifier($folderIdentifier)
    {
        $canonicalFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        return [
            'identifier' => $canonicalFolderIdentifier,
            'name' => PathUtility::basename(rtrim($canonicalFolderIdentifier, DIRECTORY_SEPARATOR)),
            'storage' => $this->storageUid
        ];
    }

    /**
     * Returns a file inside the specified path
     *
     * @param string $fileName
     * @param string $folderIdentifier
     *
     * @return string File Identifier
     */
    public function getFileInFolder($fileName, $folderIdentifier)
    {
        $folderIdentifier = $folderIdentifier . DIRECTORY_SEPARATOR . $fileName;
        return $folderIdentifier;
    }

    /**
     * Returns a list of files inside the specified path
     *
     * @param string $folderIdentifier
     * @param int $start
     * @param int $numberOfItems
     * @param bool $recursive
     * @param array $filenameFilterCallbacks callbacks for filtering the items
     * @param string $sort Property name used to sort the items.
     *                      Among them may be: '' (empty, no sorting), name,
     *                      fileext, size, tstamp and rw.
     *                      If a driver does not support the given property, it
     *                      should fall back to "name".
     * @param bool $sortRev TRUE to indicate reverse sorting (last to first)
     *
     * @return array of FileIdentifiers
     */
    public function getFilesInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 40,
        $recursive = false,
        array $filenameFilterCallbacks = [],
        $sort = 'file',
        $sortRev = false
    )
    {
        if ($folderIdentifier === '') {
            throw new \RuntimeException(
                'Something went wrong in method "getFilesInFolder"! $folderIdentifier can not be empty',
                1574754623
            );
        }

        if (!isset($this->cachedGoogleCloudStorageResources[$folderIdentifier])) {
            // Try to fetch from the cache
            $this->cachedGoogleCloudStorageResources[$folderIdentifier] = $this->getCache()->getCachedFiles($folderIdentifier);

            // If not found in TYPO3 cache, ask GoogleCloudStorage
            if (!is_array($this->cachedGoogleCloudStorageResources[$folderIdentifier])) {
                $this->log('[FAL] method "getFilesInFolder": fetch resources with folder identifier "%s"', [$folderIdentifier]);

                $this->cachedGoogleCloudStorageResources[$folderIdentifier] = $this->getDataObjects($folderIdentifier);
            }
        }

        // Sort files
        if ($sort === 'file') {
            if ($sortRev) {
                uasort(
                    $this->cachedGoogleCloudStorageResources[$folderIdentifier],
                    '\Visol\GoogleCloudStorage\Utility\SortingUtility::sortByFileNameDesc'
                );
            } else {
                uasort(
                    $this->cachedGoogleCloudStorageResources[$folderIdentifier],
                    '\Visol\GoogleCloudStorage\Utility\SortingUtility::sortByFileNameAsc'
                );
            }
        } elseif ($sort === 'tstamp') {
            if ($sortRev) {
                uasort(
                    $this->cachedGoogleCloudStorageResources[$folderIdentifier],
                    '\Visol\GoogleCloudStorage\Utility\SortingUtility::sortByTimeStampDesc'
                );
            } else {
                uasort(
                    $this->cachedGoogleCloudStorageResources[$folderIdentifier],
                    '\Visol\GoogleCloudStorage\Utility\SortingUtility::sortByTimeStampAsc'
                );
            }
        }

        // Pagination
        if ($numberOfItems > 0) {
            $files = array_slice(
                $this->cachedGoogleCloudStorageResources[$folderIdentifier],
                $start,
                $numberOfItems
            );
        } else {
            $files = $this->cachedGoogleCloudStorageResources[$folderIdentifier];
        }

        return array_keys($files);
    }

    /**
     * Returns the number of files inside the specified path
     *
     * @param string $folderIdentifier
     * @param bool $recursive
     * @param array $filenameFilterCallbacks callbacks for filtering the items
     *
     * @return int Number of files in folder
     */
    public function countFilesInFolder($folderIdentifier, $recursive = false, array $filenameFilterCallbacks = [])
    {
        if (!isset($this->cachedGoogleCloudStorageResources[$folderIdentifier])) {
            $this->getFilesInFolder($folderIdentifier, 0, -1, $recursive, $filenameFilterCallbacks);
        }
        return count($this->cachedGoogleCloudStorageResources[$folderIdentifier]);
    }

    /**
     * Returns a list of folders inside the specified path
     *
     * @param string $folderIdentifier
     * @param int $start
     * @param int $numberOfItems
     * @param bool $recursive
     * @param array $folderNameFilterCallbacks callbacks for filtering the items
     * @param string $sort Property name used to sort the items.
     *                      Among them may be: '' (empty, no sorting), name,
     *                      fileext, size, tstamp and rw.
     *                      If a driver does not support the given property, it
     *                      should fall back to "name".
     * @param bool $sortRev TRUE to indicate reverse sorting (last to first)
     *
     * @return array
     */
    public function getFoldersInFolder(
        $folderIdentifier, $start = 0, $numberOfItems = 40, $recursive = false, array $folderNameFilterCallbacks = [], $sort = '', $sortRev = false
    ) {
        if (!isset($this->cachedFolders[$folderIdentifier])) {
            // Try to fetch from the cache
            $this->cachedFolders[$folderIdentifier] = $this->getCache()->getCachedFolders($folderIdentifier);

            // If not found in TYPO3 cache, ask GoogleCloudStorage
            if (!is_array($this->cachedFolders[$folderIdentifier])) {
                $this->log('[FAL] method "getFoldersInFolder": fetch sub-folders with folder identifier "%s"', [$folderIdentifier]);

                $this->cachedFolders[$folderIdentifier] = $this->getDataFolders($folderIdentifier);
            }
        }

        // Sort
        if (isset($sort) && $sort === 'file') {
            $sortRev
                ? krsort($this->cachedFolders[$folderIdentifier])
                : ksort($this->cachedFolders[$folderIdentifier]);
        }

        return $this->cachedFolders[$folderIdentifier];
    }

    /**
     * Returns the number of folders inside the specified path
     *
     * @param string $folderIdentifier
     * @param bool $recursive
     * @param array $folderNameFilterCallbacks callbacks for filtering the items
     *
     * @return int Number of folders in folder
     */
    public function countFoldersInFolder($folderIdentifier, $recursive = false, array $folderNameFilterCallbacks = [])
    {
        return count($this->getFoldersInFolder($folderIdentifier, 0, -1, $recursive, $folderNameFilterCallbacks));
    }

    /**
     * Directly output the contents of the file to the output
     * buffer. Should not take care of header files or flushing
     * buffer before. Will be taken care of by the Storage.
     *
     * @param string $identifier
     *
     * @return void
     */
    public function dumpFileContents($identifier)
    {
        $identifier = GooglePathUtility::normalizeGooglePath($identifier);
        $stream = $this->getBucket()->object($identifier)->downloadAsStream();

        file_put_contents('php://output', $stream);
    }

    /**
     * Returns the permissions of a file/folder as an array
     * (keys r, w) of bool flags
     *
     * @param string $identifier
     *
     * @return array
     */
    public function getPermissions($identifier)
    {
        if (!isset($this->cachedPermissions[$identifier])) {
            // GoogleCloudStorage does not handle permissions
            $permissions = ['r' => true, 'w' => true];
            $this->cachedPermissions[$identifier] = $permissions;
        }
        return $this->cachedPermissions[$identifier];
    }

    /**
     * Merges the capabilites merged by the user at the storage
     * configuration into the actual capabilities of the driver
     * and returns the result.
     *
     * @param int $capabilities
     *
     * @return int
     */
    public function mergeConfigurationCapabilities($capabilities)
    {
        $this->capabilities &= $capabilities;
        return $this->capabilities;
    }

    /**
     * Returns a string where any character not matching [.a-zA-Z0-9_-] is
     * substituted by '_'
     * Trailing dots are removed
     *
     * @param string $fileName Input string, typically the body of a fileName
     * @param string $charset Charset of the a fileName (defaults to current charset; depending on context)
     *
     * @return string Output string with any characters not matching [.a-zA-Z0-9_-] is substituted by '_' and trailing dots removed
     * @throws Exception\InvalidFileNameException
     */
    public function sanitizeFileName($fileName, $charset = '')
    {
        $fileName = $this->getCharsetConversion()->specCharsToASCII('utf-8', $fileName);
        // Replace unwanted characters by underscores
        $cleanFileName = preg_replace(
            '/[' . self::UNSAFE_FILENAME_CHARACTER_EXPRESSION . '\\xC0-\\xFF]/',
            '_',
            trim($fileName)
        );

        // Strip trailing dots and return
        $cleanFileName = rtrim($cleanFileName, '.');
        if ($cleanFileName === '') {
            throw new Exception\InvalidFileNameException(
                'File name ' . $fileName . ' is invalid.',
                1320288991
            );
        }
        return $cleanFileName;
    }

    /**
     * @param string $folderIdentifier
     *
     * @return array
     */
    protected function getDataFolders(string $folderIdentifier): array
    {
        // Log API
        $this->log(
            '[FAL] method "getDataFolders": fetch resources (files + folders) with folder identifier "%s"',
            [
                $folderIdentifier,
            ]
        );

        $folders = [];
        foreach ($this->getFolderObjects($folderIdentifier) as $folderPath) {
            $folders[] = $this->canonicalizeAndCheckFolderIdentifier($folderPath);
        }

        // Add result into typo3 cache to spare [REMOTE] Calls the next time...
        $this->getCache()->setCachedFolders($folderIdentifier, $folders);

        return $folders;
    }

    /**
     * @param string $folderIdentifier
     *
     * @return array
     */
    protected function getRecursiveDataFolders(string $folderIdentifier): array
    {
        $recursiveFolders = [];
        foreach ($this->getDataFolders($folderIdentifier) as $subFolder) {
            $subFolders = $this->getDataFolders($subFolder);
            $recursiveFolders[] = $subFolder;
            if (!empty($subFolders)) {
                $recursiveFolders = array_merge(
                    $this->getRecursiveDataFolders($subFolder),
                    $recursiveFolders
                );
            }
        }
        return $recursiveFolders;
    }

    /**
     * @param string $folderIdentifier
     *
     * @return array
     */
    protected function getFolderObjects(string $folderIdentifier): array
    {

        $this->log(
            '[REMOTE] method "getFolderObjects": fetch the sub-folder with parent folder identifier "%s"',
            [
                $folderIdentifier,
            ]
        );

        $objects = $this->getBucket()->objects([
            'prefix' => $folderIdentifier === self::ROOT_FOLDER_IDENTIFIER
                ? ''
                : GooglePathUtility::normalizeGoogleFolderPath($folderIdentifier),
            'delimiter' => DIRECTORY_SEPARATOR,
        ]);

        // We must iterate for nothing... required to gather the folders!
        /** @var StorageObject $object */
        foreach ($objects as $_) {
        }

        return $objects->prefixes();
    }

    /**
     * @param string $folderIdentifier
     *
     * @return ObjectIterator
     */
    protected function getObjects(string $folderIdentifier): ObjectIterator
    {

        $this->log(
            '[REMOTE] method "getObjects": fetch resources with folder identifier "%s"',
            [
                $folderIdentifier,
            ]
        );

        $fields = [
            //'items/*', // to display all fields returned by the GCS API
            //'items/selfLink',
            //'items/mediaLink',
            'items/bucket',
            'items/name',
            'items/contentType',
            'items/size',
            'items/timeCreated',
            'items/updated',
            'nextPageToken', // <- automatically handled if we pass the "nextPageToken" option
        ];
        // => we have the whole list. If we do not give "nextPageToken" we are going to have only the first 1000 objects
        // @see https://stackoverflow.com/questions/44275034/how-to-get-the-nextpagetoken-value-from-the-objects-method
        // Documentation is ambiguous about "nextPageToken"
        // @see https://cloud.google.com/storage/docs/json_api/v1/buckets/list

        return $this->getBucket()->objects([
            'prefix' => $folderIdentifier === self::ROOT_FOLDER_IDENTIFIER
                ? ''
                : GooglePathUtility::normalizeGoogleFolderPath($folderIdentifier),
            'delimiter' => DIRECTORY_SEPARATOR,
            'fields' => implode(',', $fields),
        ]);
    }

    /**
     * @param string $folderIdentifier
     * @param bool $hideKeepFile We don't want the .keep special files to be listed
     *
     * @return array
     * @throws Exception\InvalidPathException
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function getDataObjects(string $folderIdentifier, bool $hideKeepFile = true): array
    {
        $files = [];
        /** @var StorageObject $object */
        foreach ($this->getObjects($folderIdentifier) as $object) {
            // We remote the hidden ".keep" file from the list
            if (!$hideKeepFile || !$this->isKeepFile($object->name())) {
                $objectData = $object->info();

                // Compute file identifier
                $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier(
                    GooglePathUtility::computeFileIdentifier($objectData['name'])
                );

                // We must explicitly ask the API to retrieve more info about the object.
                // For instance, we need 'mediaLink' which will then be used to compute the public URL.
                if (!$objectData['mediaLink']) {
                    $objectData = $this->getObject($fileIdentifier)->info();
                }

                $files[$fileIdentifier] = $objectData;
            }
        }


        // Add result into typo3 cache to spare API calls next time...
        $this->getCache()->setCachedFiles($folderIdentifier, $files);

        return $files;
    }

    /**
     * @param $fileName
     *
     * @return bool
     */
    protected function isKeepFile($fileName): bool
    {
        return (bool)preg_match('/\.keep$/', $fileName);
    }

    /**
     * @return Bucket
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function getBucket(): Bucket
    {
        $bucketName = $this->getConfiguration('bucketName');
        if (empty($bucketName)) {
            throw new Exception(
                'Missing the bucket name. Please add one in the driver configuration record.',
                1446553056
            );
        }

        return $this->getClient()->bucket($bucketName);
    }

    /**
     * Initialize the dear client.
     *
     * @return StorageClient
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function getClient(): StorageClient
    {
        $configuredPrivateKeyFile = $this->getConfiguration('privateKeyJsonPathAndFileName');
        if (empty($configuredPrivateKeyFile)) {
            throw new Exception(
                'Missing the Google Cloud Storage private key stored in a JSON file. Next step is to add one in the driver record.',
                1446553055
            );
        }

        if (strpos($configuredPrivateKeyFile, '/') !== 0) {
            $privateKeyPathAndFilename = realpath(
                PATH_site . $configuredPrivateKeyFile
            );
        } else {
            $privateKeyPathAndFilename = $configuredPrivateKeyFile;
        }

        if (!file_exists($privateKeyPathAndFilename)) {
            throw new Exception(
                sprintf(
                    'The Google Cloud Storage private key file "%s" does not exist. Either the file is missing or you need to adjust your settings.',
                    $privateKeyPathAndFilename
                ),
                1446553054
            );
        }
        $googleCloud = new ServiceBuilder([
            'keyFilePath' => $privateKeyPathAndFilename
        ]);

        return $googleCloud->storage();
    }

    /**
     * @param string $fileIdentifier
     *
     * @return array|false
     */
    protected function getObjectData(string $fileIdentifier)
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier(
            GeneralUtility::dirname($fileIdentifier)
        );

        // Warm up the cache!
        #if (!isset($this->cachedGoogleCloudStorageResources[$folderIdentifier][$fileIdentifier])) {
        #    $this->getFilesInFolder($folderIdentifier, 0, -1);
        #}

        $objectData = [];
        if (isset($this->cachedGoogleCloudStorageResources[$folderIdentifier][$fileIdentifier])) {
            $objectData = $this->cachedGoogleCloudStorageResources[$folderIdentifier][$fileIdentifier];
        } elseif ($this->getObject($fileIdentifier)->exists()) { // call the API
            $objectData = $this->getObject($fileIdentifier)->info();
        }

        return empty($objectData)
            ? false
            : $objectData;
    }

    /**
     * @param string $fileIdentifier
     *
     * @return StorageObject
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function getObject(string $fileIdentifier)
    {
        return $this->getBucket()->object(
            GooglePathUtility::normalizeGooglePath($fileIdentifier)
        );
    }

    /**
     * @param string $fileIdentifier
     * @param string $newName
     *
     * @return string
     */
    protected function computeNewFileNameAndIdentifier(string $fileIdentifier, string $newName): string
    {
        $strippedPath = rtrim(PathUtility::dirname($fileIdentifier), DIRECTORY_SEPARATOR);
        return $strippedPath . DIRECTORY_SEPARATOR . $this->sanitizeFileName($newName);
    }

    /**
     * Gets the charset conversion object.
     *
     * @return \TYPO3\CMS\Core\Charset\CharsetConverter
     */
    protected function getCharsetConversion()
    {
        if (!isset($this->charsetConversion)) {
            $this->charsetConversion = GeneralUtility::makeInstance(CharsetConverter::class);
        }
        return $this->charsetConversion;
    }

    /**
     * Test the connection
     */
    protected function testConnection()
    {
        $messageQueue = $this->getMessageQueue();
        $localizationPrefix = $this->languageFile . ':driverConfiguration.message.';
        try {
            $isWritable = $this->getBucket()->isWritable();
            /** @var FlashMessage $message */
            $message = GeneralUtility::makeInstance(
                FlashMessage::class,
                LocalizationUtility::translate($localizationPrefix . 'connectionTestSuccessful.message'),
                LocalizationUtility::translate($localizationPrefix . 'connectionTestSuccessful.title'),
                FlashMessage::OK
            );
            $messageQueue->addMessage($message);

            if (!$isWritable) {
                /** @var FlashMessage $warning */
                $warning = GeneralUtility::makeInstance(
                    FlashMessage::class,
                    LocalizationUtility::translate($localizationPrefix . 'connectionTestNotWritable.message'),
                    LocalizationUtility::translate($localizationPrefix . 'connectionTestNotWritable.title'),
                    FlashMessage::WARNING
                );
                $messageQueue->addMessage($warning);
            }
        } catch (\Exception $exception) {
            /** @var FlashMessage $message */
            $message = GeneralUtility::makeInstance(
                FlashMessage::class,
                $exception->getMessage(),
                LocalizationUtility::translate($localizationPrefix . 'connectionTestFailed.title'),
                FlashMessage::WARNING
            );
            $messageQueue->addMessage($message);
        }
    }

    /**
     * @return \TYPO3\CMS\Core\Messaging\FlashMessageQueue
     */
    protected function getMessageQueue()
    {
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        /** @var FlashMessageService $flashMessageService */
        $flashMessageService = $objectManager->get(FlashMessageService::class);
        return $flashMessageService->getMessageQueueByIdentifier();
    }

    /**
     * Checks if an object exists
     *
     * @param string $fileIdentifier
     *
     * @return bool
     */
    protected function objectExists(string $fileIdentifier)
    {
        $object = $this->getObjectData($fileIdentifier);
        if (empty($object)) {
            $this->log(
                'Method "objectExists": I could not find file with identifier "%s"',
                [
                    $fileIdentifier,
                ]
            );
        }
        return !empty($object);
    }

    /**
     * @return void
     */
    protected function flushCache(): void
    {
        $this->flushFolderCache();
        $this->flushFileCache();
    }

    /**
     * @return void
     */
    protected function flushFileCache(): void
    {
        // Flush the file cache entries
        $this->getCache()->flushFileCache();

        $this->cachedGoogleCloudStorageResources = [];
    }

    /**
     * @return void
     */
    protected function flushFolderCache(): void
    {
        // Flush the file cache entries
        $this->getCache()->flushFolderCache();

        $this->cachedFolders = [];
    }

    /**
     * @return GoogleCloudStorageTypo3Cache|object
     */
    protected function getCache()
    {
        if ($this->googleCloudStorageTypo3Cache === null) {
            $this->googleCloudStorageTypo3Cache = GeneralUtility::makeInstance(GoogleCloudStorageTypo3Cache::class, (int)$this->storageUid);
        }
        return $this->googleCloudStorageTypo3Cache;
    }
}
