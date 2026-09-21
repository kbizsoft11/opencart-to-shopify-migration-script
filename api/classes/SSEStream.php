<?php
/**
 * Server-Sent Events (SSE) Stream Handler
 * Manages real-time streaming of CLI output to browser
 */

namespace API;

class SSEStream
{
    private bool $isActive = true;
    
    public function __construct()
    {
        // Set headers for SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering
        
        // Disable output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Prevent timeout
        set_time_limit(0);
        ignore_user_abort(true);
    }
    
    /**
     * Send an event to the client
     */
    public function send(string $event, array $data): void
    {
        if (!$this->isActive) {
            return;
        }
        
        // Format as SSE
        echo "event: {$event}\n";
        echo "data: " . json_encode($data) . "\n\n";
        
        // Flush immediately
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
        
        // Check if client disconnected
        if (connection_aborted()) {
            $this->isActive = false;
        }
    }
    
    /**
     * Send a message event
     */
    public function message(string $message, string $level = 'info'): void
    {
        $this->send('message', [
            'level' => $level,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }
    
    /**
     * Send progress update
     */
    public function progress(int $current, int $total, array $stats = []): void
    {
        $percentage = $total > 0 ? round(($current / $total) * 100, 1) : 0;
        
        $this->send('progress', [
            'current' => $current,
            'total' => $total,
            'percentage' => $percentage,
            'stats' => $stats,
        ]);
    }
    
    /**
     * Send completion event
     */
    public function complete(array $summary): void
    {
        $this->send('complete', $summary);
    }
    
    /**
     * Send error event
     */
    public function error(string $message): void
    {
        $this->send('error', [
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }
    
    /**
     * Send heartbeat to keep connection alive
     */
    public function heartbeat(): void
    {
        echo ": heartbeat\n\n";
        
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
    
    /**
     * Check if stream is still active
     */
    public function isActive(): bool
    {
        return $this->isActive && !connection_aborted();
    }
    
    /**
     * Parse JSONL line and send appropriate event
     */
    public function parseAndSend(string $line): void
    {
        if (empty($line)) {
            return;
        }
        
        // Try to parse as JSON
        $data = json_decode($line, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Not JSON, send as plain text message
            $this->message($line, 'info');
            return;
        }
        
        // Handle different event types
        if (isset($data['type'])) {
            switch ($data['type']) {
                case 'progress':
                    $this->progress(
                        $data['current'] ?? 0,
                        $data['total'] ?? 0,
                        $data['stats'] ?? []
                    );
                    break;
                    
                case 'log':
                    $this->message(
                        $data['message'] ?? '',
                        $data['level'] ?? 'info'
                    );
                    break;
                    
                case 'complete':
                    $this->complete($data['summary'] ?? []);
                    break;
                    
                case 'error':
                    $this->error($data['message'] ?? 'Unknown error');
                    break;
                    
                default:
                    $this->send($data['type'], $data);
            }
        } else {
            // Generic data event
            $this->send('data', $data);
        }
    }
}
