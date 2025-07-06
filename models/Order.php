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
            $commonStatuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
            return array_unique(array_merge($result, $commonStatuses));
        } catch (PDOException $e) {
            error_log("Error getting statuses: " . $e->getMessage());
            return ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
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
                ':status' => $orderData['status'] ?? 'Pending',
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
        $query = "UPDATE {$this->table} SET status = :status, updated_at = NOW() WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $stmt->bindValue(':status', $status);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error updating order status: " . $e->getMessage());
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
        $query = "UPDATE {$this->table} 
                  SET customer_name = :customer_name, 
                      customer_email = :customer_email, 
                      shipping_address = :shipping_address, 
                      status = :status, 
                      priority = :priority, 
                      notes = :notes, 
                      updated_at = NOW()
                  WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $params = [
                ':id' => $orderId,
                ':customer_name' => $orderData['customer_name'],
                ':customer_email' => $orderData['customer_email'] ?? '',
                ':shipping_address' => $orderData['shipping_address'] ?? '',
                ':status' => $orderData['status'] ?? 'Pending',
                ':priority' => $orderData['priority'] ?? 'normal',
                ':notes' => $orderData['notes'] ?? ''
            ];
            
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error updating order: " . $e->getMessage());
            return false;
        }
    }
}