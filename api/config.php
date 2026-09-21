<?php
/**
 * Get default configuration from .env
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/../config.php';
    
    $configData = Config::load();
    
    $config = [
        'opencart_db_host' => $configData['opencart']['host'],
        'opencart_db_port' => $configData['opencart']['port'],
        'opencart_db_name' => $configData['opencart']['dbname'],
        'opencart_db_user' => $configData['opencart']['user'],
        'opencart_db_pass' => $configData['opencart']['pass'],
        'opencart_table_prefix' => $configData['opencart']['prefix'],
        'opencart_language_id' => (string)$configData['opencart']['language_id'],
        'opencart_store_id' => (string)$configData['opencart']['store_id'],
        'opencart_image_dir' => $configData['opencart']['image_dir'],
        'opencart_public_base_url' => $configData['opencart']['public_base_url'],
        'shopify_store_domain' => $configData['shopify']['store_domain'],
        'shopify_admin_access_token' => $configData['shopify']['access_token'],
    ];
    
    echo json_encode([
        'success' => true,
        'config' => $config,
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>