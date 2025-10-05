<?php
/**
 * Enhanced Order Model with AWB Integration
 * File: models/Order.php
 * 
 * Extended Order model with AWB generation capabilities,
 * weight calculation, and product unit management
 */
require_once BASE_PATH . '/utils/Phone.php';
use Utils\Phone;

class Order 
{
    private $conn;
    private $weightCalculator;
    private $table = "orders";
    private $itemsTable = "order_items";
    private $hasCanceledAtColumn = false;
    private $hasCanceledByColumn = false;
    
    public function __construct($conn) {
        $this->conn = $conn;
        
        // Load WeightCalculator for automatic calculations
        require_once BASE_PATH . '/models/WeightCalculator.php';
        $this->weightCalculator = new WeightCalculator($conn);

        $this->detectCancellationColumns();
    }

    private function detectCancellationColumns(): void
    {
        try {
            $columnsQuery = sprintf('SHOW COLUMNS FROM `%s` LIKE ?', $this->table);

            $stmt = $this->conn->prepare($columnsQuery);
            $stmt->execute(['canceled_at']);
            $this->hasCanceledAtColumn = $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            $stmt->closeCursor();

            $stmt->execute(['canceled_by']);
            $this->hasCanceledByColumn = $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            $stmt->closeCursor();
        } catch (PDOException $e) {
            error_log('Unable to detect cancellation columns: ' . $e->getMessage());
            $this->hasCanceledAtColumn = false;
            $this->hasCanceledByColumn = false;
        }
    }

    public function hasCancellationMetadata(): bool
    {
        return $this->hasCanceledAtColumn || $this->hasCanceledByColumn;
    }

     /**
     * Get total count of orders with filters
     * @param string $statusFilter Filter by status
     * @param string $priorityFilter Filter by priority
     * @param string $search Search in order number, customer name
     * @return int Total count
     */
    public function getTotalCount($statusFilter = '', $priorityFilter = '', $search = '', string $viewMode = 'active') {
        $viewMode = strtolower($viewMode);
        if (!in_array($viewMode, ['active', 'canceled', 'all'], true)) {
            $viewMode = 'active';
        }

        $query = "SELECT COUNT(*) as total FROM {$this->table} o WHERE 1=1";
        $params = [];

        if (!empty($statusFilter)) {
            $query .= " AND o.status = :status";
            $params[':status'] = $statusFilter;
        }

        if (!empty($priorityFilter)) {
            $query .= " AND o.priority = :priority";
            $params[':priority'] = $priorityFilter;
        }

        if (!empty($search)) {
            $query .= " AND (o.order_number LIKE :search OR o.customer_name LIKE :search OR o.customer_email LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if ($viewMode === 'active') {
            $query .= " AND (o.status IS NULL OR LOWER(o.status) <> 'canceled')";
        } elseif ($viewMode === 'canceled') {
            $query .= " AND LOWER(o.status) = 'canceled'";
        }

        try {
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['total'] ?? 0);
        } catch (PDOException $e) {
            error_log("Error getting orders total count: " . $e->getMessage());
            return 0;
        }
    }

     /**
     * Get orders by status
     * @param string $status Order status
     * @return array
     */
    public function getOrdersByStatus($status) {
        $query = "SELECT * FROM {$this->table} WHERE LOWER(status) = LOWER(:status) ORDER BY order_date ASC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':status', $status);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting orders by status: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete an order and its items
     * @param int $orderId Order ID
     * @return bool
     */
    public function deleteOrder($orderId) {
        return $this->cancelOrder((int)$orderId, $_SESSION['user_id'] ?? null);
    }

    /**
     * Soft delete (cancel) an order.
     *
     * @param int      $orderId Order identifier
     * @param int|null $userId  User performing the cancellation
     */
    public function cancelOrder(int $orderId, ?int $userId = null): bool
    {
        $orderId = max(0, $orderId);
        if ($orderId <= 0) {
            return false;
        }

        try {
            $this->conn->beginTransaction();

            $existingOrder = $this->getOrderById($orderId);
            if (!$existingOrder) {
                $this->conn->rollBack();
                return false;
            }

            $updateColumns = [
                'status = :status',
                'updated_at = NOW()'
            ];

            if ($this->hasCanceledAtColumn) {
                $updateColumns[] = 'canceled_at = NOW()';
            }

            if ($this->hasCanceledByColumn) {
                $updateColumns[] = 'canceled_by = :canceled_by';
            }

            $query = sprintf(
                "UPDATE %s SET %s WHERE id = :id",
                $this->table,
                implode(', ', $updateColumns)
            );

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $stmt->bindValue(':status', 'canceled');

            if ($this->hasCanceledByColumn) {
                if ($userId !== null) {
                    $stmt->bindValue(':canceled_by', $userId, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue(':canceled_by', null, PDO::PARAM_NULL);
                }
            }

            $result = $stmt->execute();

            if ($result) {
                $actorId = $userId ?? ($_SESSION['user_id'] ?? 0);
                logActivity(
                    $actorId,
                    'cancel',
                    'order',
                    $orderId,
                    'Order canceled',
                    ['status' => $existingOrder['status'] ?? null],
                    ['status' => 'canceled']
                );
            }

            $this->conn->commit();
            return $result;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error canceling order: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Restore a previously canceled order.
     */
    public function restoreOrder(int $orderId, string $newStatus = 'pending', ?int $userId = null): bool
    {
        $orderId = max(0, $orderId);
        if ($orderId <= 0) {
            return false;
        }

        $newStatus = strtolower($newStatus);

        try {
            $this->conn->beginTransaction();

            $existingOrder = $this->getOrderById($orderId);
            if (!$existingOrder) {
                $this->conn->rollBack();
                return false;
            }

            $updateColumns = [
                'status = :status',
                'updated_at = NOW()'
            ];

            if ($this->hasCanceledAtColumn) {
                $updateColumns[] = 'canceled_at = NULL';
            }

            if ($this->hasCanceledByColumn) {
                $updateColumns[] = 'canceled_by = NULL';
            }

            $query = sprintf(
                "UPDATE %s SET %s WHERE id = :id",
                $this->table,
                implode(', ', $updateColumns)
            );

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $stmt->bindValue(':status', $newStatus);

            $result = $stmt->execute();

            if ($result) {
                $actorId = $userId ?? ($_SESSION['user_id'] ?? 0);
                logActivity(
                    $actorId,
                    'restore',
                    'order',
                    $orderId,
                    'Order restored',
                    ['status' => $existingOrder['status'] ?? null],
                    ['status' => $newStatus]
                );
            }

            $this->conn->commit();
            return $result;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error restoring order: " . $e->getMessage());
            return false;
        }
    }
    
/**
     * Get orders with pagination and filtering
     * @param int $pageSize Number of orders per page
     * @param int $offset Starting offset
     * @param string $statusFilter Filter by status
     * @param string $priorityFilter Filter by priority
     * @param string $search Search term
     * @return array
     */
    public function getOrdersPaginated($pageSize, $offset, $statusFilter = '', $priorityFilter = '', $search = '', string $viewMode = 'active') {
        $viewMode = strtolower($viewMode);
        if (!in_array($viewMode, ['active', 'canceled', 'all'], true)) {
            $viewMode = 'active';
        }

        $select = "SELECT o.*,
                         COALESCE((SELECT SUM(oi.quantity * oi.unit_price) FROM {$this->itemsTable} oi WHERE oi.order_id = o.id), 0) as total_value,
                         COALESCE((SELECT COUNT(*) FROM {$this->itemsTable} oi WHERE oi.order_id = o.id), 0) as total_items";

        if ($this->hasCanceledByColumn) {
            $select .= ",
                         canceled_user.username AS canceled_by_username,
                         TRIM(CONCAT_WS(' ', canceled_user.first_name, canceled_user.last_name)) AS canceled_by_full_name,
                         canceled_user.email AS canceled_by_email";
        }

        $query = $select . "
                  FROM {$this->table} o";

        if ($this->hasCanceledByColumn) {
            $query .= "
                  LEFT JOIN users canceled_user ON canceled_user.id = o.canceled_by";
        }

        $query .= "
                  WHERE 1=1";

        $params = [];

        if (!empty($statusFilter)) {
            $query .= " AND o.status = :status";
            $params[':status'] = $statusFilter;
        }

        if (!empty($priorityFilter)) {
            $query .= " AND o.priority = :priority";
            $params[':priority'] = $priorityFilter;
        }

        if (!empty($search)) {
            $query .= " AND (o.order_number LIKE :search OR o.customer_name LIKE :search OR o.customer_email LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if ($viewMode === 'active') {
            $query .= " AND (o.status IS NULL OR LOWER(o.status) <> 'canceled')";
        } elseif ($viewMode === 'canceled') {
            $query .= " AND LOWER(o.status) = 'canceled'";
        }

        $query .= " ORDER BY o.order_date DESC LIMIT :limit OFFSET :offset";

        try {
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting paginated orders: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get orders with AWB generated on a specific date and ready to ship
     *
     * @param string|null $date Date in Y-m-d format. Defaults to today if null.
     * @return array
     */
    public function getOrdersReadyToShipWithAwbByDate(?string $date = null): array {
        $targetDate = $date ?? date('Y-m-d');

        $query = "SELECT
                    o.id,
                    o.order_number,
                    o.customer_name,
                    o.awb_barcode,
                    COALESCE(SUM(oi.quantity), 0) AS total_products
                FROM {$this->table} o
                LEFT JOIN {$this->itemsTable} oi ON oi.order_id = o.id
                WHERE o.awb_barcode IS NOT NULL
                  AND o.awb_barcode != ''
                  AND DATE(o.awb_created_at) = :awb_date
                  AND LOWER(o.status) = :status
                GROUP BY o.id, o.order_number, o.customer_name, o.awb_barcode
                ORDER BY o.order_date ASC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':awb_date', $targetDate);
            $stmt->bindValue(':status', 'ready_to_ship');
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Error fetching ready-to-ship orders with AWB: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Retrieve orders within a date range for PDF exports.
     * Includes customer information, order metadata and aggregated item counts.
     *
     * @param string $startDate Start date in Y-m-d format.
     * @param string $endDate   End date in Y-m-d format.
     * @return array<int, array<string, mixed>>
     */
    public function getOrdersByDateRangeForExport(string $startDate, string $endDate): array {
        $query = "SELECT
                    o.id,
                    o.order_number,
                    o.customer_name,
                    o.order_date,
                    o.status,
                    o.type,
                    o.invoice_reference,
                    COALESCE(SUM(oi.quantity), 0) AS total_items
                FROM {$this->table} o
                LEFT JOIN {$this->itemsTable} oi ON oi.order_id = o.id
                WHERE DATE(o.order_date) BETWEEN :start_date AND :end_date
                  AND (o.status IS NULL OR LOWER(o.status) <> 'canceled')
                GROUP BY o.id, o.order_number, o.customer_name, o.order_date, o.status, o.type, o.invoice_reference
                ORDER BY o.order_date ASC, o.order_number ASC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':start_date', $startDate);
            $stmt->bindValue(':end_date', $endDate);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Error fetching orders for export: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get unique priorities
     * @return array
     */
    public function getPriorities() {
        $query = "SELECT DISTINCT priority FROM {$this->table} WHERE priority IS NOT NULL AND priority != '' ORDER BY priority";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Add common priorities if not present
            $commonPriorities = ['normal', 'high', 'urgent'];
            return array_unique(array_merge($result, $commonPriorities));
        } catch (PDOException $e) {
            error_log("Error getting priorities: " . $e->getMessage());
            return ['normal', 'high', 'urgent'];
        }
    }

    /**
     * Get order by ID with full details - FIXED to not use customers table
     * @param int $orderId
     * @return array|false
     */
    public function getOrderById($orderId) {
    $query = "SELECT o.*, 
                    COALESCE((SELECT SUM(oi.quantity * oi.unit_price) FROM {$this->itemsTable} oi WHERE oi.order_id = o.id), 0) as total_value,
                    COALESCE((SELECT COUNT(*) FROM {$this->itemsTable} oi WHERE oi.order_id = o.id), 0) as total_items
            FROM {$this->table} o 
            WHERE o.id = :id";
    
    try {
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
        $stmt->execute();
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            return false;
        }
        
        // Get order items with error handling
        try {
            if (method_exists($this, 'getOrderItems')) {
                $order['items'] = $this->getOrderItems($orderId);
            } else {
                // Fallback: get items directly with FIXED join condition
                $itemsQuery = "SELECT oi.*, p.name as product_name, p.sku
                            FROM {$this->itemsTable} oi
                            LEFT JOIN products p ON oi.product_id = p.product_id
                            WHERE oi.order_id = :order_id
                            ORDER BY oi.id";
                
                $itemsStmt = $this->conn->prepare($itemsQuery);
                $itemsStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
                $itemsStmt->execute();
                $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Warning: Could not get order items for order {$orderId}: " . $e->getMessage());
            $order['items'] = [];
        }
        
        // Ensure shipping data is calculated with error handling
        try {
            if (method_exists($this, 'ensureShippingDataCalculated')) {
                $this->ensureShippingDataCalculated($order);
            }
        } catch (Exception $e) {
            error_log("Warning: Could not calculate shipping data for order {$orderId}: " . $e->getMessage());
        }
        
        return $order;
        
    } catch (PDOException $e) {
        error_log("Error getting order by ID: " . $e->getMessage());
        return false;
    }
}

      /**
     * Count active orders (pending, processing, etc.)
     * @return int Number of active orders
     */
    public function countActiveOrders(): int {
        try {
            $query = "SELECT COUNT(*) FROM orders 
                    WHERE status IN ('pending', 'processing', 'confirmed', 'shipped')";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
            
        } catch (PDOException $e) {
            error_log("Error counting active orders: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Count pending orders
     * @return int Number of pending orders
     */
    public function countPendingOrders(): int {
        try {
            $query = "SELECT COUNT(*) FROM orders WHERE LOWER(status) = 'pending'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (int)$stmt->fetchColumn();

        } catch (PDOException $e) {
            error_log("Error counting pending orders: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Count processing orders (processing or assigned)
     * @return int Number of processing orders
     */
    public function countProcessingOrders(): int {
        try {
            $query = "SELECT COUNT(*) FROM orders WHERE LOWER(status) IN ('processing','assigned')";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (int)$stmt->fetchColumn();

        } catch (PDOException $e) {
            error_log("Error counting processing orders: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Count orders completed today
     * @return int Number of orders completed today
     */
    public function countCompletedToday(): int {
        try {
            $query = "SELECT COUNT(*) FROM orders
                    WHERE LOWER(status) IN ('completed','ready','ready_to_ship') AND DATE(updated_at) = CURDATE()";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (int)$stmt->fetchColumn();

        } catch (PDOException $e) {
            error_log("Error counting completed orders today: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get recent orders
     * @param int $limit Number of recent orders to fetch
     * @return array Recent orders with basic info
     */
    public function getRecentOrders(int $limit = 10): array {
        try {
            $query = "SELECT id, order_number, customer_name, status, total_value, order_date
                    FROM {$this->table}
                    ORDER BY order_date DESC
                    LIMIT :limit";

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log("Error getting recent orders: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Count orders created today
     * @return int Number of orders created today
     */
    public function countOrdersCreatedToday(): int {
        try {
            $query = "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
            
        } catch (PDOException $e) {
            error_log("Error counting orders created today: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Count shipped orders today  
     * @return int Number of orders shipped today
     */
    public function countShippedToday(): int {
        try {
            $query = "SELECT COUNT(*) FROM orders 
                    WHERE status = 'shipped' AND DATE(updated_at) = CURDATE()";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
            
        } catch (PDOException $e) {
            error_log("Error counting shipped orders today: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get order items with product and unit details
     */
    public function getOrderItems($orderId) {
        $query = "
            SELECT
                oi.*,
                p.name as product_name,
                p.sku as product_code,
                p.category as product_category,
                pu.weight_per_unit,
                COALESCE(pu.volume_per_unit, 0) as volume_per_unit,
                ut.unit_name,
                ut.packaging_type,
                COALESCE(pu.fragile, 0) as fragile,
                COALESCE(pu.hazardous, 0) as hazardous,
                COALESCE(inv.available_inventory, 0) AS available_inventory,
                GREATEST(oi.quantity - COALESCE(oi.picked_quantity, 0), 0) AS remaining_quantity
            FROM order_items oi
            JOIN products p ON oi.product_id = p.product_id
            LEFT JOIN product_units pu ON p.product_id = pu.product_id
            LEFT JOIN unit_types ut ON pu.unit_type_id = ut.id AND ut.unit_code = oi.unit_measure
            LEFT JOIN (
                SELECT product_id, SUM(quantity) AS available_inventory
                FROM inventory
                GROUP BY product_id
            ) inv ON inv.product_id = oi.product_id
            WHERE oi.order_id = ?
            ORDER BY oi.id ASC
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get products from the provided orders that have insufficient inventory
     * compared to the outstanding quantities that still need to be picked.
     *
     * @param array<int> $orderIds
     * @return array<int, array<int, array<string, int>>> Map of order ID => list of issues
     */
    public function getOrdersStockIssues(array $orderIds): array
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds), static function ($value) {
            return $value > 0;
        }));

        if (empty($orderIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

        $query = "
            SELECT
                req.order_id,
                req.product_id,
                req.required_quantity,
                COALESCE(inv.available_quantity, 0) AS available_quantity
            FROM (
                SELECT
                    oi.order_id,
                    oi.product_id,
                    SUM(GREATEST(oi.quantity - COALESCE(oi.picked_quantity, 0), 0)) AS required_quantity
                FROM order_items oi
                WHERE oi.order_id IN ($placeholders)
                GROUP BY oi.order_id, oi.product_id
            ) AS req
            LEFT JOIN (
                SELECT product_id, SUM(quantity) AS available_quantity
                FROM inventory
                GROUP BY product_id
            ) AS inv ON inv.product_id = req.product_id
            WHERE req.required_quantity > COALESCE(inv.available_quantity, 0)
        ";

        try {
            $stmt = $this->conn->prepare($query);
            foreach ($orderIds as $index => $orderId) {
                $stmt->bindValue($index + 1, $orderId, PDO::PARAM_INT);
            }
            $stmt->execute();

            $issues = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $orderId = (int)($row['order_id'] ?? 0);
                $productId = (int)($row['product_id'] ?? 0);

                if ($orderId <= 0 || $productId <= 0) {
                    continue;
                }

                $issues[$orderId][] = [
                    'product_id' => $productId,
                    'required_quantity' => (int)($row['required_quantity'] ?? 0),
                    'available_quantity' => (int)($row['available_quantity'] ?? 0)
                ];
            }

            return $issues;
        } catch (PDOException $e) {
            error_log('Error fetching order stock issues: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Ensure shipping data is calculated and up-to-date
     */
    private function ensureShippingDataCalculated(&$order) {
        // Check if we need to recalculate
        $needsRecalculation = false;
        
        // Auto-calculate if enabled and data is missing or zero
        $autoCalcWeight = $this->getConfigValue('auto_calculate_weight', true);
        $autoCalcParcels = $this->getConfigValue('auto_calculate_parcels', true);
        
        if ($autoCalcWeight && (empty($order['total_weight']) || $order['total_weight'] <= 0)) {
            $needsRecalculation = true;
        }
        
        if ($autoCalcParcels && (empty($order['parcels_count']) || $order['parcels_count'] <= 0)) {
            $needsRecalculation = true;
        }
        
        if ($needsRecalculation) {
            $shippingData = $this->weightCalculator->calculateOrderShipping($order['id']);
            
            // Update order with calculated data
            $this->updateShippingData($order['id'], $shippingData);
            
            // Update the order array with new data
            $order['total_weight'] = $shippingData['total_weight'];
            $order['parcels_count'] = $shippingData['parcels_count'];
            $order['envelopes_count'] = $shippingData['envelopes_count'];
            $order['package_content'] = $shippingData['package_content'];
            $order['calculated_shipping_notes'] = $shippingData['shipping_notes'];
        }
    }
    
    /**
     * Update shipping data in database
     */
    private function updateShippingData($orderId, $shippingData) {
        $query = "
            UPDATE orders SET 
                total_weight = ?,
                parcels_count = ?,
                envelopes_count = ?,
                package_content = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            $shippingData['total_weight'],
            $shippingData['parcels_count'],
            $shippingData['envelopes_count'],
            $shippingData['package_content'],
            $orderId
        ]);
    }
    
    /**
     * Update AWB information after successful generation
     */
    public function updateAWBInfo($orderId, $awbData) {
        $query = "
            UPDATE orders SET 
                awb_barcode = ?,
                awb_created_at = ?,
                cargus_order_id = ?,
                updated_by = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            $awbData['awb_barcode'],
            $awbData['awb_created_at'],
            $awbData['cargus_order_id'] ?? '',
            $awbData['updated_by'] ?? null,
            $orderId
        ]);
    }
    
    /**
     * Get orders with AWB status filtering
     */
    public function getOrdersWithFilters($filters = []) {
        $whereConditions = ['1 = 1'];
        $params = [];
        
        // Status filter
        if (!empty($filters['status'])) {
            $whereConditions[] = 'o.status = ?';
            $params[] = $filters['status'];
        }
        
        // AWB status filter
        if (isset($filters['awb_status'])) {
            if ($filters['awb_status'] === 'generated') {
                $whereConditions[] = 'o.awb_barcode IS NOT NULL AND o.awb_barcode != ""';
            } elseif ($filters['awb_status'] === 'pending') {
                $whereConditions[] = '(o.awb_barcode IS NULL OR o.awb_barcode = "")';
            }
        }
        
        // Date range filter
        if (!empty($filters['date_from'])) {
            $whereConditions[] = 'DATE(o.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $whereConditions[] = 'DATE(o.created_at) <= ?';
            $params[] = $filters['date_to'];
        }
        
        // Customer name filter
        if (!empty($filters['customer_name'])) {
            $whereConditions[] = 'c.name LIKE ?';
            $params[] = '%' . $filters['customer_name'] . '%';
        }
        
        // Order number filter
        if (!empty($filters['order_number'])) {
            $whereConditions[] = 'o.order_number LIKE ?';
            $params[] = '%' . $filters['order_number'] . '%';
        }
        
        // AWB barcode filter
        if (!empty($filters['awb_barcode'])) {
            $whereConditions[] = 'o.awb_barcode LIKE ?';
            $params[] = '%' . $filters['awb_barcode'] . '%';
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $query = "
            SELECT 
                o.id,
                o.order_number,
                o.status,
                o.total_value,
                o.total_weight,
                o.parcels_count,
                o.envelopes_count,
                o.awb_barcode,
                o.awb_created_at,
                o.cargus_order_id,
                o.created_at,
                o.updated_at,
                c.name as customer_name,
                c.phone as customer_phone,
                CASE
                    WHEN o.awb_barcode IS NOT NULL AND o.awb_barcode != '' THEN 'Generat'
                    ELSE 'În așteptare'
                END as awb_status,
                CASE
                    WHEN o.total_weight IS NULL OR o.total_weight <= 0 THEN 'Nu'
                    ELSE 'Da'
                END as weight_calculated
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            WHERE {$whereClause}
            ORDER BY
                CASE WHEN (o.awb_barcode IS NULL OR o.awb_barcode = '') THEN 0 ELSE 1 END,
                o.created_at DESC
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update order status
     * @param int $orderId Order ID
     * @param string $status New status
     * @return bool
     */
    public function updateStatus($orderId, $status) {
        $status = strtolower($status);

        $setClauses = ['status = :status', 'updated_at = NOW()'];
        $shouldBindCanceledBy = false;

        if ($status === 'canceled') {
            if ($this->hasCanceledAtColumn) {
                $setClauses[] = 'canceled_at = NOW()';
            }

            if ($this->hasCanceledByColumn) {
                $setClauses[] = 'canceled_by = :canceled_by';
                $shouldBindCanceledBy = true;
            }
        } else {
            if ($this->hasCanceledAtColumn) {
                $setClauses[] = 'canceled_at = NULL';
            }

            if ($this->hasCanceledByColumn) {
                $setClauses[] = 'canceled_by = NULL';
            }
        }

        $query = "UPDATE {$this->table} SET " . implode(', ', $setClauses) . " WHERE id = :id";

        try {
            $old = $this->getOrderById($orderId);
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $stmt->bindValue(':status', $status);

            if ($shouldBindCanceledBy) {
                $canceledBy = $_SESSION['user_id'] ?? null;
                if ($canceledBy !== null) {
                    $stmt->bindValue(':canceled_by', $canceledBy, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue(':canceled_by', null, PDO::PARAM_NULL);
                }
            }

            $result = $stmt->execute();

            if ($result) {
                $userId = $_SESSION['user_id'] ?? 0;
                $oldStatus = $old['status'] ?? null;
                logActivity(
                    $userId,
                    'update',
                    'order',
                    $orderId,
                    'Order status updated',
                    ['status' => $oldStatus],
                    ['status' => $status]
                );
            }

            return $result;
        } catch (PDOException $e) {
            error_log("Error updating order status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get orders ready for AWB generation
     */
    public function getOrdersReadyForAWB($limit = 50) {
        $query = "
            SELECT 
                o.id,
                o.order_number,
                o.status,
                o.total_weight,
                o.parcels_count,
                o.recipient_name,
                o.recipient_phone,
                o.recipient_county_id,
                o.recipient_locality_id,
                c.name as customer_name,
                COUNT(oi.id) as item_count
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE (o.awb_barcode IS NULL OR o.awb_barcode = '')
                AND o.recipient_county_id IS NOT NULL
                AND o.recipient_locality_id IS NOT NULL
                AND o.recipient_phone IS NOT NULL
            GROUP BY o.id
            ORDER BY o.created_at ASC
            LIMIT ?
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Validate order for AWB generation
     */
    public function validateOrderForAWB($orderId) {
        $order = $this->getOrderById($orderId);
        
        if (!$order) {
            return [
                'valid' => false,
                'errors' => ['Order not found']
            ];
        }
        
        $errors = [];

        // Status validation
        if (($order['status'] ?? '') !== 'picked') {
            $errors[] = 'Order status must be picked';
        }

        // AWB already exists check
        if (!empty($order['awb_barcode'])) {
            $errors[] = 'AWB already generated: ' . $order['awb_barcode'];
        }
        
        // Required recipient data
        $requiredFields = [
            'recipient_county_id' => 'Recipient county',
            'recipient_locality_id' => 'Recipient locality', 
            'recipient_phone' => 'Recipient phone'
        ];
        
        foreach ($requiredFields as $field => $label) {
            if (empty($order[$field])) {
                $errors[] = "Missing {$label}";
            }
        }
        
        // Phone validation
        if (!empty($order['recipient_phone'])) {
            $normalized = Phone::toLocal($order['recipient_phone']);
            if (!preg_match('/^[0-9\s\-\(\)]{10,15}$/', $normalized)) {
                $errors[] = 'Invalid phone number format';
            }
        }
        
        // Weight validation
        if (empty($order['total_weight']) || $order['total_weight'] <= 0) {
            // Try to calculate weight automatically
            $shippingData = $this->weightCalculator->calculateOrderShipping($orderId);
            if ($shippingData['total_weight'] > 0) {
                $this->updateShippingData($orderId, $shippingData);
            } else {
                $errors[] = 'Order weight cannot be determined';
            }
        }
        
        // Items validation
        if (empty($order['items'])) {
            $errors[] = 'Order has no items';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'order' => $order
        ];
    }
    
    /**
     * Create new order with automatic weight calculation
     */
    public function createOrder($orderData) {
        try {
            $this->conn->beginTransaction();
            
            // Insert main order
            $orderQuery = "
                INSERT INTO orders (
                    order_number, customer_id, status, total_value, declared_value,
                    recipient_name, recipient_county_id, recipient_locality_id,
                    recipient_street_id, recipient_building_number, shipping_address,
                    recipient_contact_person, recipient_phone, recipient_email,
                    observations, package_content, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $this->conn->prepare($orderQuery);
            $stmt->execute([
                $orderData['order_number'],
                $orderData['customer_id'],
                $orderData['status'] ?? 'pending',
                $orderData['total_value'] ?? 0,
                $orderData['declared_value'] ?? $orderData['total_value'] ?? 0,
                $orderData['recipient_name'],
                $orderData['recipient_county_id'],
                $orderData['recipient_locality_id'],
                $orderData['recipient_street_id'] ?? null,
                $orderData['recipient_building_number'] ?? '',
                $orderData['shipping_address'],
                $orderData['recipient_contact_person'] ?? $orderData['recipient_name'],
                $orderData['recipient_phone'],
                $orderData['recipient_email'] ?? '',
                $orderData['observations'] ?? '',
                $orderData['package_content'] ?? '',
            ]);
            
            $orderId = $this->conn->lastInsertId();
            
            // Insert order items
            if (!empty($orderData['items'])) {
                $itemQuery = "
                    INSERT INTO order_items (
                        order_id, product_id, quantity, unit_measure,
                        unit_price, total_price, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ";
                
                $itemStmt = $this->conn->prepare($itemQuery);
                
                foreach ($orderData['items'] as $item) {
                    $itemStmt->execute([
                        $orderId,
                        $item['product_id'],
                        $item['quantity'],
                        $item['unit_measure'],
                        $item['unit_price'] ?? 0,
                        $item['total_price'] ?? 0,
                        $item['notes'] ?? ''
                    ]);
                }
            }
            
            // Calculate shipping data
            $shippingData = $this->weightCalculator->calculateOrderShipping($orderId);
            $this->updateShippingData($orderId, $shippingData);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'order_id' => $orderId,
                'shipping_data' => $shippingData
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Recalculate shipping data for order
     */
    public function recalculateShipping($orderId) {
        return $this->weightCalculator->recalculateAndUpdateOrder($orderId);
    }
    
    /**
     * Bulk recalculate shipping for multiple orders
     */
    public function bulkRecalculateShipping($orderIds = null) {
        if ($orderIds === null) {
            // Get all orders that need recalculation
            $query = "
                SELECT id FROM orders 
                WHERE (total_weight IS NULL OR total_weight <= 0 OR parcels_count IS NULL OR parcels_count <= 0)
                AND status IN ('pending', 'confirmed', 'picked')
                LIMIT 100
            ";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        $results = [
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($orderIds as $orderId) {
            $results['processed']++;
            
            try {
                $result = $this->recalculateShipping($orderId);
                if ($result['success']) {
                    $results['successful']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Order {$orderId}: Calculation failed";
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Order {$orderId}: " . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    public function updateOrderField($orderId, $fields) {
        $setParts = [];
        $values = [];
        
        foreach ($fields as $field => $value) {
            $setParts[] = "$field = ?";
            $values[] = $value;
        }
        
        $values[] = $orderId;
        
        $sql = "UPDATE orders SET " . implode(', ', $setParts) . " WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        
        return $stmt->execute($values);
    }

    /**
     * Increment AWB generation attempts and update last attempt timestamp
     *
     * @param int $orderId
     * @return int Updated attempt count
     */
    public function incrementAwbGenerationAttempts(int $orderId): int {
        $sql = "UPDATE orders SET awb_generation_attempts = awb_generation_attempts + 1, awb_generation_last_attempt_at = NOW() WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$orderId]);

        $stmt = $this->conn->prepare("SELECT awb_generation_attempts FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get configuration value
     */
    private function getConfigValue($key, $default = null) {
        static $config = null;
        
        if ($config === null) {
            $stmt = $this->conn->prepare("
                SELECT setting_key, setting_value, setting_type 
                FROM cargus_config 
                WHERE active = 1
            ");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $config = [];
            foreach ($settings as $setting) {
                $value = $setting['setting_value'];
                
                switch ($setting['setting_type']) {
                    case 'boolean':
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                    case 'integer':
                        $value = (int)$value;
                        break;
                    case 'decimal':
                        $value = (float)$value;
                        break;
                }
                
                $config[$setting['setting_key']] = $value;
            }
        }
        
        return $config[$key] ?? $default;
    }
    
    /**
     * Get status options for orders
     */
    public function getStatuses() {
        return [
            'pending' => 'În așteptare',
            'processing' => 'În procesare',
            'confirmed' => 'Confirmat',
            'picked' => 'Ridicat',
            'ready' => 'Pregătit',
            'ready_to_ship' => 'Pregătit pentru expediere',
            'shipped' => 'Expediat',
            'completed' => 'Finalizat',
            'delivered' => 'Livrat',
            'canceled' => 'Anulat',
            'returned' => 'Returnat'
        ];
    }

    /**
     * Fetch orders updated after the provided timestamp.
     *
     * @param string|null $since ISO/DB timestamp threshold.
     * @param string $statusFilter
     * @param string $priorityFilter
     * @param string $search
     * @param int $limit
     * @return array
     */
    public function getOrdersUpdatedSince(?string $since, string $statusFilter = '', string $priorityFilter = '', string $search = '', int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));

        $select = "SELECT o.*,
                         COALESCE((SELECT SUM(oi.quantity * oi.unit_price) FROM {$this->itemsTable} oi WHERE oi.order_id = o.id), 0) AS total_value,
                         COALESCE((SELECT COUNT(*) FROM {$this->itemsTable} oi WHERE oi.order_id = o.id), 0) AS total_items";

        if ($this->hasCanceledByColumn) {
            $select .= ",
                         canceled_user.username AS canceled_by_username,
                         TRIM(CONCAT_WS(' ', canceled_user.first_name, canceled_user.last_name)) AS canceled_by_full_name,
                         canceled_user.email AS canceled_by_email";
        }

        $query = $select . "
                  FROM {$this->table} o";

        if ($this->hasCanceledByColumn) {
            $query .= "
                  LEFT JOIN users canceled_user ON canceled_user.id = o.canceled_by";
        }

        $query .= "
                  WHERE 1=1";

        $params = [];

        if (!empty($statusFilter)) {
            $query .= " AND (LOWER(o.status) = LOWER(:status) OR LOWER(o.status) = LOWER(:alt_status))";
            $params[':status'] = $statusFilter;
            // Allow compatibility with ready_to_ship vs ready, etc.
            $params[':alt_status'] = $statusFilter === 'ready' ? 'ready_to_ship' : $statusFilter;
        }

        if (!empty($priorityFilter)) {
            $query .= " AND o.priority = :priority";
            $params[':priority'] = $priorityFilter;
        }

        if (!empty($search)) {
            $query .= " AND (o.order_number LIKE :search OR o.customer_name LIKE :search OR o.customer_email LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if (!empty($since)) {
            $query .= " AND COALESCE(o.updated_at, o.created_at, o.order_date) > :since";
            $params[':since'] = $since;
        }

        $query .= " ORDER BY COALESCE(o.updated_at, o.created_at, o.order_date) ASC LIMIT :limit";

        try {
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Error fetching order updates: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get dashboard statistics
     */
    public function getDashboardStats() {
        $stats = [];
        
        // Orders ready for AWB
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count
            FROM orders 
            WHERE (awb_barcode IS NULL OR awb_barcode = '')
            AND recipient_county_id IS NOT NULL
            AND recipient_locality_id IS NOT NULL
            AND recipient_phone IS NOT NULL
        ");
        $stmt->execute();
        $stats['orders_ready_for_awb'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Orders with AWB generated today
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count
            FROM orders 
            WHERE DATE(awb_created_at) = CURDATE()
        ");
        $stmt->execute();
        $stats['awb_generated_today'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Orders missing weight calculation
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count
            FROM orders 
            WHERE (total_weight IS NULL OR total_weight <= 0)
            AND status IN ('pending', 'confirmed', 'picked')
        ");
        $stmt->execute();
        $stats['orders_missing_weight'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Products without unit configuration
        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT p.product_id) as count
            FROM products p
            LEFT JOIN product_units pu ON p.product_id = pu.product_id AND pu.active = 1
            WHERE p.active = 1 AND pu.id IS NULL
        ");
        $stmt->execute();
        $stats['products_without_units'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        return $stats;
    }

    /**
     * Average processing time for picking orders by operator (in minutes)
     */
    public function getAverageProcessingTimeByOperator($days = 30) {
        $sql = "
            SELECT u.username AS operator,
                   AVG(TIMESTAMPDIFF(MINUTE, o.assigned_at, o.updated_at)) AS avg_minutes
            FROM orders o
            JOIN users u ON o.assigned_to = u.id
            WHERE o.status = 'completed'
              AND o.type = 'outbound'
              AND o.assigned_at IS NOT NULL
              AND o.updated_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY u.username
            ORDER BY avg_minutes
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Average processing time for picking orders by product category (in minutes)
     */
    public function getAverageProcessingTimeByCategory($days = 30) {
        $sql = "
            SELECT p.category AS category,
                   AVG(TIMESTAMPDIFF(MINUTE, o.assigned_at, o.updated_at)) AS avg_minutes
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.product_id
            WHERE o.status = 'completed'
              AND o.type = 'outbound'
              AND o.assigned_at IS NOT NULL
              AND o.updated_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY p.category
            ORDER BY avg_minutes
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Average processing time for picking orders by product (in minutes)
     */
    public function getAverageProcessingTimeByProduct($days = 30) {
        $sql = "
            SELECT p.name AS product,
                   AVG(TIMESTAMPDIFF(MINUTE, o.assigned_at, o.updated_at)) AS avg_minutes
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.product_id
            WHERE o.status = 'completed'
              AND o.type = 'outbound'
              AND o.assigned_at IS NOT NULL
              AND o.updated_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY p.product_id
            ORDER BY avg_minutes
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}