<?php
/**
 * API: Purchase Order Receiving Details
 * File: api/receiving/purchase_order_details.php
 * 
 * Returns detailed receiving information for a specific purchase order
 * Used for expandable rows in the admin purchase orders page
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Database connection
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Get purchase order ID from request
$orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order ID']);
    exit;
}

try {
    // Get purchase order basic info
    $stmt = $db->prepare("
        SELECT po.*, s.supplier_name, u.username as created_by_name
        FROM purchase_orders po
        LEFT JOIN sellers s ON po.seller_id = s.id
        LEFT JOIN users u ON po.created_by = u.id
        WHERE po.id = :order_id
    ");
    $stmt->execute([':order_id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Purchase order not found']);
        exit;
    }
    
    // Get receiving sessions for this purchase order
    $stmt = $db->prepare("
        SELECT 
            rs.*,
            u.username as received_by_name,
            COUNT(DISTINCT ri.id) as items_received_count,
            COUNT(DISTINCT rd.id) as discrepancies_count
        FROM receiving_sessions rs
        LEFT JOIN users u ON rs.received_by = u.id
        LEFT JOIN receiving_items ri ON rs.id = ri.receiving_session_id
        LEFT JOIN receiving_discrepancies rd ON rs.id = rd.receiving_session_id
        WHERE rs.purchase_order_id = :order_id
        GROUP BY rs.id
        ORDER BY rs.created_at DESC
    ");
    $stmt->execute([':order_id' => $orderId]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get detailed item breakdown
    $stmt = $db->prepare("
        SELECT 
            poi.id as item_id,
            COALESCE(pp.supplier_product_name, p.name) as product_name,
            COALESCE(pp.supplier_product_code, p.sku) as sku,
            poi.quantity as ordered_quantity,
            poi.unit_price,
            poi.total_price,
            
            -- Receiving info
            COALESCE(ri.received_quantity, 0) as received_quantity,
            ri.condition_status,
            ri.batch_number,
            ri.expiry_date,
            ri.notes as receiving_notes,
            ri.created_at as received_at,
            l.location_code,
            
            -- Receiving session info
            rs.session_number,
            rs.supplier_document_number,
            u.username as received_by_name,
            
            -- Calculate status
            CASE 
                WHEN ri.id IS NULL THEN 'not_received'
                WHEN ri.received_quantity >= poi.quantity THEN 'complete'
                WHEN ri.received_quantity < poi.quantity THEN 'partial'
                ELSE 'not_received'
            END as receiving_status,
            
            -- Calculate variance
            CASE 
                WHEN ri.id IS NULL THEN poi.quantity
                ELSE (poi.quantity - ri.received_quantity)
            END as quantity_variance
            
        FROM purchase_order_items poi
        LEFT JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
        LEFT JOIN products p ON pp.internal_product_id = p.product_id
        LEFT JOIN receiving_items ri ON poi.id = ri.purchase_order_item_id
        LEFT JOIN receiving_sessions rs ON ri.receiving_session_id = rs.id
        LEFT JOIN locations l ON ri.location_id = l.id
        LEFT JOIN users u ON rs.received_by = u.id
        WHERE poi.purchase_order_id = :order_id
        ORDER BY poi.id, ri.created_at DESC
    ");
    $stmt->execute([':order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get discrepancies for this purchase order
    $stmt = $db->prepare("
        SELECT 
            rd.*,
            COALESCE(pp.supplier_product_name, p.name) as product_name,
            COALESCE(pp.supplier_product_code, p.sku) as sku,
            rs.session_number,
            rs.supplier_document_number,
            u1.username as received_by_name,
            u2.username as resolved_by_name
        FROM receiving_discrepancies rd
        JOIN receiving_sessions rs ON rd.receiving_session_id = rs.id
        LEFT JOIN products p ON rd.product_id = p.product_id
        LEFT JOIN purchasable_products pp ON pp.internal_product_id = p.product_id
        LEFT JOIN users u1 ON rs.received_by = u1.id
        LEFT JOIN users u2 ON rd.resolved_by = u2.id
        WHERE rs.purchase_order_id = :order_id
        ORDER BY rd.created_at DESC
    ");
    $stmt->execute([':order_id' => $orderId]);
    $discrepancies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response
    $response = [
        'success' => true,
        'order_info' => [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'supplier_name' => $order['supplier_name'],
            'total_amount' => number_format((float)$order['total_amount'], 2),
            'currency' => $order['currency'],
            'status' => $order['status'],
            'expected_delivery_date' => $order['expected_delivery_date'],
            'actual_delivery_date' => $order['actual_delivery_date'],
            'created_by_name' => $order['created_by_name'],
            'created_at' => $order['created_at']
        ],
        
        'receiving_sessions' => array_map(function($session) {
            return [
                'id' => (int)$session['id'],
                'session_number' => $session['session_number'],
                'supplier_document_number' => $session['supplier_document_number'],
                'supplier_document_type' => $session['supplier_document_type'],
                'supplier_document_date' => $session['supplier_document_date'],
                'status' => $session['status'],
                'total_items_expected' => (int)$session['total_items_expected'],
                'total_items_received' => (int)$session['total_items_received'],
                'items_received_count' => (int)$session['items_received_count'],
                'discrepancies_count' => (int)$session['discrepancies_count'],
                'received_by_name' => $session['received_by_name'],
                'created_at' => $session['created_at'],
                'completed_at' => $session['completed_at'],
                'discrepancy_notes' => $session['discrepancy_notes']
            ];
        }, $sessions),
        
        'items_detail' => array_map(function($item) {
            return [
                'item_id' => (int)$item['item_id'],
                'product_name' => $item['product_name'],
                'sku' => $item['sku'],
                'ordered_quantity' => (float)$item['ordered_quantity'],
                'received_quantity' => (float)$item['received_quantity'],
                'quantity_variance' => (float)$item['quantity_variance'],
                'unit_price' => number_format((float)$item['unit_price'], 2),
                'total_price' => number_format((float)$item['total_price'], 2),
                'receiving_status' => $item['receiving_status'],
                'condition_status' => $item['condition_status'],
                'batch_number' => $item['batch_number'],
                'expiry_date' => $item['expiry_date'],
                'location_code' => $item['location_code'],
                'receiving_notes' => $item['receiving_notes'],
                'received_at' => $item['received_at'],
                'session_number' => $item['session_number'],
                'supplier_document_number' => $item['supplier_document_number'],
                'received_by_name' => $item['received_by_name']
            ];
        }, $items),
        
        'discrepancies' => array_map(function($discrepancy) {
            $typeMap = [
                'quantity_short' => 'Mai Puțină Cantitate',
                'quantity_over' => 'Mai Multă Cantitate',
                'quality_issue' => 'Problemă Calitate',
                'missing_item' => 'Articol Lipsă',
                'unexpected_item' => 'Articol Neașteptat'
            ];

            return [
                'id' => (int)$discrepancy['id'],
                'product_name' => $discrepancy['product_name'],
                'sku' => $discrepancy['sku'],
                'discrepancy_type' => $discrepancy['discrepancy_type'],
                'discrepancy_type_label' => $typeMap[$discrepancy['discrepancy_type']] ?? $discrepancy['discrepancy_type'],
                'expected_quantity' => (float)$discrepancy['expected_quantity'],
                'actual_quantity' => (float)$discrepancy['actual_quantity'],
                'description' => $discrepancy['description'],
                'resolution_status' => $discrepancy['resolution_status'],
                'resolution_notes' => $discrepancy['resolution_notes'],
                'session_number' => $discrepancy['session_number'],
                'supplier_document_number' => $discrepancy['supplier_document_number'],
                'received_by_name' => $discrepancy['received_by_name'],
                'resolved_by_name' => $discrepancy['resolved_by_name'],
                'created_at' => $discrepancy['created_at'],
                'resolved_at' => $discrepancy['resolved_at']
            ];
        }, $discrepancies),
        
        'summary_stats' => [
            'total_sessions' => count($sessions),
            'completed_sessions' => count(array_filter($sessions, function($s) { return $s['status'] === 'completed'; })),
            'total_items' => count($items),
            'received_items' => count(array_filter($items, function($i) { return $i['receiving_status'] !== 'not_received'; })),
            'pending_discrepancies' => count(array_filter($discrepancies, function($d) { return $d['resolution_status'] === 'pending'; })),
            'total_discrepancies' => count($discrepancies)
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Purchase order receiving details error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => 'Unable to fetch purchase order receiving details'
    ]);
}