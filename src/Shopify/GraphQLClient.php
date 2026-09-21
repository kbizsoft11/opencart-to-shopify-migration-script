<?php
/**
 * Shopify GraphQL API Client
 */

namespace Shopify;

use App\Utils\ImageProcessor;

class GraphQLClient
{
    private string $shopDomain;
    private string $accessToken;
    private string $apiVersion;
    private int $requestCount = 0;
    private float $lastRequestTime = 0;
    private ImageProcessor $imageProcessor;
    
    public function __construct(string $shopDomain, string $accessToken, string $apiVersion = '2026-01', bool $processImagesMode = false, ?string $imageHostOverride = null)
    {
        $this->shopDomain = $shopDomain;
        $this->accessToken = $accessToken;
        $this->apiVersion = $apiVersion;
        $this->imageProcessor = new ImageProcessor($processImagesMode, $imageHostOverride);
    }
    
    /**
     * Execute GraphQL mutation or query with enhanced error handling
     */
    public function execute(string $query, array $variables = []): array
    {
        $this->rateLimit();
        
        $url = "https://{$this->shopDomain}/admin/api/{$this->apiVersion}/graphql.json";
        
        $payload = [
            'query' => $query,
        ];
        
        // Only add variables if they are not empty
        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Shopify-Access-Token: ' . $this->accessToken,
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("cURL error: {$error}");
        }
        
        // Check if response is empty
        if (empty($response)) {
            throw new \Exception("Empty response from Shopify API (HTTP {$httpCode})");
        }
        
        // Check content type
        if ($contentType && strpos($contentType, 'application/json') === false) {
            // Got HTML error page instead of JSON
            $preview = substr($response, 0, 200);
            throw new \Exception("Non-JSON response from Shopify API (HTTP {$httpCode}): {$preview}");
        }
        
        $result = json_decode($response, true);
        
        // Check if JSON decoding failed
        if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
            $jsonError = json_last_error_msg();
            $preview = substr($response, 0, 200);
            throw new \Exception("JSON decode error: {$jsonError}. Response preview: {$preview}");
        }
        
        if ($httpCode === 429) {
            // Rate limited - wait and retry
            sleep(2);
            return $this->execute($query, $variables);
        }
        
        if ($httpCode !== 200) {
            $errorMsg = is_array($result) ? json_encode($result) : $response;
            throw new \Exception("HTTP {$httpCode}: {$errorMsg}");
        }
        
        if (isset($result['errors'])) {
            $errorMsg = json_encode($result['errors']);
            throw new \Exception("GraphQL errors: {$errorMsg}");
        }
        
        return $result;
    }
    
    /**
     * Create a product with proper variant chunking
     * Handles products with more than 100 variants by creating product with first 100,
     * then using productVariantsBulkCreate for remaining variants
     */
    public function createProduct(array $product): array
    {
        $variants = $product['variants'] ?? [];
        $variantCount = count($variants);
        
        // If more than 100 variants, split into chunks
        if ($variantCount > 100) {
            // Create product with first 100 variants
            $productWithFirstVariants = $product;
            $productWithFirstVariants['variants'] = array_slice($variants, 0, 100);
            
            $createdProduct = $this->createProductSingle($productWithFirstVariants);
            $productId = $createdProduct['id'];
            
            // Add remaining variants in chunks of 100
            $remainingVariants = array_slice($variants, 100);
            $this->addVariantsInBulk($productId, $remainingVariants, $product['options'] ?? []);
            
            return $createdProduct;
        }
        
        // If 100 or fewer variants, create normally
        return $this->createProductSingle($product);
    }
    
    /**
     * Create a single product (used internally)
     */
    /**
     * Create a single product using productCreate mutation (Shopify 2026-01 API)
     * NOTE: This creates the product with basic info only. Variants must be added separately.
     */
    private function createProductSingle(array $product): array
    {
        echo "🚀 [GraphQLClient] Starting createProductSingle for: {$product['title']}" . PHP_EOL;
        echo "📷 [GraphQLClient] Product has " . count($product['images'] ?? []) . " images to process" . PHP_EOL;
        
        // Convert images to media format for GraphQL with URL encoding and validation
        $mediaInput = [];
        $imageWarnings = [];
        
        if (!empty($product['images'])) {
            echo "🖼️ [GraphQLClient] Processing product images..." . PHP_EOL;
            foreach ($product['images'] as $idx => $image) {
                echo "🔄 [GraphQLClient] Processing image " . ($idx + 1) . "/" . count($product['images']) . ": {$image['src']}" . PHP_EOL;
                try {
                    // URL encode and validate the image
                    $encodedUrl = $this->encodeImageUrl($image['src']);
                    echo "✅ [GraphQLClient] Image processed successfully, adding to media input" . PHP_EOL;
                    $mediaInput[] = [
                        'originalSource' => $encodedUrl,
                        'mediaContentType' => 'IMAGE',
                    ];
                } catch (\Exception $e) {
                    // Collect image warnings for reporting
                    $imageWarnings[] = "Image processing failed for {$image['src']}: " . $e->getMessage();
                    echo "❌ [GraphQLClient] Image processing failed for {$image['src']}: " . $e->getMessage() . PHP_EOL;
                    continue;
                }
            }
        } elseif (!empty($product['media'])) {
            echo "🎬 [GraphQLClient] Processing product media..." . PHP_EOL;
            foreach ($product['media'] as $idx => $media) {
                echo "🔄 [GraphQLClient] Processing media " . ($idx + 1) . "/" . count($product['media']) . ": {$media['originalSource']}" . PHP_EOL;
                try {
                    $encodedUrl = $this->encodeImageUrl($media['originalSource']);
                    echo "✅ [GraphQLClient] Media processed successfully, adding to media input" . PHP_EOL;
                    $mediaInput[] = [
                        'originalSource' => $encodedUrl,
                        'mediaContentType' => $media['mediaContentType'],
                    ];
                } catch (\Exception $e) {
                    // Collect image warnings for reporting
                    $imageWarnings[] = "Media processing failed for {$media['originalSource']}: " . $e->getMessage();
                    echo "❌ [GraphQLClient] Media processing failed for {$media['originalSource']}: " . $e->getMessage() . PHP_EOL;
                    continue;
                }
            }
        }
        
        echo "📊 [GraphQLClient] Final media input count: " . count($mediaInput) . " items" . PHP_EOL;
        if (!empty($imageWarnings)) {
            echo "⚠️ [GraphQLClient] Total image warnings: " . count($imageWarnings) . PHP_EOL;
        }
        
        $mutation = '
            mutation productCreate($product: ProductCreateInput!, $media: [CreateMediaInput!]) {
                productCreate(product: $product, media: $media) {
                    product {
                        id
                        handle
                        title
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        ';
        
        // Format product input WITHOUT variants (2026-01 API change)
        $productInput = $this->formatProductInput($product);
        
        $variables = [
            'product' => $productInput,
            'media' => $mediaInput,
        ];
        
        echo "🌐 [GraphQLClient] Sending GraphQL mutation to Shopify..." . PHP_EOL;
        echo "📋 [GraphQLClient] Media array being sent: " . json_encode($mediaInput, JSON_PRETTY_PRINT) . PHP_EOL;
        
        $result = $this->execute($mutation, $variables);
        
        if (!empty($result['data']['productCreate']['userErrors'])) {
            $errors = $result['data']['productCreate']['userErrors'];
            echo "💥 [GraphQLClient] Product creation failed with errors: " . json_encode($errors) . PHP_EOL;
            throw new \Exception("Product creation failed: " . json_encode($errors));
        }
        
        $createdProduct = $result['data']['productCreate']['product'];
        echo "🎉 [GraphQLClient] Product created successfully: {$createdProduct['id']}" . PHP_EOL;
        
        // Add image warnings to the result for reporting
        if (!empty($imageWarnings)) {
            $createdProduct['image_warnings'] = $imageWarnings;
            echo "⚠️ [GraphQLClient] Product created with " . count($imageWarnings) . " image processing warnings" . PHP_EOL;
        }
        
        // After product creation, handle variants
        // Note: Shopify creates a default variant, but we need to set proper pricing/SKU
        if (!empty($product['variants'])) {
            echo "🔧 [GraphQLClient] Creating product variants..." . PHP_EOL;
            $this->createProductVariants($createdProduct['id'], $product);
        }
        
        return $createdProduct;
    }

    /**
     * Process and encode image URLs with size validation and resizing
     */
    private function encodeImageUrl(string $url): string
    {
        echo "🔗 [GraphQLClient] encodeImageUrl() called with: {$url}" . PHP_EOL;
        
        // If image is already hosted on imgbb (pre-processed), use it directly without re-processing
        if (strpos($url, 'i.ibb.co') !== false) {
            echo "✅ [GraphQLClient] Image already processed on imgbb, using as-is" . PHP_EOL;
            return $url;
        }
        
        // Parse the URL to encode only the path parts first
        $parsedUrl = parse_url($url);
        
        if (!$parsedUrl) {
            echo "❌ [GraphQLClient] Failed to parse URL: {$url}" . PHP_EOL;
            return $url;
        }
        
        // First encode the URL properly
        $encodedUrl = '';
        
        // Rebuild the URL with proper encoding
        if (isset($parsedUrl['scheme'])) {
            $encodedUrl .= $parsedUrl['scheme'] . '://';
        }
        
        if (isset($parsedUrl['host'])) {
            $encodedUrl .= $parsedUrl['host'];
        }
        
        if (isset($parsedUrl['port'])) {
            $encodedUrl .= ':' . $parsedUrl['port'];
        }
        
        if (isset($parsedUrl['path'])) {
            // Encode each path segment separately to preserve directory structure
            $pathParts = explode('/', $parsedUrl['path']);
            $encodedParts = array_map('rawurlencode', $pathParts);
            $encodedUrl .= implode('/', $encodedParts);
        }
        
        if (isset($parsedUrl['query'])) {
            $encodedUrl .= '?' . $parsedUrl['query'];
        }
        
        if (isset($parsedUrl['fragment'])) {
            $encodedUrl .= '#' . $parsedUrl['fragment'];
        }
        
        echo "🔗 [GraphQLClient] URL encoded to: {$encodedUrl}" . PHP_EOL;
        
        // Process the image through ImageProcessor to handle size and MP limits
        echo "⚡ [GraphQLClient] Calling ImageProcessor..." . PHP_EOL;
        try {
            $processedImage = $this->imageProcessor->processImageFromUrl($encodedUrl);
            
            if ($processedImage === null) {
                echo "❌ [GraphQLClient] ImageProcessor returned null" . PHP_EOL;
                throw new \Exception("Failed to process image");
            }
            
            // Log processing results
            if ($processedImage['processed']) {
                echo "✅ [GraphQLClient] Processed image: {$url} - {$processedImage['original_size']} -> {$processedImage['final_size']} ({$processedImage['megapixels']}MP)" . PHP_EOL;
            } else {
                echo "✅ [GraphQLClient] Image OK as-is: {$url} - {$processedImage['final_size']} ({$processedImage['megapixels']}MP)" . PHP_EOL;
            }
            
            // Check if upload was successful
            if (isset($processedImage['upload_failed']) && $processedImage['upload_failed']) {
                echo "⚠️ [GraphQLClient] Upload failed, using original URL (may fail in Shopify)" . PHP_EOL;
            } else if (isset($processedImage['uploaded']) && $processedImage['uploaded']) {
                echo "✅ [GraphQLClient] Image uploaded successfully" . PHP_EOL;
            }
            
            echo "🎯 [GraphQLClient] Using final URL: {$processedImage['url']}" . PHP_EOL;
            return $processedImage['url'];
            
        } catch (\Exception $e) {
            echo "💥 [GraphQLClient] ImageProcessor threw exception: " . $e->getMessage() . PHP_EOL;
            // If image processing fails, throw to skip the image
            throw new \Exception("Failed to process image");
        }
    }
    
    /**
     * Add variants to existing product in bulk (chunks of 100)
     */
    private function addVariantsInBulk(string $productId, array $variants, array $productOptions = []): void
    {
        $chunks = array_chunk($variants, 100);
        $totalChunks = count($chunks);
        
        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkNumber = $chunkIndex + 1;
            
            $mutation = '
                mutation productVariantsBulkCreate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
                    productVariantsBulkCreate(productId: $productId, variants: $variants) {
                        productVariants {
                            id
                            title
                        }
                        userErrors {
                            field
                            message
                        }
                    }
                }
            ';
            
            // Format variants for bulk input
            $bulkVariants = array_map(function($variant) use ($productOptions) {
                $v = [
                    'price' => (string)($variant['price'] ?? '0.00'),
                ];
                
                // Set SKU via inventoryItem (2026-01 API requirement)
                if (!empty($variant['sku'])) {
                    $v['inventoryItem'] = [
                        'sku' => $variant['sku'],
                        'tracked' => false, // Untracked inventory for infinite stock
                    ];
                }
                
                // Set inventory policy to allow continued sales
                $v['inventoryPolicy'] = 'CONTINUE'; // Allow purchases even when out of stock
                
                // NOTE: inventoryQuantities removed - will be set separately after variant creation
                
                // DO NOT set inventoryQuantities during variant creation - handle separately
                // This causes "Inventory quantities can only be provided during create" error
                
                if (isset($variant['compare_at_price']) && $variant['compare_at_price'] > 0) {
                    $v['compareAtPrice'] = (string)$variant['compare_at_price'];
                }
                
                // Add option values using actual option names from product options
                $optionValues = [];
                if (isset($variant['option1']) && !empty($variant['option1'])) {
                    $optionName = $productOptions[0]['name'] ?? 'Option 1';
                    $optionValues[] = ['optionName' => $optionName, 'name' => $variant['option1']];
                }
                if (isset($variant['option2']) && !empty($variant['option2'])) {
                    $optionName = $productOptions[1]['name'] ?? 'Option 2';
                    $optionValues[] = ['optionName' => $optionName, 'name' => $variant['option2']];
                }
                if (isset($variant['option3']) && !empty($variant['option3'])) {
                    $optionName = $productOptions[2]['name'] ?? 'Option 3';
                    $optionValues[] = ['optionName' => $optionName, 'name' => $variant['option3']];
                }
                
                if (!empty($optionValues)) {
                    $v['optionValues'] = $optionValues;
                }
                
                if (isset($variant['weight']) && $variant['weight'] > 0) {
                    $v['weight'] = $variant['weight'];
                    $v['weightUnit'] = strtoupper($variant['weight_unit'] ?? 'KILOGRAMS');
                }
                
                if (isset($variant['barcode'])) {
                    $v['barcode'] = $variant['barcode'];
                }
                
                if (isset($variant['taxable'])) {
                    $v['taxable'] = (bool)$variant['taxable'];
                }
                
                return $v;
            }, $chunk);
            
            $variables = [
                'productId' => $productId,
                'variants' => $bulkVariants,
            ];
            
            try {
                $result = $this->execute($mutation, $variables);
                
                if (!empty($result['data']['productVariantsBulkCreate']['userErrors'])) {
                    $errors = $result['data']['productVariantsBulkCreate']['userErrors'];
                    throw new \Exception("Bulk variant creation failed (batch $chunkNumber/$totalChunks): " . json_encode($errors));
                }
                
                // Log success for monitoring
                error_log("Successfully created batch $chunkNumber/$totalChunks (" . count($chunk) . " variants) for product $productId");
                
                // Handle inventory for created variants
                if (!empty($result['data']['productVariantsBulkCreate']['productVariants'])) {
                    $createdVariants = $result['data']['productVariantsBulkCreate']['productVariants'];
                    
                    // Map created variants back to original variants for inventory adjustment
                    foreach ($createdVariants as $index => $createdVariant) {
                        $originalVariant = $chunk[$index] ?? null;
                        if ($originalVariant && $createdVariant) {
                            // Variant created successfully with untracked inventory
                            echo "✅ Created variant with untracked inventory: {$createdVariant['id']}" . PHP_EOL;
                        }
                    }
                }
                
            } catch (\Exception $e) {
                // Log error but continue with next batch
                error_log("Error in batch $chunkNumber/$totalChunks: " . $e->getMessage());
                
                // If it's a critical error, rethrow
                if (strpos($e->getMessage(), 'Throttled') === false) {
                    throw $e;
                }
                
                // If throttled, wait longer and retry
                sleep(3);
                $result = $this->execute($mutation, $variables);
                
                if (!empty($result['data']['productVariantsBulkCreate']['userErrors'])) {
                    $errors = $result['data']['productVariantsBulkCreate']['userErrors'];
                    throw new \Exception("Bulk variant creation failed on retry (batch $chunkNumber/$totalChunks): " . json_encode($errors));
                }
            }
            
            // Add delay between chunks to avoid rate limiting (1 second minimum)
            if ($chunkIndex < $totalChunks - 1) {
                sleep(1);
            }
        }
    }
    
    /**
     * Update a product
     */
    public function updateProduct(string $productId, array $product): array
    {
        $mutation = '
            mutation productUpdate($input: ProductInput!) {
                productUpdate(input: $input) {
                    product {
                        id
                        handle
                        title
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        ';
        
        $input = $this->formatProductInput($product, true); // true = is update
        $input['id'] = $productId;
        
        $variables = [
            'input' => $input,
        ];
        
        $result = $this->execute($mutation, $variables);
        
        if (!empty($result['data']['productUpdate']['userErrors'])) {
            $errors = $result['data']['productUpdate']['userErrors'];
            throw new \Exception("Product update failed: " . json_encode($errors));
        }
        
        return $result['data']['productUpdate']['product'];
    }
    
    /**
     * Find product by metafield
     */
    /**
     * Find existing product by metafield value
     * FIXED: If metafield search doesn't work, fetch all products and filter client-side
     */
    public function findProductByMetafield(string $namespace, string $key, string $value): ?array
    {
        // First try the metafield search query
        $query = '
            query findProduct($query: String!) {
                products(first: 1, query: $query) {
                    edges {
                        node {
                            id
                            handle
                            title
                            metafield(namespace: "' . $namespace . '", key: "' . $key . '") {
                                value
                            }
                        }
                    }
                }
            }
        ';
        
        $searchQuery = "metafields.{$namespace}.{$key}:\"{$value}\"";
        $variables = [
            'query' => $searchQuery,
        ];
        
        error_log("Shopify metafield search attempt: {$searchQuery}");
        
        $result = $this->execute($query, $variables);
        
        // Check if we got an error (metafield filtering not enabled)
        if (isset($result['errors'])) {
            error_log("Metafield search failed (filtering likely not enabled): " . json_encode($result['errors']));
            
            // Fallback: Get all products and filter client-side
            return $this->findProductByMetafieldClientSide($namespace, $key, $value);
        }
        
        $edges = $result['data']['products']['edges'] ?? [];
        
        if (empty($edges)) {
            error_log("Metafield search returned no results for {$namespace}.{$key}:\"{$value}\"");
            return null;
        }
        
        $product = $edges[0]['node'];
        
        // Verify the metafield actually matches (in case search was imprecise)
        if (($product['metafield']['value'] ?? null) !== $value) {
            error_log("Metafield search returned product but value doesn't match exactly");
            return null;
        }
        
        error_log("Metafield search found: {$product['title']} (ID: {$product['id']})");
        
        return $product;
    }
    
    /**
     * Fallback method: Get all products and filter client-side
     */
    private function findProductByMetafieldClientSide(string $namespace, string $key, string $value): ?array
    {
        error_log("Using client-side filtering for metafield {$namespace}.{$key}:\"{$value}\"");
        
        $cursor = null;
        $maxProducts = 1000; // Safety limit
        $checked = 0;
        
        do {
            $query = '
                query getProducts($cursor: String) {
                    products(first: 250, after: $cursor) {
                        edges {
                            node {
                                id
                                handle
                                title
                                metafield(namespace: "' . $namespace . '", key: "' . $key . '") {
                                    value
                                }
                            }
                        }
                        pageInfo {
                            hasNextPage
                            endCursor
                        }
                    }
                }
            ';
            
            $variables = [
                'cursor' => $cursor,
            ];
            
            $result = $this->execute($query, $variables);
            
            if (isset($result['errors'])) {
                error_log("Client-side filtering failed: " . json_encode($result['errors']));
                return null;
            }
            
            $edges = $result['data']['products']['edges'] ?? [];
            $pageInfo = $result['data']['products']['pageInfo'] ?? [];
            
            foreach ($edges as $edge) {
                $product = $edge['node'];
                $checked++;
                
                if (($product['metafield']['value'] ?? null) === $value) {
                    error_log("Client-side filtering found match: {$product['title']} (checked {$checked} products)");
                    return $product;
                }
            }
            
            $cursor = $pageInfo['endCursor'] ?? null;
            
        } while (($pageInfo['hasNextPage'] ?? false) && $checked < $maxProducts);
        
        error_log("Client-side filtering found no matches (checked {$checked} products)");
        return null;
    }
    
    /**
     * Find product by handle
     */
    public function findProductByHandle(string $handle): ?array
    {
        $query = '
            query findProduct($handle: String!) {
                productByHandle(handle: $handle) {
                    id
                    handle
                    title
                }
            }
        ';
        
        $variables = [
            'handle' => $handle,
        ];
        
        // Debug: Log the handle search
        error_log("Shopify handle search: {$handle}");
        
        $result = $this->execute($query, $variables);
        
        $product = $result['data']['productByHandle'] ?? null;
        
        if ($product) {
            error_log("Handle search found: {$product['title']} (ID: {$product['id']})");
        } else {
            error_log("Handle search returned no results");
        }
        
        return $product;
    }
    
    /**
     * Get all products from Shopify store
     */
    public function getAllProducts(): array
    {
        $allProducts = [];
        $after = null;
        $pageSize = 250; // Max allowed by Shopify
        
        do {
            $query = '
                query getProducts($first: Int!, $after: String) {
                    products(first: $first, after: $after) {
                        pageInfo {
                            hasNextPage
                            endCursor
                        }
                        edges {
                            node {
                                id
                                title
                                handle
                                status
                            }
                        }
                    }
                }
            ';
            
            $variables = [
                'first' => $pageSize,
            ];
            
            if ($after) {
                $variables['after'] = $after;
            }
            
            $result = $this->execute($query, $variables);
            
            if (isset($result['errors'])) {
                throw new \Exception("Shopify API error: " . json_encode($result['errors']));
            }
            
            $products = $result['data']['products']['edges'] ?? [];
            
            foreach ($products as $edge) {
                $allProducts[] = $edge['node'];
            }
            
            $hasNextPage = $result['data']['products']['pageInfo']['hasNextPage'] ?? false;
            $after = $result['data']['products']['pageInfo']['endCursor'] ?? null;
            
        } while ($hasNextPage && $after);
        
        return $allProducts;
    }
    
    /**
     * Delete a product from Shopify store by product ID
     */
    public function deleteProduct(string $productId): void
    {
        $query = '
            mutation deleteProduct($input: ProductDeleteInput!) {
                productDelete(input: $input) {
                    deletedProductId
                    userErrors {
                        field
                        message
                    }
                }
            }
        ';
        
        $variables = [
            'input' => [
                'id' => $productId,
            ],
        ];
        
        $result = $this->execute($query, $variables);
        
        // Check for GraphQL errors
        if (isset($result['errors'])) {
            throw new \Exception("Shopify API error: " . json_encode($result['errors']));
        }
        
        // Check for mutation errors
        $userErrors = $result['data']['productDelete']['userErrors'] ?? [];
        if (!empty($userErrors)) {
            $errorMsg = $userErrors[0]['message'] ?? 'Unknown error';
            throw new \Exception("Failed to delete product: {$errorMsg}");
        }
    }
    
    /**
     * Format product data for Shopify GraphQL input (2026-01 API)
     * Note: productCreate only supports creating ONE initial variant
     */
    private function formatProductInput(array $product, bool $isUpdate = false): array
    {
        $input = [
            'title' => $product['title'],
            'descriptionHtml' => html_entity_decode($product['descriptionHtml'] ?? $product['body_html'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'vendor' => $product['vendor'] ?? '',
            'productType' => $product['productType'] ?? $product['product_type'] ?? '',
            'tags' => $product['tags'] ?? [],
            'status' => strtoupper($product['status'] ?? 'ACTIVE'),
        ];
        
        if (!empty($product['handle'])) {
            $input['handle'] = $product['handle'];
        }
        
        // Add product options (names and values) - ONLY for create, NOT for update
        if (!$isUpdate && !empty($product['options'])) {
            $input['productOptions'] = [];
            foreach ($product['options'] as $option) {
                $values = [];
                foreach ($option['values'] ?? [] as $val) {
                    // Handle both array and string formats
                    $valueName = is_array($val) ? ($val['name'] ?? '') : $val;
                    // Skip empty values
                    if (!empty($valueName)) {
                        $values[] = ['name' => $valueName];
                    }
                }
                // Only add option if it has non-empty values
                if (!empty($values)) {
                    $input['productOptions'][] = [
                        'name' => $option['name'],
                        'values' => $values,
                    ];
                }
            }
        }
        
        // NOTE: Variants are NOT included in ProductCreateInput in 2026-01 API
        // They must be created separately using productVariantsBulkCreate
        
        // Add metafields
        if (!empty($product['metafields'])) {
            $input['metafields'] = [];
            foreach ($product['metafields'] as $metafield) {
                $input['metafields'][] = [
                    'namespace' => $metafield['namespace'],
                    'key' => $metafield['key'],
                    'value' => (string)$metafield['value'],
                    'type' => $metafield['type'] ?? 'single_line_text_field',
                ];
            }
        }
        
        // NOTE: media is passed separately to the mutation, not in product input
        
        return $input;
    }
    
    /**
     * Simple rate limiting
     */
    private function rateLimit(): void
    {
        $this->requestCount++;
        $now = microtime(true);
        
        // Shopify GraphQL API allows much higher rates than REST - be less conservative
        $minInterval = 0.1; // 100ms between requests (10 requests per second)
        
        if ($this->lastRequestTime > 0) {
            $elapsed = $now - $this->lastRequestTime;
            if ($elapsed < $minInterval) {
                usleep((int)(($minInterval - $elapsed) * 1000000));
            }
        }
        
        $this->lastRequestTime = microtime(true);
    }
    
    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    /**
     * Create or update metafield definition to enable admin filtering
     */
    public function ensureMetafieldDefinition(string $namespace, string $key, string $name, string $type = 'single_line_text_field'): bool
    {
        // Check if definition already exists
        $checkQuery = '
            query {
                metafieldDefinitions(first: 50, ownerType: PRODUCT) {
                    edges {
                        node {
                            id
                            namespace
                            key
                        }
                    }
                }
            }
        ';
        
        $result = $this->execute($checkQuery);
        
        if (isset($result['errors'])) {
            error_log("Failed to check metafield definitions: " . json_encode($result['errors']));
            return false;
        }
        
        $definitions = $result['data']['metafieldDefinitions']['edges'] ?? [];
        
        // Look for existing definition
        foreach ($definitions as $edge) {
            $def = $edge['node'];
            if ($def['namespace'] === $namespace && $def['key'] === $key) {
                error_log("Metafield definition {$namespace}.{$key} already exists");
                return true; // Assume it's properly configured
            }
        }
        
        // Create new definition with filtering enabled
        $createQuery = '
            mutation metafieldDefinitionCreate($definition: MetafieldDefinitionInput!) {
                metafieldDefinitionCreate(definition: $definition) {
                    createdDefinition {
                        id
                        namespace
                        key
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        ';
        
        $variables = [
            'definition' => [
                'ownerType' => 'PRODUCT',
                'namespace' => $namespace,
                'key' => $key,
                'name' => $name,
                'type' => $type,
            ]
        ];
        
        $result = $this->execute($createQuery, $variables);
        
        if (isset($result['errors'])) {
            error_log("Failed to create metafield definition: " . json_encode($result['errors']));
            return false;
        }
        
        if (!empty($result['data']['metafieldDefinitionCreate']['userErrors'])) {
            error_log("User errors creating metafield definition: " . json_encode($result['data']['metafieldDefinitionCreate']['userErrors']));
            return false;
        }
        
        error_log("Created metafield definition {$namespace}.{$key} with filtering enabled");
        return true;
    }

    /**
     * Create product variants separately using productVariantsBulkCreate
     * This is required in Shopify 2026-01 API
     */
    private function createProductVariants(string $productId, array $product): void
    {
        if (empty($product['variants'])) {
            return;
        }
        
        // DEDUPLICATE VARIANTS BY OPTION COMBINATION
        $deduplicatedVariants = [];
        $seenCombinations = [];
        
        foreach ($product['variants'] as $variant) {
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
                error_log("Shopify API: Skipping duplicate variant: {$optionKey}");
            }
        }
        
        // Handle default variant based on product type
        if (empty($product['options'])) {
            // SIMPLE PRODUCT: Just update the default variant with correct price/SKU
            try {
                $query = '
                    query getProduct($id: ID!) {
                        product(id: $id) {
                            variants(first: 1) {
                                edges {
                                    node {
                                        id
                                    }
                                }
                            }
                        }
                    }
                ';
                
                $result = $this->execute($query, ['id' => $productId]);
                
                if (isset($result['data']['product']['variants']['edges'][0]['node']['id'])) {
                    $defaultVariantId = $result['data']['product']['variants']['edges'][0]['node']['id'];
                    $this->updateDefaultVariantBulk($productId, $deduplicatedVariants[0]);
                    error_log("Updated default variant for simple product");
                }
            } catch (\Exception $e) {
                error_log("Warning: Could not update default variant for simple product: " . $e->getMessage());
            }
        } else {
            // VARIABLE PRODUCT: Update default variant + create remaining variants
            try {
                $query = '
                    query getProduct($id: ID!) {
                        product(id: $id) {
                            variants(first: 1) {
                                edges {
                                    node {
                                        id
                                    }
                                }
                            }
                        }
                    }
                ';
                
                $result = $this->execute($query, ['id' => $productId]);
                
                if (isset($result['data']['product']['variants']['edges'][0]['node']['id'])) {
                    $defaultVariantId = $result['data']['product']['variants']['edges'][0]['node']['id'];
                    $firstVariant = $deduplicatedVariants[0];
                    
                    $updateMutation = '
                        mutation productVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
                            productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                                productVariants {
                                    id
                                }
                                userErrors {
                                    field
                                    message
                                }
                            }
                        }
                    ';
                    
                    $variantInput = [
                        'id' => $defaultVariantId,
                        'price' => (string)($firstVariant['price'] ?? '0.00'),
                    ];
                    
                    // Set SKU via inventoryItem (2026-01 API requirement)
                    if (!empty($firstVariant['sku'])) {
                        $variantInput['inventoryItem'] = [
                            'sku' => $firstVariant['sku'],
                            'tracked' => false, // Untracked inventory for infinite stock
                        ];
                    }
                    
                    // Set inventory policy to allow continued sales
                    $variantInput['inventoryPolicy'] = 'CONTINUE'; // Allow purchases even when out of stock
                    
                    // NOTE: inventoryQuantities removed - will be set separately after variant update
                    
                    // Add option values for the first variant
                    $optionValues = [];
                    if (isset($firstVariant['option1']) && !empty($firstVariant['option1'])) {
                        $optionValues[] = ['optionName' => $product['options'][0]['name'] ?? 'Option 1', 'name' => $firstVariant['option1']];
                    }
                    if (isset($firstVariant['option2']) && !empty($firstVariant['option2'])) {
                        $optionValues[] = ['optionName' => $product['options'][1]['name'] ?? 'Option 2', 'name' => $firstVariant['option2']];
                    }
                    if (isset($firstVariant['option3']) && !empty($firstVariant['option3'])) {
                        $optionValues[] = ['optionName' => $product['options'][2]['name'] ?? 'Option 3', 'name' => $firstVariant['option3']];
                    }
                    
                    if (!empty($optionValues)) {
                        $variantInput['optionValues'] = $optionValues;
                    }
                    
                    if (isset($firstVariant['taxable'])) {
                        $variantInput['taxable'] = (bool)$firstVariant['taxable'];
                    }
                    
                    $updateResult = $this->execute($updateMutation, [
                        'productId' => $productId,
                        'variants' => [$variantInput]
                    ]);
                    
                    if (!empty($updateResult['data']['productVariantsBulkUpdate']['userErrors'])) {
                        error_log("Warning: Could not update default variant: " . json_encode($updateResult['data']['productVariantsBulkUpdate']['userErrors']));
                    } else {
                        error_log("Successfully updated default variant with first variant data");
                        // Remove the first variant from the list since we just updated the default variant with its data
                        $deduplicatedVariants = array_slice($deduplicatedVariants, 1);
                    }
                }
            } catch (\Exception $e) {
                error_log("Warning: Could not update default variant: " . $e->getMessage());
            }
            
            // Create remaining variants for variable products
            if (!empty($deduplicatedVariants)) {
                $this->addVariantsInBulk($productId, $deduplicatedVariants, $product['options'] ?? []);
            }
        }
            $variantInput = [
                'price' => (string)($variant['price'] ?? '0.00'),
            ];
            
            // Add SKU via inventoryItem (new structure in 2026-01)
            if (!empty($variant['sku'])) {
                $variantInput['inventoryItem'] = [
                    'sku' => $variant['sku'],
                    'tracked' => false, // Untracked inventory for infinite stock
                ];
            }
            
            // Set inventory policy to allow continued sales
            $variantInput['inventoryPolicy'] = 'CONTINUE'; // Allow purchases even when out of stock
            
            // NOTE: inventoryQuantities removed - will be set separately after variant creation
            
            // Add option values for variant
            $optionValues = [];
            if (isset($variant['option1']) && !empty($variant['option1'])) {
                $optionValues[] = [
                    'optionName' => $product['options'][0]['name'] ?? 'Option 1',
                    'name' => $variant['option1']
                ];
            }
            if (isset($variant['option2']) && !empty($variant['option2'])) {
                $optionValues[] = [
                    'optionName' => $product['options'][1]['name'] ?? 'Option 2',
                    'name' => $variant['option2']
                ];
            }
            if (isset($variant['option3']) && !empty($variant['option3'])) {
                $optionValues[] = [
                    'optionName' => $product['options'][2]['name'] ?? 'Option 3',
                    'name' => $variant['option3']
                ];
            }
            
            if (!empty($optionValues)) {
                $variantInput['optionValues'] = $optionValues;
            }
            
            // Add inventory quantities - REMOVE THIS AS IT CAUSES ERRORS
            // if (isset($variant['inventory_quantity'])) {
            //     $variantInput['inventoryQuantities'] = [
            //         [
            //             'availableQuantity' => (int)$variant['inventory_quantity'],
            //             'locationId' => 'gid://shopify/Location/1',
            //         ]
            //     ];
            // }
            
            // Add compare at price
            if (isset($variant['compare_at_price']) && $variant['compare_at_price'] > 0) {
                $variantInput['compareAtPrice'] = (string)$variant['compare_at_price'];
            }
            
            // Add barcode (replaces weight for now)
            if (!empty($variant['barcode'])) {
                $variantInput['barcode'] = $variant['barcode'];
            }
            
            // Add taxable
            if (isset($variant['taxable'])) {
                $variantInput['taxable'] = (bool)$variant['taxable'];
            }
            
            $variants[] = $variantInput;
    }

    /**
                mutation productVariantsBulkCreate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
                    productVariantsBulkCreate(productId: $productId, variants: $variants) {
                        productVariants {
                            id
                            sku
                        }
                        userErrors {
                            field
                            message
                        }
                    }
                }
            ';
            
            $variables = [
                'productId' => $productId,
                'variants' => $chunk,
            ];
            
            $result = $this->execute($mutation, $variables);
            
            if (!empty($result['data']['productVariantsBulkCreate']['userErrors'])) {
                $errors = $result['data']['productVariantsBulkCreate']['userErrors'];
                
                // Check if it's a "variant already exists" error for single variant products
                $isDefaultVariantError = count($product['variants']) === 1 && 
                    empty($product['options']) &&
                    strpos(json_encode($errors), 'already exists') !== false;
                
                if ($isDefaultVariantError) {
                    // For single variant products, update the default variant using bulkUpdate
                    $this->updateDefaultVariantBulk($productId, $product['variants'][0]);
                } else {
                    throw new \Exception("Variant creation failed: " . json_encode($errors));
                }
            } else {
                // Variants created successfully - now handle inventory
                if (!empty($result['data']['productVariantsBulkCreate']['productVariants'])) {
                    $createdVariants = $result['data']['productVariantsBulkCreate']['productVariants'];
                    
                    // Map created variants back to original variants for inventory adjustment
                    foreach ($createdVariants as $index => $createdVariant) {
                        $originalVariant = $chunk[$index] ?? null;
                        if ($originalVariant && $createdVariant) {
                            // Variant created successfully with untracked inventory
                            echo "✅ Created variant with untracked inventory: {$createdVariant['id']}" . PHP_EOL;
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Update the default variant using productVariantsBulkUpdate
     */
    private function updateDefaultVariantBulk(string $productId, array $variant): void
    {
        // First, get the default variant ID
        $query = '
            query getProductVariants($id: ID!) {
                product(id: $id) {
                    variants(first: 1) {
                        edges {
                            node {
                                id
                            }
                        }
                    }
                }
            }
        ';
        
        $result = $this->execute($query, ['id' => $productId]);
        
        if (empty($result['data']['product']['variants']['edges'])) {
            return; // No variants to update
        }
        
        $variantId = $result['data']['product']['variants']['edges'][0]['node']['id'];
        
        // Update using bulk update - WITHOUT inventoryQuantities (use separate inventory update)
        $mutation = '
            mutation productVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
                productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                    productVariants {
                        id
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        ';
        
        $variantInput = [
            'id' => $variantId,
            'price' => (string)($variant['price'] ?? '0.00'),
        ];
        
        // Add inventory item for SKU
        if (!empty($variant['sku'])) {
            $variantInput['inventoryItem'] = [
                'sku' => $variant['sku'],
                'tracked' => false, // Untracked inventory for infinite stock
            ];
        }
        
        // Set inventory policy to allow continued sales
        $variantInput['inventoryPolicy'] = 'CONTINUE'; // Allow purchases even when out of stock
        
        // NOTE: inventoryQuantities removed - will be set separately after variant update
        
        // Add taxable
        if (isset($variant['taxable'])) {
            $variantInput['taxable'] = (bool)$variant['taxable'];
        }
        
        $updateResult = $this->execute($mutation, [
            'productId' => $productId,
            'variants' => [$variantInput]
        ]);
        
        if (!empty($updateResult['data']['productVariantsBulkUpdate']['userErrors'])) {
            $errors = $updateResult['data']['productVariantsBulkUpdate']['userErrors'];
            throw new \Exception("Variant update failed: " . json_encode($errors));
        }
        
        // Variant updated successfully with untracked inventory (set during creation)
        echo "✅ Updated default variant with untracked inventory: {$variantId}" . PHP_EOL;
    }
    
    private $mainLocationId;
    
    /**
     * Get the main location ID for inventory
     */
    private function getMainLocationId(): string
    {
        // For most Shopify stores, the main location ID is typically '1'
        if (!isset($this->mainLocationId)) {
            $query = '
                query {
                    locations(first: 1) {
                        edges {
                            node {
                                id
                            }
                        }
                    }
                }
            ';
            
            $result = $this->execute($query);
            
            if (!empty($result['data']['locations']['edges'])) {
                $locationId = $result['data']['locations']['edges'][0]['node']['id'];
                // Extract just the ID number from the GraphQL ID
                $this->mainLocationId = str_replace('gid://shopify/Location/', '', $locationId);
            } else {
                // Fallback to default - most stores use location ID '1'
                $this->mainLocationId = '1';
            }
        }
        
        return $this->mainLocationId;
    }

}