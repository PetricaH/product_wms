<?php 

class Location {
    private $conn;

    // the name of the database table for locations
    private $locationsTable = "locations";

    // the name of the database table for inventory
    private $inventoryTable = "inventory";

    // constructor to initialize the database connection
    public function __construct($db) {
        $this->conn = $db;
    }

    // counts the total number of locations
    public function countTotalLocations(): int {
        $query = "SELECT COUNT(id) FROM " . $this->locationsTable;
        try {
            $stmt = $this->conn->prepare($query);
            return (int) $stmt->fetchColumn();
        }  catch (PDOException $e) {
            error_log("Error counting total locations: " . $e->getMessage());
            return 0;
        }
    }

    // counts the number of locations that currently hold any stock
    public function countOccupiedLocations(): int {
        $query = "SELECT COUNT(DISTINCT location_id)
                    FROM " . $this->inventoryTable . "
                    WHERE quantity > 0";
        try {
            $stmt = $this->conn->prepare($query);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error counting occupied locations: " . $e->getMessage());
            return 0;
        }
    }

    // calculates the warehouse occupation percentage based on the count of occupied locations
    public function calculateOccupationPercentage(): float {
        $totalLocations = $this->countTotalLocations();
        if ($totalLocations === 0) {
            return 0.0;
        }
        $occupiedLocations = $this->countOccupiedLocations();

        $percentage = ((float)$occupiedLocations / $totalLocations) * 100;
        return round($percentage, 2);
    }
}