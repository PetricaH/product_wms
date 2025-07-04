<?php
/**
 * CORRECTED - Enhanced Order Model with AWB Support
 * File: models/Order.php
 */

class Order {
    private $conn;
    private $ordersTable = "orders";
    private $orderItemsTable = "order_items";
    private $productsTable = "products";

    // Order statuses corrected to match database ENUM
    public const STATUS_PENDING = 'Pending';
    public const STATUS_PROCESSING = 'Processing';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_CANCELLED = 'Cancelled';
    public const STATUS_SHIPPED = 'Shipped';

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Create a new order.
     * @param array $orderData Main order data
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

            if (empty($orderData['order_number'])) {
                $orderData['order_number'] = $this->generateOrderNumber();
            }

            // --- ALL FIELDS FOR INSERT ---
            $allFields = [
                'order_number', 'type', 'status', 'priority', 'customer_name', 'customer_email',
                'shipping_address', 'order_date', 'required_date', 'shipped_date',
                'total_value', 'notes', 'assigned_to', 'created_by', 'recipient_county_id',
                'recipient_county_name', 'recipient_locality_id', 'recipient_locality_name',
                'recipient_street_id', 'recipient_street_name', 'recipient_building_number',
                'recipient_contact_person', 'recipient_phone', 'recipient_email', 'total_weight',
                'declared_value', 'parcels_count', 'envelopes_count', 'cash_repayment',
                'bank_repayment', 'saturday_delivery', 'morning_delivery', 'open_package',
                'observations', 'package_content', 'sender_reference1', 'recipient_reference1',
                'recipient_reference2', 'invoice_reference', 'sender_location_id'
            ];

            // --- SET DEFAULTS ---
            $orderData['type'] = $orderData['type'] ?? 'outbound';
            $orderData['status'] = $orderData['status'] ?? self::STATUS_PENDING;
            $orderData['order_date'] = $orderData['order_date'] ?? date('Y-m-d H:i:s');
            // Assuming created_by is the logged-in user
            $orderData['created_by'] = $orderData['created_by'] ?? $_SESSION['user_id'] ?? 1;


            $providedFields = [];
            $placeholders = [];
            $values = [];

            foreach ($allFields as $field) {
                if (array_key_exists($field, $orderData) && $orderData[$field] !== '') {
                    $providedFields[] = "`$field`";
                    $placeholders[] = ":$field";
                    $values[":$field"] = $orderData[$field];
                }
            }

            $orderQuery = "INSERT INTO {$this->ordersTable} (" . implode(', ', $providedFields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $orderStmt = $this->conn->prepare($orderQuery);
            $orderStmt->execute($values);

            $orderId = $this->conn->lastInsertId();

            // Insert order items
            // CORRECTED: 'quantity' column is used instead of 'quantity_ordered'
            $itemQuery = "INSERT INTO {$this->orderItemsTable} (order_id, product_id, quantity_ordered, unit_price, picked_quantity) VALUES (:order_id, :product_id, :quantity_ordered, :unit_price, 0)";
            $itemStmt = $this->conn->prepare($itemQuery);

            foreach ($items as $item) {
                $itemStmt->execute([
                    ':order_id' => $orderId,
                    ':product_id' => $item['product_id'],
                    ':quantity_ordered' => $item['quantity'], // CORRECTED
                    ':unit_price' => $item['unit_price'] ?? 0
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
     * Update an existing order.
     * @param int $orderId The ID of the order to update.
     * @param array $orderData The data to update.
     * @return bool True on success, false on failure.
     */
    public function update(int $orderId, array $orderData): bool {
        if (empty($orderData)) {
            return false;
        }

        // Map front-end names to DB columns if necessary
        if(isset($orderData['tracking_number'])) {
            $orderData['awb_barcode'] = $orderData['tracking_number'];
            unset($orderData['tracking_number']);
        }

        $fields = [];
        $params = [':order_id' => $orderId];

        foreach ($orderData as $field => $value) {
            $fields[] = "`$field` = :$field";
            $params[":$field"] = $value;
        }

        try {
            $query = "UPDATE {$this->ordersTable} SET " . implode(', ', $fields) . " WHERE id = :order_id";
            $stmt = $this->conn->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error updating order: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete an order.
     * @param int $orderId The ID of the order to delete.
     * @return bool True on success, false on failure.
     */
    public function delete(int $orderId): bool {
        try {
            // Because of `ON DELETE CASCADE` in the order_items table,
            // we only need to delete the order itself.
            $query = "DELETE FROM {$this->ordersTable} WHERE id = :order_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error deleting order: " . $e->getMessage());
            return false;
        }
    }


    /**
     * Get order by ID with full details.
     * @param int $orderId Order ID
     * @return array|null Order data with items
     */
    /**
     * Get order by ID with full details.
     * @param int $orderId Order ID
     * @return array|null Order data with items
     */
    public function getOrderById(int $orderId): ?array {
        try {
            // Get main order data
            $orderQuery = "SELECT o.*, COALESCE(o.awb_barcode, '') AS tracking_number
                        FROM {$this->ordersTable} o
                        WHERE o.id = :order_id";

            $orderStmt = $this->conn->prepare($orderQuery);
            $orderStmt->execute([':order_id' => $orderId]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                return null;
            }

            // Get order items with correct aliases for the front-end
            $itemsQuery = "SELECT
                            oi.quantity AS quantity_ordered,
                            oi.picked_quantity,
                            oi.unit_price,
                            (oi.quantity_ordered * oi.unit_price) AS line_total,
                            p.name AS product_name,
                            p.sku
                        FROM {$this->orderItemsTable} oi
                        JOIN {$this->productsTable} p ON oi.product_id = p.product_id
                        WHERE oi.order_id = :order_id";

            $itemsStmt = $this->conn->prepare($itemsQuery);
            $itemsStmt->execute([':order_id' => $orderId]);
            $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            return $order;

        } catch (PDOException $e) {
            error_log("Error fetching order by ID: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all orders with optional filters.
     * @param array $filters Optional filters
     * @return array Array of orders
     */
    public function getAllOrders(array $filters = []): array {
        $query = "SELECT
                    o.id, o.order_number, o.customer_name, o.customer_email, o.order_date,
                    o.status, o.total_value, 
                    COALESCE(o.awb_barcode, '') AS tracking_number,
                    COALESCE((
                        SELECT COUNT(*)
                        FROM order_items oi
                        WHERE oi.order_id = o.id
                    ), 0) as item_count,
                    COALESCE((
                        SELECT SUM(oi.quantity)
                        FROM order_items oi
                        WHERE oi.order_id = o.id
                    ), 0) as total_items,
                    COALESCE((
                        SELECT SUM(COALESCE(oi.picked_quantity, 0))
                        FROM order_items oi
                        WHERE oi.order_id = o.id
                    ), 0) as picked_items,
                    COALESCE((
                        SELECT SUM(oi.quantity)
                        FROM order_items oi
                        WHERE oi.order_id = o.id
                    ), 0) as total_quantity,
                    COALESCE((
                        SELECT SUM(COALESCE(oi.picked_quantity, 0))
                        FROM order_items oi
                        WHERE oi.order_id = o.id
                    ), 0) as picked_quantity
                FROM {$this->ordersTable} o
                WHERE o.type = 'outbound'";
    
        $params = [];
    
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
    
        $query .= " ORDER BY o.order_date DESC";
    
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Ensure all numeric fields are properly set to prevent null errors
            return array_map(function($order) {
                return array_merge($order, [
                    'item_count' => (int)($order['item_count'] ?? 0),
                    'total_items' => (int)($order['total_items'] ?? 0),
                    'picked_items' => (int)($order['picked_items'] ?? 0),
                    'total_quantity' => (int)($order['total_quantity'] ?? 0),
                    'picked_quantity' => (int)($order['picked_quantity'] ?? 0),
                    'total_value' => (float)($order['total_value'] ?? 0)
                ]);
            }, $results);
            
        } catch (PDOException $e) {
            error_log("Error fetching all orders: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get available order statuses with Romanian translations.
     * @return array Array of status options
     */
    public function getStatuses(): array {
        return [
            self::STATUS_PENDING => 'În Așteptare',
            self::STATUS_PROCESSING => 'În Procesare',
            self::STATUS_SHIPPED => 'Expediat',
            self::STATUS_COMPLETED => 'Finalizat',
            self::STATUS_CANCELLED => 'Anulat',
        ];
    }
    
    /**
     * Generate a unique order number.
     * @return string Generated order number
     */
    private function generateOrderNumber(): string {
        $prefix = 'CMD';
        $date = date('Ymd');
        $sequence = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        return "{$prefix}-{$date}-{$sequence}";
    }

    public function countActiveOrders(): int {
        try {
            $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->ordersTable} WHERE status IN ('pending', 'processing')");
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("Error counting active errors: " . $e->getMessage());
            return 0;
        }
    }

    public function countShippedToday(): int {
        try {
            $today = date('Y-m-d');
            $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->ordersTable} WHERE status = 'shipped' AND DATE(shipped_date) = ?");
            $stmt->execute([$today]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error counting shipped orders today: " . $e->getMessage());
            return 0;
        }
    }
}