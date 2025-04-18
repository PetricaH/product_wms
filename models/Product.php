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

        // prepare the query so it prevents sql injection
        $stmt = $this->conn->prepare($query);

        // after the query is prepared, execute it
        $stmt->execute();

        // return the result
        return $stmt;
    }

    public function countAll() {
        // fist stem is to create an sql to query to count all produccts
        $query = 'SELECT COUNT(*) as total FROM ' . $this->table;

        // preparte query statement
        $stmt = $this->conn->prepare($query);

        // execute query
        $stmt->execute();

        // fetch the result 
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // return the count
        return $row['total'] ?? 0;
    }
}

