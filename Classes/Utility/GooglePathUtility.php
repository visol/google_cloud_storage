<?php

namespace Visol\GoogleCloudStorage\Utility;

/*
 * This file is part of the Visol/GoogleCloudStorage project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Class GooglePathUtility
 */
class GooglePathUtility
{

    private const KEEP_FILE = '.keep';

    /**
     * @param string $filePath
     * @return string
     */
    public static function computeFileIdentifier(string $filePath): string
    {
        return sprintf(
            '%s',
            DIRECTORY_SEPARATOR . ltrim($filePath, DIRECTORY_SEPARATOR)
        );
    }

    /**
     * @param string $fileIdentifier
     * @return string
     */
    public static function normalizeGooglePath(string $fileIdentifier): string
    {
        return trim($fileIdentifier, DIRECTORY_SEPARATOR);
    }


    /**
     * @param string $fileIdentifier
     * @return string
     */
    public static function normalizeGoogleFolderPath(string $fileIdentifier): string
    {
        return trim($fileIdentifier, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * @param string $folderPath
     * @param string $fileIdentifier
     * @return string
     */
    public static function combineFolderAndFile(string $folderPath, string $fileIdentifier): string
    {
        $normalizedFolderPath = self::normalizeGoogleFolderPath($folderPath);
        return self::normalizeGooglePath(
            $normalizedFolderPath . PathUtility::basename($fileIdentifier)
        );
    }

    /**
     * @param string $folderIdentifier
     * @param string $folderName
     * @return string
     */
    public static function combineFolderAndFolderName(string $folderIdentifier, string $folderName): string
    {
        $normalizedFolderPath = self::normalizeGoogleFolderPath($folderIdentifier);
        return self::normalizeGooglePath(
            $normalizedFolderPath . $folderName
        );
    }

    /**
     * @param string $folderIdentifier
     * @return string
     */
    public static function getFinalFolderIdentifier(string $folderIdentifier): string
    {
        return self::combineFolderAndfile($folderIdentifier, self::KEEP_FILE);
    }
}
