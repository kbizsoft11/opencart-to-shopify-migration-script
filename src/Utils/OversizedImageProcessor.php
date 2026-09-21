<?php

namespace App\Utils;

use OpenCart\Database;
use OpenCart\ProductExtractor;

/**
 * Dedicated Oversized Image Processor for OpenCart to Shopify Migration
 * 
 * Phase 1: Identifies, processes, uploads oversized images and generates CSV mapping
 * Phase 2: Migration integration using the generated CSV
 */
class OversizedImageProcessor
{
    // Updated Shopify limits based on 2024 documentation
    private const MAX_MEGAPIXELS = 24.9; // Stay under 25MP limit
    private const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20MB
    private const JPEG_QUALITY = 85;
    private const PNG_COMPRESSION = 6;
    
    private Database $db;
    private ProductExtractor $extractor;
    private string $csvPath;
    private string $logPath;
    private bool $verbose;
    private array $config;
    private ?string $imgbbApiKey = null;
    
    public function __construct(Database $db, ProductExtractor $extractor, array $config = [])
    {
        $this->db = $db;
        $this->extractor = $extractor;
        $this->config = array_merge([
            'output_dir' => __DIR__ . '/../../output',
            'csv_filename' => 'image_mapping.csv',
            'log_filename' => 'image_processing.log',
            'image_host_url' => '', // Base URL for hosted images
            'verbose' => false,
            'format' => 'text', // text or jsonl
            'status_filter' => 'active', // 'all', 'active', 'inactive'
        ], $config);
        
        $this->csvPath = $this->config['output_dir'] . '/' . $this->config['csv_filename'];
        $this->logPath = $this->config['output_dir'] . '/' . $this->config['log_filename'];
        $this->verbose = $this->config['verbose'];
        
        // Load ImgBB API key from environment
        $this->loadEnvironmentVariables();
        
        // Ensure output directory exists
        if (!is_dir($this->config['output_dir'])) {
            mkdir($this->config['output_dir'], 0755, true);
        }
    }
    
    /**
     * Load environment variables including IMGBB_API_KEY
     */
    private function loadEnvironmentVariables(): void
    {
        // Load from .env file if it exists
        $envFile = '.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
                    continue;
                }
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if ($key === 'IMGBB_API_KEY') {
                    $this->imgbbApiKey = $value;
                    break;
                }
            }
        }
        
        // Override with $_ENV if available
        if (isset($_ENV['IMGBB_API_KEY'])) {
            $this->imgbbApiKey = $_ENV['IMGBB_API_KEY'];
        }
    }
    
    /**
     * Phase 1: Process oversized images and generate CSV mapping
     * 
     * @param int|null $limit Number of products to process (null for all)
     * @param int|null $offset Starting offset for products
     * @return array Processing statistics
     */
    public function processOversizedImages(?int $limit = null, int $offset = 0): array
    {
        $this->log("=== Starting Oversized Image Processing ===");
        $this->log("Max Megapixels: " . self::MAX_MEGAPIXELS . "MP");
        $this->log("Max File Size: " . (self::MAX_FILE_SIZE / 1024 / 1024) . "MB");
        $this->log("CSV Output: " . $this->csvPath);
        
        // Log status filter being used
        $statusFilter = $this->config['status_filter'] ?? 'active';
        if ($statusFilter === 'all') {
            $this->log("Product Filter: All products (active and inactive)");
        } elseif ($statusFilter === 'active') {
            $this->log("Product Filter: Active products only (status=1)");
        } elseif ($statusFilter === 'inactive') {
            $this->log("Product Filter: Inactive products only (status=0)");
        }
        
        $this->log("");
        
        $startTime = microtime(true);
        
        // Load existing CSV data for resume capability
        $existingData = $this->loadExistingCsvData();
        $this->log("Loaded " . count($existingData) . " existing entries from CSV");
        
        // Get products to process
        $products = $this->getProductsToProcess($limit, $offset);
        $this->log("Found " . count($products) . " products to process");
        $this->log("");
        
        $stats = [
            'total_products' => count($products),
            'processed_products' => 0,
            'total_images' => 0,
            'oversized_images' => 0,
            'processed_images' => 0,
            'uploaded_images' => 0,
            'skipped_images' => 0,
            'failed_images' => 0,
            'errors' => [],
        ];
        
        // Initialize or append to CSV
        $this->initializeCsv();
        
        foreach ($products as $index => $product) {
            try {
                $this->log("[" . ($index + 1) . "/" . count($products) . "] Processing product: {$product['name']} (ID: {$product['product_id']})");
                
                $productStats = $this->processProductImages($product, $existingData);
                
                // Update statistics
                $stats['total_images'] += $productStats['total_images'];
                $stats['oversized_images'] += $productStats['oversized_images'];
                $stats['processed_images'] += $productStats['processed_images'];
                $stats['uploaded_images'] += $productStats['uploaded_images'];
                $stats['skipped_images'] += $productStats['skipped_images'];
                $stats['failed_images'] += $productStats['failed_images'];
                
                $stats['processed_products']++;
                
                // Progress update every 10 products
                if (($index + 1) % 10 === 0) {
                    $this->log("Progress: " . ($index + 1) . "/" . count($products) . " products processed");
                }
                
            } catch (\Exception $e) {
                $error = "Error processing product {$product['product_id']}: " . $e->getMessage();
                $stats['errors'][] = $error;
                $this->log("❌ " . $error);
            }
        }
        
        $duration = microtime(true) - $startTime;
        $stats['duration_seconds'] = round($duration, 2);
        
        $this->log("");
        $this->log("=== Processing Complete ===");
        $this->log("Duration: " . $stats['duration_seconds'] . "s");
        $this->log("Products processed: " . $stats['processed_products'] . "/" . $stats['total_products']);
        $this->log("Images found: " . $stats['total_images']);
        $this->log("Oversized images: " . $stats['oversized_images']);
        $this->log("Images processed: " . $stats['processed_images']);
        $this->log("Images uploaded: " . $stats['uploaded_images']);
        $this->log("Images skipped (already processed): " . $stats['skipped_images']);
        $this->log("Images failed: " . $stats['failed_images']);
        
        if (!empty($stats['errors'])) {
            $this->log("");
            $this->log("Errors encountered:");
            foreach ($stats['errors'] as $error) {
                $this->log("  - " . $error);
            }
        }
        
        return $stats;
    }
    
    /**
     * Process all images for a single product
     */
    private function processProductImages(array $product, array $existingData): array
    {
        $stats = [
            'total_images' => 0,
            'oversized_images' => 0,
            'processed_images' => 0,
            'uploaded_images' => 0,
            'skipped_images' => 0,
            'failed_images' => 0,
        ];
        
        // Get complete product data including images
        $completeProductData = $this->extractor->getCompleteProduct($product['product_id']);
        if (!$completeProductData || !isset($completeProductData['product'])) {
            return $stats;
        }
        
        $completeProduct = $completeProductData['product'];
        $productImages = $completeProductData['images'] ?? [];
        $productOptions = $completeProductData['options'] ?? [];
        $productOptionValues = $completeProductData['option_values'] ?? [];
        
        // Process main product image
        if (!empty($completeProduct['image'])) {
            $imageUrl = $this->buildImageUrl($completeProduct['image']);
            if ($imageUrl) {
                $stats['total_images']++;
                $result = $this->processImage(
                    $completeProduct['product_id'], 
                    null, // No variant ID for main image
                    $imageUrl, 
                    $existingData
                );
                $this->updateStatsFromResult($stats, $result);
            }
        }
        
        // Process additional product images
        if (!empty($productImages)) {
            foreach ($productImages as $image) {
                if (!empty($image['image'])) {
                    $imageUrl = $this->buildImageUrl($image['image']);
                    if ($imageUrl) {
                        $stats['total_images']++;
                        $result = $this->processImage(
                            $completeProduct['product_id'], 
                            null,
                            $imageUrl, 
                            $existingData
                        );
                        $this->updateStatsFromResult($stats, $result);
                    }
                }
            }
        }
        
        // Process variant-specific images
        if (!empty($productOptions) && !empty($productOptionValues)) {
            foreach ($productOptionValues as $optionValue) {
                if (!empty($optionValue['image'])) {
                    $imageUrl = $this->buildImageUrl($optionValue['image']);
                    if ($imageUrl) {
                        $stats['total_images']++;
                        $result = $this->processImage(
                            $completeProduct['product_id'], 
                            $optionValue['product_option_value_id'],
                            $imageUrl, 
                            $existingData
                        );
                        $this->updateStatsFromResult($stats, $result);
                    }
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * Process a single image
     */
    private function processImage(int $productId, ?int $variantId, string $originalUrl, array $existingData): array
    {
        $result = [
            'processed' => false,
            'uploaded' => false,
            'skipped' => false,
            'oversized' => false,
            'failed' => false,
            'error' => null,
        ];
        
        // Check if already processed (resume capability)
        $existingKey = $originalUrl;
        if (isset($existingData[$existingKey]) && !empty($existingData[$existingKey]['Final_Image_URL'])) {
            $result['skipped'] = true;
            $this->log("  ↩ Skipped (already processed): " . basename($originalUrl), 'verbose');
            return $result;
        }
        
        try {
            $this->log("  🔍 Processing: " . basename($originalUrl), 'verbose');
            
            // Download and analyze image
            $tempFile = $this->downloadImage($originalUrl);
            if (!$tempFile) {
                $result['failed'] = true;
                $result['error'] = 'Failed to download image';
                return $result;
            }
            
            $imageInfo = getimagesize($tempFile);
            if (!$imageInfo) {
                unlink($tempFile);
                $result['failed'] = true;
                $result['error'] = 'Invalid image format';
                return $result;
            }
            
            [$width, $height, $type] = $imageInfo;
            $megapixels = ($width * $height) / 1000000;
            $fileSize = filesize($tempFile);
            
            $this->log("    📏 Size: {$width}x{$height} ({$megapixels}MP, " . $this->formatBytes($fileSize) . ")", 'verbose');
            
            // Check if image exceeds limits
            $needsProcessing = $megapixels > self::MAX_MEGAPIXELS || $fileSize > self::MAX_FILE_SIZE;
            $result['oversized'] = $needsProcessing;
            
            $finalUrl = $originalUrl; // Default to original URL
            $finalSize = "{$width}x{$height}";
            $finalMegapixels = round($megapixels, 2);
            
            if ($needsProcessing) {
                $this->log("    ⚡ Image exceeds limits, processing...", 'verbose');
                $processedData = $this->resizeAndOptimizeImage($tempFile, $width, $height, $type);
                
                if ($processedData) {
                    // Save processed image temporarily
                    $processedTempFile = tempnam(sys_get_temp_dir(), 'processed_img_') . $this->getFileExtension($type);
                    file_put_contents($processedTempFile, $processedData['image_data']);
                    
                    // Upload processed image
                    $uploadedUrl = $this->uploadToImageHost($processedTempFile, $originalUrl);
                    if ($uploadedUrl) {
                        $finalUrl = $uploadedUrl;
                        $finalSize = $processedData['final_size'];
                        $finalMegapixels = $processedData['megapixels'];
                        $result['processed'] = true;
                        $result['uploaded'] = true;
                        
                        $this->log("    ✅ Processed and uploaded: {$finalSize} ({$finalMegapixels}MP)", 'verbose');
                    } else {
                        $this->log("    ⚠ Processing successful but upload failed, using original URL", 'verbose');
                    }
                    
                    unlink($processedTempFile);
                } else {
                    $this->log("    ❌ Image processing failed", 'verbose');
                }
            } else {
                // Image is within limits, but we might still want to upload for consistency
                if (!empty($this->config['image_host_url'])) {
                    $uploadedUrl = $this->uploadToImageHost($tempFile, $originalUrl);
                    if ($uploadedUrl) {
                        $finalUrl = $uploadedUrl;
                        $result['uploaded'] = true;
                        $this->log("    ✅ Uploaded as-is: {$finalSize} ({$finalMegapixels}MP)", 'verbose');
                    }
                } else {
                    $this->log("    ✅ Within limits: {$finalSize} ({$finalMegapixels}MP)", 'verbose');
                }
            }
            
            unlink($tempFile);
            
            // Write to CSV
            $this->writeToCsv($productId, $variantId, $originalUrl, $finalUrl, $finalSize, $finalMegapixels, $needsProcessing);
            
        } catch (\Exception $e) {
            $result['failed'] = true;
            $result['error'] = $e->getMessage();
            $this->log("    ❌ Error: " . $e->getMessage(), 'verbose');
        }
        
        return $result;
    }
    
    /**
     * Download image from URL to temporary file
     */
    private function downloadImage(string $url): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'oc_img_');
        
        // Properly encode the URL to handle spaces and special characters
        $encodedUrl = $this->encodeImageUrl($url);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $encodedUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Increase timeout to 2 minutes for large images
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30); // Connection timeout
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024); // Min 1KB/s
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 60); // For 60 seconds
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($imageData === false || $httpCode !== 200 || $error) {
            $this->log("    ❌ Download failed: HTTP $httpCode, Error: $error");
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            return null;
        }
        
        if (file_put_contents($tempFile, $imageData) === false) {
            $this->log("    ❌ Failed to save downloaded image");
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            return null;
        }
        
        return $tempFile;
    }
    
    /**
     * Properly encode image URL to handle spaces and special characters
     */
    private function encodeImageUrl(string $url): string
    {
        // Parse the URL to encode only the path part
        $parsedUrl = parse_url($url);
        if (!$parsedUrl) {
            return $url; // Return original if parsing fails
        }
        
        $encodedUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        
        if (isset($parsedUrl['port'])) {
            $encodedUrl .= ':' . $parsedUrl['port'];
        }
        
        if (isset($parsedUrl['path'])) {
            // Encode each path segment separately to preserve forward slashes
            $pathSegments = explode('/', $parsedUrl['path']);
            $encodedSegments = array_map('rawurlencode', $pathSegments);
            $encodedUrl .= implode('/', $encodedSegments);
        }
        
        if (isset($parsedUrl['query'])) {
            $encodedUrl .= '?' . $parsedUrl['query'];
        }
        
        if (isset($parsedUrl['fragment'])) {
            $encodedUrl .= '#' . $parsedUrl['fragment'];
        }
        
        return $encodedUrl;
    }
    
    /**
     * Resize and optimize image while maintaining aspect ratio
     */
    private function resizeAndOptimizeImage(string $tempFile, int $width, int $height, int $type): ?array
    {
        // Create image resource based on type
        switch ($type) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($tempFile);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($tempFile);
                break;
            case IMAGETYPE_GIF:
                $image = imagecreatefromgif($tempFile);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $image = imagecreatefromwebp($tempFile);
                } else {
                    return null;
                }
                break;
            default:
                return null;
        }
        
        if (!$image) {
            return null;
        }
        
        // Calculate new dimensions
        $newDimensions = $this->calculateNewDimensions($width, $height);
        $newWidth = $newDimensions['width'];
        $newHeight = $newDimensions['height'];
        
        // Create new image with calculated dimensions
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG and GIF
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
            imagefill($newImage, 0, 0, $transparent);
        }
        
        // Resize image with high quality
        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        // Generate output
        ob_start();
        
        // Choose output format
        if ($type == IMAGETYPE_PNG && $this->hasTransparency($image)) {
            imagepng($newImage, null, self::PNG_COMPRESSION);
        } else {
            // Convert to JPEG for better compression
            imagejpeg($newImage, null, self::JPEG_QUALITY);
        }
        
        $imageData = ob_get_clean();
        
        // Clean up
        imagedestroy($image);
        imagedestroy($newImage);
        
        $finalMegapixels = ($newWidth * $newHeight) / 1000000;
        
        return [
            'image_data' => $imageData,
            'final_size' => "{$newWidth}x{$newHeight}",
            'megapixels' => round($finalMegapixels, 2),
        ];
    }
    
    /**
     * Calculate new dimensions while maintaining aspect ratio
     */
    private function calculateNewDimensions(int $width, int $height): array
    {
        $currentMegapixels = ($width * $height) / 1000000;
        
        if ($currentMegapixels <= self::MAX_MEGAPIXELS) {
            return ['width' => $width, 'height' => $height];
        }
        
        // Calculate scaling factor to achieve target megapixels
        $scaleFactor = sqrt(self::MAX_MEGAPIXELS / $currentMegapixels);
        
        $newWidth = (int)round($width * $scaleFactor);
        $newHeight = (int)round($height * $scaleFactor);
        
        // Ensure we don't exceed the limit due to rounding
        $newMegapixels = ($newWidth * $newHeight) / 1000000;
        if ($newMegapixels > self::MAX_MEGAPIXELS) {
            $adjustment = sqrt(self::MAX_MEGAPIXELS / $newMegapixels);
            $newWidth = (int)floor($newWidth * $adjustment);
            $newHeight = (int)floor($newHeight * $adjustment);
        }
        
        // Ensure minimum size
        $newWidth = max($newWidth, 100);
        $newHeight = max($newHeight, 100);
        
        return ['width' => $newWidth, 'height' => $newHeight];
    }
    
    /**
     * Check if image has transparency
     */
    private function hasTransparency($image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Sample pixels to check for transparency
        for ($x = 0; $x < $width; $x += max(1, $width / 10)) {
            for ($y = 0; $y < $height; $y += max(1, $height / 10)) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($color['alpha'] < 127) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Upload processed image to hosting service (implement your preferred service)
     */
    private function uploadToImageHost(string $tempFilePath, string $originalUrl): ?string
    {
        // Use ImgBB with API key from environment
        if (empty($this->imgbbApiKey)) {
            $this->log("⚠️ ImgBB API key not found in environment. Set IMGBB_API_KEY in .env file");
            return null;
        }
        
        $imageData = file_get_contents($tempFilePath);
        if (!$imageData) {
            return null;
        }
        
        $base64Image = base64_encode($imageData);
        
        $postData = [
            'key' => $this->imgbbApiKey,
            'image' => $base64Image,
            'name' => 'oc_processed_' . md5($originalUrl),
            'expiration' => 0
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.imgbb.com/1/upload');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $this->log("    ❌ ImgBB Upload Error: $error");
            return null;
        }
        
        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            if (isset($responseData['success']) && $responseData['success']) {
                return $responseData['data']['url'] ?? null;
            } else {
                $this->log("    ❌ ImgBB Upload Failed: " . ($responseData['error']['message'] ?? 'Unknown error'));
            }
        } else {
            $this->log("    ❌ ImgBB HTTP Error: $httpCode");
        }
        
        return null;
    }
    
    /**
     * Build complete image URL from OpenCart path
     */
    private function buildImageUrl(string $imagePath): ?string
    {
        if (empty($imagePath)) {
            return null;
        }
        
        $baseUrl = $this->config['opencart_base_url'] ?? '';
        if (empty($baseUrl)) {
            return null;
        }
        
        return rtrim($baseUrl, '/') . '/' . ltrim($imagePath, '/');
    }
    
    /**
     * Get products to process with pagination support
     */
    private function getProductsToProcess(?int $limit = null, int $offset = 0): array
    {
        // Build WHERE clause based on status filter
        $where = "pd.language_id = 1";
        
        $statusFilter = $this->config['status_filter'] ?? 'active';
        
        if ($statusFilter === 'active') {
            // Only active products
            $where .= " AND p.status = 1";
        } elseif ($statusFilter === 'inactive') {
            // Only inactive products
            $where .= " AND p.status = 0";
        }
        // 'all' - no additional filter
        
        $sql = "SELECT p.product_id, pd.name 
                FROM " . $this->db->table('product') . " p
                LEFT JOIN " . $this->db->table('product_description') . " pd ON p.product_id = pd.product_id
                WHERE {$where}
                ORDER BY p.product_id ASC";
        
        if ($limit !== null) {
            $sql .= " LIMIT {$offset}, {$limit}";
        }
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Load existing CSV data for resume capability
     */
    private function loadExistingCsvData(): array
    {
        $data = [];
        
        if (!file_exists($this->csvPath)) {
            return $data;
        }
        
        if (($handle = fopen($this->csvPath, 'r')) !== false) {
            $header = fgetcsv($handle);
            
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) >= 4) {
                    // Use Original_Image_URL as key for lookup
                    $data[$row[2]] = [
                        'OpenCart_Product_ID' => $row[0],
                        'OpenCart_Variant_ID' => $row[1],
                        'Original_Image_URL' => $row[2],
                        'Final_Image_URL' => $row[3],
                    ];
                }
            }
            fclose($handle);
        }
        
        return $data;
    }
    
    /**
     * Initialize CSV file with headers
     */
    private function initializeCsv(): void
    {
        if (!file_exists($this->csvPath)) {
            file_put_contents($this->csvPath, "OpenCart_Product_ID,OpenCart_Variant_ID,Original_Image_URL,Final_Image_URL,Final_Size,Megapixels,Processed\n");
        }
    }
    
    /**
     * Write image mapping entry to CSV
     */
    private function writeToCsv(int $productId, ?int $variantId, string $originalUrl, string $finalUrl, string $finalSize, float $megapixels, bool $processed): void
    {
        $row = [
            $productId,
            $variantId ?: '',
            $originalUrl,
            $finalUrl,
            $finalSize,
            $megapixels,
            $processed ? 'Yes' : 'No'
        ];
        
        $csvLine = '"' . implode('","', $row) . '"' . PHP_EOL;
        file_put_contents($this->csvPath, $csvLine, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Get file extension based on image type
     */
    private function getFileExtension(int $type): string
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return '.jpg';
            case IMAGETYPE_PNG:
                return '.png';
            case IMAGETYPE_GIF:
                return '.gif';
            case IMAGETYPE_WEBP:
                return '.webp';
            default:
                return '.jpg';
        }
    }
    
    /**
     * Update statistics from processing result
     */
    private function updateStatsFromResult(array &$stats, array $result): void
    {
        if ($result['oversized']) $stats['oversized_images']++;
        if ($result['processed']) $stats['processed_images']++;
        if ($result['uploaded']) $stats['uploaded_images']++;
        if ($result['skipped']) $stats['skipped_images']++;
        if ($result['failed']) $stats['failed_images']++;
    }
    
    /**
     * Format bytes in human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * Log message with timestamp and format support
     */
    private function log(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;
        
        // Always write to log file
        file_put_contents($this->logPath, $logMessage, FILE_APPEND | LOCK_EX);
        
        // Output to console based on format and verbosity
        if ($level !== 'verbose' || $this->verbose) {
            if ($this->config['format'] === 'jsonl') {
                // Output as JSON Line for web interface
                $data = [
                    'type' => 'log',
                    'level' => $level,
                    'message' => $message,
                    'timestamp' => $timestamp,
                ];
                echo json_encode($data) . PHP_EOL;
                flush();
            } else {
                // Plain text output
                echo $message . PHP_EOL;
            }
        }
    }
    
    /**
     * Phase 2: Load image mapping CSV for migration integration
     * 
     * @return array Associative array with image URL mappings
     */
    public function loadImageMappingForMigration(): array
    {
        $mapping = [
            'by_product' => [], // product_id => [images...]
            'by_variant' => [], // variant_id => [images...]
            'by_url' => [],     // original_url => final_url
        ];
        
        if (!file_exists($this->csvPath)) {
            $this->log("Warning: Image mapping CSV not found at {$this->csvPath}");
            return $mapping;
        }
        
        if (($handle = fopen($this->csvPath, 'r')) !== false) {
            $header = fgetcsv($handle); // Skip header
            
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) >= 4) {
                    $productId = (int)$row[0];
                    $variantId = !empty($row[1]) ? (int)$row[1] : null;
                    $originalUrl = $row[2];
                    $finalUrl = $row[3];
                    
                    // Map by URL (for direct lookups)
                    $mapping['by_url'][$originalUrl] = $finalUrl;
                    
                    // Map by product ID
                    if (!isset($mapping['by_product'][$productId])) {
                        $mapping['by_product'][$productId] = [];
                    }
                    $mapping['by_product'][$productId][] = [
                        'original_url' => $originalUrl,
                        'final_url' => $finalUrl,
                        'variant_id' => $variantId,
                    ];
                    
                    // Map by variant ID (if applicable)
                    if ($variantId) {
                        if (!isset($mapping['by_variant'][$variantId])) {
                            $mapping['by_variant'][$variantId] = [];
                        }
                        $mapping['by_variant'][$variantId][] = [
                            'original_url' => $originalUrl,
                            'final_url' => $finalUrl,
                        ];
                    }
                }
            }
            fclose($handle);
        }
        
        $this->log("Loaded " . count($mapping['by_url']) . " image mappings for migration");
        
        return $mapping;
    }
    
    /**
     * Get mapped image URL for migration
     */
    public function getMappedImageUrl(string $originalUrl, array $mapping): string
    {
        return $mapping['by_url'][$originalUrl] ?? $originalUrl;
    }
}