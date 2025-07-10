<?php
/**
 * PurchaseOrder Model
 * Handles purchase orders for stock ordering
 */

class PurchaseOrder {
    private PDO $conn;
    private string $table = 'purchase_orders';

    public function __construct(PDO $database) {
        $this->conn = $database;
    }

    /**
     * Generate next order number
     * @return string
     */
    public function generateOrderNumber(): string {
        $year = date('Y');
        $query = "SELECT COUNT(*) + 1 as next_number FROM {$this->table} 
                  WHERE YEAR(created_at) = :year";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':year', $year);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $nextNumber = str_pad((string)$result['next_number'], 3, '0', STR_PAD_LEFT);
            
            return "PO-{$year}-{$nextNumber}";
        } catch (PDOException $e) {
            error_log("Error generating order number: " . $e->getMessage());
            return "PO-{$year}-" . str_pad((string)rand(1, 999), 3, '0', STR_PAD_LEFT);
        }
    }

    /**
     * Create new purchase order
     * @param array $orderData
     * @return int|false Order ID on success, false on failure
     */
    public function createPurchaseOrder(array $orderData): int|false {
        $this->conn->beginTransaction();
        
        try {
            // Generate order number
            $orderNumber = $this->generateOrderNumber();
            
            // Create purchase order
            $query = "INSERT INTO {$this->table} (
                order_number, seller_id, total_amount, currency, custom_message,
                status, expected_delivery_date, email_recipient, notes, created_by
            ) VALUES (
                :order_number, :seller_id, :total_amount, :currency, :custom_message,
                :status, :expected_delivery_date, :email_recipient, :notes, :created_by
            )";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':order_number', $orderNumber);
            $stmt->bindValue(':seller_id', $orderData['seller_id'], PDO::PARAM_INT);
            $stmt->bindValue(':total_amount', $orderData['total_amount'] ?? 0.00);
            $stmt->bindValue(':currency', $orderData['currency'] ?? 'RON');
            $stmt->bindValue(':custom_message', $orderData['custom_message'] ?? null);
            $stmt->bindValue(':status', $orderData['status'] ?? 'draft');
            $stmt->bindValue(':expected_delivery_date', $orderData['expected_delivery_date'] ?? null);
            $stmt->bindValue(':email_recipient', $orderData['email_recipient'] ?? null);
            $stmt->bindValue(':notes', $orderData['notes'] ?? null);
            $stmt->bindValue(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create purchase order");
            }
            
            $orderId = (int)$this->conn->lastInsertId();
            
            // Add purchase order items if provided
            if (!empty($orderData['items'])) {
                foreach ($orderData['items'] as $item) {
                    $this->addOrderItem($orderId, $item);
                }
                
                // Update total amount
                $this->updateOrderTotal($orderId);
            }
            
            $this->conn->commit();
            
            // Log activity
            if (function_exists('logActivity')) {
                logActivity(
                    $_SESSION['user_id'],
                    'create',
                    'purchase_order',
                    $orderId,
                    'Purchase order created: ' . $orderNumber
                );
            }
            
            return $orderId;
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Error creating purchase order: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add item to purchase order
     * @param int $orderId
     * @param array $itemData
     * @return bool
     */
    public function addOrderItem(int $orderId, array $itemData): bool {
        $query = "INSERT INTO purchase_order_items (
            purchase_order_id, purchasable_product_id, quantity, unit_price, total_price, notes
        ) VALUES (
            :purchase_order_id, :purchasable_product_id, :quantity, :unit_price, :total_price, :notes
        )";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':purchase_order_id', $orderId, PDO::PARAM_INT);
            $stmt->bindValue(':purchasable_product_id', $itemData['purchasable_product_id'], PDO::PARAM_INT);
            $stmt->bindValue(':quantity', $itemData['quantity']);
            $stmt->bindValue(':unit_price', $itemData['unit_price']);
            $stmt->bindValue(':total_price', $itemData['quantity'] * $itemData['unit_price']);
            $stmt->bindValue(':notes', $itemData['notes'] ?? null);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error adding order item: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update order total amount
     * @param int $orderId
     * @return bool
     */
    public function updateOrderTotal(int $orderId): bool {
        $query = "UPDATE {$this->table} 
                  SET total_amount = (
                      SELECT COALESCE(SUM(total_price), 0) 
                      FROM purchase_order_items 
                      WHERE purchase_order_id = :order_id
                  ) 
                  WHERE id = :order_id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error updating order total: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get purchase order by ID with items
     * @param int $orderId
     * @return array|false
     */
    public function getPurchaseOrderById(int $orderId): array|false {
        $query = "SELECT po.*, s.supplier_name, s.email as seller_email, s.contact_person,
                         u.username as created_by_name
                  FROM {$this->table} po
                  LEFT JOIN sellers s ON po.seller_id = s.id
                  LEFT JOIN users u ON po.created_by = u.id
                  WHERE po.id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $stmt->execute();
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($order) {
                // Get order items
                $order['items'] = $this->getOrderItems($orderId);
            }
            
            return $order;
        } catch (PDOException $e) {
            error_log("Error getting purchase order by ID: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get order items
     * @param int $orderId
     * @return array
     */
    public function getOrderItems(int $orderId): array {
        $query = "SELECT poi.*, pp.supplier_product_name, pp.supplier_product_code
                  FROM purchase_order_items poi
                  LEFT JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
                  WHERE poi.purchase_order_id = :order_id
                  ORDER BY poi.id ASC";
        
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
     * Get all purchase orders
     * @param array $filters
     * @return array
     */
    public function getAllPurchaseOrders(array $filters = []): array {
        $query = "SELECT po.*, s.supplier_name, u.username as created_by_name,
                         COUNT(poi.id) as item_count
                  FROM {$this->table} po
                  LEFT JOIN sellers s ON po.seller_id = s.id
                  LEFT JOIN users u ON po.created_by = u.id
                  LEFT JOIN purchase_order_items poi ON po.id = poi.purchase_order_id";
        
        $conditions = [];
        $params = [];
        
        if (!empty($filters['status'])) {
            $conditions[] = "po.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['seller_id'])) {
            $conditions[] = "po.seller_id = :seller_id";
            $params[':seller_id'] = $filters['seller_id'];
        }
        
        if (!empty($conditions)) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $query .= " GROUP BY po.id ORDER BY po.created_at DESC";
        
        try {
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting purchase orders: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Update purchase order status
     * @param int $orderId
     * @param string $status
     * @return bool
     */
    public function updateStatus(int $orderId, string $status): bool {
        $query = "UPDATE {$this->table} SET status = :status WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $stmt->bindValue(':status', $status);
            
            $result = $stmt->execute();
            
            if ($result && function_exists('logActivity')) {
                logActivity(
                    $_SESSION['user_id'],
                    'update',
                    'purchase_order',
                    $orderId,
                    "Status updated to: {$status}"
                );
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error updating purchase order status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark order as sent via email
     * @param int $orderId
     * @param string $emailRecipient
     * @return bool
     */
    public function markAsSent(int $orderId, string $emailRecipient): bool {
        $query = "UPDATE {$this->table} 
                  SET status = 'sent', email_sent_at = NOW(), email_recipient = :email 
                  WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $stmt->bindValue(':email', $emailRecipient);
            
            $result = $stmt->execute();
            
            if ($result && function_exists('logActivity')) {
                logActivity(
                    $_SESSION['user_id'],
                    'update',
                    'purchase_order',
                    $orderId,
                    "Purchase order sent to: {$emailRecipient}"
                );
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error marking purchase order as sent: " . $e->getMessage());
            return false;
        }
    }
}