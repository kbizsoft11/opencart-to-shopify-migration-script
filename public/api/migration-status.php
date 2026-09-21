<?php
/**
 * Get migration status
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
    
    $dataDir = __DIR__ . '/../../data';
    $scriptPath = __DIR__ . '/../../migrate.php';
    $processManager = new \API\ProcessManager($dataDir, $scriptPath);
    
    $status = $processManager->getStatus($processId);
    
    // Add recent logs from output file
    $processDir = $dataDir . '/processes/' . $processId;
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
                // Only send important log messages
                if ($data['type'] === 'progress' || ($data['type'] === 'log' && $data['level'] !== 'info')) {
                    $status['recent_logs'][] = $data;
                }
            }
        }
    }
    
    echo json_encode($status);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
?>