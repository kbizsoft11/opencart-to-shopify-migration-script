<?php
/**
 * List Shopify products stub
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'success' => false,
    'message' => 'List products endpoint - requires Shopify GraphQL implementation'
]);
?>