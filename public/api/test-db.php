<?php
/**
 * Test OpenCart database connection
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $config = [
        'host' => $input['opencart_db_host'] ?? '127.0.0.1',
        'port' => $input['opencart_db_port'] ?? '3306',
        'dbname' => $input['opencart_db_name'] ?? '',
        'user' => $input['opencart_db_user'] ?? 'root',
        'pass' => $input['opencart_db_pass'] ?? '',
    ];
    
    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    
    // Test query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM oc_product");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Database connection successful',
        'product_count' => $result['count'],
    ]);
    
} catch (\PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage(),
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>