<?php
/**
 * Configuration Manager
 * Loads configuration from environment variables or .env file
 */

class Config
{
    private static $config = null;
    
    public static function load(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        
        // Try to load .env file if it exists
        $envFile = __DIR__ . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    
                    if (!isset($_ENV[$key])) {
                        $_ENV[$key] = $value;
                        putenv("$key=$value");
                    }
                }
            }
        }
        
        self::$config = [
            // OpenCart Database
            'opencart' => [
                'host' => getenv('OPENCART_DB_HOST') ?: '127.0.0.1',
                'port' => getenv('OPENCART_DB_PORT') ?: '3306',
                'dbname' => getenv('OPENCART_DB_NAME') ?: 'rachel_opencart',
                'user' => getenv('OPENCART_DB_USER') ?: 'root',
                'pass' => getenv('OPENCART_DB_PASS') ?: '',
                'prefix' => getenv('OPENCART_TABLE_PREFIX') ?: 'oc_',
                'language_id' => (int)(getenv('OPENCART_LANGUAGE_ID') ?: 1),
                'store_id' => (int)(getenv('OPENCART_STORE_ID') ?: 0),
                'image_dir' => getenv('OPENCART_IMAGE_DIR') ?: '',
                'public_base_url' => getenv('OPENCART_PUBLIC_BASE_URL') ?: '',
            ],
            
            // Shopify
            'shopify' => [
                'store_domain' => getenv('SHOPIFY_STORE_DOMAIN') ?: '',
                'access_token' => getenv('SHOPIFY_ADMIN_ACCESS_TOKEN') ?: '',
                'api_version' => getenv('SHOPIFY_API_VERSION') ?: '2026-01',
            ],
            
            // Migration settings
            'migration' => [
                'batch_size' => 50,
                'max_shopify_options' => 3,
                'max_shopify_variants' => 2000, // Shopify supports up to 2000 variants
                'max_variants_per_api_call' => 100, // API batch limit
                'max_option_values' => 250, // Max values per single option
                'max_shopify_images' => 250,
            ],
        ];
        
        return self::$config;
    }
    
    public static function get(string $key, $default = null)
    {
        $config = self::load();
        
        $keys = explode('.', $key);
        $value = $config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    public static function getOpenCart(): array
    {
        return self::get('opencart');
    }
    
    public static function getShopify(): array
    {
        return self::get('shopify');
    }
    
    public static function hasShopifyCredentials(): bool
    {
        $shopify = self::getShopify();
        return !empty($shopify['store_domain']) && !empty($shopify['access_token']);
    }
}
