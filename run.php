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

// init
Logger::enableFileLogging();
Logger::setLogLevel(2);
$eprelApi = ConnectorFactory::createEprel();

// START
Logger::logGlobal("EPREL sync script started");

Logger::logGlobal("1. Download EPREL data");
// eprel_download_data($eprelApi, $EPREL_PRODUCT_GROUPS_ARRAY, $zip_basepath, $sleep_between_eprel_api_calls);

Logger::logGlobal("2. Process EPREL data");
// eprel_process_data($zip_basepath, $keep_columns);

Logger::logGlobal("3. Push EPREL data to SQL");
eprel_push_to_sql($zip_basepath);

Logger::logGlobal("4. exec Prozessdaten.dbo.EPREL_update;");
sql('low')->runSQL("exec Prozessdaten.dbo.EPREL_update;");

exit();

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

// --- Akeneo Upload --- //
$product_data_arr = sql('low')->runSQL("SELECT * FROM Prozessdaten.dbo.EPREL_tagID_to_EPRELRegistrationNumber WHERE TAGID != '' ORDER BY ERPNr;");
if ($product_data_arr) {
    Logger::logGlobal("6. Upload to Akeneo");
    akeneo_upload($product_data_arr, false);
} else {
    Logger::logGlobal("No products for Akeneo upload. Skipping...");
}

// --- Shopware Upload --- //
$product_data_arr = sql('low')->runSQL("SELECT * FROM Prozessdaten.dbo.EPREL_tagID_to_EPRELRegistrationNumber WHERE TAGID != '' ORDER BY ERPNr;");
if ($product_data_arr) {
    Logger::logGlobal("7. Upload to Shopware");
    shopware_upload($product_data_arr, false);
} else {
    Logger::logGlobal("No products for Shopware upload. Skipping...");
}

Logger::logGlobal("EPREL sync script finished");











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