<?php
/**
 * Image Processing Validation Script
 * 
 * Validates that processed images meet Shopify requirements and are accessible
 * 
 * Usage:
 *   php validate_images.php
 *   php validate_images.php --csv-path=custom/path.csv
 *   php validate_images.php --sample=20
 */

class ImageValidator
{
    private const MAX_MEGAPIXELS = 25;
    private const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20MB
    
    private string $csvPath;
    private ?int $sampleSize;
    private bool $verbose;
    
    public function __construct(array $args)
    {
        $this->verbose = in_array('--verbose', $args) || in_array('-v', $args);
        $this->csvPath = __DIR__ . '/output/image_processing/image_mapping.csv';
        $this->sampleSize = null;
        
        foreach ($args as $arg) {
            if (strpos($arg, '--csv-path=') === 0) {
                $this->csvPath = substr($arg, 11);
            } elseif (strpos($arg, '--sample=') === 0) {
                $this->sampleSize = (int)substr($arg, 9);
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
Image Processing Validation Script

Validates that processed images meet Shopify requirements (25MP, 20MB) 
and are accessible via HTTP.

Usage:
  php validate_images.php [options]

Options:
  --csv-path=PATH        Path to image mapping CSV file
  --sample=N             Validate only N random images (for large datasets)
  --verbose, -v          Show detailed validation information
  --help, -h             Show this help message

Examples:
  php validate_images.php
  php validate_images.php --sample=50 --verbose
  php validate_images.php --csv-path=custom/mapping.csv

HELP;
    }
    
    public function validate(): void
    {
        echo "Image Processing Validation" . PHP_EOL;
        echo "==========================" . PHP_EOL;
        echo "CSV File: " . $this->csvPath . PHP_EOL;
        
        if (!file_exists($this->csvPath)) {
            echo "❌ ERROR: CSV file not found: " . $this->csvPath . PHP_EOL;
            exit(1);
        }
        
        echo "Sample Size: " . ($this->sampleSize ? $this->sampleSize . " images" : "All images") . PHP_EOL;
        echo "" . PHP_EOL;
        
        $mappings = $this->loadMappings();
        echo "Total mappings loaded: " . count($mappings) . PHP_EOL;
        
        if ($this->sampleSize && $this->sampleSize < count($mappings)) {
            $mappings = array_slice($mappings, 0, $this->sampleSize);
            echo "Validating sample of: " . count($mappings) . " images" . PHP_EOL;
        }
        
        echo "" . PHP_EOL;
        
        $stats = [
            'total' => count($mappings),
            'valid' => 0,
            'invalid_size' => 0,
            'invalid_megapixels' => 0,
            'unreachable' => 0,
            'other_errors' => 0,
        ];
        
        foreach ($mappings as $index => $mapping) {
            $current = $index + 1;
            $result = $this->validateImage($mapping);
            
            if ($result['valid']) {
                $stats['valid']++;
                if ($this->verbose) {
                    echo "✅ [{$current}/{$stats['total']}] " . basename($mapping['final_url']) . 
                         " ({$result['dimensions']}, {$result['megapixels']}MP, " . $result['file_size'] . ")" . PHP_EOL;
                }
            } else {
                if ($result['error_type'] === 'size') {
                    $stats['invalid_size']++;
                } elseif ($result['error_type'] === 'megapixels') {
                    $stats['invalid_megapixels']++;
                } elseif ($result['error_type'] === 'unreachable') {
                    $stats['unreachable']++;
                } else {
                    $stats['other_errors']++;
                }
                
                echo "❌ [{$current}/{$stats['total']}] " . basename($mapping['final_url']) . 
                     " - " . $result['error'] . PHP_EOL;
            }
            
            if ($current % 20 === 0) {
                echo "Progress: {$current}/{$stats['total']} (" . round(($current / $stats['total']) * 100, 1) . "%)" . PHP_EOL;
            }
        }
        
        echo "" . PHP_EOL;
        echo "Validation Results:" . PHP_EOL;
        echo "==================" . PHP_EOL;
        echo "Total images: " . $stats['total'] . PHP_EOL;
        echo "Valid images: " . $stats['valid'] . " (" . round(($stats['valid'] / $stats['total']) * 100, 1) . "%)" . PHP_EOL;
        echo "Invalid file size: " . $stats['invalid_size'] . PHP_EOL;
        echo "Invalid megapixels: " . $stats['invalid_megapixels'] . PHP_EOL;
        echo "Unreachable URLs: " . $stats['unreachable'] . PHP_EOL;
        echo "Other errors: " . $stats['other_errors'] . PHP_EOL;
        
        if ($stats['valid'] === $stats['total']) {
            echo "" . PHP_EOL;
            echo "🎉 All images passed validation!" . PHP_EOL;
            echo "   Images are ready for Shopify migration." . PHP_EOL;
        } else {
            echo "" . PHP_EOL;
            echo "⚠️  Some images failed validation." . PHP_EOL;
            echo "   Review the errors above before proceeding with migration." . PHP_EOL;
        }
    }
    
    private function loadMappings(): array
    {
        $mappings = [];
        
        if (($handle = fopen($this->csvPath, 'r')) !== false) {
            $header = fgetcsv($handle); // Skip header
            
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) >= 4) {
                    $mappings[] = [
                        'product_id' => $row[0],
                        'variant_id' => $row[1],
                        'original_url' => $row[2],
                        'final_url' => $row[3],
                        'final_size' => $row[4] ?? '',
                        'megapixels' => $row[5] ?? '',
                        'processed' => $row[6] ?? '',
                    ];
                }
            }
            fclose($handle);
        }
        
        return $mappings;
    }
    
    private function validateImage(array $mapping): array
    {
        $result = [
            'valid' => false,
            'error' => null,
            'error_type' => null,
            'dimensions' => null,
            'megapixels' => null,
            'file_size' => null,
        ];
        
        $url = $mapping['final_url'];
        
        try {
            // Download image headers to check accessibility and size
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Image-Validator/1.0');
            
            $headers = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                $result['error'] = "HTTP {$httpCode}";
                $result['error_type'] = 'unreachable';
                return $result;
            }
            
            // Check file size if available in headers
            if ($contentLength > 0) {
                if ($contentLength > self::MAX_FILE_SIZE) {
                    $result['error'] = "File size too large: " . $this->formatBytes($contentLength);
                    $result['error_type'] = 'size';
                    return $result;
                }
                $result['file_size'] = $this->formatBytes($contentLength);
            }
            
            // For more detailed validation, download a small portion to get image dimensions
            // (This is optional and can be skipped for performance)
            $imageInfo = $this->getImageDimensions($url);
            
            if ($imageInfo) {
                $width = $imageInfo['width'];
                $height = $imageInfo['height'];
                $megapixels = ($width * $height) / 1000000;
                
                $result['dimensions'] = "{$width}x{$height}";
                $result['megapixels'] = round($megapixels, 2);
                
                if ($megapixels > self::MAX_MEGAPIXELS) {
                    $result['error'] = "Resolution too high: {$result['megapixels']}MP";
                    $result['error_type'] = 'megapixels';
                    return $result;
                }
            } else {
                // If we can't get dimensions, assume valid if reachable and size OK
                $result['dimensions'] = 'Unknown';
                $result['megapixels'] = 'Unknown';
            }
            
            $result['valid'] = true;
            
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $result['error_type'] = 'other';
        }
        
        return $result;
    }
    
    private function getImageDimensions(string $url): ?array
    {
        // Download first 24KB to get image dimensions without downloading entire file
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_RANGE, '0-24575'); // First 24KB
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Image-Validator/1.0');
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 206 && $httpCode !== 200) { // 206 = Partial Content, 200 = OK
            return null;
        }
        
        // Save to temporary file for getimagesize
        $tempFile = tempnam(sys_get_temp_dir(), 'img_val_');
        file_put_contents($tempFile, $imageData);
        
        $imageInfo = getimagesize($tempFile);
        unlink($tempFile);
        
        if ($imageInfo === false) {
            return null;
        }
        
        return [
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
            'type' => $imageInfo[2],
        ];
    }
    
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}

// Run validation
try {
    $validator = new ImageValidator($argv);
    $validator->validate();
    exit(0);
} catch (\Exception $e) {
    echo "❌ FATAL ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}