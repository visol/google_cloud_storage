<?php

namespace Visol\GoogleCloudStorage\Command;

/*
 * This file is part of the Visol/GoogleCloudStorage project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use Doctrine\DBAL\Driver\Connection;
use Google\Cloud\Core\ServiceBuilder;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use TYPO3\CMS\Core\Resource\Exception;
use Visol\GoogleCloudStorage\Driver\GoogleCloudStorageDriver;
use Visol\GoogleCloudStorage\Services\ConfigurationService;
use Visol\GoogleCloudStorage\Utility\GooglePathUtility;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\Command;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class GcsMoveCommand
 */
class GcsMoveCommand extends Command
{

    const WARNING = 'warning';

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var ResourceStorage
     */
    protected $sourceStorage;

    /**
     * @var ResourceStorage
     */
    protected $targetStorage;

    /**
     * @var array
     */
    protected $missingFiles = [];

    /**
     * @var string
     */
    protected $tableName = 'sys_file';

    /**
     * @var array
     */
    private $configuration = [];

    /**
     * @var array
     */
    protected $hasFolders = [];

    /**
     * Configure the command by defining the name, options and arguments
     */
    protected function configure()
    {
        $message = 'Move bunch of images from a local storage to a googleCloudStorage storage.';
        $message .= ' CAUTIOUS!';
        $message .= ' 1. Moving means: we are "manually" (not using the FAL API) uploading a file';
        $message .= ' to the GoogleCloudStorage storage and "manually" deleting the one from the local storage';
        $message .= ' Finally we are changing the `sys_file.storage value` to the googleCloudStorage storage.';
        $message .= ' Consequently, the file uid will be kept.';
        $message .= ' 2. The FE might break. Migrate your code that use VH `<f:image />` to `<c:googleCloudStorageImage />`';
        $this
            ->setDescription(
                $message
            )
            ->addOption(
                'silent',
                's',
                InputOption::VALUE_OPTIONAL,
                'Mute output as much as possible',
                false
            )
            ->addOption(
                'yes',
                'y',
                InputOption::VALUE_OPTIONAL,
                'Accept everything by default',
                false
            )
            ->addOption(
                'filter',
                '',
                InputArgument::OPTIONAL,
                'Filter pattern with possible wild cards, --filter="%.pdf"',
                ''
            )
            ->addOption(
                'limit',
                '',
                InputArgument::OPTIONAL,
                'Add a possible offset, limit to restrain the number of files. e.g. 0,100',
                ''
            )
            ->addOption(
                'exclude',
                '',
                InputArgument::OPTIONAL,
                'Exclude pattern, can contain comma separated values e.g. --exclude="/apps/%,/_temp/%"',
                ''
            )
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Source storage identifier'
            )
            ->addArgument(
                'target',
                InputArgument::REQUIRED,
                'Target storage identifier'
            )
            ->setHelp(
                'Usage: ./vendor/bin/typo3 googleCloudStorage:move 1 2'
            );
    }

    /**
     * Initializes the command after the input has been bound and before the input
     * is validated.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->options = $input->getOptions();

        $this->sourceStorage = ResourceFactory::getInstance()->getStorageObject(
            $input->getArgument('source')
        );
        $this->targetStorage = ResourceFactory::getInstance()->getStorageObject(
            $input->getArgument('target')
        );

        // Compute the absolute file name of the file to move
        $this->configuration = $this->targetStorage->getConfiguration();
    }

    /**
     * Move file
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->checkDriverType()) {
            $this->log('Look out! target storage is not of type "google cloud storage"');
            return 1;
        }

        $files = $this->getFiles($input);

        if (count($files) === 0) {
            $this->log('No files found, no work for me!');
            return 0;
        }

        $this->log(
            'I will move %s files from storage "%s" (%s) to "%s" (%s)',
            [
                count($files),
                $this->sourceStorage->getUid(),
                $this->sourceStorage->getName(),
                $this->targetStorage->getUid(),
                $this->targetStorage->getName(),
            ]
        );

        // A chance to the user to confirm the action
        if ($input->getOption('yes') === false) {
            $response = $this->io->confirm('Shall I continue?', true);

            if (!$response) {
                $this->log('Script aborted');
                return 0;
            }
        }

        $this->log(
            'Moving %s files from storage "%s" (%s) to "%s" (%s)',
            [
                count($files),
                $this->sourceStorage->getName(),
                $this->sourceStorage->getUid(),
                $this->targetStorage->getName(),
                $this->targetStorage->getUid(),
            ]
        );

        $counter = 0;
        foreach ($files as $file) {
            $fileObject = ResourceFactory::getInstance()->getFileObjectByStorageAndIdentifier(
                $this->sourceStorage->getUid(),
                $file['identifier']
            );

            $fileNameAndAbsolutePath = $this->getAbsolutePath($fileObject);
            if (file_exists($fileNameAndAbsolutePath)) {
                $this->log('Moving %s', [$file['identifier']]);

                // Create a folder if it does not yet exist. This will create an empty ".keep" file
                $targetFolder = $fileObject->getParentFolder()->getIdentifier();
                if (!in_array($targetFolder, $this->hasFolders) && !$this->targetStorage->hasFolder($targetFolder)) {
                    $this->targetStorage->createFolder($targetFolder);
                    $this->hasFolders[] = $targetFolder;
                }

                // Upload the file storage
                $isUploaded = $this->googleCloudStorageUploadFile($fileObject);

                if ($isUploaded) {
                    // Update the storage uid
                    $isUpdated = $this->updateFile(
                        $fileObject,
                        [
                            'storage' => $this->targetStorage->getUid()
                        ]
                    );

                    if ($isUpdated) {
                        // Delete the file form the local storage
                        unlink($fileNameAndAbsolutePath);
                    }
                }

                $counter++;
            } else {

                $this->log('Missing file %s', [$fileObject->getIdentifier()], self::WARNING);
                // We could log the missing files
                $this->missingFiles[] = $fileObject->getIdentifier();
            }
        }

        $this->log(LF);
        $this->log('Number of files moved: %s', [$counter]);

        // Write possible log
        if ($this->missingFiles) {
            $this->writeLog('missing', $this->missingFiles);
        }

        return 0;
    }

    /**
     * @return bool
     */
    protected function checkDriverType(): bool
    {
        return $this->targetStorage->getDriverType() === GoogleCloudStorageDriver::DRIVER_TYPE;
    }

    /**
     * @param string $type
     * @param array $files
     */
    protected function writeLog(string $type, array $files)
    {
        $logFileName = sprintf(
            '/tmp/%s-files-%s-%s-log',
            $type,
            getmypid(),
            uniqid()
        );

        // Write log file
        file_put_contents($logFileName, var_export($files, true));

        // Display the message
        $this->log(
            'Pay attention, I have found %s %s files. A log file has been written at %s',
            [
                $type,
                count($files),
                $logFileName,
            ],
            self::WARNING
        );
    }

    /**
     * @param File $fileObject
     *
     * @return string
     */
    protected function getAbsolutePath(File $fileObject): string
    {
        // Compute the absolute file name of the file to move
        $configuration = $this->sourceStorage->getConfiguration();
        $fileRelativePath = rtrim($configuration['basePath'], '/') . $fileObject->getIdentifier();
        return GeneralUtility::getFileAbsFileName($fileRelativePath);
    }

    /**
     * @param File $fileObject
     *
     * @return bool
     */
    protected function googleCloudStorageUploadFile(File $fileObject): bool
    {
        return (bool)$this->getBucket()->upload(
            file_get_contents($this->getAbsolutePath($fileObject)), // $fileObject->getContents()
            [
                'name' => GooglePathUtility::normalizeGooglePath($fileObject->getIdentifier())
            ]
        );
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
        $googleCloud = new ServiceBuilder(
            [
                'keyFilePath' => $privateKeyPathAndFilename
            ]
        );

        return $googleCloud->storage();
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
     * @return object|QueryBuilder
     */
    protected function getQueryBuilder(): QueryBuilder
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        return $connectionPool->getQueryBuilderForTable($this->tableName);
    }

    /**
     * @return object|Connection
     */
    protected function getConnection(): Connection
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        return $connectionPool->getConnectionForTable($this->tableName);
    }

    /**
     * @param InputInterface $input
     *
     * @return array
     */
    protected function getFiles(InputInterface $input): array
    {
        $query = $this->getQueryBuilder();
        $query
            ->select('*')
            ->from($this->tableName)
            ->where(
                $query->expr()->eq('storage', $this->sourceStorage->getUid()),
                $query->expr()->eq('missing', 0)
            );

        // Possible custom filter
        if ($input->getOption('filter')) {
            $query->andWhere(
                $query->expr()->like(
                    'identifier',
                    $query->expr()->literal($input->getOption('filter'))
                )
            );
        }

        // Possible custom exclude
        if ($input->getOption('exclude')) {
            $expressions = GeneralUtility::trimExplode(',', $input->getOption('exclude'));
            foreach ($expressions as $expression) {
                $query->andWhere(
                    $query->expr()->notLike(
                        'identifier',
                        $query->expr()->literal($expression)
                    )
                );
            }
        }

        // Set a possible offset, limit
        if ($input->getOption('limit')) {
            [$offsetOrLimit, $limit] = GeneralUtility::trimExplode(
                ',',
                $input->getOption('limit'),
                true
            );

            if ($limit !== null) {
                $query->setFirstResult((int)$offsetOrLimit);
                $query->setMaxResults((int)$limit);
            } else {
                $query->setMaxResults((int)$offsetOrLimit);
            }
        }

        return $query->execute()->fetchAll();
    }

    /**
     * @param File $fileObject
     * @param array $values
     *
     * @return int
     */
    protected function updateFile(File $fileObject, array $values): int
    {
        $connection = $this->getConnection();
        return $connection->update(
            $this->tableName,
            $values,
            [
                'uid' => $fileObject->getUid(),
            ]
        );
    }

    /**
     * @param string $message
     * @param array $arguments
     * @param string $severity can be 'warning', 'error', 'success'
     */
    protected function log(string $message = '', array $arguments = [], $severity = '')
    {
        if (!$this->isSilent()) {
            $formattedMessage = vsprintf($message, $arguments);
            if ($severity) {
                $this->io->$severity($formattedMessage);
            } else {
                $this->io->writeln($formattedMessage);
            }
        }
    }

    /**
     * @param string $message
     * @param array $arguments
     */
    protected function warning(string $message = '', array $arguments = [])
    {
        $this->log($message, $arguments, self::WARNING);
    }

    /**
     * @return bool
     */
    protected function isSilent(): bool
    {
        return $this->options['silent'] !== false;
    }
}
