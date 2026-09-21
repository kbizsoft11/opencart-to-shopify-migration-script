<?php
/**
 * Router for both API endpoints and processed images
 */

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$requestUri = $_SERVER['REQUEST_URI'];
$parsedUrl = parse_url($requestUri);
$path = $parsedUrl['path'];

// Remove leading slash
$requestedPath = ltrim($path, '/');

// Check if this is an API request
if (strpos($requestedPath, 'api/') === 0) {
    // Route to API file
    $apiFile = __DIR__ . '/' . $requestedPath;
    
    if (file_exists($apiFile) && is_file($apiFile)) {
        include $apiFile;
        exit;
    } else {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'API endpoint not found']);
        exit;
    }
}

// Handle processed images (original logic)
// Security: Only allow access to processed_images directory
if (!preg_match('#^processed_images/[a-zA-Z0-9_\-\.]+$#', $requestedPath)) {
    http_response_code(404);
    echo "File not found";
    exit;
}

$filePath = __DIR__ . '/' . $requestedPath;

if (!file_exists($filePath)) {
    http_response_code(404);
    echo "Image not found";
    exit;
}

// Determine MIME type based on file extension
$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg', 
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp'
];

$mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

// Set appropriate headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year

// Output the file
readfile($filePath);