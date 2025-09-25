<?php
// File: /api/picking/confirm_pick.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

// Define Base Path
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/models/Inventory.php';

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

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $inventoryModel = new Inventory($db);
    $autoOrderProductId = null;


    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input.']);
        exit;
    }

    // Validate required fields
    $required = ['order_item_id', 'quantity_picked'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Field '{$field}' is required."]);
            exit;
        }
    }

    $orderItemId = (int)$input['order_item_id'];
    $quantityPicked = (int)$input['quantity_picked'];
    $inventoryId = isset($input['inventory_id']) ? (int)$input['inventory_id'] : null;

    if ($orderItemId <= 0 || $quantityPicked <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid order item ID or quantity.']);
        exit;
    }

    $db->beginTransaction();

    // Get current order item info
    $itemQuery = "
        SELECT 
            oi.order_id,
            oi.product_id,
            oi.quantity_ordered,
            oi.picked_quantity,
            o.order_number,
            p.name as product_name,
            p.sku as product_sku
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN products p ON oi.product_id = p.product_id
        WHERE oi.id = :order_item_id
    ";

    $itemStmt = $db->prepare($itemQuery);
    $itemStmt->execute([':order_item_id' => $orderItemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        $db->rollback();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Order item not found.']);
        exit;
    }

    $autoOrderProductId = (int)$item['product_id'];

    $currentPicked = (int)$item['picked_quantity'];
    $newPickedTotal = $currentPicked + $quantityPicked;

    // Validate that we don't pick more than ordered
    if ($newPickedTotal > $item['quantity_ordered']) {
        $db->rollback();
        http_response_code(400);
        echo json_encode([
            'status' => 'error', 
            'message' => "Cannot pick more than ordered. Ordered: {$item['quantity_ordered']}, Already picked: {$currentPicked}, Trying to pick: {$quantityPicked}"
        ]);
        exit;
    }

    // Update the picked quantity in order_items
    $updateItemQuery = "
        UPDATE order_items 
        SET picked_quantity = :new_picked_total,
            updated_at = NOW()
        WHERE id = :order_item_id
    ";

    $updateItemStmt = $db->prepare($updateItemQuery);
    $updateItemStmt->execute([
        ':new_picked_total' => $newPickedTotal,
        ':order_item_id' => $orderItemId
    ]);

    // If inventory_id is provided, reduce inventory using the Inventory model so auto-order checks run
    $locationId = null;
    if ($inventoryId) {
        $inventoryStmt = $db->prepare('SELECT product_id, location_id FROM inventory WHERE id = :inventory_id');
        $inventoryStmt->execute([':inventory_id' => $inventoryId]);
        $inventoryRow = $inventoryStmt->fetch(PDO::FETCH_ASSOC);

        if (!$inventoryRow) {
            $db->rollback();
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Inventory record not found.'
            ]);
            exit;
        }
        if ((int)$inventoryRow['product_id'] !== (int)$item['product_id']) {

            $db->rollback();
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Inventory record does not match the requested product.'
            ]);
            exit;
        }

        $locationId = $inventoryRow['location_id'] !== null ? (int)$inventoryRow['location_id'] : null;

        if (!$inventoryModel->removeStock((int)$item['product_id'], $quantityPicked, $locationId, false)) {
            $db->rollback();
            if ($autoOrderProductId !== null) {
                $inventoryModel->discardDeferredAutoOrder($autoOrderProductId);
            }
            echo json_encode([
                'status' => 'error',
                'message' => 'Insufficient inventory at specified location.'
            ]);
            exit;
        }
    }

    // Check if this order item is now fully picked
    $isItemComplete = ($newPickedTotal >= $item['quantity_ordered']);

    // Check if entire order is complete
    $remainingQuery = "
        SELECT COUNT(*) as remaining_items
        FROM order_items 
        WHERE order_id = :order_id 
        AND quantity_ordered > COALESCE(picked_quantity, 0)
    ";

    $remainingStmt = $db->prepare($remainingQuery);
    $remainingStmt->execute([':order_id' => $item['order_id']]);
    $remainingItems = (int)$remainingStmt->fetchColumn();

    $isOrderComplete = ($remainingItems === 0);

    // If order is complete, update order status
    if ($isOrderComplete) {
        $updateOrderQuery = "
            UPDATE orders 
            SET status = 'Picked',
                updated_at = NOW()
            WHERE id = :order_id
        ";

        $updateOrderStmt = $db->prepare($updateOrderQuery);
        $updateOrderStmt->execute([':order_id' => $item['order_id']]);
    }

    $db->commit();

    $inventoryModel->processDeferredAutoOrders();

    $userId = $_SESSION['user_id'] ?? 0;
    logActivity(
        $userId,
        'pick',
        'inventory',
        $inventoryId ?? 0,
        'Item picked',
        ['picked_quantity' => $currentPicked],
        ['picked_quantity' => $newPickedTotal]
    );

    // Return success response
    echo json_encode([
        'status' => 'success',
        'message' => $isItemComplete ? 
            "Item fully picked! {$item['product_sku']} - {$item['product_name']}" :
            "Partial pick confirmed. {$quantityPicked} of {$item['product_name']} picked.",
        'data' => [
            'order_number' => $item['order_number'],
            'product_sku' => $item['product_sku'],
            'product_name' => $item['product_name'],
            'quantity_picked_now' => $quantityPicked,
            'total_picked' => $newPickedTotal,
            'quantity_ordered' => (int)$item['quantity_ordered'],
            'item_complete' => $isItemComplete,
            'order_complete' => $isOrderComplete,
            'remaining_items_in_order' => $remainingItems
        ]
    ]);

} catch (PDOException $e) {
    if ($db && $db->inTransaction()) {
        $db->rollback();
    }
    if ($autoOrderProductId !== null) {
        $inventoryModel->discardDeferredAutoOrder($autoOrderProductId);
    }
    error_log("Database error in confirm_pick.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error.',
        'error_code' => 'DB_ERROR',
        'specific_error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    if ($db && $db->inTransaction()) {
        $db->rollback();
    }
    if ($autoOrderProductId !== null) {
        $inventoryModel->discardDeferredAutoOrder($autoOrderProductId);
    }
    error_log("General error in confirm_pick.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error.',
        'error_code' => 'SERVER_ERROR',
        'specific_error' => $e->getMessage()
    ]);
}
?>