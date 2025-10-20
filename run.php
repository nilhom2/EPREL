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
    null to deactivate
*/
$debug_one_tagid = "A1000549610";


/*
    INITIALIZE
*/
Logger::enableFileLogging();
Logger::setLogLevel(2);

$eprelApi = ConnectorFactory::createEprel();
$akeneoApi = ConnectorFactory::createAkeneo();
$shopwareApi = ConnectorFactory::createShopware('live');
$shopmanagerApi = ConnectorFactory::createShopmanager();

/*
    START
*/
Logger::logGlobal("EPREL sync script started");

Logger::logGlobal("---1. Download EPREL data");
// eprel_download_data($eprelApi, $EPREL_PRODUCT_GROUPS_ARRAY, $zip_basepath, $sleep_between_eprel_api_calls);

Logger::logGlobal("---2. Process EPREL data");
// eprel_process_data($zip_basepath, $keep_columns);

Logger::logGlobal("---3. Push EPREL data to SQL");
// eprel_push_to_sql($zip_basepath);

Logger::logGlobal("---4. exec Prozessdaten.dbo.EPREL_update;");
// sql('low')->runSQL("exec Prozessdaten.dbo.EPREL_update;");

$product_data_arr = sql('low')->runSQL("SELECT * FROM Prozessdaten.dbo.EPREL_tagID_to_EPRELRegistrationNumber" . ($debug_one_tagid ?  " WHERE TAGID = '$debug_one_tagid'" : ""));

if (empty($product_data_arr)) {
    Logger::logGlobal("No products found in the database. Exiting...");
    exit();
}

// --- Product Files Download --- //
Logger::logGlobal("---5. Download product files");
$redownload = is_first_saturday_of_month();
if ($redownload) Logger::logGlobal("First Saturday of the month: redownloading all data.");
eprel_download_product_files($eprelApi, $product_data_arr, $redownload);

Logger::logGlobal("---6. EPREL data -> Akeneo");
$cache = [];
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





$energyIconMap = [
    'A'   => 'A-Left-DarkGreen-WithAGScale.svg',
    'A+'  => 'AP-Left-MediumGreen-WithAPPEScale.svg',
    'A++' => 'APP-Left-DarkGreen-WithAPPPPScale.svg',
    'B'   => 'B-Left-MediumGreen-WithAGScale.svg',
    'C'   => 'C-Left-LightOrange-WithAGScale.svg',
    'D'   => 'D-Left-Yellow-WithAGScale.svg',
    'E'   => 'E-Left-LightOrange-WithAGScale.svg',
    'F'   => 'F-Left-DarkOrange-WithAGScale.svg',
    'G'   => 'G-Left-Red-WithAGScale.svg'
];


Logger::logGlobal("---7. Shopmanager -> Akeneo");

$sm_products = sql('low')->runSQL("SELECT * FROM [1WS].[dbo].[SM_Produkte] WHERE (ISNULL(energyLabel, '') != '' OR ISNULL(energyDatasheet, '') != '')" . ($debug_one_tagid ?  " AND pimIdentifier = '$debug_one_tagid'" : ""));

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

        $rating = $row['energyEek2020'] ?? $row['energyEek']; // fallback
        if (!empty($rating) && isset($energyIconMap[$rating])) {
            $energyicon_filename = $energyIconMap[$rating];
            $energyIconUrl = $public_url_energyicons_basepath . $energyicon_filename;
        }

        // Cache
        $cache[$tagid] = array_merge($cache[$tagid], [
            'ENERGYLABEL_SM_'.$tagid => ['url' => $energyLabelUrl],
            'DATASHEET_SM_'.$tagid   => ['url' => $datasheetUrl],
            'ICON_SM_'.str_replace("-", "_", str_replace(".svg", "", $energyicon_filename)) => ['url' => $energyIconUrl]
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
        'DATASHEET'   => null,
        'ENERGYLABEL' => null,
        'ICON'        => null,
    ];

    // ============================================================
    // 🔍 Collect relevant URLs in one pass
    // ============================================================
    $urls = [
        'DATASHEET'     => null,
        'DATASHEET_SM'  => null,
        'ENERGYLABEL'   => null,
        'ENERGYLABEL_SM'=> null,
        'ICON'          => null,
        'ICON_SM'       => null,
    ];

    foreach ($values as $key => $data) {
        if (empty($data['url'])) continue;

        if (str_starts_with($key, 'DATASHEET_')) {
            if (str_contains($key, '_SM_')) $urls['DATASHEET_SM'] = $data['url'];
            else $urls['DATASHEET'] = $data['url'];
        } elseif (str_starts_with($key, 'ENERGYLABEL_')) {
            if (str_contains($key, '_SM_')) $urls['ENERGYLABEL_SM'] = $data['url'];
            else $urls['ENERGYLABEL'] = $data['url'];
        } elseif (str_starts_with($key, 'ICON_')) {
            if (str_contains($key, '_SM_')) $urls['ICON_SM'] = $data['url'];
            else $urls['ICON'] = $data['url'];
        }
    }

    // ============================================================
    // ⬆️ Unified upload logic (SM prioritized)
    // ============================================================
    $uploadTargets = [
        'DATASHEET'   => ['primary' => $urls['DATASHEET_SM'],   'fallback' => $urls['DATASHEET'],   'ext' => 'pdf'],
        'ENERGYLABEL' => ['primary' => $urls['ENERGYLABEL_SM'], 'fallback' => $urls['ENERGYLABEL'], 'ext' => 'png'],
        'ICON'        => ['primary' => $urls['ICON_SM'],        'fallback' => $urls['ICON'],        'ext' => 'svg'],
    ];

    foreach ($uploadTargets as $type => $config) {
        $url = $config['primary'] ?: $config['fallback'];
        if (!$url) continue;

        $extension = pathinfo($url, PATHINFO_EXTENSION) ?: $config['ext'];
        $filename  = $type === 'ICON'
            ? "ICON_" . pathinfo($url, PATHINFO_FILENAME)
            : "{$type}_{$tagid}";

        $media = $shopwareApi->upload_media_by_url($url, $mediaFolderId, $filename, $extension, $sw_force);
        $mediaMapping[$type] = $media['mediaId'] ?? null;
    }

    // ============================================================
    // 🏁 Update Product in Shopware
    // ============================================================
    Logger::logGlobal("Shopware final for product $tagid", $mediaMapping, Logger::LEVEL_VERBOSE);

    $shopwareApi->update_product_eprel_data(
        $tagid,
        $mediaMapping['ENERGYLABEL'],
        $mediaMapping['ICON'],
        $mediaMapping['DATASHEET']
    );
}







/*
    Shopmanager product energy label sync
*/

Logger::logGlobal("---10. Shopmanager upload");

$sm_force = false;

foreach ($cache as $tagid => $values) {

    // Skip if no energy label in cache
    if (empty($values['ENERGYLABEL_'.$tagid]['url'] ?? null)) {
        Logger::logGlobal("No cached ENERGYLABEL for $tagid, skipping...");
        continue;
    }

    // Check if product already has energy label in Shopmanager
    $smProduct = $shopmanagerApi->get_single_product($tagid);

    if(trim($smProduct['number']) == '') {
        Logger::logGlobal("Shopmanager product $tagid: 99 number not found on lookup. Cannot upload eprel files");
        continue;
    }

    // Energy label not set → upload from cache
    $energyLabelUrl = $values['ENERGYLABEL_'.$tagid]['url'];
    if($energyLabelUrl != null && trim($energyLabelUrl) != ''){
        if (!$sm_force && !empty($smProduct) && !empty($smProduct['energyLabel'] ?? null)) {
            Logger::logGlobal("Shopmanager product $tagid already has an energy label, skipping upload.");
        }else{
            Logger::logGlobal("Uploading ENERGYLABEL for $tagid to Shopmanager → $energyLabelUrl");
            $shopmanagerApi->uploadEnergyLabel($smProduct['number'], $energyLabelUrl);
        }
    }else{
        Logger::logGlobal("Shopmanager energylabel not found in cache for $tagid, skipping upload.");
    }

    // Datasheet not set → upload from cache
    $datasheetUrl = $values['DATASHEET_'.$tagid]['url'];
    if($datasheetUrl != null && trim($datasheetUrl) != ''){
        if (!$sm_force && !empty($smProduct) && !empty($smProduct['energyDatasheet'] ?? null)) {
            Logger::logGlobal("Shopmanager product $tagid already has an datasheet, skipping upload.");
        }else{
            Logger::logGlobal("Uploading DATASHEET for $tagid to Shopmanager → $energyLabelUrl");
            $shopmanagerApi->uploadDataSheet($smProduct['number'], $datasheetUrl);
        }
    }else{
        Logger::logGlobal("Shopmanager datasheet not found in cache for $tagid, skipping upload.");
    }
    
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