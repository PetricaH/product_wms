<?php
/**
 * Dashboard Statistics API
 * File: api/dashboard_stats.php
 * 
 * Provides real-time duration statistics for the admin dashboard
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
//     http_response_code(401);
//     echo json_encode(['success' => false, 'error' => 'Authentication required']);
//     exit;
// }

// Get database connection
$config = require BASE_PATH . '/config/config.php';
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database configuration error']);
    exit;
}

$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include required models
require_once BASE_PATH . '/models/Order.php';
require_once BASE_PATH . '/models/ReceivingSession.php';

try {
    // Get optional time period parameter (default 30 days)
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    $days = max(1, min(365, $days)); // Clamp between 1 and 365 days
    
    // Get detailed item-level statistics from picking_tasks
    $pickingByOperatorStmt = $db->prepare("
        SELECT 
            u.username as operator,
            AVG(pt.duration_seconds) / 60 as avg_minutes,
            COUNT(*) as total_tasks,
            SUM(pt.quantity_picked) as total_items_picked,
            MIN(pt.duration_seconds) / 60 as min_minutes,
            MAX(pt.duration_seconds) / 60 as max_minutes
        FROM picking_tasks pt
        JOIN users u ON pt.operator_id = u.id
        WHERE pt.status = 'completed'
        AND pt.end_time IS NOT NULL
        AND pt.start_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY u.username
        ORDER BY avg_minutes ASC
    ");
    $pickingByOperatorStmt->execute([$days]);
    $pickingByOperator = $pickingByOperatorStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get picking statistics by product category
    $pickingByCategoryStmt = $db->prepare("
        SELECT 
            p.category,
            AVG(pt.duration_seconds) / 60 as avg_minutes,
            COUNT(*) as total_tasks,
            SUM(pt.quantity_picked) as total_items_picked,
            MIN(pt.duration_seconds) / 60 as min_minutes,
            MAX(pt.duration_seconds) / 60 as max_minutes
        FROM picking_tasks pt
        JOIN products p ON pt.product_id = p.product_id
        WHERE pt.status = 'completed'
        AND pt.end_time IS NOT NULL
        AND pt.start_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY p.category
        ORDER BY avg_minutes ASC
    ");
    $pickingByCategoryStmt->execute([$days]);
    $pickingByCategory = $pickingByCategoryStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get receiving statistics by operator
    $receivingByOperatorStmt = $db->prepare("
        SELECT 
            u.username as operator,
            AVG(rt.duration_seconds) / 60 as avg_minutes,
            COUNT(*) as total_tasks,
            SUM(rt.quantity_received) as total_items_received,
            MIN(rt.duration_seconds) / 60 as min_minutes,
            MAX(rt.duration_seconds) / 60 as max_minutes
        FROM receiving_tasks rt
        JOIN users u ON rt.operator_id = u.id
        WHERE rt.status = 'completed'
        AND rt.end_time IS NOT NULL
        AND rt.start_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY u.username
        ORDER BY avg_minutes ASC
    ");
    $receivingByOperatorStmt->execute([$days]);
    $receivingByOperator = $receivingByOperatorStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get receiving statistics by product category
    $receivingByCategoryStmt = $db->prepare("
        SELECT 
            p.category,
            AVG(rt.duration_seconds) / 60 as avg_minutes,
            COUNT(*) as total_tasks,
            SUM(rt.quantity_received) as total_items_received,
            MIN(rt.duration_seconds) / 60 as min_minutes,
            MAX(rt.duration_seconds) / 60 as max_minutes
        FROM receiving_tasks rt
        JOIN products p ON rt.product_id = p.product_id
        WHERE rt.status = 'completed'
        AND rt.end_time IS NOT NULL
        AND rt.start_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY p.category
        ORDER BY avg_minutes ASC
    ");
    $receivingByCategoryStmt->execute([$days]);
    $receivingByCategory = $receivingByCategoryStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get additional summary statistics
    $summaryStmt = $db->prepare("
        SELECT 
            'picking' as operation_type,
            COUNT(*) as total_tasks,
            SUM(quantity_picked) as total_items,
            AVG(duration_seconds) / 60 as avg_minutes_per_task,
            SUM(duration_seconds) / 60 as total_minutes
        FROM picking_tasks
        WHERE status = 'completed'
        AND end_time IS NOT NULL
        AND start_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        
        UNION ALL
        
        SELECT 
            'receiving' as operation_type,
            COUNT(*) as total_tasks,
            SUM(quantity_received) as total_items,
            AVG(duration_seconds) / 60 as avg_minutes_per_task,
            SUM(duration_seconds) / 60 as total_minutes
        FROM receiving_tasks
        WHERE status = 'completed'
        AND end_time IS NOT NULL
        AND start_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $summaryStmt->execute([$days, $days]);
    $summary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'data' => [
            'pickingByOperator' => $pickingByOperator,
            'pickingByCategory' => $pickingByCategory,
            'receivingByOperator' => $receivingByOperator,
            'receivingByCategory' => $receivingByCategory,
            'summary' => $summary
        ],
        'meta' => [
            'days' => $days,
            'timestamp' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
            'data_source' => 'item_level_timing'
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Dashboard stats API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load statistics',
        'details' => $e->getMessage()
    ]);
}
?>