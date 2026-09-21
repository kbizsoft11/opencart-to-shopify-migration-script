<?php
/**
 * OpenCart to Shopify - Image Processing Example
 * 
 * This example demonstrates how to use the OversizedImageProcessor class directly
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/OpenCart/Database.php';
require_once __DIR__ . '/../src/OpenCart/ProductExtractor.php';
require_once __DIR__ . '/../src/Utils/OversizedImageProcessor.php';

use OpenCart\Database;
use OpenCart\ProductExtractor;
use App\Utils\OversizedImageProcessor;

try {
    echo "OpenCart to Shopify - Image Processing Example" . PHP_EOL;
    echo "===============================================" . PHP_EOL . PHP_EOL;
    
    // Load configuration
    $config = Config::load();
    
    // Initialize components
    $db = new Database($config['opencart']);
    $extractor = new ProductExtractor(
        $db,
        $config['opencart']['language_id'],
        $config['opencart']['store_id']
    );
    
    // Configure image processor
    $processorConfig = [
        'output_dir' => __DIR__ . '/../output/examples',
        'csv_filename' => 'example_image_mapping.csv',
        'log_filename' => 'example_processing.log',
        'opencart_base_url' => $config['opencart']['public_base_url'],
        'verbose' => true,
    ];
    
    $processor = new OversizedImageProcessor($db, $extractor, $processorConfig);
    
    echo "Configuration:" . PHP_EOL;
    echo "- Database: " . $config['opencart']['dbname'] . PHP_EOL;
    echo "- Base URL: " . $config['opencart']['public_base_url'] . PHP_EOL;
    echo "- Output: " . $processorConfig['output_dir'] . PHP_EOL;
    echo "" . PHP_EOL;
    
    // Example 1: Process first 10 products
    echo "Example 1: Processing first 10 products..." . PHP_EOL;
    echo "==========================================" . PHP_EOL;
    
    $stats = $processor->processOversizedImages(10, 0);
    
    echo "" . PHP_EOL;
    echo "Processing Results:" . PHP_EOL;
    echo "- Products processed: " . $stats['processed_products'] . "/" . $stats['total_products'] . PHP_EOL;
    echo "- Total images: " . $stats['total_images'] . PHP_EOL;
    echo "- Oversized images: " . $stats['oversized_images'] . PHP_EOL;
    echo "- Processed images: " . $stats['processed_images'] . PHP_EOL;
    echo "- Uploaded images: " . $stats['uploaded_images'] . PHP_EOL;
    echo "- Failed images: " . $stats['failed_images'] . PHP_EOL;
    echo "- Duration: " . $stats['duration_seconds'] . "s" . PHP_EOL;
    echo "" . PHP_EOL;
    
    // Example 2: Load image mappings for migration
    echo "Example 2: Loading image mappings for migration..." . PHP_EOL;
    echo "=================================================" . PHP_EOL;
    
    $mappings = $processor->loadImageMappingForMigration();
    
    echo "Mapping Statistics:" . PHP_EOL;
    echo "- Total URL mappings: " . count($mappings['by_url']) . PHP_EOL;
    echo "- Products with images: " . count($mappings['by_product']) . PHP_EOL;
    echo "- Variants with images: " . count($mappings['by_variant']) . PHP_EOL;
    echo "" . PHP_EOL;
    
    // Example 3: Demonstrate URL mapping
    if (!empty($mappings['by_url'])) {
        echo "Example 3: URL Mapping Demonstration..." . PHP_EOL;
        echo "======================================" . PHP_EOL;
        
        $originalUrl = array_key_first($mappings['by_url']);
        $mappedUrl = $processor->getMappedImageUrl($originalUrl, $mappings);
        
        echo "Original URL: " . $originalUrl . PHP_EOL;
        echo "Mapped URL:   " . $mappedUrl . PHP_EOL;
        echo "Changed:      " . ($originalUrl !== $mappedUrl ? 'Yes' : 'No') . PHP_EOL;
        echo "" . PHP_EOL;
    }
    
    echo "✅ Example completed successfully!" . PHP_EOL;
    echo "" . PHP_EOL;
    echo "Output files:" . PHP_EOL;
    echo "- CSV: " . $processorConfig['output_dir'] . "/" . $processorConfig['csv_filename'] . PHP_EOL;
    echo "- Log: " . $processorConfig['output_dir'] . "/" . $processorConfig['log_filename'] . PHP_EOL;
    
} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    echo "" . PHP_EOL;
    echo "Stack trace:" . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    exit(1);
}