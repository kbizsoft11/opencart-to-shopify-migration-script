<?php
/**
 * Test Shopify connection
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
    
    $domain = $input['shopify_store_domain'] ?? '';
    $token = $input['shopify_admin_access_token'] ?? '';
    
    if (empty($domain) || empty($token)) {
        echo json_encode([
            'success' => false,
            'message' => 'Shopify credentials are required',
        ]);
        exit;
    }
    
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
        echo json_encode([
            'success' => false,
            'message' => 'Connection error: ' . $curlError,
        ]);
        exit;
    }
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $shop = $data['shop'] ?? [];
        
        echo json_encode([
            'success' => true,
            'message' => 'Shopify connection successful',
            'shop_name' => $shop['name'] ?? 'Unknown',
            'shop_email' => $shop['email'] ?? '',
            'shop_domain' => $shop['domain'] ?? '',
            'currency' => $shop['currency'] ?? '',
            'plan' => $shop['plan_name'] ?? '',
        ]);
    } elseif ($httpCode === 401) {
        echo json_encode([
            'success' => false,
            'message' => 'Authentication failed. Please check your access token.',
        ]);
    } elseif ($httpCode === 404) {
        echo json_encode([
            'success' => false,
            'message' => 'Store not found. Please check your store domain.',
        ]);
    } else {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['errors'] ?? "HTTP {$httpCode}";
        
        echo json_encode([
            'success' => false,
            'message' => "Shopify API error: {$errorMsg}",
        ]);
    }
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Connection failed: ' . $e->getMessage(),
    ]);
}
?>