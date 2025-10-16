<?php

include_once("config.php");

$shopware_asset_cache = [];
$force_asset_update = false;
function shopware_upload($product_data_arr, $_force_asset_update = false){
    global $force_asset_update;
    $force_asset_update = $_force_asset_update;
    
    shopware_fill_asset_cache_by_families(['euenergielabel']);

    foreach($product_data_arr as $row) {
        $tagid = $row["TAGID"];
        $eprelRegNum = $row["EprelRegistrationNumber"];
        $eprelCategory = $row["EPRELProductGroup"];
        $energyicon_filename = $row["EnergyIconFilename"];

        shopware_upload_energylabel($tagid, $eprelRegNum, $eprelCategory);
        shopware_upload_datasheet($tagid, $eprelRegNum, $eprelCategory);
        shopware_upload_energyicon($energyicon_filename);

        shopware_product_fill_asset_collection_attribute(
            'cnsc_energylabel',
            $tagid, 
            [
                'ENERGYLABEL_'.$tagid,
                'DATASHEET_'.$tagid,
                'ICON_'.str_replace("-", "_", str_replace(".svg", "", $energyicon_filename))
            ]
        );
    }
}


// Upload of diffrent types
function shopware_upload_energylabel($a1_number, $eprel_registration_number, $eprel_category){
    global $productfiles_basepath, $public_url_productFiles_basepath;

    $local_filepath = "$productfiles_basepath/$eprel_registration_number/energylabel.png";
    if(!is_file($local_filepath)){
        _log("shopware_upload_energylabel missing file \$local_filepath = \"$local_filepath\", \$a1_number = \"$a1_number\" , \$eprel_registration_number = \"$eprel_registration_number\" ");
        return;
    }
    return shopware_upload_media_asset_wrapper(
        "ENERGYLABEL_".$a1_number, 
        "$public_url_productFiles_basepath/$eprel_registration_number/energylabel.png",
        $local_filepath
    );
}

function shopware_upload_datasheet($a1_number, $eprel_registration_number, $eprel_category){
    global $productfiles_basepath, $public_url_productFiles_basepath;
    $local_filepath = "$productfiles_basepath/$eprel_registration_number/datasheet.pdf";
    if(!is_file($local_filepath)){
        _log("shopware_upload_datasheet missing file \$local_filepath = \"$local_filepath\", \$a1_number = \"$a1_number\" , \$eprel_registration_number = \"$eprel_registration_number\" ");
        return;
    }
    return shopware_upload_media_asset_wrapper(
        "DATASHEET_".$a1_number, 
        "$public_url_productFiles_basepath/$eprel_registration_number/datasheet.pdf",
        $local_filepath
    );
}

function shopware_upload_energyicon($filename){
    global $energyicons_basepath, $public_url_energyicons_basepath;
    $local_filepath = "$energyicons_basepath/$filename";
    if(!is_file($local_filepath)){
        _log("shopware_upload_energyicon missing file \$local_filepath = \"$local_filepath\", \$filename = \"$filename\" ");
        return;
    }
    return shopware_upload_media_asset_wrapper(
        'ICON_'.str_replace("-", "_", str_replace(".svg", "", $filename)), 
        "$public_url_energyicons_basepath/$filename",
        $local_filepath
    );
}



function shopware_upload_media_asset_wrapper($name, $url, $local_filepath){
    global $ak, $force_asset_update;

    if(!file_exists($local_filepath)){
        _log("File $local_filepath does not exist. Skipping upload...");
        return false;
    }

    // 1. Create Media File
    $mediaFileCode = $ak->getAssetMediaFileApi()->create($local_filepath);

    // 2. Create Asseet with Media File linked
    return shopware_create_media_asset(
        "euenergielabel", 
        $name,
        [
            "url" => [
                [
                    'locale' => null,
                    'channel' => null,
                    'data' => $url
                ]
            ],
            "media" => [
                [
                    'locale' => null,  
                    'channel' => null,   
                    'data' => $mediaFileCode
                ]
            ]
        ],
        !$force_asset_update
    );


}

// Product association
function shopware_product_fill_asset_collection_attribute($attribute_name, $a1_num, $asset_codes_arr){
    global $ak;

    $data = [
        'identifier' => $a1_num,
        'values' => [
            $attribute_name => [
                [
                    'locale' => null,
                    'scope' => null,
                    'data' => $asset_codes_arr
                ]
            ]
        ]
    ];

    $ak->getProductApi()->upsert($a1_num, $data);

}

function shopware__product_set_field__Energielabel_URL($a1_num, $value){
    global $ak;

    $data = [
        'identifier' => $a1_num,
        'values' => [
            'Energielabel_URL' => [
                [
                    'channel' => 'Future-X',
                    'locale' => null,
                    'scope' => null,
                    'data' => $value
                ]
            ]
        ]
    ];

    $ak->getProductApi()->upsert($a1_num, $data);
}


