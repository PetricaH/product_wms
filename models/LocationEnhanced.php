<?php
/**
 * Enhanced Location Model with Level Settings Integration
 * Extends the existing Location model with automatic repartition capabilities
 */


class LocationEnhanced extends Location {
    private LocationLevelSettings $levelSettings;
    
    public function __construct(PDO $connection) {
        parent::__construct($connection);
        $this->levelSettings = new LocationLevelSettings($connection);
    }
    
    /**
     * Create a new location with level settings
     * @param array $locationData
     * @return int|false Location ID on success, false on failure
     */
    public function createLocationWithLevelSettings(array $locationData): int|false {
        try {
            $this->conn->beginTransaction();
            
            // Create the location using parent method
            $locationId = parent::createLocation($locationData);
            
            if (!$locationId) {
                throw new Exception("Failed to create location");
            }
            
            // Create default level settings
            $levels = $locationData['levels'] ?? 3;
            if (!$this->levelSettings->createDefaultSettings($locationId, $levels)) {
                throw new Exception("Failed to create level settings");
            }
            
            // If level-specific settings were provided, update them
            if (isset($locationData['level_settings']) && is_array($locationData['level_settings'])) {
                foreach ($locationData['level_settings'] as $levelNum => $settings) {
                    $this->levelSettings->updateLevelSettings($locationId, $levelNum, $settings);
                }
            }
            
            $this->conn->commit();
            return $locationId;
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("Error creating location with level settings: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update location and its level settings
     * @param int $locationId
     * @param array $locationData
     * @return bool
     */
    public function updateLocationWithLevelSettings(int $locationId, array $locationData): bool {
        try {
            $this->conn->beginTransaction();
            
            // Update the location using parent method
            if (!parent::updateLocation($locationId, $locationData)) {
                throw new Exception("Failed to update location");
            }
            
            // Update level settings if provided
            if (isset($locationData['level_settings']) && is_array($locationData['level_settings'])) {
                foreach ($locationData['level_settings'] as $levelNum => $settings) {
                    if (!$this->levelSettings->updateLevelSettings($locationId, $levelNum, $settings)) {
                        throw new Exception("Failed to update level $levelNum settings");
                    }
                }
            }
            
            // If levels count changed, adjust level settings
            if (isset($locationData['levels'])) {
                $currentLevels = $this->getCurrentLevelsCount($locationId);
                $newLevels = intval($locationData['levels']);
                
                if ($newLevels > $currentLevels) {
                    // Add new level settings
                    for ($level = $currentLevels + 1; $level <= $newLevels; $level++) {
                        $defaultSettings = $this->getDefaultLevelSettings($level, $newLevels);
                        $this->levelSettings->updateLevelSettings($locationId, $level, $defaultSettings);
                    }
                } elseif ($newLevels < $currentLevels) {
                    // Remove excess level settings and move inventory
                    for ($level = $newLevels + 1; $level <= $currentLevels; $level++) {
                        $this->moveInventoryFromLevel($locationId, $level, min($newLevels, 2)); // Move to middle or bottom
                        $this->deleteLevelSettings($locationId, $level);
                    }
                }
            }
            
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("Error updating location with level settings: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get complete location data with level settings
     * @param int $locationId
     * @return array|null
     */
    public function getLocationWithLevelSettings(int $locationId): ?array {
        $location = parent::getLocationDetails($locationId);
        if (!$location) {
            return null;
        }
        
        $location['level_settings'] = $this->levelSettings->getLevelSettings($locationId);
        
        // Add occupancy data per level
        foreach ($location['level_settings'] as &$levelSetting) {
            $levelSetting['current_occupancy'] = $this->getLevelOccupancyData(
                $locationId, 
                $levelSetting['level_number']
            );
        }
        
        return $location;
    }
    
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
    public function locationNeedsRepartition(int $locationId): bool {
        $levelSettings = $this->levelSettings->getLevelSettings($locationId);
        
        foreach ($levelSettings as $level) {
            if (!$level['enable_auto_repartition']) {
                continue;
            }
            
            $occupancy = $this->getLevelOccupancyPercentage($locationId, $level['level_number']);
            
            // Check if over threshold
            if ($occupancy > $level['repartition_trigger_threshold']) {
                return true;
            }
            
            // Check for policy violations
            if ($this->hasStoragePolicyViolations($locationId, $level)) {
                return true;
            }
            
            // Check for placement rule violations
            if ($this->hasPlacementViolations($locationId, $level['level_number'])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Find optimal level for a product in a location
     * @param int $locationId
     * @param int $productId
     * @param int $excludeLevel
     * @return int|null
     */
    public function findOptimalLevel(int $locationId, int $productId, ?int $excludeLevel = null): ?int {
        $levelSettings = $this->levelSettings->getLevelSettings($locationId);
        $candidates = [];
        
        foreach ($levelSettings as $level) {
            $levelNum = $level['level_number'];
            
            if ($excludeLevel && $levelNum == $excludeLevel) {
                continue;
            }
            
            // Check if product can be placed on this level
            $validation = $this->levelSettings->validateProductPlacement($locationId, $levelNum, $productId);
            
            if ($validation['valid']) {
                $occupancy = $this->getLevelOccupancyPercentage($locationId, $levelNum);
                $availableSpace = 100 - $occupancy;
                
                // Score based on priority, available space, and compliance
                $score = ($level['priority_order'] * 100) + $availableSpace;
                
                // Bonus for levels that prefer this product type
                if ($this->levelPrefersProduct($level, $productId)) {
                    $score += 50;
                }
                
                $candidates[$levelNum] = $score;
            }
        }
        
        if (empty($candidates)) {
            return null;
        }
        
        // Return level with highest score
        arsort($candidates);
        return array_key_first($candidates);
    }
    
    /**
     * Get level occupancy data
     * @param int $locationId
     * @param int $levelNumber
     * @return array
     */
    private function getLevelOccupancyData(int $locationId, int $levelNumber): array {
        $shelfLevel = $this->getLevelName($levelNumber);
        
        $query = "SELECT 
                    COUNT(DISTINCT i.product_id) as unique_products,
                    SUM(i.quantity) as total_items,
                    GROUP_CONCAT(DISTINCT p.category) as categories
                  FROM inventory i
                  JOIN products p ON i.product_id = p.product_id
                  WHERE i.location_id = :location_id 
                  AND i.shelf_level = :shelf_level
                  AND i.quantity > 0";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':location_id' => $locationId,
                ':shelf_level' => $shelfLevel
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get level capacity
            $capacityQuery = "SELECT capacity / levels as level_capacity FROM locations WHERE id = :location_id";
            $capacityStmt = $this->conn->prepare($capacityQuery);
            $capacityStmt->execute([':location_id' => $locationId]);
            $levelCapacity = $capacityStmt->fetchColumn() ?: 0;
            
            $totalItems = $result['total_items'] ?: 0;
            $occupancyPercentage = $levelCapacity > 0 ? ($totalItems / $levelCapacity) * 100 : 0;
            
            return [
                'unique_products' => intval($result['unique_products']),
                'total_items' => intval($totalItems),
                'level_capacity' => intval($levelCapacity),
                'occupancy_percentage' => round($occupancyPercentage, 1),
                'available_space' => max(0, $levelCapacity - $totalItems),
                'categories' => $result['categories'] ? explode(',', $result['categories']) : []
            ];
            
        } catch (PDOException $e) {
            error_log("Error getting level occupancy data: " . $e->getMessage());
            return [
                'unique_products' => 0,
                'total_items' => 0,
                'level_capacity' => 0,
                'occupancy_percentage' => 0,
                'available_space' => 0,
                'categories' => []
            ];
        }
    }
    
    /**
     * Get level occupancy percentage
     * @param int $locationId
     * @param int $levelNumber
     * @return float
     */
    private function getLevelOccupancyPercentage(int $locationId, int $levelNumber): float {
        $data = $this->getLevelOccupancyData($locationId, $levelNumber);
        return $data['occupancy_percentage'];
    }
    
    /**
     * Check for storage policy violations
     * @param int $locationId
     * @param array $levelSettings
     * @return bool
     */
    private function hasStoragePolicyViolations(int $locationId, array $levelSettings): bool {
        if ($levelSettings['storage_policy'] === 'single_product_type') {
            $occupancyData = $this->getLevelOccupancyData($locationId, $levelSettings['level_number']);
            return $occupancyData['unique_products'] > 1;
        }
        
        if ($levelSettings['storage_policy'] === 'category_restricted' && 
            $levelSettings['allowed_product_types']) {
            
            $occupancyData = $this->getLevelOccupancyData($locationId, $levelSettings['level_number']);
            $allowedCategories = $levelSettings['allowed_product_types'];
            
            foreach ($occupancyData['categories'] as $category) {
                if (!in_array($category, $allowedCategories)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check for placement rule violations
     * @param int $locationId
     * @param int $levelNumber
     * @return bool
     */
    private function hasPlacementViolations(int $locationId, int $levelNumber): bool {
        $shelfLevel = $this->getLevelName($levelNumber);
        
        $query = "SELECT DISTINCT i.product_id
                  FROM inventory i
                  WHERE i.location_id = :location_id 
                  AND i.shelf_level = :shelf_level
                  AND i.quantity > 0";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':location_id' => $locationId,
                ':shelf_level' => $shelfLevel
            ]);
            
            $productIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($productIds as $productId) {
                $validation = $this->levelSettings->validateProductPlacement($locationId, $levelNumber, $productId);
                if (!$validation['valid']) {
                    return true;
                }
            }
            
            return false;
            
        } catch (PDOException $e) {
            error_log("Error checking placement violations: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if level prefers a specific product
     * @param array $levelSettings
     * @param int $productId
     * @return bool
     */
    private function levelPrefersProduct(array $levelSettings, int $productId): bool {
        // Get product details
        $query = "SELECT p.category, COALESCE(pu.volume_per_unit, 0) as volume
                  FROM products p
                  LEFT JOIN product_units pu ON p.product_id = pu.product_id
                  WHERE p.product_id = :product_id LIMIT 1";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':product_id' => $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                return false;
            }
            
            // Check volume preferences
            $volume = floatval($product['volume']);
            
            if ($levelSettings['volume_min_liters'] && $levelSettings['volume_max_liters']) {
                return $volume >= $levelSettings['volume_min_liters'] && 
                       $volume <= $levelSettings['volume_max_liters'];
            }
            
            return false;
            
        } catch (PDOException $e) {
            error_log("Error checking level preference: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get current levels count for a location
     * @param int $locationId
     * @return int
     */
    private function getCurrentLevelsCount(int $locationId): int {
        $query = "SELECT MAX(level_number) FROM location_level_settings WHERE location_id = :location_id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':location_id' => $locationId]);
            return intval($stmt->fetchColumn()) ?: 3;
        } catch (PDOException $e) {
            return 3; // Default fallback
        }
    }
    
    /**
     * Get default level settings for a new level
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
            'enable_auto_repartition' => false,
            'repartition_trigger_threshold' => 80,
            'priority_order' => $totalLevels - $levelNumber + 1
        ];
    }
    
    /**
     * Move inventory from one level to another
     * @param int $locationId
     * @param int $fromLevel
     * @param int $toLevel
     * @return bool
     */
    private function moveInventoryFromLevel(int $locationId, int $fromLevel, int $toLevel): bool {
        $fromShelfLevel = $this->getLevelName($fromLevel);
        $toShelfLevel = $this->getLevelName($toLevel);
        
        try {
            $this->conn->beginTransaction();
            
            // Update all inventory on the from level
            $updateQuery = "UPDATE inventory 
                           SET shelf_level = :to_level,
                               updated_at = CURRENT_TIMESTAMP
                           WHERE location_id = :location_id 
                           AND shelf_level = :from_level";
            
            $stmt = $this->conn->prepare($updateQuery);
            $result = $stmt->execute([
                ':location_id' => $locationId,
                ':from_level' => $fromShelfLevel,
                ':to_level' => $toShelfLevel
            ]);
            
            $this->conn->commit();
            return $result;
            
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("Error moving inventory from level: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete level settings
     * @param int $locationId
     * @param int $levelNumber
     * @return bool
     */
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
            error_log("Error deleting level settings: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Convert level number to shelf level name
     * @param int $levelNumber
     * @return string
     */
    private function getLevelName(int $levelNumber): string {
        return match($levelNumber) {
            1 => 'bottom',
            2 => 'middle',
            3 => 'top',
            default => 'middle'
        };
    }
}