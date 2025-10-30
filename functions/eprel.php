<?php

include_once __DIR__ . '/../nh_connectors/include.php';
include_once(__DIR__."/../eprel_config.php");

use nh_connectors\Connectors\EprelAPI;
use nh_connectors\Connectors\SQLConnector;
use nh_connectors\Utils\Logger;
use function nh_connectors\sql;

/**
 * Download EPREL product group ZIPs
 */
function eprel_download_zip_data($eprelApi, array $productGroups, string $zipBasePath, int $sleepBetweenCalls = 5): void
{
    // Ensure the directory exists
    if (!is_dir($zipBasePath)) {
        if (!mkdir($zipBasePath, 0777, true) && !is_dir($zipBasePath)) {
            Logger::logError("Failed to create directory: $zipBasePath");
            return;
        }
        Logger::logNormal("Created directory: $zipBasePath");
    }

    $totalGroups = count($productGroups);
    $successCount = 0;
    $failCount = 0;
    $failedGroups = [];
    
    Logger::logImportant("Starting download of $totalGroups product groups...");
    Logger::logNormal("Product groups: " . implode(', ', $productGroups));

    foreach ($productGroups as $index => $productGroup) {
        $groupNum = $index + 1;
        Logger::logNormal("[$groupNum/$totalGroups] Processing: $productGroup");

        try {
            $result = $eprelApi->downloadProductGroupZip($productGroup, [
                'fileOutput' => [
                    'folderPath' => $zipBasePath,
                    'filename' => $productGroup
                ]
            ]);

            if ($result['success'] ?? false) {
                $successCount++;
                Logger::logImportant("✅ [$groupNum/$totalGroups] Successfully downloaded: $productGroup ({$result['fileSize']} bytes)");
            } else {
                $failCount++;
                $error = $result['error'] ?? 'Unknown error';
                Logger::logError("❌ [$groupNum/$totalGroups] Failed to download $productGroup: $error");
            }

        } catch (\Exception $e) {
            $failCount++;
            Logger::logError("❌ [$groupNum/$totalGroups] Exception for $productGroup: " . $e->getMessage());
        }

        // Sleep between calls (except after the last one)
        if ($groupNum < $totalGroups && $sleepBetweenCalls > 0) {
            Logger::logNormal("Sleeping for {$sleepBetweenCalls}s before next download...");
            sleep($sleepBetweenCalls);
        }
    }

    Logger::logImportant("Download summary: $successCount successful, $failCount failed out of $totalGroups total");

    if ($failCount > 0) {
        Logger::logError("⚠️ Some downloads failed. Check the log for details.");
    }
}


/**
 * Process and reduce EPREL JSON data
 */
function eprel_process_zip_data(string $zipBasePath, array $keepColumns, bool $debug = false): void
{
    Logger::logNormal("Unzipping downloaded files", [], Logger::LEVEL_NORMAL);

    $zipFiles = [];
    foreach (scandir($zipBasePath) as $entry) {
        if (in_array($entry, ['.', '..'])) continue;
        $zipPath = "{$zipBasePath}/{$entry}";
        if (is_file($zipPath) && str_ends_with($zipPath, '.zip')) {
            $zipFiles[] = ['path' => $zipPath, 'name' => $entry];
        }
    }

    $totalZips = count($zipFiles);
    $currentZip = 0;

    foreach ($zipFiles as $zipInfo) {
        $currentZip++;
        $zipPath = $zipInfo['path'];
        $zipName = $zipInfo['name'];
        $fileSize = filesize($zipPath);
        $fileSizeMB = round($fileSize / 1024 / 1024, 2);
        
        Logger::logImportant("[$currentZip/$totalZips] Unzipping {$zipName} ({$fileSizeMB} MB)...");
        $startTime = microtime(true);
        
        try {
            unzipToSameNameFolder($zipPath);
            $duration = round(microtime(true) - $startTime, 2);
            Logger::logImportant("✅ [$currentZip/$totalZips] Finished unzipping {$zipName} in {$duration}s");
        } catch (\Exception $e) {
            Logger::logError("❌ Failed to unzip {$zipName}: " . $e->getMessage());
        }
    }
    
    Logger::logNormal("Processing unzipped JSON files", [], Logger::LEVEL_NORMAL);

    $jsonFiles = [];
    foreach (scandir($zipBasePath) as $folder) {
        if (in_array($folder, ['.', '..'])) continue;
        $folderPath = "{$zipBasePath}/{$folder}";
        if (!is_dir($folderPath)) continue;

        foreach (scandir($folderPath) as $file) {
            if (in_array($file, ['.', '..'])) continue;
            $fullPath = "{$folderPath}/{$file}";
            if (is_file($fullPath) && str_ends_with($fullPath, '.json') && !str_ends_with($fullPath, '_reduced.json')) {
                $jsonFiles[] = ['path' => $fullPath, 'name' => $file];
            }
        }
    }

    $totalJsonFiles = count($jsonFiles);
    $currentJson = 0;

    foreach ($jsonFiles as $jsonInfo) {
        $currentJson++;
        $fullPath = $jsonInfo['path'];
        $fileName = $jsonInfo['name'];
        $resultPath = "{$fullPath}_reduced.json";
        
        $fileSize = filesize($fullPath);
        $fileSizeMB = round($fileSize / 1024 / 1024, 2);
        
        Logger::logImportant("[$currentJson/$totalJsonFiles] Reducing {$fileName} ({$fileSizeMB} MB)...");
        $startTime = microtime(true);
        
        try {
            json_reduce_columns($keepColumns, $fullPath, $resultPath);
            $duration = round(microtime(true) - $startTime, 2);
            $reducedSize = filesize($resultPath);
            $reducedSizeMB = round($reducedSize / 1024 / 1024, 2);
            $reduction = round(100 - ($reducedSize / $fileSize * 100), 1);
            Logger::logImportant("✅ [$currentJson/$totalJsonFiles] Reduced to {$reducedSizeMB} MB ({$reduction}% reduction) in {$duration}s");
        } catch (\Exception $e) {
            Logger::logError("❌ Failed to reduce {$fileName}: " . $e->getMessage());
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

    $reducedFiles = [];
    foreach (scandir($zipBasePath) as $folder) {
        if (in_array($folder, ['.', '..'])) continue;
        $folderPath = "{$zipBasePath}/{$folder}";
        if (!is_dir($folderPath)) continue;

        foreach (scandir($folderPath) as $file) {
            if (in_array($file, ['.', '..'])) continue;
            $fullPath = "{$folderPath}/{$file}";
            if (!is_file($fullPath)) continue;

            $reducedFile = "{$fullPath}_reduced.json";
            if (file_exists($reducedFile)) {
                $reducedFiles[] = ['path' => $reducedFile, 'name' => basename($reducedFile)];
            }
        }
    }

    $totalFiles = count($reducedFiles);
    $currentFile = 0;

    foreach ($reducedFiles as $fileInfo) {
        $currentFile++;
        $reducedFile = $fileInfo['path'];
        $fileName = $fileInfo['name'];
        
        Logger::logImportant("[$currentFile/$totalFiles] Importing {$fileName} into SQL...");
        $startTime = microtime(true);
        
        try {
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
            $duration = round(microtime(true) - $startTime, 2);
            Logger::logImportant("✅ [$currentFile/$totalFiles] Imported {$fileName} in {$duration}s");
        } catch (\Exception $e) {
            Logger::logError("❌ Failed to import {$fileName}: " . $e->getMessage());
        }
    }
}