<?php
// File: api/warehouse/assign_order.php
// PRODUCTION-READY VERSION - Safely assigns orders without data corruption

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Use the same BASE_PATH detection as other working APIs
if (!defined('BASE_PATH')) {
    // Try the standard location first
    $possiblePaths = [
        dirname(__DIR__, 2),                           // /api/warehouse/ -> /
        $_SERVER['DOCUMENT_ROOT'] . '/product_wms',    // Explicit localhost path
        dirname(__DIR__, 3),                           // Just in case
    ];
    
    foreach ($possiblePaths as $path) {
        if (file_exists($path . '/config/config.php')) {
            define('BASE_PATH', $path);
            break;
        }
    }
}

if (!defined('BASE_PATH') || !file_exists(BASE_PATH . '/config/config.php')) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Configuration not found.',
        'debug_info' => [
            'document_root' => $_SERVER['DOCUMENT_ROOT'],
            'script_path' => __DIR__,
            'checked_paths' => $possiblePaths ?? []
        ]
    ]);
    exit;
}

try {
    $config = require BASE_PATH . '/config/config.php';
    
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        throw new Exception('Database configuration error.');
    }

    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Method not allowed. Use POST.');
    }

    // Get and validate input
    $orderId = $_POST['order_id'] ?? '';
    $action = $_POST['action'] ?? 'assign_picking';
    
    if (empty($orderId)) {
        http_response_code(400);
        throw new Exception('Order ID is required.');
    }

    // Begin transaction for data safety
    $db->beginTransaction();

    try {
        // Find the order (accept both order number and ID)
        $orderQuery = "SELECT id, order_number, customer_name, status, assigned_to FROM orders WHERE order_number = :order_id OR id = :order_id_int";
        $orderStmt = $db->prepare($orderQuery);
        $orderStmt->execute([
            ':order_id' => $orderId,
            ':order_id_int' => is_numeric($orderId) ? (int)$orderId : 0
        ]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception('Order not found: ' . $orderId);
        }

        $actualOrderId = $order['id'];
        $currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
        $assignedTo = $order['assigned_to'];
        if ($assignedTo !== null && $assignedTo !== '') {
            $assignedTo = is_numeric($assignedTo) ? (int)$assignedTo : (string)$assignedTo;
        } else {
            $assignedTo = null;
        }
        $statusLower = strtolower((string)$order['status']);

        // CRITICAL: Verify order has items BEFORE any changes
        $itemCountQuery = "SELECT COUNT(*) FROM order_items WHERE order_id = :order_id";
        $itemCountStmt = $db->prepare($itemCountQuery);
        $itemCountStmt->execute([':order_id' => $actualOrderId]);
        $itemCount = $itemCountStmt->fetchColumn();

        if ($itemCount == 0) {
            throw new Exception('Cannot assign order: No items found in order ' . $order['order_number']);
        }

        // Check if order is already assigned or being processed by someone else
        if (in_array($statusLower, ['assigned', 'processing'], true)) {
            if ($assignedTo !== null && (string)$assignedTo !== (string)$currentUserId) {
                http_response_code(409);
                throw new Exception('Order is already being processed by another worker.');
            }

            if ($statusLower === 'assigned' && (string)$assignedTo === (string)$currentUserId) {
                // Order already assigned to current user - return success for idempotency
                $db->commit();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Order already assigned to current user.',
                    'data' => [
                        'order_id' => $actualOrderId,
                        'order_number' => $order['order_number'],
                        'status' => 'assigned',
                        'assigned_to' => $currentUserId,
                        'items_count' => $itemCount,
                        'redirect' => "mobile_picker.php?order=" . urlencode($order['order_number'])
                    ]
                ]);
                exit;
            }
        }

        // SAFE UPDATE: Only change order status and assignment, NEVER touch order_items
        $updateQuery = "UPDATE orders SET
                       status = 'assigned',
                       assigned_to = :user_id,
                       assigned_at = CURRENT_TIMESTAMP,
                       updated_at = CURRENT_TIMESTAMP
                       WHERE id = :order_id
                         AND (status != 'assigned' OR assigned_to IS NULL)
                         AND (assigned_to IS NULL OR assigned_to = :user_id)";

        $updateStmt = $db->prepare($updateQuery);
        $updateResult = $updateStmt->execute([
            ':user_id' => $currentUserId,
            ':order_id' => $actualOrderId
        ]);

        if (!$updateResult) {
            throw new Exception('Failed to update order status.');
        }

        // VERIFICATION: Double-check that items still exist after update
        $verifyItemCountStmt = $db->prepare($itemCountQuery);
        $verifyItemCountStmt->execute([':order_id' => $actualOrderId]);
        $finalItemCount = $verifyItemCountStmt->fetchColumn();

        if ($finalItemCount != $itemCount) {
            // This should never happen, but if it does, rollback
            throw new Exception('Data integrity error: Order items were modified during assignment.');
        }

        // Final verification that the order was updated correctly
        $verifyOrderQuery = "SELECT id, order_number, status, assigned_to FROM orders WHERE id = :order_id";
        $verifyOrderStmt = $db->prepare($verifyOrderQuery);
        $verifyOrderStmt->execute([':order_id' => $actualOrderId]);
        $verifiedOrder = $verifyOrderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$verifiedOrder || $verifiedOrder['status'] !== 'assigned') {
            throw new Exception('Order assignment verification failed.');
        }

        // Success! Commit the transaction
        $db->commit();

        // Return success response
        echo json_encode([
            'status' => 'success',
            'message' => 'Order assigned successfully for picking.',
            'data' => [
                'order_id' => $actualOrderId,
                'order_number' => $order['order_number'],
                'status' => 'assigned',
                'assigned_to' => $currentUserId,
                'items_count' => $finalItemCount,
                'redirect' => "mobile_picker.php?order=" . urlencode($order['order_number'])
            ]
        ]);

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Error in assign_order.php: " . $e->getMessage());
    
    // Return error response
    $statusCode = http_response_code();
    if ($statusCode === 200) {
        $statusCode = strpos($e->getMessage(), 'not found') !== false ? 404 : 500;
    }
    http_response_code($statusCode);

    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'debug_info' => [
            'file' => basename(__FILE__),
            'line' => __LINE__,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}
?>