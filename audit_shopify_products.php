<?php
/**
 * Audits products already created in Shopify and prints a concise summary.
 */

require __DIR__ . '/config.php';
require __DIR__ . '/src/Shopify/GraphQLClient.php';

use Shopify\GraphQLClient;

$config = Config::load();

if (!Config::hasShopifyCredentials()) {
    echo "Missing Shopify credentials. Please configure SHOPIFY_STORE_DOMAIN and SHOPIFY_ADMIN_ACCESS_TOKEN in .env.\n";
    exit(1);
}

$client = new GraphQLClient(
    $config['shopify']['store_domain'],
    $config['shopify']['access_token'],
    $config['shopify']['api_version']
);

try {
    $products = $client->getAllProducts();

    echo "Total Shopify products: " . count($products) . PHP_EOL . PHP_EOL;

    foreach (array_slice($products, 0, 25) as $product) {
        echo "- {$product['id']} | {$product['title']} | handle: {$product['handle']}\n";
    }

    if (count($products) > 25) {
        echo "\n... showing first 25 of " . count($products) . " products\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
