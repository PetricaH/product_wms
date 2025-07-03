<?php
/**
 * Enhanced Order Model with AWB Support
 * File: models/Order.php (Updated Version)
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
     * Create a new order with items and AWB data
     * @param array $orderData Order data including AWB fields
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

            // Build the SQL query dynamically based on provided fields
            $fields = [
                'order_number', 'customer_name', 'customer_email', 'shipping_address', 
                'order_date', 'status', 'notes', 'source'
            ];
            
            // AWB fields
            $awbFields = [
                'recipient_county_id', 'recipient_county_name', 'recipient_locality_id', 
                'recipient_locality_name', 'recipient_street_id', 'recipient_street_name',
                'recipient_building_number', 'recipient_contact_person', 'recipient_phone', 
                'recipient_email', 'total_weight', 'declared_value', 'parcels_count',
                'envelopes_count', 'cash_repayment', 'bank_repayment', 'saturday_delivery',
                'morning_delivery', 'open_package', 'observations', 'package_content',
                'sender_reference1', 'recipient_reference1', 'recipient_reference2',
                'invoice_reference', 'sender_location_id'
            ];
            
            $allFields = array_merge($fields, $awbFields);
            $providedFields = [];
            $values = [];
            $placeholders = [];
            
            foreach ($allFields as $field) {
                if (array_key_exists($field, $orderData)) {
                    $providedFields[] = $field;
                    $placeholders[] = ":$field";
                    $values[":$field"] = $orderData[$field];
                }
            }
            
            // Ensure required fields have defaults
            if (!in_array('order_date', $providedFields)) {
                $providedFields[] = 'order_date';
                $placeholders[] = ':order_date';
                $values[':order_date'] = date('Y-m-d H:i:s');
            }
            
            if (!in_array('status', $providedFields)) {
                $providedFields[] = 'status';
                $placeholders[] = ':status';
                $values[':status'] = self::STATUS_PENDING;
            }

            $orderQuery = "INSERT INTO {$this->ordersTable} (" . 
                         implode(', ', $providedFields) . ") VALUES (" . 
                         implode(', ', $placeholders) . ")";
            
            $orderStmt = $this->conn->prepare($orderQuery);
            $orderStmt->execute($values);
            
            $orderId = $this->conn->lastInsertId();

            // Insert order items
            $itemQuery = "INSERT INTO {$this->orderItemsTable} 
                         (order_id, product_id, quantity_ordered, unit_price, picked_quantity, notes)
                         VALUES (:order_id, :product_id, :quantity_ordered, :unit_price, 0, :notes)";
            
            $itemStmt = $this->conn->prepare($itemQuery);
            
            foreach ($items as $item) {
                $itemStmt->execute([
                    ':order_id' => $orderId,
                    ':product_id' => $item['product_id'],
                    ':quantity_ordered' => $item['quantity_ordered'],
                    ':unit_price' => $item['unit_price'] ?? 0,
                    ':notes' => $item['notes'] ?? ''
                ]);
            }

            $this->conn->commit();
            return (int)$orderId;

        } catch (PDOException $e) {
            $this->conn->rollback();
            error_log("Error creating order: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get order by ID with full details including AWB data
     * @param int $orderId Order ID
     * @return array|null Order data with items
     */
    public function getOrderById(int $orderId): ?array {
        try {
            // Get order data
            $orderQuery = "SELECT o.*, 
                                 COUNT(oi.id) as item_count,
                                 COALESCE(SUM(oi.quantity_ordered), 0) as total_items,
                                 COALESCE(SUM(oi.picked_quantity), 0) as picked_items,
                                 COALESCE(SUM(oi.quantity_ordered * oi.unit_price), 0) as total_value
                          FROM {$this->ordersTable} o
                          LEFT JOIN {$this->orderItemsTable} oi ON o.id = oi.order_id
                          WHERE o.id = :order_id
                          GROUP BY o.id";
            
            $orderStmt = $this->conn->prepare($orderQuery);
            $orderStmt->execute([':order_id' => $orderId]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                return null;
            }

            // Get order items
            $itemsQuery = "SELECT oi.*, p.sku, p.name as product_name, p.unit_of_measure
                          FROM {$this->orderItemsTable} oi
                          JOIN {$this->productsTable} p ON oi.product_id = p.product_id
                          WHERE oi.order_id = :order_id
                          ORDER BY oi.id";
            
            $itemsStmt = $this->conn->prepare($itemsQuery);
            $itemsStmt->execute([':order_id' => $orderId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $order['items'] = $items;
            
            // Add AWB status
            $order['awb_ready'] = !empty($order['recipient_county_id']) && !empty($order['recipient_contact_person']);
            $order['awb_generated'] = !empty($order['awb_barcode']);
            
            return $order;

        } catch (PDOException $e) {
            error_log("Error fetching order: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all orders with optional filters
     * @param array $filters Optional filters
     * @param int $limit Limit for pagination
     * @param int $offset Offset for pagination
     * @return array Array of orders
     */
    public function getAllOrders(array $filters = [], int $limit = 50, int $offset = 0): array {
        $query = "SELECT o.*, 
                         COUNT(oi.id) as item_count,
                         COALESCE(SUM(oi.quantity_ordered), 0) as total_items,
                         COALESCE(SUM(oi.picked_quantity), 0) as picked_items,
                         COALESCE(SUM(oi.quantity_ordered * oi.unit_price), 0) as total_value,
                         CASE WHEN o.awb_barcode IS NOT NULL THEN 1 ELSE 0 END as has_awb
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
        
        if (!empty($filters['awb_barcode'])) {
            $query .= " AND o.awb_barcode = :awb_barcode";
            $params[':awb_barcode'] = $filters['awb_barcode'];
        }

        $query .= " GROUP BY o.id ORDER BY o.order_date DESC LIMIT :limit OFFSET :offset";

        try {
            $stmt = $this->conn->prepare($query);
            
            // Bind limit and offset separately as integers
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching all orders: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Update order status
     * @param int $orderId Order ID
     * @param string $status New status
     * @return bool Success status
     */
    public function updateStatus(int $orderId, string $status): bool {
        try {
            $query = "UPDATE {$this->ordersTable} SET status = :status WHERE id = :order_id";
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute([
                ':status' => $status,
                ':order_id' => $orderId
            ]);
            
            return $result && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error updating order status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update AWB information for an order
     * @param int $orderId Order ID
     * @param array $awbData AWB data to update
     * @return bool Success status
     */
    public function updateAWBInfo(int $orderId, array $awbData): bool {
        try {
            $fields = [];
            $params = [':order_id' => $orderId];
            
            foreach ($awbData as $field => $value) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $value;
            }
            
            if (empty($fields)) {
                return false;
            }
            
            $query = "UPDATE {$this->ordersTable} SET " . implode(', ', $fields) . " WHERE id = :order_id";
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute($params);
            
            return $result && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error updating AWB info: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get orders ready for AWB generation
     * @return array Orders with complete AWB data but no AWB yet
     */
    public function getOrdersReadyForAWB(): array {
        try {
            $query = "SELECT o.*, 
                             COUNT(oi.id) as item_count,
                             COALESCE(SUM(oi.quantity_ordered), 0) as total_items
                      FROM {$this->ordersTable} o
                      LEFT JOIN {$this->orderItemsTable} oi ON o.id = oi.order_id
                      WHERE o.status IN ('Processing', 'Awaiting Shipment')
                        AND o.recipient_county_id IS NOT NULL
                        AND o.recipient_locality_id IS NOT NULL
                        AND o.recipient_contact_person IS NOT NULL
                        AND o.recipient_phone IS NOT NULL
                        AND o.awb_barcode IS NULL
                      GROUP BY o.id
                      ORDER BY o.order_date ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching orders ready for AWB: " . $e->getMessage());
            return [];
        }
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
     * Search orders by AWB barcode
     * @param string $awbBarcode AWB barcode
     * @return array|null Order data
     */
    public function findByAWB(string $awbBarcode): ?array {
        try {
            $query = "SELECT o.*, 
                             COUNT(oi.id) as item_count,
                             COALESCE(SUM(oi.quantity_ordered), 0) as total_items,
                             COALESCE(SUM(oi.picked_quantity), 0) as picked_items
                      FROM {$this->ordersTable} o
                      LEFT JOIN {$this->orderItemsTable} oi ON o.id = oi.order_id
                      WHERE o.awb_barcode = :awb_barcode
                      GROUP BY o.id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':awb_barcode' => $awbBarcode]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $order ?: null;
        } catch (PDOException $e) {
            error_log("Error finding order by AWB: " . $e->getMessage());
            return null;
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
     * Find order by ID (alias for getOrderById for compatibility)
     * @param int $orderId Order ID
     * @return array|null Order data
     */
    public function findById(int $orderId): ?array {
        return $this->getOrderById($orderId);
    }

    /**
     * Count active orders (not completed, cancelled, or shipped)
     * @return int Number of active orders
     */
    public function countActiveOrders(): int {
        try {
            $query = "SELECT COUNT(*) as count FROM {$this->ordersTable} 
                      WHERE status NOT IN ('Completed', 'Cancelled', 'Shipped')";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0);
        } catch (PDOException $e) {
            error_log("Error counting active orders: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Count orders shipped today
     * @return int Number of orders shipped today
     */
    public function countShippedToday(): int {
        try {
            $query = "SELECT COUNT(*) as count FROM {$this->ordersTable} 
                      WHERE status = 'Shipped' AND DATE(order_date) = CURDATE()";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0);
        } catch (PDOException $e) {
            error_log("Error counting shipped orders today: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Count pending orders
     * @return int Number of pending orders
     */
    public function countPendingOrders(): int {
        try {
            $query = "SELECT COUNT(*) as count FROM {$this->ordersTable} 
                      WHERE status = 'Pending'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0);
        } catch (PDOException $e) {
            error_log("Error counting pending orders: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Count orders in processing
     * @return int Number of orders in processing
     */
    public function countProcessingOrders(): int {
        try {
            $query = "SELECT COUNT(*) as count FROM {$this->ordersTable} 
                      WHERE status = 'Processing'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0);
        } catch (PDOException $e) {
            error_log("Error counting processing orders: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get recent orders for dashboard
     * @param int $limit Number of orders to return
     * @return array Recent orders
     */
    public function getRecentOrders(int $limit = 10): array {
        try {
            $query = "SELECT id, order_number, customer_name, status, order_date, 
                             awb_barcode,
                             CASE WHEN awb_barcode IS NOT NULL THEN 1 ELSE 0 END as has_awb
                      FROM {$this->ordersTable} 
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
     * Get dashboard statistics
     * @return array Dashboard stats
     */
    public function getDashboardStats(): array {
        return [
            'active_orders' => $this->countActiveOrders(),
            'shipped_today' => $this->countShippedToday(),
            'pending_orders' => $this->countPendingOrders(),
            'processing_orders' => $this->countProcessingOrders(),
            'total_orders_today' => $this->countOrdersToday(),
            'orders_with_awb' => $this->countOrdersWithAWB()
        ];
    }

    /**
     * Count total orders created today
     * @return int Number of orders created today
     */
    public function countOrdersToday(): int {
        try {
            $query = "SELECT COUNT(*) as count FROM {$this->ordersTable} 
                      WHERE DATE(order_date) = CURDATE()";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0);
        } catch (PDOException $e) {
            error_log("Error counting orders today: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Count orders with AWB generated
     * @return int Number of orders with AWB
     */
    public function countOrdersWithAWB(): int {
        try {
            $query = "SELECT COUNT(*) as count FROM {$this->ordersTable} 
                      WHERE awb_barcode IS NOT NULL";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0);
        } catch (PDOException $e) {
            error_log("Error counting orders with AWB: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Validate AWB data completeness
     * @param array $orderData Order data
     * @return array Validation result with missing fields
     */
    public function validateAWBData(array $orderData): array {
        $required = [
            'recipient_county_id' => 'County ID',
            'recipient_locality_id' => 'Locality ID',
            'recipient_contact_person' => 'Contact Person',
            'recipient_phone' => 'Phone Number',
            'total_weight' => 'Total Weight'
        ];
        
        $missing = [];
        foreach ($required as $field => $label) {
            if (empty($orderData[$field])) {
                $missing[] = $label;
            }
        }
        
        return [
            'valid' => empty($missing),
            'missing_fields' => $missing
        ];
    }
}