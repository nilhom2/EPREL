<?php

include_once("config.php");

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

function getAccessToken(){
    _log("Fetching Access Token");

    $username = "sblank@cenesco.de";
    $password = "hMBQ#dr3p6b2%!PV";

    $url = "https://shopmanager.future-x.de/ext/api/authentication_token";


    $client = new Client();

    try {
        $response = $client->post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'accept' => 'application/json'
            ],
            'json' => [
                "email" => $username,
                "password" => $password,
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        _log("Fetched Access Token");
        return $data['token'];
    } catch (RequestException $e) {
        _log("Error fetching Access Token: " . $e->getMessage());
        return null;
    }
}


function fetchAllProducts($pdo) {

    _log("Fetching Products");
    $client = new Client();
    $url = "https://shopmanager.future-x.de/ext/api/products/";
    $limit = 500; // Page Size
    $page = 1;
        
    $accessToken = getAccessToken();
    $accessTokenTime = time();

    do {
        try {

            if (time() - $accessTokenTime > 500) {
                $accessToken = getAccessToken();
                $accessTokenTime = time();
            }

            $curr_url = $url . "?page=$page&itemsPerPage=$limit";
            _log("Fetching page: " . $curr_url);

            $response = $client->get($curr_url, [
                'headers' => [
                    'authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json'
                ]
            ]);
            $data = json_decode($response->getBody(), true);

            storeProductsInDatabase($data, $pdo);
        } catch (RequestException $e) {
            _log("Error fetching products: " . $e->getMessage());
            if ($e->getCode() == "429") {
                sleep(30);
            } else {
                return [];
            }
        } catch (Exception $e) {
            _log($e->getMessage());
        }
        $page++;
    } while ($data);
}






function shopmanager_upload($product_data_arr, $_force_asset_update = false){
    foreach($product_data_arr as $row) {
        $ERPNr = $row["ERPNr"];
        $eprelRegNum = $row["EprelRegistrationNumber"];
        $eprelCategory = $row["EPRELProductGroup"];
        $energyicon_filename = $row["EnergyIconFilename"];

        shopmanager_upload_energylabel($ERPNr, $eprelRegNum, $eprelCategory);
        shopmanager_upload_datasheet($ERPNr, $eprelRegNum, $eprelCategory);
        // shopmanager_upload_energyicon($energyicon_filename);
    }

}


// Upload of diffrent types
function shopmanager_upload_energylabel($ERPNr, $eprel_registration_number, $eprel_category){
    global $productfiles_basepath, $public_url_productFiles_basepath;

    $local_filepath = "$productfiles_basepath/$eprel_registration_number/energylabel.png";
    if(!is_file($local_filepath)){
        _log("shopmanager_upload_energylabel missing file \$local_filepath = \"$local_filepath\", \$ERPNr = \"$ERPNr\" , \$eprel_registration_number = \"$eprel_registration_number\" ");
        return;
    }
    return shopmanager_upload_media_asset_wrapper(
        "energie-label",
        $ERPNr, 
        "$public_url_productFiles_basepath/$eprel_registration_number/energylabel.png",
        $local_filepath
    );
}

function shopmanager_upload_datasheet($ERPNr, $eprel_registration_number, $eprel_category){
    global $productfiles_basepath, $public_url_productFiles_basepath;
    $local_filepath = "$productfiles_basepath/$eprel_registration_number/datasheet.pdf";
    if(!is_file($local_filepath)){
        _log("shopmanager_upload_datasheet missing file \$local_filepath = \"$local_filepath\", \$ERPNr = \"$ERPNr\" , \$eprel_registration_number = \"$eprel_registration_number\" ");
        return;
    }
    return shopmanager_upload_media_asset_wrapper(
        "energie-datenblatt",
        $ERPNr, 
        "$public_url_productFiles_basepath/$eprel_registration_number/datasheet.pdf",
        $local_filepath
    );
}

function shopmanager_upload_energyicon($filename){
    global $energyicons_basepath, $public_url_energyicons_basepath;
    $local_filepath = "$energyicons_basepath/$filename";
    if(!is_file($local_filepath)){
        _log("shopmanager_upload_energyicon missing file \$local_filepath = \"$local_filepath\", \$filename = \"$filename\" ");
        return;
    }
    return shopmanager_upload_media_asset_wrapper(
        'icon', 
        $ERPNr,
        "$public_url_energyicons_basepath/$filename",
        $local_filepath
    );
}


/*
function shopmanager_upload_media_asset_wrapper($name, $url, $local_filepath){
    global $ak, $force_asset_update;

    if(!file_exists($local_filepath)){
        _log("File $local_filepath does not exist. Skipping upload...");
        return false;
    }

    // 1. Create Media File
    $mediaFileCode = $ak->getAssetMediaFileApi()->create($local_filepath);

    // 2. Create Asseet with Media File linked
    return akeneo_create_media_asset(
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
*/


function shopmanager_upload_media_asset_wrapper($type, $ERPNR, $publicUrl, $local_filepath) {
    if (!file_exists($local_filepath)) {
        _log("File $local_filepath does not exist. Skipping upload...");
        return false;
    }

    $client = new Client();
    $url = "https://shopmanager.future-x.de/ext/api/media_links";

    $accessToken = getAccessToken();
    $accessTokenTime = time();

    $payload = [
        "type" => $type,
        "class" => "product",
        "extId" => $ERPNR,
        "link" => $publicUrl,
        "isDefault" => true,
        "status" => true
    ];

    try {
        // Refresh token if older than 500 seconds
        if (time() - $accessTokenTime > 500) {
            $accessToken = getAccessToken();
            $accessTokenTime = time();
        }

        $response = $client->post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'json' => $payload
        ]);

        $responseData = json_decode($response->getBody(), true);
        _log("Media link uploaded successfully: " . $ERPNR);
        return $responseData;

    } catch (RequestException $e) {
        _log("Error posting media link: " . $e->getMessage());
        if ($e->getCode() == 429) {
            sleep(30);
            return shopmanager_upload_media_asset_wrapper($type, $ERPNR, $publicUrl, $local_filepath); // retry
        }
        return false;
    } catch (Exception $e) {
        _log("Unexpected error: " . $e->getMessage());
        return false;
    }
}