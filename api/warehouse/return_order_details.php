<?php
declare(strict_types=1);

// Bootstrap and setup
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/bootstrap.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get database connection
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'] ?? null;
if (!$dbFactory || !is_callable($dbFactory)) {
    error_log('[ReturnDetails] Database connection factory not available');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit;
}
$pdo = $dbFactory();

header('Content-Type: application/json; charset=utf-8');

$orderId  = (int)($_GET['order_id']  ?? 0);
$returnId = (int)($_GET['return_id'] ?? 0);

if ($orderId <= 0 || $returnId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing or invalid order_id/return_id']);
    exit;
}

try {
    // Get return info
    $sqlReturn = '
        SELECT
            r.id,
            r.order_id,
            r.processed_by,
            r.verified_by,
            r.status,
            r.return_awb,
            r.auto_created,
            r.return_date,
            r.notes,
            r.verified_at,
            r.created_at,
            r.updated_at
        FROM returns r
        WHERE r.id = :return_id AND r.order_id = :order_id
        LIMIT 1
    ';
    $stmt = $pdo->prepare($sqlReturn);
    $stmt->execute([':return_id' => $returnId, ':order_id' => $orderId]);
    $returnInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($returnInfo === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Return not found for this order']);
        exit;
    }
    
    // Get order info
    $sqlOrder = '
        SELECT
            o.id,
            o.order_number,
            o.customer_name,
            o.status,
            o.order_date,
            o.updated_at,
            o.total_value
        FROM orders o
        WHERE o.id = :order_id
        LIMIT 1
    ';
    $stmt = $pdo->prepare($sqlOrder);
    $stmt->execute([':order_id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }
    
    // Get order items with return items info
    $sqlItems = '
        SELECT
            oi.id AS order_item_id,
            oi.product_id,
            oi.quantity AS quantity_ordered,
            COALESCE(oi.picked_quantity, oi.quantity) AS picked_quantity,
            p.name AS product_name,
            p.sku,
            p.barcode,
            ri.id AS return_item_id,
            ri.quantity_returned,
            ri.item_condition,
            ri.is_extra,
            ri.notes AS return_notes,
            ri.location_id AS processed_location_id,
            ri.updated_at AS processed_updated_at,
            ri.created_at AS processed_created_at,
            l.location_code AS processed_location_code
        FROM order_items oi
        JOIN products p ON oi.product_id = p.product_id
        LEFT JOIN return_items ri ON ri.order_item_id = oi.id AND ri.return_id = :return_id
        LEFT JOIN locations l ON l.id = ri.location_id
        WHERE oi.order_id = :order_id
        ORDER BY oi.id ASC
    ';
    $stmt = $pdo->prepare($sqlItems);
    $stmt->execute([':return_id' => $returnId, ':order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process items and calculate totals
    $processedItems = [];
    $totalExpectedQty = 0;
    $processedCount = 0;
    $processedUnits = 0;
    
    foreach ($items as $item) {
        $pickedQty = (int)($item['picked_quantity'] ?? 0);
        $orderedQty = (int)($item['quantity_ordered'] ?? 0);
        $expectedQty = $pickedQty > 0 ? $pickedQty : $orderedQty;
        
        $isProcessed = !empty($item['return_item_id']);
        if ($isProcessed) {
            $processedCount++;
            $processedUnits += (int)($item['quantity_returned'] ?? 0);
        }
        
        $processedItems[] = [
            'order_item_id' => (int)$item['order_item_id'],
            'product_id' => (int)$item['product_id'],
            'product_name' => $item['product_name'],
            'sku' => $item['sku'],
            'barcode' => $item['barcode'],
            'quantity_ordered' => $orderedQty,
            'picked_quantity' => $pickedQty,
            'expected_quantity' => $expectedQty,
            'is_processed' => $isProcessed,
            'processed_quantity' => $isProcessed ? (int)$item['quantity_returned'] : null,
            'processed_condition' => $isProcessed ? $item['item_condition'] : null,
            'processed_notes' => $isProcessed ? $item['return_notes'] : null,
            'processed_location_id' => $isProcessed ? (int)$item['processed_location_id'] : null,
            'processed_location_code' => $isProcessed ? $item['processed_location_code'] : null,
            'processed_at' => $isProcessed ? ($item['processed_updated_at'] ?? $item['processed_created_at']) : null,
            'return_item_id' => $isProcessed ? (int)$item['return_item_id'] : null,
            'is_extra' => $isProcessed ? (bool)$item['is_extra'] : false
        ];
        
        $totalExpectedQty += $expectedQty;
    }
    
    $totalItems = count($processedItems);
    $allProcessed = $totalItems > 0 && $processedCount === $totalItems;
    
    // Build response
    echo json_encode([
        'success' => true,
        'order' => [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'customer_name' => $order['customer_name'],
            'status' => $order['status'],
            'order_date' => $order['order_date'],
            'updated_at' => $order['updated_at'],
            'total_value' => isset($order['total_value']) ? (float)$order['total_value'] : null
        ],
        'return' => [
            'id' => (int)$returnInfo['id'],
            'status' => $returnInfo['status'],
            'return_date' => $returnInfo['return_date'],
            'return_awb' => $returnInfo['return_awb'],
            'notes' => $returnInfo['notes'],
            'auto_created' => (bool)$returnInfo['auto_created'],
            'created_at' => $returnInfo['created_at'],
            'updated_at' => $returnInfo['updated_at'],
            'verified_at' => $returnInfo['verified_at']
        ],
        'items' => $processedItems,
        'totals' => [
            'items' => $totalItems,
            'expected_quantity' => $totalExpectedQty,
            'processed_items' => $processedCount,
            'processed_quantity' => $processedUnits
        ],
        'processing' => [
            'all_processed' => $allProcessed,
            'processed_items' => $processedCount,
            'total_items' => $totalItems
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log('[ReturnDetails][SQL] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
} catch (Throwable $e) {
    error_log('[ReturnDetails] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal error']);
}