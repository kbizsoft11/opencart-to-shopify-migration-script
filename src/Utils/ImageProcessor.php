<?php

namespace App\Utils;

class ImageProcessor
{
    private const MAX_MEGAPIXELS = 24.9; // 24.9MP to stay safely under Shopify's 25MP limit
    private const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20MB max file size
    private const JPEG_QUALITY = 85; // Good quality while keeping file size reasonable
    private const PNG_COMPRESSION = 6; // PNG compression level (0-9)
    
    // Local image hosting configuration
    private const PROCESSED_IMAGES_DIR = __DIR__ . '/../../public/processed_images';
    private const PROCESSED_IMAGES_URL_BASE = 'http://localhost:8080/processed_images';
    
    private bool $processImagesMode = false;
    private ?string $imageHostOverride = null;
    private string $outputDir;
    
    public function __construct(bool $processImagesMode = false, ?string $imageHostOverride = null, string $outputDir = null)
    {
        $this->processImagesMode = $processImagesMode;
        $this->imageHostOverride = $imageHostOverride;
        $this->outputDir = $outputDir ?: __DIR__ . '/../../output/images';
    }
    
    /**
     * Process an image from URL - resize if needed and optimize
     */
    public function processImageFromUrl(string $imageUrl): ?array
    {
        // If image host override is provided, just replace the domain
        if ($this->imageHostOverride) {
            $parsedUrl = parse_url($imageUrl);
            if (!$parsedUrl) {
                return null;
            }
            
            $newUrl = rtrim($this->imageHostOverride, '/') . ($parsedUrl['path'] ?? '');
            
            return [
                'url' => $newUrl,
                'processed' => false,
                'final_size' => 'N/A',
                'megapixels' => 'N/A',
                'hosted_externally' => true,
                'original_url' => $imageUrl,
            ];
        }
        
        // If process images mode, download and save locally without uploading to imgbb
        if ($this->processImagesMode) {
            return $this->processAndSaveLocal($imageUrl);
        }
        
        // Original behavior - process and upload to imgbb
        echo "🔍 [ImageProcessor] Starting to process image: {$imageUrl}" . PHP_EOL;
        
        try {
            // Download image to temporary location
            echo "📥 [ImageProcessor] Attempting to download image..." . PHP_EOL;
            $tempFile = $this->downloadImage($imageUrl);
            if (!$tempFile) {
                echo "❌ [ImageProcessor] Download failed - no temp file created" . PHP_EOL;
                error_log("Failed to download image: {$imageUrl}");
                return null;
            }
            echo "✅ [ImageProcessor] Download successful, temp file: {$tempFile}" . PHP_EOL;
            
            // Get image info
            echo "📊 [ImageProcessor] Analyzing image dimensions and type..." . PHP_EOL;
            $imageInfo = getimagesize($tempFile);
            if (!$imageInfo) {
                echo "❌ [ImageProcessor] Invalid image file - getimagesize failed" . PHP_EOL;
                unlink($tempFile);
                error_log("Invalid image file: {$imageUrl}");
                return null;
            }
            
            [$width, $height, $type] = $imageInfo;
            $currentMegapixels = ($width * $height) / 1000000;
            $fileSize = filesize($tempFile);
            
            echo "📏 [ImageProcessor] Image info: {$width}x{$height} ({$currentMegapixels}MP, " . round($fileSize/1024/1024, 2) . "MB)" . PHP_EOL;
            error_log("Processing image: {$imageUrl} - {$width}x{$height} ({$currentMegapixels}MP, " . round($fileSize/1024/1024, 2) . "MB)");
            
            // Check if image needs processing
            if ($currentMegapixels <= self::MAX_MEGAPIXELS && $fileSize <= self::MAX_FILE_SIZE) {
                // Image is fine as-is, but let's still upload it for consistency
                echo "✅ [ImageProcessor] Image is within limits, but uploading for consistency..." . PHP_EOL;
                
                $hostedUrl = $this->uploadImageToExternalHost($tempFile, $imageUrl);
                unlink($tempFile);
                
                if (!$hostedUrl) {
                    echo "❌ [ImageProcessor] Failed to upload original image" . PHP_EOL;
                    // Fall back to original URL if upload fails
                    return [
                        'url' => $imageUrl,
                        'processed' => false,
                        'original_size' => "{$width}x{$height}",
                        'final_size' => "{$width}x{$height}",
                        'megapixels' => round($currentMegapixels, 2),
                        'upload_failed' => true
                    ];
                }
                
                return [
                    'url' => $hostedUrl,
                    'processed' => false,
                    'original_size' => "{$width}x{$height}",
                    'final_size' => "{$width}x{$height}",
                    'megapixels' => round($currentMegapixels, 2),
                    'uploaded' => true
                ];
            }
            
            echo "⚡ [ImageProcessor] Image exceeds limits, processing required..." . PHP_EOL;
            // Process image
            $processedData = $this->resizeAndOptimizeImage($tempFile, $width, $height, $type);
            
            if (!$processedData) {
                echo "❌ [ImageProcessor] Processing failed - resizeAndOptimizeImage returned null" . PHP_EOL;
                unlink($tempFile);
                error_log("Failed to process image: {$imageUrl}");
                return null;
            }
            
            echo "✅ [ImageProcessor] Processing successful, saving processed image..." . PHP_EOL;
            
            // Save processed image to temp file
            $processedTempFile = tempnam(sys_get_temp_dir(), 'processed_img_');
            $extension = $this->getFileExtension($type);
            $processedTempFile .= $extension;
            
            if (file_put_contents($processedTempFile, $processedData['image_data']) === false) {
                echo "❌ [ImageProcessor] Failed to save processed image to temp file" . PHP_EOL;
                unlink($tempFile);
                return null;
            }
            
            // Upload processed image to external host
            echo "📤 [ImageProcessor] Uploading processed image..." . PHP_EOL;
            $hostedUrl = $this->uploadImageToExternalHost($processedTempFile, $imageUrl);
            
            // Clean up temp files
            unlink($tempFile);
            unlink($processedTempFile);
            
            if (!$hostedUrl) {
                echo "❌ [ImageProcessor] Failed to upload processed image" . PHP_EOL;
                // Return original URL as fallback (though it will likely fail in Shopify)
                return [
                    'url' => $imageUrl,
                    'processed' => true,
                    'original_size' => "{$width}x{$height}",
                    'final_size' => $processedData['final_size'],
                    'megapixels' => $processedData['megapixels'],
                    'file_size' => strlen($processedData['image_data']),
                    'upload_failed' => true
                ];
            }
            
            echo "🎯 [ImageProcessor] Processed image uploaded: {$hostedUrl}" . PHP_EOL;
            return [
                'url' => $hostedUrl,
                'processed' => true,
                'original_size' => "{$width}x{$height}",
                'final_size' => $processedData['final_size'],
                'megapixels' => $processedData['megapixels'],
                'file_size' => strlen($processedData['image_data']),
                'uploaded' => true
            ];
            
        } catch (\Exception $e) {
            echo "💥 [ImageProcessor] Exception occurred: " . $e->getMessage() . PHP_EOL;
            echo "📍 [ImageProcessor] Stack trace: " . $e->getTraceAsString() . PHP_EOL;
            error_log("Error processing image {$imageUrl}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Download image from URL to temporary file
     */
    private function downloadImage(string $url): ?string
    {
        echo "🌐 [ImageProcessor] downloadImage() called with URL: {$url}" . PHP_EOL;
        
        $tempFile = tempnam(sys_get_temp_dir(), 'shopify_img_');
        echo "📁 [ImageProcessor] Created temp file: {$tempFile}" . PHP_EOL;
        
        // Use cURL for better control
        echo "🌍 [ImageProcessor] Starting cURL download..." . PHP_EOL;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        echo "📡 [ImageProcessor] cURL response - HTTP Code: {$httpCode}" . PHP_EOL;
        if (!empty($error)) {
            echo "❌ [ImageProcessor] cURL error: {$error}" . PHP_EOL;
        }
        
        if ($imageData === false || $httpCode !== 200) {
            echo "💥 [ImageProcessor] Download failed - imageData: " . ($imageData === false ? 'false' : 'received') . ", HTTP: {$httpCode}" . PHP_EOL;
            if (file_exists($tempFile)) {
                unlink($tempFile);
                echo "🗑️ [ImageProcessor] Cleaned up temp file" . PHP_EOL;
            }
            return null;
        }
        
        $dataSize = strlen($imageData);
        echo "📊 [ImageProcessor] Downloaded {$dataSize} bytes of image data" . PHP_EOL;
        
        echo "💾 [ImageProcessor] Writing data to temp file..." . PHP_EOL;
        if (file_put_contents($tempFile, $imageData) === false) {
            echo "❌ [ImageProcessor] Failed to write data to temp file" . PHP_EOL;
            if (file_exists($tempFile)) {
                unlink($tempFile);
                echo "🗑️ [ImageProcessor] Cleaned up temp file" . PHP_EOL;
            }
            return null;
        }
        
        echo "✅ [ImageProcessor] Successfully downloaded and saved image to {$tempFile}" . PHP_EOL;
        return $tempFile;
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
        
        // Choose output format (prefer JPEG for photos, PNG for graphics with transparency)
        if ($type == IMAGETYPE_PNG && $this->hasTransparency($image)) {
            imagepng($newImage, null, self::PNG_COMPRESSION);
            $outputType = IMAGETYPE_PNG;
        } else {
            // Convert to JPEG for better compression
            imagejpeg($newImage, null, self::JPEG_QUALITY);
            $outputType = IMAGETYPE_JPEG;
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
            'output_type' => $outputType
        ];
    }
    
    /**
     * Calculate new dimensions while maintaining aspect ratio and staying under MP limit
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
        
        // Sample a few pixels to check for transparency
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
     * Upload image to external hosting service (imgbb.com)
     * Get your free API key at: https://api.imgbb.com/
     */
    private function uploadImageToExternalHost(string $tempFilePath, string $originalUrl): ?string
    {
        echo "🌐 [ImageProcessor] uploadImageToExternalHost() called" . PHP_EOL;
        
        // *** REPLACE THIS WITH YOUR REAL IMGBB API KEY ***
        // Get a free API key at: https://api.imgbb.com/
        $apiKey = 'b517f81e8a34af9255367b7fa91eb0db';
        
        if (empty($apiKey) || $apiKey === 'YOUR_IMGBB_API_KEY_HERE') {
            echo "❌ [ImageProcessor] Please set your imgbb API key in uploadImageToExternalHost()" . PHP_EOL;
            echo "🔗 [ImageProcessor] Get a free API key at: https://api.imgbb.com/" . PHP_EOL;
            
            // For now, fall back to original URL (this will likely fail)
            echo "⚠️ [ImageProcessor] Using original URL as fallback" . PHP_EOL;
            return $originalUrl;
        }
        
        // Read image file and encode it to base64
        $imageData = file_get_contents($tempFilePath);
        if (!$imageData) {
            echo "❌ [ImageProcessor] Failed to read temp file" . PHP_EOL;
            return null;
        }
        
        $base64Image = base64_encode($imageData);
        
        // Prepare API request for imgbb
        $postData = [
            'key' => $apiKey,
            'image' => $base64Image,
            'name' => 'processed_' . md5($originalUrl),
            'expiration' => 0 // Never expire (0 = permanent with free account)
        ];
        
        echo "📤 [ImageProcessor] Uploading to imgbb.com (size: " . round(strlen($base64Image)/1024/1024, 2) . "MB)..." . PHP_EOL;
        
        // Use cURL to upload to imgbb API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.imgbb.com/1/upload');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 2 minutes timeout for large images
        curl_setopt($ch, CURLOPT_USERAGENT, 'OpenCart-Shopify-Migration-Tool/1.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            echo "❌ [ImageProcessor] cURL error: {$error}" . PHP_EOL;
            return null;
        }
        
        echo "📡 [ImageProcessor] imgbb API response - HTTP Code: {$httpCode}" . PHP_EOL;
        
        if ($httpCode !== 200) {
            echo "❌ [ImageProcessor] imgbb API returned error status: {$httpCode}" . PHP_EOL;
            echo "📝 [ImageProcessor] Response: " . substr($response, 0, 500) . PHP_EOL;
            return null;
        }
        
        // Parse JSON response
        $responseData = json_decode($response, true);
        if (!$responseData) {
            echo "❌ [ImageProcessor] Failed to parse JSON response: " . substr($response, 0, 200) . PHP_EOL;
            return null;
        }
        
        if (!isset($responseData['success']) || !$responseData['success']) {
            echo "❌ [ImageProcessor] imgbb upload failed: " . ($responseData['error']['message'] ?? 'Unknown error') . PHP_EOL;
            return null;
        }
        
        $hostedUrl = $responseData['data']['url'] ?? null;
        if (!$hostedUrl) {
            echo "❌ [ImageProcessor] No URL in imgbb response" . PHP_EOL;
            return null;
        }
        
        echo "✅ [ImageProcessor] Image uploaded successfully to imgbb: {$hostedUrl}" . PHP_EOL;
        echo "🔗 [ImageProcessor] Direct link (for Shopify): {$hostedUrl}" . PHP_EOL;
        
        return $hostedUrl;
    }

    /**
     * Get MIME type based on image type constant
     */
    private function getMimeType(int $type): string
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return 'image/jpeg';
            case IMAGETYPE_PNG:
                return 'image/png';
            case IMAGETYPE_GIF:
                return 'image/gif';
            case IMAGETYPE_WEBP:
                return 'image/webp';
            default:
                return 'image/jpeg'; // Default fallback
        }
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
     * Get file size in human readable format
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Process and save image locally with OpenCart directory structure
     */
    private function processAndSaveLocal(string $imageUrl): ?array
    {
        echo "🔍 [ImageProcessor] Processing image for local storage: {$imageUrl}" . PHP_EOL;
        
        try {
            // Parse URL to get the path
            $parsedUrl = parse_url($imageUrl);
            if (!$parsedUrl || empty($parsedUrl['path'])) {
                echo "❌ [ImageProcessor] Invalid URL structure" . PHP_EOL;
                return null;
            }
            
            // Download image to temporary location
            echo "📥 [ImageProcessor] Downloading image..." . PHP_EOL;
            $tempFile = $this->downloadImage($imageUrl);
            if (!$tempFile) {
                echo "❌ [ImageProcessor] Download failed" . PHP_EOL;
                return null;
            }
            
            // Get image info
            $imageInfo = getimagesize($tempFile);
            if (!$imageInfo) {
                echo "❌ [ImageProcessor] Invalid image format" . PHP_EOL;
                unlink($tempFile);
                return null;
            }
            
            list($width, $height, $type) = $imageInfo;
            $megapixels = ($width * $height) / 1000000;
            $fileSize = filesize($tempFile);
            
            echo "📊 [ImageProcessor] Image info: {$width}x{$height} ({$megapixels}MP, " . self::formatBytes($fileSize) . ")" . PHP_EOL;
            
            // Create output directory structure matching OpenCart path
            $relativePath = trim($parsedUrl['path'], '/');
            $outputPath = $this->outputDir . '/' . $relativePath;
            $outputDir = dirname($outputPath);
            
            if (!is_dir($outputDir)) {
                if (!mkdir($outputDir, 0755, true)) {
                    echo "❌ [ImageProcessor] Failed to create output directory: {$outputDir}" . PHP_EOL;
                    unlink($tempFile);
                    return null;
                }
            }
            
            $needsProcessing = $megapixels > self::MAX_MEGAPIXELS || $fileSize > self::MAX_FILE_SIZE;
            
            if ($needsProcessing) {
                echo "⚡ [ImageProcessor] Image exceeds limits, processing..." . PHP_EOL;
                $processedData = $this->resizeAndOptimizeImage($tempFile, $width, $height, $type);
                
                if (!$processedData) {
                    echo "❌ [ImageProcessor] Failed to process image" . PHP_EOL;
                    unlink($tempFile);
                    return null;
                }
                
                // Save processed image
                file_put_contents($outputPath, $processedData['imageData']);
                $finalWidth = $processedData['width'];
                $finalHeight = $processedData['height'];
                $finalMegapixels = ($finalWidth * $finalHeight) / 1000000;
                
                echo "✅ [ImageProcessor] Processed and saved: {$outputPath}" . PHP_EOL;
                echo "📏 [ImageProcessor] Final size: {$finalWidth}x{$finalHeight} ({$finalMegapixels}MP)" . PHP_EOL;
                
                unlink($tempFile);
                
                return [
                    'url' => $imageUrl, // Keep original URL for reference
                    'local_path' => $outputPath,
                    'relative_path' => $relativePath,
                    'processed' => true,
                    'original_size' => "{$width}x{$height}",
                    'final_size' => "{$finalWidth}x{$finalHeight}",
                    'megapixels' => round($finalMegapixels, 2) . 'MP',
                ];
            } else {
                // Copy as-is
                if (!copy($tempFile, $outputPath)) {
                    echo "❌ [ImageProcessor] Failed to save image: {$outputPath}" . PHP_EOL;
                    unlink($tempFile);
                    return null;
                }
                
                echo "✅ [ImageProcessor] Saved as-is: {$outputPath}" . PHP_EOL;
                unlink($tempFile);
                
                return [
                    'url' => $imageUrl, // Keep original URL for reference
                    'local_path' => $outputPath,
                    'relative_path' => $relativePath,
                    'processed' => false,
                    'final_size' => "{$width}x{$height}",
                    'megapixels' => round($megapixels, 2) . 'MP',
                ];
            }
            
        } catch (\Exception $e) {
            echo "❌ [ImageProcessor] Processing failed: " . $e->getMessage() . PHP_EOL;
            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }
            return null;
        }
    }
}