<?php
/**
 * API: Get Expected Items for Receiving Session
 * File: api/receiving/get_expected_items.php
 * 
 * Returns the list of items expected for a receiving session
 * WORKING WITH EXISTING DATABASE STRUCTURE - NO CHANGES NEEDED
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

// Session check
session_start();
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
    
    // Get expected items from purchase order with current receiving status
    // Map purchasable_products to main products via internal_product_id or SKU
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
            CASE 
                WHEN ri.id IS NULL THEN 'pending'
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
            'batch_number' => $item['batch_number'],
            'expiry_date' => $item['expiry_date'],
            'notes' => $item['notes'],
            'status' => $item['status'],
            'received_at' => $item['received_at']
        ];
    }, $items);
    
    // Get session summary
    $totalExpected = count($items);
    $totalReceived = count(array_filter($items, function($item) {
        return $item['status'] === 'received';
    }));
    $totalPartial = count(array_filter($items, function($item) {
        return $item['status'] === 'partial';
    }));
    
    echo json_encode([
        'success' => true,
        'session' => [
            'id' => (int)$session['id'],
            'session_number' => $session['session_number'],
            'po_number' => $session['po_number'],
            'supplier_name' => $session['supplier_name'],
            'status' => $session['status'],
            'supplier_document_number' => $session['supplier_document_number'],
            'supplier_document_type' => $session['supplier_document_type']
        ],
        'items' => $formattedItems,
        'summary' => [
            'total_expected' => $totalExpected,
            'total_received' => $totalReceived,
            'total_partial' => $totalPartial,
            'total_pending' => $totalExpected - $totalReceived - $totalPartial
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