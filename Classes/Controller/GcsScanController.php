<?php

namespace Visol\GoogleCloudStorage\Controller;

/*
 * This file is part of the Visol/GoogleCloudStorage project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Visol\Cloudinary\Services\CloudinaryScanService;
use Visol\GoogleCloudStorage\Driver\GoogleCloudStorageFastDriver;

/**
 * Class GcsScanController
 */
class GcsScanController extends ActionController
{

    /**
     * @var \TYPO3\CMS\Core\Resource\StorageRepository
     * @inject
     */
    protected $storageRepository;

    /**
     * @return string
     */
    public function scanAction(): string
    {
        foreach ($this->storageRepository->findAll() as $storage) {
            if ($storage->getDriverType() === GoogleCloudStorageFastDriver::DRIVER_TYPE) {

                /** @var CloudinaryScanService $cloudinaryScanService */
                $cloudinaryScanService = GeneralUtility::makeInstance(
                    CloudinaryScanService::class,
                    $storage
                );
                $cloudinaryScanService->scan();
            }
        }
        return 'done';
    }

}
