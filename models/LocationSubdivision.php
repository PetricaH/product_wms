<?php
class LocationSubdivision {
    private PDO $conn;
    private string $table = 'location_subdivisions';

    public function __construct(PDO $connection) {
        $this->conn = $connection;
    }

    public function getAllSubdivisions(int $locationId): array {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE location_id = :loc ORDER BY level_number, subdivision_number");
        $stmt->execute([':loc' => $locationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $grouped = [];
        foreach ($rows as $row) {
            $lvl = (int)$row['level_number'];
            $grouped[$lvl][] = $row;
        }
        return $grouped;
    }

    public function syncSubdivisions(int $locationId, int $levelNumber, array $settings): bool {
        $count = max(1, (int)($settings['subdivision_count'] ?? 1));
        try {
            $query = "INSERT INTO {$this->table}
                (location_id, level_number, subdivision_number, items_capacity, dedicated_product_id, allow_other_products, notes)
                VALUES (:loc, :lvl, :sub, :cap, :ded, :allow, :notes)
                ON DUPLICATE KEY UPDATE
                    items_capacity = VALUES(items_capacity),
                    dedicated_product_id = VALUES(dedicated_product_id),
                    allow_other_products = VALUES(allow_other_products),
                    notes = VALUES(notes),
                    updated_at = CURRENT_TIMESTAMP";
            $stmt = $this->conn->prepare($query);
            for ($i = 1; $i <= $count; $i++) {
                $stmt->execute([
                    ':loc' => $locationId,
                    ':lvl' => $levelNumber,
                    ':sub' => $i,
                    ':cap' => $settings['items_capacity'] ?? null,
                    ':ded' => $settings['dedicated_product_id'] ?? null,
                    ':allow' => $settings['allow_other_products'] ?? true,
                    ':notes' => $settings['notes'] ?? null
                ]);
            }
            $del = $this->conn->prepare("DELETE FROM {$this->table} WHERE location_id = :loc AND level_number = :lvl AND subdivision_number > :cnt");
            $del->execute([':loc' => $locationId, ':lvl' => $levelNumber, ':cnt' => $count]);
            return true;
        } catch (PDOException $e) {
            error_log('Error syncing subdivisions: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteSubdivisions(int $locationId, int $levelNumber): bool {
        try {
            $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE location_id = :loc AND level_number = :lvl");
            return $stmt->execute([':loc' => $locationId, ':lvl' => $levelNumber]);
        } catch (PDOException $e) {
            error_log('Error deleting subdivisions: ' . $e->getMessage());
            return false;
        }
    }

    /**
 * Create a subdivision with enhanced product information
 * @param int $locationId
 * @param int $levelNumber
 * @param int $subdivisionNumber
 * @param array $subdivisionData
 * @return bool
 */
public function createSubdivision(int $locationId, int $levelNumber, int $subdivisionNumber, array $subdivisionData): bool {
    try {
        // Get product information if product_id is provided
        $productName = null;
        if (!empty($subdivisionData['product_id'])) {
            $productName = $this->getProductName($subdivisionData['product_id']);
        }
        
        $query = "INSERT INTO {$this->table} 
                  (location_id, level_number, subdivision_number, items_capacity, product_capacity,
                   dedicated_product_id, product_name, allow_other_products, notes, created_at)
                  VALUES (:location_id, :level_number, :subdivision_number, :items_capacity, :product_capacity,
                          :dedicated_product_id, :product_name, :allow_other_products, :notes, NOW())
                  ON DUPLICATE KEY UPDATE
                      items_capacity = VALUES(items_capacity),
                      product_capacity = VALUES(product_capacity),
                      dedicated_product_id = VALUES(dedicated_product_id),
                      product_name = VALUES(product_name),
                      allow_other_products = VALUES(allow_other_products),
                      notes = VALUES(notes),
                      updated_at = CURRENT_TIMESTAMP";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':location_id' => $locationId,
            ':level_number' => $levelNumber,
            ':subdivision_number' => $subdivisionNumber,
            ':items_capacity' => $subdivisionData['capacity'] ?? null,
            ':product_capacity' => $subdivisionData['capacity'] ?? null, // Same as items_capacity for now
            ':dedicated_product_id' => $subdivisionData['product_id'] ?? null,
            ':product_name' => $productName,
            ':allow_other_products' => false, // For subdivisions, each is dedicated to one product
            ':notes' => $subdivisionData['notes'] ?? null
        ]);
        
    } catch (PDOException $e) {
        error_log("Error creating subdivision: " . $e->getMessage());
        return false;
    }
}

/**
 * Get product name by ID
 * @param int $productId
 * @return string|null
 */
private function getProductName(int $productId): ?string {
    try {
        $stmt = $this->conn->prepare("SELECT name FROM products WHERE product_id = :product_id");
        $stmt->execute([':product_id' => $productId]);
        return $stmt->fetchColumn() ?: null;
        
    } catch (PDOException $e) {
        error_log("Error getting product name: " . $e->getMessage());
        return null;
    }
}

/**
 * Get subdivisions with detailed product information
 * @param int $locationId
 * @param int|null $levelNumber
 * @return array
 */
public function getSubdivisionsWithProducts(int $locationId, ?int $levelNumber = null): array {
    try {
        $query = "SELECT 
                    ls.*,
                    p.name as product_name,
                    p.sku as product_code,
                    p.category as product_category,
                    COALESCE(SUM(i.quantity), 0) as current_stock
                  FROM {$this->table} ls
                  LEFT JOIN products p ON ls.dedicated_product_id = p.product_id
                  LEFT JOIN inventory i ON ls.location_id = i.location_id 
                      AND i.product_id = ls.dedicated_product_id
                      AND i.subdivision_number = ls.subdivision_number
                  WHERE ls.location_id = :location_id";
        
        $params = [':location_id' => $locationId];
        
        if ($levelNumber !== null) {
            $query .= " AND ls.level_number = :level_number";
            $params[':level_number'] = $levelNumber;
        }
        
        $query .= " GROUP BY ls.id, p.product_id, p.name, p.sku, p.category
                    ORDER BY ls.level_number, ls.subdivision_number";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $subdivisions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate occupancy percentage
        foreach ($subdivisions as &$subdivision) {
            $capacity = (int)($subdivision['items_capacity'] ?: $subdivision['product_capacity'] ?: 0);
            $currentStock = (int)$subdivision['current_stock'];
            
            $subdivision['occupancy_percentage'] = $capacity > 0 ? 
                round(($currentStock / $capacity) * 100, 1) : 0;
            $subdivision['available_capacity'] = max(0, $capacity - $currentStock);
        }
        
        return $subdivisions;
        
    } catch (PDOException $e) {
        error_log("Error getting subdivisions with products: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if a product can be placed in a subdivision
 * @param int $locationId
 * @param int $levelNumber
 * @param int $subdivisionNumber
 * @param int $productId
 * @param int $quantity
 * @return array ['allowed' => bool, 'reason' => string, 'available_capacity' => int]
 */
public function canPlaceProduct(int $locationId, int $levelNumber, int $subdivisionNumber, int $productId, int $quantity = 1): array {
    try {
        // Get subdivision details
        $subdivision = $this->getSubdivisionDetails($locationId, $levelNumber, $subdivisionNumber);
        
        if (!$subdivision) {
            return [
                'allowed' => false,
                'reason' => 'Subdivision not found',
                'available_capacity' => 0
            ];
        }
        
        // Check if product matches dedicated product
        if ($subdivision['dedicated_product_id'] && $subdivision['dedicated_product_id'] != $productId) {
            return [
                'allowed' => false,
                'reason' => 'Subdivision is dedicated to a different product: ' . ($subdivision['product_name'] ?: 'Unknown'),
                'available_capacity' => 0
            ];
        }
        
        // Check capacity
        $capacity = (int)($subdivision['items_capacity'] ?: $subdivision['product_capacity'] ?: 0);
        $currentStock = (int)$subdivision['current_stock'];
        $availableCapacity = $capacity - $currentStock;
        
        if ($quantity > $availableCapacity) {
            return [
                'allowed' => false,
                'reason' => "Insufficient capacity. Available: {$availableCapacity}, Required: {$quantity}",
                'available_capacity' => $availableCapacity
            ];
        }
        
        return [
            'allowed' => true,
            'reason' => 'Product can be placed',
            'available_capacity' => $availableCapacity
        ];
        
    } catch (Exception $e) {
        error_log("Error checking product placement: " . $e->getMessage());
        return [
            'allowed' => false,
            'reason' => 'Error checking placement: ' . $e->getMessage(),
            'available_capacity' => 0
        ];
    }
}

/**
 * Get subdivision details with current stock
 * @param int $locationId
 * @param int $levelNumber
 * @param int $subdivisionNumber
 * @return array|null
 */
public function getSubdivisionDetails(int $locationId, int $levelNumber, int $subdivisionNumber): ?array {
    try {
        $query = "SELECT 
                    ls.*,
                    p.name as product_name,
                    p.sku as product_code,
                    COALESCE(SUM(i.quantity), 0) as current_stock
                  FROM {$this->table} ls
                  LEFT JOIN products p ON ls.dedicated_product_id = p.product_id
                  LEFT JOIN inventory i ON ls.location_id = i.location_id 
                      AND i.product_id = ls.dedicated_product_id
                      AND i.subdivision_number = ls.subdivision_number
                  WHERE ls.location_id = :location_id 
                      AND ls.level_number = :level_number 
                      AND ls.subdivision_number = :subdivision_number
                  GROUP BY ls.id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':location_id' => $locationId,
            ':level_number' => $levelNumber,
            ':subdivision_number' => $subdivisionNumber
        ]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        
    } catch (PDOException $e) {
        error_log("Error getting subdivision details: " . $e->getMessage());
        return null;
    }
}

/**
 * Update subdivision capacity
 * @param int $locationId
 * @param int $levelNumber
 * @param int $subdivisionNumber
 * @param int $newCapacity
 * @return bool
 */
public function updateSubdivisionCapacity(int $locationId, int $levelNumber, int $subdivisionNumber, int $newCapacity): bool {
    try {
        $query = "UPDATE {$this->table} 
                  SET items_capacity = :capacity,
                      product_capacity = :capacity,
                      updated_at = CURRENT_TIMESTAMP
                  WHERE location_id = :location_id 
                      AND level_number = :level_number 
                      AND subdivision_number = :subdivision_number";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':capacity' => $newCapacity,
            ':location_id' => $locationId,
            ':level_number' => $levelNumber,
            ':subdivision_number' => $subdivisionNumber
        ]);
        
    } catch (PDOException $e) {
        error_log("Error updating subdivision capacity: " . $e->getMessage());
        return false;
    }
}

/**
 * Get subdivision statistics for a location
 * @param int $locationId
 * @return array
 */
public function getSubdivisionStats(int $locationId): array {
    try {
        $query = "SELECT 
                    COUNT(*) as total_subdivisions,
                    COUNT(DISTINCT level_number) as levels_with_subdivisions,
                    COUNT(DISTINCT dedicated_product_id) as unique_products,
                    SUM(items_capacity) as total_capacity,
                    AVG(CASE 
                        WHEN items_capacity > 0 
                        THEN (COALESCE(current_stock, 0) / items_capacity) * 100 
                        ELSE 0 
                    END) as avg_occupancy_percentage
                  FROM (
                      SELECT 
                          ls.*,
                          COALESCE(SUM(i.quantity), 0) as current_stock
                      FROM {$this->table} ls
                      LEFT JOIN inventory i ON ls.location_id = i.location_id 
                          AND i.product_id = ls.dedicated_product_id
                          AND i.subdivision_number = ls.subdivision_number
                      WHERE ls.location_id = :location_id
                      GROUP BY ls.id
                  ) subdivision_stats";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':location_id' => $locationId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total_subdivisions' => (int)($stats['total_subdivisions'] ?? 0),
            'levels_with_subdivisions' => (int)($stats['levels_with_subdivisions'] ?? 0),
            'unique_products' => (int)($stats['unique_products'] ?? 0),
            'total_capacity' => (int)($stats['total_capacity'] ?? 0),
            'avg_occupancy_percentage' => round((float)($stats['avg_occupancy_percentage'] ?? 0), 1)
        ];
        
    } catch (PDOException $e) {
        error_log("Error getting subdivision stats: " . $e->getMessage());
        return [
            'total_subdivisions' => 0,
            'levels_with_subdivisions' => 0,
            'unique_products' => 0,
            'total_capacity' => 0,
            'avg_occupancy_percentage' => 0
        ];
    }
}

/**
 * Enhanced syncSubdivisions method to handle the new subdivision system
 * @param int $locationId
 * @param int $levelNumber
 * @param array $settings - Should include 'subdivisions' array if subdivisions are enabled
 * @return bool
 */
public function syncEnhancedSubdivisions(int $locationId, int $levelNumber, array $settings): bool {
    try {
        $this->conn->beginTransaction();
        
        // Clear existing subdivisions for this level
        $this->deleteSubdivisions($locationId, $levelNumber);
        
        // If subdivisions are provided, create them
        if (isset($settings['subdivisions']) && is_array($settings['subdivisions'])) {
            foreach ($settings['subdivisions'] as $index => $subdivisionData) {
                if (!$this->createSubdivision($locationId, $levelNumber, $index + 1, $subdivisionData)) {
                    throw new Exception("Failed to create subdivision " . ($index + 1));
                }
            }
        }
        
        $this->conn->commit();
        return true;
        
    } catch (Exception $e) {
        $this->conn->rollBack();
        error_log("Error syncing enhanced subdivisions: " . $e->getMessage());
        return false;
    }
}
}
