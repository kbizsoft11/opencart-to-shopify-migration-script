<?php
/**
 * Find OpenCart products that do not currently exist in Shopify.
 *
 * This is a lightweight verification script used after migration runs.
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

    $table = $db->table('product');
    $products = $db->fetchAll("SELECT product_id, model, sku, status, price FROM {$table} ORDER BY product_id ASC");

    $missing = [];
    $found = 0;

    foreach ($products as $product) {
        $productId = (string) $product['product_id'];
        $match = $shopify->findProductByMetafield('custom', 'opencart_product_id', $productId);

        if ($match) {
            $found++;
        } else {
            $missing[] = [
                'product_id' => $productId,
                'model' => $product['model'] ?? '',
                'sku' => $product['sku'] ?? '',
                'price' => $product['price'] ?? '',
                'status' => $product['status'] ?? 0,
            ];
        }
    }

    echo "OpenCart products checked: " . count($products) . PHP_EOL;
    echo "Products found in Shopify: " . $found . PHP_EOL;
    echo "Missing products: " . count($missing) . PHP_EOL . PHP_EOL;

    if (!empty($missing)) {
        echo "Missing product IDs:\n";
        foreach ($missing as $item) {
            echo "- Product ID: {$item['product_id']} | Model: {$item['model']} | SKU: {$item['sku']} | Price: {$item['price']}\n";
        }
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
