<?php
/**
 * API: Get Expected Items for Receiving Session - PROGRESS COUNTER FIX
 * File: api/receiving/get_expected_items.php
 * 
 * FIXED: Return correct total_items_expected and total_items_received for progress counter
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Database connection
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

try {
    $sessionId = (int)($_GET['session_id'] ?? 0);
    
    if (!$sessionId) {
        throw new Exception('Session ID is required');
    }
    
    // Validate session exists and user has access
    // FIXED: Use s.supplier_name instead of s.name
    $stmt = $db->prepare("
        SELECT rs.*, po.order_number as po_number, s.supplier_name
        FROM receiving_sessions rs
        JOIN purchase_orders po ON rs.purchase_order_id = po.id
        JOIN sellers s ON rs.supplier_id = s.id
        WHERE rs.id = :session_id
        AND (rs.received_by = :user_id OR :user_id IN (
            SELECT id FROM users WHERE role IN ('admin', 'manager')
        ))
    ");
    $stmt->execute([
        ':session_id' => $sessionId,
        ':user_id' => $_SESSION['user_id']
    ]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        throw new Exception('Receiving session not found or access denied');
    }
    
    // FIXED: Calculate current totals for progress counter
    // Count expected items
    $stmt = $db->prepare("
        SELECT COUNT(*) as expected_count 
        FROM purchase_order_items 
        WHERE purchase_order_id = :po_id
    ");
    $stmt->execute([':po_id' => $session['purchase_order_id']]);
    $expectedCount = $stmt->fetchColumn();
    
    // Count received items
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT purchase_order_item_id) as received_count
        FROM receiving_items 
        WHERE receiving_session_id = :session_id
    ");
    $stmt->execute([':session_id' => $sessionId]);
    $receivedCount = $stmt->fetchColumn();
    
    // Update the session record with correct totals
    $stmt = $db->prepare("
        UPDATE receiving_sessions 
        SET total_items_expected = :expected_count,
            total_items_received = :received_count,
            updated_at = NOW()
        WHERE id = :session_id
    ");
    $stmt->execute([
        ':expected_count' => $expectedCount,
        ':received_count' => $receivedCount,
        ':session_id' => $sessionId
    ]);
    
    // Get expected items from purchase order with current receiving status
    $stmt = $db->prepare("
        SELECT
            poi.id,
            COALESCE(pp.internal_product_id, p.product_id) as product_id,
            pp.supplier_product_name as product_name,
            pp.supplier_product_code as sku,
            COALESCE(p.barcode, pp.supplier_product_code) as barcode,
            poi.quantity as expected_quantity,
            poi.unit_price,
            COALESCE(ri.received_quantity, 0) as received_quantity,
            COALESCE(ri.location_id, 0) as location_id,
            COALESCE(l.location_code, '') as location_code,
            ri.condition_status,
            ri.batch_number,
            ri.expiry_date,
            ri.notes,
            COALESCE(ri.tracking_method, 'bulk') as tracking_method,
            ri.barcode_task_id,
            bct.expected_quantity as barcode_expected_quantity,
            bct.scanned_quantity as barcode_scanned_quantity,
            bct.status as barcode_task_status,
            CASE
                WHEN ri.id IS NULL THEN 'pending'
                WHEN COALESCE(ri.tracking_method, 'bulk') = 'individual' AND (
                    bct.task_id IS NULL
                    OR NOT (
                        bct.status = 'completed'
                        OR (bct.scanned_quantity >= bct.expected_quantity AND bct.expected_quantity IS NOT NULL)
                    )
                ) THEN 'pending_scan'
                WHEN ri.received_quantity >= poi.quantity THEN 'received'
                WHEN ri.received_quantity < poi.quantity THEN 'partial'
                ELSE 'pending'
            END as status,
            ri.created_at as received_at
        FROM purchase_order_items poi
        JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
        LEFT JOIN products p ON (pp.internal_product_id = p.product_id OR pp.supplier_product_code = p.sku)
        LEFT JOIN receiving_items ri ON poi.id = ri.purchase_order_item_id
            AND ri.receiving_session_id = :session_id
        LEFT JOIN barcode_capture_tasks bct ON ri.barcode_task_id = bct.task_id
        LEFT JOIN locations l ON ri.location_id = l.id
        WHERE poi.purchase_order_id = :purchase_order_id
        ORDER BY poi.id ASC
    ");
    $stmt->execute([
        ':session_id' => $sessionId,
        ':purchase_order_id' => $session['purchase_order_id']
    ]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format items for response
    $formattedItems = array_map(function($item) {
        return [
            'id' => (int)$item['id'],
            'product_id' => (int)$item['product_id'],
            'product_name' => $item['product_name'],
            'sku' => $item['sku'],
            'barcode' => $item['barcode'],
            'expected_quantity' => (float)$item['expected_quantity'],
            'received_quantity' => (float)$item['received_quantity'],
            'unit_price' => (float)$item['unit_price'],
            'location_id' => (int)$item['location_id'],
            'location_code' => $item['location_code'],
            'condition_status' => $item['condition_status'] ?: 'good',
            'batch_number' => $item['batch_number'] ?: '',
            'expiry_date' => $item['expiry_date'] ?: '',
            'notes' => $item['notes'] ?: '',
            'tracking_method' => $item['tracking_method'],
            'barcode_task_id' => $item['barcode_task_id'] ? (int)$item['barcode_task_id'] : null,
            'barcode_expected' => $item['barcode_expected_quantity'] !== null ? (int)$item['barcode_expected_quantity'] : null,
            'barcode_scanned' => $item['barcode_scanned_quantity'] !== null ? (int)$item['barcode_scanned_quantity'] : null,
            'barcode_status' => $item['barcode_task_status'],
            'status' => $item['status'],
            'received_at' => $item['received_at']
        ];
    }, $items);

    // FIXED: Return session data with correct progress totals
    echo json_encode([
        'success' => true,
        'session' => [
            'id' => (int)$session['id'],
            'session_number' => $session['session_number'],
            'po_number' => $session['po_number'],
            'supplier_name' => $session['supplier_name'],
            'status' => $session['status'],
            'supplier_document_number' => $session['supplier_document_number'],
            'supplier_document_type' => $session['supplier_document_type'],
            'supplier_document_date' => $session['supplier_document_date'],
            'purchase_order_id' => (int)$session['purchase_order_id'],
            'supplier_id' => (int)$session['supplier_id'],
            'total_items_expected' => (int)$expectedCount,  // FIXED: Include progress totals
            'total_items_received' => (int)$receivedCount,   // FIXED: Include progress totals
            'created_at' => $session['created_at']
        ],
        'items' => $formattedItems,
        'summary' => [
            'total_expected' => count($items),
            'total_received' => count(array_filter($items, function($item) {
                return $item['status'] === 'received';
            })),
            'total_partial' => count(array_filter($items, function($item) {
                return $item['status'] === 'partial';
            })),
            'total_pending' => count(array_filter($items, function($item) {
                return $item['status'] === 'pending';
            })),
            'total_pending_scan' => count(array_filter($items, function($item) {
                return $item['status'] === 'pending_scan';
            }))
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Get expected items error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}