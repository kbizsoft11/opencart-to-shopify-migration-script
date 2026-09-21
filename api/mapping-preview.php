<?php
/**
 * Direct mapping preview API endpoint
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $csvPath = __DIR__ . '/../output/image_processing/image_mapping.csv';
    
    if (!file_exists($csvPath)) {
        echo json_encode([
            'success' => false,
            'message' => 'Image mapping CSV not found',
            'mappings' => [],
            'pagination' => [
                'current_page' => 1,
                'total_pages' => 0,
                'total_records' => 0,
                'per_page' => $limit,
                'has_next' => false,
                'has_prev' => false
            ]
        ]);
        exit;
    }
    
    $mappings = [];
    $totalRecords = 0;
    
    if (($handle = fopen($csvPath, 'r')) !== false) {
        $header = fgetcsv($handle); // Skip header
        
        // Count total records first
        while (fgetcsv($handle) !== false) {
            $totalRecords++;
        }
        
        // Reset file pointer and skip header again
        rewind($handle);
        fgetcsv($handle);
        
        // Skip to offset
        for ($i = 0; $i < $offset; $i++) {
            if (fgetcsv($handle) === false) break;
        }
        
        // Read limited records
        $count = 0;
        while (($row = fgetcsv($handle)) !== false && $count < $limit) {
            if (count($row) >= 4) {
                $mappings[] = [
                    'product_id' => $row[0],
                    'variant_id' => !empty($row[1]) ? $row[1] : '',
                    'original_url' => $row[2],
                    'final_url' => $row[3],
                    'final_size' => isset($row[4]) ? $row[4] : '',
                    'megapixels' => isset($row[5]) ? $row[5] : '',
                    'processed' => isset($row[6]) ? $row[6] : 'No'
                ];
                $count++;
            }
        }
        fclose($handle);
    }
    
    // Calculate pagination info
    $totalPages = $totalRecords > 0 ? ceil($totalRecords / $limit) : 0;
    $currentPage = floor($offset / $limit) + 1;
    
    echo json_encode([
        'success' => true,
        'mappings' => $mappings,
        'pagination' => [
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'total_records' => $totalRecords,
            'per_page' => $limit,
            'has_next' => $currentPage < $totalPages,
            'has_prev' => $currentPage > 1,
            'offset' => $offset
        ],
        'message' => "Loaded " . count($mappings) . " of {$totalRecords} mapping entries"
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error reading mapping data: ' . $e->getMessage(),
        'mappings' => [],
        'pagination' => [
            'current_page' => 1,
            'total_pages' => 0,
            'total_records' => 0,
            'per_page' => $limit,
            'has_next' => false,
            'has_prev' => false
        ]
    ]);
}
?>