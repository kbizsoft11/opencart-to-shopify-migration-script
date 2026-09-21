<?php

namespace Mapper;

/**
 * ProductMapper that uses CSV file for image URL replacement
 */
class CsvImageProductMapper extends ProductMapper
{
    private ?array $imageUrlMappings = null;
    private string $csvPath;
    
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->csvPath = $config['image_csv_path'] ?? __DIR__ . '/../../output/image_urls.csv';
        $this->loadImageUrlMappings();
    }
    
    /**
     * Load image URL mappings from CSV
     */
    private function loadImageUrlMappings(): void
    {
        $this->imageUrlMappings = [
            'by_url' => [],
            'by_product' => [],
            'by_variant' => []
        ];
        
        if (!file_exists($this->csvPath)) {
            return;
        }
        
        if (($handle = fopen($this->csvPath, 'r')) !== false) {
            $header = fgetcsv($handle); // Skip header
            
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) >= 4) {
                    $productId = (int)$row[0];
                    $variantId = !empty($row[1]) ? (int)$row[1] : null;
                    $originalUrl = $row[2];
                    $replacementUrl = $row[3];
                    $imageType = $row[4] ?? 'unknown';
                    $status = $row[5] ?? 'pending';
                    
                    // Skip if no replacement URL or status is disabled
                    if (empty($replacementUrl) || $status === 'disabled') {
                        continue;
                    }
                    
                    // Map by URL for direct lookup
                    $this->imageUrlMappings['by_url'][$originalUrl] = $replacementUrl;
                    
                    // Map by product
                    if (!isset($this->imageUrlMappings['by_product'][$productId])) {
                        $this->imageUrlMappings['by_product'][$productId] = [];
                    }
                    $this->imageUrlMappings['by_product'][$productId][] = [
                        'original_url' => $originalUrl,
                        'replacement_url' => $replacementUrl,
                        'image_type' => $imageType,
                        'variant_id' => $variantId
                    ];
                    
                    // Map by variant if applicable
                    if ($variantId) {
                        if (!isset($this->imageUrlMappings['by_variant'][$variantId])) {
                            $this->imageUrlMappings['by_variant'][$variantId] = [];
                        }
                        $this->imageUrlMappings['by_variant'][$variantId][] = [
                            'original_url' => $originalUrl,
                            'replacement_url' => $replacementUrl,
                            'image_type' => $imageType
                        ];
                    }
                }
            }
            fclose($handle);
        }
    }
    
    /**
     * Override parent's mapImages method to use CSV mappings
     */
    protected function mapImages(string $mainImage, array $additionalImages): array
    {
        $images = [];
        
        // Add main image first
        if (!empty($mainImage)) {
            $imageUrl = $this->getImageUrl($mainImage);
            if ($imageUrl) {
                // Use mapped URL if available
                $finalUrl = $this->getMappedImageUrl($imageUrl);
                $images[] = [
                    'src' => $finalUrl,
                    'position' => 1,
                ];
            }
        }
        
        // Add additional images
        foreach ($additionalImages as $idx => $img) {
            if (!empty($img['image'])) {
                $imageUrl = $this->getImageUrl($img['image']);
                if ($imageUrl) {
                    // Use mapped URL if available
                    $finalUrl = $this->getMappedImageUrl($imageUrl);
                    $images[] = [
                        'src' => $finalUrl,
                        'position' => $idx + 2,
                    ];
                }
            }
        }
        
        return $images;
    }
    
    /**
     * Override mapProduct to handle variant-specific images with CSV mappings
     */
    public function mapProduct(array $ocProduct): array
    {
        // Call parent mapping first
        $mappedProduct = parent::mapProduct($ocProduct);
        
        // If we have image mappings, update variant images
        if ($this->imageUrlMappings && isset($mappedProduct['product']['variants'])) {
            $mappedProduct['product']['variants'] = $this->mapVariantImagesFromCsv($mappedProduct['product']['variants'], $ocProduct);
        }
        
        return $mappedProduct;
    }
    
    /**
     * Map variant-specific images using CSV mappings
     */
    private function mapVariantImagesFromCsv(array $variants, array $ocProduct): array
    {
        $productId = $ocProduct['product_id'];
        
        // Get all variant mappings for this product
        $productMappings = $this->imageUrlMappings['by_product'][$productId] ?? [];
        
        foreach ($variants as &$variant) {
            // Look for variant-specific images
            if (!empty($ocProduct['option_values'])) {
                foreach ($ocProduct['option_values'] as $optionValue) {
                    // Check if this option value corresponds to the variant and has an image
                    if (!empty($optionValue['image']) && $this->variantMatchesOptionValue($variant, $optionValue)) {
                        $originalImageUrl = $this->getImageUrl($optionValue['image']);
                        
                        if ($originalImageUrl) {
                            $mappedUrl = $this->getMappedImageUrl($originalImageUrl);
                            
                            // Find this image in the main product images and assign to variant
                            $imageId = $this->findImageIdInProductImages($mappedUrl, $mappedProduct['product']['images'] ?? []);
                            if ($imageId) {
                                $variant['image_id'] = $imageId;
                            }
                        }
                    }
                }
            }
        }
        
        return $variants;
    }
    
    /**
     * Check if a Shopify variant matches an OpenCart option value
     */
    private function variantMatchesOptionValue(array $variant, array $optionValue): bool
    {
        // Simple name matching - can be enhanced based on your mapping logic
        if (empty($variant['option1']) || empty($optionValue['name'])) {
            return false;
        }
        
        return stripos($variant['option1'], $optionValue['name']) !== false ||
               stripos($variant['option2'] ?? '', $optionValue['name']) !== false ||
               stripos($variant['option3'] ?? '', $optionValue['name']) !== false;
    }
    
    /**
     * Find image ID in product images array
     */
    private function findImageIdInProductImages(string $imageUrl, array $productImages): ?int
    {
        foreach ($productImages as $image) {
            if ($image['src'] === $imageUrl) {
                return $image['id'] ?? null;
            }
        }
        return null;
    }
    
    /**
     * Get mapped image URL or return original if no mapping exists
     */
    private function getMappedImageUrl(string $originalUrl): string
    {
        if (!$this->imageUrlMappings) {
            return $originalUrl;
        }
        
        return $this->imageUrlMappings['by_url'][$originalUrl] ?? $originalUrl;
    }
    
    /**
     * Get statistics about image URL mappings
     */
    public function getImageMappingStats(): array
    {
        if (!$this->imageUrlMappings) {
            return [
                'total_mappings' => 0,
                'csv_loaded' => false,
                'csv_path' => $this->csvPath,
                'csv_exists' => file_exists($this->csvPath)
            ];
        }
        
        return [
            'total_mappings' => count($this->imageUrlMappings['by_url']),
            'product_mappings' => count($this->imageUrlMappings['by_product']),
            'variant_mappings' => count($this->imageUrlMappings['by_variant']),
            'csv_loaded' => true,
            'csv_path' => $this->csvPath,
            'csv_exists' => file_exists($this->csvPath)
        ];
    }
}