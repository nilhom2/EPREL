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
use function nh_connectors\Utils\purgeDirectory;

ini_set('memory_limit', '4G');

function is_first_saturday_of_month(?\DateTime $date = null) {
    $date ??= new \DateTime(); 
    if ((int)$date->format('N') !== 6) return false;
    return (int)$date->format('j') <= 7;
}

/*
    DEBUG / Settings
*/

$skip_eprel_sql = false;

$sw_force = false;
$sm_force = false;
$ak_force = true;

$eprel_productfiles_redownload = is_first_saturday_of_month();

$debug_one_tagid = "A1000295305"; // null to deactivate
if($debug_one_tagid) {
    $skip_eprel_sql = true;
    $eprel_productfiles_redownload = true;
}

/*
    INIT
*/
Logger::setFileForLevel(Logger::LEVEL_IMPORTANT, 'run_important.log');
Logger::setFileForLevel(Logger::LEVEL_ERROR, 'run_important.log');
Logger::setFileForLevel('all', 'run.log');

$eprelApi = ConnectorFactory::createEprel();
$akeneoApi = ConnectorFactory::createAkeneo();
$shopwareApi = ConnectorFactory::createShopware('live');
$shopmanagerApi = ConnectorFactory::createShopmanager();

/*
    START
*/
Logger::logProcessStep("START");

if($skip_eprel_sql) goto skip_eprel_sql;

Logger::logProcessStep("Download EPREL data");
eprel_download_zip_data($eprelApi, $EPREL_PRODUCT_GROUPS_ARRAY, $zip_basepath, $sleep_between_eprel_api_calls);

Logger::logProcessStep("Process EPREL data");
eprel_process_zip_data($zip_basepath, $keep_columns);

Logger::logProcessStep("Push EPREL data to SQL");
eprel_push_to_sql($zip_basepath);

Logger::logProcessStep("exec Prozessdaten.dbo.EPREL_update;");
sql('low')->runSQL("exec Prozessdaten.dbo.EPREL_update;");

skip_eprel_sql:

$product_data_arr = sql('low')->runSQL("SELECT * FROM Prozessdaten.dbo.EPREL_tagID_to_EPRELRegistrationNumber" . ($debug_one_tagid ?  " WHERE TAGID = '$debug_one_tagid'" : ""));
if (empty($product_data_arr)) {
    Logger::logImportant("No products found in the database. Exiting...");
    exit();
}

Logger::logProcessStep("Download EPREL product files");
if ($eprel_productfiles_redownload) {
    Logger::logImportant("First Saturday of the month: redownloading all data.");
    purgeDirectory($basepath.'\\productFiles\\');
}

foreach ($product_data_arr as $row) {
    $regNum = $row["EprelRegistrationNumber"];
    $energyIconFilename = $row["EnergyIconFilename"];

    // Energy label
    $eprelApi->downloadEnergyLabel($regNum, [
        'fileOutput' => [
            'folderPath' => $basepath.'\\productFiles\\'.$regNum
        ]
    ]);

    // Datasheet
    $eprelApi->downloadDatasheet($regNum, [
        'fileOutput' => [
            'folderPath' => $basepath.'\\productFiles\\'.$regNum
        ]
    ]);

    // Energy icon
    $eprelApi->downloadEnergyIcon($regNum, [
        'fileOutput' => [
            'folderPath' => $basepath.'/energyicons',
            'filename' => str_replace(".svg", "", $energyIconFilename)
        ]
    ]);
}

Logger::logProcessStep("EPREL data -> Akeneo");
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
        $akeneoApi->uploadEnergyLabel($tagid, $eprelRegNum, $eprelCategory, 'eprel', $energyLabelUrl, $ak_force);
    }
    if ($datasheetUrl) {
        $akeneoApi->uploadDatasheet($tagid, $eprelRegNum, $eprelCategory, 'eprel', $datasheetUrl, $ak_force);
    }
    if ($energyIconUrl) {
        $akeneoApi->uploadEnergyIcon($energyicon_filename, 'eprel', $energyIconUrl, $ak_force);
    }

    // Cache
    $cache[$tagid] = [
        'ENERGYLABEL_'.$tagid => ['url' => $energyLabelUrl],
        'DATASHEET_'.$tagid   => ['url' => $datasheetUrl],
        'ICON_'.str_replace("-", "_", str_replace(".svg", "", $energyicon_filename)) => ['url' => $energyIconUrl]
    ];

    Logger::logNormal("Processed product $tagid");
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


Logger::logProcessStep("Shopmanager -> Akeneo");

$sm_products = sql('low')->runSQL("SELECT * FROM [1WS].[dbo].[SM_Produkte] WHERE (ISNULL(energyLabel, '') != '' OR ISNULL(energyDatasheet, '') != '')" . ($debug_one_tagid ?  " AND pimIdentifier = '$debug_one_tagid'" : ""));

if (!empty($sm_products)) {
    Logger::logImportant("Found " . count($sm_products) . " products to patch to Akeneo.");

    foreach ($sm_products as $row) {
        $tagid = trim($row['pimIdentifier'] ?? $row['TAGID'] ?? '');

        if ($tagid === '') {
            Logger::logImportant("Skipping product with missing TAGID/pimIdentifier", [], Logger::LEVEL_VERBOSE);
            continue;
        }

        $energyLabelUrl = trim($row['energyLabel'] ?? '');
        $datasheetUrl   = trim($row['energyDatasheet'] ?? '');

        Logger::logVerbose("Processing SM product $tagid", [
            'energyLabel' => $energyLabelUrl,
            'datasheet'   => $datasheetUrl
        ]);

        // --- Akeneo upload ---
        if ($energyLabelUrl !== '') {
            Logger::logVerbose("Uploading ENERGYLABEL for $tagid", [], Logger::LEVEL_VERBOSE);
            $akeneoApi->uploadEnergyLabel(
                $tagid,          // TAGID / product identifier
                '',              // EPREL registration number not needed for ShopManager
                '',              // EPREL category not needed
                'shopmanager',   // source
                $energyLabelUrl,  // use the URL from ShopManager
                $ak_force
            );
        }

        if ($datasheetUrl !== '') {
            Logger::logVerbose("Uploading DATASHEET for $tagid", [], Logger::LEVEL_VERBOSE);
            $akeneoApi->uploadDatasheet(
                $tagid,
                '', 
                '',
                'shopmanager',
                $datasheetUrl,  // use the URL from ShopManager
                $ak_force
            );
        }

        $rating = $row['energyEek2020'] ?? $row['energyEek']; // fallback
        if (!empty($rating) && isset($energyIconMap[$rating])) {
            $energyicon_filename = $energyIconMap[$rating];
            $energyIconUrl = $public_url_energyicons_basepath . $energyicon_filename;
        }

        // Cache
        $cache[$tagid] = array_merge($cache[$tagid] ?? [], [
            'ENERGYLABEL_SM_'.$tagid => ['url' => $energyLabelUrl],
            'DATASHEET_SM_'.$tagid   => ['url' => $datasheetUrl],
            'ICON_SM_'.str_replace("-", "_", str_replace(".svg", "", $energyicon_filename)) => ['url' => $energyIconUrl]
        ]);

        Logger::logImportant("Patched SM product $tagid to Akeneo.");
    }
} else {
    Logger::logImportant("No SM_Produkte with energyLabel or datasheet found.");
}





/*
    Akeneo product asset association

    muss alles aufeinmal da akeneo sonst die anderen einträge löscht
*/

Logger::logProcessStep("Akeneo product asset association");

foreach ($cache as $tagid => $values) {
    // Filter nur die Einträge mit einer gültigen URL
    $filteredValues = [];
    foreach ($values as $key => $data) {
        if (!empty(trim($data['url'] ?? '')) && !str_starts_with($key, 'ICON_SM')) {
            $filteredValues[] = $key; // Key als Wert verwenden
        }
    }

    if (!empty($filteredValues)) {
        Logger::logVerbose(
            "Filling Akeneo asset collection for product $tagid",
            ['collection' => 'cnsc_energylabel', 'values' => $filteredValues]
        );

        try {
            $akeneoApi->fillProductAssetCollection(
                'cnsc_energylabel',
                $tagid,
                $filteredValues
            );
            Logger::logImportant("fillProductAssetCollection finished for $tagid");
        } catch (Exception $e) {
            Logger::logError("fillProductAssetCollection failed for $tagid", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            Logger::logError("fillProductAssetCollection failed for $tagid, cache = ", $cache);
        }
    }

    Logger::logVerbose("cache for product $tagid", $values, Logger::LEVEL_VERBOSE);
}



/*
    Shopware Upload
*/  

Logger::logProcessStep("Shopware media upload and product association");

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

    Logger::logVerbose("Shopware uploaddTargets= ",$uploadTargets);

    foreach ($uploadTargets as $type => $config) {
        $url = $config['primary'] ?: $config['fallback'];
        if ($url){
            $extension = pathinfo($url, PATHINFO_EXTENSION) ?: $config['ext'];
            $filename  = $type === 'ICON'
                ? "ICON_" . pathinfo($url, PATHINFO_FILENAME)
                : "{$type}_{$tagid}";

            $media = $shopwareApi->upload_media_by_url($url, $mediaFolderId, $filename, $extension, $sw_force);
            $mediaMapping[$type] = $media['mediaId'] ?? null;
        }
    }

    // ============================================================
    // 🏁 Update Product in Shopware
    // ============================================================
    Logger::logVerbose("Shopware final for product $tagid", $mediaMapping);

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

Logger::logProcessStep("Shopmanager upload");
foreach ($cache as $tagid => $values) {

    // Skip if no energy label in cache
    if (empty($values['ENERGYLABEL_'.$tagid]['url'] ?? null)) {
        Logger::logImportant("No cached ENERGYLABEL for $tagid, skipping...");
        continue;
    }

    // Check if product already has energy label in Shopmanager
    $smProduct = $shopmanagerApi->get_single_product($tagid);

    if(trim($smProduct['number']) == '') {
        Logger::logImportant("Shopmanager product $tagid: 99 number not found on lookup. Cannot upload eprel files");
        continue;
    }

    // Energy label not set → upload from cache
    $energyLabelUrl = $values['ENERGYLABEL_'.$tagid]['url'];
    if($energyLabelUrl != null && trim($energyLabelUrl) != ''){
        if (!$sm_force && !empty($smProduct) && !empty($smProduct['energyLabel'] ?? null)) {
            Logger::logImportant("Shopmanager product $tagid already has an energy label, skipping upload.");
        }else{
            Logger::logImportant("Uploading ENERGYLABEL for $tagid to Shopmanager → $energyLabelUrl");
            $shopmanagerApi->uploadEnergyLabel($smProduct['number'], $energyLabelUrl);
        }
    }else{
        Logger::logImportant("Shopmanager energylabel not found in cache for $tagid, skipping upload.");
    }

    // Datasheet not set → upload from cache
    $datasheetUrl = $values['DATASHEET_'.$tagid]['url'];
    if($datasheetUrl != null && trim($datasheetUrl) != ''){
        if (!$sm_force && !empty($smProduct) && !empty($smProduct['energyDatasheet'] ?? null)) {
            Logger::logImportant("Shopmanager product $tagid already has an datasheet, skipping upload.");
        }else{
            Logger::logImportant("Uploading DATASHEET for $tagid to Shopmanager → $energyLabelUrl");
            $shopmanagerApi->uploadDataSheet($smProduct['number'], $datasheetUrl);
        }
    }else{
        Logger::logImportant("Shopmanager datasheet not found in cache for $tagid, skipping upload.");
    }
    
}


Logger::logProcessStep("STOP");