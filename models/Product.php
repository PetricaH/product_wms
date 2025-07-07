<?php

// File: models/Product.php - Adapted for your existing database schema
class Product {
    // database connection property
    private $conn;
    
    // database table name (matching your existing table)
    private $table = "products";
    
    // Configurable fields for modular display (adapted to your schema)
    private $defaultFields = [
        'product_id', 'sku', 'name', 'description', 'category', 'quantity',
        'min_stock_level', 'price', 'weight', 'dimensions', 'barcode', 
        'image_url', 'status', 'created_at', 'updated_at', 'smartbill_product_id'
    ];
    
    // Essential fields that should always be included
    private $essentialFields = ['product_id', 'sku', 'name'];
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Get all products with optional field selection and filtering
     * @param array $fields Fields to select (null for all)
     * @param array $filters Filters to apply
     * @param int $limit Limit for pagination
     * @param int $offset Offset for pagination
     * @return PDOStatement|false
     */
    public function getAll($fields = null, $filters = [], $limit = null, $offset = 0) {
        // Determine which fields to select
        $selectFields = $this->buildSelectFields($fields);
        
        // Build the base query
        $query = "SELECT {$selectFields} FROM {$this->table}";
        $params = [];
        
        // Add WHERE conditions
        $whereConditions = $this->buildWhereConditions($filters, $params);
        if (!empty($whereConditions)) {
            $query .= " WHERE " . implode(' AND ', $whereConditions);
        }
        
        // Add ordering
        $query .= " ORDER BY created_at DESC";
        
        // Add pagination
        if ($limit !== null) {
            $query .= " LIMIT :limit";
            if ($offset > 0) {
                $query .= " OFFSET :offset";
            }
            $params[':limit'] = $limit;
            if ($offset > 0) {
                $params[':offset'] = $offset;
            }
        }
        
        try {
            $stmt = $this->conn->prepare($query);
            
            // Bind parameters with proper types
            foreach ($params as $key => $value) {
                if ($key === ':limit' || $key === ':offset') {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value, PDO::PARAM_STR);
                }
            }
            
            $stmt->execute();
            return $stmt;
        } catch (PDOException $e) {
            error_log("Error fetching products: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all products for dropdown (no limit)
     * @return array Array of all products
     */
    public function getAllProductsForDropdown(): array {
        $query = "SELECT product_id, sku, name FROM {$this->table} ORDER BY name ASC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting all products for dropdown: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all products with optional filters and limit
     * @param array $filters Optional filters
     * @param int $limit Maximum number of results
     * @return array Array of products
     */
    public function getAllProducts(array $filters = [], int $limit = 100): array {
        $query = "SELECT * FROM {$this->table} WHERE 1=1";
        $params = [];
        
        // Apply filters
        if (!empty($filters['search'])) {
            $query .= " AND (name LIKE :search OR sku LIKE :search OR description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        if (!empty($filters['category'])) {
            $query .= " AND category = :category";
            $params[':category'] = $filters['category'];
        }
        
        $query .= " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        
        try {
            $stmt = $this->conn->prepare($query);
            
            // Bind limit parameter separately for proper type
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            
            // Bind other parameters
            foreach ($params as $key => $value) {
                if ($key !== ':limit') {
                    $stmt->bindValue($key, $value);
                }
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting all products: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get a single product by ID
     * @param int $id Product ID
     * @return array|false Product data or false if not found
     */
    public function findById($id) {
        $query = "SELECT * FROM {$this->table} WHERE product_id = :id LIMIT 1";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            return $product ?: false;
        } catch (PDOException $e) {
            error_log("Error finding product by ID: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get a product by SKU
     * @param string $sku Product SKU
     * @return array|false Product data or false if not found
     */
    public function findBySku($sku) {
        $query = "SELECT * FROM {$this->table} WHERE sku = :sku LIMIT 1";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':sku', $sku, PDO::PARAM_STR);
            $stmt->execute();
            
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            return $product ?: false;
        } catch (PDOException $e) {
            error_log("Error finding product by SKU: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create a new product (adapted to your schema)
     * @param array $data Product data
     * @return int|false Product ID on success, false on failure
     */
    public function create($data) {
        // Validate required fields
        if (empty($data['sku']) || empty($data['name'])) {
            error_log("Product creation failed: SKU and name are required");
            return false;
        }
        
        // Check if SKU already exists
        if ($this->skuExists($data['sku'])) {
            error_log("Product creation failed: SKU already exists");
            return false;
        }
        
        // Map fields to your existing schema
        $mappedData = $this->mapToExistingSchema($data);
        
        $fields = [];
        $placeholders = [];
        $params = [];
        
        // Build dynamic insert query based on provided data
        foreach ($mappedData as $field => $value) {
            if (in_array($field, $this->defaultFields) && $field !== 'product_id') {
                $fields[] = $field;
                $placeholders[] = ":{$field}";
                $params[":{$field}"] = $value;
            }
        }
        
        $query = "INSERT INTO {$this->table} (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            return (int)$this->conn->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error creating product: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update a product (adapted to your schema)
     * @param int $id Product ID
     * @param array $data Product data to update
     * @return bool Success status
     */
    public function update($id, $data) {
        if (empty($data)) {
            return false;
        }
        
        // Check if SKU is being changed and if it already exists
        if (isset($data['sku']) && $this->skuExists($data['sku'], $id)) {
            error_log("Product update failed: SKU already exists");
            return false;
        }
        
        // Map fields to your existing schema
        $mappedData = $this->mapToExistingSchema($data);
        
        $fields = [];
        $params = [':id' => $id];
        
        // Build dynamic update query
        foreach ($mappedData as $field => $value) {
            if (in_array($field, $this->defaultFields) && $field !== 'product_id') {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $value;
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $query = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE product_id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error updating product: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete a product (mark quantity as 0 since you don't have status field)
     * @param int $id Product ID
     * @return bool Success status
     */
    public function delete($id) {
        // In your schema, we'll set quantity to 0 to "disable" the product
        $query = "UPDATE {$this->table} SET quantity = 0 WHERE product_id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error deleting product: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Permanently delete a product (hard delete)
     * @param int $id Product ID
     * @return bool Success status
     */
    public function hardDelete($id) {
        $query = "DELETE FROM {$this->table} WHERE product_id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error hard deleting product: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Count all products (your existing method, adapted)
     */
    public function countAll() {
        $query = 'SELECT COUNT(*) as total FROM ' . $this->table;
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return isset($row['total']) ? (int)$row['total'] : 0;
        } catch (PDOException $e) {
            error_log("Error counting products: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get total quantity from inventory table (proper WMS approach)
     */
    public function getTotalQuantity(): int {
        $query = 'SELECT SUM(quantity) as total_stock FROM inventory';
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return isset($row['total_stock']) ? (int)$row['total_stock'] : 0;
        } catch (PDOException $e) {
            error_log("Error summing total product quantity: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get products with current inventory levels
     * @return array Products with inventory data
     */
    public function getProductsWithInventory() {
        $query = "SELECT p.*, 
                         COALESCE(SUM(i.quantity), 0) as current_stock,
                         COUNT(DISTINCT i.location_id) as locations_count
                  FROM {$this->table} p
                  LEFT JOIN inventory i ON p.product_id = i.product_id
                  GROUP BY p.product_id
                  ORDER BY p.created_at DESC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching products with inventory: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if SKU exists
     * @param string $sku SKU to check
     * @param int $excludeId Exclude this product ID (for updates)
     * @return bool True if SKU exists
     */
    public function skuExists($sku, $excludeId = 0) {
        $query = "SELECT COUNT(*) FROM {$this->table} WHERE sku = :sku";
        $params = [':sku' => $sku];
        
        if ($excludeId > 0) {
            $query .= " AND product_id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Error checking SKU existence: " . $e->getMessage());
            return true; // Err on the side of caution
        }
    }
    
    /**
     * Generate a unique SKU
     * @param string $prefix SKU prefix
     * @return string Generated SKU
     */
    public function generateSku($prefix = 'PRD') {
        $attempts = 0;
        $maxAttempts = 100;
        
        do {
            $randomPart = str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $sku = $prefix . '-' . $randomPart;
            $attempts++;
        } while ($this->skuExists($sku) && $attempts < $maxAttempts);
        
        if ($attempts >= $maxAttempts) {
            // Fallback to timestamp-based SKU
            $sku = $prefix . '-' . time();
        }
        
        return $sku;
    }
    
    /**
     * Get low stock products (using your existing schema)
     * @return array Products below minimum stock level
     */
    public function getLowStockProducts() {
        $query = "SELECT p.*, 
                         COALESCE(SUM(i.quantity), 0) as current_stock
                  FROM {$this->table} p
                  LEFT JOIN inventory i ON p.product_id = i.product_id
                  WHERE p.min_stock_level > 0
                  GROUP BY p.product_id
                  HAVING current_stock <= p.min_stock_level
                  ORDER BY (current_stock / NULLIF(p.min_stock_level, 0)) ASC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching low stock products: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get categories (from your existing category field)
     * @return array Distinct categories
     */
    public function getCategories() {
        $query = "SELECT DISTINCT category FROM {$this->table} WHERE category IS NOT NULL AND category != '' ORDER BY category ASC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            // **FIX**: Use FETCH_COLUMN to return a simple array of strings, which is what the view expects.
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            error_log("Error fetching categories: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get configurable fields for display (adapted to your schema)
     * @return array Available fields for configuration
     */
    public function getConfigurableFields() {
        return [
            'sku' => 'SKU',
            'name' => 'Product Name',
            'description' => 'Description',
            'category' => 'Category',
            'quantity' => 'Base Quantity',
            'min_stock_level' => 'Min Stock Level',
            'price' => 'Price',
            'created_at' => 'Created Date'
        ];
    }
    
    // Private helper methods
    
    /**
     * Map new schema fields to your existing schema
     * @param array $data Input data
     * @return array Mapped data
     */
    private function mapToExistingSchema($data) {
        $mapped = [];
        
        // Direct mappings
        $directMappings = [
            'sku' => 'sku',
            'name' => 'name',
            'description' => 'description',
            'category' => 'category',
            'quantity' => 'quantity',
            'min_stock_level' => 'min_stock_level',
            'price' => 'price'
        ];
        
        foreach ($directMappings as $newField => $existingField) {
            if (isset($data[$newField])) {
                $mapped[$existingField] = $data[$newField];
            }
        }
        
        // Handle field mappings that need conversion
        if (isset($data['unit_price'])) {
            $mapped['price'] = $data['unit_price'];
        }
        
        if (isset($data['category_id'])) {
            // If you have category_id, you might want to map it to category name
            // For now, we'll just use it as is
            $mapped['category'] = $data['category_id'];
        }
        
        return $mapped;
    }
    
    private function buildSelectFields($fields) {
        if ($fields === null) {
            return '*';
        }
        
        // Always include essential fields
        $selectedFields = array_unique(array_merge($this->essentialFields, $fields));
        
        // Filter to only allowed fields
        $validFields = array_intersect($selectedFields, $this->defaultFields);
        
        return implode(', ', $validFields);
    }
    
    private function buildWhereConditions($filters, &$params) {
        $conditions = [];
        
        // Text search
        if (!empty($filters['search'])) {
            $conditions[] = '(name LIKE :search OR sku LIKE :search OR description LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        // Category filter
        if (!empty($filters['category'])) {
            $conditions[] = 'category = :category';
            $params[':category'] = $filters['category'];
        }
        
        // Price range
        if (!empty($filters['min_price'])) {
            $conditions[] = 'price >= :min_price';
            $params[':min_price'] = $filters['min_price'];
        }
        
        if (!empty($filters['max_price'])) {
            $conditions[] = 'price <= :max_price';
            $params[':max_price'] = $filters['max_price'];
        }
        
        // Stock level filters
        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $conditions[] = 'quantity <= min_stock_level';
        }
        
        return $conditions;
    }

    /**
     * Get total count of products with filters
     * @param string $search Search term
     * @param string $category Category filter
     * @return int Total count
     */
    public function getTotalCount(string $search = '', string $category = ''): int {
        $query = "SELECT COUNT(*) as total FROM {$this->table} WHERE 1=1";
        $params = [];
        
        if (!empty($search)) {
            $query .= " AND (name LIKE :search OR sku LIKE :search OR description LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        if (!empty($category)) {
            $query .= " AND category = :category";
            $params[':category'] = $category;
        }
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['total'] ?? 0);
        } catch (PDOException $e) {
            error_log("Error getting total count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get products with pagination and filters
     * @param int $pageSize Number of products per page
     * @param int $offset Starting offset
     * @param string $search Search term
     * @param string $category Category filter
     * @return array Array of products
     */
    public function getProductsPaginated(int $pageSize, int $offset, string $search = '', string $category = ''): array {
        $query = "SELECT * FROM {$this->table} WHERE 1=1";
        $params = [];
        
        if (!empty($search)) {
            $query .= " AND (name LIKE :search OR sku LIKE :search OR description LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        if (!empty($category)) {
            $query .= " AND category = :category";
            $params[':category'] = $category;
        }
        
        $query .= " ORDER BY name ASC LIMIT :limit OFFSET :offset";
        
        try {
            $stmt = $this->conn->prepare($query);
            
            // Bind search parameters
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            // Bind pagination parameters
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting paginated products: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Create a new product
     * @param array $productData Product data
     * @return int|false Product ID on success, false on failure
     */
    public function createProduct(array $productData) {
        // Validate required fields
        if (empty($productData['name']) || empty($productData['sku'])) {
            error_log("Product creation failed: SKU and name are required");
            return false;
        }
        
        // Check if SKU already exists
        if ($this->skuExists($productData['sku'])) {
            error_log("Product creation failed: SKU already exists - " . $productData['sku']);
            return false;
        }
        
        // Only use fields that actually exist in your database
        $query = "INSERT INTO {$this->table} (sku, name, description, category, quantity, price, created_at) 
                VALUES (:sku, :name, :description, :category, :quantity, :price, NOW())";
        
        try {
            $stmt = $this->conn->prepare($query);
            $params = [
                ':sku' => $productData['sku'],
                ':name' => $productData['name'],
                ':description' => $productData['description'] ?? '',
                ':category' => $productData['category'] ?? '',
                ':quantity' => intval($productData['quantity'] ?? 0),
                ':price' => floatval($productData['price'] ?? 0)
            ];
            
            $success = $stmt->execute($params);
            if ($success) {
                return $this->conn->lastInsertId();
            } else {
                error_log("Product creation failed for SKU: " . $productData['sku'] . " - Execute returned false");
                return false;
            }
        } catch (PDOException $e) {
            error_log("Error creating product " . $productData['sku'] . ": " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update an existing product
     * @param int $productId Product ID
     * @param array $productData Product data
     * @return bool Success status
     */
    public function updateProduct(int $productId, array $productData): bool {
        if (empty($productData)) {
            return false;
        }
        
        // Check if SKU is being changed and already exists
        if (isset($productData['sku']) && $this->skuExists($productData['sku'], $productId)) {
            error_log("Product update failed: SKU already exists - " . $productData['sku']);
            return false;
        }
        
        // Build dynamic UPDATE query based on provided fields only
        $fields = [];
        $params = [':id' => $productId];
        
        // Map of allowed fields that exist in your database
        $allowedFields = [
            'sku' => ':sku',
            'name' => ':name', 
            'description' => ':description',
            'category' => ':category',
            'quantity' => ':quantity',
            'price' => ':price'
        ];
        
        // Only update fields that are actually provided and exist in database
        foreach ($allowedFields as $field => $param) {
            if (array_key_exists($field, $productData)) {
                $fields[] = "{$field} = {$param}";
                
                // Handle different data types
                if ($field === 'quantity') {
                    $params[$param] = intval($productData[$field]);
                } elseif ($field === 'price') {
                    $params[$param] = floatval($productData[$field]);
                } else {
                    $params[$param] = $productData[$field];
                }
            }
        }
        
        // If no valid fields to update, return false
        if (empty($fields)) {
            error_log("No valid fields to update for product ID: " . $productId);
            return false;
        }
        
        // Add updated_at timestamp
        $fields[] = "updated_at = NOW()";
        
        $query = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE product_id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $success = $stmt->execute($params);
            if (!$success) {
                error_log("Product update failed for ID: " . $productId . " - Execute returned false");
            }
            return $success;
        } catch (PDOException $e) {
            error_log("Error updating product ID " . $productId . ": " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a product
     * @param int $productId Product ID
     * @return bool Success status
     */
    public function deleteProduct(int $productId): bool {
        // chekc for order references
        $checkQuery = "SELECT COUNT(*) FROM order_items WHERE product_id = :id";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':id', $productId, PDO::PARAM_INT);
        $checkStmt->execute();

        if($checkStmt->fetchColumn() > 0) {
            $query = "UPDATE {$this->table} SET status = 'inactive' WHERE product_id = :id";
        } else {
            $query = "DELETE FROM {$this->table} WHERE product_id = :id";
        }

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $productId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error deleting product: " . $e->getMessage());
            return false;
        }
    }
}
