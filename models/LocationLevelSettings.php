
<?php
/**
 * LocationLevelSettings Model
 * Manages per-level configuration for locations
 */
class LocationLevelSettings {
    private PDO $conn;
    private string $table = 'location_level_settings';
    
    public function __construct(PDO $connection) {
        $this->conn = $connection;
    }
    
    /**
     * Get all level settings for a location
     * @param int $locationId
     * @return array
     */
    public function getLevelSettings(int $locationId): array {
        $query = "SELECT * FROM {$this->table} 
                  WHERE location_id = :location_id 
                  ORDER BY level_number ASC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':location_id', $locationId, PDO::PARAM_INT);
            $stmt->execute();
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON fields
            foreach ($results as &$result) {
                if ($result['allowed_product_types']) {
                    $result['allowed_product_types'] = json_decode($result['allowed_product_types'], true);
                }
            }
            
            return $results;
        } catch (PDOException $e) {
            error_log("Error getting level settings: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get settings for a specific level
     * @param int $locationId
     * @param int $levelNumber
     * @return array|null
     */
    public function getLevelSetting(int $locationId, int $levelNumber): ?array {
        $query = "SELECT * FROM {$this->table} 
                  WHERE location_id = :location_id AND level_number = :level_number";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':location_id', $locationId, PDO::PARAM_INT);
            $stmt->bindParam(':level_number', $levelNumber, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['allowed_product_types']) {
                $result['allowed_product_types'] = json_decode($result['allowed_product_types'], true);
            }
            
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Error getting level setting: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update or create level settings
     * @param int $locationId
     * @param int $levelNumber
     * @param array $settings
     * @return bool
     */
    public function updateLevelSettings(int $locationId, int $levelNumber, array $settings): bool {
        // Prepare allowed_product_types JSON
        $allowedTypes = null;
        if (isset($settings['allowed_product_types']) && is_array($settings['allowed_product_types'])) {
            $allowedTypes = json_encode($settings['allowed_product_types']);
        }
        
        $query = "INSERT INTO {$this->table} 
                  (location_id, level_number, level_name, storage_policy, allowed_product_types,
                   max_different_products, length_mm, depth_mm, height_mm, max_weight_kg, items_capacity,
                   dedicated_product_id, allow_other_products,
                   volume_min_liters, volume_max_liters, weight_min_kg, weight_max_kg,
                   enable_auto_repartition, repartition_trigger_threshold, priority_order, subdivision_count,
                   requires_special_handling, temperature_controlled, notes)
                  VALUES
                  (:location_id, :level_number, :level_name, :storage_policy, :allowed_product_types,
                   :max_different_products, :length_mm, :depth_mm, :height_mm, :max_weight_kg, :items_capacity,
                   :dedicated_product_id, :allow_other_products,
                   :volume_min_liters, :volume_max_liters, :weight_min_kg, :weight_max_kg,
                   :enable_auto_repartition, :repartition_trigger_threshold, :priority_order, :subdivision_count,
                   :requires_special_handling, :temperature_controlled, :notes)
                  ON DUPLICATE KEY UPDATE
                  level_name = VALUES(level_name),
                  storage_policy = VALUES(storage_policy),
                  allowed_product_types = VALUES(allowed_product_types),
                  max_different_products = VALUES(max_different_products),
                  length_mm = VALUES(length_mm),
                  depth_mm = VALUES(depth_mm),
                  height_mm = VALUES(height_mm),
                  max_weight_kg = VALUES(max_weight_kg),
                  items_capacity = VALUES(items_capacity),
                  dedicated_product_id = VALUES(dedicated_product_id),
                  allow_other_products = VALUES(allow_other_products),
                  volume_min_liters = VALUES(volume_min_liters),
                  volume_max_liters = VALUES(volume_max_liters),
                  weight_min_kg = VALUES(weight_min_kg),
                  weight_max_kg = VALUES(weight_max_kg),
                  enable_auto_repartition = VALUES(enable_auto_repartition),
                  repartition_trigger_threshold = VALUES(repartition_trigger_threshold),
                  priority_order = VALUES(priority_order),
                  subdivision_count = VALUES(subdivision_count),
                  requires_special_handling = VALUES(requires_special_handling),
                  temperature_controlled = VALUES(temperature_controlled),
                  notes = VALUES(notes),
                  updated_at = CURRENT_TIMESTAMP";
        
        try {
            $stmt = $this->conn->prepare($query);
            
            $params = [
                ':location_id' => $locationId,
                ':level_number' => $levelNumber,
                ':level_name' => $settings['level_name'] ?? null,
                ':storage_policy' => $settings['storage_policy'] ?? 'multiple_products',
                ':allowed_product_types' => $allowedTypes,
                ':max_different_products' => $settings['max_different_products'] ?? null,
                ':length_mm' => $settings['length_mm'] ?? 0,
                ':depth_mm' => $settings['depth_mm'] ?? 0,
                ':height_mm' => $settings['height_mm'] ?? 0,
                ':max_weight_kg' => $settings['max_weight_kg'] ?? 0,
                ':items_capacity' => $settings['items_capacity'] ?? null,
                ':dedicated_product_id' => $settings['dedicated_product_id'] ?? null,
                ':allow_other_products' => $settings['allow_other_products'] ?? true,
                ':volume_min_liters' => $settings['volume_min_liters'] ?? null,
                ':volume_max_liters' => $settings['volume_max_liters'] ?? null,
                ':weight_min_kg' => $settings['weight_min_kg'] ?? null,
                ':weight_max_kg' => $settings['weight_max_kg'] ?? null,
                ':enable_auto_repartition' => $settings['enable_auto_repartition'] ?? false,
                ':repartition_trigger_threshold' => $settings['repartition_trigger_threshold'] ?? 80,
                ':priority_order' => $settings['priority_order'] ?? 0,
                ':subdivision_count' => $settings['subdivision_count'] ?? 1,
                ':requires_special_handling' => $settings['requires_special_handling'] ?? false,
                ':temperature_controlled' => $settings['temperature_controlled'] ?? false,
                ':notes' => $settings['notes'] ?? null
            ];
            
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error updating level settings: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validate if a product can be placed on a specific level
     * @param int $locationId
     * @param int $levelNumber
     * @param int $productId
     * @return array ['valid' => bool, 'reason' => string]
     */
    public function validateProductPlacement(int $locationId, int $levelNumber, int $productId): array {
        $levelSettings = $this->getLevelSetting($locationId, $levelNumber);
        if (!$levelSettings) {
            return ['valid' => true, 'reason' => 'No specific restrictions'];
        }
        
        // Get product details
        $productQuery = "SELECT p.category, p.name, 
                               COALESCE(pu.volume_per_unit, 0) as volume,
                               COALESCE(pu.weight_per_unit, 0) as weight
                        FROM products p
                        LEFT JOIN product_units pu ON p.product_id = pu.product_id
                        WHERE p.product_id = :product_id LIMIT 1";
        
        try {
            $stmt = $this->conn->prepare($productQuery);
            $stmt->bindParam(':product_id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                return ['valid' => false, 'reason' => 'Product not found'];
            }
            
            // Check storage policy
            if ($levelSettings['storage_policy'] === 'single_product_type') {
                // Check if level already has different products
                $existingQuery = "SELECT DISTINCT product_id FROM inventory 
                                 WHERE location_id = :location_id 
                                 AND shelf_level = :shelf_level 
                                 AND quantity > 0 
                                 AND product_id != :product_id LIMIT 1";
                
                $existingStmt = $this->conn->prepare($existingQuery);
                $shelfLevel = $this->getLevelName($levelNumber);
                $existingStmt->execute([
                    ':location_id' => $locationId,
                    ':shelf_level' => $shelfLevel,
                    ':product_id' => $productId
                ]);
                
                if ($existingStmt->fetch()) {
                    return ['valid' => false, 'reason' => 'Level restricted to single product type'];
                }
            }
            
            // Check category restrictions
            if ($levelSettings['storage_policy'] === 'category_restricted' && 
                $levelSettings['allowed_product_types']) {
                
                $allowedCategories = $levelSettings['allowed_product_types'];
                if (!in_array($product['category'], $allowedCategories)) {
                    return ['valid' => false, 'reason' => 'Product category not allowed on this level'];
                }
            }
            
            // Check volume constraints
            if ($levelSettings['volume_min_liters'] && $product['volume'] < $levelSettings['volume_min_liters']) {
                return ['valid' => false, 'reason' => 'Product volume too small for this level'];
            }
            
            if ($levelSettings['volume_max_liters'] && $product['volume'] > $levelSettings['volume_max_liters']) {
                return ['valid' => false, 'reason' => 'Product volume too large for this level'];
            }
            
            // Check weight constraints  
            if ($levelSettings['weight_min_kg'] && $product['weight'] < $levelSettings['weight_min_kg']) {
                return ['valid' => false, 'reason' => 'Product weight too light for this level'];
            }
            
            if ($levelSettings['weight_max_kg'] && $product['weight'] > $levelSettings['weight_max_kg']) {
                return ['valid' => false, 'reason' => 'Product weight too heavy for this level'];
            }
            
            return ['valid' => true, 'reason' => 'All constraints satisfied'];
            
        } catch (PDOException $e) {
            error_log("Error validating product placement: " . $e->getMessage());
            return ['valid' => false, 'reason' => 'Validation error'];
        }
    }
    
    /**
     * Get recommended level for a product based on settings
     * @param int $locationId
     * @param int $productId
     * @return int|null Recommended level number
     */
    public function getRecommendedLevel(int $locationId, int $productId): ?int {
        $levelSettings = $this->getLevelSettings($locationId);
        $scores = [];
        
        foreach ($levelSettings as $level) {
            $validation = $this->validateProductPlacement($locationId, $level['level_number'], $productId);
            
            if ($validation['valid']) {
                // Calculate score based on priority and occupancy
                $occupancy = $this->getLevelOccupancy($locationId, $level['level_number']);
                $score = $level['priority_order'] * 100 - $occupancy; // Higher priority, lower occupancy = better
                $scores[$level['level_number']] = $score;
            }
        }
        
        if (empty($scores)) {
            return null;
        }
        
        return array_key_first($scores); // Return level with highest score
    }
    
    /**
     * Get current occupancy percentage for a level
     * @param int $locationId
     * @param int $levelNumber
     * @return float
     */
    private function getLevelOccupancy(int $locationId, int $levelNumber): float {
        $shelfLevel = $this->getLevelName($levelNumber);
        
        $query = "SELECT
                    COALESCE(lls.items_capacity, l.capacity / l.levels) as level_capacity,
                    COALESCE(SUM(i.quantity), 0) as current_items
                  FROM locations l
                  LEFT JOIN location_level_settings lls ON l.id = lls.location_id AND lls.level_number = :level_number
                  LEFT JOIN inventory i ON l.id = i.location_id AND i.shelf_level = :shelf_level
                  WHERE l.id = :location_id
                  GROUP BY l.id, lls.items_capacity";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':location_id' => $locationId,
                ':shelf_level' => $shelfLevel,
                ':level_number' => $levelNumber
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['level_capacity'] > 0) {
                return ($result['current_items'] / $result['level_capacity']) * 100;
            }
            
            return 0.0;
        } catch (PDOException $e) {
            error_log("Error getting level occupancy: " . $e->getMessage());
            return 0.0;
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
    
    /**
     * Create default level settings for a new location
     * @param int $locationId
     * @param int $totalLevels
     * @return bool
     */
    public function createDefaultSettings(int $locationId, int $totalLevels): bool {
        $success = true;
        
        for ($level = 1; $level <= $totalLevels; $level++) {
            $defaultSettings = [
                'level_name' => match($level) {
                    1 => 'Bottom',
                    2 => 'Middle',
                    3 => 'Top',
                    default => "Level $level"
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
                'priority_order' => $totalLevels - $level + 1, // Bottom = highest priority
                'subdivision_count' => 1
            ];
            
            if (!$this->updateLevelSettings($locationId, $level, $defaultSettings)) {
                $success = false;
            }
        }
        
        return $success;
    }

    /**
 * Toggle subdivisions for a level
 * @param int $locationId
 * @param int $levelNumber
 * @param bool $enabled
 * @return bool
 */
public function toggleSubdivisions(int $locationId, int $levelNumber, bool $enabled): bool {
    try {
        // When enabling subdivisions, force multiple_products policy
        $storagePolicy = $enabled ? 'multiple_products' : null;
        
        $query = "UPDATE {$this->table} 
                  SET subdivisions_enabled = :enabled,
                      storage_policy = COALESCE(:storage_policy, storage_policy),
                      updated_at = CURRENT_TIMESTAMP
                  WHERE location_id = :location_id AND level_number = :level_number";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':enabled' => $enabled,
            ':storage_policy' => $storagePolicy,
            ':location_id' => $locationId,
            ':level_number' => $levelNumber
        ]);
        
    } catch (PDOException $e) {
        error_log("Error toggling subdivisions: " . $e->getMessage());
        return false;
    }
}

/**
 * Get subdivision-enabled levels for a location
 * @param int $locationId
 * @return array
 */
public function getSubdivisionEnabledLevels(int $locationId): array {
    try {
        $query = "SELECT level_number, level_name, subdivision_count 
                  FROM {$this->table} 
                  WHERE location_id = :location_id 
                  AND subdivisions_enabled = TRUE 
                  ORDER BY level_number";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':location_id' => $locationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error getting subdivision-enabled levels: " . $e->getMessage());
        return [];
    }
}

/**
 * Update level settings with subdivision data
 * Enhanced version of existing updateLevelSettings to handle subdivisions
 * @param int $locationId
 * @param int $levelNumber
 * @param array $settings - Should include 'subdivisions_enabled' and 'subdivisions' keys
 * @return bool
 */
public function updateLevelSettingsWithSubdivisions(int $locationId, int $levelNumber, array $settings): bool {
    $startedTransaction = false;
    try {
        if (!$this->conn->inTransaction()) {
            $this->conn->beginTransaction();
            $startedTransaction = true;
        }
        
        // Handle subdivision toggle
        $subdivisionsEnabled = $settings['subdivisions_enabled'] ?? false;
        $subdivisions = $settings['subdivisions'] ?? [];
        
        // Update subdivision count based on actual subdivisions
        if ($subdivisionsEnabled && !empty($subdivisions)) {
            $settings['subdivision_count'] = count($subdivisions);
            $settings['storage_policy'] = 'multiple_products'; // Force multiple products
        } else {
            $settings['subdivision_count'] = 1;
            $subdivisionsEnabled = false;
        }
        
        $settings['subdivisions_enabled'] = $subdivisionsEnabled;
        
        // Update the level settings using existing method
        if (!$this->updateLevelSettings($locationId, $levelNumber, $settings)) {
            throw new Exception("Failed to update level settings");
        }
        
        // Handle subdivisions through LocationSubdivision model
        $subdivisionModel = new LocationSubdivision($this->conn);
        
        if ($subdivisionsEnabled && !empty($subdivisions)) {
            // Clear existing subdivisions
            $subdivisionModel->deleteSubdivisions($locationId, $levelNumber);
            
            // Create new subdivisions
            foreach ($subdivisions as $index => $subdivisionData) {
                $subdivisionModel->createSubdivision(
                    $locationId, 
                    $levelNumber, 
                    $index + 1, 
                    $subdivisionData
                );
            }
        } else {
            // Clear subdivisions if disabled
            $subdivisionModel->deleteSubdivisions($locationId, $levelNumber);
        }
        
        if ($startedTransaction) {
            $this->conn->commit();
        }
        return true;

    } catch (Exception $e) {
        if ($startedTransaction && $this->conn->inTransaction()) {
            $this->conn->rollBack();
        }
        error_log("Error updating level settings with subdivisions: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if a level has subdivisions enabled
 * @param int $locationId
 * @param int $levelNumber
 * @return bool
 */
public function hasSubdivisionsEnabled(int $locationId, int $levelNumber): bool {
    try {
        $query = "SELECT subdivisions_enabled FROM {$this->table} 
                  WHERE location_id = :location_id AND level_number = :level_number";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':location_id' => $locationId,
            ':level_number' => $levelNumber
        ]);
        
        return (bool)$stmt->fetchColumn();
        
    } catch (PDOException $e) {
        error_log("Error checking subdivisions enabled: " . $e->getMessage());
        return false;
    }
}

/**
 * Get level settings with subdivision information
 * Enhanced version that includes subdivision data
 * @param int $locationId
 * @return array
 */
public function getLevelSettingsWithSubdivisions(int $locationId): array {
    try {
        // Get basic level settings
        $levelSettings = $this->getLevelSettings($locationId);
        
        // Get subdivision data
        $subdivisionModel = new LocationSubdivision($this->conn);
        $subdivisions = $subdivisionModel->getAllSubdivisions($locationId);
        
        // Merge subdivision data with level settings
        foreach ($levelSettings as &$level) {
            $levelNumber = $level['level_number'];
            $level['subdivisions'] = $subdivisions[$levelNumber] ?? [];
            $level['has_subdivisions'] = !empty($level['subdivisions']);
        }
        
        return $levelSettings;
        
    } catch (Exception $e) {
        error_log("Error getting level settings with subdivisions: " . $e->getMessage());
        return [];
    }
}

/**
 * Validate subdivision configuration for a level
 * @param array $subdivisions
 * @return array ['valid' => bool, 'errors' => array]
 */
public function validateSubdivisionConfiguration(array $subdivisions): array {
    $errors = [];
    $productIds = [];
    
    if (empty($subdivisions)) {
        $errors[] = "At least one subdivision is required when subdivisions are enabled";
        return ['valid' => false, 'errors' => $errors];
    }
    
    foreach ($subdivisions as $index => $subdivision) {
        $subdivisionNum = $index + 1;
        
        // Check required fields
        if (empty($subdivision['product_id'])) {
            $errors[] = "Subdivision {$subdivisionNum}: Product is required";
        }
        
        if (empty($subdivision['capacity']) || $subdivision['capacity'] < 1) {
            $errors[] = "Subdivision {$subdivisionNum}: Capacity must be at least 1";
        }
        
        // Check for duplicate products
        if (!empty($subdivision['product_id'])) {
            if (in_array($subdivision['product_id'], $productIds)) {
                $errors[] = "Subdivision {$subdivisionNum}: Same product cannot be in multiple subdivisions on the same level";
            }
            $productIds[] = $subdivision['product_id'];
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}
}