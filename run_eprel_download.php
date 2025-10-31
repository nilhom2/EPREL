<?php

include_once __DIR__ . '/nh_connectors/include.php';
include_once __DIR__ . '/functions/eprel.php';
include_once __DIR__ . '/eprel_config.php';

use nh_connectors\ConnectorFactory;
use nh_connectors\Utils\Logger;

use function nh_connectors\sql;
use function nh_connectors\Utils\purgeDirectory;

ini_set('memory_limit', '4G');


/*
    INIT
*/
Logger::setFileForLevel(Logger::LEVEL_IMPORTANT, 'run_eprel_download_important.log');
Logger::setFileForLevel(Logger::LEVEL_ERROR, 'run_eprel_download_important.log');
Logger::setFileForLevel('all', 'run_eprel_download.log');

$eprelApi = ConnectorFactory::createEprel();

/*
    START
*/
Logger::logProcessStep("START - run_eprel_download");

Logger::logProcessStep("Download EPREL data");
eprel_download_zip_data($eprelApi, $EPREL_PRODUCT_GROUPS_ARRAY, $zip_basepath, $sleep_between_eprel_api_calls);

Logger::logProcessStep("Process EPREL data");
eprel_process_zip_data($zip_basepath, $keep_columns);

Logger::logProcessStep("Push EPREL data to SQL");
eprel_push_to_sql($zip_basepath);

Logger::logProcessStep("exec Prozessdaten.dbo.EPREL_update_JM;");
sql('low')->runSQL("exec Prozessdaten.dbo.EPREL_update_JM;");

Logger::logProcessStep("Download EPREL product files");

function is_first_saturday_of_month(?\DateTime $date = null) {
    $date ??= new \DateTime(); 
    if ((int)$date->format('N') !== 6) return false;
    return (int)$date->format('j') <= 7;
}
$redownload = is_first_saturday_of_month();
if ($redownload) {
    Logger::logImportant("First Saturday of the month: redownloading all data.");
    purgeDirectory($basepath.'\\productFiles\\');
}

$product_data_arr = sql('low')->runSQL("SELECT * FROM Prozessdaten.dbo.EPREL_tagID_to_EPRELRegistrationNumber" . ($debug_one_tagid ?  " WHERE TAGID = '$debug_one_tagid'" : ""));
if (empty($product_data_arr)) {
    Logger::logImportant("No products found in the database. Exiting...");
    exit();
}

$sleep_ms = 1000;

foreach ($product_data_arr as $row) {
    $regNum = $row["EprelRegistrationNumber"];  
    $energyIconFilename = $row["EnergyIconFilename"];

    // Energy label
    $res = $eprelApi->downloadEnergyLabel($regNum, [
        'fileOutput' => [
            'folderPath' => $basepath.'\\productFiles\\'.$regNum
        ]
    ]);

    if(!isset($res['skipped']) || $res['skipped'] == false) sleep_ms($sleep_ms);

    // Datasheet
    $res = $eprelApi->downloadDatasheet($regNum, [
        'fileOutput' => [
            'folderPath' => $basepath.'\\productFiles\\'.$regNum
        ]
    ]);

    if(!isset($res['skipped']) || $res['skipped'] == false) sleep_ms($sleep_ms);

    // Energy icon
    $res = $eprelApi->downloadEnergyIcon($regNum, [
        'fileOutput' => [
            'folderPath' => $basepath.'/energyicons',
            'filename' => str_replace(".svg", "", $energyIconFilename)
        ]
    ]);

    if(!isset($res['skipped']) || $res['skipped'] == false) sleep_ms($sleep_ms);
}

Logger::logProcessStep("FINISHED - run_eprel_download");