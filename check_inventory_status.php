<?php
/**
 * Shows Shopify inventory status for products and variants.
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

$query = <<<'GRAPHQL'
query {
  products(first: 250) {
    edges {
      node {
        id
        title
        status
        variants(first: 50) {
          edges {
            node {
              id
              sku
              inventoryQuantity
              inventoryPolicy
              inventoryItem {
                tracked
                id
              }
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

try {
    $result = $client->execute($query);
    $edges = $result['data']['products']['edges'] ?? [];

    echo "Shopify products scanned: " . count($edges) . PHP_EOL . PHP_EOL;

    foreach ($edges as $edge) {
        $product = $edge['node'];
        echo "Product: {$product['title']} ({$product['status']})\n";

        $variants = $product['variants']['edges'] ?? [];
        foreach ($variants as $variantEdge) {
            $variant = $variantEdge['node'];
            $tracked = $variant['inventoryItem']['tracked'] ?? false;
            echo "  - SKU: " . ($variant['sku'] ?? 'N/A') . " | Qty: " . ($variant['inventoryQuantity'] ?? 'N/A') . " | Policy: " . ($variant['inventoryPolicy'] ?? 'N/A') . " | Tracked: " . ($tracked ? 'yes' : 'no') . "\n";
        }

        echo "\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
