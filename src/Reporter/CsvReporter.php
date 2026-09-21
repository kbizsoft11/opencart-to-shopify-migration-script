<?php
/**
 * CSV and JSON Report Generator
 */

namespace Reporter;

class CsvReporter
{
    private string $outputDir;
    private array $reports = [];
    
    public function __construct(string $outputDir)
    {
        $this->outputDir = $outputDir;
        
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
    }
    
    /**
     * Add product to reports
     */
    public function addProduct(array $mappedProduct, string $status = 'DRY_RUN', ?string $shopifyId = null, ?string $error = null, array $imageWarnings = []): void
    {
        $product = $mappedProduct['product'];
        $ocProductId = $mappedProduct['opencart_product_id'];
        
        // Count total warnings including image warnings
        $totalWarnings = count($mappedProduct['warnings']) + count($imageWarnings);
        
        // Main product log
        $this->reports['products'][] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'opencart_product_id' => $ocProductId,
            'shopify_product_id' => $shopifyId ?? '',
            'title' => $product['title'],
            'handle' => $product['handle'],
            'status' => $status,
            'variant_count' => $mappedProduct['variant_count'],
            'option_count' => $mappedProduct['option_count'],
            'image_count' => count($product['images']),
            'warning_count' => $totalWarnings,
            'error' => $error ?? '',
        ];
        
        // Variants
        foreach ($product['variants'] as $idx => $variant) {
            $this->reports['variants'][] = [
                'opencart_product_id' => $ocProductId,
                'variant_index' => $idx + 1,
                'sku' => $variant['sku'] ?? '',
                'price' => $variant['price'],
                'inventory_quantity' => $variant['inventory_quantity'],
                'option1' => $variant['option1'] ?? '',
                'option2' => $variant['option2'] ?? '',
                'option3' => $variant['option3'] ?? '',
                'weight' => $variant['weight'] ?? '',
                'weight_unit' => $variant['weight_unit'] ?? '',
            ];
        }
        
        // Images with processing status
        foreach ($product['images'] as $idx => $image) {
            $this->reports['images'][] = [
                'opencart_product_id' => $ocProductId,
                'position' => $image['position'],
                'src' => $image['src'],
                'processing_status' => $this->getImageProcessingStatus($image['src'], $imageWarnings),
            ];
        }
        
        // Metafields
        foreach ($product['metafields'] as $metafield) {
            $this->reports['metafields'][] = [
                'opencart_product_id' => $ocProductId,
                'namespace' => $metafield['namespace'],
                'key' => $metafield['key'],
                'value' => $metafield['value'],
                'type' => $metafield['type'],
            ];
        }
        
        // SEO redirects
        if (!empty($mappedProduct['old_seo_url'])) {
            $this->reports['seo_redirects'][] = [
                'opencart_product_id' => $ocProductId,
                'old_url' => '/' . $mappedProduct['old_seo_url'],
                'new_url' => '/products/' . $product['handle'],
                'redirect_type' => '301',
            ];
        }
        
        // Original warnings
        foreach ($mappedProduct['warnings'] as $warning) {
            $this->reports['warnings'][] = [
                'opencart_product_id' => $ocProductId,
                'type' => 'mapping',
                'warning' => $warning,
            ];
        }
        
        // Image processing warnings
        foreach ($imageWarnings as $warning) {
            $this->reports['warnings'][] = [
                'opencart_product_id' => $ocProductId,
                'type' => 'image_processing',
                'warning' => $warning,
            ];
        }
        
        // Skipped options
        foreach ($mappedProduct['skipped_options'] as $skipped) {
            $this->reports['skipped_options'][] = [
                'opencart_product_id' => $ocProductId,
                'option_name' => $skipped['name'],
                'option_type' => $skipped['type'],
                'reason' => $skipped['reason'],
            ];
        }
    }
    
    /**
     * Write all reports to CSV files
     */
    public function writeReports(): array
    {
        $files = [];
        
        foreach ($this->reports as $reportName => $data) {
            if (empty($data)) {
                continue;
            }
            
            $filename = $this->outputDir . '/dry_run_' . $reportName . '.csv';
            $files[] = $filename;
            
            $fp = fopen($filename, 'w');
            
            // Write header
            fputcsv($fp, array_keys($data[0]));
            
            // Write data
            foreach ($data as $row) {
                fputcsv($fp, $row);
            }
            
            fclose($fp);
        }
        
        return $files;
    }
    
    /**
     * Write sample Shopify payloads to JSON
     */
    public function writeSamplePayloads(array $samples): string
    {
        $filename = $this->outputDir . '/shopify_payload_samples.json';
        
        file_put_contents(
            $filename,
            json_encode($samples, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        
        return $filename;
    }
    
    /**
     * Write summary report
     */
    public function writeSummary(array $summary): string
    {
        $filename = $this->outputDir . '/migration_summary.txt';
        
        $content = "=== MIGRATION SUMMARY ===\n";
        $content .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($summary as $key => $value) {
            $label = ucwords(str_replace('_', ' ', $key));
            $content .= sprintf("%-30s: %s\n", $label, $value);
        }
        
        file_put_contents($filename, $content);
        
        return $filename;
    }
    
    /**
     * Get image processing status based on warnings
     */
    private function getImageProcessingStatus(string $imagePath, array $imageWarnings): string
    {
        foreach ($imageWarnings as $warning) {
            if (strpos($warning, $imagePath) !== false) {
                return 'FAILED';
            }
        }
        return 'SUCCESS';
    }
    
    /**
     * Check if image file exists locally
     */
    private function checkImageExists(string $imagePath): string
    {
        // This is a placeholder - would need actual image directory configuration
        return 'UNKNOWN';
    }
    
    /**
     * Get report statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_products' => count($this->reports['products'] ?? []),
            'total_variants' => count($this->reports['variants'] ?? []),
            'total_images' => count($this->reports['images'] ?? []),
            'total_metafields' => count($this->reports['metafields'] ?? []),
            'total_redirects' => count($this->reports['seo_redirects'] ?? []),
            'total_warnings' => count($this->reports['warnings'] ?? []),
            'total_skipped_options' => count($this->reports['skipped_options'] ?? []),
        ];
    }
}
