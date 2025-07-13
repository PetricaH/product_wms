<?php
// File: api/warehouse/get_orders.php - Fixed to show outbound orders (customer orders)
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Define Base Path
if (!defined('BASE_PATH')) {
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
        'message' => 'Configuration not found.',
        'debug' => [
            'base_path' => BASE_PATH ?? 'undefined',
            'config_exists' => file_exists(BASE_PATH . '/config/config.php') ? 'yes' : 'no',
            'search_paths' => $possiblePaths ?? [],
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

    // FIXED: Query for warehouse orders (OUTBOUND orders - customer orders that need picking/shipping)
    $query = "
        SELECT
            o.id,
            o.order_number,
            o.customer_name,
            o.customer_email,
            o.shipping_address,
            o.order_date,
            o.status,
            o.priority,
            o.type,
            o.notes,
            COALESCE(o.total_value, 0) as total_value,
            COALESCE((
                SELECT COUNT(*)
                FROM order_items oi
                WHERE oi.order_id = o.id
            ), 0) as total_items,
            COALESCE((
                SELECT SUM(oi.quantity - COALESCE(oi.picked_quantity, 0))
                FROM order_items oi
                WHERE oi.order_id = o.id
            ), 0) as remaining_items
        FROM orders o
        WHERE o.type = 'outbound'
        AND o.status IN ('Pending', 'Processing', 'assigned', 'pending', 'processing')
        ORDER BY
            CASE LOWER(o.priority)
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'normal' THEN 3
                ELSE 4
            END,
            o.order_date ASC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the data for frontend
    $formattedOrders = array_map(function($order) {
        return [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'customer_name' => $order['customer_name'] ?: 'Client necunoscut',
            'total_value' => number_format((float)$order['total_value'], 2, '.', ''),
            'order_date' => $order['order_date'],
            'status' => strtolower($order['status']) === 'pending' ? 'pending' : 'assigned',
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