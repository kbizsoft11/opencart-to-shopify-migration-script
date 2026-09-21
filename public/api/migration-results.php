<?php
/**
 * Get migration results
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/../../api/classes/ProcessManager.php';
    
    $processId = isset($_GET['id']) ? $_GET['id'] : null;
    
    if (!$processId) {
        echo json_encode(['error' => 'Process ID required']);
        exit;
    }
    
    $dataDir = __DIR__ . '/../data';
    $scriptPath = __DIR__ . '/../migrate.php';
    $processManager = new \API\ProcessManager($dataDir, $scriptPath);
    
    $results = $processManager->getResults($processId);
    echo json_encode($results);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
?>