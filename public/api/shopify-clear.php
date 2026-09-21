<?php
/**
 * Delete all test products from Shopify (marked with opencart_product_id metafield)
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/Utils/ImageProcessor.php';
require_once __DIR__ . '/../../src/Shopify/GraphQLClient.php';

use Shopify\GraphQLClient;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $config = Config::load();
    
    if (!Config::hasShopifyCredentials()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Shopify credentials not configured',
        ]);
        exit;
    }
    
    $shopifyConfig = $config['shopify'];
    $client = new GraphQLClient(
        $shopifyConfig['store_domain'],
        $shopifyConfig['access_token'],
        $shopifyConfig['api_version']
    );
    
    // Get all products
    $products = $client->getAllProducts();
    
    if (empty($products)) {
        echo json_encode([
            'success' => true,
            'message' => 'No products to delete',
            'deleted_count' => 0,
        ]);
        exit;
    }
    
    // Delete each product
    $deletedCount = 0;
    $errors = [];
    
    foreach ($products as $product) {
        try {
            $client->deleteProduct($product['id']);
            $deletedCount++;
        } catch (\Exception $e) {
            $errors[] = "Failed to delete {$product['title']}: " . $e->getMessage();
        }
    }
    
    $message = "Deleted {$deletedCount} product" . ($deletedCount !== 1 ? 's' : '');
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'deleted_count' => $deletedCount,
        'errors' => $errors,
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to clear products: ' . $e->getMessage(),
        'deleted_count' => 0
    ]);
}
?>
