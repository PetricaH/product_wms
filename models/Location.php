<?php

// File: models/Location.php - Updated for locations page functionality
class Location {
    private $conn;
    private $table = "locations";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Get total count of locations with filters
     * @param string $zoneFilter Filter by zone
     * @param string $typeFilter Filter by type  
     * @param string $search Search in location codes
     * @return int Total count
     */
    public function getTotalCount($zoneFilter = '', $typeFilter = '', $search = '') {
        $query = "SELECT COUNT(*) as total FROM {$this->table} WHERE 1=1";
        $params = [];
        
        if (!empty($zoneFilter)) {
            $query .= " AND zone = :zone";
            $params[':zone'] = $zoneFilter;
        }
        
        if (!empty($typeFilter)) {
            $query .= " AND type = :type";
            $params[':type'] = $typeFilter;
        }
        
        if (!empty($search)) {
            $query .= " AND location_code LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }
        
        try {
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['total'] ?? 0);
        } catch (PDOException $e) {
            error_log("Error getting total count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get locations with pagination and filtering
     * @param int $pageSize Number of locations per page
     * @param int $offset Starting offset
     * @param string $zoneFilter Filter by zone
     * @param string $typeFilter Filter by type
     * @param string $search Search in location codes
     * @return array
     */
    public function getLocationsPaginated($pageSize, $offset, $zoneFilter = '', $typeFilter = '', $search = '') {
        $query = "SELECT l.*, 
                         COALESCE(SUM(i.quantity), 0) as total_items
                  FROM {$this->table} l
                  LEFT JOIN inventory i ON l.id = i.location_id
                  WHERE 1=1";
        
        $params = [];
        
        if (!empty($zoneFilter)) {
            $query .= " AND l.zone = :zone";
            $params[':zone'] = $zoneFilter;
        }
        
        if (!empty($typeFilter)) {
            $query .= " AND l.type = :type";
            $params[':type'] = $typeFilter;
        }
        
        if (!empty($search)) {
            $query .= " AND l.location_code LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }
        
        $query .= " GROUP BY l.id ORDER BY l.zone, l.location_code LIMIT :limit OFFSET :offset";
        
        try {
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting paginated locations: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all locations with inventory information and filtering
     * @param string $zoneFilter Filter by zone
     * @param string $typeFilter Filter by type
     * @param string $search Search in location codes
     * @return array
     */
    public function getLocationsWithInventory($zoneFilter = '', $typeFilter = '', $search = '') {
        $query = "SELECT l.*, 
                         COALESCE(SUM(i.quantity), 0) as total_items
                  FROM {$this->table} l
                  LEFT JOIN inventory i ON l.id = i.location_id
                  WHERE 1=1";
        
        $params = [];
        
        if (!empty($zoneFilter)) {
            $query .= " AND l.zone = :zone";
            $params[':zone'] = $zoneFilter;
        }
        
        if (!empty($typeFilter)) {
            $query .= " AND l.type = :type";
            $params[':type'] = $typeFilter;
        }
        
        if (!empty($search)) {
            $query .= " AND l.location_code LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }
        
        $query .= " GROUP BY l.id ORDER BY l.zone, l.location_code";
        
        try {
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting locations with inventory: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all locations
     * @return array
     */
    public function getAllLocations() {
        $query = "SELECT * FROM {$this->table} ORDER BY zone, location_code";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting all locations: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get location by ID
     * @param int $locationId
     * @return array|false
     */
    public function getLocationById($locationId) {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $locationId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting location by ID: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get location by code
     * @param string $locationCode
     * @return array|false
     */
    public function getLocationByCode($locationCode) {
        $query = "SELECT * FROM {$this->table} WHERE location_code = :code";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':code', $locationCode);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting location by code: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create a new location
     * @param array $locationData
     * @return int|false Location ID on success, false on failure
     */
    public function createLocation(array $locationData) {
        $query = "INSERT INTO {$this->table} 
                  (location_code, zone, type, capacity, notes, status, created_at) 
                  VALUES (:location_code, :zone, :type, :capacity, :notes, :status, NOW())";
        
        try {
            // Check if location code already exists
            if ($this->getLocationByCode($locationData['location_code'])) {
                return false;
            }
            
            // Convert status to enum string
            $statusMap = [0 => 'inactive', 1 => 'active', 2 => 'maintenance'];
            $status = $statusMap[$locationData['status'] ?? 1] ?? 'active';
            
            $stmt = $this->conn->prepare($query);
            $params = [
                ':location_code' => $locationData['location_code'],
                ':zone' => $locationData['zone'],
                ':type' => $locationData['type'] ?: 'shelf',
                ':capacity' => $locationData['capacity'] ?: 0,
                ':notes' => $locationData['description'] ?? '',
                ':status' => $status
            ];
            
            $success = $stmt->execute($params);
            return $success ? $this->conn->lastInsertId() : false;
        } catch (PDOException $e) {
            error_log("Error creating location: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update an existing location
     * @param int $locationId
     * @param array $locationData
     * @return bool
     */
    public function updateLocation($locationId, array $locationData) {
        // Get current location first
        $currentLocation = $this->getLocationById($locationId);
        if (!$currentLocation) {
            error_log("DEBUG: Location $locationId not found");
            return false;
        }
        
        // Only check for duplicates if location code is actually changing
        if ($currentLocation['location_code'] !== $locationData['location_code']) {
            $existing = $this->getLocationByCode($locationData['location_code']);
            if ($existing && $existing['id'] != $locationId) {
                error_log("DEBUG: Duplicate location code detected");
                return false;
            }
        }
        
        try {
            // Convert status from integer to enum string
            $statusMap = [0 => 'inactive', 1 => 'active', 2 => 'maintenance'];
            $status = $statusMap[$locationData['status'] ?? 1] ?? 'active';
            
            $query = "UPDATE {$this->table} 
                      SET location_code = :location_code, 
                          zone = :zone, 
                          type = :type, 
                          capacity = :capacity, 
                          notes = :notes, 
                          status = :status, 
                          updated_at = NOW()
                      WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            $params = [
                ':id' => $locationId,
                ':location_code' => $locationData['location_code'],
                ':zone' => $locationData['zone'],
                ':type' => $locationData['type'] ?: 'shelf',
                ':capacity' => $locationData['capacity'] ?: 0,
                ':notes' => $locationData['description'] ?? '',
                ':status' => $status
            ];
            
            error_log("DEBUG: Query: $query");
            error_log("DEBUG: Params: " . print_r($params, true));
            
            $result = $stmt->execute($params);
            $rowCount = $stmt->rowCount();
            
            error_log("DEBUG: Execute result: " . ($result ? 'true' : 'false'));
            error_log("DEBUG: Rows affected: $rowCount");
            
            // Return true only if at least 1 row was affected
            return $result && $rowCount > 0;
        } catch (PDOException $e) {
            error_log("Error updating location: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete a location
     * @param int $locationId
     * @return bool
     */
    public function deleteLocation($locationId) {
        try {
            // Check if location has inventory
            if ($this->hasInventory($locationId)) {
                return false; // Cannot delete location with inventory
            }
            
            $query = "DELETE FROM {$this->table} WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $locationId, PDO::PARAM_INT);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error deleting location: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if location has inventory
     * @param int $locationId
     * @return bool
     */
    public function hasInventory($locationId) {
        $query = "SELECT COUNT(*) as count FROM inventory WHERE location_id = :location_id AND quantity > 0";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':location_id', $locationId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['count'] > 0;
        } catch (PDOException $e) {
            error_log("Error checking location inventory: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get unique zones
     * @return array
     */
    public function getZones() {
        $query = "SELECT DISTINCT zone FROM {$this->table} WHERE zone IS NOT NULL AND zone != '' ORDER BY zone";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Error getting zones: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get unique types
     * @return array
     */
    public function getTypes() {
        $query = "SELECT DISTINCT type FROM {$this->table} WHERE type IS NOT NULL AND type != '' ORDER BY type";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Error getting types: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get locations by zone
     * @param string $zone
     * @return array
     */
    public function getLocationsByZone($zone) {
        $query = "SELECT * FROM {$this->table} WHERE zone = :zone ORDER BY location_code";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':zone', $zone);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting locations by zone: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get active locations
     * @return array
     */
    public function getActiveLocations() {
        $query = "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY zone, location_code";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting active locations: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get location occupancy statistics
     * @param int $locationId
     * @return array
     */
    public function getLocationOccupancy($locationId) {
        $query = "SELECT l.capacity, 
                         COALESCE(SUM(i.quantity), 0) as current_items,
                         COUNT(DISTINCT i.product_id) as unique_products
                  FROM {$this->table} l
                  LEFT JOIN inventory i ON l.id = i.location_id
                  WHERE l.id = :location_id
                  GROUP BY l.id, l.capacity";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':location_id', $locationId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting location occupancy: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update location status
     * @param int $locationId
     * @param int $status
     * @return bool
     */
    public function updateStatus($locationId, $status) {
        $query = "UPDATE {$this->table} SET status = :status, updated_at = NOW() WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $locationId, PDO::PARAM_INT);
            $stmt->bindValue(':status', $status, PDO::PARAM_INT);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error updating location status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get locations with low capacity (over 80% full)
     * @return array
     */
    public function getHighOccupancyLocations() {
        $query = "SELECT l.*, 
                         l.capacity,
                         COALESCE(SUM(i.quantity), 0) as current_items,
                         CASE 
                             WHEN l.capacity > 0 THEN (COALESCE(SUM(i.quantity), 0) / l.capacity) * 100
                             ELSE 0
                         END as occupancy_percentage
                  FROM {$this->table} l
                  LEFT JOIN inventory i ON l.id = i.location_id
                  WHERE l.capacity > 0 AND l.status = 1
                  GROUP BY l.id
                  HAVING occupancy_percentage >= 80
                  ORDER BY occupancy_percentage DESC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting high occupancy locations: " . $e->getMessage());
            return [];
        }
    }

    /**
 * Calculate warehouse occupation percentage
 * @return float Percentage of occupied locations
 */
public function calculateOccupationPercentage(): float {
    try {
        $totalLocations = $this->countTotalLocations();
        $occupiedLocations = $this->countOccupiedLocations();
        
        if ($totalLocations == 0) {
            return 0.0;
        }
        
        return round(($occupiedLocations / $totalLocations) * 100, 1);
        
    } catch (PDOException $e) {
        error_log("Error calculating occupation percentage: " . $e->getMessage());
        return 0.0;
    }
}

/**
 * Count total locations
 * @return int Total number of locations
 */
public function countTotalLocations(): int {
    try {
        $query = "SELECT COUNT(*) FROM {$this->table}";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
        
    } catch (PDOException $e) {
        error_log("Error counting total locations: " . $e->getMessage());
        return 0;
    }
}

/**
 * Count occupied locations (locations with inventory)
 * @return int Number of occupied locations
 */
public function countOccupiedLocations(): int {
    try {
        $query = "SELECT COUNT(DISTINCT i.location_id) 
                  FROM inventory i 
                  INNER JOIN {$this->table} l ON i.location_id = l.id 
                  WHERE i.quantity > 0";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
        
    } catch (PDOException $e) {
        error_log("Error counting occupied locations: " . $e->getMessage());
        return 0;
    }
}
}