<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(1);


include_once __DIR__ . '/nh_connectors/include.php';
include_once __DIR__ . '/functions/eprel.php';
include_once __DIR__ . '/eprel_config.php';

use nh_connectors\Connectors\EprelAPI;
use nh_connectors\Connectors\SQLManager;
use nh_connectors\ConnectorFactory;
use nh_connectors\Utils\Logger;

use function nh_connectors\sql;


/*
    INITIALIZE
*/
Logger::enableFileLogging();
Logger::setLogLevel(2);

$eprelApi = ConnectorFactory::createEprel();
$akeneoApi = ConnectorFactory::createAkeneo();
$shopwareApi = ConnectorFactory::createShopware('live');

/*
    START
*/
Logger::logGlobal("EPREL sync script started");

Logger::logGlobal("---1. Download EPREL data");
eprel_download_data($eprelApi, $EPREL_PRODUCT_GROUPS_ARRAY, $zip_basepath, $sleep_between_eprel_api_calls);

Logger::logGlobal("---2. Process EPREL data");
eprel_process_data($zip_basepath, $keep_columns);

Logger::logGlobal("---3. Push EPREL data to SQL");
eprel_push_to_sql($zip_basepath);

Logger::logGlobal("---4. exec Prozessdaten.dbo.EPREL_update;");
sql('low')->runSQL("exec Prozessdaten.dbo.EPREL_update;");

// --- Product Files Download --- //

$product_data_arr = sql('low')->runSQL("SELECT * FROM Prozessdaten.dbo.EPREL_tagID_to_EPRELRegistrationNumber ORDER BY ERPNr;");

if (empty($product_data_arr)) {
    Logger::logGlobal("No products found in the database. Exiting...");
    exit();
}

// Logger::logGlobal("---5. Download product files");
// $redownload = is_first_saturday_of_month();
// if ($redownload) Logger::logGlobal("First Saturday of the month: redownloading all data.");
// eprel_download_product_files($eprelApi, $product_data_arr, $redownload);

// $product_data_arr = sql('low')->runSQL("SELECT * FROM Prozessdaten.dbo.EPREL_tagID_to_EPRELRegistrationNumber WHERE TAGID != '' ORDER BY ERPNr;");





$cache = [];

Logger::logGlobal("---6. EPREL data -> Akeneo");
$product_data_arr = sql('low')->runSQL("SELECT * FROM Prozessdaten.dbo.EPREL_tagID_to_EPRELRegistrationNumber WHERE TAGID = 'A1000518060' ORDER BY ERPNr;");
foreach ($product_data_arr as $row) {
    $tagid = $row["TAGID"];
    $eprelRegNum = $row["EprelRegistrationNumber"];
    $eprelCategory = $row["EPRELProductGroup"];
    $energyicon_filename = $row["EnergyIconFilename"];

    // Lokale Pfade
    $energyLabelLocal = "$productfiles_basepath/$eprelRegNum/energylabel.png";
    $datasheetLocal   = "$productfiles_basepath/$eprelRegNum/datasheet.pdf";
    $energyIconLocal  = "$energyicons_basepath/$energyicon_filename";

    // Öffentliche URLs
    $energyLabelUrl = file_exists($energyLabelLocal) ? "$public_url_productFiles_basepath/$eprelRegNum/energylabel.png" : null;
    $datasheetUrl   = file_exists($datasheetLocal)   ? "$public_url_productFiles_basepath/$eprelRegNum/datasheet.pdf"   : null;
    $energyIconUrl  = file_exists($energyIconLocal)  ? "$public_url_energyicons_basepath/$energyicon_filename"         : null;

    // Upload nur durchführen, wenn Datei existiert
    if ($energyLabelUrl) {
        $akeneoApi->uploadEnergyLabel($tagid, $eprelRegNum, $eprelCategory);
    }
    if ($datasheetUrl) {
        $akeneoApi->uploadDatasheet($tagid, $eprelRegNum, $eprelCategory);
    }
    if ($energyIconUrl) {
        $akeneoApi->uploadEnergyIcon($energyicon_filename);
    }

    // Cache
    $cache[$tagid] = [
        'ENERGYLABEL_'.$tagid => ['url' => $energyLabelUrl],
        'DATASHEET_'.$tagid   => ['url' => $datasheetUrl],
        'ICON_'.str_replace("-", "_", str_replace(".svg", "", $energyicon_filename)) => ['url' => $energyIconUrl]
    ];

    Logger::logGlobal("Processed product $tagid");
}





Logger::logGlobal("---7. Shopmanager -> Akeneo");

$sm_products = sql('low')->runSQL("
    SELECT top 1 * 
    FROM [1WS].[dbo].[SM_Produkte]
    WHERE (ISNULL(energyLabel, '') != '' OR ISNULL(energyDatasheet, '') != '')
    and pimIdentifier = 'A1000518060'
");

if (!empty($sm_products)) {
    Logger::logGlobal("Found " . count($sm_products) . " products to patch to Akeneo.");

    foreach ($sm_products as $row) {
        $tagid = trim($row['pimIdentifier'] ?? $row['TAGID'] ?? '');

        if ($tagid === '') {
            Logger::logGlobal("Skipping product with missing TAGID/pimIdentifier", [], Logger::LEVEL_VERBOSE);
            continue;
        }

        $energyLabelUrl = trim($row['energyLabel'] ?? '');
        $datasheetUrl   = trim($row['energyDatasheet'] ?? '');

        Logger::logGlobal("Processing SM product $tagid", [
            'energyLabel' => $energyLabelUrl,
            'datasheet'   => $datasheetUrl
        ], Logger::LEVEL_VERBOSE);

        // --- Akeneo upload ---
        if ($energyLabelUrl !== '') {
            Logger::logGlobal("Uploading ENERGYLABEL for $tagid", [], Logger::LEVEL_VERBOSE);
            $akeneoApi->uploadEnergyLabel(
                $tagid,          // TAGID / product identifier
                '',              // EPREL registration number not needed for ShopManager
                '',              // EPREL category not needed
                'shopmanager',   // source
                $energyLabelUrl  // use the URL from ShopManager
            );

        }

        if ($datasheetUrl !== '') {
            Logger::logGlobal("Uploading DATASHEET for $tagid", [], Logger::LEVEL_VERBOSE);
            $akeneoApi->uploadDatasheet(
                $tagid,
                '', 
                '',
                'shopmanager',
                $datasheetUrl
            );

        }

        // Cache
        $cache[$tagid] = array_merge($cache[$tagid], [
            'ENERGYLABEL_SM_'.$tagid => ['url' => $energyLabelUrl],
            'DATASHEET_SM_'.$tagid   => ['url' => $datasheetUrl],
            'ICON_'.str_replace("-", "_", str_replace(".svg", "", $energyicon_filename)) => ['url' => $energyIconUrl]
        ]);

        Logger::logGlobal("Patched SM product $tagid to Akeneo.");
    }
} else {
    Logger::logGlobal("No SM_Produkte with energyLabel or datasheet found.");
}





/*
    Akeneo product asset association

    muss alles aufeinmal da akeneo sonst die anderen einträge löscht
*/

Logger::logGlobal("---8. Akeneo product asset association");

foreach ($cache as $tagid => $values) {
    // Filter nur die Einträge mit einer gültigen URL
    $filteredValues = [];
    foreach ($values as $key => $data) {
        if (!empty(trim($data['url'] ?? ''))) {
            $filteredValues[] = $key; // Key als Wert verwenden
        }
    }

    if (!empty($filteredValues)) {
        $akeneoApi->fillProductAssetCollection(
            'cnsc_energylabel',
            $tagid,
            $filteredValues
        );
    }

    Logger::logGlobal("cache for product $tagid", $values, Logger::LEVEL_VERBOSE);
}



/*
    Shopware Upload
*/  

Logger::logGlobal("---9. Shopware media upload and product association");

$sw_force = true;
$mediaFolderId = "308252dc56fe4b5c96ddb4bfd64e5ec0";

foreach ($cache as $tagid => $values) {
    $mediaMapping = [
        'DATASHEET' => null,
        'ENERGYLABEL' => null,
        'ICON' => null
    ];

    // ---------------------------
    // 1️⃣ DATASHEET & ENERGYLABEL: normal > SM
    foreach (['DATASHEET', 'ENERGYLABEL'] as $type) {
        $normalKey = $type . "_$tagid";
        $smKey     = $type . "_SM_$tagid";

        if (!empty($values[$normalKey]['url'] ?? null)) {
            $url = $values[$normalKey]['url'];
        } elseif (!empty($values[$smKey]['url'] ?? null)) {
            $url = $values[$smKey]['url'];
        } else {
            $url = null;
        }

        if ($url) {
            $extension = $type === 'DATASHEET' ? 'pdf' : 'png';
            $filename = $type . "_" . $tagid;
            $media = $shopwareApi->upload_media_by_url($url, $mediaFolderId, $filename, $extension, $sw_force);
            $mediaMapping[$type] = $media['mediaId'] ?? null;
        }
    }

    // ---------------------------
    // 2️⃣ ICON: irgendein ICON_KEY nehmen, normale Version bevorzugt
    $iconUrl = null;
    foreach ($values as $key => $data) {
        if (str_starts_with($key, 'ICON_') && !str_contains($key, '_SM_')) {
            if (!empty($data['url'] ?? null)) {
                $iconUrl = $data['url'];
                break;
            }
        }
    }

    if ($iconUrl) {
        $extension = pathinfo($iconUrl, PATHINFO_EXTENSION) ?: 'svg';
        $filename = 'ICON_' . pathinfo($iconUrl, PATHINFO_FILENAME);
        $media = $shopwareApi->upload_media_by_url($iconUrl, $mediaFolderId, $filename, $extension, $sw_force);
        $mediaMapping['ICON'] = $media['mediaId'] ?? null;
    }

    Logger::logGlobal("shopware final for product $tagid", $mediaMapping, Logger::LEVEL_VERBOSE);

    // ---------------------------
    // Update Produkt
    $shopwareApi->update_product_eprel_data(
        $tagid,
        $mediaMapping['ENERGYLABEL'],
        $mediaMapping['ICON'],
        $mediaMapping['DATASHEET']
    );
}












function is_first_saturday_of_month(?\DateTime $date = null): bool
{
    $date ??= new \DateTime(); // Use today if no date is provided

    // Check if the day is Saturday
    if ((int)$date->format('N') !== 6) {
        return false;
    }

    // Check if it's the first Saturday (day 1–7 of the month)
    return (int)$date->format('j') <= 7;
}