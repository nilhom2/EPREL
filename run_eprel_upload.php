<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '4G');

require_once __DIR__ . '/nh_connectors/include.php';
require_once __DIR__ . '/functions/eprel.php';
require_once __DIR__ . '/eprel_config.php';

use nh_connectors\ConnectorFactory;
use nh_connectors\Utils\Logger;
use function nh_connectors\sql;

// ============================================================
// CONFIGURATION
// ============================================================
$CONFIG = [
    'debug_tagid' => null,  // Set to specific TAGID for debugging
    'stop_after_product_count' => 10,
    'force' => [
        'shopmanager' => false,
        'shopware' => true,
        'akeneo' => true,
    ],
    'shopware_media_folder_id' => '308252dc56fe4b5c96ddb4bfd64e5ec0',
];

$ENERGY_ICON_MAP = [
    'A' => 'A-Left-DarkGreen-WithAGScale.svg',
    'A+' => 'AP-Left-MediumGreen-WithAPPEScale.svg',
    'A++' => 'APP-Left-DarkGreen-WithAPPEScale.svg',
    'B' => 'B-Left-MediumGreen-WithAGScale.svg',
    'C' => 'C-Left-LightGreen-WithAGScale.svg',
    'D' => 'D-Left-Yellow-WithAGScale.svg',
    'E' => 'E-Left-LightOrange-WithAGScale.svg',
    'F' => 'F-Left-DarkOrange-WithAGScale.svg',
    'G' => 'G-Left-Red-WithAGScale.svg',
];

// ============================================================
// INITIALIZE
// ============================================================
Logger::setFileForLevel(Logger::LEVEL_IMPORTANT, 'run_eprel_upload_important.log');
Logger::setFileForLevel(Logger::LEVEL_ERROR, 'run_eprel_upload_important.log');
Logger::setFileForLevel('all', 'run_eprel_upload.log');
Logger::logProcessStep("START - EPREL Upload Process");

try {
    $akeneoApi = ConnectorFactory::createAkeneo();
    $shopwareApi = ConnectorFactory::createShopware('live');
    $shopmanagerApi = ConnectorFactory::createShopmanager();
} catch (Exception $e) {
    Logger::logError("Failed to initialize APIs", ['error' => $e->getMessage()]);
    exit(1);
}

// ============================================================
// FETCH PRODUCTS
// ============================================================
$products = fetchProducts($CONFIG['debug_tagid']);
if (empty($products)) {
    Logger::logImportant("No products found. Exiting.");
    exit(0);
}

Logger::logImportant("Processing " . count($products) . " products");

// ============================================================
// PROCESS EACH PRODUCT
// ============================================================
$stats = ['success' => 0, 'failed' => 0, 'skipped' => 0];
$processedCount = 0;

foreach ($products as $product) {
    // Check if we've reached the stop limit
    if ($CONFIG['stop_after_product_count'] !== null && $processedCount >= $CONFIG['stop_after_product_count']) {
        Logger::logImportant("Reached stop limit of {$CONFIG['stop_after_product_count']} products. Stopping.");
        break;
    }
    
    try {
        $tagid = trim($product['TAGID'] ?? '');
        if (empty($tagid)) {
            Logger::logError("Product missing TAGID", ['product' => $product]);
            $stats['skipped']++;
            continue;
        }

        Logger::logImportant("Processing product: $tagid (" . ($processedCount + 1) . "/" . 
            ($CONFIG['stop_after_product_count'] ?? count($products)) . ")");
        processProduct($product, $akeneoApi, $shopwareApi, $shopmanagerApi, $CONFIG, $ENERGY_ICON_MAP);
        $stats['success']++;
        
    } catch (Exception $e) {
        $stats['failed']++;
        Logger::logError("Failed to process product", [
            'tagid' => $tagid ?? 'unknown',
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        // Continue with next product
    }
    
    $processedCount++;
}

// ============================================================
// SUMMARY
// ============================================================
Logger::logProcessStep("FINISHED - EPREL Upload Process");
Logger::logImportant("Summary", array_merge($stats, [
    'processed' => $processedCount,
    'total_available' => count($products),
    'stopped_early' => $CONFIG['stop_after_product_count'] !== null && $processedCount >= $CONFIG['stop_after_product_count']
]));

// ============================================================
// FUNCTIONS
// ============================================================

function fetchProducts(?string $debugTagid): array {
    $whereClause = $debugTagid ? " WHERE TAGID = '$debugTagid'" : "";
    
    try {
        $eprelProducts = sql('low')->runSQL(
            "SELECT * FROM Prozessdaten.dbo.EPREL_tagID_to_EPRELRegistrationNumber$whereClause"
        );
        
        $smProducts = sql('low')->runSQL(
            "SELECT * FROM [1WS].[dbo].[SM_Produkte] 
             WHERE (ISNULL(energyLabel, '') != '' OR ISNULL(energyDatasheet, '') != '')$whereClause"
        );
        
        // Merge products by TAGID
        $merged = [];
        foreach ($eprelProducts as $p) {
            $merged[$p['TAGID']] = $p;
        }
        foreach ($smProducts as $p) {
            $tagid = $p['pimIdentifier'] ?? $p['TAGID'] ?? '';
            if ($tagid) {
                $merged[$tagid] = array_merge($merged[$tagid] ?? [], ['SM_DATA' => $p]);
            }
        }
        
        return array_values($merged);
        
    } catch (Exception $e) {
        Logger::logError("Failed to fetch products", ['error' => $e->getMessage()]);
        return [];
    }
}

function processProduct(array $product, $akeneoApi, $shopwareApi, $shopmanagerApi, array $config, array $energyIconMap): void {
    global $productfiles_basepath, $public_url_productFiles_basepath;
    global $energyicons_basepath, $public_url_energyicons_basepath;
    
    $tagid = $product['TAGID'];
    $eprelRegNum = $product['EprelRegistrationNumber'] ?? '';
    $eprelCategory = $product['EPRELProductGroup'] ?? '';
    $smData = $product['SM_DATA'] ?? null;
    
    // ============================================================
    // 1. COLLECT FILE URLS
    // ============================================================
    $files = collectFileUrls($product, $energyIconMap);
    
    // ============================================================
    // 2. UPLOAD TO AKENEO
    // ============================================================
    $akeneoAssets = [];
    
    // EPREL Files
    if ($files['eprel']['energylabel']) {
        if (uploadToAkeneo($akeneoApi, 'energyLabel', $tagid, $eprelRegNum, $eprelCategory, 'eprel', null, $config['force']['akeneo'])) {
            $akeneoAssets[] = "ENERGYLABEL_$tagid";
        }
    }
    
    if ($files['eprel']['datasheet']) {
        if (uploadToAkeneo($akeneoApi, 'datasheet', $tagid, $eprelRegNum, $eprelCategory, 'eprel', null, $config['force']['akeneo'])) {
            $akeneoAssets[] = "DATASHEET_$tagid";
        }
    }
    
    if ($files['eprel']['icon']) {
        uploadToAkeneo($akeneoApi, 'icon', $tagid, $eprelRegNum, $eprelCategory, 'eprel', null, $config['force']['akeneo'], $product['EnergyIconFilename']);
    }
    
    // Shopmanager Files
    if ($files['sm']['energylabel']) {
        if (uploadToAkeneo($akeneoApi, 'energyLabel', $tagid, '', '', 'shopmanager', $files['sm']['energylabel'], $config['force']['akeneo'])) {
            $akeneoAssets[] = "ENERGYLABEL_SM_$tagid";
        }
    }
    
    if ($files['sm']['datasheet']) {
        if (uploadToAkeneo($akeneoApi, 'datasheet', $tagid, '', '', 'shopmanager', $files['sm']['datasheet'], $config['force']['akeneo'])) {
            $akeneoAssets[] = "DATASHEET_SM_$tagid";
        }
    }
    
    // Associate assets in Akeneo
    if (!empty($akeneoAssets)) {
        try {
            $akeneoApi->fillProductAssetCollection('cnsc_energylabel', $tagid, $akeneoAssets);
            Logger::logImportant("Akeneo assets linked for $tagid", ['assets' => $akeneoAssets]);
        } catch (Exception $e) {
            Logger::logError("Failed to link Akeneo assets for $tagid", ['error' => $e->getMessage()]);
        }
    }
    
    // ============================================================
    // 3. UPLOAD TO SHOPWARE
    // ============================================================
    uploadToShopware($shopwareApi, $tagid, $files, $config);
    
    // ============================================================
    // 4. SYNC TO SHOPMANAGER
    // ============================================================
    syncToShopmanager($shopmanagerApi, $tagid, $files, $config['force']['shopmanager']);
}

function collectFileUrls(array $product, array $energyIconMap): array {
    global $productfiles_basepath, $public_url_productFiles_basepath;
    global $energyicons_basepath, $public_url_energyicons_basepath;
    
    $eprelRegNum = $product['EprelRegistrationNumber'] ?? '';
    $iconFilename = $product['EnergyIconFilename'] ?? '';
    $smData = $product['SM_DATA'] ?? null;
    
    $files = [
        'eprel' => [
            'energylabel' => null,
            'datasheet' => null,
            'icon' => null,
        ],
        'sm' => [
            'energylabel' => null,
            'datasheet' => null,
            'icon' => null,
        ]
    ];
    
    // EPREL files
    if ($eprelRegNum) {
        $elPath = "$productfiles_basepath/$eprelRegNum/energylabel.png";
        $dsPath = "$productfiles_basepath/$eprelRegNum/datasheet.pdf";
        
        if (file_exists($elPath)) {
            $files['eprel']['energylabel'] = "$public_url_productFiles_basepath/$eprelRegNum/energylabel.png";
        }
        if (file_exists($dsPath)) {
            $files['eprel']['datasheet'] = "$public_url_productFiles_basepath/$eprelRegNum/datasheet.pdf";
        }
    }
    
    if ($iconFilename) {
        $iconPath = "$energyicons_basepath/$iconFilename";
        if (file_exists($iconPath)) {
            $files['eprel']['icon'] = "$public_url_energyicons_basepath/$iconFilename";
        }
    }
    
    // Shopmanager files
    if ($smData) {
        $files['sm']['energylabel'] = trim($smData['energyLabel'] ?? '') ?: null;
        $files['sm']['datasheet'] = trim($smData['energyDatasheet'] ?? '') ?: null;
        
        // Determine icon from rating
        $rating = trim($smData['energyEek2020'] ?? $smData['energyEek'] ?? '');
        if ($rating && isset($energyIconMap[$rating])) {
            $files['sm']['icon'] = "$public_url_energyicons_basepath/" . $energyIconMap[$rating];
        }
    }
    
    return $files;
}

function uploadToAkeneo($api, string $type, string $tagid, string $eprelRegNum, string $eprelCategory, string $source, ?string $url, bool $force, ?string $filename = null): bool {
    try {
        switch ($type) {
            case 'energyLabel':
                $api->uploadEnergyLabel($tagid, $eprelRegNum, $eprelCategory, $source, $url, $force);
                break;
            case 'datasheet':
                $api->uploadDatasheet($tagid, $eprelRegNum, $eprelCategory, $source, $url, $force);
                break;
            case 'icon':
                $api->uploadEnergyIcon($filename, $source, $url, $force);
                break;
            default:
                return false;
        }
        Logger::logVerbose("Uploaded $type to Akeneo for $tagid (source: $source)");
        return true;
    } catch (Exception $e) {
        Logger::logError("Failed to upload $type to Akeneo for $tagid", [
            'source' => $source,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

function uploadToShopware($api, string $tagid, array $files, array $config): void {
    $mediaIds = ['energylabel' => null, 'icon' => null, 'datasheet' => null];
    
    // Prioritize SM files over EPREL files
    $uploads = [
        'energylabel' => ['url' => $files['sm']['energylabel'] ?: $files['eprel']['energylabel'], 'ext' => 'png'],
        'datasheet' => ['url' => $files['sm']['datasheet'] ?: $files['eprel']['datasheet'], 'ext' => 'pdf'],
        'icon' => ['url' => $files['sm']['icon'] ?: $files['eprel']['icon'], 'ext' => 'svg'],
    ];
    
    foreach ($uploads as $type => $data) {
        if (!$data['url']) continue;
        
        try {
            $ext = pathinfo($data['url'], PATHINFO_EXTENSION) ?: $data['ext'];
            $filename = $type === 'icon' ? "ICON_" . pathinfo($data['url'], PATHINFO_FILENAME) : strtoupper($type) . "_$tagid";
            
            $result = $api->upload_media_by_url(
                $data['url'],
                $config['shopware_media_folder_id'],
                $filename,
                $ext,
                $config['force']['shopware']
            );
            
            $mediaIds[$type] = $result['mediaId'] ?? null;
            Logger::logVerbose("Uploaded $type to Shopware for $tagid");
            
        } catch (Exception $e) {
            Logger::logError("Failed to upload $type to Shopware for $tagid", [
                'url' => $data['url'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    // Update product
    try {
        $api->update_product_eprel_data($tagid, $mediaIds['energylabel'], $mediaIds['icon'], $mediaIds['datasheet']);
        Logger::logImportant("Shopware updated for $tagid");
    } catch (Exception $e) {
        Logger::logError("Failed to update Shopware product $tagid", ['error' => $e->getMessage()]);
    }
}

function syncToShopmanager($api, string $tagid, array $files, bool $force): void {
    $energyLabelUrl = $files['eprel']['energylabel'];
    $datasheetUrl = $files['eprel']['datasheet'];
    
    if (!$energyLabelUrl && !$datasheetUrl) {
        Logger::logVerbose("No EPREL files to sync to Shopmanager for $tagid");
        return;
    }
    
    try {
        $smProduct = $api->get_single_product($tagid);
        
        if (empty(trim($smProduct['number'] ?? ''))) {
            Logger::logError("Shopmanager product $tagid has no number, cannot upload");
            return;
        }
        
        $productNumber = $smProduct['number'];
        
        // Upload energy label
        if ($energyLabelUrl && ($force || empty($smProduct['energyLabel'] ?? ''))) {
            $api->uploadEnergyLabel($productNumber, $energyLabelUrl);
            Logger::logImportant("Uploaded energy label to Shopmanager for $tagid");
        }
        
        // Upload datasheet
        if ($datasheetUrl && ($force || empty($smProduct['energyDatasheet'] ?? ''))) {
            $api->uploadDataSheet($productNumber, $datasheetUrl);
            Logger::logImportant("Uploaded datasheet to Shopmanager for $tagid");
        }
        
    } catch (Exception $e) {
        Logger::logError("Failed to sync to Shopmanager for $tagid", ['error' => $e->getMessage()]);
    }
}