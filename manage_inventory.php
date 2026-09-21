<?php
/**
 * Shopify Inventory Management Tool
 * 
 * Features:
 * - Set infinite stock for products and all their variants
 * - Update specific products or bulk update all products
 * - Set custom inventory quantities
 * - Enable/disable inventory tracking
 * - Comprehensive logging and progress tracking
 * 
 * Usage:
 *   php manage_inventory.php --help
 *   php manage_inventory.php --infinite --all
 *   php manage_inventory.php --infinite --product-ids=123,456,789
 *   php manage_inventory.php --set-quantity=100 --product-ids=123
 *   php manage_inventory.php --disable-tracking --product-ids=123
 */

require 'config.php';
require 'src/Utils/ImageProcessor.php';
require 'src/Shopify/GraphQLClient.php';

class InventoryManager
{
    private $client;
    private $verbose = false;
    
    public function __construct($client, $verbose = false)
    {
        $this->client = $client;
        $this->verbose = $verbose;
    }
    
    /**
     * Set infinite inventory for products
     */
    public function setInfiniteInventory(array $productIds = null, bool $allProducts = false): array
    {
        if ($allProducts) {
            echo "🔄 Setting infinite inventory for ALL products in store...\n\n";
            $products = $this->getAllProducts();
        } else {
            echo "🔄 Setting infinite inventory for " . count($productIds) . " specific products...\n\n";
            $products = $this->getProductsByIds($productIds);
        }
        
        return $this->updateProductsInventory($products, 'infinite');
    }
    
    /**
     * Set specific quantity for products
     */
    public function setQuantity(int $quantity, array $productIds = null, bool $allProducts = false): array
    {
        if ($allProducts) {
            echo "🔄 Setting quantity=$quantity for ALL products in store...\n\n";
            $products = $this->getAllProducts();
        } else {
            echo "🔄 Setting quantity=$quantity for " . count($productIds) . " specific products...\n\n";
            $products = $this->getProductsByIds($productIds);
        }
        
        return $this->updateProductsInventory($products, 'quantity', $quantity);
    }
    
    /**
     * Disable inventory tracking for products
     */
    public function disableTracking(array $productIds = null, bool $allProducts = false): array
    {
        if ($allProducts) {
            echo "🔄 Disabling inventory tracking for ALL products in store...\n\n";
            $products = $this->getAllProducts();
        } else {
            echo "🔄 Disabling inventory tracking for " . count($productIds) . " specific products...\n\n";
            $products = $this->getProductsByIds($productIds);
        }
        
        return $this->updateProductsInventory($products, 'disable');
    }
    
    /**
     * Get all products from Shopify
     */
    private function getAllProducts(): array
    {
        $allProducts = [];
        $cursor = null;
        $pageCount = 0;
        
        do {
            $pageCount++;
            if ($this->verbose) echo "  📄 Fetching page $pageCount...\n";
            
            $query = '
                query getProducts($cursor: String) {
                    products(first: 250, after: $cursor) {
                        edges {
                            node {
                                id
                                title
                                handle
                                variants(first: 250) {
                                    edges {
                                        node {
                                            id
                                            sku
                                            inventoryQuantity
                                            inventoryPolicy
                                            inventoryItem {
                                                id
                                                tracked
                                                requiresShipping
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        pageInfo {
                            hasNextPage
                            endCursor
                        }
                    }
                }
            ';
            
            $variables = ['cursor' => $cursor];
            $result = $this->client->execute($query, $variables);
            
            if (isset($result['errors'])) {
                throw new Exception("GraphQL Error: " . json_encode($result['errors']));
            }
            
            $edges = $result['data']['products']['edges'] ?? [];
            $pageInfo = $result['data']['products']['pageInfo'] ?? [];
            
            foreach ($edges as $edge) {
                $product = $edge['node'];
                $product['variants'] = array_map(fn($v) => $v['node'], $product['variants']['edges']);
                $allProducts[] = $product;
            }
            
            $cursor = $pageInfo['endCursor'] ?? null;
            
        } while ($pageInfo['hasNextPage'] ?? false);
        
        echo "  ✓ Found " . count($allProducts) . " products\n\n";
        return $allProducts;
    }
    
    /**
     * Get specific products by IDs
     */
    private function getProductsByIds(array $productIds): array
    {
        $products = [];
        
        foreach ($productIds as $productId) {
            if ($this->verbose) echo "  🔍 Fetching product ID: $productId...\n";
            
            // Convert OpenCart ID to Shopify product if it's a number
            if (is_numeric($productId)) {
                $product = $this->client->findProductByMetafield('custom', 'opencart_product_id', (string)$productId);
                
                if (!$product) {
                    echo "  ⚠️  Product with OpenCart ID $productId not found in Shopify\n";
                    continue;
                }
                
                $shopifyId = $product['id'];
            } else {
                $shopifyId = $productId; // Assume it's already a Shopify ID
            }
            
            // Get full product with variants
            $query = '
                query getProduct($id: ID!) {
                    product(id: $id) {
                        id
                        title
                        handle
                        variants(first: 250) {
                            edges {
                                node {
                                    id
                                    sku
                                    inventoryQuantity
                                    inventoryPolicy
                                    inventoryItem {
                                        id
                                        tracked
                                        requiresShipping
                                    }
                                }
                            }
                        }
                    }
                }
            ';
            
            $result = $this->client->execute($query, ['id' => $shopifyId]);
            
            if (isset($result['errors']) || !isset($result['data']['product'])) {
                echo "  ⚠️  Product $productId not found or error occurred\n";
                continue;
            }
            
            $product = $result['data']['product'];
            $product['variants'] = array_map(fn($v) => $v['node'], $product['variants']['edges']);
            $products[] = $product;
        }
        
        echo "  ✓ Found " . count($products) . " valid products\n\n";
        return $products;
    }
    
    /**
     * Update inventory for products
     */
    private function updateProductsInventory(array $products, string $action, int $quantity = null): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'total_variants' => 0,
            'errors' => []
        ];
        
        $startTime = microtime(true);
        
        foreach ($products as $index => $product) {
            $productNum = $index + 1;
            $totalProducts = count($products);
            $percentage = round(($productNum / $totalProducts) * 100, 1);
            
            echo "[$productNum/$totalProducts] ($percentage%) {$product['title']}\n";
            echo "  📦 Product ID: {$product['id']}\n";
            echo "  🔢 Variants: " . count($product['variants']) . "\n";
            
            if (empty($product['variants'])) {
                echo "  ⚠️  No variants found, skipping\n\n";
                $results['skipped']++;
                continue;
            }
            
            $variantSuccess = 0;
            $variantFailed = 0;
            
            // Process variants in batches of 100 (Shopify bulk limit)
            $variantBatches = array_chunk($product['variants'], 100);
            
            foreach ($variantBatches as $batchIndex => $batch) {
                if (count($variantBatches) > 1) {
                    echo "  📊 Processing batch " . ($batchIndex + 1) . "/" . count($variantBatches) . " (" . count($batch) . " variants)\n";
                }
                
                $batchResult = $this->updateVariantBatch($batch, $action, $quantity);
                $variantSuccess += $batchResult['success'];
                $variantFailed += $batchResult['failed'];
                
                if (!empty($batchResult['errors'])) {
                    $results['errors'] = array_merge($results['errors'], $batchResult['errors']);
                }
                
                // Small delay between batches to avoid rate limiting
                if (count($variantBatches) > 1) {
                    usleep(500000); // 0.5 seconds
                }
            }
            
            $results['total_variants'] += count($product['variants']);
            
            if ($variantFailed === 0) {
                $results['success']++;
                echo "  ✅ SUCCESS: Updated all $variantSuccess variants\n";
            } else {
                $results['failed']++;
                echo "  ❌ PARTIAL: Updated $variantSuccess/$variantSuccess variants ($variantFailed failed)\n";
            }
            
            echo "\n";
            
            // Show progress every 10 products
            if ($productNum % 10 === 0) {
                $elapsed = microtime(true) - $startTime;
                $rate = $productNum / $elapsed;
                $remaining = ($totalProducts - $productNum) / $rate;
                
                echo "  📊 Progress: $productNum/$totalProducts completed\n";
                echo "  ⏱️  Rate: " . round($rate, 2) . " products/sec\n";
                echo "  ⏰ ETA: " . round($remaining / 60, 1) . " minutes remaining\n\n";
            }
        }
        
        return $results;
    }
    
    /**
     * Update a batch of variants
     */
    private function updateVariantBatch(array $variants, string $action, int $quantity = null): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];
        
        foreach ($variants as $variant) {
            try {
                $success = $this->updateSingleVariant($variant, $action, $quantity);
                if ($success) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'variant_id' => $variant['id'],
                    'sku' => $variant['sku'],
                    'error' => $e->getMessage()
                ];
                
                if ($this->verbose) {
                    echo "    ❌ Variant {$variant['sku']}: {$e->getMessage()}\n";
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Update a single variant's inventory
     */
    private function updateSingleVariant(array $variant, string $action, int $quantity = null): bool
    {
        $variantId = $variant['id'];
        
        switch ($action) {
            case 'infinite':
                return $this->setVariantInfinite($variantId);
                
            case 'quantity':
                return $this->setVariantQuantity($variantId, $quantity);
                
            case 'disable':
                return $this->disableVariantTracking($variantId);
                
            default:
                throw new Exception("Unknown action: $action");
        }
    }
    
    /**
     * Set variant to infinite inventory using inventorySetQuantities
     */
    private function setVariantInfinite(string $variantId): bool
    {
        // First get the variant's inventory item and location
        $variant = $this->getVariantDetails($variantId);
        if (!$variant || !$variant['inventoryItem']) {
            throw new Exception("Could not get variant inventory details");
        }
        
        $inventoryItemId = $variant['inventoryItem']['id'];
        $locationId = $this->getMainLocationId();
        
        // Activate tracking if not already tracked
        if (!$variant['inventoryItem']['tracked']) {
            $this->activateInventoryTracking($inventoryItemId, $locationId);
        }
        
        // Set quantities using the new API
        $query = '
            mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
                inventorySetQuantities(input: $input) {
                    inventoryLevels {
                        id
                        quantities(names: ["available", "on_hand"]) {
                            name
                            quantity
                        }
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        ';
        
        $variables = [
            'input' => [
                'reason' => 'correction',
                'name' => 'available',
                'inventoryItemAdjustments' => [
                    [
                        'inventoryItemId' => $inventoryItemId,
                        'locationId' => $locationId,
                        'quantity' => 999999
                    ]
                ]
            ]
        ];
        
        $result = $this->client->execute($query, $variables);
        
        if (isset($result['data']['inventorySetQuantities']['userErrors']) &&
            !empty($result['data']['inventorySetQuantities']['userErrors'])) {
            $errors = $result['data']['inventorySetQuantities']['userErrors'];
            throw new Exception("Inventory update errors: " . json_encode($errors));
        }
        
        // Also set on_hand quantity
        $variables['input']['name'] = 'on_hand';
        $result2 = $this->client->execute($query, $variables);
        
        if (isset($result2['data']['inventorySetQuantities']['userErrors']) &&
            !empty($result2['data']['inventorySetQuantities']['userErrors'])) {
            $errors = $result2['data']['inventorySetQuantities']['userErrors'];
            throw new Exception("On-hand inventory update errors: " . json_encode($errors));
        }
        
        return true;
    }
    
    /**
     * Set variant to specific quantity using inventorySetQuantities
     */
    private function setVariantQuantity(string $variantId, int $quantity): bool
    {
        // First get the variant's inventory item and location
        $variant = $this->getVariantDetails($variantId);
        if (!$variant || !$variant['inventoryItem']) {
            throw new Exception("Could not get variant inventory details");
        }
        
        $inventoryItemId = $variant['inventoryItem']['id'];
        $locationId = $this->getMainLocationId();
        
        // Activate tracking if not already tracked
        if (!$variant['inventoryItem']['tracked']) {
            $this->activateInventoryTracking($inventoryItemId, $locationId);
        }
        
        // Set available quantity
        $query = '
            mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
                inventorySetQuantities(input: $input) {
                    inventoryLevels {
                        id
                        quantities(names: ["available", "on_hand"]) {
                            name
                            quantity
                        }
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        ';
        
        $variables = [
            'input' => [
                'reason' => 'correction',
                'name' => 'available',
                'inventoryItemAdjustments' => [
                    [
                        'inventoryItemId' => $inventoryItemId,
                        'locationId' => $locationId,
                        'quantity' => $quantity
                    ]
                ]
            ]
        ];
        
        $result = $this->client->execute($query, $variables);
        
        if (isset($result['data']['inventorySetQuantities']['userErrors']) &&
            !empty($result['data']['inventorySetQuantities']['userErrors'])) {
            $errors = $result['data']['inventorySetQuantities']['userErrors'];
            throw new Exception("Inventory update errors: " . json_encode($errors));
        }
        
        // Also set on_hand quantity to the same value
        $variables['input']['name'] = 'on_hand';
        $result2 = $this->client->execute($query, $variables);
        
        if (isset($result2['data']['inventorySetQuantities']['userErrors']) &&
            !empty($result2['data']['inventorySetQuantities']['userErrors'])) {
            $errors = $result2['data']['inventorySetQuantities']['userErrors'];
            throw new Exception("On-hand inventory update errors: " . json_encode($errors));
        }
        
        return true;
    }
    
    /**
     * Disable variant inventory tracking using inventoryItemUpdate
     */
    private function disableVariantTracking(string $variantId): bool
    {
        // Get the variant's inventory item
        $variant = $this->getVariantDetails($variantId);
        if (!$variant || !$variant['inventoryItem']) {
            throw new Exception("Could not get variant inventory details");
        }
        
        $inventoryItemId = $variant['inventoryItem']['id'];
        
        $query = '
            mutation inventoryItemUpdate($id: ID!, $input: InventoryItemInput!) {
                inventoryItemUpdate(id: $id, input: $input) {
                    inventoryItem {
                        id
                        tracked
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        ';
        
        $variables = [
            'id' => $inventoryItemId,
            'input' => [
                'tracked' => false
            ]
        ];
        
        $result = $this->client->execute($query, $variables);
        
        if (isset($result['data']['inventoryItemUpdate']['userErrors']) &&
            !empty($result['data']['inventoryItemUpdate']['userErrors'])) {
            $errors = $result['data']['inventoryItemUpdate']['userErrors'];
            throw new Exception("Inventory item update errors: " . json_encode($errors));
        }
        
        return true;
    }
    
    /**
     * Get variant details including inventory item
     */
    private function getVariantDetails(string $variantId): ?array
    {
        $query = '
            query getVariant($id: ID!) {
                productVariant(id: $id) {
                    id
                    sku
                    inventoryQuantity
                    inventoryPolicy
                    inventoryItem {
                        id
                        tracked
                        requiresShipping
                    }
                }
            }
        ';
        
        $result = $this->client->execute($query, ['id' => $variantId]);
        
        if (isset($result['errors']) || !isset($result['data']['productVariant'])) {
            return null;
        }
        
        return $result['data']['productVariant'];
    }
    
    /**
     * Activate inventory tracking for an item at a location
     */
    private function activateInventoryTracking(string $inventoryItemId, string $locationId): bool
    {
        $query = '
            mutation inventoryActivate($inventoryItemId: ID!, $locationId: ID!, $available: Int) {
                inventoryActivate(inventoryItemId: $inventoryItemId, locationId: $locationId, available: $available) {
                    inventoryLevel {
                        id
                        location {
                            id
                        }
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        ';
        
        $variables = [
            'inventoryItemId' => $inventoryItemId,
            'locationId' => $locationId,
            'available' => 0 // Start with 0, will be updated separately
        ];
        
        $result = $this->client->execute($query, $variables);
        
        if (isset($result['data']['inventoryActivate']['userErrors']) &&
            !empty($result['data']['inventoryActivate']['userErrors'])) {
            $errors = $result['data']['inventoryActivate']['userErrors'];
            throw new Exception("Inventory activation errors: " . json_encode($errors));
        }
        
        return true;
    }
    
    /**
     * Get main location ID for inventory updates
     */
    private function getMainLocationId(): string
    {
        $query = '
            query getLocations {
                locations(first: 1) {
                    edges {
                        node {
                            id
                            name
                        }
                    }
                }
            }
        ';
        
        $result = $this->client->execute($query);
        
        if (!isset($result['data']['locations']['edges'][0]['node']['id'])) {
            throw new Exception("Could not get main location ID");
        }
        
        return $result['data']['locations']['edges'][0]['node']['id'];
    }
}

// CLI Interface
function showHelp() {
    echo "
🛒 Shopify Inventory Management Tool

USAGE:
  php manage_inventory.php [OPTIONS]

OPTIONS:
  --help                     Show this help message
  --verbose                  Show detailed progress information
  
ACTIONS (choose one):
  --infinite                 Set infinite inventory (999,999 units + allow overselling)
  --set-quantity=N           Set specific quantity N for all variants
  --disable-tracking         Disable inventory tracking (unlimited selling)

TARGETING (choose one):
  --all                      Apply to ALL products in store
  --product-ids=1,2,3        Apply to specific products (OpenCart or Shopify IDs)
  --limit=N                  Apply to first N products only (with --all)

EXAMPLES:
  # Set infinite stock for all products
  php manage_inventory.php --infinite --all
  
  # Set infinite stock for specific products (OpenCart IDs)
  php manage_inventory.php --infinite --product-ids=123,456,789
  
  # Set quantity of 100 for specific products
  php manage_inventory.php --set-quantity=100 --product-ids=123,456
  
  # Disable inventory tracking for all products (unlimited selling)
  php manage_inventory.php --disable-tracking --all
  
  # Set infinite stock for first 50 products only
  php manage_inventory.php --infinite --all --limit=50

NOTES:
  - Product IDs can be OpenCart IDs (numbers) or Shopify IDs (gid://...)
  - All variants of each product will be updated
  - Large operations may take several minutes
  - Progress is shown every 10 products
  - Use --verbose for detailed variant-level information

";
}

// Parse CLI arguments
$options = getopt('', [
    'help',
    'verbose',
    'infinite',
    'set-quantity:',
    'disable-tracking',
    'all',
    'product-ids:',
    'limit:'
]);

if (isset($options['help']) || count($argv) === 1) {
    showHelp();
    exit(0);
}

// Validate arguments
$actions = ['infinite', 'set-quantity', 'disable-tracking'];
$actionCount = 0;
$selectedAction = null;
$quantity = null;

foreach ($actions as $action) {
    if (isset($options[$action])) {
        $actionCount++;
        $selectedAction = $action;
        if ($action === 'set-quantity') {
            $quantity = intval($options[$action]);
            if ($quantity < 0) {
                echo "❌ Error: Quantity must be a positive integer\n";
                exit(1);
            }
        }
    }
}

if ($actionCount === 0) {
    echo "❌ Error: Please specify an action (--infinite, --set-quantity=N, or --disable-tracking)\n";
    exit(1);
}

if ($actionCount > 1) {
    echo "❌ Error: Please specify only one action\n";
    exit(1);
}

// Parse targeting
$allProducts = isset($options['all']);
$productIds = isset($options['product-ids']) ? explode(',', $options['product-ids']) : null;
$limit = isset($options['limit']) ? intval($options['limit']) : null;

if (!$allProducts && !$productIds) {
    echo "❌ Error: Please specify --all or --product-ids=1,2,3\n";
    exit(1);
}

if ($allProducts && $productIds) {
    echo "❌ Error: Please specify either --all or --product-ids, not both\n";
    exit(1);
}

if ($limit && !$allProducts) {
    echo "❌ Error: --limit can only be used with --all\n";
    exit(1);
}

// Clean up product IDs
if ($productIds) {
    $productIds = array_map('trim', $productIds);
    $productIds = array_filter($productIds, fn($id) => !empty($id));
}

// Initialize
$config = Config::load();
$shopifyConfig = $config['shopify'];
$client = new \Shopify\GraphQLClient(
    $shopifyConfig['store_domain'],
    $shopifyConfig['access_token'],
    $shopifyConfig['api_version']
);

$verbose = isset($options['verbose']);
$manager = new InventoryManager($client, $verbose);

echo "🛒 Shopify Inventory Management Tool\n";
echo str_repeat("=", 50) . "\n\n";

try {
    $startTime = microtime(true);
    
    // Execute the selected action
    switch ($selectedAction) {
        case 'infinite':
            $results = $manager->setInfiniteInventory($productIds, $allProducts);
            break;
            
        case 'set-quantity':
            $results = $manager->setQuantity($quantity, $productIds, $allProducts);
            break;
            
        case 'disable-tracking':
            $results = $manager->disableTracking($productIds, $allProducts);
            break;
    }
    
    $totalTime = microtime(true) - $startTime;
    
    // Show final results
    echo str_repeat("=", 50) . "\n";
    echo "📊 FINAL RESULTS\n";
    echo str_repeat("=", 50) . "\n\n";
    
    echo "✅ Products updated successfully: {$results['success']}\n";
    echo "❌ Products failed: {$results['failed']}\n";
    echo "⚠️  Products skipped: {$results['skipped']}\n";
    echo "🔢 Total variants processed: {$results['total_variants']}\n";
    echo "⏱️  Total time: " . round($totalTime / 60, 2) . " minutes\n";
    echo "📈 Average rate: " . round(($results['success'] + $results['failed']) / $totalTime, 2) . " products/sec\n\n";
    
    if (!empty($results['errors'])) {
        echo "❌ ERRORS ENCOUNTERED:\n";
        echo str_repeat("-", 40) . "\n";
        foreach ($results['errors'] as $error) {
            echo "Variant: {$error['variant_id']} ({$error['sku']})\n";
            echo "Error: {$error['error']}\n\n";
        }
    }
    
    if ($results['failed'] === 0) {
        echo "🎉 ALL OPERATIONS COMPLETED SUCCESSFULLY!\n";
    } else {
        echo "⚠️  Some operations failed. Check the errors above.\n";
    }
    
} catch (Exception $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
?>