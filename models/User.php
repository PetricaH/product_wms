<?php

class Users {
    // database connection and table name
    private $conn;
    
    // database table name
    private $table = "users";

    // now add a constructor to initialize the connection
    public function __construct($db) {
        $this->conn = $db;        
    } 

    public function getAllUsers() {
        // fist create an sql query to select all users
        $query = 'SELECT * FROM ' . $this->table;

        // prepare the query so it prevents sql injection
        $stmt = $this->conn->prepare($query);

        // after the query is prepared, execute
        return $stmt;
    }

    public function countAllUsers() {
        // first step is to create an sql to query to count all users
        $query = 'SELECT COUNT(*) AS total FROM ' . $this->table;

        // prepare the query statement
        $stmt = $this->conn->prepare($query);

        // execute query
        $stmt->execute();

        // fetch the result 
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // return the count
        return $row['total'] ?? 0;
    }
}