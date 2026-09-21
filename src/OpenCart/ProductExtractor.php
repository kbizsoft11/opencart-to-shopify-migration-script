<?php
/**
 * OpenCart Product Data Extractor
 * Safely reads product data from OpenCart database
 */

namespace OpenCart;

class ProductExtractor
{
    private Database $db;
    private int $languageId;
    private int $storeId;
    
    public function __construct(Database $db, int $languageId = 1, int $storeId = 0)
    {
        $this->db = $db;
        $this->languageId = $languageId;
        $this->storeId = $storeId;
    }
    
    /**
     * Get products with optional filters
     */
    public function getProducts(?int $limit = null, ?int $productId = null): array
    {
        $sql = "
            SELECT p.*
            FROM {$this->db->table('product')} p
        ";
        
        $params = [];
        
        if ($productId !== null) {
            $sql .= " WHERE p.product_id = ?";
            $params[] = $productId;
        }
        
        $sql .= " ORDER BY p.product_id ASC";
        
        if ($limit !== null) {
            $sql .= " LIMIT " . (int)$limit;
        }
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get product description
     */
    public function getProductDescription(int $productId): ?array
    {
        $sql = "
            SELECT *
            FROM {$this->db->table('product_description')}
            WHERE product_id = ? AND language_id = ?
        ";
        
        return $this->db->fetchOne($sql, [$productId, $this->languageId]);
    }
    
    /**
     * Get product options
     */
    public function getProductOptions(int $productId): array
    {
        $sql = "
            SELECT po.*, od.name as option_name, o.type as option_type
            FROM {$this->db->table('product_option')} po
            LEFT JOIN {$this->db->table('option')} o ON po.option_id = o.option_id
            LEFT JOIN {$this->db->table('option_description')} od 
                ON po.option_id = od.option_id AND od.language_id = ?
            WHERE po.product_id = ?
            ORDER BY po.product_option_id
        ";
        
        return $this->db->fetchAll($sql, [$this->languageId, $productId]);
    }
    
    /**
     * Get product option values
     */
    public function getProductOptionValues(int $productId, int $productOptionId): array
    {
        $sql = "
            SELECT pov.*, 
                   ovd.name as option_value_name,
                   ov.image as option_value_image
            FROM {$this->db->table('product_option_value')} pov
            LEFT JOIN {$this->db->table('option_value')} ov 
                ON pov.option_value_id = ov.option_value_id
            LEFT JOIN {$this->db->table('option_value_description')} ovd 
                ON pov.option_value_id = ovd.option_value_id AND ovd.language_id = ?
            WHERE pov.product_id = ? AND pov.product_option_id = ?
            ORDER BY pov.product_option_value_id
        ";
        
        return $this->db->fetchAll($sql, [$this->languageId, $productId, $productOptionId]);
    }
    
    /**
     * Get all option values for a product (for variant generation)
     */
    public function getAllProductOptionValues(int $productId): array
    {
        $sql = "
            SELECT pov.*, 
                   ovd.name as option_value_name,
                   ov.image as option_value_image,
                   po.option_id,
                   od.name as option_name,
                   o.type as option_type
            FROM {$this->db->table('product_option_value')} pov
            LEFT JOIN {$this->db->table('product_option')} po 
                ON pov.product_option_id = po.product_option_id
            LEFT JOIN {$this->db->table('option')} o 
                ON po.option_id = o.option_id
            LEFT JOIN {$this->db->table('option_description')} od 
                ON po.option_id = od.option_id AND od.language_id = ?
            LEFT JOIN {$this->db->table('option_value')} ov 
                ON pov.option_value_id = ov.option_value_id
            LEFT JOIN {$this->db->table('option_value_description')} ovd 
                ON pov.option_value_id = ovd.option_value_id AND ovd.language_id = ?
            WHERE pov.product_id = ?
            ORDER BY po.product_option_id, pov.product_option_value_id
        ";
        
        return $this->db->fetchAll($sql, [$this->languageId, $this->languageId, $productId]);
    }
    
    /**
     * Get product attributes
     */
    public function getProductAttributes(int $productId): array
    {
        if (!$this->db->tableExists('product_attribute')) {
            return [];
        }
        
        $sql = "
            SELECT pa.*, 
                   ad.name as attribute_name,
                   agd.name as attribute_group_name
            FROM {$this->db->table('product_attribute')} pa
            LEFT JOIN {$this->db->table('attribute')} a ON pa.attribute_id = a.attribute_id
            LEFT JOIN {$this->db->table('attribute_description')} ad 
                ON pa.attribute_id = ad.attribute_id AND ad.language_id = ?
            LEFT JOIN {$this->db->table('attribute_group')} ag 
                ON a.attribute_group_id = ag.attribute_group_id
            LEFT JOIN {$this->db->table('attribute_group_description')} agd 
                ON ag.attribute_group_id = agd.attribute_group_id AND agd.language_id = ?
            WHERE pa.product_id = ? AND pa.language_id = ?
            ORDER BY a.attribute_group_id, a.sort_order
        ";
        
        return $this->db->fetchAll($sql, [$this->languageId, $this->languageId, $productId, $this->languageId]);
    }
    
    /**
     * Get product images
     */
    public function getProductImages(int $productId): array
    {
        $sql = "
            SELECT *
            FROM {$this->db->table('product_image')}
            WHERE product_id = ?
            ORDER BY sort_order, product_image_id
        ";
        
        return $this->db->fetchAll($sql, [$productId]);
    }
    
    /**
     * Get product categories
     */
    public function getProductCategories(int $productId): array
    {
        $sql = "
            SELECT c.*, cd.name as category_name, cd.description as category_description
            FROM {$this->db->table('product_to_category')} ptc
            LEFT JOIN {$this->db->table('category')} c ON ptc.category_id = c.category_id
            LEFT JOIN {$this->db->table('category_description')} cd 
                ON c.category_id = cd.category_id AND cd.language_id = ?
            WHERE ptc.product_id = ?
            ORDER BY c.parent_id, c.sort_order
        ";
        
        return $this->db->fetchAll($sql, [$this->languageId, $productId]);
    }
    
    /**
     * Get product manufacturer
     */
    public function getProductManufacturer(int $manufacturerId): ?array
    {
        if ($manufacturerId <= 0) {
            return null;
        }
        
        $sql = "
            SELECT *
            FROM {$this->db->table('manufacturer')}
            WHERE manufacturer_id = ?
        ";
        
        return $this->db->fetchOne($sql, [$manufacturerId]);
    }
    
    /**
     * Get product SEO URL
     */
    public function getProductSeoUrl(int $productId): ?string
    {
        // Try oc_seo_url first (OpenCart 2.3+)
        if ($this->db->tableExists('seo_url')) {
            $sql = "
                SELECT keyword
                FROM {$this->db->table('seo_url')}
                WHERE query = ? AND store_id = ? AND language_id = ?
                LIMIT 1
            ";
            
            $result = $this->db->fetchOne($sql, ["product_id={$productId}", $this->storeId, $this->languageId]);
            if ($result) {
                return $result['keyword'];
            }
        }
        
        // Fallback to oc_url_alias (older OpenCart)
        if ($this->db->tableExists('url_alias')) {
            $sql = "
                SELECT keyword
                FROM {$this->db->table('url_alias')}
                WHERE query = ?
                LIMIT 1
            ";
            
            $result = $this->db->fetchOne($sql, ["product_id={$productId}"]);
            if ($result) {
                return $result['keyword'];
            }
        }
        
        return null;
    }
    
    /**
     * Get complete product data with all relationships
     */
    public function getCompleteProduct(int $productId): array
    {
        $product = $this->db->fetchOne(
            "SELECT * FROM {$this->db->table('product')} WHERE product_id = ?",
            [$productId]
        );
        
        if (!$product) {
            throw new \Exception("Product {$productId} not found");
        }
        
        return [
            'product' => $product,
            'description' => $this->getProductDescription($productId),
            'options' => $this->getProductOptions($productId),
            'option_values' => $this->getAllProductOptionValues($productId),
            'attributes' => $this->getProductAttributes($productId),
            'images' => $this->getProductImages($productId),
            'categories' => $this->getProductCategories($productId),
            'manufacturer' => $this->getProductManufacturer((int)$product['manufacturer_id']),
            'seo_url' => $this->getProductSeoUrl($productId),
        ];
    }
    
    /**
     * Get representative sample products for testing
     */
    public function getSampleProducts(int $count = 20): array
    {
        $productIds = [];
        
        // Get some products with different characteristics
        
        // 1. Simple products (no options)
        $sql = "
            SELECT p.product_id
            FROM {$this->db->table('product')} p
            LEFT JOIN {$this->db->table('product_option')} po ON p.product_id = po.product_id
            WHERE po.product_id IS NULL AND p.status = 1
            LIMIT 5
        ";
        $productIds = array_merge($productIds, $this->db->fetchColumn($sql));
        
        // 2. Products with options
        $sql = "
            SELECT DISTINCT p.product_id
            FROM {$this->db->table('product')} p
            INNER JOIN {$this->db->table('product_option')} po ON p.product_id = po.product_id
            WHERE p.status = 1
            LIMIT 10
        ";
        $productIds = array_merge($productIds, $this->db->fetchColumn($sql));
        
        // 3. Products with multiple images
        $sql = "
            SELECT DISTINCT p.product_id
            FROM {$this->db->table('product')} p
            INNER JOIN {$this->db->table('product_image')} pi ON p.product_id = pi.product_id
            WHERE p.status = 1
            LIMIT 5
        ";
        $productIds = array_merge($productIds, $this->db->fetchColumn($sql));
        
        // Remove duplicates and limit
        $productIds = array_unique($productIds);
        $productIds = array_slice($productIds, 0, $count);
        
        return array_map('intval', $productIds);
    }
}
