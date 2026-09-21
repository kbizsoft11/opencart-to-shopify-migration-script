<?php
/**
 * List Shopify products via GraphQL
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/Utils/ImageProcessor.php';
require_once __DIR__ . '/../../src/Shopify/GraphQLClient.php';

use Shopify\GraphQLClient;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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
    
    // Get all products using the getAllProducts method we created
    $products = $client->getAllProducts();
    
    // Enhance with metafield data
    $enhancedProducts = [];
    foreach ($products as $product) {
        $enhancedProducts[] = [
            'id' => $product['id'],
            'title' => $product['title'] ?? 'Unknown',
            'handle' => $product['handle'] ?? '',
            'status' => $product['status'] ?? 'UNKNOWN',
            'opencart_product_id' => $product['opencart_product_id'] ?? null,
        ];
    }
    
    echo json_encode([
        'success' => true,
        'count' => count($enhancedProducts),
        'products' => $enhancedProducts,
        'message' => 'Retrieved ' . count($enhancedProducts) . ' products from Shopify'
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to list products: ' . $e->getMessage(),
    ]);
}
?>
