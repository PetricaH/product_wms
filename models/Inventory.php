<?php
/**
 * Complete Enhanced Inventory Model with FIFO support
 * Replaces the existing basic Inventory.php model
 * Maintains compatibility with existing dashboard methods
 */

class Inventory {
    private $conn;
    private $inventoryTable = "inventory";
    private $productsTable = "products";
    private $locationsTable = "locations";

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Get all inventory records with product and location details
     * @param array $filters Optional filters (product_id, location_id, zone)
     * @return array Array of inventory records
     */
    public function getAllInventory(array $filters = []): array {
        $query = "SELECT i.*, 
                         p.sku, p.name as product_name, p.category,
                         l.location_code, l.zone, l.type as location_type
                  FROM {$this->inventoryTable} i
                  LEFT JOIN {$this->productsTable} p ON i.product_id = p.product_id
                  LEFT JOIN {$this->locationsTable} l ON i.location_id = l.id
                  WHERE i.quantity > 0";

        $params = [];

        // Apply filters
        if (!empty($filters['product_id'])) {
            $query .= " AND i.product_id = :product_id";
            $params[':product_id'] = $filters['product_id'];
        }

        if (!empty($filters['location_id'])) {
            $query .= " AND i.location_id = :location_id";
            $params[':location_id'] = $filters['location_id'];
        }

        if (!empty($filters['zone'])) {
            $query .= " AND l.zone = :zone";
            $params[':zone'] = $filters['zone'];
        }

        if (!empty($filters['low_stock'])) {
            $query .= " AND i.quantity <= p.min_stock_level";
        }

        $query .= " ORDER BY p.name ASC, l.location_code ASC, i.received_at ASC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching inventory: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get inventory for a specific product across all locations
     * @param int $productId Product ID
     * @return array Array of inventory records for the product
     */
    public function getProductInventory(int $productId): array {
        $query = "SELECT i.*, l.location_code, l.zone, l.type as location_type
                  FROM {$this->inventoryTable} i
                  LEFT JOIN {$this->locationsTable} l ON i.location_id = l.id
                  WHERE i.product_id = :product_id AND i.quantity > 0
                  ORDER BY i.received_at ASC, i.id ASC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':product_id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching product inventory: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get inventory for a specific location
     * @param int $locationId Location ID
     * @return array Array of inventory records for the location
     */
    public function getLocationInventory(int $locationId): array {
        $query = "SELECT i.*, p.sku, p.name as product_name, p.category, p.min_stock_level
                  FROM {$this->inventoryTable} i
                  LEFT JOIN {$this->productsTable} p ON i.product_id = p.product_id
                  WHERE i.location_id = :location_id AND i.quantity > 0
                  ORDER BY p.name ASC, i.received_at ASC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':location_id', $locationId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching location inventory: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Add stock to inventory (receive goods)
     * @param array $data Inventory data
     * @return int|false Inventory record ID on success, false on failure
     */
    public function addStock(array $data): int|false {
        $requiredFields = ['product_id', 'location_id', 'quantity'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                error_log("Add stock failed: Required field '{$field}' missing");
                return false;
            }
        }

        // Set received_at to current time if not provided
        if (empty($data['received_at'])) {
            $data['received_at'] = date('Y-m-d H:i:s');
        }

        $query = "INSERT INTO {$this->inventoryTable} 
                  (product_id, location_id, quantity, received_at, batch_number, lot_number, expiry_date)
                  VALUES (:product_id, :location_id, :quantity, :received_at, :batch_number, :lot_number, :expiry_date)";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':product_id', $data['product_id'], PDO::PARAM_INT);
            $stmt->bindParam(':location_id', $data['location_id'], PDO::PARAM_INT);
            $stmt->bindParam(':quantity', $data['quantity'], PDO::PARAM_INT);
            $stmt->bindParam(':received_at', $data['received_at'], PDO::PARAM_STR);
            $stmt->bindParam(':batch_number', $data['batch_number'] ?? null, PDO::PARAM_STR);
            $stmt->bindParam(':lot_number', $data['lot_number'] ?? null, PDO::PARAM_STR);
            $stmt->bindParam(':expiry_date', $data['expiry_date'] ?? null, PDO::PARAM_STR);
            $stmt->execute();

            return (int)$this->conn->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error adding stock: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove stock from inventory using FIFO logic
     * @param int $productId Product ID
     * @param int $quantity Quantity to remove
     * @param int|null $locationId Specific location (optional)
     * @return bool Success status
     */
    public function removeStock(int $productId, int $quantity, int $locationId = null): bool {
        if ($quantity <= 0) {
            error_log("Remove stock failed: Invalid quantity");
            return false;
        }

        try {
            $this->conn->beginTransaction();

            // Get available inventory using FIFO (oldest first)
            $query = "SELECT id, location_id, quantity, received_at 
                      FROM {$this->inventoryTable} 
                      WHERE product_id = :product_id AND quantity > 0";
            
            $params = [':product_id' => $productId];
            
            if ($locationId !== null) {
                $query .= " AND location_id = :location_id";
                $params[':location_id'] = $locationId;
            }
            
            $query .= " ORDER BY received_at ASC, id ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $inventoryRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $remainingToRemove = $quantity;
            $removedRecords = [];

            foreach ($inventoryRecords as $record) {
                if ($remainingToRemove <= 0) break;

                $inventoryId = $record['id'];
                $availableQuantity = (int)$record['quantity'];
                $quantityToRemove = min($remainingToRemove, $availableQuantity);

                // Update inventory record
                $updateQuery = "UPDATE {$this->inventoryTable} 
                               SET quantity = quantity - :quantity_to_remove 
                               WHERE id = :inventory_id";
                
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->bindParam(':quantity_to_remove', $quantityToRemove, PDO::PARAM_INT);
                $updateStmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
                $updateStmt->execute();

                $remainingToRemove -= $quantityToRemove;
                $removedRecords[] = [
                    'inventory_id' => $inventoryId,
                    'location_id' => $record['location_id'],
                    'quantity_removed' => $quantityToRemove,
                    'received_at' => $record['received_at']
                ];
            }

            if ($remainingToRemove > 0) {
                $this->conn->rollback();
                error_log("Remove stock failed: Insufficient inventory. Remaining: {$remainingToRemove}");
                return false;
            }

            $this->conn->commit();
            return true;

        } catch (PDOException $e) {
            $this->conn->rollback();
            error_log("Error removing stock: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Move stock from one location to another
     * @param int $inventoryId Inventory record ID
     * @param int $newLocationId New location ID
     * @param int $quantity Quantity to move (optional, moves all if not specified)
     * @return bool Success status
     */
    public function moveStock(int $inventoryId, int $newLocationId, int $quantity = null): bool {
        try {
            $this->conn->beginTransaction();

            // Get current inventory record
            $query = "SELECT * FROM {$this->inventoryTable} WHERE id = :inventory_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
            $stmt->execute();
            $inventoryRecord = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventoryRecord) {
                $this->conn->rollback();
                return false;
            }

            $availableQuantity = (int)$inventoryRecord['quantity'];
            $moveQuantity = $quantity ?? $availableQuantity;

            if ($moveQuantity > $availableQuantity || $moveQuantity <= 0) {
                $this->conn->rollback();
                return false;
            }

            // Create new inventory record at new location
            $insertQuery = "INSERT INTO {$this->inventoryTable} 
                           (product_id, location_id, quantity, received_at, batch_number, lot_number, expiry_date)
                           VALUES (:product_id, :location_id, :quantity, :received_at, :batch_number, :lot_number, :expiry_date)";
            
            $insertStmt = $this->conn->prepare($insertQuery);
            $insertStmt->bindParam(':product_id', $inventoryRecord['product_id'], PDO::PARAM_INT);
            $insertStmt->bindParam(':location_id', $newLocationId, PDO::PARAM_INT);
            $insertStmt->bindParam(':quantity', $moveQuantity, PDO::PARAM_INT);
            $insertStmt->bindParam(':received_at', $inventoryRecord['received_at'], PDO::PARAM_STR);
            $insertStmt->bindParam(':batch_number', $inventoryRecord['batch_number'], PDO::PARAM_STR);
            $insertStmt->bindParam(':lot_number', $inventoryRecord['lot_number'], PDO::PARAM_STR);
            $insertStmt->bindParam(':expiry_date', $inventoryRecord['expiry_date'], PDO::PARAM_STR);
            $insertStmt->execute();

            // Update original inventory record
            if ($moveQuantity == $availableQuantity) {
                // Remove the record if moving all quantity
                $deleteQuery = "DELETE FROM {$this->inventoryTable} WHERE id = :inventory_id";
                $deleteStmt = $this->conn->prepare($deleteQuery);
                $deleteStmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
                $deleteStmt->execute();
            } else {
                // Reduce quantity in original location
                $updateQuery = "UPDATE {$this->inventoryTable} 
                               SET quantity = quantity - :move_quantity 
                               WHERE id = :inventory_id";
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->bindParam(':move_quantity', $moveQuantity, PDO::PARAM_INT);
                $updateStmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
                $updateStmt->execute();
            }

            $this->conn->commit();
            return true;

        } catch (PDOException $e) {
            $this->conn->rollback();
            error_log("Error moving stock: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get products with low stock levels
     * @return array Array of products below minimum stock level
     */
    public function getLowStockProducts(): array {
        $query = "SELECT p.product_id, p.sku, p.name, p.min_stock_level,
                         COALESCE(SUM(i.quantity), 0) as current_stock,
                         COUNT(DISTINCT i.location_id) as locations_count
                  FROM {$this->productsTable} p
                  LEFT JOIN {$this->inventoryTable} i ON p.product_id = i.product_id AND i.quantity > 0
                  GROUP BY p.product_id
                  HAVING current_stock <= p.min_stock_level OR current_stock = 0
                  ORDER BY current_stock ASC, p.name ASC";

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
     * Get stock summary by product
     * @return array Array of products with total stock levels
     */
    public function getStockSummary(): array {
        $query = "SELECT p.product_id, p.sku, p.name, p.category, p.min_stock_level,
                         COALESCE(SUM(i.quantity), 0) as total_stock,
                         COUNT(DISTINCT i.location_id) as locations_count,
                         MAX(i.received_at) as last_received
                  FROM {$this->productsTable} p
                  LEFT JOIN {$this->inventoryTable} i ON p.product_id = i.product_id AND i.quantity > 0
                  GROUP BY p.product_id
                  ORDER BY p.name ASC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching stock summary: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get products that are expiring soon (within 30 days)
     * @return array Array of products expiring soon
     */
    public function getExpiringProducts(): array {
        $query = "SELECT i.*, p.sku, p.name as product_name, l.location_code,
                         DATEDIFF(i.expiry_date, CURDATE()) as days_until_expiry
                  FROM {$this->inventoryTable} i
                  LEFT JOIN {$this->productsTable} p ON i.product_id = p.product_id
                  LEFT JOIN {$this->locationsTable} l ON i.location_id = l.id
                  WHERE i.expiry_date IS NOT NULL 
                    AND i.quantity > 0
                    AND DATEDIFF(i.expiry_date, CURDATE()) <= 30
                  ORDER BY i.expiry_date ASC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching expiring products: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get inventory movements history (for audit trail)
     * @param int $limit Number of records to return
     * @return array Array of recent inventory movements
     */
    public function getMovementHistory(int $limit = 100): array {
        // This would require a separate movements table in a full implementation
        // For now, we'll return recent inventory additions
        $query = "SELECT i.*, p.sku, p.name as product_name, l.location_code,
                         'RECEIVED' as movement_type
                  FROM {$this->inventoryTable} i
                  LEFT JOIN {$this->productsTable} p ON i.product_id = p.product_id
                  LEFT JOIN {$this->locationsTable} l ON i.location_id = l.id
                  ORDER BY i.created_at DESC
                  LIMIT :limit";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching movement history: " . $e->getMessage());
            return [];
        }
    }

    // === EXISTING DASHBOARD METHODS (Compatibility) ===

    /**
     * Get total item count (existing dashboard method)
     * @return int Total items in inventory
     */
    public function getTotalItemCount(): int {
        $query = "SELECT COALESCE(SUM(quantity), 0) as total_items FROM " . $this->inventoryTable;
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error counting total items in inventory: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get inventory value (new method for enhanced dashboard)
     * @return float Total inventory value
     */
    public function getTotalInventoryValue(): float {
        $query = "SELECT COALESCE(SUM(i.quantity * p.price), 0) as total_value 
                  FROM {$this->inventoryTable} i
                  LEFT JOIN {$this->productsTable} p ON i.product_id = p.product_id
                  WHERE i.quantity > 0";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (float) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error calculating total inventory value: " . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Get count of low stock products
     * @return int Number of products below minimum stock level
     */
    public function getLowStockCount(): int {
        $query = "SELECT COUNT(*) as low_stock_count
                  FROM (
                      SELECT p.product_id,
                             COALESCE(SUM(i.quantity), 0) as current_stock,
                             p.min_stock_level
                      FROM {$this->productsTable} p
                      LEFT JOIN {$this->inventoryTable} i ON p.product_id = i.product_id AND i.quantity > 0
                      GROUP BY p.product_id
                      HAVING current_stock <= p.min_stock_level
                  ) as low_stock_products";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error counting low stock products: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get count of products expiring within specified days
     * @param int $days Number of days to check (default 30)
     * @return int Number of products expiring
     */
    public function getExpiringCount(int $days = 30): int {
        $query = "SELECT COUNT(DISTINCT i.product_id) as expiring_count
                  FROM {$this->inventoryTable} i
                  WHERE i.expiry_date IS NOT NULL 
                    AND i.quantity > 0
                    AND DATEDIFF(i.expiry_date, CURDATE()) <= :days
                    AND DATEDIFF(i.expiry_date, CURDATE()) >= 0";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error counting expiring products: " . $e->getMessage());
            return 0;
        }
    }
}