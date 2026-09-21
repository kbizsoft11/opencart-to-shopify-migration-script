<?php
/**
 * Simple Image URL CSV Creator
 * 
 * Creates a CSV mapping original OpenCart image URLs to replacement URLs
 * This allows you to easily replace image URLs during migration
 * 
 * Usage:
 *   php create_image_url_csv.php --limit=100
 *   php create_image_url_csv.php --all
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/OpenCart/Database.php';
require_once __DIR__ . '/src/OpenCart/ProductExtractor.php';

use OpenCart\Database;
use OpenCart\ProductExtractor;

class ImageUrlCsvCreator
{
    private Database $db;
    private ProductExtractor $extractor;
    private array $config;
    private ?int $limit = null;
    private string $outputPath;
    
    public function __construct(array $args)
    {
        $this->config = Config::load();
        $this->parseArguments($args);
        
        // Initialize components
        $this->db = new Database($this->config['opencart']);
        $this->extractor = new ProductExtractor(
            $this->db,
            $this->config['opencart']['language_id'],
            $this->config['opencart']['store_id']
        );
        
        $this->outputPath = __DIR__ . '/output/image_urls.csv';
        
        // Ensure output directory exists
        $outputDir = dirname($this->outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
    }
    
    private function parseArguments(array $args): void
    {
        foreach ($args as $arg) {
            if (strpos($arg, '--limit=') === 0) {
                $this->limit = (int)substr($arg, 8);
            } elseif ($arg === '--all') {
                $this->limit = null;
            }
        }
        
        if (in_array('--help', $args) || in_array('-h', $args)) {
            $this->showHelp();
            exit(0);
        }
    }
    
    private function showHelp(): void
    {
        echo <<<HELP
Simple Image URL CSV Creator

Creates a CSV file mapping original OpenCart image URLs to replacement URLs.
You can edit this CSV to specify custom image URLs for migration.

Usage:
  php create_image_url_csv.php [options]

Options:
  --limit=N     Process only N products (for testing)
  --all         Process all products (default)
  --help, -h    Show this help

Output:
  Creates: output/image_urls.csv

CSV Format:
  Product_ID, Variant_ID, Original_URL, Replacement_URL, Image_Type, Status

Examples:
  # Create CSV for first 50 products
  php create_image_url_csv.php --limit=50
  
  # Create CSV for all products  
  php create_image_url_csv.php --all

HELP;
    }
    
    public function run(): void
    {
        echo "=== Image URL CSV Creator ===" . PHP_EOL;
        echo "Database: {$this->config['opencart']['dbname']}" . PHP_EOL;
        echo "Base URL: " . ($this->config['opencart']['public_base_url'] ?: 'NOT CONFIGURED') . PHP_EOL;
        echo "Output: {$this->outputPath}" . PHP_EOL;
        
        if ($this->limit) {
            echo "Limit: {$this->limit} products" . PHP_EOL;
        }
        echo "" . PHP_EOL;
        
        // Get products
        $products = $this->getProducts();
        echo "Found " . count($products) . " products to process" . PHP_EOL;
        
        // Create CSV
        $this->createCsv($products);
        
        echo "" . PHP_EOL;
        echo "✅ CSV created successfully!" . PHP_EOL;
        echo "📄 File: {$this->outputPath}" . PHP_EOL;
        echo "" . PHP_EOL;
        echo "Next steps:" . PHP_EOL;
        echo "1. Open the CSV file in Excel or text editor" . PHP_EOL;
        echo "2. Edit the 'Replacement_URL' column with your desired image URLs" . PHP_EOL;
        echo "3. Use --use-image-csv flag in migration" . PHP_EOL;
    }
    
    private function getProducts(): array
    {
        $sql = "SELECT p.product_id, pd.name 
                FROM " . $this->db->table('product') . " p
                LEFT JOIN " . $this->db->table('product_description') . " pd ON p.product_id = pd.product_id
                WHERE p.status = 1 AND pd.language_id = 1 
                ORDER BY p.product_id ASC";
                
        if ($this->limit) {
            $sql .= " LIMIT " . $this->limit;
        }
        
        return $this->db->fetchAll($sql);
    }
    
    private function createCsv(array $products): void
    {
        $fp = fopen($this->outputPath, 'w');
        
        // Write CSV header
        fputcsv($fp, [
            'Product_ID',
            'Variant_ID', 
            'Original_URL',
            'Replacement_URL',
            'Image_Type',
            'Status'
        ]);
        
        $totalImages = 0;
        $baseUrl = $this->config['opencart']['public_base_url'];
        
        foreach ($products as $index => $product) {
            echo "Processing product " . ($index + 1) . "/" . count($products) . ": {$product['name']}" . PHP_EOL;
            
            try {
                // Get complete product data
                $completeProductData = $this->extractor->getCompleteProduct($product['product_id']);
                if (!$completeProductData || !isset($completeProductData['product'])) {
                    continue;
                }
                
                $completeProduct = $completeProductData['product'];
                $productImages = $completeProductData['images'] ?? [];
                $productOptionValues = $completeProductData['option_values'] ?? [];
                
                // Main product image
                if (!empty($completeProduct['image'])) {
                    $originalUrl = $this->buildImageUrl($completeProduct['image'], $baseUrl);
                    if ($originalUrl) {
                        fputcsv($fp, [
                            $completeProduct['product_id'],
                            '', // No variant ID for main image
                            $originalUrl,
                            $originalUrl, // Default replacement is same as original
                            'main',
                            'pending'
                        ]);
                        $totalImages++;
                    }
                }
                
                // Additional product images
                if (!empty($productImages)) {
                    foreach ($productImages as $image) {
                        if (!empty($image['image'])) {
                            $originalUrl = $this->buildImageUrl($image['image'], $baseUrl);
                            if ($originalUrl) {
                                fputcsv($fp, [
                                    $completeProduct['product_id'],
                                    '',
                                    $originalUrl,
                                    $originalUrl,
                                    'additional',
                                    'pending'
                                ]);
                                $totalImages++;
                            }
                        }
                    }
                }
                
                // Variant/option images
                if (!empty($productOptionValues)) {
                    foreach ($productOptionValues as $optionValue) {
                        if (!empty($optionValue['image'])) {
                            $originalUrl = $this->buildImageUrl($optionValue['image'], $baseUrl);
                            if ($originalUrl) {
                                fputcsv($fp, [
                                    $completeProduct['product_id'],
                                    $optionValue['product_option_value_id'],
                                    $originalUrl,
                                    $originalUrl,
                                    'variant',
                                    'pending'
                                ]);
                                $totalImages++;
                            }
                        }
                    }
                }
                
            } catch (\Exception $e) {
                echo "  Error processing product: " . $e->getMessage() . PHP_EOL;
                continue;
            }
        }
        
        fclose($fp);
        
        echo "" . PHP_EOL;
        echo "CSV Statistics:" . PHP_EOL;
        echo "- Products processed: " . count($products) . PHP_EOL;
        echo "- Total image URLs: {$totalImages}" . PHP_EOL;
    }
    
    private function buildImageUrl(string $imagePath, ?string $baseUrl): ?string
    {
        if (empty($imagePath)) {
            return null;
        }
        
        if (empty($baseUrl)) {
            return $imagePath;
        }
        
        return rtrim($baseUrl, '/') . '/' . ltrim($imagePath, '/');
    }
}

// Run the script
try {
    $creator = new ImageUrlCsvCreator($argv);
    $creator->run();
    exit(0);
} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}