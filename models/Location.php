<?php
/**
 * Complete Location Model with Level Settings Integration
 * Cleaned up version without duplicate methods
 * File: models/Location.php
 */

require_once __DIR__ . '/Setting.php';
require_once __DIR__ . '/LocationLevelSettings.php';
require_once __DIR__ . '/LocationSubdivision.php';

class Location {
    protected $conn;
    private $table = "locations";
    private LocationLevelSettings $levelSettings;
    private LocationSubdivision $subdivisions;

    // Default geometry constants used when dimensions are missing
    private const DEFAULT_LENGTH_MM = 1200;
    private const DEFAULT_DEPTH_MM = 800;
    private const STANDARD_LEVELS = 3;
    public const TYPE_TEMPORARY = 'temporary';


    public function __construct($db) {
        $this->conn = $db;
        $this->levelSettings = new LocationLevelSettings($this->conn);
        $this->subdivisions = new LocationSubdivision($this->conn);
    }

    /**
     * Get level capacity based on location dimensions
     * @param array $location
     * @return int
     */
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

    // ===== COUNTING AND STATISTICS METHODS =====

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

    // ===== LOCATION RETRIEVAL METHODS =====

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
     * Get location details with optional enhancements
     * @param int $locationId
     * @param bool $includeLevelSettings Include level settings data
     * @param bool $includeZoneContext Include zone context information
     * @param bool $enhanceOccupancy Include enhanced occupancy calculations
     * @return array|false
     */
    /**
     * Check if location has reached capacity
     * @param int $locationId
     * @return bool
     */
    public function isLocationFull(int $locationId): bool {
        try {
            $stmt = $this->conn->prepare("SELECT capacity, current_occupancy FROM {$this->table} WHERE id = :id");
            $stmt->bindParam(':id', $locationId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return false;
            }
            $capacity = (int)($row['capacity'] ?? 0);
            if ($capacity === 0) {
                return false;
            }
            return (int)($row['current_occupancy'] ?? 0) >= $capacity;
        } catch (PDOException $e) {
            error_log("Error checking location capacity: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Find an available temporary location
     * @return int|null
     */
    public function findAvailableTemporaryLocation(): ?int {
        try {
            $query = "SELECT id FROM {$this->table} WHERE type = 'temporary' AND status = 'active' AND (capacity = 0 OR current_occupancy < capacity) ORDER BY id LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : null;
        } catch (PDOException $e) {
            error_log("Error finding temporary location: " . $e->getMessage());
            return null;
        }
    }

    public function getLocationDetails($locationId, $includeLevelSettings = false, $includeZoneContext = false, $enhanceOccupancy = false) {
        $query = "SELECT 
                l.*,
                COALESCE(SUM(i.quantity), 0) as total_items,
                COUNT(DISTINCT i.product_id) as unique_products
            FROM {$this->table} l
            LEFT JOIN inventory i on l.id = i.location_id
            WHERE l.id = :id
            GROUP BY l.id";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $locationId, PDO::PARAM_INT);
            $stmt->execute();
            $details = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$details) {
                return false;
            }

            // Add level settings if requested
            if ($includeLevelSettings) {
                $details['level_settings'] = $this->levelSettings->getLevelSettings($locationId);

                $subs = $this->subdivisions->getAllSubdivisions($locationId);

                // Attach QR code paths
                $qrStmt = $this->conn->prepare(
                    "SELECT level_number, file_path FROM location_qr_codes WHERE location_id = :id"
                );
                $qrStmt->bindValue(':id', $locationId, PDO::PARAM_INT);
                $qrStmt->execute();
                $qrRecords = $qrStmt->fetchAll(PDO::FETCH_ASSOC);
                $qrMap = [];
                foreach ($qrRecords as $qr) {
                    $qrMap[(int)$qr['level_number']] = $qr['file_path'];
                }

                // Add occupancy data and QR path
                foreach ($details['level_settings'] as &$levelSetting) {
                    $levelNumber = (int)$levelSetting['level_number'];
                    $levelSetting['current_occupancy'] = $this->getLevelOccupancyData(
                        $locationId,
                        $levelNumber
                    );
                    if (isset($qrMap[$levelNumber])) {
                        $levelSetting['qr_code_path'] = $qrMap[$levelNumber];
                    }
                    if (isset($subs[$levelNumber])) {
                        $levelSetting['subdivisions'] = $subs[$levelNumber];
                    } else {
                        $levelSetting['subdivisions'] = [];
                    }
                }
                unset($levelSetting);
            }

            // Add zone context if requested
            if ($includeZoneContext && !empty($details['zone'])) {
                $zoneStats = $this->getZoneOccupancyStats($details['zone']);
                $details['zone_stats'] = $zoneStats;
                
                // Get other locations in the same zone
                $zoneLocations = $this->getLocationsByZone($details['zone']);
                $details['zone_locations_count'] = count($zoneLocations);
                $details['zone_sibling_locations'] = array_slice($zoneLocations, 0, 5); // First 5 for preview
            }

            // Add enhanced occupancy if requested
            if ($enhanceOccupancy) {
                $details = $this->enhanceLocationOccupancy($details);
            }
            
            return $details;
            
        } catch (PDOException $e) {
            error_log("Error getting location details: " . $e->getMessage());
            return false;
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

    // ===== REPARTITION AND LEVEL MANAGEMENT METHODS =====

    /**
     * Get locations that need repartition
     * @return array
     */
    public function getLocationsNeedingRepartition(): array {
        $query = "SELECT DISTINCT 
                    l.id, 
                    l.location_code, 
                    l.zone,
                    COUNT(lls.id) as levels_with_auto_repartition
                  FROM locations l
                  JOIN location_level_settings lls ON l.id = lls.location_id
                  WHERE l.type = 'shelf' 
                  AND l.status = 'active'
                  AND lls.enable_auto_repartition = true
                  GROUP BY l.id, l.location_code, l.zone
                  ORDER BY l.zone, l.location_code";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // For each location, check if it actually needs repartition
            $needingRepartition = [];
            foreach ($locations as $location) {
                if ($this->locationNeedsRepartition($location['id'])) {
                    $needingRepartition[] = $location;
                }
            }
            
            return $needingRepartition;
            
        } catch (PDOException $e) {
            error_log("Error getting locations needing repartition: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if a location needs repartition
     * @param int $locationId
     * @return bool
     */
    private function locationNeedsRepartition(int $locationId): bool {
        try {
            $levelSettings = $this->levelSettings->getLevelSettings($locationId);
            
            foreach ($levelSettings as $levelSetting) {
                if ($levelSetting['enable_auto_repartition']) {
                    $occupancy = $this->getLevelOccupancyData($locationId, $levelSetting['level_number']);
                    $occupancyPercent = ($occupancy['items'] / max($occupancy['capacity'], 1)) * 100;
                    
                    // Check if occupancy exceeds repartition threshold
                    if ($occupancyPercent >= ($levelSetting['repartition_threshold'] ?? 90)) {
                        return true;
                    }
                }
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error checking location repartition needs: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get occupancy data for a specific level
     * @param int $locationId
     * @param int $levelNumber
     * @return array
     */
    public function getLevelOccupancyData(int $locationId, int $levelNumber): array {
        try {
            // Fetch level configuration to determine capacity source
            $level = $this->levelSettings->getLevelSetting($locationId, $levelNumber);
            $levelName = $level['level_name'] ?? (
                $this->levelSettings->getLevelNameByNumber($locationId, $levelNumber) ?? ('Level ' . $levelNumber)
            );

            $items = 0;
            $capacity = 0;

            // When subdivisions are enabled, sum their capacities and stock
            if ($level && (!empty($level['subdivisions_enabled']) || (int)($level['subdivision_count'] ?? 1) > 1)) {
                $subs = $this->subdivisions->getSubdivisionsWithProducts($locationId, $levelNumber);
                foreach ($subs as $sub) {
                    $items += (int)($sub['current_stock'] ?? 0);
                    $capacity += (int)($sub['items_capacity'] ?: $sub['product_capacity'] ?: 0);
                }
            } else {
                // Otherwise use level capacity directly
                $query = "SELECT COALESCE(SUM(i.quantity), 0) as items
                          FROM inventory i
                          WHERE i.location_id = :location_id
                          AND i.shelf_level = :level_name";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([
                    ':location_id' => $locationId,
                    ':level_name'  => $levelName
                ]);

                $items = (int)$stmt->fetchColumn();
                $capacity = (int)($level['items_capacity'] ?? 0);
            }

            $occupancyPercent = $capacity > 0 ? round(($items / $capacity) * 100, 1) : 0;

            return [
                'items' => $items,
                'capacity' => $capacity,
                'occupancy_percent' => $occupancyPercent
            ];

        } catch (PDOException $e) {
            error_log("Error getting level occupancy data: " . $e->getMessage());
            return ['items' => 0, 'capacity' => 0, 'occupancy_percent' => 0];
        }
    }

    /**
     * Get dynamic occupancy data for all levels of a location
     * @param int $locationId
     * @return array Occupancy data keyed by level_number
     */
    private function getDynamicLevelOccupancy(int $locationId): array {
        $levelSettings = $this->levelSettings->getLevelSettings($locationId);
        $occupancy = [];

        foreach ($levelSettings as $level) {
            $levelNumber = $level['level_number'];
            $levelName = $level['level_name'] ?: "Nivel {$levelNumber}";

            $items = 0;
            $capacity = 0;

            if (!empty($level['subdivisions_enabled']) || (int)($level['subdivision_count'] ?? 1) > 1) {
                $subdivisions = $this->subdivisions->getSubdivisionsWithProducts($locationId, $levelNumber);
                foreach ($subdivisions as $sub) {
                    $items += (int)($sub['current_stock'] ?? 0);
                    $capacity += (int)($sub['items_capacity'] ?: $sub['product_capacity'] ?: 0);
                }
            } else {
                $query = "SELECT COALESCE(SUM(i.quantity), 0) as items
                      FROM inventory i
                      WHERE i.location_id = :location_id
                      AND i.shelf_level = :level_name";

                $stmt = $this->conn->prepare($query);
                $stmt->execute([
                    ':location_id' => $locationId,
                    ':level_name' => $levelName
                ]);

                $items = (int)$stmt->fetchColumn();
                $capacity = (int)($level['items_capacity'] ?: 0);
            }

            $percentage = $capacity > 0 ? round(($items / $capacity) * 100, 1) : 0;

            $occupancy[$levelNumber] = [
                'items' => $items,
                'capacity' => $capacity,
                'percentage' => $percentage,
                'level_name' => $levelName
            ];
        }

        return $occupancy;
    }

    /**
     * Recalculate and persist total capacity for a location by
     * summing capacities from each level and its subdivisions.
     *
     * @param int $locationId
     * @return int Total capacity for the location
     */
    public function refreshCapacity(int $locationId): int {
        $levels = $this->levelSettings->getLevelSettingsWithSubdivisions($locationId);
        $totalCapacity = 0;

        foreach ($levels as $level) {
            if (!empty($level['subdivisions'])) {
                foreach ($level['subdivisions'] as $sub) {
                    $totalCapacity += (int)($sub['items_capacity'] ?: $sub['product_capacity'] ?: 0);
                }
            } else {
                $totalCapacity += (int)($level['items_capacity'] ?? 0);
            }
        }

        $stmt = $this->conn->prepare("UPDATE {$this->table} SET capacity = :capacity WHERE id = :id");
        $stmt->execute([':capacity' => $totalCapacity, ':id' => $locationId]);

        return $totalCapacity;
    }

    // ===== LOCATION CREATION METHODS =====

    /**
     * Create a new location with optional enhancements
     * @param array $locationData Location data
     * @param bool $autoExtractZone Auto-extract zone from location_code
     * @param bool $includeLevelSettings Create with level settings
     * @return int|false Location ID on success, false on failure
     */
    public function createLocation(array $locationData, $autoExtractZone = false, $includeLevelSettings = true): int|false {
        try {
            $this->conn->beginTransaction();

            // Check if location code already exists
            if ($this->getLocationByCode($locationData['location_code'])) {
                return false;
            }

            // Auto-extract zone if requested
            if ($autoExtractZone && !empty($locationData['location_code']) && strpos($locationData['location_code'], '-') !== false) {
                $extractedZone = explode('-', $locationData['location_code'])[0];
                
                // Only auto-set if zone is empty or generic
                if (empty($locationData['zone']) || $locationData['zone'] === 'Marfa') {
                    $locationData['zone'] = strtoupper(trim($extractedZone));
                }
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
                throw new Exception("Database error: failed to insert new location.");
            }

            $locationId = (int)$this->conn->lastInsertId();

            if ($locationId === 0) {
                throw new Exception("Failed to retrieve ID for new location.");
            }

            // Create level settings if requested
            if ($includeLevelSettings) {
                $levels = $locationData['levels'] ?? 3;
                if (!$this->levelSettings->createDefaultSettings($locationId, $levels)) {
                    throw new Exception("Failed to create default level settings.");
                }
                for ($lvl = 1; $lvl <= $levels; $lvl++) {
                    $this->subdivisions->syncSubdivisions($locationId, $lvl, ['subdivision_count' => 1]);
                }

                // If level-specific settings were provided, update them
                if (isset($locationData['level_settings']) && is_array($locationData['level_settings'])) {
                    foreach ($locationData['level_settings'] as $levelNum => $settings) {
                        $this->levelSettings->updateLevelSettings($locationId, $levelNum, $settings);
                        $this->subdivisions->syncSubdivisions($locationId, $levelNum, $settings);
                    }
                }
            }

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

    // ===== LOCATION UPDATE METHODS =====

    /**
     * Update a location with optional enhancements
     * @param int $locationId Location ID
     * @param array $locationData Location data
     * @param bool $autoExtractZone Auto-extract zone from location_code
     * @return bool
     */
    public function updateLocation(int $locationId, array $locationData, $autoExtractZone = false): bool {
        try {
            $this->conn->beginTransaction();

            // Auto-extract zone if requested
            if ($autoExtractZone && !empty($locationData['location_code']) && strpos($locationData['location_code'], '-') !== false) {
                $extractedZone = explode('-', $locationData['location_code'])[0];
                
                // Only auto-set if zone is empty or generic
                if (empty($locationData['zone']) || $locationData['zone'] === 'Marfa') {
                    $locationData['zone'] = strtoupper(trim($extractedZone));
                }
            }
    
            // Update the main location data
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
    
            // Update the individual level settings (if they were submitted)
            if (isset($locationData['level_settings']) && is_array($locationData['level_settings'])) {
                foreach ($locationData['level_settings'] as $levelNum => $settings) {
                    if (!$this->levelSettings->updateLevelSettings($locationId, $levelNum, $settings)) {
                        throw new Exception("Failed to update settings for level $levelNum on location ID: $locationId");
                    }
                    $this->subdivisions->syncSubdivisions($locationId, $levelNum, $settings);
                }
            }
            
            // Handle changes to the number of levels
            if (isset($locationData['levels'])) {
                $currentLevels = $this->getCurrentLevelsCount($locationId);
                $newLevels = intval($locationData['levels']);
                
                if ($newLevels > $currentLevels) {
                    // Add new default settings for the new levels
                    for ($level = $currentLevels + 1; $level <= $newLevels; $level++) {
                        $defaultSettings = $this->getDefaultLevelSettings($level, $newLevels);
                        $this->levelSettings->updateLevelSettings($locationId, $level, $defaultSettings);
                        $this->subdivisions->syncSubdivisions($locationId, $level, $defaultSettings);
                    }
                } elseif ($newLevels < $currentLevels) {
                    // Remove settings for levels that no longer exist
                    for ($level = $newLevels + 1; $level <= $currentLevels; $level++) {
                        $this->deleteLevelSettings($locationId, $level);
                        $this->subdivisions->deleteSubdivisions($locationId, $level);
                    }
                }
            }
    
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("Error in updateLocation transaction: " . $e->getMessage());
            return false;
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

    // ===== LOCATION DELETE METHODS =====

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

    // ===== ZONE AND TYPE METHODS =====

    /**
     * Get zones with optional filtering
     * @param bool $activeOnly Get only active location zones
     * @param bool $withStats Include zone statistics
     * @return array Array of zones or zone data with stats
     */
    public function getZones($activeOnly = false, $withStats = false) {
        if ($withStats) {
            return $this->getDynamicZones();
        }

        $query = "SELECT DISTINCT zone FROM {$this->table} WHERE zone IS NOT NULL AND zone != ''";
        
        if ($activeOnly) {
            $query .= " AND status = 'active'";
        }
        
        $query .= " ORDER BY zone";
        
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
     * Get locations by zone for zone-specific displays
     * @param string $zone
     * @return array Array of locations in the specified zone
     */
    public function getLocationsByZone($zone) {
        $query = "SELECT
                    l.*,
                    COALESCE(SUM(i.quantity), 0) as total_items,
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
     * Get dynamic zones extracted from location_code field
     * @return array Array of zones with statistics
     */
    public function getDynamicZones() {
        error_log("getDynamicZones() - Starting universal zone extraction");
        
        // CORRECTED: Extract zones from ALL active locations regardless of type
        $query = "SELECT 
                    CASE 
                        WHEN location_code LIKE '%-%' THEN SUBSTRING_INDEX(location_code, '-', 1)
                        ELSE COALESCE(zone, 'UNASSIGNED')
                    END as zone_name,
                    COUNT(*) as location_count,
                    COUNT(DISTINCT type) as location_types_count,
                    GROUP_CONCAT(DISTINCT type ORDER BY type) as location_types,
                    AVG(capacity) as avg_capacity,
                    SUM(capacity) as total_capacity,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_locations,
                    -- Get type breakdown
                    COUNT(CASE WHEN type = 'shelf' THEN 1 END) as shelf_count,
                    COUNT(CASE WHEN type = 'bin' THEN 1 END) as bin_count,
                    COUNT(CASE WHEN type = 'rack' THEN 1 END) as rack_count,
                    COUNT(CASE WHEN type = 'warehouse' THEN 1 END) as warehouse_count,
                    COUNT(CASE WHEN type = 'zone' THEN 1 END) as zone_count,
                    COUNT(CASE WHEN type LIKE '%qc%' OR type LIKE '%quarantine%' THEN 1 END) as qc_count,
                    COUNT(CASE WHEN type = 'production' THEN 1 END) as production_count,
                    COUNT(CASE WHEN type = 'temporary' THEN 1 END) as temporary_count
                  FROM {$this->table} 
                  WHERE status = 'active'
                    AND (location_code IS NOT NULL OR zone IS NOT NULL)
                  GROUP BY CASE 
                        WHEN location_code LIKE '%-%' THEN SUBSTRING_INDEX(location_code, '-', 1)
                        ELSE COALESCE(zone, 'UNASSIGNED')
                    END
                  HAVING zone_name IS NOT NULL AND zone_name != ''
                  ORDER BY zone_name";
        
        try {
            error_log("getDynamicZones() - Executing universal query for all location types");
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("getDynamicZones() - Found " . count($zones) . " zones: " . implode(', ', array_column($zones, 'zone_name')));
            
            // Calculate occupancy for each zone (with safety check)
            foreach ($zones as &$zone) {
                try {
                    $zoneOccupancy = $this->getZoneOccupancyStats($zone['zone_name']);
                    $zone['avg_occupancy'] = $zoneOccupancy['avg_occupancy'];
                    $zone['total_items'] = $zoneOccupancy['total_items'];
                    $zone['max_occupancy'] = $zoneOccupancy['max_occupancy'];
                    $zone['min_occupancy'] = $zoneOccupancy['min_occupancy'];
                    
                    // Enhanced zone info
                    $zone['type_summary'] = $this->buildTypeSummary($zone);
                    
                } catch (Exception $e) {
                    error_log("Error getting occupancy for zone {$zone['zone_name']}: " . $e->getMessage());
                    // Set default values if occupancy calculation fails
                    $zone['avg_occupancy'] = 0;
                    $zone['total_items'] = 0;
                    $zone['max_occupancy'] = 0;
                    $zone['min_occupancy'] = 0;
                    $zone['type_summary'] = 'Mixed';
                }
            }
            
            error_log("getDynamicZones() - Successfully processed " . count($zones) . " zones with all location types");
            return $zones;
            
        } catch (PDOException $e) {
            error_log("Error getting dynamic zones: " . $e->getMessage());
            
            // FALLBACK: Extract zones from all locations if SQL fails
            error_log("getDynamicZones() - Using fallback extraction method");
            return $this->extractZonesFallback();
        }
    }

    private function extractZonesFallback() {
        error_log("Just a test.");
    }
   
    /**
     * Build a readable summary of location types in a zone
     * @param array $zone Zone data with type counts
     * @return string Readable summary
     */
    private function buildTypeSummary($zone) {
        $parts = [];
        
        if ($zone['shelf_count'] > 0) $parts[] = $zone['shelf_count'] . ' rafturi';
        if ($zone['bin_count'] > 0) $parts[] = $zone['bin_count'] . ' containere';
        if ($zone['rack_count'] > 0) $parts[] = $zone['rack_count'] . ' rack-uri';
        if ($zone['warehouse_count'] > 0) $parts[] = $zone['warehouse_count'] . ' depozite';
        if ($zone['zone_count'] > 0) $parts[] = $zone['zone_count'] . ' zone';
        if ($zone['qc_count'] > 0) $parts[] = $zone['qc_count'] . ' QC';
        if ($zone['production_count'] > 0) $parts[] = $zone['production_count'] . ' producție';
        if ($zone['temporary_count'] > 0) $parts[] = $zone['temporary_count'] . ' temporare';
        
        return !empty($parts) ? implode(', ', $parts) : 'Mixt';
    }

    /**
     * Get occupancy statistics for a specific zone
     * @param string $zoneName
     * @return array Zone occupancy statistics
     */
    public function getZoneOccupancyStats($zoneName) {
        // CORRECTED: Include ALL location types in occupancy calculation
        $query = "SELECT 
                    l.id,
                    l.type,
                    l.capacity,
                    COALESCE(SUM(i.quantity), 0) as total_items
                  FROM {$this->table} l
                  LEFT JOIN inventory i ON l.id = i.location_id
                  WHERE (l.zone = :zone_name OR 
                         (l.location_code LIKE CONCAT(:zone_name_2, '-%')))
                    AND l.status = 'active'
                  GROUP BY l.id, l.type, l.capacity";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':zone_name', $zoneName);
            $stmt->bindParam(':zone_name_2', $zoneName);
            $stmt->execute();
            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($locations)) {
                error_log("getZoneOccupancyStats() - No locations found for zone: $zoneName");
                return [
                    'avg_occupancy' => 0,
                    'total_items' => 0,
                    'max_occupancy' => 0,
                    'min_occupancy' => 0,
                    'location_breakdown' => []
                ];
            }
            
            $occupancies = [];
            $totalItems = 0;
            $locationBreakdown = [];
            
            foreach ($locations as $location) {
                $capacity = (int)$location['capacity'];
                $items = (int)$location['total_items'];
                $occupancy = $capacity > 0 ? ($items / $capacity) * 100 : 0;
                
                $occupancies[] = $occupancy;
                $totalItems += $items;
                
                // Track by location type
                $type = $location['type'];
                if (!isset($locationBreakdown[$type])) {
                    $locationBreakdown[$type] = ['count' => 0, 'total_items' => 0, 'total_capacity' => 0];
                }
                $locationBreakdown[$type]['count']++;
                $locationBreakdown[$type]['total_items'] += $items;
                $locationBreakdown[$type]['total_capacity'] += $capacity;
            }
            
            return [
                'avg_occupancy' => !empty($occupancies) ? round(array_sum($occupancies) / count($occupancies), 1) : 0,
                'total_items' => $totalItems,
                'max_occupancy' => !empty($occupancies) ? round(max($occupancies), 1) : 0,
                'min_occupancy' => !empty($occupancies) ? round(min($occupancies), 1) : 0,
                'location_breakdown' => $locationBreakdown
            ];
            
        } catch (PDOException $e) {
            error_log("Error getting zone occupancy stats for $zoneName: " . $e->getMessage());
            return [
                'avg_occupancy' => 0,
                'total_items' => 0,
                'max_occupancy' => 0,
                'min_occupancy' => 0,
                'location_breakdown' => []
            ];
        }
    }

    // ===== WAREHOUSE VISUALIZATION METHODS =====

    /**
     * Get warehouse visualization data with optional enhancements
     * @param string $zoneFilter Filter by zone
     * @param string $typeFilter Filter by type
     * @param string $search Search in location codes
     * @param bool $enhanced Include enhanced occupancy and zone extraction
     * @return array
     */
    public function getWarehouseVisualizationData($zoneFilter = '', $typeFilter = '', $search = '', $enhanced = false) {
        $query = "SELECT
                    l.id,
                    l.location_code,
                    l.zone,
                    l.type,
                    l.capacity,
                    l.levels,
                    l.status,
                    l.notes,
                    COALESCE(SUM(i.quantity), 0) as total_items,
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

            foreach ($results as &$location) {
                if ($enhanced && !empty($location['location_code']) && strpos($location['location_code'], '-') !== false) {
                    $extractedZone = explode('-', $location['location_code'])[0];
                    if ($location['zone'] === 'Marfa' || empty($location['zone'])) {
                        $location['zone'] = strtoupper($extractedZone);
                    }
                }

                $location = $this->enhanceLocationOccupancy($location);
            }

            return $results;

        } catch (PDOException $e) {
            error_log("Error getting warehouse visualization data: " . $e->getMessage());
            return [];
        }
    }

    // ===== HIGH OCCUPANCY AND STATISTICS METHODS =====

    /**
     * Get locations with high occupancy (over 80% full)
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
     * Get warehouse statistics with optional enhancements
     * @param bool $enhanced Include zone breakdowns and detailed stats
     * @return array Enhanced warehouse statistics
     */
    public function getWarehouseStats($enhanced = false) {
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

            // Add enhanced statistics if requested
            if ($enhanced) {
                $zones = $this->getDynamicZones();
                
                $basicStats = array_merge($basicStats, [
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
                            $basicStats['most_occupied_zone'] = $zone;
                        }
                        
                        if ($occupancy < $minOccupancy) {
                            $minOccupancy = $occupancy;
                            $basicStats['least_occupied_zone'] = $zone;
                        }
                    }
                    
                    $basicStats['average_zone_occupancy'] = round($totalZoneOccupancy / count($zones), 1);
                }
            }
            
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

    // ===== UTILITY AND VALIDATION METHODS =====

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
     * Update inventory level for a specific location and shelf level
     * @param int $locationId
     * @param string $level (bottom, middle, top)
     * @param int $productId
     * @param int $quantity
     * @return bool
     */
    public function updateShelfLevel($locationId, $level, $productId, $quantity) {
        if (empty($level)) {
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
     * Migrate existing generic zones to proper zones
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

    // ===== HELPER METHODS FOR LOCATION MANAGEMENT =====

    /**
     * Get current levels count for a location
     * @param int $locationId
     * @return int
     */
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

    /**
     * Get default level settings for new levels
     * @param int $levelNumber
     * @param int $totalLevels
     * @return array
     */
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
            'items_capacity' => null,
            'dedicated_product_id' => null,
            'allow_other_products' => true,
            'enable_auto_repartition' => false,
            'repartition_trigger_threshold' => 80,
            'priority_order' => $totalLevels - $levelNumber + 1,
            'subdivision_count' => 1
        ];
    }

    /**
     * Delete level settings for removed levels
     * @param int $locationId
     * @param int $levelNumber
     * @return bool
     */
    private function deleteLevelSettings(int $locationId, int $levelNumber): bool {
        $query = "DELETE FROM location_level_settings
                    WHERE location_id = :location_id AND level_number = :level_number";

        try {
            $stmt = $this->conn->prepare($query);
            $res = $stmt->execute([
                ':location_id' => $locationId,
                ':level_number' => $levelNumber
            ]);
            $this->subdivisions->deleteSubdivisions($locationId, $levelNumber);
            return $res;
        } catch (PDOException $e) {
            error_log("Error deleting level settings.");
            return false;
        }
    }

    /**
     * Convert level number to shelf level name
     * @param int $levelNumber
     * @return string
     */


    /**
     * Enhance location occupancy calculations
     * @param array $location
     * @return array Enhanced location data
     */
    private function enhanceLocationOccupancy($location) {
        $locationId = (int)$location['id'];
        
        // Special handling for temporary locations - use simple calculation
        if (strtolower($location['type']) === 'temporary') {
            $totalItems = (int)($location['total_items'] ?? 0);
            $totalCapacity = (int)($location['capacity'] ?? 0);
            $totalPercentage = $totalCapacity > 0 ? round(($totalItems / $totalCapacity) * 100, 1) : 0;
            
            $location['occupancy'] = [
                'total' => $totalPercentage,
                'levels' => []
            ];
        } else {
            // Regular locations use level-based calculation
            $levelOccupancy = $this->getDynamicLevelOccupancy($locationId);
    
            $totalItems = array_sum(array_column($levelOccupancy, 'items'));
            $totalCapacity = array_sum(array_column($levelOccupancy, 'capacity'));
            $totalPercentage = $totalCapacity > 0 ? round(($totalItems / $totalCapacity) * 100, 1) : 0;
    
            $location['occupancy'] = [
                'total' => $totalPercentage,
                'levels' => $levelOccupancy
            ];
            
            $location['total_items'] = $totalItems;
            $location['capacity'] = $totalCapacity;
        }
    
        // Set occupancy status
        if ($totalPercentage === 0) {
            $location['occupancy_status'] = 'empty';
        } elseif ($totalPercentage <= 50) {
            $location['occupancy_status'] = 'low';
        } elseif ($totalPercentage <= 79) {
            $location['occupancy_status'] = 'medium';
        } elseif ($totalPercentage <= 94) {
            $location['occupancy_status'] = 'high';
        } else {
            $location['occupancy_status'] = 'full';
        }
    
        return $location;
    }
    // ===== BACKWARD COMPATIBILITY ALIASES =====
    // These methods provide backward compatibility for existing code

    /**
     * Alias for getZones(true) - backward compatibility
     * @return array
     */
    public function getUniqueZones() {
        return $this->getZones(true);
    }

    /**
     * Alias for createLocation with level settings - backward compatibility
     * @param array $locationData
     * @return int|false
     */
    public function createLocationWithLevelSettings(array $locationData): int|false {
        return $this->createLocation($locationData, false, true);
    }

    /**
     * Alias for createLocation with auto zone - backward compatibility
     * @param array $locationData
     * @return int|false
     */
    public function createLocationWithAutoZone(array $locationData) {
        return $this->createLocation($locationData, true, true);
    }

    /**
     * Alias for updateLocation with auto zone - backward compatibility
     * @param int $locationId
     * @param array $locationData
     * @return bool
     */
    public function updateLocationWithAutoZone($locationId, array $locationData) {
        return $this->updateLocation($locationId, $locationData, true);
    }

    /**
     * Alias for getLocationDetails with level settings - backward compatibility
     * @param int $locationId
     * @return array|null
     */
    public function getLocationWithLevelSettings(int $locationId): ?array {
        return $this->getLocationDetails($locationId, true, false, false);
    }

    /**
     * Alias for getLocationDetails with enhancements - backward compatibility
     * @param int $locationId
     * @return array|false
     */
    public function getEnhancedLocationDetails($locationId) {
        return $this->getLocationDetails($locationId, false, true, true);
    }

    /**
     * Alias for getWarehouseVisualizationData with enhancements - backward compatibility
     * @param string $zoneFilter
     * @param string $typeFilter
     * @param string $search
     * @return array
     */
    public function getEnhancedWarehouseData($zoneFilter = '', $typeFilter = '', $search = '') {
        return $this->getWarehouseVisualizationData($zoneFilter, $typeFilter, $search, true);
    }

    /**
     * Alias for getWarehouseStats with enhancements - backward compatibility
     * @return array
     */
    public function getEnhancedWarehouseStats() {
        return $this->getWarehouseStats(true);
    }
}