<?php

/*
 * This file is part of the Visol/GoogleCloudFactory project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

namespace Visol\GoogleCloudStorage;

use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * Class GoogleCloudFactory
 */
class GoogleCloudFactory extends \Exception
{

    /**
     * @return ResourceStorage
     */
    public static function getDefaultStorage(): ResourceStorage
    {
        // TODO: change me after typo3 v9 migration
        //       GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('default_cloudinary_storage')
        $extensionConfiguration = (array)unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['google_cloud_storage']);
        $defaultGoogleCloudStorageUid = (int)$extensionConfiguration['default_google_cloud_storage'];
        return ResourceFactory::getInstance()->getStorageObject($defaultGoogleCloudStorageUid);
    }
}
