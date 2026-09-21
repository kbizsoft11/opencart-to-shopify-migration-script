<?php

namespace Mapper;

use App\Utils\OversizedImageProcessor;

/**
 * Extended ProductMapper that uses CSV image mappings
 */
class ImageMappingProductMapper extends ProductMapper
{
    private ?array $imageMappings = null;
    private ?OversizedImageProcessor $imageProcessor = null;
    
    public function __construct(array $config = [], ?OversizedImageProcessor $imageProcessor = null)
    {
        parent::__construct($config);
        $this->imageProcessor = $imageProcessor;
        
        // Load image mappings if processor is provided
        if ($this->imageProcessor) {
            $this->imageMappings = $this->imageProcessor->loadImageMappingForMigration();
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
     * Override mapProduct to handle variant-specific images with mappings
     */
    public function mapProduct(array $ocProduct): array
    {
        // Call parent mapping first
        $mappedProduct = parent::mapProduct($ocProduct);
        
        // If we have image mappings, update variant images
        if ($this->imageMappings && isset($mappedProduct['product']['variants'])) {
            $mappedProduct['product']['variants'] = $this->mapVariantImages($mappedProduct['product']['variants'], $ocProduct);
        }
        
        return $mappedProduct;
    }
    
    /**
     * Map variant-specific images using CSV mappings
     */
    private function mapVariantImages(array $variants, array $ocProduct): array
    {
        foreach ($variants as &$variant) {
            // Find OpenCart variant data that corresponds to this Shopify variant
            $ocVariantImages = $this->findOpenCartVariantImages($variant, $ocProduct);
            
            if (!empty($ocVariantImages)) {
                $variantImageIds = [];
                
                foreach ($ocVariantImages as $imageUrl) {
                    $finalUrl = $this->getMappedImageUrl($imageUrl);
                    
                    // Find the image ID in the main product images
                    $imageId = $this->findImageIdInProduct($finalUrl, $variants[0]['product'] ?? []);
                    if ($imageId) {
                        $variantImageIds[] = $imageId;
                    }
                }
                
                if (!empty($variantImageIds)) {
                    // Assign images to this specific variant
                    $variant['image_id'] = $variantImageIds[0]; // Primary variant image
                    if (count($variantImageIds) > 1) {
                        $variant['image_ids'] = $variantImageIds; // All variant images
                    }
                }
            }
        }
        
        return $variants;
    }
    
    /**
     * Find OpenCart variant images that should be assigned to a Shopify variant
     */
    private function findOpenCartVariantImages(array $variant, array $ocProduct): array
    {
        $images = [];
        
        if (!isset($this->imageMappings['by_variant'])) {
            return $images;
        }
        
        // Look for variant-specific images based on option values
        if (!empty($ocProduct['option_values'])) {
            foreach ($ocProduct['option_values'] as $optionValue) {
                // Check if this option value corresponds to the variant
                if ($this->variantMatchesOptionValue($variant, $optionValue)) {
                    // Look for images mapped to this variant ID
                    $variantId = $optionValue['product_option_value_id'];
                    if (isset($this->imageMappings['by_variant'][$variantId])) {
                        foreach ($this->imageMappings['by_variant'][$variantId] as $mapping) {
                            $images[] = $mapping['original_url'];
                        }
                    }
                }
            }
        }
        
        return array_unique($images);
    }
    
    /**
     * Check if a Shopify variant matches an OpenCart option value
     */
    private function variantMatchesOptionValue(array $variant, array $optionValue): bool
    {
        // This is a simplified matching - in practice, you'd need more sophisticated logic
        // based on how your ProductMapper maps option values to variants
        
        if (empty($variant['option1']) || empty($optionValue['name'])) {
            return false;
        }
        
        // Simple name matching - customize based on your mapping logic
        return stripos($variant['option1'], $optionValue['name']) !== false ||
               stripos($variant['option2'] ?? '', $optionValue['name']) !== false ||
               stripos($variant['option3'] ?? '', $optionValue['name']) !== false;
    }
    
    /**
     * Find image ID within product images
     */
    private function findImageIdInProduct(string $imageUrl, array $product): ?int
    {
        if (empty($product['images'])) {
            return null;
        }
        
        foreach ($product['images'] as $image) {
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
        if (!$this->imageMappings || !$this->imageProcessor) {
            return $originalUrl;
        }
        
        return $this->imageProcessor->getMappedImageUrl($originalUrl, $this->imageMappings);
    }
    
    /**
     * Get statistics about image mapping usage
     */
    public function getImageMappingStats(): array
    {
        if (!$this->imageMappings) {
            return [
                'total_mappings' => 0,
                'by_product' => 0,
                'by_variant' => 0,
                'by_url' => 0,
            ];
        }
        
        return [
            'total_mappings' => count($this->imageMappings['by_url']),
            'by_product' => count($this->imageMappings['by_product']),
            'by_variant' => count($this->imageMappings['by_variant']),
            'by_url' => count($this->imageMappings['by_url']),
        ];
    }
}