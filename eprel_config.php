<?php

include_once(__DIR__."/NH_Code/include.php");
include_once(__DIR__."/functions/shopware.php");
include_once(__DIR__."/functions/akeneo.php");
include_once(__DIR__."/functions/eprel.php");
include_once(__DIR__."/functions/shopmanager.php");

/*
    Settings
*/

$EPREL_PRODUCT_GROUPS_ARRAY = [
    "electronicdisplays",
    "televisions"
];

$basepath = "E:\Webdaten\EPREL_Public";
$zip_basepath = "$basepath/zips";
$productfiles_basepath = "$basepath/productFiles";
$energyicons_basepath = "$basepath/energyicons";

$public_url_basepath = "https://webapi.tarox.de/webservices/eprel";
$public_url_energyicons_basepath = "$public_url_basepath/energyicons";
$public_url_productFiles_basepath = "$public_url_basepath/productFiles";

$debug = true;

// FIRST NUMBER IS SECONDS (converts for usleep)
$sleep_between_eprel_api_calls = 0.2 * 1000000;
$sleep_between_akeneo_api_calls = 0.5 * 1000000; 


$keep_columns = [
    "eprelRegistrationNumber",
    "productGroup",

    "modelIdentifier",
    "organisation.organisationTitle",
    "organisation.organisationName",

    "energyClass",
    "energyClassImage",
    "energyClassImageWithScale"
];