<?php

namespace Visol\GoogleCloudStorage\Slots;

/*
 * This file is part of the Visol/GoogleCloudStorage project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ResourceStorageSlot
{

    // We want to remove all processed files
    public function preFileReplace(File $file, string $localFilePath)
    {
        /** @var $processedFileRepository \TYPO3\CMS\Core\Resource\ProcessedFileRepository */
        $processedFileRepository = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\ProcessedFileRepository::class);
        $processedFiles = $processedFileRepository->findAllByOriginalFile($file);
        foreach ($processedFiles as $processedFile) {
            $processedFile->delete();
        }

    }

}
