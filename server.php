<?php
/**
 * Development Server Router
 * Routes requests for built-in PHP server
 * Serves from public/ directory, handles API routing
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove leading slash
$requestedPath = ltrim($uri, '/');

// API routes - serve from public/api/
if (strpos($requestedPath, 'api/') === 0) {
    $apiFile = __DIR__ . '/public/' . $requestedPath;
    
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

// Check for static files in public directory
$publicFile = __DIR__ . '/public' . $uri;

if ($uri !== '/' && file_exists($publicFile) && is_file($publicFile)) {
    // Serve static files with proper content types
    $ext = pathinfo($publicFile, PATHINFO_EXTENSION);
    $contentTypes = [
        'js' => 'application/javascript',
        'css' => 'text/css',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];
    
    if (isset($contentTypes[$ext])) {
        header('Content-Type: ' . $contentTypes[$ext]);
    }
    
    readfile($publicFile);
    exit;
}

// Serve index.html for all other routes
require __DIR__ . '/public/index.html';
