<?php
/**
 * OpenCart to Shopify Migration Script
 * 
 * Usage:
 *   php migrate.php --dry-run --limit=20
 *   php migrate.php --product-id=125 --dry-run
 *   php migrate.php --send --limit=10
 *   php migrate.php --help
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/OpenCart/Database.php';
require_once __DIR__ . '/src/OpenCart/ProductExtractor.php';
require_once __DIR__ . '/src/Mapper/ProductMapper.php';
require_once __DIR__ . '/src/Mapper/ImageMappingProductMapper.php';
require_once __DIR__ . '/src/Mapper/CsvImageProductMapper.php';
require_once __DIR__ . '/src/Utils/OversizedImageProcessor.php';
require_once __DIR__ . '/src/Shopify/GraphQLClient.php';
require_once __DIR__ . '/src/Reporter/CsvReporter.php';
require_once __DIR__ . '/src/Utils/ImageProcessor.php';

use OpenCart\Database;
use OpenCart\ProductExtractor;
use Mapper\ProductMapper;
use Mapper\ImageMappingProductMapper;
use Mapper\CsvImageProductMapper;
use Shopify\GraphQLClient;
use Reporter\CsvReporter;
use App\Utils\OversizedImageProcessor;

class MigrationScript
{
    private array $config;
    private array $args;
    private bool $verbose = false;
    private bool $dryRun = true;
    private bool $sendMode = false;
    private bool $updateExisting = false;
    private bool $processImages = false;
    private bool $useImageMapping = false;
    private bool $useImageCsv = false;
    private bool $deleteAllProducts = false;
    private bool $disableTracking = false;
    private ?string $imageHostOverride = null;
    private ?int $limit = null;
    private ?int $productId = null;
    private string $format = 'text'; // text or jsonl
    
    private Database $db;
    private ProductExtractor $extractor;
    private ProductMapper $mapper;
    private ?OversizedImageProcessor $imageProcessor = null;
    private ?GraphQLClient $shopify = null;
    private CsvReporter $reporter;
    
    public function __construct(array $args)
    {
        $this->args = $args;
        $this->config = Config::load();
        
        // Disable output buffering for real-time streaming
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        $this->parseArguments();
        
        // Initialize components
        $this->db = new Database($this->config['opencart']);
        $this->extractor = new ProductExtractor(
            $this->db,
            $this->config['opencart']['language_id'],
            $this->config['opencart']['store_id']
        );
        
        // Initialize mapper - choose based on image handling options
        if ($this->useImageCsv) {
            // Use CSV-based image URL mapping
            $this->mapper = new CsvImageProductMapper([
                'max_options' => $this->config['migration']['max_shopify_options'],
                'max_variants' => $this->config['migration']['max_shopify_variants'],
                'image_base_url' => $this->config['opencart']['public_base_url'],
                'image_local_dir' => $this->config['opencart']['image_dir'],
                'image_csv_path' => __DIR__ . '/output/image_urls.csv',
            ]);
        } elseif ($this->useImageMapping) {
            // Use processed image mapping
            $imageProcessorConfig = [
                'output_dir' => __DIR__ . '/output/image_processing',
                'csv_filename' => 'image_mapping.csv',
                'opencart_base_url' => $this->config['opencart']['public_base_url'],
                'verbose' => $this->verbose,
            ];
            
            $this->imageProcessor = new OversizedImageProcessor($this->db, $this->extractor, $imageProcessorConfig);
            
            $this->mapper = new ImageMappingProductMapper([
                'max_options' => $this->config['migration']['max_shopify_options'],
                'max_variants' => $this->config['migration']['max_shopify_variants'],
                'image_base_url' => $this->config['opencart']['public_base_url'],
                'image_local_dir' => $this->config['opencart']['image_dir'],
            ], $this->imageProcessor);
        } else {
            // Standard mapping without image processing
            $this->mapper = new ProductMapper([
                'max_options' => $this->config['migration']['max_shopify_options'],
                'max_variants' => $this->config['migration']['max_shopify_variants'],
                'image_base_url' => $this->config['opencart']['public_base_url'],
                'image_local_dir' => $this->config['opencart']['image_dir'],
            ]);
        }
        
        $this->reporter = new CsvReporter(__DIR__ . '/output/reports');
        
        // Initialize Shopify client if in send mode (not needed for image processing)
        if ($this->sendMode && !$this->processImages) {
            if (!Config::hasShopifyCredentials()) {
                throw new \Exception("Shopify credentials required for --send mode. Please configure SHOPIFY_STORE_DOMAIN and SHOPIFY_ADMIN_ACCESS_TOKEN.");
            }
            
            $shopifyConfig = $this->config['shopify'];
            $this->shopify = new GraphQLClient(
                $shopifyConfig['store_domain'],
                $shopifyConfig['access_token'],
                $shopifyConfig['api_version'],
                $this->processImages,
                $this->imageHostOverride
            );
            
            // CRITICAL: Ensure metafield definition exists with filtering enabled
            echo "Setting up metafield definitions for filtering..." . PHP_EOL;
            $metafieldSetup = $this->shopify->ensureMetafieldDefinition(
                'custom',
                'opencart_product_id',
                'OpenCart Product ID',
                'single_line_text_field'
            );
            
            if (!$metafieldSetup) {
                echo "WARNING: Could not set up metafield definition for filtering. Metafield search may not work properly." . PHP_EOL;
            } else {
                echo "Metafield definition ready with filtering enabled." . PHP_EOL;
            }
        }
    }
    
    private function parseArguments(): void
    {
        $this->verbose = in_array('--verbose', $this->args) || in_array('-v', $this->args);
        $this->sendMode = in_array('--send', $this->args);
        $this->updateExisting = in_array('--update-existing', $this->args);
        $this->processImages = in_array('--process-images', $this->args);
        $this->useImageMapping = in_array('--use-image-mapping', $this->args);
        $this->useImageCsv = in_array('--use-image-csv', $this->args);
        $this->deleteAllProducts = in_array('--delete-all-products', $this->args);
        $this->disableTracking = in_array('--disable-tracking', $this->args);
        
        // Check for format and image host override flags
        foreach ($this->args as $arg) {
            if (strpos($arg, '--format=') === 0) {
                $this->format = substr($arg, 9);
            } elseif (strpos($arg, '--image-host=') === 0) {
                $this->imageHostOverride = substr($arg, 13);
            }
        }
        
        // Default to dry-run unless --send is specified
        $this->dryRun = !$this->sendMode;
        
        // Parse options
        foreach ($this->args as $arg) {
            if (strpos($arg, '--limit=') === 0) {
                $this->limit = (int)substr($arg, 8);
            } elseif (strpos($arg, '--product-id=') === 0) {
                $this->productId = (int)substr($arg, 13);
            } elseif (strpos($arg, '--db-name=') === 0) {
                // Allow override but already loaded from config
            }
        }
        
        // Show help
        if (in_array('--help', $this->args) || in_array('-h', $this->args)) {
            $this->showHelp();
            exit(0);
        }
    }
    
    private function showHelp(): void
    {
        echo <<<HELP
OpenCart to Shopify Migration Script

Usage:
  php migrate.php [options]

Options:
  --dry-run              Run in dry-run mode (default, no Shopify credentials needed)
  --send                 Send products to Shopify (requires credentials)
  --limit=N              Limit number of products to process
  --product-id=ID        Process specific product by OpenCart ID
  --update-existing      Update existing products in Shopify (requires --send)
  --delete-all-products  Delete all existing products from Shopify (requires --send)
  --disable-tracking     Disable inventory tracking (products always in stock, infinite qty)
  --process-images       Download and process images locally (saves time during migration)
  --use-image-mapping    Use CSV image mappings generated by process_images.php
  --use-image-csv        Use simple CSV image URL mappings (created by create_image_url_csv.php)
  --image-host=URL       Use custom image host URL instead of processing images
  --format=FORMAT        Output format: text or jsonl (default: text)
  --verbose, -v          Show detailed output
  --help, -h             Show this help message

Image Processing Options:
  --process-images       Downloads all product images and processes them (resize, optimize)
                         to 'output/images/' directory with same structure as OpenCart.
                         Use this to pre-process images, then host them elsewhere.
                         
  --use-image-mapping    Use the CSV mapping file generated by process_images.php.
                         This ensures that processed/uploaded images are used during migration
                         instead of original OpenCart images that might exceed Shopify limits.
                         
  --use-image-csv        Use simple CSV image URL replacement (recommended for most users).
                         First run: php create_image_url_csv.php --limit=100
                         Then edit the CSV file to specify replacement image URLs.
                         
  --image-host=URL       Instead of processing images during migration, use this URL
                         as the base for all image URLs. Images should be available at:
                         URL + /catalog/path/to/image.jpg
                         Example: --image-host=https://cdn.mystore.com/images

Performance Tips:
  1. Use --process-images first to download/process all images locally
  2. Host processed images on your CDN/hosting 
  3. Run migration with --image-host=YOUR_CDN_URL for much faster migration
  
  OR (Simple CSV Method - Recommended)
  
  1. Run: php create_image_url_csv.php --limit=100
  2. Edit output/image_urls.csv to specify replacement URLs
  3. Run: php migrate.php --send --use-image-csv --limit=100

Examples:
  php migrate.php --dry-run --limit=20 --verbose
  php migrate.php --process-images --limit=20
  php migrate.php --send --use-image-mapping --limit=50
  php migrate.php --send --use-image-csv --limit=50
  php migrate.php --send --image-host=https://cdn.mystore.com/images --limit=50
  php migrate.php --product-id=125 --dry-run
  php migrate.php --send --limit=10
  php migrate.php --send --update-existing

Configuration:
  Edit .env file or set environment variables:
  - OPENCART_DB_HOST, OPENCART_DB_NAME, OPENCART_DB_USER, OPENCART_DB_PASS
  - SHOPIFY_STORE_DOMAIN, SHOPIFY_ADMIN_ACCESS_TOKEN (for --send mode)

Output:
  Reports are saved to: ./output/reports/
  Logs are saved to: ./output/logs/

HELP;
    }
    
    public function run(): void
    {
        $startTime = microtime(true);
        
        $this->log("=== OpenCart to Shopify Migration ===");
        
        if ($this->processImages) {
            $this->log("Mode: IMAGE PROCESSING");
            $this->log("Output: output/images/");
        } elseif ($this->useImageCsv) {
            $this->log("Mode: " . ($this->dryRun ? "DRY RUN" : "SEND TO SHOPIFY"));
            $this->log("Using Image CSV: YES");
            
            // Show CSV image mapping stats
            if ($this->mapper instanceof CsvImageProductMapper) {
                $stats = $this->mapper->getImageMappingStats();
                $this->log("CSV Image Mappings: " . $stats['total_mappings']);
            }
        } elseif ($this->useImageMapping) {
            $this->log("Mode: " . ($this->dryRun ? "DRY RUN" : "SEND TO SHOPIFY"));
            $this->log("Using Image Mapping: YES");
            
            // Show image mapping stats
            if ($this->mapper instanceof ImageMappingProductMapper) {
                $stats = $this->mapper->getImageMappingStats();
                $this->log("Image Mappings Loaded: " . $stats['total_mappings']);
            }
        } elseif ($this->imageHostOverride) {
            $this->log("Mode: " . ($this->dryRun ? "DRY RUN" : "SEND TO SHOPIFY"));
            $this->log("Image Host: {$this->imageHostOverride}");
        } else {
            $this->log("Mode: " . ($this->dryRun ? "DRY RUN" : "SEND TO SHOPIFY"));
        }
        
        $this->log("Database: {$this->config['opencart']['dbname']}");
        
        if ($this->limit) {
            $this->log("Limit: {$this->limit} products");
        }
        
        if ($this->disableTracking) {
            $this->log("Inventory Tracking: DISABLED (infinite stock)");
        }
        
        if ($this->productId) {
            $this->log("Processing single product: {$this->productId}");
        }
        
        $this->log("");
        
        // Handle delete all products first if requested
        if ($this->deleteAllProducts && $this->sendMode) {
            $this->deleteAllShopifyProducts();
            return;
        }
        
        // Handle image processing mode
        if ($this->processImages) {
            $this->processImagesOnly();
            return;
        }
        
        // Get products to process
        $productIds = $this->getProductIds();
        
        $this->log("Found " . count($productIds) . " products to process");
        $this->log("");
        
        // Process products
        $stats = [
            'total' => count($productIds),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'warnings' => 0,
        ];
        
        $samplePayloads = [];
        
        foreach ($productIds as $idx => $ocProductId) {
            $current = $idx + 1;
            
            try {
                // Extract OpenCart product
                $ocProduct = $this->extractor->getCompleteProduct($ocProductId);
                
                // Map to Shopify format
                $mappedProduct = $this->mapper->mapProduct($ocProduct);
                
                // Get variant and option counts
                $variantCount = isset($mappedProduct['product']['variants']) ? count($mappedProduct['product']['variants']) : 0;
                $optionCount = isset($mappedProduct['product']['options']) ? count($mappedProduct['product']['options']) : 0;
                
                // Collect sample payloads for first few products
                if (count($samplePayloads) < 5) {
                    $samplePayloads[] = [
                        'opencart_product_id' => $ocProductId,
                        'shopify_product' => $mappedProduct['product'],
                    ];
                }
                
                // Count warnings
                if (!empty($mappedProduct['warnings'])) {
                    $stats['warnings'] += count($mappedProduct['warnings']);
                    
                    // Log warnings
                    foreach ($mappedProduct['warnings'] as $warning) {
                        $this->log("⚠ [Product {$ocProductId}] {$warning}", 'log', 'warning');
                    }
                }
                
                $shopifyId = null;
                $status = 'DRY_RUN';
                $error = null;
                
                // Send to Shopify if in send mode
                if ($this->sendMode) {
                    try {
                        $result = $this->sendToShopify($mappedProduct);
                        $shopifyId = $result['id'];
                        $status = $result['action'];
                        
                        // Check for image warnings from GraphQLClient
                        if (!empty($result['image_warnings'])) {
                            $stats['warnings'] += count($result['image_warnings']);
                            foreach ($result['image_warnings'] as $warning) {
                                $this->log("⚠ [Product {$ocProductId}] {$warning}", 'log', 'warning');
                            }
                        }
                        
                        $this->log("✓ [{$current}/{$stats['total']}] {$mappedProduct['product']['title']}", 'log', 'success');
                        $stats['success']++;
                    } catch (\Exception $e) {
                        $error = $e->getMessage();
                        $this->log("✗ [{$current}/{$stats['total']}] ERROR: {$error}", 'log', 'error');
                        $stats['failed']++;
                    }
                } else {
                    // Log every 50th product or if has warnings
                    if ($current % 50 === 0 || !empty($mappedProduct['warnings'])) {
                        $this->log("✓ [{$current}/{$stats['total']}] {$mappedProduct['product']['title']} ({$variantCount} variants, {$optionCount} options)", 'log', 'success');
                    }
                    $stats['success']++;
                }
                
                // Send progress update after each product
                $this->logProgress($current, $stats['total'], $stats);
                
                // Add to reporter with image warnings
                $imageWarnings = isset($result['image_warnings']) ? $result['image_warnings'] : [];
                $this->reporter->addProduct($mappedProduct, $status, $shopifyId, $error, $imageWarnings);
                
            } catch (\Exception $e) {
                $this->log("  ✗ ERROR: " . $e->getMessage(), 'log', 'error');
                $stats['failed']++;
            }
            
            if ($this->verbose) {
                $this->log("");
            }
        }
        
        // Write reports
        $this->log("");
        $this->log("=== Generating Reports ===");
        
        $reportFiles = $this->reporter->writeReports();
        foreach ($reportFiles as $file) {
            $this->log("✓ " . basename($file));
        }
        
        $payloadFile = $this->reporter->writeSamplePayloads($samplePayloads);
        $this->log("✓ " . basename($payloadFile));
        
        $duration = round(microtime(true) - $startTime, 2);
        
        $summary = [
            'mode' => $this->dryRun ? 'DRY_RUN' : 'SEND',
            'total_products' => $stats['total'],
            'successful' => $stats['success'],
            'failed' => $stats['failed'],
            'skipped' => $stats['skipped'],
            'warnings' => $stats['warnings'],
            'duration_seconds' => $duration,
        ];
        
        if ($this->shopify) {
            $summary['shopify_api_calls'] = $this->shopify->getRequestCount();
        }
        
        $summaryFile = $this->reporter->writeSummary($summary);
        $this->log("✓ " . basename($summaryFile));
        
        // Send completion event for web UI
        if ($this->format === 'jsonl') {
            echo json_encode([
                'type' => 'complete',
                'summary' => $summary,
            ]) . PHP_EOL;
            flush(); // Force immediate output
        }
        
        $this->log("");
        $this->log("=== Summary ===");
        $this->log("Total: {$stats['total']}");
        $this->log("Success: {$stats['success']}");
        $this->log("Failed: {$stats['failed']}");
        $this->log("Warnings: {$stats['warnings']}");
        $this->log("Duration: {$duration}s");
        
        if ($this->dryRun) {
            $this->log("");
            $this->log("✓ Dry run complete! Review the reports in ./output/reports/");
            $this->log("  To send to Shopify, run with --send flag after configuring credentials.");
        } else {
            $this->log("");
            $this->log("✓ Migration complete!");
        }
    }
    
    private function getProductIds(): array
    {
        if ($this->productId !== null) {
            return [$this->productId];
        }
        
        if ($this->limit !== null) {
            // Get first N products (no special sampling for small limits)
            $products = $this->extractor->getProducts($this->limit);
            return array_column($products, 'product_id');
        }
        
        // Get all products
        $products = $this->extractor->getProducts();
        return array_column($products, 'product_id');
    }
    
    /**
     * Delete all existing products from Shopify store
     */
    private function deleteAllShopifyProducts(): void
    {
        $this->log("Fetching all products from Shopify...");
        
        try {
            // Get all products from Shopify
            $products = $this->shopify->getAllProducts();
            $totalCount = count($products);
            
            if ($totalCount === 0) {
                $this->log("✓ No products to delete - Shopify store is empty");
                return;
            }
            
            $this->log("Found {$totalCount} products to delete");
            $this->log("");
            
            $deleted = 0;
            $failed = 0;
            
            foreach ($products as $idx => $product) {
                $current = $idx + 1;
                
                try {
                    // Extract product ID from GID
                    $productId = $product['id'];
                    $productTitle = $product['title'] ?? 'Unknown';
                    
                    // Delete product
                    $this->shopify->deleteProduct($productId);
                    
                    $this->log("[{$current}/{$totalCount}] ✓ Deleted: {$productTitle}");
                    $deleted++;
                    
                } catch (\Exception $e) {
                    $this->log("[{$current}/{$totalCount}] ✗ Failed to delete: {$product['title']} - {$e->getMessage()}", 'log', 'error');
                    $failed++;
                }
            }
            
            $this->log("");
            $this->log("=== Deletion Complete ===");
            $this->log("Total Deleted: {$deleted}");
            if ($failed > 0) {
                $this->log("Failed: {$failed}");
            }
            $this->log("Shopify store ready for fresh migration");
            
        } catch (\Exception $e) {
            $this->log("ERROR: Failed to delete products - {$e->getMessage()}", 'log', 'error');
            throw $e;
        }
    }
    
    private function sendToShopify(array $mappedProduct): array
    {
        $product = $mappedProduct['product'];
        $ocProductId = $mappedProduct['opencart_product_id'];
        
        // Apply disable tracking if requested
        if ($this->disableTracking && isset($product['variants'])) {
            foreach ($product['variants'] as &$variant) {
                $variant['tracked'] = false;
            }
            unset($variant);
        }
        
        // Check if product already exists
        $existingProduct = $this->findExistingProduct($ocProductId, $product['handle']);
        
        if ($existingProduct) {
            if ($this->updateExisting) {
                // Update existing product
                $result = $this->shopify->updateProduct($existingProduct['id'], $product);
                $result['action'] = 'UPDATED';
                return $result;
            } else {
                // Skip existing product
                return [
                    'id' => $existingProduct['id'],
                    'title' => $existingProduct['title'],
                    'action' => 'SKIPPED',
                ];
            }
        }
        
        // Create new product
        $result = $this->shopify->createProduct($product);
        $result['action'] = 'CREATED';
        return $result;
    }
    
    private function findExistingProduct(int $ocProductId, string $handle): ?array
    {
        // Debug logging
        if ($this->format === 'jsonl') {
            echo json_encode([
                'type' => 'log', 
                'level' => 'info', 
                'message' => "Checking if product exists: OpenCart ID {$ocProductId}, handle '{$handle}'",
                'timestamp' => date('Y-m-d H:i:s')
            ]) . PHP_EOL;
            flush();
        }
        
        // Try to find by OpenCart ID metafield
        $product = $this->shopify->findProductByMetafield('custom', 'opencart_product_id', (string)$ocProductId);
        
        if ($product) {
            if ($this->format === 'jsonl') {
                echo json_encode([
                    'type' => 'log', 
                    'level' => 'warning', 
                    'message' => "Found existing product by metafield: {$product['title']} (ID: {$product['id']})",
                    'timestamp' => date('Y-m-d H:i:s')
                ]) . PHP_EOL;
                flush();
            }
            return $product;
        }
        
        // Try to find by handle
        $product = $this->shopify->findProductByHandle($handle);
        
        if ($product) {
            if ($this->format === 'jsonl') {
                echo json_encode([
                    'type' => 'log', 
                    'level' => 'warning', 
                    'message' => "Found existing product by handle: {$product['title']} (ID: {$product['id']})",
                    'timestamp' => date('Y-m-d H:i:s')
                ]) . PHP_EOL;
                flush();
            }
            return $product;
        }
        
        // No existing product found
        if ($this->format === 'jsonl') {
            echo json_encode([
                'type' => 'log', 
                'level' => 'info', 
                'message' => "No existing product found for OpenCart ID {$ocProductId}",
                'timestamp' => date('Y-m-d H:i:s')
            ]) . PHP_EOL;
            flush();
        }
        
        return null;
    }
    
    private function log(string $message, string $type = 'log', string $level = 'info'): void
    {
        if ($this->format === 'jsonl') {
            // Output as JSON Line
            $data = [
                'type' => $type,
                'level' => $level,
                'message' => $message,
                'timestamp' => date('Y-m-d H:i:s'),
            ];
            echo json_encode($data) . PHP_EOL;
            flush(); // Force immediate output
        } else {
            // Plain text output
            echo $message . PHP_EOL;
        }
    }
    
    private function logProgress(int $current, int $total, array $stats): void
    {
        if ($this->format === 'jsonl') {
            $percentage = $total > 0 ? round(($current / $total) * 100, 1) : 0;
            
            $data = [
                'type' => 'progress',
                'current' => $current,
                'total' => $total,
                'percentage' => $percentage,
                'stats' => $stats,
            ];
            echo json_encode($data) . PHP_EOL;
            flush(); // Force immediate output
        }
    }
    
    /**
     * Process images only mode - downloads and processes all product images locally
     */
    private function processImagesOnly(): void
    {
        $startTime = microtime(true);
        
        // Get products to process
        $productIds = $this->getProductIds();
        
        $this->log("Found " . count($productIds) . " products to process for image extraction");
        $this->log("");
        
        // Ensure output directory exists
        $outputDir = __DIR__ . '/output/images';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
            $this->log("Created output directory: {$outputDir}");
        }
        
        $stats = [
            'total_products' => count($productIds),
            'processed_products' => 0,
            'total_images' => 0,
            'processed_images' => 0,
            'failed_images' => 0,
        ];
        
        // Create ImageProcessor for local processing
        $imageProcessor = new \App\Utils\ImageProcessor(true, null, $outputDir);
        
        foreach ($productIds as $index => $productId) {
            try {
                // Extract product data
                $openCartProduct = $this->extractor->getProduct($productId);
                if (!$openCartProduct) {
                    continue;
                }
                
                // Map to Shopify format to get image URLs
                $mappedProduct = $this->mapper->mapProduct($openCartProduct);
                if (empty($mappedProduct['images'])) {
                    $stats['processed_products']++;
                    continue;
                }
                
                $this->log("[" . ($index + 1) . "/" . count($productIds) . "] Processing images for: {$mappedProduct['title']}");
                
                foreach ($mappedProduct['images'] as $imageIndex => $image) {
                    $stats['total_images']++;
                    $imageUrl = $image['src'];
                    
                    echo "  Processing image " . ($imageIndex + 1) . "/" . count($mappedProduct['images']) . ": " . basename($imageUrl) . PHP_EOL;
                    
                    $result = $imageProcessor->processImageFromUrl($imageUrl);
                    if ($result) {
                        $stats['processed_images']++;
                        if ($result['processed']) {
                            echo "    ✅ Processed and saved: " . $result['final_size'] . " (" . $result['megapixels'] . ")" . PHP_EOL;
                        } else {
                            echo "    ✅ Saved as-is: " . $result['final_size'] . " (" . $result['megapixels'] . ")" . PHP_EOL;
                        }
                    } else {
                        $stats['failed_images']++;
                        echo "    ❌ Failed to process image" . PHP_EOL;
                    }
                }
                
                $stats['processed_products']++;
                
            } catch (\Exception $e) {
                $this->log("❌ Error processing product {$productId}: " . $e->getMessage());
                continue;
            }
        }
        
        $duration = microtime(true) - $startTime;
        
        $this->log("");
        $this->log("=== Image Processing Complete ===");
        $this->log("Products processed: {$stats['processed_products']}/{$stats['total_products']}");
        $this->log("Images processed: {$stats['processed_images']}/{$stats['total_images']}");
        $this->log("Images failed: {$stats['failed_images']}");
        $this->log("Duration: " . number_format($duration, 2) . "s");
        $this->log("Output directory: {$outputDir}");
        $this->log("");
        $this->log("Next steps:");
        $this->log("1. Host the processed images on your server/CDN");
        $this->log("2. Run migration with: --send --image-host=YOUR_IMAGE_URL");
        $this->log("");
    }
}

// Run the script
try {
    $script = new MigrationScript($argv);
    $script->run();
    exit(0);
} catch (\Exception $e) {
    echo "✗ FATAL ERROR: " . $e->getMessage() . PHP_EOL;
    echo "\nStack trace:\n" . $e->getTraceAsString() . PHP_EOL;
    exit(1);
}
