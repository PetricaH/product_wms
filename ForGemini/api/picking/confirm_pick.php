<?php
// File: /api/picking/confirm_pick.php
// Correct version with quantity check and error logging

// --- Basic Setup ---
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1); // TODO: Disable in production

// --- Define Base Path ---
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2)); // Go up two levels
}

// --- Bootstrap and Config ---
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// --- Database Connection ---
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection factory not configured correctly.']);
    exit;
}
$dbFactory = $config['connection_factory'];
/** @var PDO $db */
$db = $dbFactory();

// --- Input Handling (from POST request body) ---
$inputData = json_decode(file_get_contents('php://input'), true);

$orderItemId = filter_var($inputData['order_item_id'] ?? null, FILTER_VALIDATE_INT);
$inventoryId = filter_var($inputData['inventory_id'] ?? null, FILTER_VALIDATE_INT);
$quantityPicked = filter_var($inputData['quantity_picked'] ?? null, FILTER_VALIDATE_INT);

// Basic validation
if (!$orderItemId || !$inventoryId || $quantityPicked === null || $quantityPicked <= 0) {
    http_response_code(400); // Bad Request
    error_log("[confirm_pick] Bad Request: Invalid input data received: " . json_encode($inputData));
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing or invalid data: order_item_id, inventory_id, and positive quantity_picked are required.'
    ]);
    exit;
}

error_log("[confirm_pick] Received valid input: OrderItemID={$orderItemId}, InventoryID={$inventoryId}, QtyPicked={$quantityPicked}");

// --- Core Logic: Confirm Pick and Update Inventory/Order ---
$response = ['status' => 'error', 'message' => 'Failed to confirm pick.']; // Default response
http_response_code(500); // Default to Internal Server Error

// Use a transaction to ensure atomicity
$db->beginTransaction();
error_log("[confirm_pick] Transaction started.");

try {
    // 1. Check if sufficient quantity exists in the specified inventory row
    error_log("[confirm_pick] Checking inventory quantity for ID: {$inventoryId}");
    $checkStmt = $db->prepare("SELECT quantity FROM inventory WHERE id = :inventory_id");
    $checkStmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
    $checkStmt->execute();
    $currentInvQuantityResult = $checkStmt->fetchColumn();
    $currentInvQuantity = ($currentInvQuantityResult === false) ? 0 : (int)$currentInvQuantityResult;
    error_log("[confirm_pick] Current inventory quantity found: " . ($currentInvQuantityResult === false ? 'Not Found' : $currentInvQuantity));


    if ($currentInvQuantityResult === false || $currentInvQuantity < $quantityPicked) {
        $db->rollBack(); // Abort transaction
        error_log("[confirm_pick] Rollback: Insufficient quantity. Required: {$quantityPicked}, Available: " . ($currentInvQuantity ?: 0));
        http_response_code(409); // Conflict
        echo json_encode([
            'status' => 'error',
            'message' => "Insufficient quantity in inventory location (ID: {$inventoryId}). Required: {$quantityPicked}, Available: {$currentInvQuantity}.",
            'debug_fetched_quantity' => $currentInvQuantityResult
        ]);
        exit;
    }

    // 2. Decrement inventory quantity for the specific inventory ID
    error_log("[confirm_pick] Attempting to UPDATE inventory ID: {$inventoryId}, Decrement by: {$quantityPicked}");
    $updateInvStmt = $db->prepare("UPDATE inventory SET quantity = quantity - :quantity_picked WHERE id = :inventory_id");
    $updateInvStmt->bindParam(':quantity_picked', $quantityPicked, PDO::PARAM_INT);
    $updateInvStmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
    $invUpdated = $updateInvStmt->execute();
    $invRowCount = $updateInvStmt->rowCount();
    error_log("[confirm_pick] Inventory UPDATE execute result: " . ($invUpdated ? 'Success' : 'Failure') . ", Rows Affected: " . $invRowCount);


    // 3. Increment picked_quantity for the order item
    error_log("[confirm_pick] Attempting to UPDATE order_items ID: {$orderItemId}, Increment picked_quantity by: {$quantityPicked}");
    // This query will now work because the column exists
    $updateOiStmt = $db->prepare("UPDATE order_items SET picked_quantity = picked_quantity + :quantity_picked WHERE id = :order_item_id");
    $updateOiStmt->bindParam(':quantity_picked', $quantityPicked, PDO::PARAM_INT);
    $updateOiStmt->bindParam(':order_item_id', $orderItemId, PDO::PARAM_INT);
    $oiUpdated = $updateOiStmt->execute();
    $oiRowCount = $updateOiStmt->rowCount();
    error_log("[confirm_pick] Order Item UPDATE execute result: " . ($oiUpdated ? 'Success' : 'Failure') . ", Rows Affected: " . $oiRowCount);

    // Check if updates actually affected rows
    if (!$invUpdated || !$oiUpdated || $invRowCount < 1 || $oiRowCount < 1) {
         error_log("[confirm_pick] Update failure or 0 rows affected. Inv Rows: {$invRowCount}, OI Rows: {$oiRowCount}");
        throw new Exception("Failed to update inventory or order item records (0 rows affected?).");
    }

    // --- Post-Pick Checks ---
    $checkOiCompleteStmt = $db->prepare("SELECT quantity_ordered, picked_quantity, order_id FROM order_items WHERE id = :order_item_id");
    $checkOiCompleteStmt->bindParam(':order_item_id', $orderItemId, PDO::PARAM_INT);
    $checkOiCompleteStmt->execute();
    $orderItemStatus = $checkOiCompleteStmt->fetch(PDO::FETCH_ASSOC);

    $orderItemFullyPicked = false;
    if ($orderItemStatus && $orderItemStatus['picked_quantity'] >= $orderItemStatus['quantity_ordered']) {
        $orderItemFullyPicked = true;
        error_log("[confirm_pick] Order item ID {$orderItemId} is now fully picked.");
    }

    $orderFullyPicked = false;
    if ($orderItemFullyPicked) {
        $orderId = $orderItemStatus['order_id'];
        error_log("[confirm_pick] Checking if entire order ID {$orderId} is fully picked.");
        $checkOrderCompleteStmt = $db->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND quantity_ordered > picked_quantity");
        $checkOrderCompleteStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
        $checkOrderCompleteStmt->execute();
        $remainingItems = $checkOrderCompleteStmt->fetchColumn();
        error_log("[confirm_pick] Remaining items for order ID {$orderId}: " . $remainingItems);

        if ($remainingItems === 0) {
            $orderFullyPicked = true;
            error_log("[confirm_pick] Order ID {$orderId} is now fully picked. Updating status.");
            $updateOrderStatusStmt = $db->prepare("UPDATE orders SET status = 'Awaiting Shipment' WHERE id = :order_id AND status = 'Picking'");
            $updateOrderStatusStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
            $updateOrderStatusStmt->execute();
            error_log("[confirm_pick] Order status update affected rows: " . $updateOrderStatusStmt->rowCount());
        }
    }

    // --- Commit Transaction ---
    error_log("[confirm_pick] Attempting to commit transaction.");
    if ($db->commit()) {
        error_log("[confirm_pick] Transaction committed successfully.");
        http_response_code(200); // OK
        $response = [
            'status' => 'success',
            'message' => "Pick confirmed for {$quantityPicked} units.",
            'order_item_fully_picked' => $orderItemFullyPicked,
            'order_fully_picked' => $orderFullyPicked
        ];
    } else {
        error_log("[confirm_pick] db->commit() returned false.");
        $db->rollBack();
        throw new Exception("Failed to commit database transaction.");
    }

} catch (PDOException $e) {
    $db->rollBack();
    error_log("[confirm_pick] PDOException during transaction: " . $e->getMessage() . " Input: " . json_encode($inputData));
    http_response_code(500);
    $response = ['status' => 'error', 'message' => 'Database error during pick confirmation.'];
} catch (Exception $e) {
    $db->rollBack();
    error_log("[confirm_pick] Exception during transaction: " . $e->getMessage() . " Input: " . json_encode($inputData));
    http_response_code(500);
    $response = ['status' => 'error', 'message' => 'Server error during pick confirmation.'];
}

// --- Output JSON ---
error_log("[confirm_pick] Sending final response: " . json_encode($response));
echo json_encode($response);
exit;
?>
