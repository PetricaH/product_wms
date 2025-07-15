<?php
// File: api/warehouse/get_orders_production.php - Production version matching your DB schema
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Use hardcoded path since we know it works from diagnostic
    if (!defined('BASE_PATH')) {
        define('BASE_PATH', '/var/www/notsowms.ro');
    }

    // Load configuration
    $config = require BASE_PATH . '/config/config.php';
    
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        throw new Exception('Database connection not configured');
    }

    // Get database connection
    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    // Query matching your exact database schema
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
            o.total_value,
            o.created_at,
            o.updated_at
        FROM orders o
        WHERE o.type = 'outbound'
        AND o.status IN ('pending', 'processing', 'assigned')
        ORDER BY 
            CASE o.priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'normal' THEN 3
                WHEN 'low' THEN 4
                ELSE 5
            END,
            o.created_at ASC
        LIMIT 50
    ";

    $stmt = $db->prepare($query);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process orders with simplified logic
    $formattedOrders = [];
    
    foreach ($orders as $order) {
        // Get order items count (simplified query)
        $itemsStmt = $db->prepare("SELECT COUNT(*) as count FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$order['id']]);
        $itemsCount = $itemsStmt->fetchColumn() ?: 1;

        // Format the order for frontend
        $formattedOrder = [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'] ?: 'ORD-' . $order['id'],
            'customer_name' => $order['customer_name'] ?: 'Client necunoscut',
            'total_value' => number_format((float)($order['total_value'] ?: 0), 2, '.', ''),
            'order_date' => $order['order_date'] ?: $order['created_at'],
            'status' => strtolower($order['status']) ?: 'pending',
            'priority' => strtolower($order['priority']) ?: 'normal',
            'source' => 'manual',
            'notes' => $order['notes'] ?: '',
            'total_items' => (int)$itemsCount,
            'total_locations' => 1, // Simplified for now
            'remaining_items' => (int)$itemsCount // Simplified for now
        ];
        
        $formattedOrders[] = $formattedOrder;
    }

    // Success response
    $response = [
        'status' => 'success',
        'orders' => $formattedOrders,
        'total_count' => count($formattedOrders),
        'timestamp' => date('Y-m-d H:i:s'),
        'api_version' => '1.0'
    ];

    // Ensure clean output
    if (ob_get_level()) {
        ob_clean();
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    // Database error
    error_log("WMS API Database Error: " . $e->getMessage());
    
    if (ob_get_level()) {
        ob_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error',
        'timestamp' => date('Y-m-d H:i:s'),
        'error_code' => 'DB_ERROR'
    ]);

} catch (Exception $e) {
    // General error
    error_log("WMS API General Error: " . $e->getMessage());
    
    if (ob_get_level()) {
        ob_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error',
        'timestamp' => date('Y-m-d H:i:s'),
        'error_code' => 'SERVER_ERROR'
    ]);
}
?>