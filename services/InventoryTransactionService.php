<?php
/**
 * InventoryTransactionService
 * Handles all inventory movement tracking and analytics
 * 
 * Place this file as: services/InventoryTransactionService.php
 */

class InventoryTransactionService {
    private PDO $conn;
    private string $transactionsTable = 'inventory_transactions';
    private string $summaryTable = 'inventory_transaction_summary';
    
    public function __construct(PDO $connection) {
        $this->conn = $connection;
    }
    
    /**
     * Log an inventory transaction
     */
    public function logTransaction(array $data): bool {
        try {
            // Get current inventory quantity for before/after tracking
            $currentQty = $this->getCurrentQuantity($data['product_id'], $data['location_id'] ?? null);
            
            $stmt = $this->conn->prepare("
                INSERT INTO {$this->transactionsTable} (
                    transaction_type, quantity_change, quantity_before, quantity_after,
                    product_id, location_id, source_location_id, batch_number, lot_number,
                    expiry_date, shelf_level, subdivision_number, user_id, reference_type,
                    reference_id, reason, notes, operator_notes, session_id, 
                    parent_transaction_id, unit_cost, total_cost, duration_seconds,
                    system_latency_ms
                ) VALUES (
                    :transaction_type, :quantity_change, :quantity_before, :quantity_after,
                    :product_id, :location_id, :source_location_id, :batch_number, :lot_number,
                    :expiry_date, :shelf_level, :subdivision_number, :user_id, :reference_type,
                    :reference_id, :reason, :notes, :operator_notes, :session_id,
                    :parent_transaction_id, :unit_cost, :total_cost, :duration_seconds,
                    :system_latency_ms
                )
            ");
            
            $quantityAfter = $currentQty + ($data['quantity_change'] ?? 0);
            
            $result = $stmt->execute([
                ':transaction_type' => $data['transaction_type'],
                ':quantity_change' => $data['quantity_change'] ?? 0,
                ':quantity_before' => $currentQty,
                ':quantity_after' => $quantityAfter,
                ':product_id' => $data['product_id'],
                ':location_id' => $data['location_id'] ?? null,
                ':source_location_id' => $data['source_location_id'] ?? null,
                ':batch_number' => $data['batch_number'] ?? null,
                ':lot_number' => $data['lot_number'] ?? null,
                ':expiry_date' => $data['expiry_date'] ?? null,
                ':shelf_level' => $data['shelf_level'] ?? null,
                ':subdivision_number' => $data['subdivision_number'] ?? null,
                ':user_id' => $data['user_id'] ?? $_SESSION['user_id'] ?? 1,
                ':reference_type' => $data['reference_type'] ?? 'manual',
                ':reference_id' => $data['reference_id'] ?? null,
                ':reason' => $data['reason'] ?? null,
                ':notes' => $data['notes'] ?? null,
                ':operator_notes' => $data['operator_notes'] ?? null,
                ':session_id' => $data['session_id'] ?? session_id(),
                ':parent_transaction_id' => $data['parent_transaction_id'] ?? null,
                ':unit_cost' => $data['unit_cost'] ?? null,
                ':total_cost' => $data['total_cost'] ?? null,
                ':duration_seconds' => $data['duration_seconds'] ?? null,
                ':system_latency_ms' => $data['system_latency_ms'] ?? null
            ]);
            
            return $result;
            
        } catch (PDOException $e) {
            error_log("Failed to log transaction: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get location activity data for enhanced tooltip
     */
    public function getLocationActivity(int $locationId): array {
        try {
            // Last movement
            $stmt = $this->conn->prepare("
                SELECT 
                    created_at, 
                    transaction_type, 
                    quantity_change, 
                    user_id, 
                    reason,
                    p.name as product_name
                FROM {$this->transactionsTable} t
                LEFT JOIN products p ON t.product_id = p.product_id
                WHERE t.location_id = :location_id OR t.source_location_id = :location_id
                ORDER BY t.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([':location_id' => $locationId]);
            $lastMovement = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Recent changes (last 24 hours)
            $stmt = $this->conn->prepare("
                SELECT 
                    COUNT(*) as count, 
                    SUM(ABS(quantity_change)) as total_quantity
                FROM {$this->transactionsTable}
                WHERE (location_id = :location_id OR source_location_id = :location_id)
                AND created_at >= (NOW() - INTERVAL 1 DAY)
            ");
            $stmt->execute([':location_id' => $locationId]);
            $recentActivity = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Transaction breakdown by type (last 7 days)
            $stmt = $this->conn->prepare("
                SELECT 
                    transaction_type,
                    COUNT(*) as count,
                    SUM(quantity_change) as net_change
                FROM {$this->transactionsTable}
                WHERE (location_id = :location_id OR source_location_id = :location_id)
                AND created_at >= (NOW() - INTERVAL 7 DAY)
                GROUP BY transaction_type
                ORDER BY count DESC
            ");
            $stmt->execute([':location_id' => $locationId]);
            $transactionBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'last_movement' => $lastMovement['created_at'] ?? null,
                'last_movement_type' => $lastMovement['transaction_type'] ?? null,
                'last_movement_quantity' => $lastMovement['quantity_change'] ?? 0,
                'last_movement_product' => $lastMovement['product_name'] ?? null,
                'recent_changes' => (int)($recentActivity['count'] ?? 0),
                'recent_quantity_moved' => (int)($recentActivity['total_quantity'] ?? 0),
                'transaction_breakdown' => $transactionBreakdown,
                'activity_score' => $this->calculateActivityScore($locationId)
            ];
            
        } catch (PDOException $e) {
            error_log("Error fetching location activity: " . $e->getMessage());
            return [
                'last_movement' => null,
                'last_movement_type' => null,
                'last_movement_quantity' => 0,
                'recent_changes' => 0,
                'recent_quantity_moved' => 0,
                'transaction_breakdown' => [],
                'activity_score' => 0
            ];
        }
    }
    
    /**
     * Calculate activity score for location (0-100)
     */
    private function calculateActivityScore(int $locationId): int {
        try {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as movements
                FROM {$this->transactionsTable}
                WHERE (location_id = :location_id OR source_location_id = :location_id)
                AND created_at >= (NOW() - INTERVAL 7 DAY)
            ");
            $stmt->execute([':location_id' => $locationId]);
            $movements = (int)$stmt->fetchColumn();
            
            // Score calculation: 0-5 movements = 0-20, 6-15 = 21-60, 16+ = 61-100
            if ($movements <= 5) return min(20, $movements * 4);
            if ($movements <= 15) return 20 + (($movements - 5) * 4);
            return min(100, 60 + (($movements - 15) * 2));
            
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get current quantity for product at location
     */
    private function getCurrentQuantity(int $productId, ?int $locationId): int {
        if (!$locationId) return 0;
        
        try {
            $stmt = $this->conn->prepare("
                SELECT COALESCE(SUM(quantity), 0) as total
                FROM inventory
                WHERE product_id = :product_id AND location_id = :location_id
            ");
            $stmt->execute([
                ':product_id' => $productId,
                ':location_id' => $locationId
            ]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get movement trends for analytics
     */
    public function getMovementTrends(int $locationId, int $days = 30): array {
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    DATE(created_at) as movement_date,
                    transaction_type,
                    COUNT(*) as transaction_count,
                    SUM(ABS(quantity_change)) as total_quantity,
                    AVG(duration_seconds) as avg_duration
                FROM {$this->transactionsTable}
                WHERE (location_id = :location_id OR source_location_id = :location_id)
                AND created_at >= (NOW() - INTERVAL :days DAY)
                GROUP BY DATE(created_at), transaction_type
                ORDER BY movement_date DESC, transaction_count DESC
            ");
            $stmt->execute([
                ':location_id' => $locationId,
                ':days' => $days
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error fetching movement trends: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get busiest locations
     */
    public function getBusiestLocations(int $limit = 10, int $days = 7): array {
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    l.location_code,
                    l.zone,
                    COUNT(t.id) as total_transactions,
                    SUM(ABS(t.quantity_change)) as total_quantity_moved,
                    COUNT(DISTINCT t.product_id) as unique_products,
                    COUNT(DISTINCT t.user_id) as unique_operators
                FROM {$this->transactionsTable} t
                JOIN locations l ON (t.location_id = l.id OR t.source_location_id = l.id)
                WHERE t.created_at >= (NOW() - INTERVAL :days DAY)
                GROUP BY l.id, l.location_code, l.zone
                ORDER BY total_transactions DESC
                LIMIT :limit
            ");
            $stmt->execute([
                ':days' => $days,
                ':limit' => $limit
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error fetching busiest locations: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Helper methods for common transaction types
     */
    public function logReceive(int $productId, int $locationId, int $quantity, array $metadata = []): bool {
        return $this->logTransaction(array_merge([
            'transaction_type' => 'receive',
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity_change' => $quantity,
            'reason' => 'Items received from supplier'
        ], $metadata));
    }
    
    public function logMove(int $productId, int $fromLocationId, int $toLocationId, int $quantity, array $metadata = []): bool {
        // Log as two linked transactions for complete audit trail
        $sessionId = uniqid('move_');
        
        // Outbound transaction
        $outbound = $this->logTransaction(array_merge([
            'transaction_type' => 'move',
            'product_id' => $productId,
            'location_id' => $fromLocationId,
            'quantity_change' => -$quantity,
            'session_id' => $sessionId,
            'reason' => 'Moved to location ' . $toLocationId
        ], $metadata));
        
        // Inbound transaction
        $inbound = $this->logTransaction(array_merge([
            'transaction_type' => 'move',
            'product_id' => $productId,
            'location_id' => $toLocationId,
            'source_location_id' => $fromLocationId,
            'quantity_change' => $quantity,
            'session_id' => $sessionId,
            'reason' => 'Moved from location ' . $fromLocationId
        ], $metadata));
        
        return $outbound && $inbound;
    }
    
    public function logPick(int $productId, int $locationId, int $quantity, array $metadata = []): bool {
        return $this->logTransaction(array_merge([
            'transaction_type' => 'pick',
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity_change' => -$quantity,
            'reason' => 'Items picked for order'
        ], $metadata));
    }
    
    public function logAdjustment(int $productId, int $locationId, int $quantityChange, string $reason, array $metadata = []): bool {
        return $this->logTransaction(array_merge([
            'transaction_type' => 'adjust',
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity_change' => $quantityChange,
            'reason' => $reason
        ], $metadata));
    }
    
    /**
     * Get warehouse activity dashboard data
     */
    public function getWarehouseActivitySummary(): array {
        try {
            // Get today's activity
            $stmt = $this->conn->prepare("
                SELECT 
                    transaction_type,
                    COUNT(*) as count,
                    SUM(ABS(quantity_change)) as total_quantity
                FROM {$this->transactionsTable} 
                WHERE DATE(created_at) = CURDATE()
                GROUP BY transaction_type
            ");
            $stmt->execute();
            $todayActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get busiest locations
            $busiestLocations = $this->getBusiestLocations(5, 7);
            
            // Get performance metrics
            $stmt = $this->conn->prepare("
                SELECT 
                    AVG(duration_seconds) as avg_duration,
                    AVG(system_latency_ms) as avg_latency,
                    COUNT(*) as total_transactions
                FROM {$this->transactionsTable} 
                WHERE created_at >= (NOW() - INTERVAL 24 HOUR)
                AND duration_seconds IS NOT NULL
            ");
            $stmt->execute();
            $performance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'today_activity' => $todayActivity,
                'busiest_locations' => $busiestLocations,
                'performance_metrics' => $performance
            ];
            
        } catch (Exception $e) {
            error_log("Error getting warehouse activity summary: " . $e->getMessage());
            return [
                'today_activity' => [],
                'busiest_locations' => [],
                'performance_metrics' => []
            ];
        }
    }
    
    /**
     * Check if transaction system is available
     */
    public function isTransactionSystemAvailable(): bool {
        try {
            $stmt = $this->conn->query("SHOW TABLES LIKE '{$this->transactionsTable}'");
            return $stmt && $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
?>