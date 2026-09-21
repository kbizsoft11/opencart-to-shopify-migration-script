<?php
/**
 * OpenCart to Shopify - Oversized Image Processing Script
 * 
 * Usage:
 *   php process_images.php --limit=500
 *   php process_images.php --product-id=125
 *   php process_images.php --resume
 *   php process_images.php --help
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/OpenCart/Database.php';
require_once __DIR__ . '/src/OpenCart/ProductExtractor.php';
require_once __DIR__ . '/src/Utils/OversizedImageProcessor.php';

use OpenCart\Database;
use OpenCart\ProductExtractor;
use App\Utils\OversizedImageProcessor;

class ImageProcessingScript
{
    private array $config;
    private array $args;
    private bool $verbose = false;
    private ?int $limit = null;
    private ?int $productId = null;
    private ?string $outputDir = null;
    private bool $resume = false;
    private string $format = 'text'; // text or jsonl
    private string $statusFilter = 'active'; // 'all', 'active', 'inactive'
    
    private Database $db;
    private ProductExtractor $extractor;
    private OversizedImageProcessor $processor;
    
    public function __construct(array $args)
    {
        $this->args = $args;
        $this->config = Config::load();
        
        $this->parseArguments();
        
        // Initialize components
        $this->db = new Database($this->config['opencart']);
        $this->extractor = new ProductExtractor(
            $this->db,
            $this->config['opencart']['language_id'],
            $this->config['opencart']['store_id']
        );
        
        $processorConfig = [
            'output_dir' => $this->outputDir ?: __DIR__ . '/output/image_processing',
            'csv_filename' => 'image_mapping.csv',
            'log_filename' => 'image_processing.log',
            'image_host_url' => getenv('IMAGE_HOST_URL') ?: '',
            'opencart_base_url' => $this->config['opencart']['public_base_url'],
            'verbose' => $this->verbose,
            'format' => $this->format,
            'status_filter' => $this->statusFilter,  // Pass status filter to processor
        ];
        
        $this->processor = new OversizedImageProcessor($this->db, $this->extractor, $processorConfig);
    }
    
    private function parseArguments(): void
    {
        $this->verbose = in_array('--verbose', $this->args) || in_array('-v', $this->args);
        $this->resume = in_array('--resume', $this->args);
        
        foreach ($this->args as $arg) {
            if (strpos($arg, '--limit=') === 0) {
                $this->limit = (int)substr($arg, 8);
            } elseif (strpos($arg, '--product-id=') === 0) {
                $this->productId = (int)substr($arg, 13);
            } elseif (strpos($arg, '--output-dir=') === 0) {
                $this->outputDir = substr($arg, 13);
            } elseif (strpos($arg, '--format=') === 0) {
                $this->format = substr($arg, 9);
            } elseif (strpos($arg, '--status-filter=') === 0) {
                $this->statusFilter = substr($arg, 15);
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
OpenCart to Shopify - Oversized Image Processing Script

This script identifies product images that exceed Shopify's limits (25MP or 20MB),
processes them to fit within constraints, uploads them to hosting service,
and generates a CSV mapping file for use during migration.

SHOPIFY IMAGE LIMITS (2024):
  - Maximum file size: 20 MB
  - Maximum resolution: 25 megapixels (approx. 5000x5000 pixels)

Usage:
  php process_images.php [options]

Options:
  --limit=N              Process first N products (default: all products)
  --product-id=ID        Process specific product by OpenCart ID
  --resume               Resume processing (skip already processed images)
  --output-dir=PATH      Custom output directory (default: output/image_processing)
  --format=FORMAT        Output format: text or jsonl (default: text)
  --verbose, -v          Show detailed processing information
  --help, -h             Show this help message

Environment Variables:
  IMAGE_HOST_URL         Base URL for uploaded images (optional)
  IMGBB_API_KEY         API key for imgbb.com hosting service
  OPENCART_PUBLIC_BASE_URL  Base URL for OpenCart images

Output Files:
  - image_mapping.csv    CSV file with image URL mappings for migration
  - image_processing.log Detailed processing log

Examples:
  # Process first 500 products
  php process_images.php --limit=500 --verbose
  
  # Process specific product
  php process_images.php --product-id=125 --verbose
  
  # Resume processing (skip already processed images)
  php process_images.php --resume --verbose
  
  # Process all products
  php process_images.php --verbose

Next Steps:
  1. Review the generated image_mapping.csv file
  2. Ensure uploaded images are accessible via the configured IMAGE_HOST_URL
  3. Run the main migration script which will use the CSV mappings:
     php migrate.php --send --use-image-mapping

HELP;
    }
    
    public function run(): void
    {
        $startTime = microtime(true);
        
        $this->log("=== OpenCart to Shopify - Oversized Image Processing ===");
        $this->log("Database: {$this->config['opencart']['dbname']}");
        $this->log("OpenCart Base URL: " . ($this->config['opencart']['public_base_url'] ?: 'NOT CONFIGURED'));
        $this->log("Image Host URL: " . (getenv('IMAGE_HOST_URL') ?: 'NOT CONFIGURED'));
        $this->log("Shopify Limits: 25MP, 20MB");
        
        if ($this->limit) {
            $this->log("Limit: {$this->limit} products");
        }
        
        if ($this->productId) {
            $this->log("Processing single product: {$this->productId}");
        }
        
        if ($this->resume) {
            $this->log("Resume mode: Will skip already processed images");
        }
        
        $this->log("");
        
        // Validate configuration
        if (empty($this->config['opencart']['public_base_url'])) {
            $this->log("❌ ERROR: OPENCART_PUBLIC_BASE_URL is not configured.", 'error');
            $this->log("   Please set this in your .env file or environment variables.", 'error');
            $this->log("   Example: OPENCART_PUBLIC_BASE_URL=https://yourstore.com/image", 'error');
            exit(1);
        }
        
        try {
            // Determine processing parameters
            $limit = null;
            $offset = 0;
            
            if ($this->productId !== null) {
                // Process single product
                $limit = 1;
                $stats = $this->processSingleProduct($this->productId);
            } else {
                // Process multiple products
                $limit = $this->limit;
                $stats = $this->processor->processOversizedImages($limit, $offset);
            }
            
            $duration = microtime(true) - $startTime;
            
            $this->log("");
            $this->log("=== Processing Complete ===");
            $this->log("Duration: " . number_format($duration, 2) . "s");
            $this->log("Products processed: {$stats['processed_products']}/{$stats['total_products']}");
            $this->log("Total images found: {$stats['total_images']}");
            $this->log("Oversized images: {$stats['oversized_images']}");
            $this->log("Images processed: {$stats['processed_images']}");
            $this->log("Images uploaded: {$stats['uploaded_images']}");
            $this->log("Images skipped: {$stats['skipped_images']}");
            $this->log("Images failed: {$stats['failed_images']}");
            
            if (!empty($stats['errors'])) {
                $this->log("");
                $this->log("❌ Errors encountered:");
                foreach ($stats['errors'] as $error) {
                    $this->log("   - {$error}", 'error');
                }
            }
            
            // Send completion event for web UI
            if ($this->format === 'jsonl') {
                $this->logComplete($stats);
            }
            
            $this->log("");
            $this->log("✅ Image processing complete!");
            $this->log("");
            $this->log("Output files:");
            $this->log("  📄 CSV mapping: output/image_processing/image_mapping.csv");
            $this->log("  📋 Processing log: output/image_processing/image_processing.log");
            $this->log("");
            $this->log("Next steps:");
            $this->log("  1. Review the generated CSV file");
            $this->log("  2. Verify uploaded images are accessible");
            $this->log("  3. Run migration: php migrate.php --send --use-image-mapping");
            
        } catch (\Exception $e) {
            $this->log("❌ FATAL ERROR: " . $e->getMessage(), 'error');
            $this->log("");
            $this->log("Stack trace:");
            $this->log($e->getTraceAsString());
            exit(1);
        }
    }
    
    /**
     * Process a single product by ID
     */
    private function processSingleProduct(int $productId): array
    {
        $this->log("Processing single product ID: {$productId}");
        
        // Get the specific product
        $product = $this->extractor->getProduct($productId);
        if (!$product) {
            throw new \Exception("Product with ID {$productId} not found");
        }
        
        $this->log("Product found: {$product['name']}");
        $this->log("");
        
        // Use the processor but limit to just this product
        return $this->processor->processOversizedImages(1, 0);
    }
    
    /**
     * Log message with format support
     */
    private function log(string $message, string $level = 'info'): void
    {
        if ($this->format === 'jsonl') {
            // Output as JSON Line
            $data = [
                'type' => 'log',
                'level' => $level,
                'message' => $message,
                'timestamp' => date('Y-m-d H:i:s'),
            ];
            echo json_encode($data) . PHP_EOL;
            flush();
        } else {
            // Plain text output
            echo $message . PHP_EOL;
        }
    }
    
    /**
     * Log completion event for web UI
     */
    private function logComplete(array $stats): void
    {
        $data = [
            'type' => 'complete',
            'stats' => $stats,
            'message' => 'Image processing completed successfully',
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        echo json_encode($data) . PHP_EOL;
        flush();
    }
}

// Run the script
try {
    $script = new ImageProcessingScript($argv);
    $script->run();
    exit(0);
} catch (\Exception $e) {
    echo "✗ FATAL ERROR: " . $e->getMessage() . PHP_EOL;
    echo "\nStack trace:\n" . $e->getTraceAsString() . PHP_EOL;
    exit(1);
}