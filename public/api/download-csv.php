<?php
/**
 * Direct CSV download endpoint
 */

$csvPath = __DIR__ . '/../../output/image_processing/image_mapping.csv';

if (!file_exists($csvPath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Image mapping CSV not found'
    ]);
    exit;
}

// Set download headers
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="image_mapping.csv"');
header('Content-Length: ' . filesize($csvPath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Output the file
readfile($csvPath);
?>