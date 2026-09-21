<?php
/**
 * Direct validation API endpoint
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../config.php';

function validateImageUrl(string $url): array
{
    $result = [
        'valid' => false,
        'error_type' => 'unknown',
        'message' => ''
    ];
    
    try {
        // Get image headers
        $headers = get_headers($url, 1);
        
        if (!$headers || strpos($headers[0], '200') === false) {
            $result['error_type'] = 'unreachable';
            $result['message'] = 'Image not accessible';
            return $result;
        }
        
        // Get content type
        $contentType = '';
        if (isset($headers['Content-Type'])) {
            $contentType = is_array($headers['Content-Type']) 
                ? end($headers['Content-Type']) 
                : $headers['Content-Type'];
        }
        
        if (strpos($contentType, 'image/') !== 0) {
            $result['error_type'] = 'format';
            $result['message'] = 'Not an image file';
            return $result;
        }
        
        // Get content length
        $contentLength = 0;
        if (isset($headers['Content-Length'])) {
            $contentLength = is_array($headers['Content-Length']) 
                ? (int)end($headers['Content-Length']) 
                : (int)$headers['Content-Length'];
        }
        
        // Check file size (20MB limit)
        $maxSize = 20 * 1024 * 1024; // 20MB
        if ($contentLength > 0 && $contentLength > $maxSize) {
            $result['error_type'] = 'size';
            $result['message'] = 'File too large (' . round($contentLength / 1024 / 1024, 2) . 'MB)';
            return $result;
        }
        
        $result['valid'] = true;
        $result['message'] = 'Valid image';
        return $result;
        
    } catch (\Exception $e) {
        $result['error_type'] = 'error';
        $result['message'] = $e->getMessage();
        return $result;
    }
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $sampleSize = $input['sample_size'] ?? 20;
    
    $csvPath = __DIR__ . '/../output/image_processing/image_mapping.csv';
    
    if (!file_exists($csvPath)) {
        echo json_encode([
            'success' => false,
            'message' => 'Image mapping CSV not found'
        ]);
        exit;
    }
    
    // Load mappings
    $mappings = [];
    if (($handle = fopen($csvPath, 'r')) !== false) {
        fgetcsv($handle); // Skip header
        
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 4) {
                $mappings[] = [
                    'product_id' => $row[0],
                    'variant_id' => $row[1],
                    'original_url' => $row[2],
                    'final_url' => $row[3]
                ];
            }
        }
        fclose($handle);
    }
    
    // Sample random mappings if requested
    if ($sampleSize > 0 && count($mappings) > $sampleSize) {
        shuffle($mappings);
        $mappings = array_slice($mappings, 0, $sampleSize);
    }
    
    $stats = [
        'total' => count($mappings),
        'valid' => 0,
        'invalid_size' => 0,
        'invalid_megapixels' => 0,
        'unreachable' => 0,
        'other_errors' => 0
    ];
    
    // Validate each image
    foreach ($mappings as $mapping) {
        $result = validateImageUrl($mapping['final_url']);
        
        if ($result['valid']) {
            $stats['valid']++;
        } else {
            switch ($result['error_type']) {
                case 'size':
                    $stats['invalid_size']++;
                    break;
                case 'megapixels':
                    $stats['invalid_megapixels']++;
                    break;
                case 'unreachable':
                    $stats['unreachable']++;
                    break;
                default:
                    $stats['other_errors']++;
                    break;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'sample_size' => count($mappings),
        'total_mappings' => count($mappings),
        'message' => "Validated {$stats['valid']}/{$stats['total']} images successfully"
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Validation error: ' . $e->getMessage()
    ]);
}
?>