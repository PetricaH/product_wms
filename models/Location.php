<?php

// File: models/Location.php - Updated for locations page functionality
require_once __DIR__ . '/Setting.php';
require_once __DIR__ . '/LocationLevelSettings.php';

class Location {
    protected $conn;
    private $table = "locations";
    private LocationLevelSettings $levelSettings;

    // Default geometry constants used when dimensions are missing
    private const DEFAULT_LENGTH_MM = 1200;
    private const DEFAULT_DEPTH_MM = 800;
    private const STANDARD_LEVELS = 3;

    private function getLevelCapacity(array $location = []): int {
        $length = (int)($location['length_mm'] ?? self::DEFAULT_LENGTH_MM);
        $depth  = (int)($location['depth_mm'] ?? self::DEFAULT_DEPTH_MM);

        // Fallback pallet calculation using global settings
        $settingsModel = new Setting($this->conn);
        $pallets = (int)$settingsModel->get('pallets_per_level') ?: 1;
        $barrelsPerPallet = (int)$settingsModel->get('barrels_per_pallet_25l') ?: 1;

        $perRow = $pallets; // treat pallets_per_level as number of pallets that fit per level
        return $perRow * $barrelsPerPallet;
    }
    
    public function __construct($db) {
        $this->conn = $db;
        $this->levelSettings = new LocationLevelSettings($this->conn);
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
    
    // FUNCTION TO CREATE A LOCATION IN LOCATIONS.PHP START

    public function createLocation(array $locationData): int|false {
        try {
            $this->conn->beginTransaction();

            if ($this->getLocationByCode($locationData['location_code'])) {
                return false;
            }

            $query = "INSERT INTO {$this->table}
                        (location_code, zone, type, levels, capacity, length_mm, depth_mm, height_mm, max_weight_kg, notes, status, created_at)
                        VALUES (:location_code, :zone, :type, :levels, :capacity, :length_mm, :depth_mm, :height_mm, :max_weight_kg, :notes, :status, NOW())";
            
            $statusMap = [0 => 'inactive', 1 => 'active', 2 => 'maintenance'];
            $status = $statusMap[$locationData['status'] ?? 1] ?? 'active';

            $stmt = $this->conn->prepare($query);
            $params = [
                ':location_code' => $locationData['location_code'],
                ':zone' => $locationData['zone'],
                ':type' => $locationData['type'] ?? 'shelf',
                ':levels' => $locationData['levels'] ?? 3,
                ':capacity' => $locationData['capacity'] ?? 0,
                ':length_mm' => $locationData['length_mm'] ?? 0,
                ':depth_mm' => $locationData['depth_mm'] ?? 0,
                ':height_mm' => $locationData['height_mm'] ?? 0,
                ':max_weight_kg' => $locationData['max_weight_kg'] ?? 0,
                ':notes' => $locationData['description'] ?? '',
                ':status' => $status 
            ];

            if (!$stmt->execute($params)) {
                throw new Exception("Database error: faild to insert new location.");
            }

            $locationId = (int)$this->conn->lastInsertId();

            if ($locationId === 0) {
                throw new Exception("Failed to retrieve ID for new location.");
            }

            $levels = $locationData['levels'] ?? 3;
            if (!$this->levelSettings->createDefaultSettings($locationId, $levels)) {
                throw new Exception("failed to create default level settings.");
            }

            // if everythging succeded, commit the changes
            $this->conn->commit();
            return $locationId;

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error in createLocation transaction: " . $e->getMessage());
            return false;
        }
    }

    // FUNCTION TO CREATE A LOCATION IN LOCATIONS.PHP END
    
    // FUNCTION TO UPDATE A LOCATION IN LOCATIONS.PHP START

    public function updateLocation(int $locationId, array $locationData): bool {
        // Wrap the entire operation in a transaction for safety.
        // If any part fails, the entire update is cancelled.
        try {
            $this->conn->beginTransaction();
    
            // 1. UPDATE THE MAIN LOCATION DATA
            $statusMap = [0 => 'inactive', 1 => 'active', 2 => 'maintenance'];
            $status = $statusMap[$locationData['status'] ?? 1] ?? 'active';
    
            $query = "UPDATE {$this->table}
                      SET location_code = :location_code,
                          zone = :zone,
                          type = :type,
                          levels = :levels,
                          capacity = :capacity,
                          length_mm = :length_mm,
                          depth_mm = :depth_mm,
                          height_mm = :height_mm,
                          max_weight_kg = :max_weight_kg,
                          notes = :notes,
                          status = :status,
                          updated_at = NOW()
                      WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            
            $params = [
                ':id' => $locationId,
                ':location_code' => $locationData['location_code'] ?? '',
                ':zone' => $locationData['zone'] ?? '',
                ':type' => $locationData['type'] ?? 'shelf',
                ':levels' => $locationData['levels'] ?? 3,
                ':capacity' => $locationData['capacity'] ?? 0,
                ':length_mm' => $locationData['length_mm'] ?? 0,
                ':depth_mm' => $locationData['depth_mm'] ?? 0,
                ':height_mm' => $locationData['height_mm'] ?? 0,
                ':max_weight_kg' => $locationData['max_weight_kg'] ?? 0,
                ':notes' => $locationData['description'] ?? '',
                ':status' => $status
            ];
    
            // Execute the main update and throw an error if it fails.
            if (!$stmt->execute($params)) {
                throw new Exception("Failed to update base location data for ID: $locationId");
            }
    
            // 2. UPDATE THE INDIVIDUAL LEVEL SETTINGS (if they were submitted)
            if (isset($locationData['level_settings']) && is_array($locationData['level_settings'])) {
                foreach ($locationData['level_settings'] as $levelNum => $settings) {
                    if (!$this->levelSettings->updateLevelSettings($locationId, $levelNum, $settings)) {
                        throw new Exception("Failed to update settings for level $levelNum on location ID: $locationId");
                    }
                }
            }
            
            // 3. HANDLE CHANGES TO THE NUMBER OF LEVELS
            if (isset($locationData['levels'])) {
                $currentLevels = $this->getCurrentLevelsCount($locationId);
                $newLevels = intval($locationData['levels']);
                
                if ($newLevels > $currentLevels) {
                    // Add new default settings for the new levels
                    for ($level = $currentLevels + 1; $level <= $newLevels; $level++) {
                        $defaultSettings = $this->getDefaultLevelSettings($level, $newLevels);
                        $this->levelSettings->updateLevelSettings($locationId, $level, $defaultSettings);
                    }
                } elseif ($newLevels < $currentLevels) {
                    // Remove settings for levels that no longer exist
                    for ($level = $newLevels + 1; $level <= $currentLevels; $level++) {
                        $this->deleteLevelSettings($locationId, $level);
                    }
                }
            }
    
            // If everything was successful, commit the changes to the database.
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            // If any error occurred, roll back all changes.
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("Error in updateLocation transaction: " . $e->getMessage());
            return false;
        }
    }

    // HELPER FUNCTIONS FOR UPDATE LOCATION MAIN FUNCTION START

    private function getCurrentLevelsCount(int $locationId): int {
        $query = "SELECT MAX(level_number) FROM location_level_settings WHERE location_id = :location_id";

        try  {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':location_id' => $locationId]);
            return intval($stmt->fetchColumn()) ?: 3;
        } catch (PDOException $e) {
            return 3;
        }
    }

    private function getDefaultLevelSettings(int $levelNumber, int $totalLevels): array {
        return [
            'level_name' => match($levelNumber) {
                1 => 'Bottom',
                2 => 'Middle',
                3 => 'Top',
                default => "Level $levelNumber"
            },
            'storage_policy' => 'multiple_products',
            'length_mm' => 1000,
            'depth_mm' => 400,
            'height_mm' => 300,
            'max_weight_kg' => 50,
            'enable_auto_repartition' => false,
            'repartition_trigger_threshold' => 80,
            'priority_order' => $totalLevels - $levelNumber + 1
        ];
    }

    private function deleteLevelSettings(int $locationId, int $levelNumber): bool {
        $query = "DELETE FROM location_level_settings
                    WHERE location_id = :location_id AND level_number = :level_number";
        
        try {
            $stmt = $this->conn->prepare($query);
            return $stmt->execute([
                ':location_id' => $locationId,
                ':level_number' => $levelNumber
            ]);
        } catch (PDOException $e) {
            error_log("Error deleting level settings.");
            return false;
        }
    }

    private function getLevelName(int $levelNumber): string {
        return match($levelNumber) {
            1 => 'bottom',
            2 => 'middle',
            3 => 'top',
            default => 'middle'
        };
    }

    // HELPER FUNCTIONS FOR UPDATE LOCATION MAIN FUNCTION END 

    // FUNCTION TO UPDATE A LOCATION IN LOCATIONS.PHP END
        
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

/**
 * Get warehouse visualization data with level breakdown
 * @param string $zoneFilter
 * @param string $typeFilter  
 * @param string $search
 * @return array
 */
public function getWarehouseVisualizationData($zoneFilter = '', $typeFilter = '', $search = '') {
    $query = "SELECT 
                l.id,
                l.location_code,
                l.zone,
                l.type,
                l.capacity,
                l.status,
                l.notes,
                COALESCE(SUM(i.quantity), 0) as total_items,
                COALESCE(SUM(CASE WHEN i.shelf_level = 'bottom' THEN i.quantity ELSE 0 END), 0) as bottom_items,
                COALESCE(SUM(CASE WHEN i.shelf_level = 'middle' THEN i.quantity ELSE 0 END), 0) as middle_items,
                COALESCE(SUM(CASE WHEN i.shelf_level = 'top' THEN i.quantity ELSE 0 END), 0) as top_items,
                COUNT(DISTINCT i.product_id) as unique_products
              FROM {$this->table} l
              LEFT JOIN inventory i ON l.id = i.location_id
              WHERE l.status = 'active'";
    
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
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Process the results to add occupancy data
        foreach ($results as &$location) { // Use a reference '&' to modify the array directly
            $totalCapacity = (int)$location['capacity'];
            $levels = (int)($location['levels'] ?? self::STANDARD_LEVELS);
            $levelCapacity = $levels > 0 ? $totalCapacity / $levels : 0;
            
            $location['occupancy'] = [
                'total' => $totalCapacity > 0 ? round(($location['total_items'] / $totalCapacity) * 100, 1) : 0,
                'bottom' => $levelCapacity > 0 ? round(($location['bottom_items'] / $levelCapacity) * 100, 1) : 0,
                'middle' => $levelCapacity > 0 ? round(($location['middle_items'] / $levelCapacity) * 100, 1) : 0,
                'top' => $levelCapacity > 0 ? round(($location['top_items'] / $levelCapacity) * 100, 1) : 0
            ];
        }
        
        return $results;

    } catch (PDOException $e) {
        error_log("Error getting warehouse visualization data: " . $e->getMessage());
        return [];
    }
}

// FUNCTION TO GET LOCATION DETAILS START

public function getLocationDetails($locationId) {
    $query = "SELECT 
            l.*,
            COALESCE(SUM(i.quantity), 0) as total_items,
            COUNT(DISTINCT i.product_id) as unique_products
        FROM {$this->table} l
        LEFT JOIN inventory i on l.id = i.location_id
        WHERE l.id = :id
        GROUP BY l.id";
}

// FUNCTION TO GET LOCATION DETAILS END

/**
 * Update inventory level for a specific location and shelf level
 * @param int $locationId
 * @param string $level (bottom, middle, top)
 * @param int $productId
 * @param int $quantity
 * @return bool
 */
public function updateShelfLevel($locationId, $level, $productId, $quantity) {
    $validLevels = ['bottom', 'middle', 'top'];
    if (!in_array($level, $validLevels)) {
        return false;
    }
    
    $query = "INSERT INTO inventory (location_id, product_id, shelf_level, quantity, updated_at) 
              VALUES (:location_id, :product_id, :level, :quantity, NOW())
              ON DUPLICATE KEY UPDATE 
              quantity = :quantity, 
              updated_at = NOW()";
    
    try {
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':location_id', $locationId, PDO::PARAM_INT);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':level', $level);
        $stmt->bindValue(':quantity', $quantity, PDO::PARAM_INT);
        
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log("Error updating shelf level: " . $e->getMessage());
        return false;
    }
}

/**
 * Get dynamic zones extracted from location_code field
 * @return array Array of zones with statistics
 */
public function getDynamicZones() {
    $query = "SELECT 
                SUBSTRING_INDEX(location_code, '-', 1) as zone_name,
                COUNT(*) as shelf_count,
                AVG(capacity) as avg_capacity,
                SUM(capacity) as total_capacity,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_shelves
              FROM {$this->table} 
              WHERE type = 'Shelf' 
                AND status = 'active' 
                AND location_code LIKE '%-%'
              GROUP BY SUBSTRING_INDEX(location_code, '-', 1)
              ORDER BY zone_name";
    
    try {
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate occupancy for each zone (with safety check)
        foreach ($zones as &$zone) {
            try {
                $zoneOccupancy = $this->getZoneOccupancyStats($zone['zone_name']);
                $zone['avg_occupancy'] = $zoneOccupancy['avg_occupancy'];
                $zone['total_items'] = $zoneOccupancy['total_items'];
                $zone['max_occupancy'] = $zoneOccupancy['max_occupancy'];
                $zone['min_occupancy'] = $zoneOccupancy['min_occupancy'];
            } catch (Exception $e) {
                error_log("Error getting occupancy for zone {$zone['zone_name']}: " . $e->getMessage());
                // Set default values if occupancy calculation fails
                $zone['avg_occupancy'] = 0;
                $zone['total_items'] = 0;
                $zone['max_occupancy'] = 0;
                $zone['min_occupancy'] = 0;
            }
        }
        
        return $zones;
        
    } catch (PDOException $e) {
        error_log("Error getting dynamic zones: " . $e->getMessage());
        return [];
    }
}

/**
 * Get occupancy statistics for a specific zone
 * @param string $zoneName
 * @return array Zone occupancy statistics
 */
public function getZoneOccupancyStats($zoneName) {
    $query = "SELECT 
                l.id,
                l.capacity,
                COALESCE(SUM(i.quantity), 0) as total_items
              FROM {$this->table} l
              LEFT JOIN inventory i ON l.id = i.location_id
              WHERE l.zone = :zone_name 
                AND l.type = 'Shelf' 
                AND l.status = 'active'
              GROUP BY l.id, l.capacity";
    
    try {
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':zone_name', $zoneName);
        $stmt->execute();
        $shelves = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($shelves)) {
            return [
                'avg_occupancy' => 0,
                'total_items' => 0,
                'max_occupancy' => 0,
                'min_occupancy' => 0
            ];
        }
        
        $occupancies = [];
        $totalItems = 0;
        
        foreach ($shelves as $shelf) {
            $capacity = (int)$shelf['capacity'];
            $items = (int)$shelf['total_items'];
            $occupancy = $capacity > 0 ? ($items / $capacity) * 100 : 0;
            
            $occupancies[] = $occupancy;
            $totalItems += $items;
        }
        
        return [
            'avg_occupancy' => !empty($occupancies) ? round(array_sum($occupancies) / count($occupancies), 1) : 0,
            'total_items' => $totalItems,
            'max_occupancy' => !empty($occupancies) ? round(max($occupancies), 1) : 0,
            'min_occupancy' => !empty($occupancies) ? round(min($occupancies), 1) : 0
        ];
        
    } catch (PDOException $e) {
        error_log("Error getting zone occupancy stats: " . $e->getMessage());
        return [
            'avg_occupancy' => 0,
            'total_items' => 0,
            'max_occupancy' => 0,
            'min_occupancy' => 0
        ];
    }
}

/**
 * Get enhanced warehouse visualization data with dynamic zones
 * Enhanced version of existing getWarehouseVisualizationData
 * @param string $zoneFilter
 * @param string $typeFilter  
 * @param string $search
 * @return array
 */
public function getEnhancedWarehouseData($zoneFilter = '', $typeFilter = '', $search = '') {
    // Use existing method as base, then enhance the results
    $locations = $this->getWarehouseVisualizationData($zoneFilter, $typeFilter, $search);
    
    // Add enhanced data for each location
    foreach ($locations as &$location) {
        // Extract zone from location_code if zone is generic
        if (!empty($location['location_code']) && strpos($location['location_code'], '-') !== false) {
            $extractedZone = explode('-', $location['location_code'])[0];
            if ($location['zone'] === 'Marfa' || empty($location['zone'])) {
                $location['zone'] = strtoupper($extractedZone);
            }
        }
        
        // Add enhanced occupancy calculations
        $location = $this->enhanceLocationOccupancy($location);
        
        // Add level capacity information
        $levels = (int)($location['levels'] ?? self::STANDARD_LEVELS);
        $levelCapacity = $this->getLevelCapacity($location);
        $location['level_capacity'] = $levelCapacity;
        $location['capacity'] = $levelCapacity * $levels;
        
        // Add items per level for enhanced visualization
        $location['items'] = [
            'total' => (int)($location['total_items'] ?? 0),
            'bottom' => (int)($location['bottom_items'] ?? 0),
            'middle' => (int)($location['middle_items'] ?? 0),
            'top' => (int)($location['top_items'] ?? 0)
        ];
    }
    
    return $locations;
}

/**
 * Enhance location occupancy calculations
 * @param array $location
 * @return array Enhanced location data
 */
private function enhanceLocationOccupancy($location) {
    $totalCapacity = (int)($location['capacity'] ?? 0);
    $levels = (int)($location['levels'] ?? self::STANDARD_LEVELS);
    $levelCapacity = $levels > 0 ? $totalCapacity / $levels : 0;
    
    // Enhanced occupancy with better calculations
    $location['occupancy'] = [
        'total' => $totalCapacity > 0 ? round(($location['total_items'] / $totalCapacity) * 100, 1) : 0,
        'bottom' => $levelCapacity > 0 ? round(($location['bottom_items'] / $levelCapacity) * 100, 1) : 0,
        'middle' => $levelCapacity > 0 ? round(($location['middle_items'] / $levelCapacity) * 100, 1) : 0,
        'top' => $levelCapacity > 0 ? round(($location['top_items'] / $levelCapacity) * 100, 1) : 0
    ];
    
    // Add occupancy status
    $totalOccupancy = $location['occupancy']['total'];
    if ($totalOccupancy === 0) {
        $location['occupancy_status'] = 'empty';
    } elseif ($totalOccupancy <= 50) {
        $location['occupancy_status'] = 'low';
    } elseif ($totalOccupancy <= 79) {
        $location['occupancy_status'] = 'medium';
    } elseif ($totalOccupancy <= 94) {
        $location['occupancy_status'] = 'high';
    } else {
        $location['occupancy_status'] = 'full';
    }
    
    return $location;
}

/**
 * Auto-extract and update zone from location_code
 * Enhanced version of createLocation that auto-extracts zone
 * @param array $locationData
 * @return int|false Location ID on success, false on failure
 */
public function createLocationWithAutoZone(array $locationData) {
    // Auto-extract zone from location_code if it contains dash and zone is empty/generic
    if (!empty($locationData['location_code']) && strpos($locationData['location_code'], '-') !== false) {
        $extractedZone = explode('-', $locationData['location_code'])[0];
        
        // Only auto-set if zone is empty or generic
        if (empty($locationData['zone']) || $locationData['zone'] === 'Marfa') {
            $locationData['zone'] = strtoupper(trim($extractedZone));
        }
    }
    
    // Use existing createLocation method
    return $this->createLocation($locationData);
}

/**
 * Enhanced update that auto-extracts zone
 * @param int $locationId
 * @param array $locationData
 * @return bool
 */
public function updateLocationWithAutoZone($locationId, array $locationData) {
    // Auto-extract zone from location_code if it contains dash
    if (!empty($locationData['location_code']) && strpos($locationData['location_code'], '-') !== false) {
        $extractedZone = explode('-', $locationData['location_code'])[0];
        
        // Only auto-set if zone is empty or generic
        if (empty($locationData['zone']) || $locationData['zone'] === 'Marfa') {
            $locationData['zone'] = strtoupper(trim($extractedZone));
        }
    }
    
    // Use existing updateLocation method
    return $this->updateLocation($locationId, $locationData);
}

/**
 * Get all unique zones for filter dropdowns
 * @return array Array of unique zones
 */
public function getUniqueZones() {
    $query = "SELECT DISTINCT zone 
              FROM {$this->table} 
              WHERE status = 'active' 
                AND zone IS NOT NULL 
                AND zone != '' 
              ORDER BY zone";
    
    try {
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Error getting unique zones: " . $e->getMessage());
        return [];
    }
}

/**
 * Get locations by zone for zone-specific displays
 * @param string $zone
 * @return array Array of locations in the specified zone
 */
public function getLocationsByZone($zone) {
    $query = "SELECT 
                l.*,
                COALESCE(SUM(i.quantity), 0) as total_items,
                COALESCE(SUM(CASE WHEN i.shelf_level = 'bottom' THEN i.quantity ELSE 0 END), 0) as bottom_items,
                COALESCE(SUM(CASE WHEN i.shelf_level = 'middle' THEN i.quantity ELSE 0 END), 0) as middle_items,
                COALESCE(SUM(CASE WHEN i.shelf_level = 'top' THEN i.quantity ELSE 0 END), 0) as top_items,
                COUNT(DISTINCT i.product_id) as unique_products
              FROM {$this->table} l
              LEFT JOIN inventory i ON l.id = i.location_id
              WHERE l.zone = :zone 
                AND l.status = 'active'
                AND l.type = 'Shelf'
              GROUP BY l.id 
              ORDER BY l.location_code";
    
    try {
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':zone', $zone);
        $stmt->execute();
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Enhance each location with occupancy data
        foreach ($locations as &$location) {
            $location = $this->enhanceLocationOccupancy($location);
        }
        
        return $locations;
        
    } catch (PDOException $e) {
        error_log("Error getting locations by zone: " . $e->getMessage());
        return [];
    }
}

/**
 * Get enhanced warehouse statistics including zone breakdowns
 * @return array Enhanced warehouse statistics
 */
public function getWarehouseStats() {
    try {
        $query = "SELECT 
                    COUNT(*) as total_locations,
                    COUNT(CASE WHEN l.status = 'active' THEN 1 END) as active_locations,
                    COUNT(CASE WHEN EXISTS (SELECT 1 FROM inventory i WHERE i.location_id = l.id AND i.quantity > 0) THEN 1 END) as occupied_locations,
                    COALESCE(SUM(l.capacity), 0) as total_capacity,
                    COALESCE((SELECT SUM(i.quantity) FROM inventory i 
                             INNER JOIN {$this->table} loc ON i.location_id = loc.id 
                             WHERE loc.status = 'active'), 0) as total_items
                  FROM {$this->table} l";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $basicStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate high occupancy locations
        $highOccupancyQuery = "SELECT COUNT(*) as high_occupancy_count
                              FROM (
                                  SELECT l.id,
                                         CASE 
                                             WHEN l.capacity > 0 THEN (COALESCE(SUM(i.quantity), 0) / l.capacity) * 100
                                             ELSE 0
                                         END as occupancy_percentage
                                  FROM {$this->table} l
                                  LEFT JOIN inventory i ON l.id = i.location_id
                                  WHERE l.status = 'active' AND l.capacity > 0
                                  GROUP BY l.id
                                  HAVING occupancy_percentage >= 80
                              ) high_occ";
        
        $highOccStmt = $this->conn->prepare($highOccupancyQuery);
        $highOccStmt->execute();
        $highOccResult = $highOccStmt->fetch(PDO::FETCH_ASSOC);
        
        $basicStats['high_occupancy_count'] = $highOccResult['high_occupancy_count'] ?? 0;
        
        return $basicStats;
        
    } catch (PDOException $e) {
        error_log("Error getting warehouse stats: " . $e->getMessage());
        return [
            'total_locations' => 0,
            'active_locations' => 0,
            'occupied_locations' => 0,
            'total_capacity' => 0,
            'total_items' => 0,
            'high_occupancy_count' => 0
        ];
    }
}

public function getEnhancedWarehouseStats() {
    try {
        // Get basic stats first (NOT calling itself!)
        $basicStats = $this->getWarehouseStats();
        
        // Add zone-specific statistics
        $zones = $this->getDynamicZones();
        
        $enhancedStats = array_merge($basicStats, [
            'zones' => $zones,
            'zone_count' => count($zones),
            'most_occupied_zone' => null,
            'least_occupied_zone' => null,
            'average_zone_occupancy' => 0
        ]);
        
        // Find most and least occupied zones
        if (!empty($zones)) {
            $maxOccupancy = -1;
            $minOccupancy = 101;
            $totalZoneOccupancy = 0;
            
            foreach ($zones as $zone) {
                $occupancy = $zone['avg_occupancy'];
                $totalZoneOccupancy += $occupancy;
                
                if ($occupancy > $maxOccupancy) {
                    $maxOccupancy = $occupancy;
                    $enhancedStats['most_occupied_zone'] = $zone;
                }
                
                if ($occupancy < $minOccupancy) {
                    $minOccupancy = $occupancy;
                    $enhancedStats['least_occupied_zone'] = $zone;
                }
            }
            
            $enhancedStats['average_zone_occupancy'] = round($totalZoneOccupancy / count($zones), 1);
        }
        
        return $enhancedStats;
        
    } catch (Exception $e) {
        error_log("Error getting enhanced warehouse stats: " . $e->getMessage());
        // Return basic stats as fallback (NOT calling itself!)
        return $this->getWarehouseStats();
    }
}

/**
 * Migrate existing generic zones to proper zones
 * One-time migration method to fix existing data
 * @return array Migration results
 */
public function migrateGenericZones() {
    $query = "SELECT id, location_code, zone 
              FROM {$this->table} 
              WHERE zone = 'Marfa' 
                AND location_code LIKE '%-%'
                AND status = 'active'";
    
    try {
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $updated = 0;
        $errors = 0;
        
        foreach ($locations as $location) {
            $extractedZone = explode('-', $location['location_code'])[0];
            $newZone = strtoupper(trim($extractedZone));
            
            if (!empty($newZone)) {
                $updateQuery = "UPDATE {$this->table} SET zone = :zone WHERE id = :id";
                $updateStmt = $this->conn->prepare($updateQuery);
                
                if ($updateStmt->execute([':zone' => $newZone, ':id' => $location['id']])) {
                    $updated++;
                } else {
                    $errors++;
                }
            }
        }
        
        return [
            'total_processed' => count($locations),
            'updated' => $updated,
            'errors' => $errors,
            'message' => "Migration completed: {$updated} locations updated, {$errors} errors"
        ];
        
    } catch (PDOException $e) {
        error_log("Error in zone migration: " . $e->getMessage());
        return [
            'total_processed' => 0,
            'updated' => 0,
            'errors' => 1,
            'message' => "Migration failed: " . $e->getMessage()
        ];
    }
}

/**
 * Validate location code format
 * @param string $locationCode
 * @param string $type
 * @return array Validation result
 */
public function validateLocationCode($locationCode, $type = 'Shelf') {
    $errors = [];
    
    if (empty($locationCode)) {
        $errors[] = 'Codul locației este obligatoriu';
        return ['valid' => false, 'errors' => $errors];
    }
    
    // For shelves, require dash format
    if ($type === 'Shelf' && strpos($locationCode, '-') === false) {
        $errors[] = 'Pentru rafturi, codul trebuie să conțină cratimă (ex: MID-1A)';
    }
    
    // Check for valid characters (letters, numbers, dash)
    if (!preg_match('/^[A-Z0-9\-]+$/i', $locationCode)) {
        $errors[] = 'Codul poate conține doar litere, cifre și cratimă';
    }
    
    // Check if already exists
    if ($this->getLocationByCode($locationCode)) {
        $errors[] = 'Codul locației există deja';
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'extracted_zone' => strpos($locationCode, '-') !== false ? explode('-', $locationCode)[0] : null
    ];
}

/**
 * Get enhanced location details with zone context
 * Enhanced version of existing getLocationDetails
 * @param int $locationId
 * @return array|false
 */
public function getEnhancedLocationDetails($locationId) {
    // Get basic details using existing method
    $details = $this->getLocationDetails($locationId);
    
    if (!$details) {
        return false;
    }
    
    try {
        // Add zone context information
        if (!empty($details['zone'])) {
            $zoneStats = $this->getZoneOccupancyStats($details['zone']);
            $details['zone_stats'] = $zoneStats;
            
            // Get other locations in the same zone
            $zoneLocations = $this->getLocationsByZone($details['zone']);
            $details['zone_locations_count'] = count($zoneLocations);
            $details['zone_sibling_locations'] = array_slice($zoneLocations, 0, 5); // First 5 for preview
        }
        
        // Add enhanced occupancy status
        $details = $this->enhanceLocationOccupancy($details);
        
        return $details;
        
    } catch (Exception $e) {
        error_log("Error getting enhanced location details: " . $e->getMessage());
        return $details; // Return basic details if enhancement fails
    }
}

}
