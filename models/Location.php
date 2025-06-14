<?php
/**
 * Enhanced Location Model with full CRUD operations
 * Manages warehouse locations with zone/type hierarchy
 */

class Location {
    private $conn;
    private $locationsTable = "locations";
    private $inventoryTable = "inventory";

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Get all locations with inventory occupancy data
     * @return array Array of location records with occupancy info
     */
    public function getAllLocations(): array {
        $query = "SELECT l.*, 
                         COUNT(DISTINCT i.product_id) as product_count,
                         COALESCE(SUM(i.quantity), 0) as total_items
                  FROM {$this->locationsTable} l
                  LEFT JOIN {$this->inventoryTable} i ON l.id = i.location_id AND i.quantity > 0
                  GROUP BY l.id
                  ORDER BY l.zone ASC, l.location_code ASC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching all locations: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a single location by ID
     * @param int $id Location ID
     * @return array|false Location data or false if not found
     */
    public function findById(int $id) {
        $query = "SELECT l.*, 
                         COUNT(DISTINCT i.product_id) as product_count,
                         COALESCE(SUM(i.quantity), 0) as total_items
                  FROM {$this->locationsTable} l
                  LEFT JOIN {$this->inventoryTable} i ON l.id = i.location_id AND i.quantity > 0
                  WHERE l.id = :id
                  GROUP BY l.id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            $location = $stmt->fetch(PDO::FETCH_ASSOC);
            return $location ?: false;
        } catch (PDOException $e) {
            error_log("Error finding location by ID: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new location
     * @param array $data Location data
     * @return int|false Location ID on success, false on failure
     */
    public function create(array $data): int|false {
        if (empty($data['location_code']) || empty($data['zone']) || empty($data['type'])) {
            error_log("Location creation failed: Required fields missing");
            return false;
        }

        // Check if location code already exists
        if ($this->locationCodeExists($data['location_code'])) {
            error_log("Location creation failed: Location code already exists");
            return false;
        }

        $query = "INSERT INTO {$this->locationsTable} (location_code, zone, type) 
                  VALUES (:location_code, :zone, :type)";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':location_code', $data['location_code'], PDO::PARAM_STR);
            $stmt->bindParam(':zone', $data['zone'], PDO::PARAM_STR);
            $stmt->bindParam(':type', $data['type'], PDO::PARAM_STR);
            $stmt->execute();
            
            return (int)$this->conn->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error creating location: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a location
     * @param int $id Location ID
     * @param array $data Location data to update
     * @return bool Success status
     */
    public function update(int $id, array $data): bool {
        if (empty($data)) {
            return false;
        }

        // Check if location code is being changed and if it already exists
        if (isset($data['location_code']) && $this->locationCodeExists($data['location_code'], $id)) {
            error_log("Location update failed: Location code already exists");
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        $allowedFields = ['location_code', 'zone', 'type'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $query = "UPDATE {$this->locationsTable} SET " . implode(', ', $fields) . " WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error updating location: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a location (only if no inventory exists)
     * @param int $id Location ID
     * @return bool Success status
     */
    public function delete(int $id): bool {
        // Check if location has any inventory
        if ($this->hasInventory($id)) {
            error_log("Cannot delete location: Location has inventory");
            return false;
        }

        $query = "DELETE FROM {$this->locationsTable} WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error deleting location: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if location code already exists
     * @param string $locationCode Location code to check
     * @param int|null $excludeId Location ID to exclude from check (for updates)
     * @return bool True if exists, false otherwise
     */
    private function locationCodeExists(string $locationCode, int $excludeId = null): bool {
        $query = "SELECT COUNT(*) FROM {$this->locationsTable} WHERE location_code = :location_code";
        $params = [':location_code' => $locationCode];

        if ($excludeId !== null) {
            $query .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Error checking location code existence: " . $e->getMessage());
            return true; // Assume exists to prevent duplicates
        }
    }

    /**
     * Check if location has any inventory
     * @param int $locationId Location ID
     * @return bool True if has inventory, false otherwise
     */
    private function hasInventory(int $locationId): bool {
        $query = "SELECT COUNT(*) FROM {$this->inventoryTable} WHERE location_id = :location_id AND quantity > 0";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':location_id', $locationId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Error checking location inventory: " . $e->getMessage());
            return true; // Assume has inventory to prevent deletion
        }
    }

    /**
     * Get unique zones for dropdown/filtering
     * @return array Array of zone names
     */
    public function getZones(): array {
        $query = "SELECT DISTINCT zone FROM {$this->locationsTable} ORDER BY zone ASC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Error fetching zones: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get unique types for dropdown/filtering
     * @return array Array of type names
     */
    public function getTypes(): array {
        $query = "SELECT DISTINCT type FROM {$this->locationsTable} ORDER BY type ASC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Error fetching types: " . $e->getMessage());
            return [];
        }
    }

    // Dashboard methods (existing)
    public function countTotalLocations(): int {
        $query = "SELECT COUNT(id) FROM " . $this->locationsTable;
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error counting total locations: " . $e->getMessage());
            return 0;
        }
    }

    public function countOccupiedLocations(): int {
        $query = "SELECT COUNT(DISTINCT location_id)
                    FROM " . $this->inventoryTable . "
                    WHERE quantity > 0";
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error counting occupied locations: " . $e->getMessage());
            return 0;
        }
    }

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