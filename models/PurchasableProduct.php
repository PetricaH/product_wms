<?php
/**
 * PurchasableProduct Model
 * Handles products that can be purchased from suppliers
 */

class PurchasableProduct {
    private PDO $conn;
    private string $table = 'purchasable_products';
    private ?string $lastError = null;

    public function __construct(PDO $database) {
        $this->conn = $database;
    }

    public function getLastError(): ?string {
        return $this->lastError;
    }

    private function rememberError(?string $message = null): void {
        $this->lastError = $message;
        if ($message) {
            error_log('[PurchasableProduct] ' . $message);
        }
    }

    /**
     * Get all active purchasable products
     * @return array
     */
    public function getAllProducts(): array {
        $query = "SELECT pp.*, s.supplier_name, p.name as internal_product_name 
                  FROM {$this->table} pp
                  LEFT JOIN sellers s ON pp.preferred_seller_id = s.id
                  LEFT JOIN products p ON pp.internal_product_id = p.product_id
                  WHERE pp.status = 'active' 
                  ORDER BY pp.supplier_product_name ASC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting purchasable products: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get purchasable product by ID
     * @param int $productId
     * @return array|false
     */
    public function getProductById(int $productId): array|false {
        $query = "SELECT pp.*, s.supplier_name, p.name as internal_product_name 
                  FROM {$this->table} pp
                  LEFT JOIN sellers s ON pp.preferred_seller_id = s.id
                  LEFT JOIN products p ON pp.internal_product_id = p.product_id
                  WHERE pp.id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting purchasable product by ID: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create new purchasable product
     * @param array $productData
     * @return int|false Product ID on success, false on failure
     */
    public function createProduct(array $productData): int|false {
        $this->rememberError(null);
        $query = "INSERT INTO {$this->table} (
            supplier_product_name, supplier_product_code, description,
            unit_measure, last_purchase_price, currency, internal_product_id,
            preferred_seller_id, notes, status
        ) VALUES (
            :supplier_product_name, :supplier_product_code, :description,
            :unit_measure, :last_purchase_price, :currency, :internal_product_id,
            :preferred_seller_id, :notes, :status
        )";
        
        try {
            $stmt = $this->conn->prepare($query);
            
            $stmt->bindValue(':supplier_product_name', $productData['supplier_product_name']);
            $stmt->bindValue(':supplier_product_code', $productData['supplier_product_code'] ?? null);
            $stmt->bindValue(':description', $productData['description'] ?? null);
            $stmt->bindValue(':unit_measure', $productData['unit_measure'] ?? 'bucata');
            $stmt->bindValue(':last_purchase_price', $productData['last_purchase_price'] ?? null);
            $stmt->bindValue(':currency', $productData['currency'] ?? 'RON');
            $stmt->bindValue(':internal_product_id', $productData['internal_product_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(':preferred_seller_id', $productData['preferred_seller_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(':notes', $productData['notes'] ?? null);
            $stmt->bindValue(':status', $productData['status'] ?? 'active');
            
            if ($stmt->execute()) {
                $productId = (int)$this->conn->lastInsertId();

                // Log activity
                if (isset($_SESSION['user_id']) && function_exists('logActivity')) {
                    logActivity(
                        $_SESSION['user_id'],
                        'create',
                        'purchasable_product',
                        $productId,
                        'Purchasable product created: ' . $productData['supplier_product_name']
                    );
                }

                return $productId;
            }

            $errorInfo = $stmt->errorInfo();
            $this->rememberError('Failed to create purchasable product: ' . ($errorInfo[2] ?? 'Unknown database error'));
            return false;
        } catch (PDOException $e) {
            $this->rememberError('Error creating purchasable product: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update purchasable product
     * @param int $productId
     * @param array $productData
     * @return bool
     */
    public function updateProduct(int $productId, array $productData): bool {
        $fields = [];
        $params = [':id' => $productId];
        
        $allowedFields = [
            'supplier_product_name', 'supplier_product_code', 'description',
            'unit_measure', 'last_purchase_price', 'currency', 'internal_product_id',
            'preferred_seller_id', 'notes', 'status'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($productData[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $productData[$field];
            }
        }
        
        if (empty($fields)) {
            $this->rememberError('No fields provided for product update.');
            return false;
        }
        
        $query = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute($params);

            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                $this->rememberError('Failed to update purchasable product: ' . ($errorInfo[2] ?? 'Unknown database error'));
                return false;
            }

            if (isset($_SESSION['user_id']) && function_exists('logActivity')) {
                logActivity(
                    $_SESSION['user_id'],
                    'update',
                    'purchasable_product',
                    $productId,
                    'Purchasable product updated'
                );
            }

            return true;
        } catch (PDOException $e) {
            $this->rememberError('Error updating purchasable product: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Search purchasable products
     * @param string $search
     * @return array
     */
    public function searchProducts(string $search): array {
        $query = "SELECT pp.*, s.supplier_name, p.name as internal_product_name 
                  FROM {$this->table} pp
                  LEFT JOIN sellers s ON pp.preferred_seller_id = s.id
                  LEFT JOIN products p ON pp.internal_product_id = p.product_id
                  WHERE pp.status = 'active' 
                  AND (pp.supplier_product_name LIKE :search 
                       OR pp.supplier_product_code LIKE :search 
                       OR pp.description LIKE :search)
                  ORDER BY pp.supplier_product_name ASC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':search', '%' . $search . '%');
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error searching purchasable products: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get products by seller
     * @param int $sellerId
     * @return array
     */
    public function getProductsBySeller(int $sellerId): array {
        $query = "SELECT * FROM {$this->table} 
                  WHERE preferred_seller_id = :seller_id 
                  AND status = 'active'
                  ORDER BY supplier_product_name ASC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':seller_id', $sellerId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting products by seller: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Update last purchase price
     * @param int $productId
     * @param float $price
     * @param string $currency
     * @return bool
     */
    public function updateLastPurchasePrice(int $productId, float $price, string $currency = 'RON'): bool {
        $query = "UPDATE {$this->table} 
                  SET last_purchase_price = :price, currency = :currency 
                  WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
            $stmt->bindValue(':price', $price);
            $stmt->bindValue(':currency', $currency);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error updating last purchase price: " . $e->getMessage());
            return false;
        }
    }

    // find purchasable product by name
    public function findByName(string $productName): array|false {
        $query = "SELECT * FROM {$this->table}
                WHERE supplier_product_name = :product_name
                AND status = 'active'
                LIMIT 1";
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':product_name', $productName);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error finding purchasable product by name: " . $e->getMessage());
            return false;
        }
    }
}