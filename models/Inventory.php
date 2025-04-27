<?php

class Inventory {
    private $conn;

    private $table = "inventory";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getTotalItemCount(): int {
        $query = "SELECT SUM(quantity) as total_items FROM " . $this->table . " WHERE quantity > 0";
        
        try {
            $stmt = $this->conn->prepare($query);

            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return isset($row['total_items']) ? (int)$row['total_items'] : 0;
        } catch(PDOException $e) {
            error_log("Error getting total item count: " . $e->getMessage());
            return 0;
        }
    }
}