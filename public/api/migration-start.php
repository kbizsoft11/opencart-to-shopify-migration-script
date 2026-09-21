<?php
/**
 * Start migration process
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
    require_once __DIR__ . '/../../api/classes/ProcessManager.php';
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $config = $input['config'] ?? [];
    $args = $input['args'] ?? [];
    
    // Validate required fields
    if (empty($config['opencart_db_name'])) {
        echo json_encode(['error' => 'Database name is required']);
        exit;
    }
    
    // Handle fresh start mode - delete CSV file if requested
    if (isset($args['resume']) && !$args['resume']) {
        // Fresh start mode - delete existing CSV to start over
        $csvPath = __DIR__ . '/../../output/image_processing/image_mapping.csv';
        if (file_exists($csvPath)) {
            unlink($csvPath);
        }
    }
    
    $dataDir = __DIR__ . '/../../data';
    $scriptPath = __DIR__ . '/../../migrate.php';
    $processManager = new \API\ProcessManager($dataDir, $scriptPath);
    
    $processId = $processManager->start($config, $args);
    
    echo json_encode([
        'success' => true,
        'process_id' => $processId,
        'message' => 'Migration started',
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
?>