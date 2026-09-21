<?php
/**
 * Get migration report data
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/classes/ProcessManager.php';
    
    $processId = isset($_GET['id']) ? $_GET['id'] : null;
    $reportType = isset($_GET['type']) ? $_GET['type'] : null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    
    if (!$processId || !$reportType) {
        echo json_encode(['error' => 'Process ID and report type required']);
        exit;
    }
    
    $dataDir = __DIR__ . '/../data';
    $scriptPath = __DIR__ . '/../migrate.php';
    $processManager = new \API\ProcessManager($dataDir, $scriptPath);
    
    $data = $processManager->readCsvData($processId, $reportType, $limit);
    echo json_encode([
        'success' => true,
        'data' => $data,
        'count' => count($data),
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
?>