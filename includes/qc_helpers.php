<?php
/**
 * QC Helper Functions
 * File: includes/qc_helpers.php
 * 
 * Helper functions for Quality Control operations
 */

/**
 * Get default location ID by type
 * @param PDO $db Database connection
 * @param string $locationType Location type (qc_hold, quarantine, pending_approval)
 * @return int|null Location ID or null if not found
 */
if (!function_exists('getDefaultLocationId')) {
    function getDefaultLocationId($db, $locationType) {
        $defaultLocations = [
            'qc_hold' => 'QC-HOLD',
            'quarantine' => 'QUARANTINE', 
            'pending_approval' => 'PENDING-APPROVAL'
        ];
        
        $locationCode = $defaultLocations[$locationType] ?? null;
        if (!$locationCode) {
            return null;
        }
        
        try {
            $stmt = $db->prepare("
                SELECT id FROM locations 
                WHERE location_code = :location_code 
                AND type = :type 
                AND status = 'active' 
                LIMIT 1
            ");
            $stmt->execute([
                ':location_code' => $locationCode,
                ':type' => $locationType
            ]);
            
            $result = $stmt->fetchColumn();
            return $result ? (int)$result : null;
            
        } catch (PDOException $e) {
            error_log("Error getting default location ID: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Create default QC locations if they don't exist
 * @param PDO $db Database connection
 * @return array Results of creation attempts
 */
function createDefaultQcLocations($db) {
    $defaultLocations = [
        [
            'location_code' => 'QC-HOLD',
            'zone' => 'QC',
            'type' => 'qc_hold',
            'capacity' => 1000,
            'notes' => 'Default QC Hold location for items pending approval'
        ],
        [
            'location_code' => 'QUARANTINE',
            'zone' => 'QC', 
            'type' => 'quarantine',
            'capacity' => 500,
            'notes' => 'Default Quarantine location for damaged/defective items'
        ],
        [
            'location_code' => 'PENDING-APPROVAL',
            'zone' => 'QC',
            'type' => 'pending_approval', 
            'capacity' => 500,
            'notes' => 'Default location for items awaiting supervisor approval'
        ]
    ];
    
    $results = [];
    
    foreach ($defaultLocations as $location) {
        try {
            // Check if location already exists
            $stmt = $db->prepare("
                SELECT id FROM locations 
                WHERE location_code = :location_code
            ");
            $stmt->execute([':location_code' => $location['location_code']]);
            
            if ($stmt->fetchColumn()) {
                $results[] = [
                    'location_code' => $location['location_code'],
                    'status' => 'exists',
                    'message' => 'Location already exists'
                ];
                continue;
            }
            
            // Create new location
            $stmt = $db->prepare("
                INSERT INTO locations (
                    location_code, zone, type, capacity, status, notes, created_at
                ) VALUES (
                    :location_code, :zone, :type, :capacity, 'active', :notes, NOW()
                )
            ");
            
            $success = $stmt->execute([
                ':location_code' => $location['location_code'],
                ':zone' => $location['zone'],
                ':type' => $location['type'],
                ':capacity' => $location['capacity'],
                ':notes' => $location['notes']
            ]);
            
            $results[] = [
                'location_code' => $location['location_code'],
                'status' => $success ? 'created' : 'failed',
                'message' => $success ? 'Location created successfully' : 'Failed to create location'
            ];
            
        } catch (PDOException $e) {
            $results[] = [
                'location_code' => $location['location_code'],
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    return $results;
}

/**
 * Get QC items requiring approval for dashboard
 * @param PDO $db Database connection
 * @param int $limit Maximum number of items to return
 * @return array QC items data
 */
function getQcItemsForDashboard($db, $limit = 5) {
    try {
        $stmt = $db->prepare("
            SELECT 
                ri.id,
                ri.received_quantity,
                ri.expected_quantity,
                ri.condition_status,
                ri.created_at as received_at,
                pp.supplier_product_name as product_name,
                po.po_number,
                po.supplier_name,
                l.location_code,
                l.type as location_type
            FROM receiving_items ri
            LEFT JOIN purchase_order_items poi ON ri.purchase_order_item_id = poi.id
            LEFT JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
            LEFT JOIN receiving_sessions rs ON ri.receiving_session_id = rs.id
            LEFT JOIN purchase_orders po ON rs.purchase_order_id = po.id
            LEFT JOIN locations l ON ri.location_id = l.id
            WHERE ri.approval_status = 'pending'
            ORDER BY ri.created_at ASC
            LIMIT :limit
        ");
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error getting QC items for dashboard: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if user has QC management permissions
 * @param array $userSession Session data containing user role
 * @return bool True if user can manage QC
 */
function canManageQc($userSession) {
    $allowedRoles = ['admin', 'supervisor'];
    return isset($userSession['role']) && in_array($userSession['role'], $allowedRoles);
}

/**
 * Log QC decision for audit trail
 * @param PDO $db Database connection
 * @param int $receivingItemId Receiving item ID
 * @param string $decision Decision made (approved/rejected)
 * @param int $decidedBy User ID who made the decision
 * @param string $reason Reason for the decision
 * @param string $notes Additional notes
 * @param string $previousStatus Previous approval status
 * @return bool Success status
 */
function logQcDecision($db, $receivingItemId, $decision, $decidedBy, $reason = null, $notes = null, $previousStatus = null) {
    try {
        $stmt = $db->prepare("
            INSERT INTO qc_decisions (
                receiving_item_id, decision, decided_by, decision_reason, 
                supervisor_notes, previous_status, created_at
            ) VALUES (
                :receiving_item_id, :decision, :decided_by, :decision_reason,
                :supervisor_notes, :previous_status, NOW()
            )
        ");
        
        return $stmt->execute([
            ':receiving_item_id' => $receivingItemId,
            ':decision' => $decision,
            ':decided_by' => $decidedBy,
            ':decision_reason' => $reason,
            ':supervisor_notes' => $notes,
            ':previous_status' => $previousStatus
        ]);
        
    } catch (PDOException $e) {
        error_log("Error logging QC decision: " . $e->getMessage());
        return false;
    }
}

/**
 * Get QC statistics for a given timeframe
 * @param PDO $db Database connection
 * @param int $days Number of days to include in statistics
 * @return array Statistics data
 */
function getQcStatistics($db, $days = 30) {
    try {
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_items,
                SUM(CASE WHEN approval_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN approval_status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN approval_status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN condition_status != 'good' THEN 1 ELSE 0 END) as damaged_count,
                SUM(CASE WHEN received_quantity != expected_quantity THEN 1 ELSE 0 END) as discrepancy_count,
                AVG(CASE WHEN approval_status = 'approved' 
                    THEN TIMESTAMPDIFF(HOUR, created_at, approved_at) 
                    ELSE NULL END) as avg_approval_time_hours
            FROM receiving_items 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        
        $stmt->execute([':days' => $days]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate approval rate
        $totalProcessed = ($stats['approved_count'] + $stats['rejected_count']);
        $stats['approval_rate'] = $totalProcessed > 0 ? 
            round(($stats['approved_count'] / $totalProcessed) * 100, 1) : 0;
        
        return $stats;
        
    } catch (PDOException $e) {
        error_log("Error getting QC statistics: " . $e->getMessage());
        return [
            'total_items' => 0,
            'pending_count' => 0,
            'approved_count' => 0,
            'rejected_count' => 0,
            'damaged_count' => 0,
            'discrepancy_count' => 0,
            'avg_approval_time_hours' => 0,
            'approval_rate' => 0
        ];
    }
}

/**
 * Validate QC decision data
 * @param array $data Decision data to validate
 * @return array Validation result with 'valid' and 'errors' keys
 */
function validateQcDecisionData($data) {
    $errors = [];
    
    // Check required fields
    if (empty($data['item_ids']) || !is_array($data['item_ids'])) {
        $errors[] = 'Item IDs are required and must be an array';
    }
    
    if (empty($data['decision']) || !in_array($data['decision'], ['approved', 'rejected'])) {
        $errors[] = 'Valid decision is required (approved or rejected)';
    }
    
    // If rejecting, reason is required
    if ($data['decision'] === 'rejected' && empty($data['rejection_reason'])) {
        $errors[] = 'Rejection reason is required when rejecting items';
    }
    
    // Validate item IDs are integers
    foreach ($data['item_ids'] as $itemId) {
        if (!is_numeric($itemId) || $itemId <= 0) {
            $errors[] = 'All item IDs must be positive integers';
            break;
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Format QC decision reason for display
 * @param string $reason Raw reason from database
 * @return string Formatted reason
 */
function formatQcDecisionReason($reason) {
    $reasons = [
        'quality_defect' => 'Defect de calitate',
        'wrong_product' => 'Produs greșit',
        'damaged_packaging' => 'Ambalaj deteriorat',
        'expired' => 'Expirat',
        'quantity_mismatch' => 'Nepotrivire cantitate',
        'other' => 'Altul'
    ];
    
    return $reasons[$reason] ?? $reason;
}