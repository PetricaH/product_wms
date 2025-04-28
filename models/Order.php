<?php
class Order {
    private $conn;

    private $ordersTable = "orders";

    private $orderItemsTable = "order_items";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function countActiveOrders(): int {
        $activeStatuses = ['Pending', 'Processing', 'Awaiting Shipment', 'On Hold'];

        $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));

        $query = "SELECT COUNT(id) as active_count
                  FROM " . $this->ordersTable . "
                  WHERE status IN (" . $placeholders . ")";

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
}