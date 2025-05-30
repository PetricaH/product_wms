<?php
// File: /api/picking/get_next_task.php

// --- Basic Setup ---
header('Content-Type: application/json');
// Report errors, but ideally configure php.ini to not display them in production APIs
error_reporting(E_ALL);
ini_set('display_errors', 0); // Turn off direct display (errors should go to log)
ini_set('log_errors', 1);    // Ensure errors are logged (configure error_log in php.ini)

// --- Define Base Path ---
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

// --- Bootstrap and Config ---
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// --- Database Connection ---
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB factory error.']);
    exit;
}
$dbFactory = $config['connection_factory'];
/** @var PDO $db */
$db = $dbFactory();

// --- Input Handling ---
// Get order_id from query string. Use trim to remove whitespace.
$orderIdInput = isset($_GET['order_id']) ? trim($_GET['order_id']) : null; // Get raw input and trim

if ($orderIdInput === null || $orderIdInput === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Valid Order ID/Number is required.']);
    exit;
}
// Use the potentially non-numeric identifier directly now
$orderIdentifier = $orderIdInput;

// --- Core Logic: Find the Next Pick Task (FIFO) ---
$response = ['status' => 'error', 'message' => 'No items left to pick for this order or order not found.'];
http_response_code(404);

try {
    // Find the internal order ID based on the identifier (assuming order_number is unique)
    $findOrderStmt = $db->prepare("SELECT id FROM orders WHERE order_number = :order_number LIMIT 1");
    $findOrderStmt->bindParam(':order_number', $orderIdentifier, PDO::PARAM_STR);
    $findOrderStmt->execute();
    $orderId = $findOrderStmt->fetchColumn();

    if (!$orderId) {
         // If not found by order_number, try treating input as numeric ID (fallback)
         if (filter_var($orderIdentifier, FILTER_VALIDATE_INT)) {
              $orderId = (int)$orderIdentifier;
              // Verify this numeric ID exists
              $verifyOrderStmt = $db->prepare("SELECT id FROM orders WHERE id = :order_id LIMIT 1");
              $verifyOrderStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
              $verifyOrderStmt->execute();
              if (!$verifyOrderStmt->fetchColumn()) {
                   $orderId = false; // ID doesn't exist
              }
         } else {
              $orderId = false;
         }
    }

    if (!$orderId) {
         $response['message'] = "Order '{$orderIdentifier}' not found.";
         http_response_code(404);
         echo json_encode($response);
         exit;
    }


    // 1. Find the next unpicked item in the order using the internal orderId
    // Assumes 'picked_quantity' column exists in 'order_items'
    $orderItemQuery = "SELECT
                           oi.id as order_item_id,
                           oi.product_id,
                           p.sku as product_sku,
                           p.name as product_name,
                           (oi.quantity_ordered - oi.picked_quantity) as quantity_needed
                       FROM order_items oi
                       JOIN products p ON oi.product_id = p.product_id
                       WHERE oi.order_id = :order_id
                         AND oi.quantity_ordered > oi.picked_quantity
                       ORDER BY oi.id ASC -- Or your specific picking priority
                       LIMIT 1";
    $stmtItem = $db->prepare($orderItemQuery);
    $stmtItem->bindParam(':order_id', $orderId, PDO::PARAM_INT);
    $stmtItem->execute();
    $orderItem = $stmtItem->fetch(PDO::FETCH_ASSOC);

    if ($orderItem) {
        $productId = $orderItem['product_id'];
        $quantityNeeded = (int)$orderItem['quantity_needed'];

        // 2. Find the oldest inventory batch for this product ID (FIFO)
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
                           ORDER BY i.received_at ASC, i.id ASC -- FIFO logic
                           LIMIT 1";
        $stmtInv = $db->prepare($inventoryQuery);
        $stmtInv->bindParam(':product_id', $productId, PDO::PARAM_INT);
        $stmtInv->execute();
        $inventoryLocation = $stmtInv->fetch(PDO::FETCH_ASSOC);

        if ($inventoryLocation) {
            // 3. Determine quantity to pick from this specific inventory record
            $quantityToPick = min($quantityNeeded, (int)$inventoryLocation['available_quantity']);

            // 4. Prepare the success response
            $response = [
                'status' => 'success',
                'data' => [
                    'order_id' => (int)$orderId, // Return the internal ID
                    'order_identifier' => $orderIdentifier, // Return the original identifier used
                    'order_item_id' => (int)$orderItem['order_item_id'],
                    'product_id' => (int)$productId,
                    'product_sku' => $orderItem['product_sku'],
                    'product_name' => $orderItem['product_name'],
                    'location_code' => $inventoryLocation['location_code'],
                    'inventory_id' => (int)$inventoryLocation['inventory_id'],
                    'batch_number' => $inventoryLocation['batch_number'],
                    'lot_number' => $inventoryLocation['lot_number'],
                    'expiry_date' => $inventoryLocation['expiry_date'],
                    'quantity_to_pick' => $quantityToPick,
                    'total_needed_for_item' => $quantityNeeded,
                    'available_in_location' => (int)$inventoryLocation['available_quantity']
                ]
            ];
            http_response_code(200); // OK

        } else {
            $response['message'] = "Item '{$orderItem['product_name']}' (ID: {$productId}) required, but no available stock found.";
            http_response_code(404);
        }
    } else {
         $response['status'] = 'complete';
         $response['message'] = 'All items for this order appear to be picked.';
         http_response_code(200); // OK, but indicate completion
    }

} catch (PDOException $e) {
    http_response_code(500);
    error_log("API Error (PDO) in get_next_task.php for Order '{$orderIdentifier}': " . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'Database error fetching task.'];
} catch (Exception $e) {
    http_response_code(500);
    error_log("API Error (General) in get_next_task.php for Order '{$orderIdentifier}': " . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'Server error fetching task.'];
}

// --- Output JSON ---
echo json_encode($response);
exit;
?>
