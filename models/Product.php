<?php 
class Product {
    // database connection property
    private $conn;

    // databas table name
    private $table = "products";

    // now add a constructor to initialize the connection
    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAll() {
        // first step is to create an sql query to select all products
        $query = 'SELECT * FROM ' . $this->table;

        try {
            // prepare the query so it prevents sql injection
            $stmt = $this->conn->prepare($query);

            // after the query is prepared, execute it
            $stmt->execute();

            // return the result
            return $stmt;
        } catch (PDOException $e) {
            error_log("Error fetching all products: " . $e->getMessage());

            return false;
        }
    }

    public function countAll() {

        $query = 'SELECT COUNT(*) as total FROM ' . $this->table;

        try {
            // preparte query statement
            $stmt = $this->conn->prepare($query);

            // execute query
            $stmt->execute();

            // fetch the result 
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // return the count
            return isset($row['total']) ? (int)$row['total'] : 0;
        } catch (PDOException $e) {
            error_log("Error counting products: " . $e->getMessage());
            return 0;
        }
    }

    // calculates the sum of quantities for all products
    public function getTotalQuantity(): int {
        $query = 'SELECT SUM(quantity) as total_stock FROM ' . $this->table;

        try {
            $stmt = $this->conn->prepare($query);

            // execute the query
            $stmt->execute();

            // fetch the result
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // return the total quantity
            return isset($row['total_stock']) ? (int)$row['total_stock'] : 0;
        } catch (PDOException $e) {
            error_log("Error summing total product quantity: " . $e->getMessage());
            return 0;
        }
    }
}

