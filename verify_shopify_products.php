<?php
/**
 * Verifies Shopify product count and checks whether OpenCart products exist in Shopify.
 */

require __DIR__ . '/config.php';
require __DIR__ . '/src/OpenCart/Database.php';
require __DIR__ . '/src/Shopify/GraphQLClient.php';

use OpenCart\Database;
use Shopify\GraphQLClient;

$config = Config::load();

if (!Config::hasShopifyCredentials()) {
    echo "Missing Shopify credentials. Please configure SHOPIFY_STORE_DOMAIN and SHOPIFY_ADMIN_ACCESS_TOKEN in .env.\n";
    exit(1);
}

try {
    $db = new Database($config['opencart']);
    $shopify = new GraphQLClient(
        $config['shopify']['store_domain'],
        $config['shopify']['access_token'],
        $config['shopify']['api_version']
    );

    $openCartTotal = count($db->fetchAll("SELECT product_id FROM " . $db->table('product')));
    $shopifyProducts = $shopify->getAllProducts();

    echo "OpenCart product count: {$openCartTotal}\n";
    echo "Shopify product count: " . count($shopifyProducts) . "\n\n";

    $missing = 0;
    foreach ($db->fetchAll("SELECT product_id FROM " . $db->table('product') . " ORDER BY product_id ASC") as $row) {
        $productId = (string) $row['product_id'];
        if (!$shopify->findProductByMetafield('custom', 'opencart_product_id', $productId)) {
            $missing++;
        }
    }

    echo "OpenCart products missing in Shopify: {$missing}\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
