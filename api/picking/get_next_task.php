<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection factory not configured correctly. ']);
    exit;
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
$workerId = filter_input(INPUT_GET, 'worker_id', FILTER_VALIDATE_INT);

if (!$orderId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Valid Order ID is required']);
    exit;
}

$response = ['status' => 'error', 'message' => 'No items left to pick for this order or order not found.'];
http_response_code(404);

try {
    // find the next unpicked item in the order
    $orderItemQuery = "SELECT
                           oi.id as order_item_id,
                           oi.product_id,
                           p.sku as product_sku, -- Get SKU from products table
                           p.name as product_name,
                           (oi.quantity_ordered - oi.picked_quantity) as quantity_needed
                       FROM order_items oi
                       JOIN products p ON oi.product_id = p.product_id -- Join based on product_id
                       WHERE oi.order_id = :order_id
                         AND oi.quantity_ordered > oi.picked_quantity -- Check if item needs picking
                       ORDER BY oi.id ASC -- Or specific picking priority logic
                       LIMIT 1";
    $stmtItem = $db->prepare($orderItemQuery);
    $stmtItem->bindParam(':order_id', $orderId, PDO::PARAM_INT);
    $stmtItem->execute();
    $orderItem = $stmtItem->fetch(PDO::FETCH_ASSOC);

    if ($orderItem) {
        $productId = $orderItem['product_id'];
        $quantityNeeded = (int)$orderItem['quantity_needed'];

        // find the oldest inventory batch for this product id (FIFO)
        $inventoryQuery = "SELECT
                                i.id as inventory_id,
                                i.location_id,
                                l.location_code,
                                i.quantity as available_quantity,
                                i.batch_number,
                                i.lot_number,
                                i.expiry_date,
                                i.received_at
                            FROM inventory i
                             JOIN locations l ON i.location_id = l.id
                            WHERE i.product_id = :product_id
                                AND i.quantity > 0
                            ORDER BY i.received_at ASC, i.id ASC
                            LIMIT 1";
        $stmtInv = $db->prepare($inventoryQuery);
        $stmtInv->bindParam(':product_id', $productId, PDO::PARAM_INT);
        $stmtInv->execute();
        $inventoryLocation = $stmtInv->fetch(PDO::FETCH_ASSOC);

        if ($inventoryLocation) {
            // determine quantity to pick from this specific record
            $quantityToPick = min($quantityNeeded, (int)$inventoryLocation['available_quantity']);

            $response = [
                'status' => 'success',
                'data' => [
                    'order_id' => (int)$orderId,
                    'order_item_id' => (int)$orderItem['order_item_id'],
                    'product_id' => (int)$productId,
                    'product_sku' => $orderItem['product_sku'],
                    'product_name' => $orderItem['product_name'],
                    'location_code' => $inventoryLocation['location_code'],
                    'inventory_id' => (int)$inventoryLocation['location_id'],
                    'batch_number' => $inventoryLocation['batch_number'],
                    'lot_number' => $inventoryLocation['lot_number'],
                    'expiry_date' => $inventoryLocation['expiry_date'],
                    'quantity_to_pick' => $quantityToPick,
                    'total_needed_for_item' => $quantityNeeded,
                    'available_in_location' => (int)$inventoryLocation['available_quantity']
                ]
            ];
            http_response_code(200);
        } else {
            $response['message'] = "Item '{$orderItem['product_name']}' (ID: {$productID}} required, but no avaiable stock found in inventory.";
            http_response_code(400);
        }
    } else {
        $response['status'] = 'complete';
        $response['message'] = 'All items for this order appear to be picked, or the order was not found.';
        http_response_code(200);
    }
} catch (PDOException $e) {
    http_response_code(500);
    error_log("API Error (PDO) in get_next_task.php for Order ID {$orderId}: " . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'An internal database error occured.'];
} catch (Exception $e) {
    http_response_code(500);
    error_log("API Error (General) in get_next_task.php for Order ID {$orderId}: " . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'An unexpected server error occured.'];
}

echo json_encode($response);
exit;