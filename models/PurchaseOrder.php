<?php
/**
 * PurchaseOrder Model
 * Handles purchase orders with updated status workflow
 */

class PurchaseOrder {
    private PDO $conn;
    private string $table = 'purchase_orders';

    public function __construct(PDO $database) {
        $this->conn = $database;
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

    /**
     * Update PDF path for order
     */
    public function updatePdfPath(int $orderId, string $pdfPath): bool {
        $query = "UPDATE {$this->table} SET pdf_path = :pdf_path WHERE id = :id";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $stmt->bindValue(':pdf_path', $pdfPath);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log('Error updating pdf path: ' . $e->getMessage());
            return false;
        }
    }


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

    public function createPurchaseOrder(array $orderData): int|false {
        $this->conn->beginTransaction();
        
        try {
            // Generate order number
            $orderNumber = $this->generateOrderNumber();
            
            // Validate status (only change from original)
            $allowedStatuses = ['draft', 'sent', 'confirmed', 'partial_delivery', 'delivered', 'cancelled', 'returned', 'completed'];
            $status = $orderData['status'] ?? 'draft';
            if (!in_array($status, $allowedStatuses)) {
                $status = 'draft';
            }
            
            // Create purchase order
            $query = "INSERT INTO {$this->table} (
                order_number, seller_id, total_amount, currency, custom_message,
                email_subject, status, expected_delivery_date, email_recipient,
                notes, pdf_path, tax_rate, created_by
            ) VALUES (
                :order_number, :seller_id, :total_amount, :currency, :custom_message,
                :email_subject, :status, :expected_delivery_date, :email_recipient,
                :notes, :pdf_path, :tax_rate, :created_by
            )";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':order_number', $orderNumber);
            $stmt->bindValue(':seller_id', $orderData['seller_id'], PDO::PARAM_INT);
            $stmt->bindValue(':total_amount', $orderData['total_amount'] ?? 0.00);
            $stmt->bindValue(':currency', $orderData['currency'] ?? 'RON');
            $stmt->bindValue(':custom_message', $orderData['custom_message'] ?? null);
            $stmt->bindValue(':email_subject', $orderData['email_subject'] ?? null);
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':expected_delivery_date', $orderData['expected_delivery_date'] ?? null);
            $stmt->bindValue(':email_recipient', $orderData['email_recipient'] ?? null);
            $stmt->bindValue(':notes', $orderData['notes'] ?? null);
            $stmt->bindValue(':pdf_path', $orderData['pdf_path'] ?? null);
            $stmt->bindValue(':tax_rate', $orderData['tax_rate'] ?? 19);
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

    private function createOrderItems(int $orderId, array $items): void {
        $query = "INSERT INTO purchase_order_items (
            purchase_order_id, purchasable_product_id, quantity, unit_price, total_price, notes
        ) VALUES (
            :purchase_order_id, :purchasable_product_id, :quantity, :unit_price, :total_price, :notes
        )";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($items as $item) {
            $totalPrice = $item['quantity'] * $item['unit_price'];
            
            $stmt->execute([
                ':purchase_order_id' => $orderId,
                ':purchasable_product_id' => $item['purchasable_product_id'],
                ':quantity' => $item['quantity'],
                ':unit_price' => $item['unit_price'],
                ':total_price' => $totalPrice,
                ':notes' => $item['notes'] ?? null
            ]);
        }
    }

    public function updateOrderStatus(int $orderId, string $newStatus): bool {
        $allowedStatuses = ['draft', 'sent', 'confirmed', 'partial_delivery', 'delivered', 'cancelled', 'returned', 'completed'];
        
        if (!in_array($newStatus, $allowedStatuses)) {
            return false;
        }
        
        try {
            $query = "UPDATE {$this->table} SET status = :status, updated_at = NOW() WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute([
                ':status' => $newStatus,
                ':id' => $orderId
            ]);
            
            if ($result && function_exists('logActivity')) {
                logActivity(
                    $_SESSION['user_id'],
                    'update',
                    'purchase_order',
                    $orderId,
                    "Status updated to: {$newStatus}"
                );
            }
            
            return $result;
        } catch (Exception $e) {
            error_log("Error updating order status: " . $e->getMessage());
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
}