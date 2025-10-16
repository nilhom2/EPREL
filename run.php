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

Logger::logGlobal("1. Download EPREL data");
// eprel_download_data($eprelApi, $EPREL_PRODUCT_GROUPS_ARRAY, $zip_basepath, $sleep_between_eprel_api_calls);

Logger::logGlobal("2. Process EPREL data");
// eprel_process_data($zip_basepath, $keep_columns);

Logger::logGlobal("3. Push EPREL data to SQL");
eprel_push_to_sql($zip_basepath);

Logger::logGlobal("4. exec Prozessdaten.dbo.EPREL_update;");
sql('low')->runSQL("exec Prozessdaten.dbo.EPREL_update;");

// --- Product Files Download --- //

$product_data_arr = sql('low')->runSQL("SELECT * FROM Prozessdaten.dbo.EPREL_tagID_to_EPRELRegistrationNumber ORDER BY ERPNr;");

if (empty($product_data_arr)) {
    Logger::logGlobal("No products found in the database. Exiting...");
    exit();
}

Logger::logGlobal("5. Download product files");
$redownload = is_first_saturday_of_month();
if ($redownload) Logger::logGlobal("First Saturday of the month: redownloading all data.");
eprel_download_product_files($eprelApi, $product_data_arr, $redownload);

$product_data_arr = sql('low')->runSQL("SELECT * FROM Prozessdaten.dbo.EPREL_tagID_to_EPRELRegistrationNumber WHERE TAGID != '' ORDER BY ERPNr;");

/*
    Media upload and product update Loop
*/
foreach ($product_data_arr as $row) {

    $tagid = $row["TAGID"];
    $eprelRegNum = $row["EprelRegistrationNumber"];
    $eprelCategory = $row["EPRELProductGroup"];
    $energyicon_filename = $row["EnergyIconFilename"];

    $energyLabelUrl = "$public_url_productFiles_basepath/$eprelRegNum/energylabel.png";
    $datasheetUrl   = "$public_url_productFiles_basepath/$eprelRegNum/datasheet.pdf";
    $energyIconUrl  = "$public_url_energyicons_basepath/$energyIconFilename";

    /*
        Shopware
    */  

    $mediaFolderId = "308252dc56fe4b5c96ddb4bfd64e5ec0";

    // upload media
    $energyLabelMedia = $shopwareApi->upload_media_by_url($energyLabelUrl, $mediaFolderId, "ENERGYLABEL_$tagid", 'png');
    $datasheetMedia   = $shopwareApi->upload_media_by_url($datasheetUrl, $mediaFolderId, "DATASHEET_$tagid", 'pdf');
    $energyIconMedia  = $shopwareApi->upload_media_by_url($energyIconUrl, $mediaFolderId, "ICON_".pathinfo($energyIconFilename, PATHINFO_FILENAME), 'svg');

    // product association
    $shopwareApi->update_product_eprel_data(
        $tagid,
        $energyLabelMedia['mediaId'] ?? null,
        $energyIconMedia['mediaId'] ?? null,
        $datasheetMedia['mediaId'] ?? null
    );

    /*  
        Akeneo
    */

    // upload assets
    $akeneoApi->uploadEnergyLabel($tagid, $eprelRegNum, $eprelCategory);
    $akeneoApi->uploadDatasheet($tagid, $eprelRegNum, $eprelCategory);
    $akeneoApi->uploadEnergyIcon($energyicon_filename);

    // product association
    $akeneoApi->fillProductAssetCollection(
        'cnsc_energylabel',
        $tagid,
        [
            'ENERGYLABEL_'.$tagid,
            'DATASHEET_'.$tagid,
            'ICON_'.str_replace("-", "_", str_replace(".svg", "", $energyicon_filename))
        ]
    );

    Logger::logGlobal("Processed product $tagid");
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