<?php

include_once(__DIR__."/nh_connectors/include.php");
include_once(__DIR__."/functions/eprel.php");


/*
    Base settings
*/

$basepath = "E:\Webdaten\EPREL_Public";


/*
    DEV Settings
*/

$basepath = is_dir($basepath) ? $basepath : __DIR__."/EPREL_Testdaten";


/*
    Other settings
*/

$EPREL_PRODUCT_GROUPS_ARRAY = [
    "electronicdisplays",
    "televisions",
    "smartphonestablets20231669"
];

$zip_basepath = "$basepath\\zips";
$productfiles_basepath = "$basepath/productFiles";
$energyicons_basepath = "$basepath/energyicons";

$public_url_basepath = "https://webapi.tarox.de/webservices/eprel";
$public_url_energyicons_basepath = "$public_url_basepath/energyicons";
$public_url_productFiles_basepath = "$public_url_basepath/productFiles";

$sleep_between_eprel_api_calls = 1;
$sleep_between_akeneo_api_calls = 1; 


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