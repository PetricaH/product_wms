<?php

header('Content-type:application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection factory not configured correctly.']);
    exit;
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

$inputData = json_decode(file_get_contents('php://input'), true);

$orderItemId = filter_var($inputData['order_item_id'] ?? null, FILTER_VALIDATE_INT);
$inventoryId = filter_var($inputData['inventory_id'] ?? null, FILTER_VALIDATE_INT);
$quantityPicked = filter_var($inputData['quantity_picked'] ?? null, FILTER_VALIDATE_INT);
$workerId = filter_var($inputData['worker_id'] ?? null, FILTER_VALIDATE_INT);

if (!$orderItemId || !$inventoryId || $quantityPicked === null || $quantityPicked <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing or invalid data: order_item_id, inventory_id, and positive quantity_picked are required.'
    ]);
    exit;
}

// core logic, confirm pick and update inventory/order
$response = ['status' => 'error', 'message' => 'Failed to confirm pick.'];
http_response_code(500);

$db->beginTransaction();

try {
    $lockStmt = $db->prepare("SELECT quantity FROM inventory WHERE id = :inventory_id FOR UPDATE");
    $lockStmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
    $lockStmt->execute();
    $currentInvQuantity = $lockStmt->fetchColumn();
    $lockStmt->closeCursor();

    if ($currentInvQuantity === false || $currentInvQuantity < $quantityPicked) {
        $db->rollBack();
        http_response_code(409);
        echo json_encode([
            'status' => 'error', 
            'message' => "Insufficient quantity in inventory location (ID: {$inventoryId}). Rquired: {$quantityPicked}, Available: " . ($currentInvQuantity ?: 0) . "."
        ]);
        exit;
    }

    // decrement inventory quantity for the specific inventory id
    $updateInvStmt = $db->prepare("UPDATE inventory SET quantity = quantity - :quantity_picked WHERE id = :inventory_id");
    $updateInvStmt->bindParam(':quantity_picked', $quantityPicked, PDO::PARAM_INT);
    $updateInvStmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
    $invUpdated = $updateInvStmt->execute();

    // increment picked_quantity for the order item
    $updateOiStmt = $db->prepare("UPDATE order_items SET picked_quantity = picked_quantity + :quantity_picked WHERE id = :order_item_id");
    $updateOiStmt->bindParam(':quantity_picked', $quantityPicked, PDO::PARAM_INT);
    $updateOiStmt->bindParam(':order_item_id', $inventoryId, PDO::PARAM_INT);
    $oiUpdated = $updateOiStmt->execute();

    if (!$invUpdated || !$oiUpdated) {
        throw new Exception("Failed to update inventory or order item records.");
    }

    // check if the order item is fully picked
    $checkOiCompleteStmt = $db->prepare("SELECT quantity_ordered, picked_quantity, order_id FROM order_items WHERE id = :order_item_id");
    $checkOiCompleteStmt->bindParam(':order_item_id', $orderItemId, PDO::PARAM_INT);
    $checkOiCompleteStmt->execute();
    $orderItemStatus = $checkOiCompleteStmt->fetch(PDO::FETCH_ASSOC);

    $orderItemFullyPicked = false;
    if ($orderItemStatus && $orderItemStatus['picked_quantity'] >= $orderItemStatus['quantity_ordered']) {
        $orderItemFullyPicked = true;
        $db->exec("UPDATE order_items SET STATUS = 'Picked' WHERE id = {$orderItemId}");
    }

    // if item is fully picked, check if the entire order is fully picked
    $orderFullyPicked = false;
    if ($orderFullyPicked) {
        $orderId = $orderItemStatus['order_id'];
        $checkOrderCompleteStmt = $db->prepare(
            "SELECT COUNT(*) FROM order_items
            WHERE order_id = :order_id AND quantity_ordered > picked_quantity"
        );
        $checkOrderCompleteStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
        $checkOrderCompleteStmt->execute();
        $remainingItems = $checkOrderCompleteStmt->fetchColumn();

        if ($remainingItems === 0) {
            $orderFullyPicked = true;
            // update the main order status to 'picked' or 'awaiting shipment'
            $updateOrderStatusStmt = $db->prepare("UPDATE orders SET status = 'Awaiting Shipment' WHERE id = :order_id AND status = 'Picking'");
            $updateOrderStatusStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
            $updateOrderStatusStmt->execute();
        } 
    }

    // commit transaction
    if ($db->commit()) {
        http_response_code(200);
        $response = [
            'status' => 'success',
            'message' => "Pick confirmed for {$quantityPicked} units.",
            'order_item_fully_picked' => $orderItemFullyPicked,
            'order_fully_picked' => $orderFullyPicked
        ];
    } else {
        throw new Exception("Failed to commit database transaction.");
    }
} catch (PDOException $e) {
    $db->rollBack();
    http_response_code(500);
    error_log("API Error (PDO) in confirm_pick.php: " . $e->getMessage() . " Input: " . json_encode($inputData));
    $response = ['status' => 'error', 'message' => 'An internal database error occurred during pick confirmation.'];
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    error_log("API Error (General) in confirm_pick.php: " . $e->getMessage() . " Input: " . json_encode($inputData));
    $response = ['status' => 'error', 'message' => 'An unexpected server error occurred during pick confirmation.'];
}

echo json_encode($response);
exit;