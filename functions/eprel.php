<?php

include_once __DIR__ . '/../nh_connectors/include.php';
include_once(__DIR__."/../eprel_config.php");

use nh_connectors\Connectors\EprelAPI;
use nh_connectors\Connectors\SQLConnector;
use nh_connectors\Utils\Logger;

use function nh_connectors\Utils\purgeDirectory;
use function nh_connectors\Utils\unzipToSameNameFolder;
use function nh_connectors\Utils\json_reduce_columns;
use function nh_connectors\sql;

/**
 * Download EPREL product group ZIPs
 */
function eprel_download_zip_data(EprelAPI $api, array $productGroups, string $zipBasePath, int $sleepBetween = 5): void
{
    Logger::logNormal("Deleting old zips and folders", [], Logger::LEVEL_NORMAL);
    purgeDirectory($zipBasePath);

    Logger::logNormal("Downloading EPREL product group ZIPs", [], Logger::LEVEL_NORMAL);

    foreach ($productGroups as $group) {
        Logger::logNormal("Downloading product group: {$group}", [], Logger::LEVEL_NORMAL);

        $result = $api->downloadProductGroupZip($group, [
            'fileOutput' => [
                'folderPath' => $zipBasePath,
                'filename' => $group
            ],
        ], false); // false = auto-save

        if ($result) {
            Logger::logNormal("File saved: {$zipBasePath}/{$group}.zip", [], Logger::LEVEL_NORMAL);
        } else {
            Logger::logNormal("Failed to download {$group}", [], Logger::LEVEL_NORMAL);
        }

        // Sleep between API calls
        usleep($sleepBetween);
    }
}


/**
 * Process and reduce EPREL JSON data
 */
function eprel_process_zip_data(string $zipBasePath, array $keepColumns, bool $debug = false): void
{
    Logger::logNormal("Unzipping downloaded files", [], Logger::LEVEL_NORMAL);

    foreach (scandir($zipBasePath) as $entry) {
        if (in_array($entry, ['.', '..'])) continue;
        $zipPath = "{$zipBasePath}/{$entry}";
        if (is_file($zipPath) && str_ends_with($zipPath, '.zip')) {
            Logger::logNormal("Unzipping {$zipPath}", [], Logger::LEVEL_VERBOSE);
            unzipToSameNameFolder($zipPath);  // Only call once
            Logger::logNormal("Finished unzipping {$zipPath}", [], Logger::LEVEL_VERBOSE);
        }
    }
    

    Logger::logNormal("Processing unzipped JSON files", [], Logger::LEVEL_NORMAL);

    foreach (scandir($zipBasePath) as $folder) {
        if (in_array($folder, ['.', '..'])) continue;
        $folderPath = "{$zipBasePath}/{$folder}";
        if (!is_dir($folderPath)) continue;

        foreach (scandir($folderPath) as $file) {
            if (in_array($file, ['.', '..'])) continue;
            $fullPath = "{$folderPath}/{$file}";
            if (!is_file($fullPath)) continue;

            $resultPath = "{$fullPath}_reduced.json";
            Logger::logNormal("Reducing {$fullPath} → {$resultPath}", [], Logger::LEVEL_VERBOSE);
            json_reduce_columns($keepColumns, $fullPath, $resultPath);
        }
    }
}

/**
 * Push processed EPREL data to SQL database
 */
function eprel_push_to_sql(string $zipBasePath, bool $debug = false): void
{
    Logger::logNormal("Clearing EPREL_rawProductData table", [], Logger::LEVEL_NORMAL);
    sql('low')->runSQL("DELETE FROM Prozessdaten.dbo.EPREL_rawProductData;");

    foreach (scandir($zipBasePath) as $folder) {
        if (in_array($folder, ['.', '..'])) continue;
        $folderPath = "{$zipBasePath}/{$folder}";
        if (!is_dir($folderPath)) continue;

        foreach (scandir($folderPath) as $file) {
            if (in_array($file, ['.', '..'])) continue;
            $fullPath = "{$folderPath}/{$file}";
            if (!is_file($fullPath)) continue;

            $reducedFile = "{$fullPath}_reduced.json";
            if (!file_exists($reducedFile)) continue;

            Logger::logNormal("Importing {$reducedFile} into SQL", [], Logger::LEVEL_VERBOSE);
            sql('low')->filetosql($reducedFile, "Prozessdaten.dbo.EPREL_rawProductData", [
                "EprelRegistrationNumber" => "eprelRegistrationNumber",
                "EPRELProductGroup" => "productGroup",
                "ModelIdentifier" => "modelIdentifier",
                "OrganisationTitle" => "organisation.organisationTitle",
                "OrganisationName" => "organisation.organisationName",
                "EnergyClass" => "energyClass",
                "EnergyClassImage" => "energyClassImage",
                "EnergyClassImageWithScale" => "energyClassImageWithScale"
            ]);
        }
    }
}