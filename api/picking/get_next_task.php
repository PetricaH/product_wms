<?php
// File: /api/picking/get_next_task.php (Final Fix with Output Buffering)

// Start output buffering to catch any stray output
ob_start();

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Define Base Path
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

// Function to send a clean JSON response and exit
function send_json_response(array $data, int $http_code = 200) {
    // Clean (erase) the output buffer and turn off output buffering
    ob_end_clean();
    http_response_code($http_code);
    echo json_encode($data);
    exit;
}

if (!file_exists(BASE_PATH . '/config/config.php')) {
    send_json_response(['status' => 'error', 'message' => 'Config file missing.'], 500);
}

try {
    $config = require BASE_PATH . '/config/config.php';
    
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        send_json_response(['status' => 'error', 'message' => 'Database config error.'], 500);
    }

    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    $orderId = $_GET['order_id'] ?? '';
    
    if (empty($orderId)) {
        send_json_response(['status' => 'error', 'message' => 'Order ID is required.'], 400);
    }

    $orderQuery = "SELECT id, order_number, customer_name, status FROM orders WHERE order_number = :order_id OR id = :order_id_int";
    $orderStmt = $db->prepare($orderQuery);
    $orderStmt->execute([
        ':order_id' => $orderId,
        ':order_id_int' => is_numeric($orderId) ? (int)$orderId : 0
    ]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        send_json_response(['status' => 'error', 'message' => 'Order not found.'], 404);
    }

    $actualOrderId = $order['id'];

    $taskQuery = "
        SELECT 
            oi.id as order_item_id,
            oi.order_id,
            oi.product_id,
            oi.quantity as quantity_ordered,
            oi.picked_quantity,
            (oi.quantity - COALESCE(oi.picked_quantity, 0)) as quantity_to_pick,
            p.sku as product_sku,
            p.name as product_name,
            p.barcode as product_barcode,
            COALESCE(l.location_code, 'N/A') as location_code,
            COALESCE(i.quantity, 0) as available_in_location,
            i.id as inventory_id
        FROM order_items oi
        JOIN products p ON oi.product_id = p.product_id
        LEFT JOIN inventory i ON p.product_id = i.product_id AND i.quantity > 0
        LEFT JOIN locations l ON i.location_id = l.id
        WHERE oi.order_id = :order_id
        AND oi.quantity > COALESCE(oi.picked_quantity, 0)
        ORDER BY oi.id ASC
        LIMIT 1
    ";

    $taskStmt = $db->prepare($taskQuery);
    $taskStmt->execute([':order_id' => $actualOrderId]);
    $task = $taskStmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        $itemCountStmt = $db->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = :order_id");
        $itemCountStmt->execute([':order_id' => $actualOrderId]);
        $itemCount = $itemCountStmt->fetchColumn();

        if ($itemCount == 0) {
            send_json_response([
                'status' => 'error',
                'message' => 'Eroare: Comanda ' . htmlspecialchars($order['order_number']) . ' nu conține niciun produs.',
                'order_info' => $order
            ]);
        } else {
            send_json_response([
                'status' => 'complete',
                'message' => 'Toate articolele din această comandă au fost deja colectate.',
                'order_info' => $order
            ]);
        }
    }

    send_json_response([
        'status' => 'success',
        'message' => 'Next picking task retrieved.',
        'data' => $task
    ]);

} catch (PDOException $e) {
    error_log("Database error in get_next_task.php: " . $e->getMessage());
    send_json_response([
        'status' => 'error',
        'message' => 'Database error.',
        'error_details' => $e->getMessage()
    ], 500);
} catch (Exception $e) {
    error_log("General error in get_next_task.php: " . $e->getMessage());
    send_json_response([
        'status' => 'error',
        'message' => 'Server error.',
        'error_details' => $e->getMessage()
    ], 500);
}
