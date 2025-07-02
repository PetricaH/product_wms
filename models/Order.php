<?php
/**
 * Enhanced Order Model with full CRUD operations
 * Manages orders, order items, and order workflow
 */

class Order {
    private $conn;
    private $ordersTable = "orders";
    private $orderItemsTable = "order_items";
    private $productsTable = "products";
    
    // Order statuses
    const STATUS_PENDING = 'Pending';
    const STATUS_PROCESSING = 'Processing';
    const STATUS_AWAITING_SHIPMENT = 'Awaiting Shipment';
    const STATUS_SHIPPED = 'Shipped';
    const STATUS_COMPLETED = 'Completed';
    const STATUS_CANCELLED = 'Cancelled';
    const STATUS_ON_HOLD = 'On Hold';

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Get all orders with summary information
     * @param array $filters Optional filters (status, date_from, date_to)
     * @return array Array of order records
     */
    public function getAllOrders(array $filters = []): array {
        $query = "SELECT o.*, 
                         COUNT(oi.id) as item_count,
                         COALESCE(SUM(oi.quantity_ordered), 0) as total_items,
                         COALESCE(SUM(oi.picked_quantity), 0) as picked_items
                  FROM {$this->ordersTable} o
                  LEFT JOIN {$this->orderItemsTable} oi ON o.id = oi.order_id
                  WHERE 1=1";

        $params = [];

        // Apply filters
        if (!empty($filters['status'])) {
            $query .= " AND o.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $query .= " AND DATE(o.order_date) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $query .= " AND DATE(o.order_date) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['customer_name'])) {
            $query .= " AND o.customer_name LIKE :customer_name";
            $params[':customer_name'] = '%' . $filters['customer_name'] . '%';
        }
        
        if (!empty($filters['order_number'])) {
            $query .= " AND o.order_number LIKE :order_number";
            $params[':order_number'] = '%' . $filters['order_number'] . '%';
        }

        $query .= " GROUP BY o.id ORDER BY o.order_date DESC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching all orders: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a single order by ID with all items
     * @param int $id Order ID
     * @return array|false Order data with items or false if not found
     */
    public function findById(int $id) {
        $orderQuery = "SELECT * FROM {$this->ordersTable} WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($orderQuery);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                return false;
            }

            // Get order items
            $itemsQuery = "SELECT oi.*, p.sku, p.name as product_name, p.price as current_price
                          FROM {$this->orderItemsTable} oi
                          LEFT JOIN {$this->productsTable} p ON oi.product_id = p.product_id
                          WHERE oi.order_id = :order_id
                          ORDER BY oi.id ASC";
            
            $itemsStmt = $this->conn->prepare($itemsQuery);
            $itemsStmt->bindParam(':order_id', $id, PDO::PARAM_INT);
            $itemsStmt->execute();
            
            $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $order;
        } catch (PDOException $e) {
            error_log("Error finding order by ID: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new order with items
     * @param array $orderData Order data
     * @param array $items Array of order items
     * @return int|false Order ID on success, false on failure
     */
    public function create(array $orderData, array $items = []): int|false {
        if (empty($orderData['customer_name']) || empty($items)) {
            error_log("Order creation failed: Customer name and items are required");
            return false;
        }

        try {
            $this->conn->beginTransaction();

            // Generate order number if not provided
            if (empty($orderData['order_number'])) {
                $orderData['order_number'] = $this->generateOrderNumber();
            }

            // Insert order
            $orderQuery = "INSERT INTO {$this->ordersTable} 
                          (order_number, customer_name, customer_email, shipping_address, order_date, status, notes)
                          VALUES (:order_number, :customer_name, :customer_email, :shipping_address, :order_date, :status, :notes)";
            
            $orderStmt = $this->conn->prepare($orderQuery);
            $orderStmt->bindParam(':order_number', $orderData['order_number'], PDO::PARAM_STR);
            $orderStmt->bindParam(':customer_name', $orderData['customer_name'], PDO::PARAM_STR);
            $orderStmt->bindParam(':customer_email', $orderData['customer_email'] ?? null, PDO::PARAM_STR);
            $orderStmt->bindParam(':shipping_address', $orderData['shipping_address'] ?? null, PDO::PARAM_STR);
            $orderStmt->bindParam(':order_date', $orderData['order_date'] ?? date('Y-m-d H:i:s'), PDO::PARAM_STR);
            $orderStmt->bindParam(':status', $orderData['status'] ?? self::STATUS_PENDING, PDO::PARAM_STR);
            $orderStmt->bindParam(':notes', $orderData['notes'] ?? null, PDO::PARAM_STR);
            $orderStmt->execute();

            $orderId = (int)$this->conn->lastInsertId();

            // Insert order items
            $totalValue = 0;
            foreach ($items as $item) {
                if (empty($item['product_id']) || empty($item['quantity_ordered']) || empty($item['unit_price'])) {
                    $this->conn->rollback();
                    error_log("Order creation failed: Invalid item data");
                    return false;
                }

                $itemQuery = "INSERT INTO {$this->orderItemsTable} 
                             (order_id, product_id, quantity_ordered, unit_price)
                             VALUES (:order_id, :product_id, :quantity_ordered, :unit_price)";
                
                $itemStmt = $this->conn->prepare($itemQuery);
                $itemStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
                $itemStmt->bindParam(':product_id', $item['product_id'], PDO::PARAM_INT);
                $itemStmt->bindParam(':quantity_ordered', $item['quantity_ordered'], PDO::PARAM_INT);
                $itemStmt->bindParam(':unit_price', $item['unit_price'], PDO::PARAM_STR);
                $itemStmt->execute();

                $totalValue += $item['quantity_ordered'] * $item['unit_price'];
            }

            // Update order with total value
            $updateQuery = "UPDATE {$this->ordersTable} SET total_value = :total_value WHERE id = :order_id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':total_value', $totalValue, PDO::PARAM_STR);
            $updateStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
            $updateStmt->execute();

            $this->conn->commit();
            return $orderId;

        } catch (PDOException $e) {
            $this->conn->rollback();
            error_log("Error creating order: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update an order
     * @param int $id Order ID
     * @param array $data Order data to update
     * @return bool Success status
     */
    public function update(int $id, array $data): bool {
        if (empty($data)) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        $allowedFields = ['customer_name', 'customer_email', 'shipping_address', 'status', 'tracking_number', 'notes'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        // Handle status-specific updates
        if (isset($data['status'])) {
            if ($data['status'] === self::STATUS_SHIPPED && empty($data['shipped_date'])) {
                $fields[] = "shipped_date = :shipped_date";
                $params[':shipped_date'] = date('Y-m-d H:i:s');
            }
        }

        if (empty($fields)) {
            return false;
        }

        $query = "UPDATE {$this->ordersTable} SET " . implode(', ', $fields) . " WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error updating order: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete an order (only if status is Pending or Cancelled)
     * @param int $id Order ID
     * @return bool Success status
     */
    public function delete(int $id): bool {
        try {
            $this->conn->beginTransaction();

            // Check if order can be deleted
            $checkQuery = "SELECT status FROM {$this->ordersTable} WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $checkStmt->execute();
            $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                $this->conn->rollback();
                return false;
            }

            if (!in_array($order['status'], [self::STATUS_PENDING, self::STATUS_CANCELLED])) {
                $this->conn->rollback();
                error_log("Cannot delete order: Order status is {$order['status']}");
                return false;
            }

            // Delete order items first (foreign key constraint)
            $deleteItemsQuery = "DELETE FROM {$this->orderItemsTable} WHERE order_id = :order_id";
            $deleteItemsStmt = $this->conn->prepare($deleteItemsQuery);
            $deleteItemsStmt->bindParam(':order_id', $id, PDO::PARAM_INT);
            $deleteItemsStmt->execute();

            // Delete order
            $deleteOrderQuery = "DELETE FROM {$this->ordersTable} WHERE id = :id";
            $deleteOrderStmt = $this->conn->prepare($deleteOrderQuery);
            $deleteOrderStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $result = $deleteOrderStmt->execute();

            $this->conn->commit();
            return $result;

        } catch (PDOException $e) {
            $this->conn->rollback();
            error_log("Error deleting order: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate unique order number
     * @return string Generated order number
     */
    private function generateOrderNumber(): string {
        $prefix = 'ORD';
        $date = date('Ymd');
        
        // Get next sequence number for today
        $query = "SELECT COUNT(*) + 1 as next_num FROM {$this->ordersTable} WHERE DATE(order_date) = CURDATE()";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $sequence = str_pad($result['next_num'], 4, '0', STR_PAD_LEFT);
        
        return "{$prefix}-{$date}-{$sequence}";
    }

    /**
     * Get available order statuses
     * @return array Array of status options
     */
    public function getStatuses(): array {
        return [
            self::STATUS_PENDING => 'În Așteptare',
            self::STATUS_PROCESSING => 'În Procesare',
            self::STATUS_AWAITING_SHIPMENT => 'Așteptând Expediere',
            self::STATUS_SHIPPED => 'Expediat',
            self::STATUS_COMPLETED => 'Finalizat',
            self::STATUS_CANCELLED => 'Anulat',
            self::STATUS_ON_HOLD => 'În Suspendare'
        ];
    }

    /**
     * Get orders ready for picking (Processing status)
     * @return array Array of orders ready for picking
     */
    public function getOrdersForPicking(): array {
        $query = "SELECT o.*, 
                         COUNT(oi.id) as item_count,
                         COALESCE(SUM(oi.quantity_ordered), 0) as total_items,
                         COALESCE(SUM(oi.picked_quantity), 0) as picked_items
                  FROM {$this->ordersTable} o
                  LEFT JOIN {$this->orderItemsTable} oi ON o.id = oi.order_id
                  WHERE o.status = :status
                  GROUP BY o.id
                  HAVING picked_items < total_items
                  ORDER BY o.order_date ASC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':status', self::STATUS_PROCESSING, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching orders for picking: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get order by ID with items
     * @param int $orderId Order ID
     * @return array|null Order data with items
     */
    public function getOrderById(int $orderId): ?array {
        // Get order details
        $orderQuery = "SELECT * FROM {$this->ordersTable} WHERE id = :order_id";
        
        try {
            $stmt = $this->conn->prepare($orderQuery);
            $stmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
            $stmt->execute();
            
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                return null;
            }
            
            // Get order items
            $itemsQuery = "SELECT oi.*, p.sku, p.name as product_name, p.unit_of_measure
                          FROM {$this->orderItemsTable} oi
                          LEFT JOIN {$this->productsTable} p ON oi.product_id = p.product_id
                          WHERE oi.order_id = :order_id
                          ORDER BY oi.id ASC";
            
            $stmt = $this->conn->prepare($itemsQuery);
            $stmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
            $stmt->execute();
            
            $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $order;
            
        } catch (PDOException $e) {
            error_log("Error getting order by ID: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update order status
     * @param int $orderId Order ID
     * @param string $status New status
     * @return bool Success status
     */
    public function updateStatus(int $orderId, string $status): bool {
        $query = "UPDATE {$this->ordersTable} 
                  SET status = :status, 
                      updated_at = NOW()
                  WHERE id = :order_id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error updating order status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if order is fully picked
     * @param int $orderId Order ID
     * @return bool True if fully picked
     */
    public function isOrderFullyPicked(int $orderId): bool {
        $query = "SELECT 
                    COALESCE(SUM(quantity_ordered), 0) as total_ordered,
                    COALESCE(SUM(picked_quantity), 0) as total_picked
                  FROM {$this->orderItemsTable} 
                  WHERE order_id = :order_id";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result && $result['total_ordered'] == $result['total_picked'];
        } catch (PDOException $e) {
            error_log("Error checking if order is fully picked: " . $e->getMessage());
            return false;
        }
    }

    // Dashboard methods (existing)
    public function countActiveOrders(): int {
        $activeStatuses = [self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_AWAITING_SHIPMENT, self::STATUS_ON_HOLD];
        $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
        $query = "SELECT COUNT(id) as active_count FROM " . $this->ordersTable . " WHERE status IN (" . $placeholders . ")";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($activeStatuses);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return isset($result['active_count']) ? (int)$result['active_count'] : 0;
        } catch(PDOException $e) {
            error_log("Error counting active orders: " . $e->getMessage());
            return 0;
        }
    }

    public function countShippedToday(): int {
        $shippedStatuses = [self::STATUS_SHIPPED, self::STATUS_COMPLETED];
        $placeholders = implode(',', array_fill(0, count($shippedStatuses), '?'));
        $query = "SELECT COUNT(id) as shipped_today_count FROM " . $this->ordersTable . " WHERE status IN (" . $placeholders . ") AND DATE(shipped_date) = CURDATE()";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($shippedStatuses);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return isset($result['shipped_today_count']) ? (int)$result['shipped_today_count'] : 0;
        } catch(PDOException $e) {
            error_log("Error counting shipped orders today: " . $e->getMessage());
            return 0;
        }
    }
}