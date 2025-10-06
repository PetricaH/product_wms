<?php
require_once __DIR__ . '/Location.php';
require_once __DIR__ . '/RelocationTask.php';
require_once __DIR__ . '/Setting.php';
require_once __DIR__ . '/AutoOrderDuplicateGuard.php';
/**
 * Complete Enhanced Inventory Model with FIFO support
 * Updated to include missing methods called by inventory.php
 * Maintains compatibility with existing dashboard methods
 */

class Inventory {
    private const AUTO_ORDER_SETTINGS_KEY = 'auto_order_configuratie_globala';
    private $conn;
    private $inventoryTable = "inventory";
    private $productsTable = "products";
    private $locationsTable = "locations";
    private ?InventoryTransactionService $transactionService = null;
    private ?bool $inventoryTransactionsTableExists = null;
    private array $deferredAutoOrderProductIds = [];
    private ?AutoOrderDuplicateGuard $duplicateGuard = null;
    private ?array $autoOrderSettingsCache = null;

    public function __construct($db) {
        $this->conn = $db;
    }

    // init transaction servie
    private function getTransactionService(): ?InventoryTransactionService {
        if ($this->transactionService === null) {
            if (file_exists(BASE_PATH . '/services/InventoryTransactionService.php')) {
                require_once BASE_PATH . '/services/InventoryTransactionService.php';
                $this->transactionService = new InventoryTransactionService($this->conn);
            }
        }
        return $this->transactionService;
    }

    private function getDuplicateGuard(): AutoOrderDuplicateGuard
    {
        if ($this->duplicateGuard === null) {
            $this->duplicateGuard = new AutoOrderDuplicateGuard($this->conn, $this->productsTable);
        }

        return $this->duplicateGuard;
    }

    private function getAutoOrderSettings(): array
    {
        if ($this->autoOrderSettingsCache !== null) {
            return $this->autoOrderSettingsCache;
        }

        $settingModel = new Setting($this->conn);
        $setari = $settingModel->get(self::AUTO_ORDER_SETTINGS_KEY);
        if (is_string($setari)) {
            $setari = json_decode($setari, true) ?: [];
        } elseif (!is_array($setari)) {
            $setari = [];
        }

        $implicite = [
            'interval_minim_ore' => 24,
        ];

        $this->autoOrderSettingsCache = array_merge($implicite, $setari);

        return $this->autoOrderSettingsCache;
    }

    private function getAutoOrderMinIntervalMinutes(): int
    {
        $setari = $this->getAutoOrderSettings();
        $intervalOre = max(1, (int)($setari['interval_minim_ore'] ?? 24));
        $minuteSetari = $intervalOre * 60;
        $configurat = $this->getDuplicateGuard()->getConfiguredMinIntervalMinutes();

        return max($configurat, $minuteSetari);
    }

    private function hasTransactionLogging(): bool {
        try {
            return $this->getTransactionService() &&
                   $this->getTransactionService()->isTransactionSystemAvailable();
        } catch (Exception $e) {
            return false;
        }
    }

    private function hasInventoryTransactions(): bool
    {
        if ($this->inventoryTransactionsTableExists !== null) {
            return $this->inventoryTransactionsTableExists;
        }

        try {
            $stmt = $this->conn->query("SHOW TABLES LIKE 'inventory_transactions'");
            $this->inventoryTransactionsTableExists = $stmt && $stmt->fetchColumn() ? true : false;
        } catch (PDOException $e) {
            $this->inventoryTransactionsTableExists = false;
        }

        return $this->inventoryTransactionsTableExists;
    }

    /**
     * Get inventory with filters - Method called by inventory.php
     * @param string $productFilter Product ID filter
     * @param string $locationFilter Location ID filter  
     * @param bool $lowStockOnly Show only low stock items
     * @param array $options Additional filters (received_from, received_to)
     * @return array Array of inventory records
     */
    public function getInventoryWithFilters($productFilter = '', $locationFilter = '', $lowStockOnly = false, array $options = []): array {
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

        $receivedFrom = $options['received_from'] ?? '';
        $receivedTo = $options['received_to'] ?? '';

        if (!empty($receivedFrom)) {
            $query .= " AND i.received_at >= :received_from";
            $params[':received_from'] = $receivedFrom . ' 00:00:00';
        }

        if (!empty($receivedTo)) {
            $query .= " AND i.received_at <= :received_to";
            $params[':received_to'] = $receivedTo . ' 23:59:59';
        }

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

        if (!empty($receivedFrom) || !empty($receivedTo)) {
            $query .= " ORDER BY i.received_at DESC, p.name ASC, l.location_code ASC";
        } else {
            $query .= " ORDER BY p.name ASC, l.location_code ASC, i.received_at ASC";
        }

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

            $this->processDeferredAutoOrders();

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
                        'transaction_type' => $data['transaction_type'] ?? 'receive',
                        'batch_number' => $data['batch_number'] ?? null,
                        'lot_number' => $data['lot_number'] ?? null,
                        'expiry_date' => $data['expiry_date'] ?? null,
                        'shelf_level' => $shelfLevel ?? null,
                        'subdivision_number' => $data['subdivision_number'] ?? null,
                        'duration_seconds' => $duration,
                        'reference_type' => $data['reference_type'] ?? 'manual',
                        'reference_id' => $data['reference_id'] ?? null,
                        'reason' => $data['reason'] ?? 'Stock added to inventory',
                        'notes' => $data['notes'] ?? null,
                        'user_id' => $data['user_id'] ?? ($_SESSION['user_id'] ?? 0)
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
            if (!empty($data['product_id'])) {
                $this->discardDeferredAutoOrder((int)$data['product_id']);
            }
            error_log("Add stock failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Increase quantity on an existing inventory record while keeping audit logs
     *
     * @param int   $inventoryId    Inventory record ID
     * @param int   $quantity       Quantity to add
     * @param array $options        Additional metadata (received_at, reason, notes, etc.)
     * @param bool  $useTransaction Wrap the operation in a database transaction
     */
    public function increaseInventoryQuantity(int $inventoryId, int $quantity, array $options = [], bool $useTransaction = true): bool
    {
        $startTime = microtime(true);

        if ($quantity <= 0) {
            error_log('Increase inventory failed: quantity must be positive');
            return false;
        }

        try {
            if ($useTransaction) {
                $this->conn->beginTransaction();
            }

            $selectQuery = "SELECT id, product_id, location_id, quantity, shelf_level, subdivision_number, batch_number, lot_number, expiry_date
                             FROM {$this->inventoryTable}
                             WHERE id = :id
                             FOR UPDATE";
            $selectStmt = $this->conn->prepare($selectQuery);
            $selectStmt->bindParam(':id', $inventoryId, PDO::PARAM_INT);
            $selectStmt->execute();
            $record = $selectStmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                if ($useTransaction && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                error_log('Increase inventory failed: record not found');
                return false;
            }

            $receivedAt = $options['received_at'] ?? date('Y-m-d H:i:s');

            $updateQuery = "UPDATE {$this->inventoryTable}
                            SET quantity = quantity + :quantity,
                                received_at = :received_at,
                                updated_at = NOW()
                            WHERE id = :id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':quantity', $quantity, PDO::PARAM_INT);
            $updateStmt->bindParam(':received_at', $receivedAt, PDO::PARAM_STR);
            $updateStmt->bindParam(':id', $inventoryId, PDO::PARAM_INT);
            $updateStmt->execute();

            $this->updateProductTotalQuantity((int)$record['product_id']);
            $this->updateLocationOccupancy((int)$record['location_id']);

            if ($useTransaction) {
                $this->conn->commit();
            }

            $userId = $_SESSION['user_id'] ?? 0;
            logActivity(
                $userId,
                'add',
                'inventory',
                $inventoryId,
                'Stock increased',
                ['quantity_before' => (int)$record['quantity']],
                ['quantity_after' => (int)$record['quantity'] + $quantity]
            );

            if ($this->hasTransactionLogging()) {
                try {
                    $duration = round((microtime(true) - $startTime), 2);
                    $this->getTransactionService()->logReceive(
                        (int)$record['product_id'],
                        (int)$record['location_id'],
                        $quantity,
                        [
                            'transaction_type' => $options['transaction_type'] ?? 'receive',
                            'batch_number' => $options['batch_number'] ?? $record['batch_number'] ?? null,
                            'lot_number' => $options['lot_number'] ?? $record['lot_number'] ?? null,
                            'expiry_date' => $options['expiry_date'] ?? $record['expiry_date'] ?? null,
                            'shelf_level' => $options['shelf_level'] ?? $record['shelf_level'] ?? null,
                            'subdivision_number' => $options['subdivision_number'] ?? $record['subdivision_number'] ?? null,
                            'reference_type' => $options['reference_type'] ?? 'manual',
                            'reference_id' => $options['reference_id'] ?? null,
                            'reason' => $options['reason'] ?? 'Stock increased in inventory',
                            'notes' => $options['notes'] ?? null,
                            'user_id' => $options['user_id'] ?? ($_SESSION['user_id'] ?? 0),
                            'duration_seconds' => $duration
                        ]
                    );
                } catch (Exception $e) {
                    error_log('Transaction logging failed: ' . $e->getMessage());
                }
            }

            return true;

        } catch (PDOException $e) {
            if ($useTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            if (!empty($record['product_id'])) {
                $this->discardDeferredAutoOrder((int)$record['product_id']);
            }
            error_log('Increase inventory failed: ' . $e->getMessage());
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
            $this->discardDeferredAutoOrder($productId);
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
            }
        } catch (PDOException $e) {
            error_log("Warning: Could not update product total quantity: " . $e->getMessage());
        } finally {
            $this->scheduleAutoOrderEvaluation($productId);
        }
    }

    private function scheduleAutoOrderEvaluation(int $productId): void
    {
        if ($this->conn->inTransaction()) {
            $this->deferredAutoOrderProductIds[$productId] = true;
            return;
        }

        $this->runAutoOrderEvaluation($productId);
    }

    private function runAutoOrderEvaluation(int $productId): void
    {
        try {
            $this->triggerAutoOrder($productId);
        } catch (Throwable $t) {
            error_log("Warning: Auto order evaluation failed: " . $t->getMessage());
        }
    }

    public function processDeferredAutoOrders(): void
    {
        if ($this->conn->inTransaction()) {
            return;
        }

        if (empty($this->deferredAutoOrderProductIds)) {
            return;
        }

        $productIds = array_keys($this->deferredAutoOrderProductIds);
        $this->deferredAutoOrderProductIds = [];

        foreach ($productIds as $productId) {
            $this->runAutoOrderEvaluation((int)$productId);
        }
    }

    public function discardDeferredAutoOrder(int $productId): void
    {
        unset($this->deferredAutoOrderProductIds[$productId]);
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
                    0 as product_quantity,
                    COALESCE(SUM(i.quantity), 0) as total_quantity,
                    COUNT(DISTINCT CASE WHEN i.quantity > 0 THEN i.location_id END) as locations_count,
                    GROUP_CONCAT(DISTINCT l.location_code) as locations,
                    p.product_id,
                    p.name as product_name
                FROM {$this->productsTable} p
                LEFT JOIN {$this->inventoryTable} i ON p.product_id = i.product_id AND i.quantity > 0
                LEFT JOIN {$this->locationsTable} l ON i.location_id = l.id
                WHERE p.sku = :sku
                GROUP BY p.product_id, p.name";

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
                         COALESCE(SUM(i.quantity), 0) as current_stock,
                         COUNT(DISTINCT CASE WHEN i.quantity > 0 THEN i.location_id END) as locations_count,
                         0 as product_base_quantity,
                         CASE
                            WHEN SUM(CASE WHEN i.quantity > 0 THEN 1 ELSE 0 END) > 0 THEN 'inventory_tracked'
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
                         COALESCE(SUM(i.quantity), 0) as total_stock,
                         COUNT(DISTINCT CASE WHEN i.quantity > 0 THEN i.location_id END) as locations_count,
                         MAX(i.received_at) as last_received,
                         0 as product_base_quantity,
                         CASE
                            WHEN SUM(CASE WHEN i.quantity > 0 THEN 1 ELSE 0 END) > 0 THEN 'inventory_tracked'
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
                    $this->discardDeferredAutoOrder($productId);
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
                $this->discardDeferredAutoOrder($productId);
                return false;
            }

            $this->conn->commit();

            $this->processDeferredAutoOrders();

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
            $this->discardDeferredAutoOrder($productId);
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
     * Get receiving entries with full details for stock entry history
     */
    public function getReceivingEntriesWithDetails(array $filters = [], int $page = 1, int $pageSize = 25): array {
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);
        $offset = ($page - 1) * $pageSize;

        $conditions = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $conditions[] = "DATE(COALESCE(rs.completed_at, ri.created_at)) >= :entries_date_from";
            $params[':entries_date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = "DATE(COALESCE(rs.completed_at, ri.created_at)) <= :entries_date_to";
            $params[':entries_date_to'] = $filters['date_to'];
        }

        if (!empty($filters['seller_id'])) {
            $conditions[] = "(po.seller_id = :entries_seller_id OR rs.supplier_id = :entries_seller_id)";
            $params[':entries_seller_id'] = (int)$filters['seller_id'];
        }

        if (!empty($filters['product_id'])) {
            $conditions[] = "ri.product_id = :entries_product_id";
            $params[':entries_product_id'] = (int)$filters['product_id'];
        }

        if (!empty($filters['invoice_status'])) {
            if ($filters['invoice_status'] === 'with') {
                $conditions[] = "(po.invoice_file_path IS NOT NULL AND po.invoice_file_path <> '')";
            } elseif ($filters['invoice_status'] === 'without') {
                $conditions[] = "(po.invoice_file_path IS NULL OR po.invoice_file_path = '')";
            }
        }

        if (!empty($filters['verification_status'])) {
            if ($filters['verification_status'] === 'verified') {
                $conditions[] = "po.invoice_verified = 1";
            } elseif ($filters['verification_status'] === 'unverified') {
                $conditions[] = "(po.invoice_verified = 0 OR po.invoice_verified IS NULL)";
            }
        }

        if (!empty($filters['search'])) {
            $params[':entries_search'] = '%' . $filters['search'] . '%';
            $conditions[] = "(
                p.name LIKE :entries_search OR
                p.sku LIKE :entries_search OR
                s.supplier_name LIKE :entries_search OR
                sr.supplier_name LIKE :entries_search OR
                po.order_number LIKE :entries_search OR
                rs.session_number LIKE :entries_search OR
                po.invoice_file_path LIKE :entries_search OR
                ri.notes LIKE :entries_search
            )";
        }

        $baseQuery = "
            FROM receiving_items ri
            INNER JOIN receiving_sessions rs ON ri.receiving_session_id = rs.id
            LEFT JOIN purchase_orders po ON rs.purchase_order_id = po.id
            LEFT JOIN sellers s ON po.seller_id = s.id
            LEFT JOIN sellers sr ON rs.supplier_id = sr.id
            LEFT JOIN products p ON ri.product_id = p.product_id
            LEFT JOIN users uv ON po.invoice_verified_by = uv.id
            LEFT JOIN users ur ON rs.received_by = ur.id
            LEFT JOIN users ua ON ri.admin_notes_updated_by = ua.id
        ";

        $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        try {
            $countSql = "SELECT COUNT(DISTINCT ri.id) $baseQuery $whereSql";
            $countStmt = $this->conn->prepare($countSql);
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();

            $dataSql = "SELECT
                    ri.id AS receiving_item_id,
                    ri.receiving_session_id,
                    ri.product_id,
                    ri.received_quantity,
                    ri.notes AS item_notes,
                    ri.admin_notes,
                    ri.admin_notes_updated_at,
                    COALESCE(rs.completed_at, ri.created_at) AS received_at,
                    rs.completed_at AS session_completed_at,
                    rs.session_number,
                    rs.supplier_document_number,
                    rs.supplier_document_type,
                    po.id AS purchase_order_id,
                    po.order_number,
                    po.invoice_file_path,
                    po.invoiced,
                    po.invoiced_at,
                    po.invoice_verified,
                    po.invoice_verified_at,
                    po.invoice_verified_by,
                    s.supplier_name,
                    sr.supplier_name AS fallback_supplier_name,
                    p.name AS product_name,
                    p.sku,
                    p.unit_of_measure,
                    uv.username AS invoice_verified_by_name,
                    ur.username AS received_by_username,
                    ua.username AS admin_notes_updated_by_name
                $baseQuery
                $whereSql
                ORDER BY COALESCE(rs.completed_at, ri.created_at) DESC, ri.id DESC
                LIMIT :entries_limit OFFSET :entries_offset";

            $stmt = $this->conn->prepare($dataSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':entries_limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':entries_offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                $row['supplier_name'] = $row['supplier_name'] ?: ($row['fallback_supplier_name'] ?? null);
                unset($row['fallback_supplier_name']);

                $sessionId = (int)($row['receiving_session_id'] ?? 0);
                $itemId = (int)($row['receiving_item_id'] ?? 0);

                $row['photos'] = $this->loadReceivingEntryPhotos($sessionId, $itemId);
                $row['description_text'] = $this->loadReceivingEntryDescription($sessionId, $itemId);
                if (isset($row['admin_notes_updated_at']) && $row['admin_notes_updated_at'] === '0000-00-00 00:00:00') {
                    $row['admin_notes_updated_at'] = null;
                }
                $row['invoice_verified'] = !empty($row['invoice_verified']);
            }

            return [
                'data' => $rows,
                'total' => $total,
            ];
        } catch (PDOException $e) {
            error_log('Error loading receiving entries: ' . $e->getMessage());
            return [
                'data' => [],
                'total' => 0,
            ];
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

        if (!$this->hasInventoryTransactions()) {
            return [
                'data' => [],
                'total' => 0,
            ];
        }

        $recordsSql = "SELECT
                CAST(t.id AS CHAR) AS record_id,
                CASE WHEN t.reason = 'Relocation' THEN 'relocation' ELSE 'transaction' END AS record_type,
                CASE WHEN t.reason = 'Relocation' THEN 'relocation' ELSE t.transaction_type END AS transaction_type,
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
                CASE WHEN t.reason = 'Relocation' THEN t.source_location_id ELSE NULL END AS relocation_target_location_id,
                CASE
                    WHEN t.reason = 'Relocation' AND t.quantity_change < 0 THEN 'out'
                    WHEN t.reason = 'Relocation' AND t.quantity_change > 0 THEN 'in'
                    ELSE NULL
                END AS movement_direction
            FROM inventory_transactions t";

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
     * Rulează o simulare completă a procesului de autocomandă fără a crea documente reale.
     */
    public function testAutoOrder(int $productId): array
    {
        $rezultat = $this->evaluateAutoOrderScenario($productId);
        $rezultat['mod_simulare'] = true;

        if ($rezultat['poate_comanda']) {
            try {
                require_once __DIR__ . '/PurchaseOrder.php';
                $po = new PurchaseOrder($this->conn);
                $numarEstimativ = $po->generateOrderNumber();
                $rezultat['comanda']['numar_estimativ'] = $numarEstimativ;

                $email = $this->buildAutoOrderEmail($numarEstimativ, $rezultat);
                $rezultat['email'] = $email;
                $rezultat['payload_simulat'] = $rezultat['payload'];
                $rezultat['payload_simulat']['email_subject'] = $email['subiect'];
                $rezultat['payload_simulat']['custom_message'] = $email['corp'];
            } catch (Exception $e) {
                $rezultat['email'] = null;
                $rezultat['validari'][] = [
                    'conditie' => 'Generare număr comandă',
                    'rezultat' => 'eroare',
                    'tip' => 'informativ',
                    'detalii' => 'Nu s-a putut genera un număr estimativ: ' . $e->getMessage()
                ];
            }
        } else {
            $rezultat['email'] = null;
        }

        return $rezultat;
    }

    /**
     * Analizează condițiile de autocomandă și pregătește detaliile necesare.
     */
    private function evaluateAutoOrderScenario(int $productId): array
{
    $detalii = [
        'poate_comanda' => false,
        'validari' => [],
        'produs' => null,
        'furnizor' => null,
        'articol' => null,
        'comanda' => null,
        'payload' => null
    ];

    try {
        // Updated query with all necessary fields including prices
        $query = "SELECT
            p.product_id,
            p.sku,
            p.name,
            p.price,                -- ✅ Product price in RON
            p.price_eur,           -- ✅ Product price in EUR
            COALESCE(SUM(inv.quantity), 0) as quantity,
            COALESCE(SUM(inv.quantity), 0) as current_stock,
            p.min_stock_level,
            p.min_order_quantity,
            p.auto_order_enabled,
            p.seller_id,
            p.last_auto_order_date,
            pp.id AS purchasable_product_id,
            pp.supplier_product_name,
            pp.supplier_product_code,
            pp.last_purchase_price,
            pp.currency,           -- ✅ Currency for purchasable products
            pp.preferred_seller_id,
            -- Primary seller data
            s1.supplier_name,
            s1.email AS seller_email,
            s1.contact_person,
            -- Preferred seller data
            s2.supplier_name AS preferred_supplier_name,
            s2.email AS preferred_seller_email,
            s2.contact_person AS preferred_contact_person
        FROM {$this->productsTable} p
        LEFT JOIN purchasable_products pp ON pp.internal_product_id = p.product_id
        LEFT JOIN sellers s1 ON s1.id = p.seller_id        -- Primary seller
        LEFT JOIN sellers s2 ON s2.id = pp.preferred_seller_id  -- Preferred seller
        LEFT JOIN inventory inv ON inv.product_id = p.product_id
        WHERE p.product_id = :id
        GROUP BY p.product_id, p.sku, p.name, p.price, p.price_eur, p.min_stock_level, p.min_order_quantity, 
                 p.auto_order_enabled, p.seller_id, p.last_auto_order_date, 
                 pp.id, pp.supplier_product_name, pp.supplier_product_code, 
                 pp.last_purchase_price, pp.currency, pp.preferred_seller_id, 
                 s1.supplier_name, s1.email, s1.contact_person,
                 s2.supplier_name, s2.email, s2.contact_person
        ORDER BY
            CASE WHEN pp.preferred_seller_id = p.seller_id THEN 0 ELSE 1 END,
            CASE WHEN pp.last_purchase_price IS NULL OR pp.last_purchase_price <= 0 THEN 1 ELSE 0 END,
            pp.id ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            $detalii['validari'][] = [
                'conditie' => 'Identificare produs',
                'rezultat' => 'eroare',
                'tip' => 'critic',
                'detalii' => 'Produsul solicitat nu există în baza de date.'
            ];
            return $detalii;
        }

        $primaLinie = $rows[0];
        $cantitateCurenta = (float)($primaLinie['quantity'] ?? 0); // Fixed to use 'quantity'
        $pragMinim = (float)($primaLinie['min_stock_level'] ?? 0);
        $cantitateMinimaComanda = (int)($primaLinie['min_order_quantity'] ?? 0);
        $ultimaAutocomanda = $primaLinie['last_auto_order_date'] ?? null;

        // Set product data
        $detalii['produs'] = [
            'id' => (int)$primaLinie['product_id'],
            'sku' => $primaLinie['sku'] ?? null,
            'nume' => $primaLinie['name'] ?? null,
            'cantitate_curenta' => $cantitateCurenta,
            'prag_minim' => $pragMinim,
            'cantitate_minima_comanda' => $cantitateMinimaComanda,
            'ultima_autocomanda' => $ultimaAutocomanda
        ];

        $detalii['validari'][] = [
            'conditie' => 'Produs disponibil',
            'rezultat' => 'ok',
            'tip' => 'critic',
            'detalii' => 'Produsul a fost găsit și poate fi procesat.'
        ];

        // Check if autoorder is enabled
        $autoActiv = (int)($primaLinie['auto_order_enabled'] ?? 0) === 1;
        $detalii['validari'][] = [
            'conditie' => 'Autocomandă activă',
            'rezultat' => $autoActiv ? 'ok' : 'eroare',
            'tip' => 'critic',
            'detalii' => $autoActiv ? 'Autocomanda este activată pentru produs.' : 'Autocomanda este dezactivată pentru acest produs.'
        ];

        // SUPPLIER PROCESSING LOGIC
        $sellerId = (int)($primaLinie['seller_id'] ?? 0);
        $numeFurnizor = $primaLinie['supplier_name'] ?? null;
        $emailFurnizor = trim($primaLinie['seller_email'] ?? '');
        $sursaFurnizor = 'produs';

        // If primary supplier is invalid, try preferred supplier
        if ($sellerId <= 0 || $emailFurnizor === '' || !filter_var($emailFurnizor, FILTER_VALIDATE_EMAIL)) {
            foreach ($rows as $row) {
                $preferredId = (int)($row['preferred_seller_id'] ?? 0);
                $preferredEmail = trim($row['preferred_seller_email'] ?? '');

                if ($preferredId > 0 && $preferredEmail !== '' && filter_var($preferredEmail, FILTER_VALIDATE_EMAIL)) {
                    $sellerId = $preferredId;
                    $numeFurnizor = $row['preferred_supplier_name'] ?? $numeFurnizor;
                    $emailFurnizor = $preferredEmail;
                    $sursaFurnizor = 'preferred';
                    break;
                }
            }
        }

        // Set supplier data
        $detalii['furnizor'] = [
            'id' => $sellerId,
            'nume' => $numeFurnizor,
            'email' => $emailFurnizor,
            'contact' => $primaLinie['contact_person'] ?? null,
            'sursa' => $sursaFurnizor
        ];

        // Validate supplier configuration
        $areFurnizor = $sellerId > 0;
        $detalii['validari'][] = [
            'conditie' => 'Furnizor configurat',
            'rezultat' => $areFurnizor ? 'ok' : 'eroare',
            'tip' => 'critic',
            'detalii' => $areFurnizor
                ? ($sursaFurnizor === 'preferred'
                    ? 'Produsul folosește furnizorul preferat definit în articolul achiziționabil.'
                    : 'Produsul are un furnizor principal configurat.')
                : 'Produsul nu are definit un furnizor.'
        ];

        // Validate supplier email
        $emailValid = $emailFurnizor !== '' && filter_var($emailFurnizor, FILTER_VALIDATE_EMAIL);
        $detalii['validari'][] = [
            'conditie' => 'Email furnizor valid',
            'rezultat' => $emailValid ? 'ok' : 'eroare',
            'tip' => 'critic',
            'detalii' => $emailValid ? 'Adresa de email a furnizorului este validă.' : 'Adresa de email a furnizorului este absentă sau invalidă.'
        ];

        // Stock level validation
        $subPrag = $pragMinim > 0 ? $cantitateCurenta <= $pragMinim : $cantitateCurenta <= 0;
        $detalii['validari'][] = [
            'conditie' => 'Nivel de stoc critic',
            'rezultat' => $subPrag ? 'ok' : 'eroare',
            'tip' => 'critic',
            'detalii' => $subPrag ? 'Stocul a atins pragul minim și necesită reaprovizionare.' : 'Stocul este peste pragul minim configurat.'
        ];

        // PRICE FALLBACK SETUP
        $pretFallback = 0.0;
        $monedaFallback = null;
        $pretProdusRon = isset($primaLinie['price']) ? (float)$primaLinie['price'] : 0.0;
        $pretProdusEur = isset($primaLinie['price_eur']) ? (float)$primaLinie['price_eur'] : 0.0;
        if ($pretProdusRon > 0) {
            $pretFallback = $pretProdusRon;
            $monedaFallback = 'RON';
        } elseif ($pretProdusEur > 0) {
            $pretFallback = $pretProdusEur;
            $monedaFallback = 'EUR';
        }

        // PROCESS PURCHASABLE PRODUCTS
        $piesaSelectata = null;
        $scorSelectat = -1;
        foreach ($rows as $row) {
            if (empty($row['purchasable_product_id'])) {
                continue;
            }

            $pretAchizitie = isset($row['last_purchase_price']) ? (float)$row['last_purchase_price'] : 0.0;
            $monedaAchizitie = $row['currency'] ?? null;
            $pretCalculat = $pretAchizitie;
            $monedaCalculata = $monedaAchizitie;

            // Use fallback price if no purchase price
            if ($pretCalculat <= 0 && $pretFallback > 0) {
                $pretCalculat = $pretFallback;
                $monedaCalculata = $monedaFallback ?? $monedaCalculata;
            }

            $monedaCalculata = $monedaCalculata ?: ($monedaFallback ?: 'RON');

            // Score the option
            $scor = 1;
            if ((int)($row['preferred_seller_id'] ?? 0) === $sellerId) {
                $scor += 4;
            }
            if ($pretCalculat > 0) {
                $scor += 3;
            }

            // Select best option
            if ($piesaSelectata === null
                || $scor > $scorSelectat
                || ($scor === $scorSelectat && $pretCalculat > (float)($piesaSelectata['pret'] ?? 0))) {
                $piesaSelectata = [
                    'purchasable_product_id' => (int)$row['purchasable_product_id'],
                    'supplier_product_name' => $row['supplier_product_name'] ?? null,
                    'supplier_product_code' => $row['supplier_product_code'] ?? null,
                    'pret' => $pretCalculat,
                    'currency' => $monedaCalculata,
                    'price_source' => $pretAchizitie > 0 ? 'purchasable_product' : ($pretCalculat > 0 ? 'product_price' : 'unknown'),
                    'requires_auto_creation' => false
                ];
                $scorSelectat = $scor;
            }
        }

        // CREATE FALLBACK PURCHASABLE PRODUCT IF NONE EXISTS
        if ($piesaSelectata === null && $pretFallback > 0) {
            $piesaSelectata = [
                'purchasable_product_id' => null,
                'supplier_product_name' => $primaLinie['supplier_product_name'] ?? $primaLinie['name'] ?? null,
                'supplier_product_code' => $primaLinie['supplier_product_code'] ?? $primaLinie['sku'] ?? null,
                'pret' => $pretFallback,
                'currency' => $monedaFallback ?: 'RON',
                'price_source' => 'product_price',
                'requires_auto_creation' => true
            ];
        }

        // SET ARTICLE DATA
        if ($piesaSelectata) {
            $detalii['articol'] = [
                'id' => $piesaSelectata['purchasable_product_id'] !== null
                    ? (int)$piesaSelectata['purchasable_product_id']
                    : null,
                'nume' => $piesaSelectata['supplier_product_name'] ?? null,
                'cod' => $piesaSelectata['supplier_product_code'] ?? null,
                'pret' => (float)($piesaSelectata['pret'] ?? 0),
                'currency' => $piesaSelectata['currency'] ?? ($monedaFallback ?: 'RON'),
                'price_source' => $piesaSelectata['price_source'] ?? null,
                'needs_auto_creation' => !empty($piesaSelectata['requires_auto_creation']),
                'internal_product_id' => (int)$primaLinie['product_id']
            ];
        } else {
            $detalii['articol'] = null;
        }

        // PRICE VALIDATION
        $pretValid = $detalii['articol'] && (float)$detalii['articol']['pret'] > 0;
        $detaliuPret = 'Nu există un preț de achiziție valid pentru produs.';
        if ($pretValid) {
            $sursaPret = $detalii['articol']['price_source'] ?? null;
            $monedaArticol = $detalii['articol']['currency'] ?? 'RON';
            if ($sursaPret === 'product_price') {
                if (!empty($detalii['articol']['needs_auto_creation'])) {
                    $detaliuPret = sprintf(
                        'Se va folosi prețul configurat în fișa produsului (%s). La trimiterea comenzii va fi creat automat un articol achiziționabil.',
                        $monedaArticol
                    );
                } else {
                    $detaliuPret = sprintf('Se va folosi prețul configurat în fișa produsului (%s).', $monedaArticol);
                }
            } elseif ($sursaPret === 'purchasable_product') {
                $detaliuPret = 'Se va folosi ultimul preț de achiziție salvat pentru furnizor.';
            } else {
                $detaliuPret = 'Există un preț de achiziție valid pentru produs.';
            }
        }
        $detalii['validari'][] = [
            'conditie' => 'Preț de achiziție disponibil',
            'rezultat' => $pretValid ? 'ok' : 'eroare',
            'tip' => 'critic',
            'detalii' => $detaliuPret
        ];

        $minIntervalMinutes = $this->getAutoOrderMinIntervalMinutes();
        $verificareDuplicat = $this->getDuplicateGuard()->canPlaceAutoOrder($productId, $minIntervalMinutes);
        $detalii['validari'][] = [
            'conditie' => 'Interval minim între autocomenzi',
            'rezultat' => $verificareDuplicat['permisa'] ? 'ok' : 'eroare',
            'tip' => 'critic',
            'detalii' => $verificareDuplicat['mesaj']
        ];

        // CALCULATE ORDER DETAILS
        $cantitateMinimaComanda = max(1, $cantitateMinimaComanda);
        $deficit = max(0, $pragMinim - $cantitateCurenta);
        $cantitateComandata = max($cantitateMinimaComanda, (int)ceil($deficit));
        if ($cantitateComandata <= 0) {
            $cantitateComandata = $cantitateMinimaComanda;
        }

        $pretUnitar = $detalii['articol']['pret'] ?? 0.0;
        $monedaComanda = $detalii['articol']['currency'] ?? ($monedaFallback ?: 'RON');
        $valoareTotala = $pretUnitar * $cantitateComandata;

        $detalii['comanda'] = [
            'cantitate' => $cantitateComandata,
            'deficit_estimat' => $deficit,
            'pret_unitar' => $pretUnitar,
            'valoare_totala' => $valoareTotala,
            'currency' => $monedaComanda,
            'price_source' => $detalii['articol']['price_source'] ?? null
        ];

        $detalii['validari'][] = [
            'conditie' => 'Cantitate minimă de comandă',
            'rezultat' => 'ok',
            'tip' => 'informativ',
            'detalii' => 'Cantitatea comandată va fi de ' . $cantitateComandata . ' bucăți.'
        ];

        // FINAL VALIDATION CHECK - DETERMINE IF ORDER CAN BE PLACED
        $poateComanda = true;
        foreach ($detalii['validari'] as $validare) {
            if ($validare['tip'] === 'critic' && $validare['rezultat'] !== 'ok') {
                $poateComanda = false;
                break;
            }
        }

        // SET PAYLOAD IF ORDER CAN BE PLACED
        if ($poateComanda && $detalii['articol']) {
            $detalii['payload'] = [
                'seller_id' => $sellerId,
                'status' => 'sent',
                'notes' => 'Autocomandă generată automat pe baza pragului minim de stoc.',
                'custom_message' => null,
                'email_subject' => null,
                'email_recipient' => $emailFurnizor,
                'total_amount' => $detalii['comanda']['valoare_totala'],
                'currency' => $monedaComanda,
                'items' => [[
                    'purchasable_product_id' => $detalii['articol']['id'],
                    'quantity' => $cantitateComandata,
                    'unit_price' => $detalii['articol']['pret'],
                    'notes' => 'Autocomandă generată automat de sistemul WMS.',
                    'needs_auto_creation' => !empty($detalii['articol']['needs_auto_creation']),
                    'internal_product_id' => (int)$primaLinie['product_id']
                ]]
            ];
        }

        // SET THE CRITICAL FLAG
        $detalii['poate_comanda'] = $poateComanda;

        // Add debug logging
        error_log("DEBUG Product {$productId}: poate_comanda=" . ($poateComanda ? 'true' : 'false') . 
                  ", price=" . $pretUnitar . ", total=" . $valoareTotala);

    } catch (Exception $e) {
        $detalii['validari'][] = [
            'conditie' => 'Procesare autocomandă',
            'rezultat' => 'eroare',
            'tip' => 'critic',
            'detalii' => 'A apărut o eroare neașteptată: ' . $e->getMessage()
        ];
        error_log("Error in evaluateAutoOrderScenario for product {$productId}: " . $e->getMessage());
    }

    return $detalii;
}

    /**
     * Construiește datele emailului de autocomandă.
     */
    private function deriveAutoOrderFailureMessage(array $context): string
    {
        if (!empty($context['validari']) && is_array($context['validari'])) {
            foreach ($context['validari'] as $validare) {
                $rezultat = strtolower((string)($validare['rezultat'] ?? ''));
                $tip = strtolower((string)($validare['tip'] ?? ''));
                if ($tip === 'critic' && $rezultat !== 'ok') {
                    return $validare['detalii']
                        ?? $validare['conditie']
                        ?? 'Condițiile de autocomandă nu sunt îndeplinite.';
                }
            }
        }

        return 'Condițiile de autocomandă nu sunt îndeplinite pentru acest produs.';
    }

    private function processAutoOrderExecution(array $context, int $productId, ?string $overrideRecipient = null): array
    {
        $result = [
            'success' => false,
            'message' => 'Autocomanda nu a putut fi procesată.',
            'order_id' => null,
            'order_number' => null,
            'recipient' => null
        ];

        $ensureResult = $this->ensureAutoOrderPurchasableProducts($context);
        if (empty($ensureResult['success'])) {
            $result['message'] = $ensureResult['message']
                ?? 'Produsul nu are articole achiziționabile pregătite pentru autocomandă.';
            return $result;
        }

        $context = $ensureResult['context'];

        $recipientEmail = $overrideRecipient
            ?: ($context['furnizor']['email'] ?? '');
        $recipientName = $context['furnizor']['nume'] ?? '';

        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $result['message'] = 'Adresa de email a furnizorului este invalidă.';
            return $result;
        }

        require_once __DIR__ . '/PurchaseOrder.php';
        $purchaseOrder = new PurchaseOrder($this->conn);
        $intervalMinute = $this->getAutoOrderMinIntervalMinutes();
        $guard = $this->getDuplicateGuard();

        $transactionStarted = false;
        $guardMessage = null;
        $orderId = null;

        try {
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $transactionStarted = true;
            }

            $verificareDuplicat = $guard->canPlaceAutoOrder($productId, $intervalMinute, true);
            if (!$verificareDuplicat['permisa']) {
                $guardMessage = $verificareDuplicat['mesaj'];
                throw new RuntimeException($guardMessage);
            }

            $orderId = $purchaseOrder->createPurchaseOrder($context['payload'], false);
            if (!$orderId) {
                $mesaj = $purchaseOrder->getLastError()
                    ?? 'Comanda de achiziție nu a putut fi creată.';
                throw new RuntimeException($mesaj);
            }

            $updateDate = $this->conn->prepare(
                "UPDATE {$this->productsTable} SET last_auto_order_date = CURRENT_TIMESTAMP WHERE product_id = :id"
            );
            $updateDate->bindValue(':id', $productId, PDO::PARAM_INT);
            $updateDate->execute();

            if ($transactionStarted && $this->conn->inTransaction()) {
                $this->conn->commit();
            }

            $orderNumberStmt = $this->conn->prepare('SELECT order_number FROM purchase_orders WHERE id = :id');
            $orderNumberStmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $orderNumberStmt->execute();
            $orderNumber = $orderNumberStmt->fetchColumn() ?: ('PO-' . date('Y') . '-NEDEFINIT');

            $result['order_id'] = $orderId;
            $result['order_number'] = $orderNumber;

            $orderInfo = [
                'order_number' => $orderNumber,
                'supplier_name' => $context['furnizor']['nume'] ?? '',
                'currency' => $context['comanda']['currency'] ?? 'RON'
            ];

            $itemsForPdf = [];
            foreach ($context['payload']['items'] as $itemPayload) {
                $itemsForPdf[] = [
                    'product_name' => $context['articol']['nume']
                        ?? ($context['produs']['nume'] ?? ''),
                    'quantity' => $itemPayload['quantity'] ?? 0,
                    'unit_price' => $itemPayload['unit_price'] ?? 0
                ];
            }

            $pdfFile = $this->generateAutoOrderPdf($orderInfo, $itemsForPdf);
            if ($pdfFile) {
                $purchaseOrder->updatePdfPath($orderId, $pdfFile);
            }

            $email = $this->buildAutoOrderEmail($orderNumber, $context);

            $updateEmailStmt = $this->conn->prepare(
                'UPDATE purchase_orders SET email_subject = :subject, custom_message = :message WHERE id = :id'
            );
            $updateEmailStmt->execute([
                ':subject' => $email['subiect'],
                ':message' => $email['corp'],
                ':id' => $orderId
            ]);

            $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
            $configGlobal = $GLOBALS['config'] ?? require $basePath . '/config/config.php';
            $smtpConfig = $configGlobal['email'] ?? [];

            if (empty($smtpConfig)) {
                $result['message'] = 'Configurația de email nu este definită.';
                return $result;
            }

            $emailResult = $this->dispatchAutoOrderEmail(
                $smtpConfig,
                $recipientEmail,
                $recipientName,
                $email['subiect'],
                $email['corp'],
                $email['corp_html'] ?? null,
                $pdfFile ?? ($context['payload']['pdf_path'] ?? null)
            );

            if ($emailResult['success']) {
                $purchaseOrder->markAsSent($orderId, $recipientEmail);
                if (function_exists('logActivity')) {
                    $descriere = sprintf('Autocomandă generată automat (%s)', $orderNumber);
                    logActivity(
                        $_SESSION['user_id'] ?? 0,
                        'create',
                        'purchase_order',
                        $orderId,
                        $descriere
                    );
                }

                $result['success'] = true;
                $result['message'] = sprintf(
                    'Autocomanda #%s pentru produsul %s a fost trimisă către %s.',
                    $orderNumber,
                    $context['produs']['nume'] ?? ('#' . $productId),
                    $recipientEmail
                );
                $result['recipient'] = $recipientEmail;
                return $result;
            }

            $result['message'] = sprintf(
                'Autocomanda %s pentru produsul %s nu a putut trimite emailul către %s: %s',
                $orderNumber,
                $context['produs']['nume'] ?? ('#' . $productId),
                $recipientEmail,
                $emailResult['message'] ?? 'motiv necunoscut'
            );
            $result['recipient'] = $recipientEmail;
            return $result;
        } catch (RuntimeException $e) {
            if ($transactionStarted && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            $result['message'] = $guardMessage !== null ? $guardMessage : $e->getMessage();
            return $result;
        } catch (Throwable $e) {
            if ($transactionStarted && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            $result['message'] = 'Autocomanda a întâmpinat o eroare neașteptată: ' . $e->getMessage();
            return $result;
        }
    }

    private function ensureAutoOrderPurchasableProducts(array $context): array
    {
        if (empty($context['payload']['items'])) {
            return ['success' => true, 'context' => $context];
        }

        require_once __DIR__ . '/PurchasableProduct.php';
        $purchasableModel = new PurchasableProduct($this->conn);

        foreach ($context['payload']['items'] as $index => $item) {
            $purchasableId = (int)($item['purchasable_product_id'] ?? 0);
            if ($purchasableId > 0) {
                unset($context['payload']['items'][$index]['needs_auto_creation']);
                continue;
            }

            $internalProductId = (int)($item['internal_product_id'] ?? ($context['produs']['id'] ?? 0));
            $sellerId = (int)($context['furnizor']['id'] ?? 0);
            $unitPrice = (float)($item['unit_price'] ?? 0);
            $currency = $context['articol']['currency'] ?? ($context['comanda']['currency'] ?? 'RON');

            if ($internalProductId <= 0 || $sellerId <= 0 || $unitPrice <= 0) {
                return [
                    'success' => false,
                    'message' => 'Nu s-a putut crea articolul achiziționabil necesar pentru autocomandă.'
                ];
            }

            $productName = $context['articol']['nume'] ?? ($context['produs']['nume'] ?? ('Produs #' . $internalProductId));
            $productCode = $context['articol']['cod'] ?? ($context['produs']['sku'] ?? null);

            $creationData = [
                'supplier_product_name' => $productName,
                'supplier_product_code' => $productCode,
                'description' => 'Articol creat automat pentru autocomandă.',
                'unit_measure' => 'buc',
                'last_purchase_price' => $unitPrice,
                'currency' => $currency,
                'internal_product_id' => $internalProductId,
                'preferred_seller_id' => $sellerId,
                'notes' => 'Generat automat de modulul de autocomandă.',
                'status' => 'active'
            ];

            $newId = $purchasableModel->createProduct($creationData);
            if (!$newId) {
                $error = $purchasableModel->getLastError() ?? 'Nu s-a putut crea produsul achiziționabil necesar.';
                return [
                    'success' => false,
                    'message' => $error
                ];
            }

            $context['payload']['items'][$index]['purchasable_product_id'] = $newId;
            unset($context['payload']['items'][$index]['needs_auto_creation']);
            $context['articol']['id'] = $newId;
            $context['articol']['needs_auto_creation'] = false;
        }

        return ['success' => true, 'context' => $context];
    }

    private function buildAutoOrderEmail(string $orderNumber, array $context): array
    {
        $numeProdus = trim($context['produs']['nume'] ?? '') ?: 'Produs fără nume';
        $sku = trim($context['produs']['sku'] ?? '') ?: 'N/A';
        $cantitate = $context['comanda']['cantitate'] ?? 0;
        $pret = $context['comanda']['pret_unitar'] ?? 0.0;
        $total = $context['comanda']['valoare_totala'] ?? 0.0;
        $currency = $context['comanda']['currency'] ?? 'RON';
        $dataGenerarii = date('d.m.Y, ora H:i');

        $pretFormatat = number_format((float)$pret, 2, ',', '.');
        $totalFormatat = number_format((float)$total, 2, ',', '.');

        $subiect = sprintf('🤖 Autocomandă generată automat - %s (%s)', $orderNumber, $numeProdus);

        $lines = [
            'Detalii comandă',
            '',
            "Număr comandă: {$orderNumber}",
            '',
            "Data generării: {$dataGenerarii}",
            '',
            'Tip comandă: Autocomandă generată automat',
            '',
            "Denumire produs: {$numeProdus}",
            '',
            "Cod / SKU: {$sku}",
            '',
            "Cantitate solicitată: {$cantitate} bucăți",
            '',
            'Bună ziua,',
            '',
            "Sistemul WMS a detectat că produsul \"{$numeProdus}\" (SKU: {$sku}) a atins nivelul minim de stoc și necesită reaprovizionare.",
            '',
            'Detalii financiare',
            '',
            "Preț unitar estimat: {$pretFormatat} {$currency}",
            '',
            "Valoare totală estimată: {$totalFormatat} {$currency}",
            '',
            'Această autocomandă a fost creată automat conform pragurilor de stoc configurate în sistem.',
            '',
            'Vă mulțumim pentru promptitudine.',
            '',
            'Cu stimă,',
            'Echipa Wartung – Sistem WMS',
        ];
        $corpText = implode("\r\n", $lines);

        $numeProdusHtml = htmlspecialchars($numeProdus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $skuHtml = htmlspecialchars($sku, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $htmlTableRow = static function (string $label, string $value): string {
            return sprintf(
                '<tr><td style="padding:2px 12px 2px 0;font-weight:600;white-space:nowrap;">%s</td><td style="padding:2px 0;color:#1f2933;">%s</td></tr>',
                htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        };

        $detaliiComandaRows = [
            $htmlTableRow('Număr comandă:', $orderNumber),
            $htmlTableRow('Data generării:', $dataGenerarii),
            $htmlTableRow('Tip comandă:', 'Autocomandă generată automat'),
            $htmlTableRow('Denumire produs:', $numeProdus),
            $htmlTableRow('Cod / SKU:', $sku),
            $htmlTableRow('Cantitate solicitată:', $cantitate . ' bucăți'),
        ];

        $detaliiFinanciareRows = [
            $htmlTableRow('Preț unitar estimat:', $pretFormatat . ' ' . $currency),
            $htmlTableRow('Valoare totală estimată:', $totalFormatat . ' ' . $currency),
        ];

        $corpHtml = <<<HTML
<div style="font-family:Arial,'Helvetica Neue',Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#111827;">
    <p style="margin:0 0 12px 0;font-size:16px;font-weight:700;color:#0f172a;">Detalii comandă</p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;border-collapse:collapse;">
        %s
    </table>
    <p style="margin:0 0 12px 0;">Bună ziua,</p>
    <p style="margin:0 0 16px 0;">Sistemul WMS a detectat că produsul „%s” (SKU: %s) a atins nivelul minim de stoc și necesită reaprovizionare.</p>
    <p style="margin:0 0 12px 0;font-size:16px;font-weight:700;color:#0f172a;">Detalii financiare</p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;border-collapse:collapse;">
        %s
    </table>
    <p style="margin:0 0 12px 0;">Această autocomandă a fost creată automat conform pragurilor de stoc configurate în sistem.</p>
    <p style="margin:0 0 12px 0;">Vă mulțumim pentru promptitudine.</p>
    <p style="margin:0 0 2px 0;">Cu stimă,</p>
    <p style="margin:0;">Echipa Wartung – Sistem WMS</p>
</div>
HTML;

        $corpHtml = sprintf(
            $corpHtml,
            implode('', $detaliiComandaRows),
            $numeProdusHtml,
            $skuHtml,
            implode('', $detaliiFinanciareRows)
        );

        return [
            'subiect' => $subiect,
            'corp' => $corpText,
            'corp_html' => $corpHtml,
            'data_generare' => $dataGenerarii
        ];
    }

    private function generateAutoOrderPdf(array $orderInfo, array $items): ?string
    {
        if (!class_exists('FPDF')) {
            $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
            $fpdfPath = $basePath . '/lib/fpdf.php';
            if (file_exists($fpdfPath)) {
                require_once $fpdfPath;
            }
        }

        if (!class_exists('FPDF')) {
            error_log('FPDF library is not available for auto order PDF generation.');
            return null;
        }

        try {
            $currency = $orderInfo['currency'] ?? 'RON';
            $pdf = new \FPDF();
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(0, 10, 'COMANDA ACHIZITIE', 0, 1, 'C');

            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 8, 'Numar comanda: ' . ($orderInfo['order_number'] ?? ''), 0, 1);
            $pdf->Cell(0, 8, 'Furnizor: ' . ($orderInfo['supplier_name'] ?? ''), 0, 1);
            $pdf->Cell(0, 8, 'Data: ' . date('d.m.Y H:i'), 0, 1);
            $pdf->Ln(10);

            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(80, 8, 'Produs', 1, 0, 'C');
            $pdf->Cell(30, 8, 'Cantitate', 1, 0, 'C');
            $pdf->Cell(30, 8, 'Pret (' . $currency . ')', 1, 0, 'C');
            $pdf->Cell(40, 8, 'Total (' . $currency . ')', 1, 1, 'C');

            $pdf->SetFont('Arial', '', 9);
            $grandTotal = 0.0;

            foreach ($items as $item) {
                $name = $item['product_name'] ?? '';
                if (strlen($name) > 45) {
                    $name = substr($name, 0, 42) . '...';
                }

                $quantity = (float)($item['quantity'] ?? 0);
                $unitPrice = (float)($item['unit_price'] ?? 0);
                $lineTotal = $quantity * $unitPrice;
                $grandTotal += $lineTotal;

                $pdf->Cell(80, 8, $name, 1);
                $pdf->Cell(30, 8, number_format($quantity, 2), 1, 0, 'R');
                $pdf->Cell(30, 8, number_format($unitPrice, 2), 1, 0, 'R');
                $pdf->Cell(40, 8, number_format($lineTotal, 2), 1, 1, 'R');
            }

            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(140, 8, 'TOTAL', 1, 0, 'R');
            $pdf->Cell(40, 8, number_format($grandTotal, 2) . ' ' . $currency, 1, 1, 'R');

            $fileName = 'po_' . ($orderInfo['order_number'] ?? 'auto') . '_' . time() . '.pdf';
            $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
            $writableLocations = [
                $basePath . '/storage/purchase_order_pdfs/',
                '/tmp/wms_pdfs/',
                '/tmp/',
                $basePath . '/tmp/',
                sys_get_temp_dir() . '/'
            ];

            foreach ($writableLocations as $directory) {
                if (!is_dir($directory)) {
                    @mkdir($directory, 0777, true);
                }

                if (!is_dir($directory) || !is_writable($directory)) {
                    continue;
                }

                $fullPath = $directory . $fileName;
                try {
                    $pdf->Output('F', $fullPath);
                    if (is_file($fullPath) && filesize($fullPath) > 0) {
                        return $fileName;
                    }
                } catch (Exception $e) {
                    error_log('Eroare la generarea PDF pentru autocomandă: ' . $e->getMessage());
                }
            }

            error_log('Nu s-a putut salva PDF-ul autocomenzii în niciuna dintre locațiile disponibile.');
            return null;
        } catch (Throwable $t) {
            error_log('Excepție la generarea PDF-ului pentru autocomandă: ' . $t->getMessage());
            return null;
        }
    }

    private function resolvePdfAttachment(?string $fileName): ?array
    {
        if (empty($fileName)) {
            return null;
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        $paths = [
            $basePath . '/storage/purchase_order_pdfs/' . $fileName,
            '/tmp/wms_pdfs/' . $fileName,
            '/tmp/' . $fileName,
            $basePath . '/tmp/' . $fileName,
            $fileName
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                return [
                    'path' => $path,
                    'name' => basename($path),
                    'data' => @file_get_contents($path) ?: null
                ];
            }
        }

        return null;
    }

    private function saveAutoOrderEmailToSentFolder(array $smtpConfig, string $toEmail, string $subject, string $body, ?string $attachmentData, ?string $attachmentName): bool
    {
        if (!extension_loaded('imap')) {
            return false;
        }

        $imapHost = $smtpConfig['imap_host'] ?? $smtpConfig['host'] ?? '';
        if (empty($imapHost)) {
            return false;
        }

        $imapPort = (int)($smtpConfig['imap_port'] ?? 993);
        $flags = $smtpConfig['imap_flags'] ?? ['/imap/ssl/novalidate-cert'];
        $folders = $smtpConfig['imap_sent_folders'] ?? ['Sent'];

        $fromEmail = $smtpConfig['from_email'] ?? ($smtpConfig['username'] ?? '');
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $fromEmail,
            'To: ' . $toEmail,
            'Subject: ' . $subject,
            'MIME-Version: 1.0'
        ];

        $message = implode("\r\n", $headers) . "\r\n";

        if ($attachmentData && $attachmentName) {
            $boundary = '----=_AutoOrder_' . md5(uniqid('', true));
            $message .= 'Content-Type: multipart/mixed; boundary="' . $boundary . "\r\n\r\n";
            $message .= '--' . $boundary . "\r\n";
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $message .= $body . "\r\n\r\n";
            $message .= '--' . $boundary . "\r\n";
            $message .= 'Content-Type: application/pdf; name="' . $attachmentName . "\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= 'Content-Disposition: attachment; filename="' . $attachmentName . "\r\n\r\n";
            $message .= chunk_split(base64_encode($attachmentData)) . "\r\n";
            $message .= '--' . $boundary . "--\r\n";
        } else {
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $message .= $body;
        }

        foreach ($flags as $flag) {
            foreach ($folders as $folder) {
                $mailbox = sprintf('{%s:%d%s}%s', $imapHost, $imapPort, $flag, $folder);
                $connection = @imap_open($mailbox, $smtpConfig['username'], $smtpConfig['password']);
                if ($connection === false) {
                    continue;
                }

                $result = imap_append($connection, $mailbox, $message, '\\Seen');
                imap_close($connection);

                if ($result) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Trimite emailul de autocomandă folosind infrastructura existentă.
     */
    private function dispatchAutoOrderEmail(
        array $smtpConfig,
        string $toEmail,
        ?string $toName,
        string $subject,
        string $bodyText,
        ?string $bodyHtml = null,
        ?string $pdfFile = null
    ): array
    {
        if (empty($smtpConfig['host']) || empty($smtpConfig['port']) || empty($smtpConfig['username']) || empty($smtpConfig['password'])) {
            return [
                'success' => false,
                'message' => 'Configurația SMTP este incompletă.'
            ];
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        require_once $basePath . '/lib/PHPMailer/PHPMailer.php';
        require_once $basePath . '/lib/PHPMailer/SMTP.php';
        require_once $basePath . '/lib/PHPMailer/Exception.php';

        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $smtpConfig['host'];
            $mailer->Port = (int)$smtpConfig['port'];
            $mailer->SMTPAuth = true;
            $mailer->Username = $smtpConfig['username'];
            $mailer->Password = $smtpConfig['password'];
            if (!empty($smtpConfig['encryption'])) {
                $mailer->SMTPSecure = $smtpConfig['encryption'];
            }
            $mailer->CharSet = 'UTF-8';

            $fromEmail = $smtpConfig['from_email'] ?? $smtpConfig['username'];
            $fromName = $smtpConfig['from_name'] ?? 'Sistem WMS';
            $mailer->setFrom($fromEmail, $fromName);
            if (!empty($smtpConfig['reply_to'])) {
                $mailer->addReplyTo($smtpConfig['reply_to']);
            }

            $mailer->addAddress($toEmail, $toName ?? '');
            if (!empty($fromEmail)) {
                $mailer->addBCC($fromEmail);
            }
            $mailer->Subject = $subject;
            if ($bodyHtml) {
                $mailer->isHTML(true);
                $mailer->Body = $bodyHtml;
                $mailer->AltBody = $bodyText;
            } else {
                $mailer->isHTML(false);
                $mailer->Body = $bodyText;
                $mailer->AltBody = $bodyText;
            }

            $attachment = $this->resolvePdfAttachment($pdfFile);
            if ($attachment && !empty($attachment['path'])) {
                $mailer->addAttachment($attachment['path'], $attachment['name']);
            }

            $mailer->send();

            $savedToSent = $this->saveAutoOrderEmailToSentFolder(
                $smtpConfig,
                $toEmail,
                $subject,
                $bodyText,
                $attachment['data'] ?? null,
                $attachment['name'] ?? null
            );

            if (!$savedToSent) {
                error_log('Autocomandă: email transmis dar nu a putut fi salvat în folderul Sent.');
            }

            return [
                'success' => true,
                'message' => 'Email trimis cu succes.'
            ];
        } catch (\PHPMailer\PHPMailer\Exception $mailException) {
            return [
                'success' => false,
                'message' => 'Trimiterea emailului a eșuat: ' . $mailException->getMessage()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Eroare neașteptată la trimiterea emailului: ' . $e->getMessage()
            ];
        }
    }

    public function executeAutoOrder(int $productId, ?string $overrideRecipient = null): array
    {
        $context = $this->evaluateAutoOrderScenario($productId);

        if (empty($context['poate_comanda']) || empty($context['payload'])) {
            return [
                'success' => false,
                'message' => $this->deriveAutoOrderFailureMessage($context),
                'order_id' => null,
                'order_number' => null,
                'recipient' => null
            ];
        }

        $result = $this->processAutoOrderExecution($context, $productId, $overrideRecipient);

        if (!$result['success']) {
            error_log(sprintf(
                'Autocomandă produs #%d: %s',
                $productId,
                $result['message']
            ));
        }

        return $result;
    }

    public function sendAutoOrderTestEmail(int $productId, ?string $testRecipient = null): array
    {
        $simulation = $this->testAutoOrder($productId);

        if (empty($simulation['poate_comanda'])) {
            return [
                'success' => false,
                'message' => $this->deriveAutoOrderFailureMessage($simulation)
            ];
        }

        $recipientEmail = $testRecipient
            ?: ($simulation['furnizor']['email'] ?? '');

        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Adresa de email pentru test nu este validă.'
            ];
        }

        $orderNumber = $simulation['comanda']['numar_estimativ']
            ?? ('TEST-AUTO-' . date('Ymd-His'));

        $itemsSource = $simulation['payload_simulat']['items']
            ?? $simulation['payload']['items']
            ?? [];

        $itemsForPdf = [];
        foreach ($itemsSource as $itemPayload) {
            if (!is_array($itemPayload)) {
                continue;
            }
            $itemsForPdf[] = [
                'product_name' => $simulation['articol']['nume']
                    ?? ($simulation['produs']['nume'] ?? ''),
                'quantity' => $itemPayload['quantity'] ?? 0,
                'unit_price' => $itemPayload['unit_price'] ?? 0
            ];
        }

        if (empty($itemsForPdf)) {
            $itemsForPdf[] = [
                'product_name' => $simulation['produs']['nume'] ?? '',
                'quantity' => $simulation['comanda']['cantitate'] ?? 0,
                'unit_price' => $simulation['comanda']['pret_unitar'] ?? 0
            ];
        }

        $orderInfo = [
            'order_number' => $orderNumber,
            'supplier_name' => $simulation['furnizor']['nume'] ?? '',
            'currency' => $simulation['comanda']['currency'] ?? 'RON'
        ];

        $pdfFile = $this->generateAutoOrderPdf($orderInfo, $itemsForPdf);

        $emailData = $simulation['email'] ?? $this->buildAutoOrderEmail($orderNumber, $simulation);

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        $configGlobal = $GLOBALS['config'] ?? require $basePath . '/config/config.php';
        $smtpConfig = $configGlobal['email'] ?? [];

        if (empty($smtpConfig)) {
            return [
                'success' => false,
                'message' => 'Configurația de email nu este definită.'
            ];
        }

        $dispatchResult = $this->dispatchAutoOrderEmail(
            $smtpConfig,
            $recipientEmail,
            $simulation['furnizor']['nume'] ?? '',
            $emailData['subiect'] ?? ('Autocomandă test - ' . $orderNumber),
            $emailData['corp'] ?? 'Acesta este un email de test pentru autocomandă.',
            $emailData['corp_html'] ?? null,
            $pdfFile
        );

        if (!empty($pdfFile)) {
            $attachment = $this->resolvePdfAttachment($pdfFile);
            if ($attachment && !empty($attachment['path']) && is_file($attachment['path'])) {
                @unlink($attachment['path']);
            }
        }

        if ($dispatchResult['success']) {
            return [
                'success' => true,
                'message' => sprintf('Emailul de test a fost trimis către %s.', $recipientEmail),
                'order_number' => $orderNumber
            ];
        }

        return [
            'success' => false,
            'message' => $dispatchResult['message'] ?? 'Trimiterea emailului de test a eșuat.'
        ];
    }

    private function loadReceivingEntryPhotos(int $sessionId, int $itemId): array {
        $directories = [
            BASE_PATH . '/storage/receiving/factory',
            BASE_PATH . '/storage/receiving/sellers',
        ];
        $patterns = [
            "session_{$sessionId}_*",
            "receipt_{$itemId}_*",
        ];
        $extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $photos = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                foreach ($extensions as $extension) {
                    $globPattern = $directory . '/' . $pattern . '.' . $extension;
                    foreach (glob($globPattern) as $filePath) {
                        $relative = ltrim(str_replace(BASE_PATH . '/', '', $filePath), '/');
                        if (!isset($photos[$relative])) {
                            $photos[$relative] = [
                                'path' => $relative,
                                'url' => $this->buildStorageUrl($relative),
                                'thumbnail_url' => $this->buildStorageUrl($relative),
                                'filename' => basename($filePath),
                            ];
                        }
                    }
                }
            }
        }

        return array_values($photos);
    }

    private function loadReceivingEntryDescription(int $sessionId, int $itemId): ?string {
        $directories = [
            BASE_PATH . '/storage/receiving/descriptions',
            BASE_PATH . '/storage/receiving/factory',
            BASE_PATH . '/storage/receiving/sellers',
            BASE_PATH . '/storage/receiving',
        ];

        $candidates = [
            "session_{$sessionId}_desc.txt",
            "receipt_{$itemId}_desc.txt",
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            foreach ($candidates as $filename) {
                $path = rtrim($dir, '/') . '/' . $filename;
                if (is_file($path)) {
                    $contents = trim((string)@file_get_contents($path));
                    if ($contents !== '') {
                        return $contents;
                    }
                }
            }
        }

        return null;
    }

    private function buildStorageUrl(string $relativePath): string {
        $normalized = ltrim($relativePath, '/');
        if (strpos($normalized, 'storage/') !== 0) {
            $normalized = 'storage/' . $normalized;
        }

        $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        if ($baseUrl) {
            return $baseUrl . '/' . $normalized;
        }

        return '/' . $normalized;
    }

    /**
     * Trigger automatic purchase order if stock below minimum
     */
    private function triggerAutoOrder(int $productId): void {
        $context = $this->evaluateAutoOrderScenario($productId);

        if (empty($context['poate_comanda']) || empty($context['payload'])) {
            foreach ($context['validari'] as $validare) {
                if (($validare['tip'] ?? '') === 'critic' && ($validare['rezultat'] ?? '') !== 'ok') {
                    error_log(sprintf(
                        'Autocomandă blocată pentru produsul #%d: %s - %s',
                        $productId,
                        $validare['conditie'] ?? 'Condiție necunoscută',
                        $validare['detalii'] ?? 'Fără detalii'
                    ));
                }
            }
            return;
        }

        $result = $this->processAutoOrderExecution($context, $productId);

        if ($result['success']) {
            error_log(sprintf(
                'Autocomandă finalizată pentru produsul #%d: comanda %s a fost transmisă către %s.',
                $productId,
                $result['order_number'] ?? 'N/A',
                $result['recipient'] ?? 'destinatar necunoscut'
            ));
        } else {
            error_log(sprintf(
                'Autocomandă produs #%d: %s',
                $productId,
                $result['message'] ?? 'Trimiterea emailului nu a reușit.'
            ));
        }
    }
}
