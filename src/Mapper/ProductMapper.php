<?php
/**
 * Maps OpenCart products to Shopify products
 */

namespace Mapper;

class ProductMapper
{
    private array $warnings = [];
    private array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_options' => 3,
            'max_variants' => 2000, // Shopify supports up to 2000 variants per product
            'max_variants_per_api_call' => 100, // API limit per request (handled by GraphQLClient)
            'max_option_values' => 250, // Maximum values per option
            'image_base_url' => '',
            'image_local_dir' => '',
        ], $config);
    }
    
    /**
     * Map complete OpenCart product to Shopify format
     */
    public function mapProduct(array $ocProduct): array
    {
        $this->warnings = [];
        
        $product = $ocProduct['product'];
        $description = $ocProduct['description'];
        $options = $ocProduct['options'];
        $optionValues = $ocProduct['option_values'];
        $images = $ocProduct['images'];
        $categories = $ocProduct['categories'];
        $manufacturer = $ocProduct['manufacturer'];
        $seoUrl = $ocProduct['seo_url'];
        
        $productId = $product['product_id'];
        
        // Generate Shopify handle
        $handle = $this->generateHandle($productId, $seoUrl, $description['name'] ?? '');
        
        // Map options and variants
        $variantMapping = $this->mapOptionsToVariants($productId, $options, $optionValues);
        
        // Build base product
        $shopifyProduct = [
            'title' => html_entity_decode($description['name'] ?? "Product {$productId}", ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'body_html' => $this->cleanDescription($description['description'] ?? ''),
            'vendor' => html_entity_decode($manufacturer['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'product_type' => $this->getProductType($categories),
            'tags' => $this->generateTags($categories, $description['tag'] ?? ''),
            'status' => $product['status'] == 1 ? 'active' : 'draft',
            'handle' => $handle,
            'metafields' => $this->generateMetafields($product, $description, $ocProduct['attributes']),
        ];
        
        // Add variants
        $shopifyProduct['variants'] = $this->generateVariants(
            $product,
            $variantMapping['variants'],
            $variantMapping['options']
        );
        
        // Add options
        if (!empty($variantMapping['options'])) {
            $shopifyProduct['options'] = $variantMapping['options'];
        }
        
        // Add images
        $shopifyProduct['images'] = $this->mapImages($product['image'], $images);
        
        // Add SEO fields
        if (!empty($description['meta_title'])) {
            $shopifyProduct['metafields'][] = [
                'namespace' => 'global',
                'key' => 'title_tag',
                'value' => $description['meta_title'],
                'type' => 'single_line_text_field',
            ];
        }
        
        if (!empty($description['meta_description'])) {
            $shopifyProduct['metafields'][] = [
                'namespace' => 'global',
                'key' => 'description_tag',
                'value' => $description['meta_description'],
                'type' => 'single_line_text_field',
            ];
        }
        
        return [
            'product' => $shopifyProduct,
            'warnings' => $this->warnings,
            'old_seo_url' => $seoUrl,
            'opencart_product_id' => $productId,
            'variant_count' => count($shopifyProduct['variants']),
            'option_count' => count($variantMapping['options']),
            'skipped_options' => $variantMapping['skipped_options'],
        ];
    }
    
    /**
     * Map OpenCart options to Shopify variants
     */
    private function mapOptionsToVariants(int $productId, array $options, array $optionValues): array
    {
        $variantOptions = [];
        $skippedOptions = [];
        
        // Classify options
        foreach ($options as $option) {
            $optionType = strtolower($option['option_type']);
            $optionName = $option['option_name'];
            
            // Determine if this should be a Shopify variant option
            $shouldBeVariant = in_array($optionType, ['select', 'radio', 'checkbox']);
            
            if ($shouldBeVariant && $option['required'] == 1) {
                $variantOptions[] = [
                    'id' => $option['product_option_id'],
                    'option_id' => $option['option_id'],
                    'name' => $this->sanitizeOptionName($optionName),
                    'type' => $optionType,
                ];
            } else {
                $skippedOptions[] = [
                    'name' => $this->sanitizeOptionName($optionName),
                    'type' => $optionType,
                    'reason' => $this->getSkipReason($optionType, $option['required']),
                ];
            }
        }
        
        // Limit to 3 options (Shopify limit)
        if (count($variantOptions) > $this->config['max_options']) {
            $this->warnings[] = "Product has " . count($variantOptions) . " variant options, but Shopify only supports {$this->config['max_options']}. Using first {$this->config['max_options']}.";
            
            $removed = array_slice($variantOptions, $this->config['max_options']);
            $variantOptions = array_slice($variantOptions, 0, $this->config['max_options']);
            
            foreach ($removed as $removedOption) {
                $skippedOptions[] = [
                    'name' => $this->sanitizeOptionName($removedOption['name']),
                    'type' => $removedOption['type'],
                    'reason' => 'Exceeds Shopify 3-option limit',
                ];
            }
        }
        
        // Group option values by option
        $optionValuesGrouped = [];
        foreach ($optionValues as $ov) {
            $productOptionId = $ov['product_option_id'];
            if (!isset($optionValuesGrouped[$productOptionId])) {
                $optionValuesGrouped[$productOptionId] = [];
            }
            $optionValuesGrouped[$productOptionId][] = $ov;
        }
        
        // Generate Shopify options
        $shopifyOptions = [];
        $optionValueMap = [];
        
        foreach ($variantOptions as $idx => $option) {
            $position = $idx + 1;
            $values = $optionValuesGrouped[$option['id']] ?? [];
            
            // Check if this option exceeds 250 values limit
            if (count($values) > $this->config['max_option_values']) {
                $this->warnings[] = "Option '{$option['name']}' has " . count($values) . " values, exceeding Shopify's limit of {$this->config['max_option_values']}. Only first {$this->config['max_option_values']} will be used. MANUAL REVIEW RECOMMENDED.";
                $values = array_slice($values, 0, $this->config['max_option_values']);
            }
            
            $shopifyOptions[] = [
                'name' => $this->sanitizeOptionName($option['name']),
                'position' => $position,
                'values' => array_values(array_unique(array_column($values, 'option_value_name'))),
            ];
            
            $optionValueMap[$option['id']] = [
                'position' => $position,
                'name' => $this->sanitizeOptionName($option['name']),
                'values' => $values,
            ];
        }
        
        // Generate variant combinations
        $variants = $this->generateVariantCombinations($optionValueMap, $productId);
        
        // Check variant limit (Shopify supports 2000 variants)
        if (count($variants) > $this->config['max_variants']) {
            $this->warnings[] = "Product would generate " . count($variants) . " variants, exceeding Shopify's limit of {$this->config['max_variants']}. MANUAL REVIEW REQUIRED - consider reducing options or option values.";
            // Truncate to 2000 variants
            $variants = array_slice($variants, 0, $this->config['max_variants']);
        } else if (count($variants) > 100) {
            $this->warnings[] = "Product has " . count($variants) . " variants. Will be uploaded in " . ceil(count($variants) / 100) . " batches using bulk variant creation API.";
        }
        
        return [
            'options' => $shopifyOptions,
            'variants' => $variants,
            'skipped_options' => $skippedOptions,
        ];
    }
    
    /**
     * Generate variant combinations from options
     */
    private function generateVariantCombinations(array $optionValueMap, int $productId): array
    {
        if (empty($optionValueMap)) {
            // No options, return single default variant
            return [[
                'option_values' => [],
                'price_modifier' => 0,
                'weight_modifier' => 0,
                'sku_suffix' => '',
            ]];
        }
        
        $variants = [[]];
        
        foreach ($optionValueMap as $productOptionId => $optionData) {
            $newVariants = [];
            
            foreach ($variants as $variant) {
                foreach ($optionData['values'] as $value) {
                    // Skip empty option values
                    $optionValueName = $value['option_value_name'] ?? '';
                    if (empty($optionValueName)) {
                        continue; // Skip this value, don't create a variant for it
                    }
                    
                    $newVariant = $variant;
                    $newVariant['option_values'][] = [
                        'option_name' => $optionData['name'],
                        'option_value' => $optionValueName,
                        'position' => $optionData['position'],
                        'image' => !empty($value['option_value_image']) ? $value['option_value_image'] : null,
                    ];
                    
                    // Accumulate price modifiers
                    $priceModifier = floatval($value['price'] ?? 0);
                    if (($value['price_prefix'] ?? '+') == '-') {
                        $priceModifier = -$priceModifier;
                    }
                    $newVariant['price_modifier'] = ($variant['price_modifier'] ?? 0) + $priceModifier;
                    
                    // Accumulate weight modifiers
                    $weightModifier = floatval($value['weight'] ?? 0);
                    if (($value['weight_prefix'] ?? '+') == '-') {
                        $weightModifier = -$weightModifier;
                    }
                    $newVariant['weight_modifier'] = ($variant['weight_modifier'] ?? 0) + $weightModifier;
                    
                    // Build SKU suffix
                    $skuSuffix = $optionValueName;
                    $newVariant['sku_suffix'] = ($variant['sku_suffix'] ?? '') . '-' . $this->sanitizeSku($skuSuffix);
                    
                    $newVariants[] = $newVariant;
                }
            }
            
            $variants = $newVariants;
        }
        
        return $variants;
    }
    
    /**
     * Generate Shopify variants from product and combinations
     */
    private function generateVariants(array $product, array $variantCombinations, array $options): array
    {
        $basePrice = floatval($product['price']);
        $baseSku = !empty($product['sku']) ? $product['sku'] : $product['model'];
        $baseWeight = floatval($product['weight']);
        
        $variants = [];
        
        foreach ($variantCombinations as $combination) {
            $variant = [
                'price' => number_format($basePrice + ($combination['price_modifier'] ?? 0), 2, '.', ''),
                'sku' => $baseSku . ($combination['sku_suffix'] ?? ''),
                'inventory_quantity' => (int)$product['quantity'],
                'inventory_management' => 'shopify',
                'requires_shipping' => $product['shipping'] == 1,
                'taxable' => $product['tax_class_id'] > 0,
            ];
            
            // Add weight
            if ($baseWeight > 0 || ($combination['weight_modifier'] ?? 0) != 0) {
                $variant['weight'] = $baseWeight + ($combination['weight_modifier'] ?? 0);
                $variant['weight_unit'] = 'kg'; // Default, should be mapped from weight_class
            }
            
            // Add option values and collect variant images
            $variantImages = [];
            if (!empty($combination['option_values'])) {
                foreach ($combination['option_values'] as $optionValue) {
                    $position = $optionValue['position'];
                    $variant["option{$position}"] = $optionValue['option_value'];
                    
                    // Collect variant-specific images
                    if (!empty($optionValue['image'])) {
                        $variantImages[] = $optionValue['image'];
                    }
                }
            }
            
            // Add variant images if available
            if (!empty($variantImages)) {
                $processedVariantImages = [];
                foreach ($variantImages as $imagePath) {
                    $imageUrl = $this->getImageUrl($imagePath);
                    if ($imageUrl) {
                        $processedVariantImages[] = $imageUrl;
                    }
                }
                if (!empty($processedVariantImages)) {
                    $variant['images'] = array_unique($processedVariantImages);
                }
            }
            
            $variants[] = $variant;
        }
        
        // Deduplicate variants by option combination
        $deduplicatedVariants = [];
        $seenCombinations = [];
        
        foreach ($variants as $variant) {
            // Create a key based on option values
            $optionKey = implode('|', [
                $variant['option1'] ?? '',
                $variant['option2'] ?? '',
                $variant['option3'] ?? '',
            ]);
            
            if (!isset($seenCombinations[$optionKey])) {
                $seenCombinations[$optionKey] = true;
                $deduplicatedVariants[] = $variant;
            } else {
                $this->warnings[] = "Skipping duplicate variant: {$optionKey}";
            }
        }
        
        $variants = $deduplicatedVariants;
        
        // If no variants, create a default one
        if (empty($variants)) {
            $variants[] = [
                'price' => number_format($basePrice, 2, '.', ''),
                'sku' => $baseSku,
                'inventory_quantity' => (int)$product['quantity'],
                'inventory_management' => 'shopify',
                'requires_shipping' => $product['shipping'] == 1,
                'taxable' => $product['tax_class_id'] > 0,
                'weight' => $baseWeight > 0 ? $baseWeight : null,
                'weight_unit' => $baseWeight > 0 ? 'kg' : null,
            ];
        }
        
        return $variants;
    }
    
    /**
     * Map images to Shopify format
     */
    protected function mapImages(string $mainImage, array $additionalImages): array
    {
        $images = [];
        
        // Add main image first
        if (!empty($mainImage)) {
            $imageUrl = $this->getImageUrl($mainImage);
            if ($imageUrl) {
                $images[] = [
                    'src' => $imageUrl,
                    'position' => 1,
                ];
            }
        }
        
        // Add additional images
        foreach ($additionalImages as $idx => $img) {
            if (!empty($img['image'])) {
                $imageUrl = $this->getImageUrl($img['image']);
                if ($imageUrl) {
                    $images[] = [
                        'src' => $imageUrl,
                        'position' => $idx + 2,
                    ];
                }
            }
        }
        
        return $images;
    }
    
    /**
     * Get full image URL or path
     */
    protected function getImageUrl(string $imagePath): ?string
    {
        if (empty($imagePath)) {
            return null;
        }
        
        // If public base URL is configured, use it
        if (!empty($this->config['image_base_url'])) {
            return rtrim($this->config['image_base_url'], '/') . '/' . ltrim($imagePath, '/');
        }
        
        // Otherwise, return the path for local checking
        return $imagePath;
    }
    
    /**
     * Generate metafields
     */
    private function generateMetafields(array $product, array $description, array $attributes): array
    {
        $metafields = [];
        
        // Store OpenCart product ID
        $metafields[] = [
            'namespace' => 'custom',
            'key' => 'opencart_product_id',
            'value' => (string)$product['product_id'],
            'type' => 'single_line_text_field',
        ];
        
        // Add UPC/EAN/etc if available
        if (!empty($product['upc'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'upc',
                'value' => $product['upc'],
                'type' => 'single_line_text_field',
            ];
        }
        
        if (!empty($product['ean'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'ean',
                'value' => $product['ean'],
                'type' => 'single_line_text_field',
            ];
        }
        
        if (!empty($product['jan'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'jan',
                'value' => $product['jan'],
                'type' => 'single_line_text_field',
            ];
        }
        
        if (!empty($product['isbn'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'isbn',
                'value' => $product['isbn'],
                'type' => 'single_line_text_field',
            ];
        }
        
        if (!empty($product['mpn'])) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'mpn',
                'value' => $product['mpn'],
                'type' => 'single_line_text_field',
            ];
        }
        
        // Add attributes as metafields
        foreach ($attributes as $attr) {
            $key = $this->sanitizeMetafieldKey($attr['attribute_name']);
            $metafields[] = [
                'namespace' => 'custom',
                'key' => $key,
                'value' => $attr['text'],
                'type' => 'single_line_text_field',
            ];
        }
        
        return $metafields;
    }
    
    /**
     * Generate Shopify handle
     */
    private function generateHandle(int $productId, ?string $seoUrl, string $name): string
    {
        // Use safe default: opencart-{id}
        $safeHandle = "opencart-{$productId}";
        
        // If SEO URL exists, use it (but keep safe default as backup)
        if (!empty($seoUrl)) {
            $handle = $this->sanitizeHandle($seoUrl);
            if ($handle) {
                return $handle;
            }
        }
        
        // Fallback to name-based handle
        if (!empty($name)) {
            $handle = $this->sanitizeHandle($name);
            if ($handle) {
                return $handle . "-{$productId}";
            }
        }
        
        return $safeHandle;
    }
    
    /**
     * Sanitize string for Shopify handle
     */
    private function sanitizeHandle(string $string): string
    {
        $handle = strtolower($string);
        $handle = preg_replace('/[^a-z0-9\-_]/', '-', $handle);
        $handle = preg_replace('/-+/', '-', $handle);
        $handle = trim($handle, '-');
        return $handle;
    }
    
    /**
     * Sanitize string for SKU suffix
     */
    private function sanitizeSku(?string $string): string
    {
        if ($string === null || $string === '') {
            return '';
        }
        
        $sku = strtoupper($string);
        $sku = preg_replace('/[^A-Z0-9\-_]/', '', $sku);
        return substr($sku, 0, 20);
    }
    
    /**
     * Sanitize string for metafield key
     */
    private function sanitizeMetafieldKey(string $string): string
    {
        $key = strtolower($string);
        $key = preg_replace('/[^a-z0-9_]/', '_', $key);
        $key = preg_replace('/_+/', '_', $key);
        $key = trim($key, '_');
        return substr($key, 0, 30);
    }
    
    /**
     * Clean HTML description
     */
    private function cleanDescription(string $description): string
    {
        // Basic HTML cleaning - keep most tags for Shopify
        return trim($description);
    }
    
    /**
     * Get product type from first category
     */
    private function getProductType(array $categories): string
    {
        if (empty($categories)) {
            return '';
        }
        
        $productType = $categories[0]['category_name'] ?? '';
        return html_entity_decode($productType, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Generate tags from categories and OpenCart tags
     */
    private function generateTags(array $categories, string $ocTags): string
    {
        $tags = [];
        
        // Add category names as tags
        foreach ($categories as $category) {
            if (!empty($category['category_name'])) {
                $tags[] = html_entity_decode(trim($category['category_name']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        
        // Add OpenCart tags
        if (!empty($ocTags)) {
            $ocTagsArray = explode(',', $ocTags);
            foreach ($ocTagsArray as $tag) {
                $cleanTag = html_entity_decode(trim($tag), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!empty($cleanTag)) {
                    $tags[] = $cleanTag;
                }
            }
        }
        
        return implode(', ', array_unique($tags));
    }
    
    /**
     * Get reason for skipping an option
     */
    /**
     * Sanitize option name for Shopify compatibility
     * Removes invalid character sequences like " / "
     */
    private function sanitizeOptionName(string $optionName): string
    {
        // Replace problematic character sequences that Shopify doesn't allow
        $sanitized = $optionName;
        
        // Remove the problematic " / " sequence (space-slash-space)
        $sanitized = str_replace(' / ', ' - ', $sanitized);
        
        // Remove other problematic sequences if they exist
        $sanitized = str_replace(['/', '\\'], '-', $sanitized);
        
        // Clean up multiple spaces and dashes
        $sanitized = preg_replace('/\s+/', ' ', $sanitized);
        $sanitized = preg_replace('/\-+/', '-', $sanitized);
        
        // Trim whitespace and dashes from ends
        $sanitized = trim($sanitized, ' -');
        
        // Ensure we don't return an empty string
        if (empty($sanitized)) {
            $sanitized = 'Option';
        }
        
        return $sanitized;
    }

    private function getSkipReason(string $optionType, int $required): string
    {
        if ($required != 1) {
            return 'Not required';
        }
        
        $nonVariantTypes = [
            'text' => 'Text input - should be line item property',
            'textarea' => 'Textarea input - should be line item property',
            'file' => 'File upload - requires custom implementation',
            'date' => 'Date picker - should be line item property',
            'datetime' => 'DateTime picker - should be line item property',
            'time' => 'Time picker - should be line item property',
        ];
        
        return $nonVariantTypes[$optionType] ?? 'Not suitable for Shopify variant';
    }
}
