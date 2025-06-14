<?php
/**
 * Transaction Model - Manages WMS to SmartBill transaction synchronization
 * Handles automatic invoice generation, inventory updates, and accounting integration
 */

require_once BASE_PATH . '/models/SmartBillService.php';

class Transaction {
    private $conn;
    private $smartBillService;
    private $transactionsTable = "transactions";
    private $transactionItemsTable = "transaction_items";
    private $transactionQueueTable = "transaction_queue";
    private $transactionAuditTable = "transaction_audit";
    
    // Transaction types
    const TYPE_SALES = 'sales';
    const TYPE_PURCHASE = 'purchase';
    const TYPE_ADJUSTMENT = 'adjustment';
    const TYPE_TRANSFER = 'transfer';
    const TYPE_RETURN = 'return';
    
    // Transaction statuses
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';
    
    // Reference types
    const REF_ORDER = 'order';
    const REF_INVENTORY = 'inventory';
    const REF_LOCATION = 'location';
    const REF_MANUAL = 'manual';

    public function __construct($db) {
        $this->conn = $db;
        $this->smartBillService = new SmartBillService($db);
    }

    /**
     * Get all transactions with filtering and pagination
     * @param array $filters Optional filters
     * @param int $limit Limit for pagination
     * @param int $offset Offset for pagination
     * @return array Array of transactions
     */
    public function getAllTransactions(array $filters = [], int $limit = 50, int $offset = 0): array {
        $query = "SELECT t.*, 
                         COALESCE(creator.username, 'System') as created_by_name,
                         COALESCE(processor.username, 'System') as processed_by_name,
                         COUNT(ti.id) as item_count
                  FROM {$this->transactionsTable} t
                  LEFT JOIN users creator ON t.created_by = creator.id
                  LEFT JOIN users processor ON t.processed_by = processor.id
                  LEFT JOIN {$this->transactionItemsTable} ti ON t.id = ti.transaction_id
                  WHERE 1=1";

        $params = [];

        // Apply filters
        if (!empty($filters['transaction_type'])) {
            $query .= " AND t.transaction_type = :transaction_type";
            $params[':transaction_type'] = $filters['transaction_type'];
        }

        if (!empty($filters['status'])) {
            $query .= " AND t.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['reference_type'])) {
            $query .= " AND t.reference_type = :reference_type";
            $params[':reference_type'] = $filters['reference_type'];
        }

        if (!empty($filters['date_from'])) {
            $query .= " AND DATE(t.created_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $query .= " AND DATE(t.created_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['customer_name'])) {
            $query .= " AND t.customer_name LIKE :customer_name";
            $params[':customer_name'] = '%' . $filters['customer_name'] . '%';
        }

        if (!empty($filters['smartbill_doc_number'])) {
            $query .= " AND t.smartbill_doc_number LIKE :smartbill_doc_number";
            $params[':smartbill_doc_number'] = '%' . $filters['smartbill_doc_number'] . '%';
        }

        $query .= " GROUP BY t.id ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        try {
            $stmt = $this->conn->prepare($query);
            
            // Bind parameters with correct types
            foreach ($params as $key => $value) {
                if ($key === ':limit' || $key === ':offset') {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value, PDO::PARAM_STR);
                }
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching transactions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get transaction by ID with items
     * @param int $id Transaction ID
     * @return array|false Transaction data with items or false if not found
     */
    public function findById(int $id) {
        $transactionQuery = "SELECT t.*, 
                                    COALESCE(creator.username, 'System') as created_by_name,
                                    COALESCE(processor.username, 'System') as processed_by_name
                             FROM {$this->transactionsTable} t
                             LEFT JOIN users creator ON t.created_by = creator.id
                             LEFT JOIN users processor ON t.processed_by = processor.id
                             WHERE t.id = :id";
        
        try {
            $stmt = $this->conn->prepare($transactionQuery);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$transaction) {
                return false;
            }

            // Get transaction items
            $itemsQuery = "SELECT ti.*, p.name as product_name_lookup
                          FROM {$this->transactionItemsTable} ti
                          LEFT JOIN products p ON ti.product_id = p.product_id
                          WHERE ti.transaction_id = :transaction_id
                          ORDER BY ti.id ASC";
            
            $itemsStmt = $this->conn->prepare($itemsQuery);
            $itemsStmt->bindParam(':transaction_id', $id, PDO::PARAM_INT);
            $itemsStmt->execute();
            
            $transaction['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $transaction;
        } catch (PDOException $e) {
            error_log("Error finding transaction by ID: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create transaction from order shipment
     * @param int $orderId Order ID
     * @return int|false Transaction ID on success, false on failure
     */
    public function createFromOrder(int $orderId): int|false {
        try {
            $this->conn->beginTransaction();

            // Get order details
            require_once BASE_PATH . '/models/Order.php';
            $orderModel = new Order($this->conn);
            $order = $orderModel->findById($orderId);

            if (!$order || empty($order['items'])) {
                $this->conn->rollback();
                error_log("Order not found or has no items: {$orderId}");
                return false;
            }

            // Calculate totals
            $totalAmount = 0;
            $totalTax = 0;
            
            foreach ($order['items'] as $item) {
                $lineTotal = $item['quantity_ordered'] * $item['unit_price'];
                $totalAmount += $lineTotal;
                // Assume 19% VAT for now - should be configurable
                $totalTax += $lineTotal * 0.19 / 1.19;
            }

            $netAmount = $totalAmount - $totalTax;

            // Create transaction record
            $transactionData = [
                'transaction_type' => self::TYPE_SALES,
                'reference_type' => self::REF_ORDER,
                'reference_id' => $orderId,
                'status' => self::STATUS_PENDING,
                'amount' => $totalAmount,
                'tax_amount' => $totalTax,
                'net_amount' => $netAmount,
                'currency' => 'RON',
                'description' => "Invoice for order {$order['order_number']}",
                'customer_name' => $order['customer_name'],
                'invoice_date' => date('Y-m-d'),
                'created_by' => $_SESSION['user_id'] ?? null
            ];

            $transactionId = $this->create($transactionData);
            
            if (!$transactionId) {
                $this->conn->rollback();
                return false;
            }

            // Create transaction items
            foreach ($order['items'] as $item) {
                $this->addTransactionItem($transactionId, [
                    'product_id' => $item['product_id'],
                    'sku' => $item['sku'],
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity_ordered'],
                    'unit_price' => $item['unit_price'],
                    'tax_percent' => 19.00, // Should be configurable
                    'unit_of_measure' => 'buc'
                ]);
            }

            // Queue for SmartBill synchronization
            $this->queueForSync($transactionId, 'high');

            $this->conn->commit();
            
            // Log audit trail
            $this->logAudit($transactionId, 'created', null, self::STATUS_PENDING, [
                'source' => 'order_shipment',
                'order_id' => $orderId
            ]);

            return $transactionId;

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Error creating transaction from order: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create transaction from inventory adjustment
     * @param int $inventoryId Inventory record ID
     * @param array $adjustmentData Adjustment details
     * @return int|false Transaction ID on success, false on failure
     */
    public function createFromInventoryAdjustment(int $inventoryId, array $adjustmentData): int|false {
        try {
            $this->conn->beginTransaction();

            // Get inventory details
            require_once BASE_PATH . '/models/Inventory.php';
            $inventoryModel = new Inventory($this->conn);
            
            $query = "SELECT i.*, p.sku, p.name as product_name, p.price, l.location_code
                     FROM inventory i
                     LEFT JOIN products p ON i.product_id = p.product_id
                     LEFT JOIN locations l ON i.location_id = l.id
                     WHERE i.id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $inventoryId, PDO::PARAM_INT);
            $stmt->execute();
            $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventory) {
                $this->conn->rollback();
                return false;
            }

            $adjustmentValue = $adjustmentData['quantity_change'] * $inventory['price'];

            // Create transaction record
            $transactionData = [
                'transaction_type' => self::TYPE_ADJUSTMENT,
                'reference_type' => self::REF_INVENTORY,
                'reference_id' => $inventoryId,
                'status' => self::STATUS_PENDING,
                'amount' => abs($adjustmentValue),
                'tax_amount' => abs($adjustmentValue) * 0.19 / 1.19,
                'net_amount' => abs($adjustmentValue) - (abs($adjustmentValue) * 0.19 / 1.19),
                'currency' => 'RON',
                'description' => $adjustmentData['reason'] ?? 'Inventory adjustment',
                'created_by' => $_SESSION['user_id'] ?? null
            ];

            $transactionId = $this->create($transactionData);
            
            if (!$transactionId) {
                $this->conn->rollback();
                return false;
            }

            // Create transaction item
            $this->addTransactionItem($transactionId, [
                'product_id' => $inventory['product_id'],
                'sku' => $inventory['sku'],
                'product_name' => $inventory['product_name'],
                'quantity' => abs($adjustmentData['quantity_change']),
                'unit_price' => $inventory['price'],
                'tax_percent' => 19.00,
                'warehouse_code' => $inventory['location_code'],
                'batch_number' => $inventory['batch_number'],
                'lot_number' => $inventory['lot_number']
            ]);

            // Queue for SmartBill synchronization
            $this->queueForSync($transactionId, 'normal');

            $this->conn->commit();
            
            // Log audit trail
            $this->logAudit($transactionId, 'created', null, self::STATUS_PENDING, [
                'source' => 'inventory_adjustment',
                'inventory_id' => $inventoryId,
                'quantity_change' => $adjustmentData['quantity_change']
            ]);

            return $transactionId;

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Error creating transaction from inventory adjustment: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create manual transaction
     * @param array $transactionData Transaction data
     * @param array $items Transaction items
     * @return int|false Transaction ID on success, false on failure
     */
    public function createManualTransaction(array $transactionData, array $items = []): int|false {
        try {
            $this->conn->beginTransaction();

            // Set defaults for manual transaction
            $transactionData['reference_type'] = self::REF_MANUAL;
            $transactionData['reference_id'] = 0;
            $transactionData['status'] = self::STATUS_PENDING;
            $transactionData['created_by'] = $_SESSION['user_id'] ?? null;

            $transactionId = $this->create($transactionData);
            
            if (!$transactionId) {
                $this->conn->rollback();
                return false;
            }

            // Add transaction items
            foreach ($items as $item) {
                $this->addTransactionItem($transactionId, $item);
            }

            // Queue for SmartBill synchronization
            $priority = $transactionData['transaction_type'] === self::TYPE_SALES ? 'high' : 'normal';
            $this->queueForSync($transactionId, $priority);

            $this->conn->commit();
            
            // Log audit trail
            $this->logAudit($transactionId, 'created', null, self::STATUS_PENDING, [
                'source' => 'manual_entry',
                'item_count' => count($items)
            ]);

            return $transactionId;

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Error creating manual transaction: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create basic transaction record
     * @param array $data Transaction data
     * @return int|false Transaction ID on success, false on failure
     */
    private function create(array $data): int|false {
        $fields = [
            'transaction_type', 'reference_type', 'reference_id', 'status',
            'amount', 'tax_amount', 'net_amount', 'currency', 'description',
            'customer_name', 'supplier_name', 'invoice_date', 'due_date',
            'series', 'created_by'
        ];

        $validData = [];
        $placeholders = [];
        
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $validData[$field] = $data[$field];
                $placeholders[] = ":{$field}";
            }
        }

        if (empty($validData)) {
            return false;
        }

        $query = "INSERT INTO {$this->transactionsTable} (" . implode(', ', array_keys($validData)) . ") 
                  VALUES (" . implode(', ', $placeholders) . ")";

        try {
            $stmt = $this->conn->prepare($query);
            
            foreach ($validData as $field => $value) {
                $stmt->bindValue(":{$field}", $value);
            }
            
            $stmt->execute();
            return (int)$this->conn->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error creating transaction: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add item to transaction
     * @param int $transactionId Transaction ID
     * @param array $itemData Item data
     * @return bool Success status
     */
    private function addTransactionItem(int $transactionId, array $itemData): bool {
        // Calculate amounts
        $netAmount = $itemData['quantity'] * $itemData['unit_price'];
        $discountAmount = $netAmount * ($itemData['discount_percent'] ?? 0) / 100;
        $netAfterDiscount = $netAmount - $discountAmount;
        $taxAmount = $netAfterDiscount * ($itemData['tax_percent'] ?? 0) / 100;
        $totalAmount = $netAfterDiscount + $taxAmount;

        $query = "INSERT INTO {$this->transactionItemsTable} 
                  (transaction_id, product_id, sku, product_name, quantity, unit_price, 
                   discount_percent, discount_amount, tax_percent, tax_amount, 
                   net_amount, total_amount, unit_of_measure, warehouse_code, 
                   batch_number, lot_number, expiry_date)
                  VALUES 
                  (:transaction_id, :product_id, :sku, :product_name, :quantity, :unit_price,
                   :discount_percent, :discount_amount, :tax_percent, :tax_amount,
                   :net_amount, :total_amount, :unit_of_measure, :warehouse_code,
                   :batch_number, :lot_number, :expiry_date)";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':transaction_id', $transactionId, PDO::PARAM_INT);
            $stmt->bindParam(':product_id', $itemData['product_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindParam(':sku', $itemData['sku'] ?? null, PDO::PARAM_STR);
            $stmt->bindParam(':product_name', $itemData['product_name'] ?? null, PDO::PARAM_STR);
            $stmt->bindParam(':quantity', $itemData['quantity'], PDO::PARAM_STR);
            $stmt->bindParam(':unit_price', $itemData['unit_price'], PDO::PARAM_STR);
            $stmt->bindParam(':discount_percent', $itemData['discount_percent'] ?? 0, PDO::PARAM_STR);
            $stmt->bindParam(':discount_amount', $discountAmount, PDO::PARAM_STR);
            $stmt->bindParam(':tax_percent', $itemData['tax_percent'] ?? 19.00, PDO::PARAM_STR);
            $stmt->bindParam(':tax_amount', $taxAmount, PDO::PARAM_STR);
            $stmt->bindParam(':net_amount', $netAfterDiscount, PDO::PARAM_STR);
            $stmt->bindParam(':total_amount', $totalAmount, PDO::PARAM_STR);
            $stmt->bindParam(':unit_of_measure', $itemData['unit_of_measure'] ?? 'buc', PDO::PARAM_STR);
            $stmt->bindParam(':warehouse_code', $itemData['warehouse_code'] ?? null, PDO::PARAM_STR);
            $stmt->bindParam(':batch_number', $itemData['batch_number'] ?? null, PDO::PARAM_STR);
            $stmt->bindParam(':lot_number', $itemData['lot_number'] ?? null, PDO::PARAM_STR);
            $stmt->bindParam(':expiry_date', $itemData['expiry_date'] ?? null, PDO::PARAM_STR);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error adding transaction item: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Queue transaction for SmartBill synchronization
     * @param int $transactionId Transaction ID
     * @param string $priority Priority level
     * @return bool Success status
     */
    private function queueForSync(int $transactionId, string $priority = 'normal'): bool {
        $query = "INSERT INTO {$this->transactionQueueTable} 
                  (transaction_id, priority, scheduled_at) 
                  VALUES (:transaction_id, :priority, NOW())";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':transaction_id', $transactionId, PDO::PARAM_INT);
            $stmt->bindParam(':priority', $priority, PDO::PARAM_STR);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error queuing transaction for sync: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process transaction synchronization with SmartBill
     * @param int $transactionId Transaction ID
     * @return bool Success status
     */
    public function processSync(int $transactionId): bool {
        try {
            // Update status to processing
            $this->updateStatus($transactionId, self::STATUS_PROCESSING);

            $transaction = $this->findById($transactionId);
            if (!$transaction) {
                throw new Exception("Transaction not found: {$transactionId}");
            }

            $result = false;

            switch ($transaction['transaction_type']) {
                case self::TYPE_SALES:
                    $result = $this->processSalesTransaction($transaction);
                    break;
                    
                case self::TYPE_PURCHASE:
                    $result = $this->processPurchaseTransaction($transaction);
                    break;
                    
                case self::TYPE_ADJUSTMENT:
                    $result = $this->processAdjustmentTransaction($transaction);
                    break;
                    
                case self::TYPE_TRANSFER:
                    $result = $this->processTransferTransaction($transaction);
                    break;
                    
                case self::TYPE_RETURN:
                    $result = $this->processReturnTransaction($transaction);
                    break;
            }

            if ($result) {
                $this->updateStatus($transactionId, self::STATUS_COMPLETED);
                $this->updateSyncDate($transactionId);
                
                // Remove from queue
                $this->removeFromQueue($transactionId);
                
                // Log success
                $this->logAudit($transactionId, 'synced', self::STATUS_PROCESSING, self::STATUS_COMPLETED);
            } else {
                $this->handleSyncFailure($transactionId, "Synchronization failed");
            }

            return $result;

        } catch (Exception $e) {
            $this->handleSyncFailure($transactionId, $e->getMessage());
            error_log("Transaction sync error for ID {$transactionId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process sales transaction with SmartBill
     * @param array $transaction Transaction data
     * @return bool Success status
     */
    private function processSalesTransaction(array $transaction): bool {
        // Prepare invoice data for SmartBill
        $invoiceData = [
            'customer_name' => $transaction['customer_name'],
            'customer_email' => '', // Add from order if available
            'invoice_date' => $transaction['invoice_date'] ?? date('Y-m-d'),
            'series' => $transaction['series'] ?? $this->smartBillService->getConfig('default_series'),
            'currency' => $transaction['currency'],
            'notes' => $transaction['description'],
            'items' => []
        ];

        // Add items
        foreach ($transaction['items'] as $item) {
            $invoiceData['items'][] = [
                'product_name' => $item['product_name'],
                'sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'tax_percent' => $item['tax_percent'],
                'discount_percent' => $item['discount_percent'],
                'unit_of_measure' => $item['unit_of_measure'],
                'warehouse_code' => $item['warehouse_code']
            ];
        }

        // Create invoice in SmartBill
        $result = $this->smartBillService->createInvoice($invoiceData);

        if ($result['success']) {
            // Update transaction with SmartBill details
            $this->updateSmartBillDetails($transaction['id'], [
                'smartbill_doc_id' => $result['smartbill_id'],
                'smartbill_doc_type' => 'factura',
                'smartbill_doc_number' => $result['document_number'],
                'series' => $result['series']
            ]);
            return true;
        } else {
            throw new Exception($result['message']);
        }
    }

    /**
     * Process purchase transaction with SmartBill
     * @param array $transaction Transaction data
     * @return bool Success status
     */
    private function processPurchaseTransaction(array $transaction): bool {
        // For purchase transactions, we typically update inventory in SmartBill
        $inventoryData = [
            'warehouse_code' => $this->smartBillService->getConfig('warehouse_code'),
            'items' => []
        ];

        foreach ($transaction['items'] as $item) {
            $inventoryData['items'][] = [
                'sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price']
            ];
        }

        $result = $this->smartBillService->updateInventory($inventoryData);

        if ($result['success']) {
            return true;
        } else {
            throw new Exception($result['message']);
        }
    }

    /**
     * Process adjustment transaction with SmartBill
     * @param array $transaction Transaction data
     * @return bool Success status
     */
    private function processAdjustmentTransaction(array $transaction): bool {
        // For adjustments, update inventory quantities in SmartBill
        return $this->processPurchaseTransaction($transaction);
    }

    /**
     * Process transfer transaction with SmartBill
     * @param array $transaction Transaction data
     * @return bool Success status
     */
    private function processTransferTransaction(array $transaction): bool {
        // For transfers, update inventory in both locations
        return $this->processPurchaseTransaction($transaction);
    }

    /**
     * Process return transaction with SmartBill
     * @param array $transaction Transaction data
     * @return bool Success status
     */
    private function processReturnTransaction(array $transaction): bool {
        // For returns, create credit note or negative invoice
        return $this->processSalesTransaction($transaction);
    }

    /**
     * Update transaction status
     * @param int $transactionId Transaction ID
     * @param string $status New status
     * @return bool Success status
     */
    public function updateStatus(int $transactionId, string $status): bool {
        $query = "UPDATE {$this->transactionsTable} 
                  SET status = :status, 
                      processed_by = :processed_by,
                      updated_at = CURRENT_TIMESTAMP 
                  WHERE id = :id";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->bindParam(':processed_by', $_SESSION['user_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindParam(':id', $transactionId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error updating transaction status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update SmartBill document details
     * @param int $transactionId Transaction ID
     * @param array $details SmartBill details
     * @return bool Success status
     */
    private function updateSmartBillDetails(int $transactionId, array $details): bool {
        $fields = [];
        $params = [':id' => $transactionId];

        $allowedFields = ['smartbill_doc_id', 'smartbill_doc_type', 'smartbill_doc_number', 'series'];
        
        foreach ($allowedFields as $field) {
            if (isset($details[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $details[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $query = "UPDATE {$this->transactionsTable} 
                  SET " . implode(', ', $fields) . ", updated_at = CURRENT_TIMESTAMP 
                  WHERE id = :id";

        try {
            $stmt = $this->conn->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error updating SmartBill details: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update sync date
     * @param int $transactionId Transaction ID
     * @return bool Success status
     */
    private function updateSyncDate(int $transactionId): bool {
        $query = "UPDATE {$this->transactionsTable} 
                  SET sync_date = NOW(), updated_at = CURRENT_TIMESTAMP 
                  WHERE id = :id";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $transactionId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error updating sync date: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle synchronization failure
     * @param int $transactionId Transaction ID
     * @param string $errorMessage Error message
     * @return bool Success status
     */
    private function handleSyncFailure(int $transactionId, string $errorMessage): bool {
        try {
            $this->conn->beginTransaction();

            // Get current retry count
            $query = "SELECT retry_count, max_retries FROM {$this->transactionsTable} WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $transactionId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $retryCount = ($result['retry_count'] ?? 0) + 1;
            $maxRetries = $result['max_retries'] ?? 3;

            // Calculate next retry time (exponential backoff)
            $baseDelay = 300; // 5 minutes
            $nextRetryDelay = $baseDelay * pow(2, $retryCount - 1);
            $nextRetry = date('Y-m-d H:i:s', time() + $nextRetryDelay);

            // Update transaction
            $updateQuery = "UPDATE {$this->transactionsTable} 
                           SET status = :status,
                               error_message = :error_message,
                               retry_count = :retry_count,
                               next_retry = :next_retry,
                               updated_at = CURRENT_TIMESTAMP
                           WHERE id = :id";

            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':status', self::STATUS_FAILED, PDO::PARAM_STR);
            $updateStmt->bindParam(':error_message', $errorMessage, PDO::PARAM_STR);
            $updateStmt->bindParam(':retry_count', $retryCount, PDO::PARAM_INT);
            $updateStmt->bindParam(':next_retry', $retryCount < $maxRetries ? $nextRetry : null, PDO::PARAM_STR);
            $updateStmt->bindParam(':id', $transactionId, PDO::PARAM_INT);
            $updateStmt->execute();

            // Update queue status
            $queueQuery = "UPDATE {$this->transactionQueueTable} 
                          SET status = 'failed',
                              attempts = attempts + 1,
                              last_attempt = NOW(),
                              last_error = :error_message,
                              updated_at = CURRENT_TIMESTAMP
                          WHERE transaction_id = :transaction_id AND status IN ('queued', 'processing')";

            $queueStmt = $this->conn->prepare($queueQuery);
            $queueStmt->bindParam(':error_message', $errorMessage, PDO::PARAM_STR);
            $queueStmt->bindParam(':transaction_id', $transactionId, PDO::PARAM_INT);
            $queueStmt->execute();

            $this->conn->commit();

            // Log audit trail
            $this->logAudit($transactionId, 'sync_failed', self::STATUS_PROCESSING, self::STATUS_FAILED, [
                'error' => $errorMessage,
                'retry_count' => $retryCount,
                'next_retry' => $retryCount < $maxRetries ? $nextRetry : null
            ]);

            return true;

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Error handling sync failure: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove transaction from sync queue
     * @param int $transactionId Transaction ID
     * @return bool Success status
     */
    private function removeFromQueue(int $transactionId): bool {
        $query = "UPDATE {$this->transactionQueueTable} 
                  SET status = 'completed', updated_at = CURRENT_TIMESTAMP 
                  WHERE transaction_id = :transaction_id";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':transaction_id', $transactionId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error removing from queue: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log audit trail
     * @param int $transactionId Transaction ID
     * @param string $action Action performed
     * @param string|null $oldStatus Old status
     * @param string|null $newStatus New status
     * @param array $changes Additional changes
     * @return bool Success status
     */
    private function logAudit(int $transactionId, string $action, ?string $oldStatus = null, ?string $newStatus = null, array $changes = []): bool {
        $query = "INSERT INTO {$this->transactionAuditTable} 
                  (transaction_id, user_id, action, old_status, new_status, changes, ip_address, user_agent)
                  VALUES (:transaction_id, :user_id, :action, :old_status, :new_status, :changes, :ip_address, :user_agent)";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':transaction_id', $transactionId, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $_SESSION['user_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindParam(':action', $action, PDO::PARAM_STR);
            $stmt->bindParam(':old_status', $oldStatus, PDO::PARAM_STR);
            $stmt->bindParam(':new_status', $newStatus, PDO::PARAM_STR);
            $stmt->bindParam(':changes', json_encode($changes), PDO::PARAM_STR);
            $stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR'] ?? null, PDO::PARAM_STR);
            $stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? null, PDO::PARAM_STR);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error logging audit: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get transactions ready for retry
     * @return array Array of transactions ready for retry
     */
    public function getTransactionsForRetry(): array {
        $query = "SELECT id, transaction_type, reference_type, reference_id, retry_count, max_retries
                 FROM {$this->transactionsTable}
                 WHERE status = :status 
                   AND retry_count < max_retries 
                   AND (next_retry IS NULL OR next_retry <= NOW())
                 ORDER BY created_at ASC 
                 LIMIT 10";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':status', self::STATUS_FAILED, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting transactions for retry: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get pending transactions from queue
     * @param int $limit Maximum number of transactions to return
     * @return array Array of queued transactions
     */
    public function getPendingTransactions(int $limit = 20): array {
        $query = "SELECT tq.transaction_id, tq.priority, tq.scheduled_at, tq.attempts,
                         t.transaction_type, t.reference_type, t.reference_id, t.amount
                 FROM {$this->transactionQueueTable} tq
                 INNER JOIN {$this->transactionsTable} t ON tq.transaction_id = t.id
                 WHERE tq.status = 'queued' AND tq.scheduled_at <= NOW()
                 ORDER BY 
                   CASE tq.priority 
                     WHEN 'urgent' THEN 1 
                     WHEN 'high' THEN 2 
                     WHEN 'normal' THEN 3 
                     WHEN 'low' THEN 4 
                   END ASC,
                   tq.scheduled_at ASC
                 LIMIT :limit";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting pending transactions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get transaction statistics
     * @param int $days Number of days to include in statistics
     * @return array Transaction statistics
     */
    public function getTransactionStats(int $days = 30): array {
        $query = "SELECT 
                    transaction_type,
                    status,
                    COUNT(*) as count,
                    SUM(amount) as total_amount,
                    AVG(amount) as avg_amount,
                    MIN(created_at) as first_transaction,
                    MAX(created_at) as last_transaction
                  FROM {$this->transactionsTable}
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                  GROUP BY transaction_type, status
                  ORDER BY transaction_type, status";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting transaction stats: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Cancel transaction
     * @param int $transactionId Transaction ID
     * @param string $reason Cancellation reason
     * @return bool Success status
     */
    public function cancelTransaction(int $transactionId, string $reason = ''): bool {
        try {
            $this->conn->beginTransaction();

            $oldStatus = $this->getTransactionStatus($transactionId);

            // Update transaction status
            $query = "UPDATE {$this->transactionsTable} 
                      SET status = :status, 
                          error_message = :reason,
                          updated_at = CURRENT_TIMESTAMP 
                      WHERE id = :id AND status IN ('pending', 'failed')";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':status', self::STATUS_CANCELLED, PDO::PARAM_STR);
            $stmt->bindParam(':reason', $reason, PDO::PARAM_STR);
            $stmt->bindParam(':id', $transactionId, PDO::PARAM_INT);
            $result = $stmt->execute();

            if ($result && $stmt->rowCount() > 0) {
                // Remove from queue
                $this->removeFromQueue($transactionId);

                // Log audit trail
                $this->logAudit($transactionId, 'cancelled', $oldStatus, self::STATUS_CANCELLED, [
                    'reason' => $reason
                ]);

                $this->conn->commit();
                return true;
            } else {
                $this->conn->rollback();
                return false;
            }

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Error cancelling transaction: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get transaction status
     * @param int $transactionId Transaction ID
     * @return string|null Transaction status
     */
    private function getTransactionStatus(int $transactionId): ?string {
        $query = "SELECT status FROM {$this->transactionsTable} WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $transactionId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error getting transaction status: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Retry failed transaction
     * @param int $transactionId Transaction ID
     * @return bool Success status
     */
    public function retryTransaction(int $transactionId): bool {
        $transaction = $this->findById($transactionId);
        
        if (!$transaction || $transaction['status'] !== self::STATUS_FAILED) {
            return false;
        }

        if ($transaction['retry_count'] >= $transaction['max_retries']) {
            return false;
        }

        // Reset error state and queue for processing
        $this->updateStatus($transactionId, self::STATUS_PENDING);
        $this->queueForSync($transactionId, 'high');

        // Log retry attempt
        $this->logAudit($transactionId, 'retry', self::STATUS_FAILED, self::STATUS_PENDING);

        return true;
    }

    /**
     * Get count of transactions by status
     * @return array Status counts
     */
    public function getStatusCounts(): array {
        $query = "SELECT status, COUNT(*) as count 
                 FROM {$this->transactionsTable} 
                 GROUP BY status";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $counts = [];
            foreach ($results as $row) {
                $counts[$row['status']] = (int)$row['count'];
            }
            
            return $counts;
        } catch (PDOException $e) {
            error_log("Error getting status counts: " . $e->getMessage());
            return [];
        }
    }
}