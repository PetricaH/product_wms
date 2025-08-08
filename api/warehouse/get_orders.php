<?php
// File: /api/warehouse/get_orders.php - PRODUCTION READY
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// FIXED: Robust BASE_PATH detection for both development and production
if (!defined('BASE_PATH')) {
    // Method 1: Try going up from current directory structure
    $currentDir = __DIR__; // /api/warehouse/
    $possiblePaths = [
        dirname($currentDir, 2),                    // /api/warehouse/ -> /
        dirname($currentDir, 3),                    // In case of nested structure
        $_SERVER['DOCUMENT_ROOT'],                  // Web root
        $_SERVER['DOCUMENT_ROOT'] . '/product_wms', // Development path
    ];
    
    $basePathFound = false;
    foreach ($possiblePaths as $path) {
        if (file_exists($path . '/config/config.php')) {
            define('BASE_PATH', $path);
            $basePathFound = true;
            break;
        }
    }
    
    // If still not found, try to detect from script path
    if (!$basePathFound) {
        $scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
        $scriptDir = dirname($scriptPath);
        
        // Go up until we find config/config.php
        $currentPath = $scriptDir;
        for ($i = 0; $i < 5; $i++) { // Max 5 levels up
            if (file_exists($currentPath . '/config/config.php')) {
                define('BASE_PATH', $currentPath);
                $basePathFound = true;
                break;
            }
            $currentPath = dirname($currentPath);
        }
    }
    
    // Last resort: use document root
    if (!$basePathFound) {
        define('BASE_PATH', $_SERVER['DOCUMENT_ROOT']);
    }
}

// Verify config file exists
if (!file_exists(BASE_PATH . '/config/config.php')) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Configuration file not found.',
        'debug' => [
            'BASE_PATH' => BASE_PATH,
            'config_path' => BASE_PATH . '/config/config.php',
            'document_root' => $_SERVER['DOCUMENT_ROOT'],
            'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'unknown',
            'current_dir' => __DIR__,
            'file_exists' => file_exists(BASE_PATH . '/config/config.php') ? 'yes' : 'no'
        ]
    ]);
    exit;
}

try {
    // Load configuration
    $config = require BASE_PATH . '/config/config.php';
    
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        throw new Exception('Database connection factory not properly configured.');
    }

    // Get database connection
    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    // Query for warehouse orders (including today's completed)
    $query = "
        SELECT
            o.id,
            o.order_number,
            o.customer_name,
            o.customer_email,
            o.shipping_address,
            o.order_date,
            o.updated_at,
            o.status,
            o.priority,
            o.type,
            o.notes,
            COALESCE(o.total_value, 0) AS total_value,
            COALESCE((
                SELECT COUNT(*)
                FROM order_items oi
                WHERE oi.order_id = o.id
            ), 0) AS total_items,
            COALESCE((
                SELECT SUM(oi.quantity - COALESCE(oi.picked_quantity, 0))
                FROM order_items oi
                WHERE oi.order_id = o.id
            ), 0) AS remaining_items
        FROM orders o
        WHERE (
            o.status IN ('Pending', 'Processing', 'assigned', 'pending', 'processing', 'ready_to_ship')
            OR (LOWER(o.status) = 'completed' AND DATE(o.updated_at) = CURDATE())
        )
        ORDER BY
            o.order_date ASC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the data for frontend
    $formattedOrders = array_map(function($order) {
        $rawStatus = strtolower($order['status']);
        $status = $rawStatus === 'ready_to_ship' ? 'ready' : $rawStatus;
        return [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'customer_name' => $order['customer_name'] ?: 'Client necunoscut',
            'total_value' => number_format((float)$order['total_value'], 2, '.', ''),
            'order_date' => $order['order_date'],
            'status' => $status,
            'priority' => strtolower($order['priority'] ?: 'normal'),
            'source' => 'manual',
            'notes' => $order['notes'],
            'total_items' => (int)$order['total_items'],
            'total_locations' => 1,
            'remaining_items' => max(1, (int)$order['remaining_items'])
        ];
    }, $orders);

    // Return success response
    echo json_encode([
        'status' => 'success',
        'orders' => $formattedOrders,
        'total_count' => count($formattedOrders),
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (PDOException $e) {
    error_log("Database error in get_orders.php: " . $e->getMessage());
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
    error_log("General error in get_orders.php: " . $e->getMessage());
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