<?php
/**
 * Lists Shopify products that contain custom OpenCart metafields.
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
        handle
        metafields(first: 50) {
          edges {
            node {
              namespace
              key
              value
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

    $count = 0;
    foreach ($edges as $edge) {
        $product = $edge['node'];
        $metafields = $product['metafields']['edges'] ?? [];

        if (empty($metafields)) {
            continue;
        }

        $count++;
        echo "Product: {$product['title']} ({$product['handle']})\n";
        foreach ($metafields as $item) {
            $node = $item['node'];
            echo "  - {$node['namespace']}.{$node['key']} = {$node['value']}\n";
        }
        echo "\n";
    }

    echo "Products with metafields: {$count}\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
