<?php
/**
 * API: Get Recent Receiving Sessions
 * File: api/receiving/recent_sessions.php
 * 
 * Returns recent receiving sessions for the current user
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

// Session check
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Database connection
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

try {
    $limit = (int)($_GET['limit'] ?? 10);
    $limit = min($limit, 50); // Max 50 sessions
    
    // Get recent sessions with details
    $stmt = $db->prepare("
        SELECT 
            rs.id,
            rs.session_number,
            rs.supplier_document_number,
            rs.supplier_document_type,
            rs.status,
            rs.total_items_expected,
            rs.total_items_received,
            rs.created_at,
            rs.completed_at,
            s.supplier_name as supplier_name,
            po.order_number as po_number,
            u.username as received_by_name,
            COUNT(DISTINCT rd.id) as discrepancies_count
        FROM receiving_sessions rs
        LEFT JOIN sellers s ON rs.supplier_id = s.id
        LEFT JOIN purchase_orders po ON rs.purchase_order_id = po.id
        LEFT JOIN users u ON rs.received_by = u.id
        LEFT JOIN receiving_discrepancies rd ON rs.id = rd.receiving_session_id
        WHERE rs.received_by = :user_id 
        OR :user_id IN (
            SELECT id FROM users WHERE role IN ('admin', 'manager')
        )
        GROUP BY rs.id
        ORDER BY rs.created_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format sessions for response
    $formattedSessions = array_map(function($session) {
        $completionPercentage = 0;
        if ($session['total_items_expected'] > 0) {
            $completionPercentage = round(
                ($session['total_items_received'] / $session['total_items_expected']) * 100, 
                1
            );
        }
        
        return [
            'id' => (int)$session['id'],
            'session_number' => $session['session_number'],
            'supplier_document_number' => $session['supplier_document_number'],
            'supplier_document_type' => $session['supplier_document_type'],
            'status' => $session['status'],
            'supplier_name' => $session['supplier_name'],
            'po_number' => $session['po_number'],
            'received_by_name' => $session['received_by_name'],
            'total_items_expected' => (int)$session['total_items_expected'],
            'total_items_received' => (int)$session['total_items_received'],
            'completion_percentage' => $completionPercentage,
            'discrepancies_count' => (int)$session['discrepancies_count'],
            'created_at' => $session['created_at'],
            'completed_at' => $session['completed_at'],
            'duration' => $session['completed_at'] ? 
                $this->calculateDuration($session['created_at'], $session['completed_at']) : null
        ];
    }, $sessions);
    
    // Get summary statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_sessions,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_sessions,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as active_sessions,
            SUM(total_items_received) as total_items_received,
            AVG(CASE WHEN total_items_expected > 0 
                THEN (total_items_received / total_items_expected) * 100 
                ELSE 0 END) as avg_completion_percentage
        FROM receiving_sessions rs
        WHERE rs.received_by = :user_id 
        AND rs.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'sessions' => $formattedSessions,
        'statistics' => [
            'total_sessions' => (int)$stats['total_sessions'],
            'completed_sessions' => (int)$stats['completed_sessions'], 
            'active_sessions' => (int)$stats['active_sessions'],
            'total_items_received' => (int)$stats['total_items_received'],
            'avg_completion_percentage' => round((float)$stats['avg_completion_percentage'], 1)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Get recent sessions error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Calculate duration between two timestamps
 */
function calculateDuration($start, $end) {
    $startTime = new DateTime($start);
    $endTime = new DateTime($end);
    $interval = $startTime->diff($endTime);
    
    if ($interval->d > 0) {
        return $interval->d . ' days';
    } elseif ($interval->h > 0) {
        return $interval->h . ' hours';
    } else {
        return $interval->i . ' minutes';
    }
}