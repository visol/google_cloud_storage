<?php

defined('TYPO3_MODE') || die('Access denied.');
call_user_func(
    function () {

        \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScript(
            'google_cloud_storage',
            'setup',
            '<INCLUDE_TYPOSCRIPT: source="FILE:EXT:google_cloud_storage/Configuration/TypoScript/setup.typoscript">'
        );

        \TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
            'Visol.GoogleCloudStorage',
            'Cache',
            [
                'GoogleCloudStorageTypo3CacheManager' => 'flush',
            ],
            // non-cacheable actions
            [
                'GoogleCloudStorageTypo3CacheManager' => 'flush',
            ]
        );

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers'][\Visol\GoogleCloudStorage\Driver\GoogleCloudStorageDriver::DRIVER_TYPE] = [
            'class' => \Visol\GoogleCloudStorage\Driver\GoogleCloudStorageDriver::class,

            'flexFormDS' => 'FILE:EXT:google_cloud_storage/Configuration/FlexForm/GoogleCloudStorageFlexForm.xml',
            'label' => 'Google Cloud Storage',
            'shortName' => \Visol\GoogleCloudStorage\Driver\GoogleCloudStorageDriver::DRIVER_TYPE,
        ];

        $GLOBALS['TYPO3_CONF_VARS']['LOG']['Visol']['GoogleCloudStorage']['Cache']['writerConfiguration'] =
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['Visol']['GoogleCloudStorage']['Driver']['writerConfiguration'] = [

            // configuration for WARNING severity, including all
            // levels with higher severity (ERROR, CRITICAL, EMERGENCY)
            \TYPO3\CMS\Core\Log\LogLevel::INFO => [
                \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                    // configuration for the writer
                    'logFile' => 'typo3temp/var/logs/google-cloud-storage.log'
                ],
            ],
        ];

        if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['google_cloud_storage'])) {
            // cache configuration, see https://docs.typo3.org/typo3cms/CoreApiReference/ApiOverview/CachingFramework/Configuration/Index.html#cache-configurations
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['google_cloud_storage']['frontend'] = \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class;
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['google_cloud_storage']['groups'] = ['all', 'google_cloud_storage'];
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['google_cloud_storage']['options']['defaultLifetime'] = 2592000;
        }
    }
);
