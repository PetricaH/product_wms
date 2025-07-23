<?php
/**
 * AutoRepartitionService
 * Handles automatic product redistribution across shelf levels
 */
class AutoRepartitionService {
    private PDO $conn;
    private LocationLevelSettings $levelSettings;
    private bool $dryRun = false;
    
    public function __construct(PDO $connection, LocationLevelSettings $levelSettings) {
        $this->conn = $connection;
        $this->levelSettings = $levelSettings;
    }
    
    /**
     * Set dry run mode (analyze only, don't execute)
     * @param bool $dryRun
     */
    public function setDryRun(bool $dryRun): void {
        $this->dryRun = $dryRun;
    }
    
    /**
     * Analyze and execute repartition for all locations with auto-repartition enabled
     * @return array Results summary
     */
    public function processAllLocations(): array {
        $results = [
            'processed_locations' => 0,
            'total_moves' => 0,
            'errors' => [],
            'moves_details' => []
        ];
        
        // Get all locations with auto-repartition enabled
        $locationsQuery = "SELECT DISTINCT l.id, l.location_code, l.zone
                          FROM locations l
                          JOIN location_level_settings lls ON l.id = lls.location_id
                          WHERE l.type = 'shelf' 
                          AND l.status = 'active'
                          AND lls.enable_auto_repartition = true";
        
        try {
            $stmt = $this->conn->prepare($locationsQuery);
            $stmt->execute();
            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($locations as $location) {
                $locationResult = $this->processLocation($location['id']);
                $results['processed_locations']++;
                $results['total_moves'] += $locationResult['moves_count'];
                
                if (!empty($locationResult['errors'])) {
                    $results['errors'] = array_merge($results['errors'], $locationResult['errors']);
                }
                
                if (!empty($locationResult['moves'])) {
                    $results['moves_details'][$location['location_code']] = $locationResult['moves'];
                }
            }
            
        } catch (PDOException $e) {
            $results['errors'][] = "Database error: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Process repartition for a specific location
     * @param int $locationId
     * @return array
     */
    public function processLocation(int $locationId): array {
        $result = [
            'moves_count' => 0,
            'moves' => [],
            'errors' => []
        ];
        
        try {
            // Get current inventory and analyze
            $analysis = $this->analyzeLocationInventory($locationId);
            
            if (empty($analysis['issues'])) {
                return $result; // No issues found
            }
            
            // Generate repartition plan
            $plan = $this->generateRepartitionPlan($locationId, $analysis);
            
            if (empty($plan)) {
                return $result; // No moves needed
            }
            
            // Execute moves if not in dry run mode
            if (!$this->dryRun) {
                foreach ($plan as $move) {
                    if ($this->executeMove($move)) {
                        $result['moves'][] = $move;
                        $result['moves_count']++;
                    } else {
                        $result['errors'][] = "Failed to move product {$move['product_id']} from level {$move['from_level']} to level {$move['to_level']}";
                    }
                }
            } else {
                $result['moves'] = $plan;
                $result['moves_count'] = count($plan);
            }
            
        } catch (Exception $e) {
            $result['errors'][] = "Error processing location $locationId: " . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Analyze current inventory and identify issues
     * @param int $locationId
     * @return array
     */
    private function analyzeLocationInventory(int $locationId): array {
        $analysis = [
            'levels' => [],
            'issues' => [],
            'total_occupancy' => 0
        ];
        
        // Get level settings and current inventory
        $levelSettings = $this->levelSettings->getLevelSettings($locationId);
        
        foreach ($levelSettings as $level) {
            $levelAnalysis = $this->analyzeLevelInventory($locationId, $level);
            $analysis['levels'][$level['level_number']] = $levelAnalysis;
            
            // Check for issues
            if ($levelAnalysis['occupancy'] > $level['repartition_trigger_threshold']) {
                $analysis['issues'][] = [
                    'type' => 'over_threshold',
                    'level' => $level['level_number'],
                    'occupancy' => $levelAnalysis['occupancy'],
                    'threshold' => $level['repartition_trigger_threshold']
                ];
            }
            
            // Check storage policy violations
            if ($level['storage_policy'] === 'single_product_type' && 
                count($levelAnalysis['products']) > 1) {
                
                $analysis['issues'][] = [
                    'type' => 'policy_violation',
                    'level' => $level['level_number'],
                    'policy' => 'single_product_type',
                    'product_count' => count($levelAnalysis['products'])
                ];
            }
            
            // Check product placement rules
            foreach ($levelAnalysis['products'] as $product) {
                $validation = $this->levelSettings->validateProductPlacement(
                    $locationId, 
                    $level['level_number'], 
                    $product['product_id']
                );
                
                if (!$validation['valid']) {
                    $analysis['issues'][] = [
                        'type' => 'placement_violation',
                        'level' => $level['level_number'],
                        'product_id' => $product['product_id'],
                        'reason' => $validation['reason']
                    ];
                }
            }
        }
        
        return $analysis;
    }
    
    /**
     * Analyze inventory for a specific level
     * @param int $locationId
     * @param array $levelSettings
     * @return array
     */
    private function analyzeLevelInventory(int $locationId, array $levelSettings): array {
        $shelfLevel = $this->getLevelName($levelSettings['level_number']);
        
        $query = "SELECT 
                    i.product_id,
                    i.quantity,
                    p.name as product_name,
                    p.category,
                    COALESCE(pu.volume_per_unit, 0) as volume_per_unit,
                    COALESCE(pu.weight_per_unit, 0) as weight_per_unit
                  FROM inventory i
                  JOIN products p ON i.product_id = p.product_id
                  LEFT JOIN product_units pu ON p.product_id = pu.product_id
                  WHERE i.location_id = :location_id 
                  AND i.shelf_level = :shelf_level
                  AND i.quantity > 0";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':location_id' => $locationId,
                ':shelf_level' => $shelfLevel
            ]);
            
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totalQuantity = array_sum(array_column($products, 'quantity'));
            
            // Calculate level capacity
            $capacityQuery = "SELECT capacity / levels as level_capacity FROM locations WHERE id = :location_id";
            $capacityStmt = $this->conn->prepare($capacityQuery);
            $capacityStmt->execute([':location_id' => $locationId]);
            $levelCapacity = $capacityStmt->fetchColumn() ?: 0;
            
            $occupancy = $levelCapacity > 0 ? ($totalQuantity / $levelCapacity) * 100 : 0;
            
            return [
                'products' => $products,
                'total_quantity' => $totalQuantity,
                'level_capacity' => $levelCapacity,
                'occupancy' => $occupancy,
                'available_space' => max(0, $levelCapacity - $totalQuantity)
            ];
            
        } catch (PDOException $e) {
            error_log("Error analyzing level inventory: " . $e->getMessage());
            return [
                'products' => [],
                'total_quantity' => 0,
                'level_capacity' => 0,
                'occupancy' => 0,
                'available_space' => 0
            ];
        }
    }
    
    /**
     * Generate a repartition plan based on analysis
     * @param int $locationId
     * @param array $analysis
     * @return array
     */
    private function generateRepartitionPlan(int $locationId, array $analysis): array {
        $plan = [];
        
        foreach ($analysis['issues'] as $issue) {
            switch ($issue['type']) {
                case 'over_threshold':
                    $moves = $this->planOverThresholdMoves($locationId, $issue, $analysis['levels']);
                    $plan = array_merge($plan, $moves);
                    break;
                    
                case 'policy_violation':
                    $moves = $this->planPolicyViolationMoves($locationId, $issue, $analysis['levels']);
                    $plan = array_merge($plan, $moves);
                    break;
                    
                case 'placement_violation':
                    $moves = $this->planPlacementViolationMoves($locationId, $issue, $analysis['levels']);
                    $plan = array_merge($plan, $moves);
                    break;
            }
        }
        
        return $plan;
    }
    
    /**
     * Plan moves for over-threshold levels
     * @param int $locationId
     * @param array $issue
     * @param array $levels
     * @return array
     */
    private function planOverThresholdMoves(int $locationId, array $issue, array $levels): array {
        $moves = [];
        $fromLevel = $issue['level'];
        $fromLevelData = $levels[$fromLevel];
        
        // Find products that can be moved to other levels
        foreach ($fromLevelData['products'] as $product) {
            $bestTarget = $this->findBestTargetLevel($locationId, $product['product_id'], $fromLevel, $levels);
            
            if ($bestTarget) {
                // Calculate quantity to move (start with smaller amounts)
                $moveQuantity = min($product['quantity'], 
                                  ceil($product['quantity'] * 0.5), // Move up to 50%
                                  $levels[$bestTarget]['available_space']);
                
                if ($moveQuantity > 0) {
                    $moves[] = [
                        'location_id' => $locationId,
                        'product_id' => $product['product_id'],
                        'product_name' => $product['product_name'],
                        'from_level' => $fromLevel,
                        'to_level' => $bestTarget,
                        'quantity' => $moveQuantity,
                        'reason' => 'Over threshold - redistributing load'
                    ];
                }
            }
        }
        
        return $moves;
    }
    
    /**
     * Plan moves for policy violations
     * @param int $locationId
     * @param array $issue
     * @param array $levels
     * @return array
     */
    private function planPolicyViolationMoves(int $locationId, array $issue, array $levels): array {
        $moves = [];
        $fromLevel = $issue['level'];
        $fromLevelData = $levels[$fromLevel];
        
        if ($issue['policy'] === 'single_product_type') {
            // Keep the product with highest quantity, move others
            $products = $fromLevelData['products'];
            usort($products, fn($a, $b) => $b['quantity'] <=> $a['quantity']);
            
            // Move all but the first (highest quantity) product
            for ($i = 1; $i < count($products); $i++) {
                $product = $products[$i];
                $bestTarget = $this->findBestTargetLevel($locationId, $product['product_id'], $fromLevel, $levels);
                
                if ($bestTarget) {
                    $moves[] = [
                        'location_id' => $locationId,
                        'product_id' => $product['product_id'],
                        'product_name' => $product['product_name'],
                        'from_level' => $fromLevel,
                        'to_level' => $bestTarget,
                        'quantity' => $product['quantity'],
                        'reason' => 'Single product type policy violation'
                    ];
                }
            }
        }
        
        return $moves;
    }
    
    /**
     * Plan moves for placement violations
     * @param int $locationId
     * @param array $issue
     * @param array $levels
     * @return array
     */
    private function planPlacementViolationMoves(int $locationId, array $issue, array $levels): array {
        $moves = [];
        $fromLevel = $issue['level'];
        $productId = $issue['product_id'];
        
        // Find the product in the level data
        $fromLevelData = $levels[$fromLevel];
        $product = null;
        
        foreach ($fromLevelData['products'] as $p) {
            if ($p['product_id'] == $productId) {
                $product = $p;
                break;
            }
        }
        
        if ($product) {
            $bestTarget = $this->findBestTargetLevel($locationId, $productId, $fromLevel, $levels);
            
            if ($bestTarget) {
                $moves[] = [
                    'location_id' => $locationId,
                    'product_id' => $productId,
                    'product_name' => $product['product_name'],
                    'from_level' => $fromLevel,
                    'to_level' => $bestTarget,
                    'quantity' => $product['quantity'],
                    'reason' => $issue['reason']
                ];
            }
        }
        
        return $moves;
    }
    
    /**
     * Find the best target level for a product
     * @param int $locationId
     * @param int $productId
     * @param int $excludeLevel
     * @param array $levels
     * @return int|null
     */
    private function findBestTargetLevel(int $locationId, int $productId, int $excludeLevel, array $levels): ?int {
        $candidates = [];
        
        foreach ($levels as $levelNum => $levelData) {
            if ($levelNum == $excludeLevel) continue;
            
            // Check if product can be placed on this level
            $validation = $this->levelSettings->validateProductPlacement($locationId, $levelNum, $productId);
            
            if ($validation['valid'] && $levelData['available_space'] > 0) {
                // Calculate score: priority * available_space_ratio
                $spaceRatio = $levelData['available_space'] / max($levelData['level_capacity'], 1);
                $score = $levelData['level_capacity'] * $spaceRatio;
                
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
     * Execute a single move
     * @param array $move
     * @return bool
     */
    private function executeMove(array $move): bool {
        try {
            $this->conn->beginTransaction();
            
            $fromShelfLevel = $this->getLevelName($move['from_level']);
            $toShelfLevel = $this->getLevelName($move['to_level']);
            
            // Update the inventory record
            $updateQuery = "UPDATE inventory 
                           SET shelf_level = :to_level,
                               quantity = quantity - :quantity,
                               updated_at = CURRENT_TIMESTAMP
                           WHERE location_id = :location_id 
                           AND product_id = :product_id 
                           AND shelf_level = :from_level
                           AND quantity >= :quantity";
            
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateResult = $updateStmt->execute([
                ':location_id' => $move['location_id'] ?? 0,
                ':product_id' => $move['product_id'],
                ':from_level' => $fromShelfLevel,
                ':to_level' => $toShelfLevel,
                ':quantity' => $move['quantity']
            ]);
            
            if (!$updateResult) {
                throw new Exception("Failed to update inventory");
            }
            
            // Insert or update target level inventory
            $insertQuery = "INSERT INTO inventory 
                           (location_id, product_id, shelf_level, quantity, received_at)
                           VALUES (:location_id, :product_id, :to_level, :quantity, NOW())
                           ON DUPLICATE KEY UPDATE 
                           quantity = quantity + :quantity,
                           updated_at = CURRENT_TIMESTAMP";
            
            $insertStmt = $this->conn->prepare($insertQuery);
            $insertResult = $insertStmt->execute([
                ':location_id' => $move['location_id'] ?? 0,
                ':product_id' => $move['product_id'],
                ':to_level' => $toShelfLevel,
                ':quantity' => $move['quantity']
            ]);
            
            if (!$insertResult) {
                throw new Exception("Failed to insert/update target inventory");
            }
            
            // Clean up zero quantities
            $cleanupQuery = "DELETE FROM inventory 
                            WHERE location_id = :location_id 
                            AND product_id = :product_id 
                            AND shelf_level = :from_level 
                            AND quantity <= 0";
            
            $cleanupStmt = $this->conn->prepare($cleanupQuery);
            $cleanupStmt->execute([
                ':location_id' => $move['location_id'] ?? 0,
                ':product_id' => $move['product_id'],
                ':from_level' => $fromShelfLevel
            ]);
            
            $this->conn->commit();
            
            // Log the move
            $this->logMove($move);
            
            return true;
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("Failed to execute move: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log a move operation
     * @param array $move
     */
    private function logMove(array $move): void {
        $logMessage = sprintf(
            "Auto-repartition: Moved %d units of product %d from level %d to level %d. Reason: %s",
            $move['quantity'],
            $move['product_id'],
            $move['from_level'],
            $move['to_level'],
            $move['reason']
        );
        
        error_log($logMessage);
        
        // Could also log to a dedicated repartition_log table if needed
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