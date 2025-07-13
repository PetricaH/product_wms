<?php
// File: api/warehouse/dashboard_stats.php - Warehouse dashboard statistics
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Production-ready BASE_PATH detection
if (!defined('BASE_PATH')) {
    // Try relative paths from /api/warehouse/ location  
    $possiblePaths = [
        dirname(__DIR__, 2),    // /api/warehouse/ -> /
        dirname(__DIR__, 3),    // In case of deeper nesting
    ];
    
    foreach ($possiblePaths as $path) {
        if (file_exists($path . '/config/config.php')) {
            define('BASE_PATH', $path);
            break;
        }
    }
}

if (!defined('BASE_PATH') || !file_exists(BASE_PATH . '/config/config.php')) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Configuration not found.'
    ]);
    exit;
}

try {
    // Load configuration and get database connection
    $config = require BASE_PATH . '/config/config.php';
    
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        throw new Exception('Database configuration error.');
    }

    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    // Get warehouse statistics
    $stats = [];

    // 1. Pending picks (orders waiting to be picked)
    $pendingPicksQuery = "
        SELECT COUNT(*) as count 
        FROM orders 
        WHERE status IN ('pending', 'assigned') 
        AND type = 'inbound'
    ";
    $stmt = $db->prepare($pendingPicksQuery);
    $stmt->execute();
    $stats['pending_picks'] = (int)$stmt->fetchColumn();

    // 2. Picks completed today
    $picksTodayQuery = "
        SELECT COUNT(*) as count 
        FROM orders 
        WHERE DATE(updated_at) = CURDATE() 
        AND status = 'completed'
        AND type = 'inbound'
    ";
    $stmt = $db->prepare($picksTodayQuery);
    $stmt->execute();
    $stats['picks_today'] = (int)$stmt->fetchColumn();

    // 3. Pending receipts (incoming shipments)
    $pendingReceiptsQuery = "
        SELECT COUNT(*) as count 
        FROM orders 
        WHERE status = 'pending' 
        AND type = 'inbound'
    ";
    $stmt = $db->prepare($pendingReceiptsQuery);
    $stmt->execute();
    $stats['pending_receipts'] = (int)$stmt->fetchColumn();

    // 4. Receipts processed today
    $receiptsTodayQuery = "
        SELECT COUNT(*) as count 
        FROM orders 
        WHERE DATE(created_at) = CURDATE() 
        AND type = 'inbound'
    ";
    $stmt = $db->prepare($receiptsTodayQuery);
    $stmt->execute();
    $stats['receipts_today'] = (int)$stmt->fetchColumn();

    // 5. Total orders in system
    $totalOrdersQuery = "SELECT COUNT(*) as count FROM orders";
    $stmt = $db->prepare($totalOrdersQuery);
    $stmt->execute();
    $stats['total_orders'] = (int)$stmt->fetchColumn();

    // 6. Orders by status breakdown
    $statusBreakdownQuery = "
        SELECT status, COUNT(*) as count 
        FROM orders 
        GROUP BY status
    ";
    $stmt = $db->prepare($statusBreakdownQuery);
    $stmt->execute();
    $statusBreakdown = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['status_breakdown'] = $statusBreakdown;

    // 7. Activity summary (last 7 days)
    $activityQuery = "
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as orders_created,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as orders_completed
        FROM orders 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ";
    $stmt = $db->prepare($activityQuery);
    $stmt->execute();
    $stats['weekly_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Return successful response
    echo json_encode([
        'status' => 'success',
        'stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s'),
        'cache_duration' => 300 // 5 minutes
    ]);

} catch (PDOException $e) {
    error_log("Database error in dashboard_stats.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection error'
    ]);
} catch (Exception $e) {
    error_log("General error in dashboard_stats.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error occurred'
    ]);
}
?>