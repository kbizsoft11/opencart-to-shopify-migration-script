<?php
/**
 * Debug and manage metafield definitions via GraphQL
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
    
    // Query for metafield definitions (products only)
    $query = '
        {
            metafieldDefinitions(first: 100, ownerType: PRODUCT) {
                edges {
                    node {
                        id
                        name
                        namespace
                        key
                        type {
                            name
                        }
                        description
                    }
                }
            }
        }
    ';
    
    $result = $client->execute($query);
    
    if (isset($result['errors'])) {
        throw new \Exception("GraphQL error: " . json_encode($result['errors']));
    }
    
    $definitions = [];
    $edges = $result['data']['metafieldDefinitions']['edges'] ?? [];
    
    foreach ($edges as $edge) {
        $node = $edge['node'];
        $definitions[] = [
            'id' => $node['id'],
            'name' => $node['name'],
            'namespace' => $node['namespace'],
            'key' => $node['key'],
            'type' => $node['type']['name'] ?? 'Unknown',
            'description' => $node['description'] ?? '',
            'fullKey' => $node['namespace'] . '.' . $node['key'],
        ];
    }
    
    // Check if opencart_product_id exists
    $ocProductIdDef = null;
    foreach ($definitions as $def) {
        if ($def['fullKey'] === 'custom.opencart_product_id') {
            $ocProductIdDef = $def;
            break;
        }
    }
    
    $status = 'OK';
    $statusMessage = 'All metafield definitions are properly configured';
    
    if (!$ocProductIdDef) {
        $status = 'MISSING';
        $statusMessage = 'opencart_product_id metafield definition not found. It will be created automatically during migration.';
    }
    
    echo json_encode([
        'success' => true,
        'message' => $statusMessage,
        'status' => $status,
        'definitions' => $definitions,
        'opencart_product_id_definition' => $ocProductIdDef,
        'total_definitions' => count($definitions),
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to debug metafields: ' . $e->getMessage(),
    ]);
}
?>
