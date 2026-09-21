<?php
/**
 * Direct mapping status API endpoint
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $csvPath = __DIR__ . '/../../output/image_processing/image_mapping.csv';
    
    if (!file_exists($csvPath)) {
        echo json_encode([
            'success' => true,
            'csv_exists' => false,
            'mapping_count' => 0,
            'file_size' => 0,
            'last_updated' => null
        ]);
        exit;
    }
    
    // Get file stats
    $fileStats = stat($csvPath);
    $lastUpdated = date('c', $fileStats['mtime']); // ISO 8601 format
    $fileSize = $fileStats['size'];
    
    // Count mappings (excluding header)
    $mappingCount = 0;
    if (($handle = fopen($csvPath, 'r')) !== false) {
        // Skip header
        fgetcsv($handle);
        
        // Count data rows
        while (fgetcsv($handle) !== false) {
            $mappingCount++;
        }
        fclose($handle);
    }
    
    echo json_encode([
        'success' => true,
        'csv_exists' => true,
        'mapping_count' => $mappingCount,
        'file_size' => $fileSize,
        'last_updated' => $lastUpdated
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error checking mapping status: ' . $e->getMessage()
    ]);
}
?>