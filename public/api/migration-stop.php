<?php
/**
 * Stop migration process
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
    
    $processId = isset($_GET['id']) ? $_GET['id'] : null;
    
    if (!$processId) {
        echo json_encode(['error' => 'Process ID required']);
        exit;
    }
    
    $dataDir = __DIR__ . '/../../data';
    $scriptPath = __DIR__ . '/../../migrate.php';
    $processManager = new \API\ProcessManager($dataDir, $scriptPath);
    
    $success = $processManager->stop($processId);
    
    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Process stopped' : 'Failed to stop process',
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
?>