<?php
/**
 * Process Manager for Background CLI Execution
 * Handles spawning, tracking, and killing background migration processes
 */

namespace API;

class ProcessManager
{
    private string $dataDir;
    private string $scriptPath;
    
    public function __construct(string $dataDir, string $scriptPath)
    {
        $this->dataDir = $dataDir;
        $this->scriptPath = $scriptPath;
        
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
    }
    
    /**
     * Start a new migration process
     */
    public function start(array $config, array $args): string
    {
        // Generate unique process ID
        $processId = uniqid('migration_', true);
        
        // Create process data directory
        $processDir = $this->getProcessDir($processId);
        mkdir($processDir, 0755, true);
        
        // Build command with inline environment variables (Windows compatible)
        $command = $this->buildCommand($args, $config, $processDir);
        
        // Log file paths
        $outLog = $processDir . '/output.log';
        $errLog = $processDir . '/error.log';
        
        // Spawn process
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['file', $outLog, 'w'], // stdout
            2 => ['file', $errLog, 'w'], // stderr
        ];
        
        $process = proc_open($command, $descriptors, $pipes, dirname($this->scriptPath));
        
        if (!is_resource($process)) {
            throw new \Exception('Failed to start migration process');
        }
        
        // Close stdin
        fclose($pipes[0]);
        
        // Get process status
        $status = proc_get_status($process);
        $pid = $status['pid'];
        
        // Save process metadata
        $metadata = [
            'process_id' => $processId,
            'pid' => $pid,
            'command' => $command,
            'status' => 'running',
            'started_at' => date('Y-m-d H:i:s'),
            'config' => $this->sanitizeConfig($config),
            'args' => $args,
        ];
        
        file_put_contents(
            $processDir . '/metadata.json',
            json_encode($metadata, JSON_PRETTY_PRINT)
        );
        
        return $processId;
    }
    
    /**
     * Get process status
     */
    public function getStatus(string $processId): array
    {
        $processDir = $this->getProcessDir($processId);
        $metadataFile = $processDir . '/metadata.json';
        
        if (!file_exists($metadataFile)) {
            return ['status' => 'not_found'];
        }
        
        $metadata = json_decode(file_get_contents($metadataFile), true);
        
        // Check if process is still running
        if ($metadata['status'] === 'running') {
            $isRunning = $this->isProcessRunning($metadata['pid']);
            
            if (!$isRunning) {
                // Process finished, check if it was successful by looking at output
                $outLog = $processDir . '/output.log';
                $errLog = $processDir . '/error.log';
                
                // Check if there's a complete event in output
                $hasComplete = false;
                if (file_exists($outLog)) {
                    $lines = file($outLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    foreach ($lines as $line) {
                        if (strpos($line, '"type":"complete"') !== false) {
                            $hasComplete = true;
                            break;
                        }
                    }
                }
                
                // Only mark as failed if there are actual errors (not just warnings) and no complete event
                $hasFatalErrors = false;
                if (file_exists($errLog) && filesize($errLog) > 0) {
                    $errorContent = file_get_contents($errLog);
                    // Check for fatal errors or parse errors (not just warnings)
                    if (strpos($errorContent, 'Fatal error') !== false || 
                        strpos($errorContent, 'Parse error') !== false) {
                        $hasFatalErrors = true;
                    }
                }
                
                $metadata['status'] = ($hasFatalErrors && !$hasComplete) ? 'failed' : 'completed';
                $metadata['finished_at'] = date('Y-m-d H:i:s');
                
                file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));
            }
        }
        
        return $metadata;
    }
    
    /**
     * Stop a running process
     */
    public function stop(string $processId): bool
    {
        $status = $this->getStatus($processId);
        
        if ($status['status'] !== 'running') {
            return false;
        }
        
        $pid = $status['pid'];
        
        // Kill process (Windows compatible)
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec("taskkill /F /PID {$pid} 2>&1", $output, $returnVar);
        } else {
            exec("kill -9 {$pid} 2>&1", $output, $returnVar);
        }
        
        // Update metadata
        $processDir = $this->getProcessDir($processId);
        $metadataFile = $processDir . '/metadata.json';
        $metadata = json_decode(file_get_contents($metadataFile), true);
        $metadata['status'] = 'stopped';
        $metadata['stopped_at'] = date('Y-m-d H:i:s');
        
        file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));
        
        return $returnVar === 0;
    }
    
    /**
     * Get process output stream
     */
    public function getOutputStream(string $processId): ?\Generator
    {
        $outLog = $this->getProcessDir($processId) . '/output.log';
        
        // Wait for file to be created (max 10 seconds)
        $waitTime = 0;
        while (!file_exists($outLog) && $waitTime < 10) {
            usleep(500000); // 0.5 second
            $waitTime += 0.5;
        }
        
        if (!file_exists($outLog)) {
            return null;
        }
        
        // Stream file line by line
        $fp = fopen($outLog, 'r');
        
        if (!$fp) {
            return null;
        }
        
        // Seek to beginning
        fseek($fp, 0);
        
        $emptyReads = 0;
        $maxEmptyReads = 600; // 60 seconds with 100ms sleep
        
        while (true) {
            $line = fgets($fp);
            
            if ($line === false) {
                // Check if process is still running
                $status = $this->getStatus($processId);
                
                if ($status['status'] !== 'running') {
                    // Process finished, no more output
                    fclose($fp);
                    break;
                }
                
                $emptyReads++;
                if ($emptyReads > $maxEmptyReads) {
                    // Timeout after too many empty reads
                    fclose($fp);
                    break;
                }
                
                // Wait for more output
                usleep(100000); // 100ms
                clearstatcache();
                continue;
            }
            
            $emptyReads = 0; // Reset counter on successful read
            yield trim($line);
        }
    }
    
    /**
     * Update process progress in metadata
     */
    public function updateProgress(string $processId, array $progressData): void
    {
        $metadataFile = $this->getProcessDir($processId) . '/metadata.json';
        
        if (!file_exists($metadataFile)) {
            return;
        }
        
        $metadata = json_decode(file_get_contents($metadataFile), true);
        $metadata['progress'] = $progressData;
        $metadata['last_update'] = date('Y-m-d H:i:s');
        
        file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));
    }
    public function getResults(string $processId): array
    {
        $processDir = $this->getProcessDir($processId);
        $outputDir = dirname($this->scriptPath) . '/output/reports';
        
        $results = [
            'summary' => null,
            'reports' => [],
            'samples' => null,
        ];
        
        // Read summary
        $summaryFile = $outputDir . '/migration_summary.txt';
        if (file_exists($summaryFile)) {
            $results['summary'] = $this->parseSummary($summaryFile);
        }
        
        // List available reports
        $reports = [
            'products' => 'dry_run_products.csv',
            'variants' => 'dry_run_variants.csv',
            'images' => 'dry_run_images.csv',
            'metafields' => 'dry_run_metafields.csv',
            'seo_redirects' => 'dry_run_seo_redirects.csv',
            'warnings' => 'dry_run_warnings.csv',
            'skipped_options' => 'dry_run_skipped_options.csv',
        ];
        
        foreach ($reports as $key => $filename) {
            $filepath = $outputDir . '/' . $filename;
            if (file_exists($filepath)) {
                $results['reports'][$key] = [
                    'filename' => $filename,
                    'path' => $filepath,
                    'size' => filesize($filepath),
                    'rows' => $this->countCsvRows($filepath),
                ];
            }
        }
        
        // Read sample payloads
        $samplesFile = $outputDir . '/shopify_payload_samples.json';
        if (file_exists($samplesFile)) {
            $results['samples'] = json_decode(file_get_contents($samplesFile), true);
        }
        
        return $results;
    }
    
    /**
     * Read CSV data for dashboard
     */
    public function readCsvData(string $processId, string $reportType, int $limit = 100): array
    {
        $outputDir = dirname($this->scriptPath) . '/output/reports';
        
        $reportFiles = [
            'products' => 'dry_run_products.csv',
            'variants' => 'dry_run_variants.csv',
            'images' => 'dry_run_images.csv',
            'metafields' => 'dry_run_metafields.csv',
            'seo_redirects' => 'dry_run_seo_redirects.csv',
            'warnings' => 'dry_run_warnings.csv',
            'skipped_options' => 'dry_run_skipped_options.csv',
        ];
        
        if (!isset($reportFiles[$reportType])) {
            throw new \Exception('Invalid report type');
        }
        
        $filepath = $outputDir . '/' . $reportFiles[$reportType];
        
        if (!file_exists($filepath)) {
            return [];
        }
        
        $data = [];
        $fp = fopen($filepath, 'r');
        
        // Read header
        $header = fgetcsv($fp);
        
        // Read data rows
        $count = 0;
        while (($row = fgetcsv($fp)) !== false && $count < $limit) {
            $data[] = array_combine($header, $row);
            $count++;
        }
        
        fclose($fp);
        
        return $data;
    }
    
    /**
     * Build CLI command with inline environment variables
     */
    private function buildCommand(array $args, array $config, string $processDir): string
    {
        $phpBinary = PHP_BINARY;
        $script = $this->scriptPath;
        
        // For Windows, create a batch file with proper environment variables
        // This avoids issues with spaces and special characters
        $batchContent = "@echo off\r\n";
        
        foreach ($config as $key => $value) {
            $envKey = strtoupper($key);
            // Properly escape the value
            $escapedValue = str_replace('"', '""', $value);
            $batchContent .= "set \"{$envKey}={$escapedValue}\"\r\n";
        }
        
        // Choose the appropriate script based on mode
        $scriptToUse = $this->scriptPath;
        if (isset($args['mode']) && $args['mode'] === 'process-images') {
            // Use the dedicated image processing script
            $scriptToUse = dirname($this->scriptPath) . '/process_images.php';
        }
        
        // Build the PHP command
        $batchContent .= "\"{$phpBinary}\" \"{$scriptToUse}\"";
        
        // Add format flag for JSONL output
        $batchContent .= " --format=jsonl";
        
        // Handle different modes
        if (isset($args['mode'])) {
            switch ($args['mode']) {
                case 'send':
                    $batchContent .= " --send";
                    break;
                case 'process-images':
                    // For image processing script, add appropriate flags
                    $batchContent .= " --verbose";
                    // Always use JSONL format for web interface
                    $batchContent .= " --format=jsonl";
                    break;
                default:
                    $batchContent .= " --dry-run";
                    break;
            }
        } else {
            $batchContent .= " --dry-run";
        }
        
        // Add use-image-csv flag if enabled
        if (isset($args['use_image_csv']) && $args['use_image_csv']) {
            $batchContent .= " --use-image-csv";
        }
        
        // Add use-image-mapping flag if enabled (only for migration script)
        if (isset($args['use_image_mapping']) && $args['use_image_mapping'] && 
            (!isset($args['mode']) || $args['mode'] !== 'process-images')) {
            $batchContent .= " --use-image-mapping";
        }
        
        // Add resume flag for image processing
        if (isset($args['resume']) && $args['resume']) {
            $batchContent .= " --resume";
        }
        
        // Only add limit if it's greater than 0
        if (isset($args['limit']) && is_numeric($args['limit']) && $args['limit'] > 0) {
            $batchContent .= " --limit=" . (int)$args['limit'];
        }
        
        if (isset($args['product_id']) && is_numeric($args['product_id']) && $args['product_id'] > 0) {
            $batchContent .= " --product-id=" . (int)$args['product_id'];
        }
        
        if (isset($args['update_existing']) && $args['update_existing']) {
            $batchContent .= " --update-existing";
        }
        
        if (isset($args['verbose']) && $args['verbose']) {
            $batchContent .= " --verbose";
        }
        
        // Add status filter for image processing
        if (isset($args['status_filter']) && in_array($args['status_filter'], ['all', 'active', 'inactive'])) {
            $batchContent .= " --status-filter=" . $args['status_filter'];
        }
        
        // Write batch file
        $batchFile = $processDir . '/run.bat';
        file_put_contents($batchFile, $batchContent);
        
        // Return the batch file command
        return $batchFile;
    }
    
    /**
     * Write environment file
     */
    private function writeEnvFile(string $filepath, array $config): void
    {
        $lines = [];
        
        foreach ($config as $key => $value) {
            $lines[] = strtoupper($key) . "=" . $value;
        }
        
        file_put_contents($filepath, implode("\n", $lines));
    }
    
    /**
     * Sanitize config for storage (remove sensitive data)
     */
    private function sanitizeConfig(array $config): array
    {
        $sanitized = $config;
        
        if (isset($sanitized['opencart_db_pass'])) {
            $sanitized['opencart_db_pass'] = '***';
        }
        
        if (isset($sanitized['shopify_admin_access_token'])) {
            $token = $sanitized['shopify_admin_access_token'];
            $sanitized['shopify_admin_access_token'] = substr($token, 0, 10) . '***';
        }
        
        return $sanitized;
    }
    
    /**
     * Check if process is running
     */
    private function isProcessRunning(int $pid): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec("tasklist /FI \"PID eq {$pid}\" 2>&1", $output);
            return count($output) > 1 && strpos($output[1], (string)$pid) !== false;
        } else {
            return file_exists("/proc/{$pid}");
        }
    }
    
    /**
     * Get process directory
     */
    private function getProcessDir(string $processId): string
    {
        return $this->dataDir . '/processes/' . $processId;
    }
    
    /**
     * Parse summary file
     */
    private function parseSummary(string $filepath): array
    {
        $content = file_get_contents($filepath);
        $lines = explode("\n", $content);
        
        $summary = [];
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $key = trim(strtolower(str_replace(' ', '_', $key)));
                $value = trim($value);
                $summary[$key] = $value;
            }
        }
        
        return $summary;
    }
    
    /**
     * Count CSV rows
     */
    private function countCsvRows(string $filepath): int
    {
        $count = 0;
        $fp = fopen($filepath, 'r');
        
        // Skip header
        fgetcsv($fp);
        
        while (fgetcsv($fp) !== false) {
            $count++;
        }
        
        fclose($fp);
        
        return $count;
    }
}
