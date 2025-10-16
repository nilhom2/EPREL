<?php

include_once __DIR__ . '/../NH_Core/include.php';

use NH_Core\Connectors\EprelAPI;
use NH_Core\Utils\Logger;

use function NH_Core\Utils\purgeDirectory;
use function NH_Core\Utils\unzipToSameNameFolder;

/**
 * Download EPREL product group ZIPs
 */
function eprel_download_data(EprelAPI $api, array $productGroups, string $zipBasePath, bool $debug = false, int $sleepBetween = 5): void
{
    Logger::logGlobal("Deleting old zips and folders", [], Logger::LEVEL_NORMAL);
    purgeDirectory($zipBasePath);

    Logger::logGlobal("Downloading EPREL product group ZIPs", [], Logger::LEVEL_NORMAL);
    foreach ($productGroups as $group) {
        Logger::logGlobal("Downloading product group: {$group}", [], Logger::LEVEL_NORMAL);
        $api->downloadProductGroupZip($group, [
            'fileOutput' => [
                'folderPath' => $zipBasePath,
                'filename' => $group
            ],
            'debug' => $debug,
        ]);
        sleep($sleepBetween);
    }
}

/**
 * Process and reduce EPREL JSON data
 */
function eprel_process_data(string $zipBasePath, array $keepColumns, bool $debug = false): void
{
    Logger::logGlobal("Unzipping downloaded files", [], Logger::LEVEL_NORMAL);

    foreach (scandir($zipBasePath) as $entry) {
        if (in_array($entry, ['.', '..'])) continue;
        $zipPath = "{$zipBasePath}/{$entry}";
        if (is_file($zipPath) && str_ends_with($zipPath, '.zip')) {
            Logger::logGlobal("Unzipping {$zipPath}", [], Logger::LEVEL_VERBOSE);
            unzipToSameNameFolder($zipPath);

            Logger::logGlobal("Unzipping {$zipPath}", [], Logger::LEVEL_VERBOSE);
            unzipToSameNameFolder($zipPath);
            Logger::logGlobal("Finished unzipping {$zipPath}", [], Logger::LEVEL_VERBOSE);

        }
    }

    Logger::logGlobal("Processing unzipped JSON files", [], Logger::LEVEL_NORMAL);

    foreach (scandir($zipBasePath) as $folder) {
        if (in_array($folder, ['.', '..'])) continue;
        $folderPath = "{$zipBasePath}/{$folder}";
        if (!is_dir($folderPath)) continue;

        foreach (scandir($folderPath) as $file) {
            if (in_array($file, ['.', '..'])) continue;
            $fullPath = "{$folderPath}/{$file}";
            if (!is_file($fullPath)) continue;

            $resultPath = "{$fullPath}_reduced.json";
            Logger::logGlobal("Reducing {$fullPath} → {$resultPath}", [], Logger::LEVEL_VERBOSE);
            json_reduce_columns($keepColumns, $fullPath, $resultPath);
        }
    }
}

/**
 * Push processed EPREL data to SQL database
 */
function eprel_push_to_sql(string $zipBasePath, bool $debug = false): void
{
    Logger::logGlobal("Clearing EPREL_rawProductData table", [], Logger::LEVEL_NORMAL);
    runSQL("DELETE FROM Prozessdaten.dbo.EPREL_rawProductData;");

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

            Logger::logGlobal("Importing {$reducedFile} into SQL", [], Logger::LEVEL_VERBOSE);
            filetosql($reducedFile, "Prozessdaten.dbo.EPREL_rawProductData", [
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

/**
 * Download individual product files (energy label, datasheet, icon)
 */
function eprel_download_product_files(EprelAPI $api, array $productData, bool $redownload = false): void
{
    if (count($productData) === 0) {
        Logger::logGlobal("No product data provided. Exiting.", [], Logger::LEVEL_NORMAL);
        return;
    }

    foreach ($productData as $row) {
        $regNum = $row["EprelRegistrationNumber"];
        $energyIconFilename = $row["EnergyIconFilename"];

        // Energy label
        $api->downloadEnergyLabel($regNum, [
            'fileOutput' => [
                'folderPath' => './files/energylabels',
                'filename' => $regNum
            ]
        ]);

        // Datasheet
        $api->downloadDatasheet($regNum, [
            'fileOutput' => [
                'folderPath' => './files/datasheets',
                'filename' => $regNum
            ]
        ]);

        // Energy icon
        $api->downloadEnergyIcon($regNum, [
            'fileOutput' => [
                'folderPath' => './files/energyicons',
                'filename' => str_replace(".svg", "", $energyIconFilename)
            ]
        ]);

        if ($redownload) {
            Logger::logGlobal("Redownloaded product {$regNum}", [], Logger::LEVEL_NORMAL);
        }
    }
}
