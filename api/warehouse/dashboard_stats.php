<?php
// File: api/warehouse/dashboard_stats.php - Fixed to show outbound order statistics
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

    // 1. Pending picks (orders waiting to be picked) - FIXED: Changed to 'outbound'
    $pendingPicksQuery = "
        SELECT COUNT(*) as count 
        FROM orders 
        WHERE status IN ('pending', 'assigned') 
        AND type = 'outbound'
    ";
    $stmt = $db->prepare($pendingPicksQuery);
    $stmt->execute();
    $stats['pending_picks'] = (int)$stmt->fetchColumn();

    // 2. Picks completed today - FIXED: Changed to 'outbound'
    $picksTodayQuery = "
        SELECT COUNT(*) as count 
        FROM orders 
        WHERE DATE(updated_at) = CURDATE() 
        AND status = 'completed'
        AND type = 'outbound'
    ";
    $stmt = $db->prepare($picksTodayQuery);
    $stmt->execute();
    $stats['picks_today'] = (int)$stmt->fetchColumn();

    // 3. Pending receipts (incoming shipments) - This should remain 'inbound' as it's for receiving
    $pendingReceiptsQuery = "
        SELECT COUNT(*) as count 
        FROM orders 
        WHERE status = 'pending' 
        AND type = 'inbound'
    ";
    $stmt = $db->prepare($pendingReceiptsQuery);
    $stmt->execute();
    $stats['pending_receipts'] = (int)$stmt->fetchColumn();

    // 4. Receipts processed today - This should remain 'inbound' as it's for receiving
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

    // 6. Today's shipments (outbound orders shipped today)
    $shipmentsTodayQuery = "
        SELECT COUNT(*) as count 
        FROM orders 
        WHERE DATE(shipped_date) = CURDATE() 
        AND type = 'outbound'
        AND status = 'shipped'
    ";
    $stmt = $db->prepare($shipmentsTodayQuery);
    $stmt->execute();
    $stats['shipments_today'] = (int)$stmt->fetchColumn();

    // 7. Processing orders (currently being worked on)
    $processingOrdersQuery = "
        SELECT COUNT(*) as count 
        FROM orders 
        WHERE status = 'processing' 
        AND type = 'outbound'
    ";
    $stmt = $db->prepare($processingOrdersQuery);
    $stmt->execute();
    $stats['processing_orders'] = (int)$stmt->fetchColumn();

    // Return the statistics
    echo json_encode([
        'status' => 'success',
        'stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (PDOException $e) {
    error_log("Database error in dashboard_stats.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection error.',
        'debug' => [
            'error' => $e->getMessage(),
            'file' => basename(__FILE__),
            'line' => __LINE__
        ]
    ]);
} catch (Exception $e) {
    error_log("General error in dashboard_stats.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage(),
        'debug' => [
            'file' => basename(__FILE__),
            'line' => __LINE__,
            'BASE_PATH' => BASE_PATH
        ]
    ]);
}
?>