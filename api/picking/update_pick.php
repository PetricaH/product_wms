<?php
// File: api/picking/update_pick.php
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

    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
        exit;
    }

    // Get and validate input
    $orderItemId = $_POST['order_item_id'] ?? '';
    $quantityPicked = $_POST['quantity_picked'] ?? '';
    $orderId = $_POST['order_id'] ?? '';

    if (empty($orderItemId) || empty($quantityPicked) || empty($orderId)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Order item ID, quantity picked, and order ID are required.'
        ]);
        exit;
    }

    $quantityPicked = (int)$quantityPicked;
    if ($quantityPicked <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Quantity must be greater than 0.']);
        exit;
    }

    // Begin transaction
    $db->beginTransaction();

    try {
        // Get current order item details
        $itemQuery = "
            SELECT 
                oi.id,
                oi.order_id,
                oi.product_id,
                oi.quantity,
                oi.picked_quantity,
                p.name as product_name,
                p.sku
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.product_id
            WHERE oi.id = :order_item_id AND oi.order_id = :order_id
        ";
        
        $itemStmt = $db->prepare($itemQuery);
        $itemStmt->execute([
            ':order_item_id' => $orderItemId,
            ':order_id' => $orderId
        ]);
        $orderItem = $itemStmt->fetch(PDO::FETCH_ASSOC);

        if (!$orderItem) {
            throw new Exception('Order item not found.');
        }

        $currentPicked = (int)($orderItem['picked_quantity'] ?? 0);
        $totalOrdered = (int)$orderItem['quantity'];
        $newTotalPicked = $currentPicked + $quantityPicked;

        // Validate that we don't exceed ordered quantity
        if ($newTotalPicked > $totalOrdered) {
            throw new Exception("Cannot pick more than ordered quantity. Ordered: {$totalOrdered}, Already picked: {$currentPicked}, Trying to pick: {$quantityPicked}");
        }

        // Update the order item with new picked quantity
        $updateQuery = "
            UPDATE order_items 
            SET picked_quantity = :new_picked_quantity,
                updated_at = NOW()
            WHERE id = :order_item_id
        ";
        
        $updateStmt = $db->prepare($updateQuery);
        $updateResult = $updateStmt->execute([
            ':new_picked_quantity' => $newTotalPicked,
            ':order_item_id' => $orderItemId
        ]);

        if (!$updateResult) {
            throw new Exception('Failed to update picked quantity.');
        }

        // Check if all items in the order are fully picked
        $remainingItemsQuery = "
            SELECT COUNT(*) as remaining_items
            FROM order_items 
            WHERE order_id = :order_id 
            AND (picked_quantity IS NULL OR picked_quantity < quantity)
        ";
        
        $remainingStmt = $db->prepare($remainingItemsQuery);
        $remainingStmt->execute([':order_id' => $orderId]);
        $remainingResult = $remainingStmt->fetch(PDO::FETCH_ASSOC);
        $remainingItems = (int)$remainingResult['remaining_items'];

        // Update order status if all items are picked
        $orderStatusUpdated = false;
        if ($remainingItems === 0) {
            $updateOrderQuery = "
                UPDATE orders 
                SET status = 'picked', 
                    updated_at = NOW()
                WHERE id = :order_id
            ";
            
            $updateOrderStmt = $db->prepare($updateOrderQuery);
            $updateOrderResult = $updateOrderStmt->execute([':order_id' => $orderId]);
            
            if ($updateOrderResult) {
                $orderStatusUpdated = true;
            }
        }

        // Commit transaction
        $db->commit();

        // Return success response
        echo json_encode([
            'status' => 'success',
            'message' => 'Pick confirmed successfully.',
            'data' => [
                'order_item_id' => $orderItemId,
                'product_name' => $orderItem['product_name'],
                'sku' => $orderItem['sku'],
                'quantity_picked_now' => $quantityPicked,
                'total_picked' => $newTotalPicked,
                'total_ordered' => $totalOrdered,
                'remaining_to_pick' => max(0, $totalOrdered - $newTotalPicked),
                'order_completed' => $remainingItems === 0,
                'order_status_updated' => $orderStatusUpdated
            ]
        ]);

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Error in update_pick.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'debug_info' => [
            'file' => basename(__FILE__),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}
?>