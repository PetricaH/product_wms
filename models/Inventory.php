<?php
require_once __DIR__ . '/Location.php';
require_once __DIR__ . '/RelocationTask.php';
/**
 * Complete Enhanced Inventory Model with FIFO support
 * Updated to include missing methods called by inventory.php
 * Maintains compatibility with existing dashboard methods
 */

class Inventory {
    private $conn;
    private $inventoryTable = "inventory";
    private $productsTable = "products";
    private $locationsTable = "locations";
    private ?InventoryTransactionService $transactionService = null;
    private ?bool $inventoryMovementsTableExists = null;

    public function __construct($db) {
        $this->conn = $db;
    }

    // init transaction servie
    private function getTransactionService(): InventoryTransactionService {
        if ($this->transactionService === null) {
            if (file_exists(BASE_PATH . '/services/InventoryTransactionService.php')) {
                require_once BASE_PATH . '/services/InventoryTransactionService.php';
                $this->transactionService = new InventoryTransactionService($this->conn);
            }
        }
        return $this->transactionService;
    }

    private function hasTransactionLogging(): bool {
        try {
            return $this->getTransactionService() &&
                   $this->getTransactionService()->isTransactionSystemAvailable();
        } catch (Exception $e) {
            return false;
        }
    }

    private function hasInventoryMovements(): bool
    {
        if ($this->inventoryMovementsTableExists !== null) {
            return $this->inventoryMovementsTableExists;
        }

        try {
            $stmt = $this->conn->query("SHOW TABLES LIKE 'inventory_movements'");
            $this->inventoryMovementsTableExists = $stmt && $stmt->fetchColumn() ? true : false;
        } catch (PDOException $e) {
            $this->inventoryMovementsTableExists = false;
        }

        return $this->inventoryMovementsTableExists;
    }

    /**
     * Get inventory with filters - Method called by inventory.php
     * @param string $productFilter Product ID filter
     * @param string $locationFilter Location ID filter  
     * @param bool $lowStockOnly Show only low stock items
     * @return array Array of inventory records
     */
    public function getInventoryWithFilters($productFilter = '', $locationFilter = '', $lowStockOnly = false): array {
        $query = "SELECT i.*, 
                        p.sku, p.name as product_name, p.description as product_description, 
                        p.category, p.min_stock_level, p.price,
                        l.location_code, l.notes as location_description,
                        l.zone, l.type as location_type
                FROM {$this->inventoryTable} i
                LEFT JOIN {$this->productsTable} p ON i.product_id = p.product_id
                LEFT JOIN {$this->locationsTable} l ON i.location_id = l.id
                WHERE i.quantity > 0";

        $params = [];

        // Apply product filter
        if (!empty($productFilter)) {
            $query .= " AND i.product_id = :product_id";
            $params[':product_id'] = $productFilter;
        }

        // Apply location filter
        if (!empty($locationFilter)) {
            $query .= " AND i.location_id = :location_id";
            $params[':location_id'] = $locationFilter;
        }

        // Apply low stock filter
        if ($lowStockOnly) {
            $query .= " AND i.quantity <= COALESCE(p.min_stock_level, 5)";
        }

        $query .= " ORDER BY p.name ASC, l.location_code ASC, i.received_at ASC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching inventory with filters: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get low stock items - Method called by inventory.php
     * @return array Array of products below minimum stock level
     */
    public function getLowStockItems(): array {
        return $this->getLowStockProducts();
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
     * @param bool $useTransaction Whether to wrap the operation in a transaction
     * @return int|false Inventory record ID on success, false on failure
     */
    public function addStock(array $data, bool $useTransaction = true): int|false {
        // Check required fields
        $startTime = microtime(true);

        $requiredFields = ['product_id', 'location_id', 'quantity'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field]) && $data[$field] !== 0) {
                error_log("Add stock failed: Required field '{$field}' missing");
                return false;
            }
        }

        // Shelf rule enforcement and level resolution
        $subdivision = $data['subdivision_number'] ?? null;
        $shelfLevel  = $data['shelf_level'] ?? null;

        if ($shelfLevel === null && $subdivision !== null) {
            require_once __DIR__ . '/ShelfLevelResolver.php';
            $resolved = ShelfLevelResolver::getCorrectShelfLevel(
                $this->conn,
                (int)$data['location_id'],
                (int)$data['product_id'],
                (int)$subdivision
            );
            if ($resolved !== null) {
                $shelfLevel = $resolved;
            }
        }

        if ($shelfLevel === null) {
            $shelfLevel = $this->detectShelfLevel($data['location_id']);
        }

        if ($shelfLevel && !$this->validateShelfRule($data['product_id'], $shelfLevel)) {
            error_log('Add stock failed: shelf rule violation');
            return false;
        }

        
        $originalLocationId = (int)$data['location_id'];
        $locationModel = new Location($this->conn);
        if ($locationModel->isLocationFull($originalLocationId)) {
            $tempLoc = $locationModel->findAvailableTemporaryLocation();
            if ($tempLoc) {
                $data['location_id'] = $tempLoc;
                $relocation = new RelocationTask($this->conn);
                $relocation->createTask($data['product_id'], $tempLoc, $originalLocationId, (int)$data['quantity']);
            }
        }

        // Default received_at if not provided
        if (empty($data['received_at'])) {
            $data['received_at'] = date('Y-m-d H:i:s');
        }

        // Normalize nullable fields
        $batchNumber = $data['batch_number'] ?? null;
        $lotNumber   = $data['lot_number']   ?? null;
        $expiryDate  = $data['expiry_date']  ?? null;
        $subdivision = $data['subdivision_number'] ?? null;

        // Resolve numeric level using settings table when provided
        if (is_numeric($shelfLevel)) {
            require_once __DIR__ . '/LocationLevelSettings.php';
            $lls = new LocationLevelSettings($this->conn);
            $name = $lls->getLevelNameByNumber((int)$data['location_id'], (int)$shelfLevel);
            $shelfLevel = $name ?: 'Nivel ' . $shelfLevel;
        } elseif ($shelfLevel === null || $shelfLevel === '') {
            $shelfLevel = 'middle';
        }

        // Handle empty strings as null for date fields
        if (empty($expiryDate)) {
            $expiryDate = null;
        }

        try {
            if ($useTransaction) {
                $this->conn->beginTransaction();
            }

            $relocation = new RelocationTask($this->conn);

            $relocation = new RelocationTask($this->conn);

            // Insert inventory record
            $query = "INSERT INTO {$this->inventoryTable}
                    (product_id, location_id, shelf_level, subdivision_number, quantity, batch_number, lot_number, expiry_date, received_at)
                    VALUES (:product_id, :location_id, :shelf_level, :subdivision, :quantity, :batch_number, :lot_number, :expiry_date, :received_at)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':product_id', $data['product_id'], PDO::PARAM_INT);
            $stmt->bindParam(':location_id', $data['location_id'], PDO::PARAM_INT);
            $stmt->bindParam(':shelf_level', $shelfLevel, PDO::PARAM_STR);
            $stmt->bindParam(':subdivision', $subdivision, PDO::PARAM_INT);
            $stmt->bindParam(':quantity', $data['quantity'], PDO::PARAM_INT);
            $stmt->bindParam(':batch_number', $batchNumber, PDO::PARAM_STR);
            $stmt->bindParam(':lot_number', $lotNumber, PDO::PARAM_STR);
            $stmt->bindParam(':expiry_date', $expiryDate, PDO::PARAM_STR);
            $stmt->bindParam(':received_at', $data['received_at'], PDO::PARAM_STR);

            if (!$stmt->execute()) {
                if ($useTransaction && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                error_log("Add stock failed: Failed to insert inventory record");
                return false;
            }

            $inventoryId = $this->conn->lastInsertId();

            // Update product total quantity (if products table has quantity column)
            $this->updateProductTotalQuantity($data['product_id']);

            // Update location occupancy within the same transaction
            $this->updateLocationOccupancy($data['location_id']);

            if ($useTransaction) {
                $this->conn->commit();
            }

            $userId = $_SESSION['user_id'] ?? 0;
            logActivity(
                $userId,
                'add',
                'inventory',
                $inventoryId,
                'Stock added',
                null,
                $data
            );

            if ($this->hasTransactionLogging()) {
            try {
                $duration = round((microtime(true) - $startTime), 2);
                $this->getTransactionService()->logReceive(
                    $data['product_id'],
                    $data['location_id'],
                    $data['quantity'],
                    [
                        'batch_number' => $data['batch_number'] ?? null,
                        'lot_number' => $data['lot_number'] ?? null,
                        'expiry_date' => $data['expiry_date'] ?? null,
                        'shelf_level' => $shelfLevel ?? null,
                        'subdivision_number' => $data['subdivision_number'] ?? null,
                        'duration_seconds' => $duration,
                        'reference_type' => $data['reference_type'] ?? 'manual',
                        'reference_id' => $data['reference_id'] ?? null,
                        'reason' => $data['reason'] ?? 'Stock added to inventory',
                        'notes' => $data['notes'] ?? null
                    ]
                );
            } catch (Exception $e) {
                error_log("Transaction logging failed: " . $e->getMessage());
                // Don't fail the operation if logging fails
            }
        }

            return (int) $inventoryId;

        } catch (PDOException $e) {
            if ($useTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Add stock failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Assign a product to a location without adjusting stock quantity
     * @param array $data Assignment data
     * @return bool Success status
     */
    public function assignProductLocation(array $data): bool {
        $required = ['product_id', 'location_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                error_log("Assign product location failed: Missing {$field}");
                return false;
            }
        }

        $shelfLevel = $data['shelf_level'] ?? null;
        $subdivision = $data['subdivision_number'] ?? null;

        // Resolve numeric level name if needed
        if ($shelfLevel !== null && $shelfLevel !== '') {
            if (is_numeric($shelfLevel)) {
                require_once __DIR__ . '/LocationLevelSettings.php';
                $lls = new LocationLevelSettings($this->conn);
                $name = $lls->getLevelNameByNumber((int)$data['location_id'], (int)$shelfLevel);
                $shelfLevel = $name ?: 'Nivel ' . $shelfLevel;
            }
        } else {
            $shelfLevel = null;
        }

        try {
            $query = "INSERT INTO {$this->inventoryTable}
                    (product_id, location_id, shelf_level, subdivision_number, quantity, received_at)
                    VALUES (:product_id, :location_id, :shelf_level, :subdivision, 0, NOW())
                    ON DUPLICATE KEY UPDATE
                        shelf_level = VALUES(shelf_level),
                        subdivision_number = VALUES(subdivision_number)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':product_id', $data['product_id'], PDO::PARAM_INT);
            $stmt->bindParam(':location_id', $data['location_id'], PDO::PARAM_INT);
            $stmt->bindParam(':shelf_level', $shelfLevel, PDO::PARAM_STR);
            $stmt->bindParam(':subdivision', $subdivision, PDO::PARAM_INT);

            if (!$stmt->execute()) {
                error_log('Assign product location failed: DB execute error');
                return false;
            }

            // Update location occupancy though quantity is zero to ensure consistency
            $this->updateLocationOccupancy($data['location_id']);

            return true;
        } catch (PDOException $e) {
            error_log('Assign product location failed: ' . $e->getMessage());
            return false;
        }
    }

    private function detectShelfLevel(int $locationId): ?string {
        $stmt = $this->conn->prepare("SELECT location_code FROM locations WHERE id = ?");
        $stmt->execute([$locationId]);
        $code = $stmt->fetchColumn();
        if (!$code) return null;
        if (preg_match('/-(T|M|B)$/i', $code, $m)) {
            return strtolower($m[1]) === 't' ? 'top' : (strtolower($m[1]) === 'm' ? 'middle' : 'bottom');
        }
        return null;
    }

    private function getProductVolume(int $productId): ?float {
        $stmt = $this->conn->prepare("SELECT volume_per_unit FROM product_units WHERE product_id = ? LIMIT 1");
        $stmt->execute([$productId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (float)$val : null;
    }

    private function getProductCategory(int $productId): ?string {
        $stmt = $this->conn->prepare("SELECT category FROM products WHERE product_id = ?");
        $stmt->execute([$productId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : null;
    }

    private function validateShelfRule(int $productId, string $level): bool {
        $category = strtolower($this->getProductCategory($productId) ?? '');
        $volume = $this->getProductVolume($productId);
        return match($level) {
            'top' => str_contains($category, 'gift'),
            'middle' => in_array((int)$volume, [5,10], true),
            'bottom' => (int)$volume === 25,
            default => true,
        };
    }
    
    /**
     * Remove stock from inventory using FIFO method
     * @param int $productId Product ID
     * @param int $quantity Quantity to remove
     * @param int|null $locationId Optional specific location
     * @param bool $useTransaction Whether to wrap the operation in a transaction
     * @return bool Success status
    */
    public function removeStock(int $productId, int $quantity, ?int $locationId = null, bool $useTransaction = true): bool {
        $startTime = microtime(true);
        
        if ($quantity <= 0) {
            error_log("Remove stock failed: Invalid quantity");
            return false;
        }

        $quantityBefore = 0;
        if ($locationId && $this->hasTransactionLogging()) {
            $stmt = $this->conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE product_id = ? AND location_id = ?");
            $stmt->execute([$productId, $locationId]);
            $quantityBefore = (int)$stmt->fetchColumn();
        }

        try {
            if ($useTransaction) {
                $this->conn->beginTransaction();
            }
            $relocation = new RelocationTask($this->conn);

            // Get available stock using FIFO (oldest first)
            $query = "SELECT i.id, i.quantity, i.location_id
                      FROM {$this->inventoryTable} i
                      WHERE i.product_id = :product_id AND i.quantity > 0";
            
            $params = [':product_id' => $productId];
            
            if ($locationId !== null) {
                $query .= " AND i.location_id = :location_id";
                $params[':location_id'] = $locationId;
            }
            
            $query .= " ORDER BY i.received_at ASC, i.id ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $inventoryRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($inventoryRecords)) {
                if ($useTransaction && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                error_log("Remove stock failed: No stock available");
                return false;
            }

            // Check if enough stock is available
            $totalAvailable = array_sum(array_column($inventoryRecords, 'quantity'));
            if ($totalAvailable < $quantity) {
                if ($useTransaction && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                error_log("Remove stock failed: Insufficient stock (available: {$totalAvailable}, requested: {$quantity})");
                return false;
            }

            // Remove stock using FIFO
            $remainingToRemove = $quantity;
            $affectedLocations = [];
            foreach ($inventoryRecords as $record) {
                if ($remainingToRemove <= 0) {
                    break;
                }

                $inventoryId = $record['id'];
                $availableQty = $record['quantity'];

                if ($availableQty <= $remainingToRemove) {
                    // Remove entire record
                    $removeQuery = "DELETE FROM {$this->inventoryTable} WHERE id = :id";
                    $removeStmt = $this->conn->prepare($removeQuery);
                    $removeStmt->bindParam(':id', $inventoryId, PDO::PARAM_INT);
                    $removeStmt->execute();

                    $remainingToRemove -= $availableQty;
                    $affectedLocations[] = (int)$record['location_id'];
                } else {
                    // Partial removal - update quantity
                    $newQty = $availableQty - $remainingToRemove;
                    $updateQuery = "UPDATE {$this->inventoryTable} SET quantity = :quantity WHERE id = :id";
                    $updateStmt = $this->conn->prepare($updateQuery);
                    $updateStmt->bindParam(':quantity', $newQty, PDO::PARAM_INT);
                    $updateStmt->bindParam(':id', $inventoryId, PDO::PARAM_INT);
                    $updateStmt->execute();

                    $remainingToRemove = 0;
                    $affectedLocations[] = (int)$record['location_id'];
                }
            }

            // Update product total quantity
            $this->updateProductTotalQuantity($productId);

            // Update occupancy for affected locations within the transaction
            foreach (array_unique($affectedLocations) as $locId) {
                $this->updateLocationOccupancy($locId);
                $relocation->activatePendingTasks($locId);
            }

            if ($useTransaction) {
                $this->conn->commit();
            }

            $userId = $_SESSION['user_id'] ?? 0;
            logActivity(
                $userId,
                'remove',
                'inventory',
                $productId,
                'Stock removed',
                ['quantity' => $totalAvailable],
                ['quantity' => $totalAvailable - $quantity]
            );

            if ($this->hasTransactionLogging()) {
                try {
                    $duration = round((microtime(true) - $startTime), 2);
                    $this->getTransactionService()->logTransaction([
                        'transaction_type' => 'pick',
                        'product_id' => $productId,
                        'location_id' => $locationId,
                        'quantity_change' => -$quantity,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => max(0, $quantityBefore - $quantity),
                        'duration_seconds' => $duration,
                        'reference_type' => 'system_auto',
                        'reason' => 'Stock removed from inventory.'
                    ]);
                } catch (Exception $e) {
                    error_log("Transaction logging failed: " . $e->getMessage());
                }
            }

            return true;

        } catch (PDOException $e) {
            if ($useTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Remove stock failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update product total quantity in products table
     * @param int $productId Product ID
     * @return void
     */
    private function updateProductTotalQuantity(int $productId): void {
        try {
            // Check if products table has quantity column
            $checkQuery = "SHOW COLUMNS FROM {$this->productsTable} LIKE 'quantity'";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                // Update total quantity in products table
                $updateQuery = "UPDATE {$this->productsTable} p 
                                SET quantity = (
                                    SELECT COALESCE(SUM(i.quantity), 0) 
                                    FROM {$this->inventoryTable} i 
                                    WHERE i.product_id = p.product_id
                                ) 
                                WHERE p.product_id = :product_id";
                
                $stmt = $this->conn->prepare($updateQuery);
                $stmt->bindParam(':product_id', $productId, PDO::PARAM_INT);
                $stmt->execute();
                // After updating quantity, check auto order rules
                $this->triggerAutoOrder($productId);
            }
        } catch (PDOException $e) {
            error_log("Warning: Could not update product total quantity: " . $e->getMessage());
        }
    }

    /**
     * Update the current occupancy for a location
     * @param int $locationId Location ID
     * @return void
     */
    private function updateLocationOccupancy(int $locationId): void {
        try {
            $query = "UPDATE {$this->locationsTable} l
                      SET current_occupancy = (
                          SELECT COALESCE(SUM(quantity), 0)
                          FROM {$this->inventoryTable}
                          WHERE location_id = :loc
                      )
                      WHERE l.id = :loc";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':loc', $locationId, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("Warning: Could not update location occupancy: " . $e->getMessage());
        }
    }

    /**
     * Get stock summary by SKU
     * @param string $sku Product SKU
     * @return array Stock summary data
     */
    public function getStockSummaryBySku(string $sku): array {
        $query = "SELECT 
                    COALESCE(SUM(i.quantity), 0) as inventory_quantity,
                    COALESCE(p.quantity, 0) as product_quantity,
                    GREATEST(
                        COALESCE(SUM(i.quantity), 0), 
                        COALESCE(p.quantity, 0)
                    ) as total_quantity,
                    COUNT(DISTINCT CASE WHEN i.quantity > 0 THEN i.location_id END) as locations_count,
                    GROUP_CONCAT(DISTINCT l.location_code) as locations,
                    p.product_id,
                    p.name as product_name
                FROM {$this->productsTable} p
                LEFT JOIN {$this->inventoryTable} i ON p.product_id = i.product_id AND i.quantity > 0
                LEFT JOIN {$this->locationsTable} l ON i.location_id = l.id
                WHERE p.sku = :sku
                GROUP BY p.product_id, p.quantity, p.name";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':sku', $sku, PDO::PARAM_STR);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return [
                    'total_quantity' => floatval($result['total_quantity']),
                    'inventory_quantity' => floatval($result['inventory_quantity']),
                    'product_quantity' => floatval($result['product_quantity']),
                    'locations_count' => intval($result['locations_count']),
                    'locations' => $result['locations'] ? explode(',', $result['locations']) : [],
                    'product_id' => intval($result['product_id']),
                    'product_name' => $result['product_name']
                ];
            } else {
                return [
                    'total_quantity' => 0,
                    'inventory_quantity' => 0,
                    'product_quantity' => 0,
                    'locations_count' => 0,
                    'locations' => []
                ];
            }
            
        } catch (PDOException $e) {
            error_log("Error getting stock summary by SKU: " . $e->getMessage());
            return [
                'total_quantity' => 0,
                'inventory_quantity' => 0,
                'product_quantity' => 0,
                'locations_count' => 0,
                'locations' => []
            ];
        }
    }

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
     * Get products with low stock levels
     * @return array Array of products below minimum stock level
     */
    public function getLowStockProducts(): array {
        $query = "SELECT p.product_id, p.sku, p.name, p.min_stock_level,
                         COALESCE(SUM(i.quantity), p.quantity, 0) as current_stock,
                         COALESCE(COUNT(DISTINCT CASE WHEN i.quantity > 0 THEN i.location_id END), 
                                  CASE WHEN p.quantity > 0 THEN 1 ELSE 0 END) as locations_count,
                         p.quantity as product_base_quantity,
                         CASE 
                            WHEN COUNT(i.id) > 0 THEN 'inventory_tracked'
                            WHEN p.quantity > 0 THEN 'product_quantity'
                            ELSE 'no_stock'
                         END as stock_source
                  FROM {$this->productsTable} p
                  LEFT JOIN {$this->inventoryTable} i ON p.product_id = i.product_id
                  GROUP BY p.product_id
                  HAVING current_stock <= COALESCE(p.min_stock_level, 5) OR current_stock = 0
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
                         COALESCE(SUM(i.quantity), p.quantity, 0) as total_stock,
                         COALESCE(COUNT(DISTINCT CASE WHEN i.quantity > 0 THEN i.location_id END), 
                                  CASE WHEN p.quantity > 0 THEN 1 ELSE 0 END) as locations_count,
                         MAX(i.received_at) as last_received,
                         p.quantity as product_base_quantity,
                         CASE 
                            WHEN COUNT(i.id) > 0 THEN 'inventory_tracked'
                            WHEN p.quantity > 0 THEN 'product_quantity'
                            ELSE 'no_stock'
                         END as stock_source
                  FROM {$this->productsTable} p
                  LEFT JOIN {$this->inventoryTable} i ON p.product_id = i.product_id
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
        $query = "SELECT i.*, p.sku, p.name as product_name, l.name as location_code,
                         DATEDIFF(i.expiry_date, CURDATE()) as days_until_expiry
                  FROM {$this->inventoryTable} i
                  LEFT JOIN {$this->productsTable} p ON i.product_id = p.product_id
                  LEFT JOIN {$this->locationsTable} l ON i.location_id = l.id
                  WHERE i.expiry_date IS NOT NULL 
                        AND i.expiry_date >= CURDATE()
                        AND i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                        AND i.quantity > 0
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
     * Get expired products
     * @return array Array of expired products
     */
    public function getExpiredProducts(): array {
        $query = "SELECT i.*, p.sku, p.name as product_name, l.name as location_code,
                         DATEDIFF(CURDATE(), i.expiry_date) as days_expired
                  FROM {$this->inventoryTable} i
                  LEFT JOIN {$this->productsTable} p ON i.product_id = p.product_id
                  LEFT JOIN {$this->locationsTable} l ON i.location_id = l.id
                  WHERE i.expiry_date IS NOT NULL 
                        AND i.expiry_date < CURDATE()
                        AND i.quantity > 0
                  ORDER BY i.expiry_date ASC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching expired products: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Move stock from one location to another
     * @param int $productId Product ID
     * @param int $fromLocationId Source location ID
     * @param int $toLocationId Destination location ID
     * @param int $quantity Quantity to move
     * @return bool Success status
     */
    public function moveStock(int $productId, int $fromLocationId, int $toLocationId, int $quantity, ?int $inventoryId = null): bool {

        $startTime = microtime(true);

        if ($quantity <= 0) {
            error_log("Move stock failed: Invalid quantity");
            return false;
        }

        try {
            $this->conn->beginTransaction();

            if ($inventoryId !== null) {
                // Fetch the specific inventory record
                $checkQuery = "SELECT quantity FROM {$this->inventoryTable} WHERE id = :id AND product_id = :pid AND location_id = :lid";
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->bindValue(':id', $inventoryId, PDO::PARAM_INT);
                $checkStmt->bindValue(':pid', $productId, PDO::PARAM_INT);
                $checkStmt->bindValue(':lid', $fromLocationId, PDO::PARAM_INT);
                $checkStmt->execute();
                $record = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$record || (int)$record['quantity'] < $quantity) {
                    $this->conn->rollBack();
                    error_log("Move stock failed: Record not found or insufficient quantity");
                    return false;
                }

                // Update or delete the record based on remaining quantity
                $remaining = (int)$record['quantity'] - $quantity;
                if ($remaining > 0) {
                    $updateQuery = "UPDATE {$this->inventoryTable} SET quantity = :q WHERE id = :id";
                    $updateStmt = $this->conn->prepare($updateQuery);
                    $updateStmt->bindValue(':q', $remaining, PDO::PARAM_INT);
                    $updateStmt->bindValue(':id', $inventoryId, PDO::PARAM_INT);
                    $updateStmt->execute();
                    error_log('moveStock update affected rows: ' . $updateStmt->rowCount());
                } else {
                    $delQuery = "DELETE FROM {$this->inventoryTable} WHERE id = :id";
                    $delStmt = $this->conn->prepare($delQuery);
                    $delStmt->bindValue(':id', $inventoryId, PDO::PARAM_INT);
                    $delStmt->execute();
                    error_log('moveStock delete affected rows: ' . $delStmt->rowCount());                }
                $this->updateProductTotalQuantity($productId);
            } else {
                // Remove stock via FIFO if no specific record provided
                if (!$this->removeStock($productId, $quantity, $fromLocationId, false)) {
                    $this->conn->rollBack();
                    return false;
                }
            }

            // Add stock to destination location
            $addData = [
                'product_id' => $productId,
                'location_id' => $toLocationId,
                'quantity' => $quantity,
                'received_at' => date('Y-m-d H:i:s')
            ];

            if (!$this->addStock($addData, false)) {
                $this->conn->rollBack();
                return false;
            }

            $this->conn->commit();

            if ($this->hasTransactionLogging()) {
                try {
                    $duration = round((microtime(true) - $startTime), 2);
                    $sessionId = uniqid('move_');
                    
                    $this->getTransactionService()->logMove(
                        $productId,
                        $fromLocationId,
                        $toLocationId,
                        $quantity,
                        [
                            'duration_seconds' => $duration,
                            'reference_type' => 'system_auto',
                            'reason' => 'Stock movement between locations',
                            'session_id' => $sessionId,
                            'inventory_record_id' => $inventoryId
                        ]
                    );
                } catch (Exception $e) {
                    error_log("Transaction logging failed: " . $e->getMessage());
                    // Don't fail the operation if logging fails
                }
            }
            return true;

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Move stock failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get total unique products count in inventory
     * @return int Count of unique products
     */
    public function getUniqueProductsCount(): int {
        $query = "SELECT COUNT(DISTINCT product_id) as unique_products 
                  FROM {$this->inventoryTable} 
                  WHERE quantity > 0";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error counting unique products: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get total locations with stock
     * @return int Count of locations with stock
     */
    public function getLocationsWithStockCount(): int {
        $query = "SELECT COUNT(DISTINCT location_id) as locations_with_stock 
                  FROM {$this->inventoryTable} 
                  WHERE quantity > 0";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error counting locations with stock: " . $e->getMessage());
            return 0;
        }
    }

    /**
     *Get enhanced location activity (for the API)
     */
    public function getLocationActivity(int $locationId): array {
        if ($this->hasTransactionLogging()) {
            return $this->getTransactionService()->getLocationActivity($locationId);
        }
        
        // Fallback to inventory table
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    MAX(updated_at) as last_activity,
                    COUNT(*) as record_count,
                    SUM(quantity) as total_items
                FROM inventory 
                WHERE location_id = :id
            ");
            $stmt->execute([':id' => $locationId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Count recent changes
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) 
                FROM inventory 
                WHERE location_id = :id 
                AND (updated_at >= (NOW() - INTERVAL 1 DAY) OR received_at >= (NOW() - INTERVAL 1 DAY))
            ");
            $stmt->execute([':id' => $locationId]);
            $recentChanges = (int)$stmt->fetchColumn();
            
            return [
                'last_movement' => $result['last_activity'] ?? null,
                'last_movement_type' => 'inventory_update',
                'recent_changes' => $recentChanges,
                'activity_score' => min(100, $recentChanges * 15),
                'transaction_breakdown' => []
            ];
            
        } catch (Exception $e) {
            error_log("Error fetching location activity fallback: " . $e->getMessage());
            return [
                'last_movement' => null,
                'recent_changes' => 0,
                'activity_score' => 0,
                'transaction_breakdown' => []
            ];
        }
    }

    /**
     * Get warehouse activity dashboard data
     */
    public function getWarehouseActivitySummary(): array {
        if ($this->hasTransactionLogging()) {
            return $this->getTransactionService()->getWarehouseActivitySummary();
        }
        
        // Fallback data
        return [
            'today_activity' => [],
            'busiest_locations' => [],
            'performance_metrics' => []
        ];
    }

    /**
 * Get count of products with low stock
 * @return int Number of products below minimum stock level
 */
public function getLowStockCount(): int {
    try {
        $query = "SELECT COUNT(DISTINCT p.product_id) 
                  FROM products p 
                  LEFT JOIN (
                      SELECT product_id, SUM(quantity) as total_qty 
                      FROM inventory 
                      GROUP BY product_id
                  ) i ON p.product_id = i.product_id
                  WHERE COALESCE(i.total_qty, 0) <= p.min_stock_level";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
        
    } catch (PDOException $e) {
        error_log("Error getting low stock count: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get critical stock alerts
 * @param int $limit Maximum number of alerts to return
 * @return array Products with critical stock levels
 */
public function getCriticalStockAlerts(int $limit = 10): array {
    try {
        $query = "SELECT p.product_id, p.sku, p.name, p.min_stock_level,
                         COALESCE(i.total_qty, 0) as quantity
                  FROM products p
                  LEFT JOIN (
                      SELECT product_id, SUM(quantity) as total_qty
                      FROM inventory
                      GROUP BY product_id
                  ) i ON p.product_id = i.product_id
                  WHERE COALESCE(i.total_qty, 0) <= p.min_stock_level
                    AND LOWER(p.category) = 'marfa'
                  ORDER BY (COALESCE(i.total_qty, 0) / GREATEST(p.min_stock_level, 1)) ASC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error getting critical stock alerts: " . $e->getMessage());
        return [];
    }
}

/**
 * Get number of items moved today (transactions)
 * @return int Number of inventory movements today
 */
    public function getItemsMovedToday(): int {
        try {
            $query = "SELECT COALESCE(SUM(ABS(quantity_change)), 0) as items_moved
                  FROM inventory_transactions
                  WHERE DATE(created_at) = CURDATE()";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetchColumn();
        return (int)($result ?? 0);
        
    } catch (PDOException $e) {
        // If inventory_transactions table doesn't exist, return 0
        error_log("Error getting items moved today: " . $e->getMessage());
        return 0;
    }
}

    /**
     * Fetch inventory transactions with filters and pagination
     */
    public function getStockMovements(array $filters = [], int $page = 1, int $pageSize = 25,
                                      string $sort = 'created_at', string $direction = 'DESC'): array {
        $offset = ($page - 1) * $pageSize;

        $sortColumnMap = [
            'created_at' => 'records.created_at',
            'transaction_type' => 'records.transaction_type',
            'quantity_change' => 'records.quantity_change',
            'product_name' => 'p.name',
        ];

        if (!array_key_exists($sort, $sortColumnMap)) {
            $sort = 'created_at';
        }
        $sortColumn = $sortColumnMap[$sort];
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        $includeRelocations = $this->hasInventoryMovements();

        $unionParts = [];
        $unionParts[] = "SELECT
                CAST(t.id AS CHAR) AS record_id,
                'transaction' AS record_type,
                t.transaction_type,
                t.product_id,
                t.location_id,
                t.source_location_id,
                t.quantity_change,
                t.quantity_before,
                t.quantity_after,
                t.reason,
                t.user_id,
                t.duration_seconds,
                t.reference_type,
                t.reference_id,
                t.created_at,
                NULL AS relocation_target_location_id,
                NULL AS movement_direction
            FROM inventory_transactions t";

        if ($includeRelocations) {
            $unionParts[] = "SELECT
                    CONCAT('reloc-in-', dest.id) AS record_id,
                    'relocation' AS record_type,
                    'relocation' AS transaction_type,
                    dest.product_id,
                    dest.location_id,
                    source.location_id AS source_location_id,
                    dest.quantity_change,
                    NULL AS quantity_before,
                    NULL AS quantity_after,
                    dest.notes AS reason,
                    dest.user_id,
                    NULL AS duration_seconds,
                    dest.reference_type,
                    dest.reference_id,
                    dest.created_at,
                    dest.location_id AS relocation_target_location_id,
                    'in' AS movement_direction
                FROM inventory_movements dest
                LEFT JOIN inventory_movements source
                    ON source.reference_type = dest.reference_type
                    AND source.reference_id = dest.reference_id
                    AND source.movement_type = dest.movement_type
                    AND source.quantity_change < 0
                WHERE dest.movement_type = 'relocation' AND dest.quantity_change > 0";

            $unionParts[] = "SELECT
                    CONCAT('reloc-out-', source.id) AS record_id,
                    'relocation' AS record_type,
                    'relocation' AS transaction_type,
                    source.product_id,
                    source.location_id,
                    source.location_id AS source_location_id,
                    source.quantity_change,
                    NULL AS quantity_before,
                    NULL AS quantity_after,
                    source.notes AS reason,
                    source.user_id,
                    NULL AS duration_seconds,
                    source.reference_type,
                    source.reference_id,
                    source.created_at,
                    dest.location_id AS relocation_target_location_id,
                    'out' AS movement_direction
                FROM inventory_movements source
                LEFT JOIN inventory_movements dest
                    ON dest.reference_type = source.reference_type
                    AND dest.reference_id = source.reference_id
                    AND dest.movement_type = source.movement_type
                    AND dest.quantity_change > 0
                WHERE source.movement_type = 'relocation' AND source.quantity_change < 0";
        }

        $recordsSql = implode("\nUNION ALL\n", $unionParts);

        $baseQuery = "FROM (
                $recordsSql
            ) records
            LEFT JOIN products p ON records.product_id = p.product_id
            LEFT JOIN locations loc ON records.location_id = loc.id
            LEFT JOIN locations source_loc ON records.source_location_id = source_loc.id
            LEFT JOIN locations target_loc ON records.relocation_target_location_id = target_loc.id
            LEFT JOIN users u ON records.user_id = u.id";

        $where = [];
        $params = [];

        $dateFrom = $filters['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo   = $filters['date_to'] ?? date('Y-m-d');
        $where[] = 'records.created_at BETWEEN :date_from AND :date_to';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
        $params[':date_to']   = $dateTo . ' 23:59:59';

        if (!empty($filters['transaction_type']) && $filters['transaction_type'] !== 'all') {
            $where[] = 'records.transaction_type = :type';
            $params[':type'] = $filters['transaction_type'];
        }

        if (!empty($filters['product_search'])) {
            $where[] = '(p.name LIKE :product OR p.sku LIKE :product)';
            $params[':product'] = '%' . $filters['product_search'] . '%';
        }

        if (!empty($filters['location_id'])) {
            $where[] = '(
                records.location_id = :loc
                OR records.source_location_id = :loc
                OR records.relocation_target_location_id = :loc
            )';
            $params[':loc'] = $filters['location_id'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = 'records.user_id = :user_id';
            $params[':user_id'] = $filters['user_id'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->conn->prepare("SELECT COUNT(*) $baseQuery $whereSql");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT
                records.record_id,
                records.record_type,
                records.transaction_type,
                records.product_id,
                records.location_id,
                records.source_location_id,
                records.quantity_change,
                records.quantity_before,
                records.quantity_after,
                records.reason,
                records.user_id,
                records.duration_seconds,
                records.reference_type,
                records.reference_id,
                records.created_at,
                records.relocation_target_location_id,
                records.movement_direction,
                p.name AS product_name,
                p.sku,
                loc.location_code,
                source_loc.location_code AS source_location_code,
                target_loc.location_code AS target_location_code,
                u.username AS full_name,
                u.username AS username
            $baseQuery
            $whereSql
            ORDER BY $sortColumn $direction
            LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total
        ];
    }

    /**
     * Get summary metrics for today's movements
     */
    public function getTodayMovementSummary(): array {
        try {
            $stmt = $this->conn->prepare("SELECT
                        COUNT(*) AS movements,
                        COUNT(DISTINCT product_id) AS products,
                        COUNT(DISTINCT COALESCE(location_id, source_location_id)) AS locations,
                        AVG(duration_seconds) AS avg_duration
                    FROM inventory_transactions
                    WHERE DATE(created_at) = CURDATE()");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return [
                'movements' => (int)($row['movements'] ?? 0),
                'products' => (int)($row['products'] ?? 0),
                'locations' => (int)($row['locations'] ?? 0),
                'avg_duration' => $row['avg_duration'] ? (float)$row['avg_duration'] : 0.0
            ];
        } catch (PDOException $e) {
            error_log('Error fetching movement summary: ' . $e->getMessage());
            return [
                'movements' => 0,
                'products' => 0,
                'locations' => 0,
                'avg_duration' => 0.0
            ];
        }
    }

    /**
     * Trigger automatic purchase order if stock below minimum
     */
    private function triggerAutoOrder(int $productId): void {
        try {
            $query = "SELECT p.product_id, p.quantity, p.min_stock_level,
                             p.min_order_quantity, p.auto_order_enabled,
                             p.seller_id,
                             pp.id AS purchasable_product_id
                      FROM {$this->productsTable} p
                      LEFT JOIN purchasable_products pp
                        ON pp.internal_product_id = p.product_id
                        AND pp.preferred_seller_id = p.seller_id
                      WHERE p.product_id = :id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product || !$product['auto_order_enabled'] || empty($product['seller_id'])) {
                return;
            }

            if ((int)$product['quantity'] > (int)$product['min_stock_level']) {
                return;
            }

            require_once __DIR__ . '/PurchaseOrder.php';
            $po = new PurchaseOrder($this->conn);

            $orderData = [
                'seller_id' => (int)$product['seller_id'],
                'status' => 'draft',
                'notes' => 'Auto-generated order for low stock',
                'items' => [[
                    'purchasable_product_id' => (int)($product['purchasable_product_id'] ?? 0),
                    'quantity' => max(1, (int)($product['min_order_quantity'] ?? 1)),
                    'unit_price' => 0
                ]]
            ];
            $po->createPurchaseOrder($orderData);

            $upd = $this->conn->prepare("UPDATE {$this->productsTable} SET last_auto_order_date = NOW() WHERE product_id = :id");
            $upd->execute([':id' => $productId]);

        } catch (Exception $e) {
            error_log('Auto order error: ' . $e->getMessage());
        }
    }
}
