<?php
// File: /api/picking/get_next_task.php (Updated for Debugging)
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Define Base Path
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

if (!file_exists(BASE_PATH . '/config/config.php')) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Config file missing.']);
    exit;
}

try {
    $config = require BASE_PATH . '/config/config.php';
    
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database config error.']);
        exit;
    }

    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    // Get order ID from query parameter
    $orderId = $_GET['order_id'] ?? '';
    
    if (empty($orderId)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Order ID is required.']);
        exit;
    }

    // First, check if this is an order number or order ID
    $orderQuery = "SELECT id, order_number, customer_name, status FROM orders WHERE order_number = :order_id OR id = :order_id_int";
    $orderStmt = $db->prepare($orderQuery);
    $orderStmt->execute([
        ':order_id' => $orderId,
        ':order_id_int' => is_numeric($orderId) ? (int)$orderId : 0
    ]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Order not found.']);
        exit;
    }

    $actualOrderId = $order['id'];

    // Get the next picking task (order item that needs picking)
    $taskQuery = "
        SELECT 
            oi.id as order_item_id,
            oi.order_id,
            oi.product_id,
            oi.quantity as quantity_ordered, -- CORRECTED: Use alias for compatibility
            oi.picked_quantity,
            (oi.quantity - COALESCE(oi.picked_quantity, 0)) as quantity_to_pick, -- CORRECTED
            p.sku as product_sku,
            p.name as product_name,
            p.barcode as product_barcode,
            -- Get inventory location (simplified - pick from first available location)
            COALESCE(i.location_id, 1) as location_id,
            COALESCE(l.location_code, 'A1-01-01') as location_code, -- CORRECTED: Changed l.code to l.location_code
            COALESCE(i.quantity, 0) as available_in_location,
            i.id as inventory_id
        FROM order_items oi
        JOIN products p ON oi.product_id = p.product_id
        LEFT JOIN inventory i ON p.product_id = i.product_id AND i.quantity > 0
        LEFT JOIN locations l ON i.location_id = l.id
        WHERE oi.order_id = :order_id
        AND oi.quantity > COALESCE(oi.picked_quantity, 0) -- CORRECTED
        ORDER BY oi.id ASC
        LIMIT 1
    ";

    $taskStmt = $db->prepare($taskQuery);
    $taskStmt->execute([':order_id' => $actualOrderId]);
    $task = $taskStmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        // No more tasks. Check if it's because the order is complete or empty.
        $itemCountStmt = $db->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = :order_id");
        $itemCountStmt->execute([':order_id' => $actualOrderId]);
        $itemCount = $itemCountStmt->fetchColumn();

        if ($itemCount == 0) {
            // This order has no items associated with it.
            echo json_encode([
                'status' => 'error',
                'message' => 'Eroare: Comanda ' . htmlspecialchars($order['order_number']) . ' nu conține niciun produs.',
                'order_info' => [
                    'id' => $order['id'],
                    'order_number' => $order['order_number'],
                    'customer_name' => $order['customer_name']
                ]
            ]);
        } else {
            // No more items to pick - order is complete
            echo json_encode([
                'status' => 'complete',
                'message' => 'Toate articolele din această comandă au fost deja colectate.',
                'order_info' => [
                    'id' => $order['id'],
                    'order_number' => $order['order_number'],
                    'customer_name' => $order['customer_name']
                ]
            ]);
        }
        exit;
    }

    // Return the next task
    echo json_encode([
        'status' => 'success',
        'message' => 'Next picking task retrieved.',
        'data' => [
            'order_item_id' => (int)$task['order_item_id'],
            'order_id' => (int)$task['order_id'],
            'product_id' => (int)$task['product_id'],
            'product_sku' => $task['product_sku'],
            'product_name' => $task['product_name'],
            'product_barcode' => $task['product_barcode'],
            'quantity_ordered' => (int)$task['quantity_ordered'],
            'picked_quantity' => (int)$task['picked_quantity'],
            'quantity_to_pick' => (int)$task['quantity_to_pick'],
            'location_id' => (int)$task['location_id'],
            'location_code' => $task['location_code'],
            'available_in_location' => (int)$task['available_in_location'],
            'inventory_id' => $task['inventory_id'],
            'order_info' => [
                'order_number' => $order['order_number'],
                'customer_name' => $order['customer_name']
            ]
        ]
    ]);

} catch (PDOException $e) {
    // --- MODIFIED FOR DEBUGGING ---
    // This will now output the specific SQL error message to the browser.
    // This should be removed in a production environment for security.
    $errorMessage = $e->getMessage();
    error_log("Database error in get_next_task.php: " . $errorMessage);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'A database error occurred. See details for more info.',
        'error_details' => $errorMessage, // The specific error from the database
        'error_code' => 'DB_ERROR'
    ]);
} catch (Exception $e) {
    error_log("General error in get_next_task.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'A server error occurred.',
        'error_details' => $e->getMessage(),
        'error_code' => 'SERVER_ERROR'
    ]);
}
?>
