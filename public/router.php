<?php
/**
 * Router for PHP Built-in Development Server
 * This file is used with: php -S localhost:PORT router.php
 */

$requestUri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

// Check if API request
if (strpos($requestUri, '/api/') === 0) {
    // Route to API file
    $apiFile = __DIR__ . $requestUri;
    
    if (file_exists($apiFile) && is_file($apiFile)) {
        include $apiFile;
        return true;
    } else {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'API endpoint not found']);
        return false;
    }
}

// Otherwise, serve normally
return false;
