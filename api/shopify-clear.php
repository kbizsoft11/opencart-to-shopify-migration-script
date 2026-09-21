<?php
/**
 * Clear test products from Shopify stub
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

echo json_encode([
    'success' => false,
    'message' => 'Clear products endpoint - requires Shopify GraphQL implementation',
    'deleted_count' => 0
]);
?>