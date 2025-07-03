<?php
// File: /api/warehouse/get_orders.php (Fixed version)
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Define Base Path
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2)); // Go up 2 levels from /api/warehouse/
}

// Simple error handling for missing files
if (!file_exists(BASE_PATH . '/config/config.php')) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Fișier de configurare lipsă.']);
    exit;
}

try {
    // Bootstrap and Config
    $config = require BASE_PATH . '/config/config.php';

    // Database Connection
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Eroare configurare bază de date.']);
        exit;
    }

    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    // Query to get orders with items count - adjusted for existing schema
    $query = "
        SELECT 
            o.id,
            o.order_number,
            o.customer_name,
            o.customer_email,
            o.shipping_address,
            o.order_date,
            o.status,
            CASE 
                WHEN o.notes LIKE '%urgent%' OR o.notes LIKE '%priority%' THEN 'urgent'
                WHEN o.notes LIKE '%high%' THEN 'high'
                ELSE 'normal'
            END as priority,
            COALESCE(o.source, 'manual') as source,
            o.notes,
            -- Calculate total value from order items if not set
            CASE 
                WHEN o.total_value > 0 THEN o.total_value
                ELSE COALESCE((
                    SELECT SUM(oi.quantity_ordered * oi.unit_price) 
                    FROM order_items oi 
                    WHERE oi.order_id = o.id
                ), 0)
            END as total_value,
            -- Count items
            COALESCE((
                SELECT COUNT(*) 
                FROM order_items oi 
                WHERE oi.order_id = o.id
            ), 0) as total_items,
            -- Count distinct locations (estimated)
            COALESCE((
                SELECT COUNT(DISTINCT i.location_id)
                FROM order_items oi
                JOIN inventory i ON oi.product_id = i.product_id AND i.quantity > 0
                WHERE oi.order_id = o.id
            ), 1) as total_locations,
            -- Count remaining items to pick
            COALESCE((
                SELECT SUM(oi.quantity_ordered - oi.picked_quantity)
                FROM order_items oi 
                WHERE oi.order_id = o.id
                AND oi.quantity_ordered > oi.picked_quantity
            ), 0) as remaining_items
        FROM orders o
        WHERE o.status IN ('Pending', 'Processing') -- Using existing enum values
        HAVING remaining_items > 0  -- Only orders with items left to pick
        ORDER BY 
            CASE priority 
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
            'status' => strtolower($order['status']) === 'pending' ? 'pending' : 'assigned', // Map to frontend values
            'priority' => $order['priority'] ?: 'normal',
            'source' => $order['source'],
            'notes' => $order['notes'],
            'total_items' => (int)$order['total_items'],
            'total_locations' => (int)$order['total_locations'],
            'remaining_items' => (int)$order['remaining_items']
        ];
    }, $orders);

    // Return successful response
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
        'message' => 'Eroare bază de date.',
        'error_code' => 'DB_ERROR'
    ]);
} catch (Exception $e) {
    error_log("General error in get_orders.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Eroare server.',
        'error_code' => 'SERVER_ERROR'
    ]);
}
?>