<?php

// File: models/Order.php - Updated for orders page functionality
class Order {
    private $conn;
    private $table = "orders";
    private $itemsTable = "order_items";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Get total count of orders with filters
     * @param string $statusFilter Filter by status
     * @param string $priorityFilter Filter by priority
     * @param string $search Search in order number, customer name
     * @return int Total count
     */
    public function getTotalCount($statusFilter = '', $priorityFilter = '', $search = '') {
        $query = "SELECT COUNT(*) as total FROM {$this->table} WHERE 1=1";
        $params = [];
        
        if (!empty($statusFilter)) {
            $query .= " AND status = :status";
            $params[':status'] = $statusFilter;
        }
        
        if (!empty($priorityFilter)) {
            $query .= " AND priority = :priority";
            $params[':priority'] = $priorityFilter;
        }
        
        if (!empty($search)) {
            $query .= " AND (order_number LIKE :search OR customer_name LIKE :search OR customer_email LIKE :search)";
            $params[':search'] = '%' . $search . '%';
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
     * Get orders with pagination and filtering
     * @param int $pageSize Number of orders per page
     * @param int $offset Starting offset
     * @param string $statusFilter Filter by status
     * @param string $priorityFilter Filter by priority
     * @param string $search Search term
     * @return array
     */
    public function getOrdersPaginated($pageSize, $offset, $statusFilter = '', $priorityFilter = '', $search = '') {
        $query = "SELECT o.*, 
                         COALESCE((SELECT SUM(oi.quantity * oi.unit_price) FROM {$this->itemsTable} oi WHERE oi.order_id = o.id), 0) as total_value,
                         COALESCE((SELECT COUNT(*) FROM {$this->itemsTable} oi WHERE oi.order_id = o.id), 0) as total_items
                  FROM {$this->table} o
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
     * Get unique statuses
     * @return array
     */
    public function getStatuses() {
        $query = "SELECT DISTINCT status FROM {$this->table} WHERE status IS NOT NULL AND status != '' ORDER BY status";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Add common statuses if not present
            $commonStatuses = ['Pending', 'Processing', 'Picked', 'Shipped', 'Delivered', 'Cancelled'];
            return array_unique(array_merge($result, $commonStatuses));
        } catch (PDOException $e) {
            error_log("Error getting statuses: " . $e->getMessage());
            return ['Pending', 'Processing', 'Picked', 'Shipped', 'Delivered', 'Cancelled'];
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
     * Create a new order with items
     * @param array $orderData Order data
     * @param array $items Order items
     * @return int|false Order ID on success, false on failure
     */
    public function createOrder(array $orderData, array $items) {
        try {
            $this->conn->beginTransaction();
            
            // Create order
            $query = "INSERT INTO {$this->table} 
                      (order_number, customer_name, customer_email, shipping_address, order_date, status, priority, notes, created_by, created_at) 
                      VALUES (:order_number, :customer_name, :customer_email, :shipping_address, :order_date, :status, :priority, :notes, :created_by, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $params = [
                ':order_number' => $orderData['order_number'],
                ':customer_name' => $orderData['customer_name'],
                ':customer_email' => $orderData['customer_email'] ?? '',
                ':shipping_address' => $orderData['shipping_address'] ?? '',
                ':order_date' => $orderData['order_date'] ?? date('Y-m-d H:i:s'),
                ':status' => strtolower($orderData['status'] ?? 'pending'),
                ':priority' => $orderData['priority'] ?? 'normal',
                ':notes' => $orderData['notes'] ?? '',
                ':created_by' => $orderData['created_by'] ?? null
            ];
            
            $stmt->execute($params);
            $orderId = $this->conn->lastInsertId();
            
            // Create order items
            if (!empty($items)) {
                $itemQuery = "INSERT INTO {$this->itemsTable} 
                              (order_id, product_id, quantity, unit_price, created_at) 
                              VALUES (:order_id, :product_id, :quantity, :unit_price, NOW())";
                
                $itemStmt = $this->conn->prepare($itemQuery);
                
                foreach ($items as $item) {
                    $itemParams = [
                        ':order_id' => $orderId,
                        ':product_id' => $item['product_id'],
                        ':quantity' => $item['quantity'],
                        ':unit_price' => $item['unit_price']
                    ];
                    $itemStmt->execute($itemParams);
                }
            }
            
            $this->conn->commit();

            // Log order creation
            $userId = $orderData['created_by'] ?? ($_SESSION['user_id'] ?? 0);
            logActivity(
                $userId,
                'create',
                'order',
                $orderId,
                'Order created',
                null,
                $orderData
            );

            return $orderId;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error creating order: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update order status
     * @param int $orderId Order ID
     * @param string $status New status
     * @return bool
     */
    public function updateStatus($orderId, $status) {
        $status = strtolower($status);
        $query = "UPDATE {$this->table} SET status = :status, updated_at = NOW() WHERE id = :id";

        try {
            $old = $this->getOrderById($orderId);
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $stmt->bindValue(':status', $status);
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
     * Update AWB related fields for an order
     * @param int $orderId Order ID
     * @param array $awbData Key/value pairs to update
     * @return bool
     */
    public function updateAWBInfo(int $orderId, array $awbData): bool {
        if (empty($awbData)) {
            return false;
        }

        $fields = [];
        $params = [':id' => $orderId];
        foreach ($awbData as $key => $value) {
            $fields[] = "$key = :$key";
            $params[":$key"] = $value;
        }

        $query = "UPDATE {$this->table} SET " . implode(',', $fields) . ", updated_at = NOW() WHERE id = :id";

        try {
            $stmt = $this->conn->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log('Error updating AWB info: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete an order and its items
     * @param int $orderId Order ID
     * @return bool
     */
    public function deleteOrder($orderId) {
        try {
            $this->conn->beginTransaction();
            
            // Delete order items first
            $itemQuery = "DELETE FROM {$this->itemsTable} WHERE order_id = :order_id";
            $itemStmt = $this->conn->prepare($itemQuery);
            $itemStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $itemStmt->execute();
            
            // Delete order
            $orderQuery = "DELETE FROM {$this->table} WHERE id = :id";
            $orderStmt = $this->conn->prepare($orderQuery);
            $orderStmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $result = $orderStmt->execute();

            $this->conn->commit();

            if ($result) {
                $userId = $_SESSION['user_id'] ?? 0;
                logActivity(
                    $userId,
                    'delete',
                    'order',
                    $orderId,
                    'Order deleted'
                );
            }

            return $result;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error deleting order: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get order by ID with items
     * @param int $orderId Order ID
     * @return array|false
     */
    public function getOrderById($orderId) {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $stmt->execute();
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($order) {
                $order['items'] = $this->getOrderItems($orderId);
            }
            
            return $order;
        } catch (PDOException $e) {
            error_log("Error getting order by ID: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get order items
     * @param int $orderId Order ID
     * @return array
     */
    public function getOrderItems($orderId) {
        $query = "SELECT oi.*, p.name as product_name, p.sku 
                  FROM {$this->itemsTable} oi
                  LEFT JOIN products p ON oi.product_id = p.product_id
                  WHERE oi.order_id = :order_id
                  ORDER BY oi.id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting order items: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all orders with basic info
     * @return array
     */
    public function getAllOrders() {
        $query = "SELECT * FROM {$this->table} ORDER BY order_date DESC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting all orders: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get orders by status
     * @param string $status Order status
     * @return array
     */
    public function getOrdersByStatus($status) {
        $query = "SELECT * FROM {$this->table} WHERE status = :status ORDER BY order_date DESC";
        
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
     * Update order
     * @param int $orderId Order ID
     * @param array $orderData Order data
     * @return bool
     */
     public function updateOrder($orderId, array $orderData) {
        // Handle the case where only specific fields need updating (like status, assigned_to)
        $fieldsToUpdate = [];
        $params = [':id' => $orderId];
        
        // Only update fields that are provided
        if (isset($orderData['customer_name'])) {
            $fieldsToUpdate[] = 'customer_name = :customer_name';
            $params[':customer_name'] = $orderData['customer_name'];
        }
        
        if (isset($orderData['customer_email'])) {
            $fieldsToUpdate[] = 'customer_email = :customer_email';
            $params[':customer_email'] = $orderData['customer_email'];
        }
        
        if (isset($orderData['shipping_address'])) {
            $fieldsToUpdate[] = 'shipping_address = :shipping_address';
            $params[':shipping_address'] = $orderData['shipping_address'];
        }
        
        if (isset($orderData['status'])) {
            $fieldsToUpdate[] = 'status = :status';
            $params[':status'] = $orderData['status'];
        }
        
        if (isset($orderData['priority'])) {
            $fieldsToUpdate[] = 'priority = :priority';
            $params[':priority'] = $orderData['priority'];
        }
        
        if (isset($orderData['notes'])) {
            $fieldsToUpdate[] = 'notes = :notes';
            $params[':notes'] = $orderData['notes'];
        }
        
        if (isset($orderData['assigned_to'])) {
            $fieldsToUpdate[] = 'assigned_to = :assigned_to';
            $params[':assigned_to'] = $orderData['assigned_to'];
        }
        
        if (empty($fieldsToUpdate)) {
            return false; // Nothing to update
        }
        
        // Always update the updated_at timestamp
        $fieldsToUpdate[] = 'updated_at = NOW()';
        
        $query = "UPDATE {$this->table} SET " . implode(', ', $fieldsToUpdate) . " WHERE id = :id";
        
        try {
            $old = $this->getOrderById($orderId);
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute($params);
            if ($result) {
                $userId = $_SESSION['user_id'] ?? 0;
                logActivity(
                    $userId,
                    'update',
                    'order',
                    $orderId,
                    'Order updated',
                    $old,
                    $orderData
                );
            }
            return $result;
        } catch (PDOException $e) {
            error_log("Error updating order: " . $e->getMessage());
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
        $query = "SELECT COUNT(*) FROM orders WHERE status = 'pending'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
        
    } catch (PDOException $e) {
        error_log("Error counting pending orders: " . $e->getMessage());
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
                  WHERE status = 'completed' AND DATE(updated_at) = CURDATE()";
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
        $query = "SELECT id, customer_name, status, total_amount, created_at
                  FROM orders 
                  ORDER BY created_at DESC 
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
}