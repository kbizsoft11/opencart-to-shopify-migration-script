<?php
/**
 * Migration API Router
 * Handles all API requests for the web-based migration tool
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Autoload classes
require_once __DIR__ . '/classes/ProcessManager.php';
require_once __DIR__ . '/classes/SSEStream.php';
require_once __DIR__ . '/../config.php';

use API\ProcessManager;
use API\SSEStream;

// Initialize
$dataDir = __DIR__ . '/../data';
$scriptPath = __DIR__ . '/../migrate.php';
$processManager = new ProcessManager($dataDir, $scriptPath);

// Route request
$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($path, PHP_URL_PATH);
$path = str_replace('/api', '', $path);

// Parse route
$parts = array_filter(explode('/', $path));
$parts = array_values($parts);

try {
    // Route handling
    if ($method === 'GET' && count($parts) >= 2 && $parts[0] === 'config' && $parts[1] === 'defaults') {
        handleGetConfig();
    } elseif ($method === 'POST' && count($parts) >= 2 && $parts[0] === 'config' && $parts[1] === 'test-db') {
        handleTestDatabase();
    } elseif ($method === 'POST' && count($parts) >= 2 && $parts[0] === 'config' && $parts[1] === 'test-shopify') {
        handleTestShopify();
    } elseif ($method === 'GET' && count($parts) >= 2 && $parts[0] === 'debug' && $parts[1] === 'metafields') {
        handleDebugMetafields();
    } elseif ($method === 'GET' && count($parts) >= 2 && $parts[0] === 'shopify' && $parts[1] === 'list-products') {
        handleListShopifyProducts();
    } elseif ($method === 'POST' && count($parts) >= 2 && $parts[0] === 'shopify' && $parts[1] === 'clear-test-products') {
        handleClearTestProducts();
    } elseif ($method === 'POST' && count($parts) >= 2 && $parts[0] === 'migration' && $parts[1] === 'start') {
        handleMigrationStart($processManager);
    } elseif ($method === 'GET' && count($parts) >= 3 && $parts[0] === 'migration' && $parts[1] === 'stream') {
        handleMigrationStream($processManager, $parts[2]);
    } elseif ($method === 'GET' && count($parts) >= 3 && $parts[0] === 'migration' && $parts[1] === 'status') {
        handleMigrationStatus($processManager, $parts[2]);
    } elseif ($method === 'POST' && count($parts) >= 3 && $parts[0] === 'migration' && $parts[1] === 'stop') {
        handleMigrationStop($processManager, $parts[2]);
    } elseif ($method === 'GET' && count($parts) >= 3 && $parts[0] === 'migration' && $parts[1] === 'results') {
        handleMigrationResults($processManager, $parts[2]);
    } elseif ($method === 'GET' && count($parts) >= 4 && $parts[0] === 'migration' && $parts[1] === 'report') {
        handleMigrationReport($processManager, $parts[2], $parts[3]);
    } elseif ($method === 'GET' && count($parts) >= 3 && $parts[0] === 'migration' && $parts[1] === 'download') {
        handleDownloadReport($parts[2]);
    } elseif ($method === 'GET' && count($parts) >= 2 && $parts[0] === 'images' && $parts[1] === 'mapping-status') {
        handleImageMappingStatus();
    } elseif ($method === 'POST' && count($parts) >= 2 && $parts[0] === 'images' && $parts[1] === 'validate') {
        handleImageValidation();
    } elseif ($method === 'GET' && count($parts) >= 2 && $parts[0] === 'images' && $parts[1] === 'download-csv') {
        handleDownloadImageCsv();
    } elseif ($method === 'GET' && count($parts) >= 2 && $parts[0] === 'images' && $parts[1] === 'mapping-preview') {
        handleImageMappingPreview();
    } else {
        http_response_code(404);
        jsonResponse(['error' => 'Endpoint not found']);
    }
} catch (\Exception $e) {
    error_log('API Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    jsonResponse(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}

/**
 * Get default configuration from .env
 */
function handleGetConfig(): void
{
    // Load config which reads .env file
    $configData = Config::load();
    
    $config = [
        'opencart_db_host' => $configData['opencart']['host'],
        'opencart_db_port' => $configData['opencart']['port'],
        'opencart_db_name' => $configData['opencart']['dbname'],
        'opencart_db_user' => $configData['opencart']['user'],
        'opencart_db_pass' => $configData['opencart']['pass'],
        'opencart_table_prefix' => $configData['opencart']['prefix'],
        'opencart_language_id' => (string)$configData['opencart']['language_id'],
        'opencart_store_id' => (string)$configData['opencart']['store_id'],
        'opencart_image_dir' => $configData['opencart']['image_dir'],
        'opencart_public_base_url' => $configData['opencart']['public_base_url'],
        'shopify_store_domain' => $configData['shopify']['store_domain'],
        'shopify_admin_access_token' => $configData['shopify']['access_token'],
    ];
    
    jsonResponse([
        'success' => true,
        'config' => $config,
    ]);
}

/**
 * Test OpenCart database connection
 */
function handleTestDatabase(): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    $config = [
        'host' => $input['opencart_db_host'] ?? '127.0.0.1',
        'port' => $input['opencart_db_port'] ?? '3306',
        'dbname' => $input['opencart_db_name'] ?? '',
        'user' => $input['opencart_db_user'] ?? 'root',
        'pass' => $input['opencart_db_pass'] ?? '',
    ];
    
    try {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        
        // Test query
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM oc_product");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'success' => true,
            'message' => 'Database connection successful',
            'product_count' => $result['count'],
        ]);
    } catch (\PDOException $e) {
        jsonResponse([
            'success' => false,
            'message' => 'Database connection failed: ' . $e->getMessage(),
        ]);
    }
}

/**
 * Test Shopify API connection
 */
function handleTestShopify(): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    $domain = $input['shopify_store_domain'] ?? '';
    $token = $input['shopify_admin_access_token'] ?? '';
    
    if (empty($domain) || empty($token)) {
        jsonResponse([
            'success' => false,
            'message' => 'Shopify credentials are required',
        ]);
        return;
    }
    
    try {
        // Test REST API connection
        $url = "https://{$domain}/admin/api/2026-01/shop.json";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'X-Shopify-Access-Token: ' . $token,
                'Content-Type: application/json',
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            jsonResponse([
                'success' => false,
                'message' => 'Connection error: ' . $curlError,
            ]);
            return;
        }
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $shop = $data['shop'] ?? [];
            
            jsonResponse([
                'success' => true,
                'message' => 'Shopify connection successful',
                'shop_name' => $shop['name'] ?? 'Unknown',
                'shop_email' => $shop['email'] ?? '',
                'shop_domain' => $shop['domain'] ?? '',
                'currency' => $shop['currency'] ?? '',
                'plan' => $shop['plan_name'] ?? '',
            ]);
        } elseif ($httpCode === 401) {
            jsonResponse([
                'success' => false,
                'message' => 'Authentication failed. Please check your access token.',
            ]);
        } elseif ($httpCode === 404) {
            jsonResponse([
                'success' => false,
                'message' => 'Store not found. Please check your store domain.',
            ]);
        } else {
            $errorData = json_decode($response, true);
            $errorMsg = $errorData['errors'] ?? "HTTP {$httpCode}";
            
            jsonResponse([
                'success' => false,
                'message' => "Shopify API error: {$errorMsg}",
            ]);
        }
    } catch (\Exception $e) {
        jsonResponse([
            'success' => false,
            'message' => 'Connection failed: ' . $e->getMessage(),
        ]);
    }
}

/**
 * List products in Shopify store for debugging
 */
function handleListShopifyProducts(): void
{
    // Load config to get Shopify credentials
    $config = Config::load();
    $domain = $config['shopify']['store_domain'];
    $token = $config['shopify']['access_token'];
    
    if (empty($domain) || empty($token)) {
        jsonResponse([
            'success' => false,
            'message' => 'Shopify credentials not configured',
        ]);
        return;
    }
    
    try {
        require_once __DIR__ . '/../src/Shopify/GraphQLClient.php';
        require_once __DIR__ . '/../src/Utils/ImageProcessor.php';
        
        $shopify = new \Shopify\GraphQLClient($domain, $token);
        
        // Get current metafield definitions first
        $definitionsQuery = '
            query {
                metafieldDefinitions(first: 50, ownerType: PRODUCT) {
                    edges {
                        node {
                            id
                            name
                            namespace
                            key
                            type {
                                name
                            }
                        }
                    }
                }
            }
        ';
        
        $definitionsResult = $shopify->execute($definitionsQuery);
        $definitions = $definitionsResult['data']['metafieldDefinitions']['edges'] ?? [];
        
        $definitionsList = [];
        foreach ($definitions as $edge) {
            $def = $edge['node'];
            $definitionsList[] = [
                'namespace' => $def['namespace'],
                'key' => $def['key'],
                'name' => $def['name'],
                'type' => $def['type']['name'],
            ];
        }
        
        // Then query products with their metafields
        $query = '
            query {
                products(first: 10) {
                    edges {
                        node {
                            id
                            handle
                            title
                            status
                            createdAt
                            metafields(first: 10) {
                                edges {
                                    node {
                                        namespace
                                        key
                                        value
                                        type
                                    }
                                }
                            }
                        }
                    }
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                }
            }
        ';
        
        $result = $shopify->execute($query);
        $products = $result['data']['products']['edges'] ?? [];
        $pageInfo = $result['data']['products']['pageInfo'] ?? [];
        
        $productList = [];
        foreach ($products as $edge) {
            $product = $edge['node'];
            $metafields = [];
            
            foreach ($product['metafields']['edges'] as $metafieldEdge) {
                $metafield = $metafieldEdge['node'];
                $metafields[] = [
                    'namespace' => $metafield['namespace'],
                    'key' => $metafield['key'],
                    'value' => $metafield['value'],
                    'type' => $metafield['type'],
                ];
            }
            
            $productList[] = [
                'id' => $product['id'],
                'title' => $product['title'],
                'handle' => $product['handle'],
                'status' => $product['status'],
                'createdAt' => $product['createdAt'],
                'metafields' => $metafields,
            ];
        }
        
        jsonResponse([
            'success' => true,
            'products' => $productList,
            'count' => count($productList),
            'hasNextPage' => $pageInfo['hasNextPage'] ?? false,
            'metafieldDefinitions' => $definitionsList,
            'note' => 'Shows first 10 products with their metafields and available metafield definitions',
        ]);
        
    } catch (\Exception $e) {
        error_log("List products error: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'message' => 'Failed to list products: ' . $e->getMessage(),
        ]);
    }
}

/**
 * Clear Shopify test products (products with opencart_product_id metafield)
 */
function handleClearTestProducts(): void
{
    // Load config to get Shopify credentials
    $config = Config::load();
    $domain = $config['shopify']['store_domain'];
    $token = $config['shopify']['access_token'];
    
    if (empty($domain) || empty($token)) {
        jsonResponse([
            'success' => false,
            'message' => 'Shopify credentials not configured',
        ]);
        return;
    }
    
    try {
        require_once __DIR__ . '/../src/Shopify/GraphQLClient.php';
        require_once __DIR__ . '/../src/Utils/ImageProcessor.php';
        
        $shopify = new \Shopify\GraphQLClient($domain, $token);
        
        // First try to query with metafield filtering
        $query = '
            query {
                products(first: 250, query: "metafields.custom.opencart_product_id:*") {
                    edges {
                        node {
                            id
                            title
                            handle
                        }
                    }
                }
            }
        ';
        
        $result = $shopify->execute($query);
        
        $products = $result['data']['products']['edges'] ?? [];
        
        // If metafield filtering didn't work, fallback to getting all products and filtering client-side
        if (empty($products)) {
            error_log("Metafield filtering returned no results, trying client-side filtering...");
            
            $allProductsQuery = '
                query {
                    products(first: 250) {
                        edges {
                            node {
                                id
                                title
                                handle
                                metafield(namespace: "custom", key: "opencart_product_id") {
                                    value
                                }
                            }
                        }
                    }
                }
            ';
            
            $allResult = $shopify->execute($allProductsQuery);
            $allProducts = $allResult['data']['products']['edges'] ?? [];
            
            // Filter products that have the opencart_product_id metafield
            foreach ($allProducts as $edge) {
                if (!empty($edge['node']['metafield']['value'])) {
                    $products[] = [
                        'node' => [
                            'id' => $edge['node']['id'],
                            'title' => $edge['node']['title'],
                            'handle' => $edge['node']['handle'],
                        ]
                    ];
                }
            }
        }
        
        if (empty($products)) {
            jsonResponse([
                'success' => true,
                'deleted_count' => 0,
                'message' => 'No test products found to delete',
                'errors' => [],
            ]);
            return;
        }
        
        $deletedCount = 0;
        $errors = [];
        
        foreach ($products as $edge) {
            $productId = $edge['node']['id'];
            $title = $edge['node']['title'];
            
            try {
                // Delete product
                $deleteMutation = '
                    mutation productDelete($input: ProductDeleteInput!) {
                        productDelete(input: $input) {
                            deletedProductId
                            userErrors {
                                field
                                message
                            }
                        }
                    }
                ';
                
                $variables = [
                    'input' => [
                        'id' => $productId
                    ]
                ];
                
                $deleteResult = $shopify->execute($deleteMutation, $variables);
                
                if (isset($deleteResult['data']['productDelete']['userErrors']) && 
                          !empty($deleteResult['data']['productDelete']['userErrors'])) {
                    $errors[] = "Error deleting {$title}: " . json_encode($deleteResult['data']['productDelete']['userErrors']);
                } elseif (!empty($deleteResult['data']['productDelete']['deletedProductId'])) {
                    $deletedCount++;
                    error_log("Deleted product: {$title}");
                } else {
                    $errors[] = "Unknown error deleting {$title}";
                }
                
            } catch (\Exception $e) {
                $errors[] = "Exception deleting {$title}: " . $e->getMessage();
                error_log("Exception deleting product {$title}: " . $e->getMessage());
            }
            
            // Add small delay between deletes to avoid rate limiting
            usleep(100000); // 100ms
        }
        
        jsonResponse([
            'success' => true,
            'deleted_count' => $deletedCount,
            'total_found' => count($products),
            'message' => "Found " . count($products) . " test products, successfully deleted {$deletedCount}",
            'errors' => $errors,
        ]);
        
    } catch (\Exception $e) {
        error_log("Clear products error: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'message' => 'Failed to clear products: ' . $e->getMessage(),
        ]);
    }
}

/**
 * Start migration process
 */
function handleMigrationStart(ProcessManager $pm): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    $config = $input['config'] ?? [];
    $args = $input['args'] ?? [];
    
    // Validate required fields
    if (empty($config['opencart_db_name'])) {
        jsonResponse(['error' => 'Database name is required']);
        return;
    }
    
    try {
        $processId = $pm->start($config, $args);
        
        jsonResponse([
            'success' => true,
            'process_id' => $processId,
            'message' => 'Migration started',
        ]);
    } catch (\Exception $e) {
        jsonResponse([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * Stream migration output (SSE)
 */
function handleMigrationStream(ProcessManager $pm, string $processId): void
{
    // Disable ALL output buffering
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    $stream = new SSEStream();
    
    // Send immediate connection confirmation
    $stream->message('Stream connected, checking for output...', 'info');
    
    $processDir = dirname(__FILE__) . '/../data/processes/' . $processId;
    $outLog = $processDir . '/output.log';
    
    // Check process status FIRST
    $status = $pm->getStatus($processId);
    
    // If process already completed, read entire file at once
    if ($status['status'] !== 'running') {
        $stream->message('Process already completed, reading full output...', 'info');
        
        if (!file_exists($outLog)) {
            $stream->error('Output log not found');
            return;
        }
        
        // Read all lines and send them with throttling
        $lines = file($outLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lineCount = 0;
        $batchSize = 50; // Send 50 lines at a time
        $batch = [];
        
        foreach ($lines as $line) {
            $lineCount++;
            $batch[] = $line;
            
            // Send batch every 50 lines
            if (count($batch) >= $batchSize) {
                foreach ($batch as $batchLine) {
                    $stream->parseAndSend($batchLine);
                }
                $batch = [];
                usleep(50000); // 50ms pause between batches to allow browser to process
            }
        }
        
        // Send remaining lines
        foreach ($batch as $batchLine) {
            $stream->parseAndSend($batchLine);
        }
        
        $stream->message("Read $lineCount lines from completed process", 'info');
        
        if ($status['status'] === 'completed') {
            $results = $pm->getResults($processId);
            $stream->complete($results['summary'] ?? []);
        } elseif ($status['status'] === 'failed') {
            $stream->error('Migration failed');
        }
        
        return;
    }
    
    // Process is still running - stream in real-time
    $stream->message('Process is running, streaming in real-time...', 'info');
    
    // Wait for file to be created (max 10 seconds)
    $waitTime = 0;
    while (!file_exists($outLog) && $waitTime < 10) {
        usleep(500000); // 0.5 second
        $waitTime += 0.5;
        $stream->heartbeat();
    }
    
    if (!file_exists($outLog)) {
        $stream->error('Output log not found at: ' . $outLog);
        return;
    }
    
    $stream->message('Output log found, starting real-time stream...', 'info');
    
    // Open file for reading
    $fp = fopen($outLog, 'r');
    if (!$fp) {
        $stream->error('Cannot open output log');
        return;
    }
    
    $lastHeartbeat = time();
    $lineCount = 0;
    $emptyReads = 0;
    $maxEmptyReads = 600; // 60 seconds
    
    while ($stream->isActive()) {
        $line = fgets($fp);
        
        if ($line !== false) {
            $emptyReads = 0;
            $lineCount++;
            
            // Parse and send line
            $stream->parseAndSend(trim($line));
        } else {
            // No more data available
            $emptyReads++;
            
            // Check if process is still running
            $status = $pm->getStatus($processId);
            
            if ($status['status'] !== 'running') {
                // Process finished - read remaining lines
                $stream->message("Process completed, reading remaining output...", 'info');
                
                while (($line = fgets($fp)) !== false) {
                    $lineCount++;
                    $stream->parseAndSend(trim($line));
                }
                
                fclose($fp);
                
                $stream->message("Finished reading $lineCount lines", 'info');
                
                if ($status['status'] === 'completed') {
                    $results = $pm->getResults($processId);
                    $stream->complete($results['summary'] ?? []);
                } elseif ($status['status'] === 'failed') {
                    $stream->error('Migration failed');
                }
                break;
            }
            
            if ($emptyReads > $maxEmptyReads) {
                // Timeout
                fclose($fp);
                $stream->error("Stream timeout - no output received (read $lineCount lines)");
                break;
            }
            
            // Wait before next read
            usleep(100000); // 100ms
            clearstatcache();
            
            // Send heartbeat every 15 seconds
            if (time() - $lastHeartbeat > 15) {
                $stream->heartbeat();
                $lastHeartbeat = time();
            }
        }
    }
    
    if (isset($fp) && is_resource($fp)) {
        fclose($fp);
    }
}

/**
 * Get migration status with progress
 */
function handleMigrationStatus(ProcessManager $pm, string $processId): void
{
    $status = $pm->getStatus($processId);
    
    // Add recent logs from output file
    $processDir = dirname(__FILE__) . '/../data/processes/' . $processId;
    $outLog = $processDir . '/output.log';
    
    $status['recent_logs'] = [];
    $status['total_lines'] = 0;
    
    if (file_exists($outLog)) {
        $lines = file($outLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $totalLines = count($lines);
        $status['total_lines'] = $totalLines;
        
        // Get last 50 lines
        $recentLines = array_slice($lines, -50);
        
        foreach ($recentLines as $line) {
            $data = json_decode($line, true);
            if ($data && isset($data['type'])) {
                // Only send progress and important log messages
                if ($data['type'] === 'progress') {
                    $status['progress'] = $data;
                } else if ($data['type'] === 'log' && ($data['level'] !== 'info' || strpos($data['message'], '✓') === 0 || strpos($data['message'], '⚠') === 0)) {
                    $status['recent_logs'][] = $data;
                }
            }
        }
    }
    
    jsonResponse($status);
}

/**
 * Stop migration process
 */
function handleMigrationStop(ProcessManager $pm, string $processId): void
{
    $success = $pm->stop($processId);
    
    jsonResponse([
        'success' => $success,
        'message' => $success ? 'Process stopped' : 'Failed to stop process',
    ]);
}

/**
 * Get migration results
 */
function handleMigrationResults(ProcessManager $pm, string $processId): void
{
    $results = $pm->getResults($processId);
    jsonResponse($results);
}

/**
 * Get specific report data
 */
function handleMigrationReport(ProcessManager $pm, string $processId, string $reportType): void
{
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    
    try {
        $data = $pm->readCsvData($processId, $reportType, $limit);
        jsonResponse([
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ]);
    } catch (\Exception $e) {
        jsonResponse([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * Download report file
 */
function handleDownloadReport(string $filename): void
{
    $outputDir = __DIR__ . '/../output/reports';
    $filepath = $outputDir . '/' . basename($filename);
    
    if (!file_exists($filepath)) {
        http_response_code(404);
        echo 'File not found';
        return;
    }
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($filepath));
    
    readfile($filepath);
}

/**
 * Send JSON response with proper error handling
 */
function jsonResponse(array $data): void
{
    // Ensure we have clean output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers first
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Validate data can be JSON encoded
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($json === false) {
        // JSON encoding failed
        $error = json_last_error_msg();
        error_log("JSON encoding failed: " . $error);
        
        $fallback = json_encode([
            'success' => false,
            'error' => 'JSON encoding failed: ' . $error,
            'original_error' => 'Data could not be encoded to JSON'
        ], JSON_PRETTY_PRINT);
        
        echo $fallback ?: '{"success":false,"error":"Critical JSON encoding failure"}';
        return;
    }
    
    echo $json;
    
    // Ensure output is flushed
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }
}

/**
 * Debug metafield definitions and create missing ones
 */
function handleDebugMetafields(): void
{
    // Load config to get Shopify credentials
    $config = Config::load();
    $domain = $config['shopify']['store_domain'];
    $token = $config['shopify']['access_token'];
    
    if (empty($domain) || empty($token)) {
        jsonResponse([
            'success' => false,
            'message' => 'Shopify credentials not configured',
        ]);
        return;
    }
    
    try {
        require_once __DIR__ . '/../src/Shopify/GraphQLClient.php';
        require_once __DIR__ . '/../src/Utils/ImageProcessor.php';
        
        $shopify = new \Shopify\GraphQLClient($domain, $token);
        
        // Get current metafield definitions
        $query = '
            query {
                metafieldDefinitions(first: 50, ownerType: PRODUCT) {
                    edges {
                        node {
                            id
                            name
                            namespace
                            key
                            type {
                                name
                            }
                        }
                    }
                }
            }
        ';
        
        $result = $shopify->execute($query);
        $definitions = $result['data']['metafieldDefinitions']['edges'] ?? [];
        
        $definitionsList = [];
        $opencartDefinitionExists = false;
        
        foreach ($definitions as $edge) {
            $def = $edge['node'];
            $definitionsList[] = [
                'id' => $def['id'],
                'namespace' => $def['namespace'],
                'key' => $def['key'],
                'name' => $def['name'],
                'type' => $def['type']['name'],
            ];
            
            if ($def['namespace'] === 'custom' && $def['key'] === 'opencart_product_id') {
                $opencartDefinitionExists = true;
            }
        }
        
        $message = "Found " . count($definitionsList) . " metafield definitions.";
        $actions = [];
        
        // Try to create the opencart_product_id definition if missing
        if (!$opencartDefinitionExists) {
            $message .= " Creating opencart_product_id definition with filtering enabled...";
            
            try {
                $created = $shopify->ensureMetafieldDefinition(
                    'custom',
                    'opencart_product_id',
                    'OpenCart Product ID',
                    'single_line_text_field'
                );
                
                if ($created) {
                    $message .= " SUCCESS! Definition created.";
                    $actions[] = "Created opencart_product_id metafield definition";
                } else {
                    $message .= " FAILED to create definition.";
                    $actions[] = "Failed to create opencart_product_id metafield definition";
                }
                
            } catch (\Exception $e) {
                $message .= " ERROR: " . $e->getMessage();
                $actions[] = "Error creating definition: " . $e->getMessage();
            }
            
        } else {
            $message .= " opencart_product_id definition already exists.";
            $actions[] = "opencart_product_id definition already exists";
        }
        
        // Test if metafield filtering actually works
        try {
            $testQuery = '
                query {
                    products(first: 1, query: "metafields.custom.opencart_product_id:*") {
                        edges {
                            node {
                                id
                                title
                            }
                        }
                    }
                }
            ';
            
            $testResult = $shopify->execute($testQuery);
            $testProducts = $testResult['data']['products']['edges'] ?? [];
            
            if (count($testProducts) > 0) {
                $message .= " Metafield filtering is working (found " . count($testProducts) . " products).";
                $actions[] = "Metafield filtering is working correctly";
            } else {
                $message .= " Metafield filtering returned no results (may not be enabled yet).";
                $actions[] = "Metafield filtering may not be enabled yet";
            }
            
        } catch (\Exception $e) {
            $message .= " Could not test metafield filtering: " . $e->getMessage();
            $actions[] = "Could not test metafield filtering: " . $e->getMessage();
        }
        
        jsonResponse([
            'success' => true,
            'message' => $message,
            'definitions' => $definitionsList,
            'opencart_definition_exists' => $opencartDefinitionExists,
            'actions_taken' => $actions,
            'total_definitions' => count($definitionsList),
        ]);
        
    } catch (\Exception $e) {
        error_log("Debug metafields error: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'message' => 'Failed to debug metafields: ' . $e->getMessage(),
            'error_details' => $e->getTraceAsString(),
        ]);
    }
}

/**
 * Check image mapping CSV status
 */
function handleImageMappingStatus(): void
{
    $csvPath = __DIR__ . '/../output/image_processing/image_mapping.csv';
    
    if (!file_exists($csvPath)) {
        jsonResponse([
            'success' => true,
            'csv_exists' => false,
            'mapping_count' => 0,
            'last_updated' => null,
            'message' => 'No image mapping CSV found'
        ]);
        return;
    }
    
    try {
        $lastUpdated = filemtime($csvPath);
        $mappingCount = 0;
        
        if (($handle = fopen($csvPath, 'r')) !== false) {
            // Skip header
            fgetcsv($handle);
            
            // Count data rows
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) >= 4) {
                    $mappingCount++;
                }
            }
            fclose($handle);
        }
        
        jsonResponse([
            'success' => true,
            'csv_exists' => true,
            'mapping_count' => $mappingCount,
            'last_updated' => date('c', $lastUpdated),
            'file_size' => filesize($csvPath),
            'message' => "Found {$mappingCount} image mappings"
        ]);
        
    } catch (\Exception $e) {
        jsonResponse([
            'success' => false,
            'message' => 'Error reading CSV: ' . $e->getMessage()
        ]);
    }
}

/**
 * Validate processed images
 */
function handleImageValidation(): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    $sampleSize = $input['sample_size'] ?? 20;
    
    $csvPath = __DIR__ . '/../output/image_processing/image_mapping.csv';
    
    if (!file_exists($csvPath)) {
        jsonResponse([
            'success' => false,
            'message' => 'Image mapping CSV not found'
        ]);
        return;
    }
    
    try {
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
        
        jsonResponse([
            'success' => true,
            'stats' => $stats,
            'sample_size' => count($mappings),
            'total_mappings' => count($mappings),
            'message' => "Validated {$stats['valid']}/{$stats['total']} images successfully"
        ]);
        
    } catch (\Exception $e) {
        jsonResponse([
            'success' => false,
            'message' => 'Validation error: ' . $e->getMessage()
        ]);
    }
}

/**
 * Validate a single image URL
 */
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
        
        // For more detailed validation, we'd need to download the image
        // For now, basic checks are sufficient
        $result['valid'] = true;
        $result['message'] = 'Valid image';
        return $result;
        
    } catch (\Exception $e) {
        $result['error_type'] = 'error';
        $result['message'] = $e->getMessage();
        return $result;
    }
}

/**
 * Download image mapping CSV
 */
function handleDownloadImageCsv(): void
{
    $csvPath = __DIR__ . '/../output/image_processing/image_mapping.csv';
    
    if (!file_exists($csvPath)) {
        http_response_code(404);
        jsonResponse([
            'success' => false,
            'message' => 'Image mapping CSV not found'
        ]);
        return;
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="image_mapping.csv"');
    header('Content-Length: ' . filesize($csvPath));
    header('Cache-Control: no-cache, must-revalidate');
    
    readfile($csvPath);
}

/**
 * Format bytes in human readable format
 */
function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Get image mapping preview data
 */
function handleImageMappingPreview(): void
{
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $csvPath = __DIR__ . '/../output/image_processing/image_mapping.csv';
    
    if (!file_exists($csvPath)) {
        jsonResponse([
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
        return;
    }
    
    try {
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
        
        jsonResponse([
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
            'message' => "Loaded {$count} of {$totalRecords} mapping entries"
        ]);
        
    } catch (\Exception $e) {
        jsonResponse([
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
}