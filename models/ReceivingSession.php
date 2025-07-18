<?php
/**
 * ReceivingSession Model
 * File: models/ReceivingSession.php
 * 
 * Handles receiving session operations following WMS model patterns
 */

class ReceivingSession {
    private $db;
    
    public function __construct(PDO $db) {
        $this->db = $db;
    }
    
    /**
     * Create a new receiving session
     */
    public function createSession(array $data) {
        $stmt = $this->db->prepare("
            INSERT INTO receiving_sessions (
                session_number, supplier_document_number, supplier_document_type,
                supplier_document_date, purchase_order_id, supplier_id, received_by,
                status, total_items_expected
            ) VALUES (
                :session_number, :supplier_doc_number, :doc_type, :doc_date,
                :purchase_order_id, :supplier_id, :received_by, 'in_progress',
                :total_items_expected
            )
        ");
        
        $stmt->execute([
            ':session_number' => $data['session_number'],
            ':supplier_doc_number' => $data['supplier_document_number'],
            ':doc_type' => $data['supplier_document_type'],
            ':doc_date' => $data['supplier_document_date'] ?? null,
            ':purchase_order_id' => $data['purchase_order_id'],
            ':supplier_id' => $data['supplier_id'],
            ':received_by' => $data['received_by'],
            ':total_items_expected' => $data['total_items_expected'] ?? 0
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Get session by ID with full details
     */
    public function getSessionById($sessionId, $userId = null) {
        $sql = "
            SELECT 
                rs.*,
                po.order_number as po_number,
                po.total_amount as po_total_amount,
                po.currency as po_currency,
                s.supplier_name as supplier_name,
                s.email as supplier_email,
                u.username as received_by_name
            FROM receiving_sessions rs
            LEFT JOIN purchase_orders po ON rs.purchase_order_id = po.id
            LEFT JOIN sellers s ON rs.supplier_id = s.id
            LEFT JOIN users u ON rs.received_by = u.id
            WHERE rs.id = :session_id
        ";
        
        $params = [':session_id' => $sessionId];
        
        // Add user access control if userId provided
        if ($userId !== null) {
            $sql .= " AND (rs.received_by = :user_id OR :user_id IN (
                SELECT id FROM users WHERE role IN ('admin', 'manager')
            ))";
            $params[':user_id'] = $userId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all sessions with optional filters
     */
    public function getAllSessions(array $filters = []) {
        $sql = "
            SELECT 
                rs.*,
                po.order_number as po_number,
                s.supplier_name as supplier_name,
                u.username as received_by_name,
                COUNT(DISTINCT ri.id) as items_received_count,
                COUNT(DISTINCT rd.id) as discrepancies_count
            FROM receiving_sessions rs
            LEFT JOIN purchase_orders po ON rs.purchase_order_id = po.id
            LEFT JOIN sellers s ON rs.supplier_id = s.id
            LEFT JOIN users u ON rs.received_by = u.id
            LEFT JOIN receiving_items ri ON rs.id = ri.receiving_session_id
            LEFT JOIN receiving_discrepancies rd ON rs.id = rd.receiving_session_id
            WHERE 1=1
        ";
        
        $params = [];
        
        // Apply filters
        if (!empty($filters['status'])) {
            $sql .= " AND rs.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['supplier_id'])) {
            $sql .= " AND rs.supplier_id = :supplier_id";
            $params[':supplier_id'] = $filters['supplier_id'];
        }
        
        if (!empty($filters['received_by'])) {
            $sql .= " AND rs.received_by = :received_by";
            $params[':received_by'] = $filters['received_by'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(rs.created_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(rs.created_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (
                rs.session_number LIKE :search 
                OR rs.supplier_document_number LIKE :search
                OR s.supplier_name LIKE :search
                OR po.order_number LIKE :search
            )";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        $sql .= " GROUP BY rs.id ORDER BY rs.created_at DESC";
        
        // Add limit if specified
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT :limit";
            $params[':limit'] = (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === ':limit') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Update session status
     */
    public function updateSessionStatus($sessionId, $status, $notes = null) {
        $sql = "
            UPDATE receiving_sessions SET 
                status = :status,
                updated_at = NOW()
        ";
        
        $params = [
            ':status' => $status,
            ':session_id' => $sessionId
        ];
        
        if ($status === 'completed') {
            $sql .= ", completed_at = NOW()";
        }
        
        if ($notes !== null) {
            $sql .= ", discrepancy_notes = :notes";
            $params[':notes'] = $notes;
        }
        
        $sql .= " WHERE id = :session_id";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Get session statistics
     */
    public function getSessionStatistics($sessionId) {
        $stmt = $this->db->prepare("
            SELECT 
                rs.total_items_expected,
                COUNT(DISTINCT ri.purchase_order_item_id) as items_received,
                COUNT(DISTINCT rd.id) as discrepancies_count,
                SUM(ri.received_quantity) as total_received_quantity,
                SUM(poi.quantity) as total_expected_quantity,
                MIN(ri.created_at) as first_item_received,
                MAX(ri.created_at) as last_item_received
            FROM receiving_sessions rs
            LEFT JOIN receiving_items ri ON rs.id = ri.receiving_session_id
            LEFT JOIN receiving_discrepancies rd ON rs.id = rd.receiving_session_id
            LEFT JOIN purchase_order_items poi ON ri.purchase_order_item_id = poi.id
            WHERE rs.id = :session_id
            GROUP BY rs.id
        ");
        $stmt->execute([':session_id' => $sessionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get recent sessions for user
     */
    public function getRecentSessions($userId, $limit = 10) {
        $stmt = $this->db->prepare("
            SELECT 
                rs.*,
                s.supplier_name as supplier_name,
                po.order_number as po_number,
                u.username as received_by_name
            FROM receiving_sessions rs
            LEFT JOIN sellers s ON rs.supplier_id = s.id
            LEFT JOIN purchase_orders po ON rs.purchase_order_id = po.id
            LEFT JOIN users u ON rs.received_by = u.id
            WHERE rs.received_by = :user_id 
            OR :user_id IN (
                SELECT id FROM users WHERE role IN ('admin', 'manager')
            )
            ORDER BY rs.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Search sessions by supplier document
     */
    public function searchBySupplierDocument($documentNumber, $documentType = null) {
        $sql = "
            SELECT rs.*, s.supplier_name as supplier_name, po.order_number as po_number
            FROM receiving_sessions rs
            LEFT JOIN sellers s ON rs.supplier_id = s.id
            LEFT JOIN purchase_orders po ON rs.purchase_order_id = po.id
            WHERE rs.supplier_document_number = :doc_number
        ";
        
        $params = [':doc_number' => $documentNumber];
        
        if ($documentType) {
            $sql .= " AND rs.supplier_document_type = :doc_type";
            $params[':doc_type'] = $documentType;
        }
        
        $sql .= " ORDER BY rs.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Check if session can be modified by user
     */
    public function canUserModifySession($sessionId, $userId) {
        $stmt = $this->db->prepare("
            SELECT 
                CASE 
                    WHEN rs.received_by = :user_id THEN 1
                    WHEN u.role IN ('admin', 'manager') THEN 1
                    ELSE 0
                END as can_modify
            FROM receiving_sessions rs
            JOIN users u ON u.id = :user_id
            WHERE rs.id = :session_id
        ");
        $stmt->execute([
            ':session_id' => $sessionId,
            ':user_id' => $userId
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (bool)$result['can_modify'] : false;
    }
    
    /**
     * Generate unique session number
     */
    public function generateSessionNumber() {
        $prefix = 'REC-' . date('Y') . '-';
        
        // Get the last session number for this year
        $stmt = $this->db->prepare("
            SELECT session_number 
            FROM receiving_sessions 
            WHERE session_number LIKE :prefix 
            ORDER BY session_number DESC 
            LIMIT 1
        ");
        $stmt->execute([':prefix' => $prefix . '%']);
        $lastNumber = $stmt->fetchColumn();
        
        if ($lastNumber) {
            // Extract the number part and increment
            $lastNum = (int)substr($lastNumber, strlen($prefix));
            $nextNum = $lastNum + 1;
        } else {
            $nextNum = 1;
        }
        
        return $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Delete session (admin only)
     */
    public function deleteSession($sessionId) {
        // Note: This will cascade delete receiving_items and receiving_discrepancies
        $stmt = $this->db->prepare("DELETE FROM receiving_sessions WHERE id = :session_id");
        return $stmt->execute([':session_id' => $sessionId]);
    }
    
    /**
     * Get dashboard statistics
     */
    public function getDashboardStats($userId = null, $days = 30) {
        $sql = "
            SELECT 
                COUNT(*) as total_sessions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_sessions,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as active_sessions,
                SUM(total_items_received) as total_items_received,
                COUNT(DISTINCT supplier_id) as unique_suppliers,
                AVG(CASE WHEN total_items_expected > 0 
                    THEN (total_items_received / total_items_expected) * 100 
                    ELSE 0 END) as avg_completion_rate
            FROM receiving_sessions 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        ";
        
        $params = [':days' => $days];
        
        if ($userId !== null) {
            $sql .= " AND received_by = :user_id";
            $params[':user_id'] = $userId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Count items that require quality approval
     * @return int Pending approval count
     */
    public function countPendingQualityItems(): int {
        $sql = "SELECT COUNT(*) FROM receiving_items WHERE approval_status = 'pending'";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('Error counting pending quality items: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Average duration of receiving sessions by operator (in minutes)
     */
    public function getAverageDurationByOperator($days = 30) {
        $sql = "
            SELECT u.username AS operator,
                   AVG(TIMESTAMPDIFF(MINUTE, rs.created_at, rs.completed_at)) AS avg_minutes
            FROM receiving_sessions rs
            JOIN users u ON rs.received_by = u.id
            WHERE rs.status = 'completed'
              AND rs.completed_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY u.username
            ORDER BY avg_minutes
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Average duration of receiving sessions by product category (in minutes)
     */
    public function getAverageDurationByCategory($days = 30) {
        $sql = "
            SELECT p.category AS category,
                   AVG(TIMESTAMPDIFF(MINUTE, rs.created_at, rs.completed_at)) AS avg_minutes
            FROM receiving_sessions rs
            JOIN receiving_items ri ON rs.id = ri.receiving_session_id
            JOIN products p ON ri.product_id = p.product_id
            WHERE rs.status = 'completed'
              AND rs.completed_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY p.category
            ORDER BY avg_minutes
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Average duration of receiving sessions by product (in minutes)
     */
    public function getAverageDurationByProduct($days = 30) {
        $sql = "
            SELECT p.name AS product,
                   AVG(TIMESTAMPDIFF(MINUTE, rs.created_at, rs.completed_at)) AS avg_minutes
            FROM receiving_sessions rs
            JOIN receiving_items ri ON rs.id = ri.receiving_session_id
            JOIN products p ON ri.product_id = p.product_id
            WHERE rs.status = 'completed'
              AND rs.completed_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY p.product_id
            ORDER BY avg_minutes
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}