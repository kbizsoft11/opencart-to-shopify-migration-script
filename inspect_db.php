<?php
/**
 * OpenCart Database Schema Inspector
 * Connects to the OpenCart database and analyzes structure
 */

// Database configuration
$config = [
    'host' => '127.0.0.1',
    'port' => '3306',
    'dbname' => 'rachel_opencart',
    'user' => 'root',
    'pass' => '',
    'prefix' => 'oc_'
];

try {
    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "✓ Connected to database: {$config['dbname']}\n\n";
    
    // Get all tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Total tables found: " . count($tables) . "\n\n";
    
    // Product-related tables to analyze
    $productTables = [
        'product',
        'product_description',
        'product_to_store',
        'product_to_category',
        'product_to_layout',
        'product_option',
        'product_option_value',
        'product_attribute',
        'product_image',
        'product_special',
        'product_discount',
        'product_related',
        'product_reward',
        'product_recurring',
        'category',
        'category_description',
        'category_to_store',
        'manufacturer',
        'manufacturer_to_store',
        'option',
        'option_description',
        'option_value',
        'option_value_description',
        'attribute',
        'attribute_description',
        'attribute_group',
        'attribute_group_description',
        'seo_url',
        'url_alias',
        'store',
        'language',
        'weight_class',
        'weight_class_description',
        'length_class',
        'length_class_description',
        'stock_status',
        'tax_class'
    ];
    
    $foundTables = [];
    $missingTables = [];
    
    foreach ($productTables as $table) {
        $fullTableName = $config['prefix'] . $table;
        if (in_array($fullTableName, $tables)) {
            $foundTables[] = $table;
        } else {
            $missingTables[] = $table;
        }
    }
    
    echo "=== PRODUCT-RELATED TABLES ===\n";
    echo "Found: " . count($foundTables) . " tables\n\n";
    
    foreach ($foundTables as $table) {
        $fullTableName = $config['prefix'] . $table;
        
        // Get row count
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `{$fullTableName}`");
        $count = $stmt->fetch()['count'];
        
        // Get table structure
        $stmt = $pdo->query("DESCRIBE `{$fullTableName}`");
        $columns = $stmt->fetchAll();
        
        echo "Table: {$table}\n";
        echo "  Full name: {$fullTableName}\n";
        echo "  Row count: {$count}\n";
        echo "  Columns: " . count($columns) . "\n";
        
        if ($count > 0 && $count <= 5) {
            echo "  Key columns: ";
            $keyColumns = array_slice($columns, 0, 5);
            echo implode(', ', array_column($keyColumns, 'Field')) . "\n";
        } else {
            echo "  Sample columns: ";
            $keyColumns = array_slice($columns, 0, 5);
            echo implode(', ', array_column($keyColumns, 'Field')) . "\n";
        }
        echo "\n";
    }
    
    if (!empty($missingTables)) {
        echo "=== MISSING TABLES (not found in database) ===\n";
        foreach ($missingTables as $table) {
            echo "  - {$config['prefix']}{$table}\n";
        }
        echo "\n";
    }
    
    // Detect OpenCart version
    echo "=== VERSION DETECTION ===\n";
    $versionIndicators = [];
    
    if (in_array($config['prefix'] . 'seo_url', $tables)) {
        $versionIndicators[] = "Has oc_seo_url (OpenCart 2.3+)";
    }
    if (in_array($config['prefix'] . 'url_alias', $tables)) {
        $versionIndicators[] = "Has oc_url_alias (OpenCart 1.x/2.x)";
    }
    if (in_array($config['prefix'] . 'setting', $tables)) {
        $stmt = $pdo->query("SELECT value FROM `{$config['prefix']}setting` WHERE `key` = 'config_version' LIMIT 1");
        $version = $stmt->fetch();
        if ($version) {
            $versionIndicators[] = "Version from settings: {$version['value']}";
        }
    }
    
    foreach ($versionIndicators as $indicator) {
        echo "  {$indicator}\n";
    }
    echo "\n";
    
    // Analyze product data
    echo "=== PRODUCT DATA ANALYSIS ===\n";
    
    if (in_array('product', $foundTables)) {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM `{$config['prefix']}product`");
        $total = $stmt->fetch()['total'];
        echo "Total products: {$total}\n";
        
        $stmt = $pdo->query("SELECT COUNT(*) as active FROM `{$config['prefix']}product` WHERE status = 1");
        $active = $stmt->fetch()['active'];
        echo "Active products: {$active}\n";
        
        $stmt = $pdo->query("SELECT COUNT(DISTINCT manufacturer_id) as count FROM `{$config['prefix']}product` WHERE manufacturer_id > 0");
        $manufacturers = $stmt->fetch()['count'];
        echo "Products with manufacturer: {$manufacturers}\n";
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `{$config['prefix']}product` WHERE image != ''");
        $withImages = $stmt->fetch()['count'];
        echo "Products with main image: {$withImages}\n\n";
    }
    
    if (in_array('product_option', $foundTables)) {
        $stmt = $pdo->query("SELECT COUNT(DISTINCT product_id) as count FROM `{$config['prefix']}product_option`");
        $withOptions = $stmt->fetch()['count'];
        echo "Products with options: {$withOptions}\n";
        
        $stmt = $pdo->query("SELECT product_id, COUNT(*) as option_count FROM `{$config['prefix']}product_option` GROUP BY product_id ORDER BY option_count DESC LIMIT 1");
        $maxOptions = $stmt->fetch();
        if ($maxOptions) {
            echo "Max options per product: {$maxOptions['option_count']} (product_id: {$maxOptions['product_id']})\n\n";
        }
    }
    
    if (in_array('product_option_value', $foundTables)) {
        $stmt = $pdo->query("
            SELECT product_id, COUNT(*) as variant_count 
            FROM `{$config['prefix']}product_option_value` 
            GROUP BY product_id 
            ORDER BY variant_count DESC 
            LIMIT 1
        ");
        $maxVariants = $stmt->fetch();
        if ($maxVariants) {
            echo "Max option values per product: {$maxVariants['variant_count']} (product_id: {$maxVariants['product_id']})\n\n";
        }
    }
    
    if (in_array('product_attribute', $foundTables)) {
        $stmt = $pdo->query("SELECT COUNT(DISTINCT product_id) as count FROM `{$config['prefix']}product_attribute`");
        $withAttributes = $stmt->fetch()['count'];
        echo "Products with attributes: {$withAttributes}\n\n";
    }
    
    if (in_array('product_image', $foundTables)) {
        $stmt = $pdo->query("SELECT COUNT(DISTINCT product_id) as count FROM `{$config['prefix']}product_image`");
        $withAdditionalImages = $stmt->fetch()['count'];
        echo "Products with additional images: {$withAdditionalImages}\n";
        
        $stmt = $pdo->query("
            SELECT product_id, COUNT(*) as image_count 
            FROM `{$config['prefix']}product_image` 
            GROUP BY product_id 
            ORDER BY image_count DESC 
            LIMIT 1
        ");
        $maxImages = $stmt->fetch();
        if ($maxImages) {
            echo "Max additional images per product: {$maxImages['image_count']} (product_id: {$maxImages['product_id']})\n\n";
        }
    }
    
    if (in_array('category', $foundTables)) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `{$config['prefix']}category`");
        $categories = $stmt->fetch()['count'];
        echo "Total categories: {$categories}\n\n";
    }
    
    // Check for SEO URLs
    if (in_array('seo_url', $foundTables)) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `{$config['prefix']}seo_url` WHERE query LIKE 'product_id=%'");
        $productSeoUrls = $stmt->fetch()['count'];
        echo "Product SEO URLs: {$productSeoUrls}\n\n";
    } elseif (in_array('url_alias', $foundTables)) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `{$config['prefix']}url_alias` WHERE query LIKE 'product_id=%'");
        $productSeoUrls = $stmt->fetch()['count'];
        echo "Product URL aliases: {$productSeoUrls}\n\n";
    }
    
    // Sample a few products for analysis
    echo "=== SAMPLE PRODUCTS ===\n";
    $stmt = $pdo->query("
        SELECT p.product_id, p.model, p.sku, p.price, p.quantity, p.status, p.image,
               pd.name, pd.description
        FROM `{$config['prefix']}product` p
        LEFT JOIN `{$config['prefix']}product_description` pd ON p.product_id = pd.product_id
        WHERE pd.language_id = 1
        LIMIT 5
    ");
    
    $samples = $stmt->fetchAll();
    foreach ($samples as $product) {
        echo "Product ID: {$product['product_id']}\n";
        echo "  Name: {$product['name']}\n";
        echo "  Model: {$product['model']}\n";
        echo "  SKU: {$product['sku']}\n";
        echo "  Price: {$product['price']}\n";
        echo "  Quantity: {$product['quantity']}\n";
        echo "  Status: " . ($product['status'] ? 'Active' : 'Inactive') . "\n";
        echo "  Has image: " . ($product['image'] ? 'Yes' : 'No') . "\n";
        echo "  Description length: " . strlen($product['description']) . " chars\n";
        echo "\n";
    }
    
    echo "✓ Database inspection complete\n";
    
} catch (PDOException $e) {
    echo "✗ Database error: " . $e->getMessage() . "\n";
    exit(1);
}
