<?php
/**
 * API: Purchase Order Summary with Invoice Support
 * File: api/receiving/purchase_order_summary.php
 * 
 * Updated to include invoice information
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

try {
    $statusFilter = $_GET['status'] ?? '';
    $sellerFilter = intval($_GET['seller_id'] ?? 0);
    $receivingStatusFilter = $_GET['receiving_status'] ?? '';
    $limit = min(intval($_GET['limit'] ?? 100), 500);

    // Updated query to include invoice information
    $sql = "
        SELECT 
            po.id,
            po.order_number,
            po.status as po_status,
            po.total_amount,
            po.currency,
            po.expected_delivery_date,
            po.actual_delivery_date,
            po.created_at,
            po.updated_at,
            
            -- NEW: Invoice information
            po.invoiced,
            po.invoice_file_path,
            po.invoiced_at,
            
            -- Supplier info
            s.supplier_name,
            s.id as supplier_id,
            
            -- Creator info
            u.username as created_by_name,
            
            -- Order items summary
            COUNT(DISTINCT poi.id) as total_items_ordered,
            SUM(poi.quantity) as total_quantity_ordered,
            
            -- Receiving sessions summary
            COUNT(DISTINCT rs.id) as receiving_sessions_count,
            MAX(rs.completed_at) as last_received_date,
            
            -- Receiving items summary
            COUNT(DISTINCT ri.id) as total_items_received,
            COALESCE(SUM(ri.received_quantity), 0) as total_quantity_received,
            
            -- Discrepancies summary
            COUNT(DISTINCT rd.id) as total_discrepancies,
            SUM(CASE WHEN rd.resolution_status = 'pending' THEN 1 ELSE 0 END) as pending_discrepancies,
            SUM(CASE WHEN rd.resolution_status = 'resolved' THEN 1 ELSE 0 END) as resolved_discrepancies,
            COUNT(DISTINCT CASE WHEN rd.resolution_status = 'pending' THEN rd.product_id END) as pending_discrepant_items,
            COUNT(DISTINCT CASE WHEN rd.resolution_status = 'pending' AND rd.discrepancy_type = 'quantity_short' THEN rd.product_id END) as pending_items_short,
            COUNT(DISTINCT CASE WHEN rd.resolution_status = 'pending' AND rd.discrepancy_type = 'quantity_over' THEN rd.product_id END) as pending_items_over,
            
            -- Calculate receiving status
            CASE 
                WHEN COUNT(DISTINCT rs.id) = 0 THEN 'not_received'
                WHEN COUNT(DISTINCT rd.id) > 0 AND SUM(CASE WHEN rd.resolution_status = 'pending' THEN 1 ELSE 0 END) > 0 THEN 'with_discrepancies'
                WHEN COUNT(DISTINCT ri.id) = 0 THEN 'not_received'
                WHEN COUNT(DISTINCT ri.id) < COUNT(DISTINCT poi.id) THEN 'partial'
                WHEN COUNT(DISTINCT ri.id) = COUNT(DISTINCT poi.id) THEN 'complete'
                ELSE 'partial'
            END as receiving_status,
            
            -- Calculate progress percentage
            CASE 
                WHEN COUNT(DISTINCT poi.id) = 0 THEN 0
                ELSE ROUND((COUNT(DISTINCT ri.id) / COUNT(DISTINCT poi.id)) * 100, 1)
            END as receiving_progress_percent,
            
            -- Quantity progress percentage
            CASE 
                WHEN SUM(poi.quantity) = 0 THEN 0
                ELSE ROUND((COALESCE(SUM(ri.received_quantity), 0) / SUM(poi.quantity)) * 100, 1)
            END as quantity_progress_percent
            
        FROM purchase_orders po
        LEFT JOIN sellers s ON po.seller_id = s.id
        LEFT JOIN users u ON po.created_by = u.id
        LEFT JOIN purchase_order_items poi ON po.id = poi.purchase_order_id
        LEFT JOIN receiving_sessions rs ON po.id = rs.purchase_order_id AND rs.status = 'completed'
        LEFT JOIN receiving_items ri ON poi.id = ri.purchase_order_item_id
        LEFT JOIN receiving_discrepancies rd ON rs.id = rd.receiving_session_id
        WHERE 1=1
    ";

    $params = [];

    // apply filters
    if (!empty($statusFilter)) {
        $sql .= " AND po.status = :status";
        $params[':status'] = $statusFilter;
    }

    if ($sellerFilter > 0) {
        $sql .= " AND po.seller_id = :seller_id";
        $params[':seller_id'] = $sellerFilter;
    }

    // Fixed: Corrected column name (was total_ammount)
    $sql .= " GROUP BY po.id, po.order_number, po.status, po.total_amount, po.currency,
                     po.expected_delivery_date, po.actual_delivery_date, po.created_at, po.updated_at,
                     po.invoiced, po.invoice_file_path, po.invoiced_at,
                     s.supplier_name, s.id, u.username";
    
    // apply receiving status filter after grouping
    if (!empty($receivingStatusFilter)) {
        $sql .= " HAVING receiving_status = :receiving_status";
        $params[':receiving_status'] = $receivingStatusFilter;
    }

    $sql .= " ORDER BY po.created_at DESC LIMIT :limit";
    $params[':limit'] = $limit;

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        if ($key === ':limit') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // format orders for response with invoice information
    $formattedOrders = [];
    foreach ($orders as $order) {
        $formattedOrders[] = [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'po_status' => $order['po_status'],
            'total_amount' => number_format((float)$order['total_amount'], 2),
            'currency' => $order['currency'],
            'expected_delivery_date' => $order['expected_delivery_date'],
            'actual_delivery_date' => $order['actual_delivery_date'],
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            
            // NEW: Invoice information
            'invoiced' => (bool)$order['invoiced'],
            'invoice_file_path' => $order['invoice_file_path'],
            'invoiced_at' => $order['invoiced_at'],
            
            // Supplier info
            'supplier_name' => $order['supplier_name'],
            'supplier_id' => (int)$order['supplier_id'],
            'created_by_name' => $order['created_by_name'],
            
            // Order summary
            'order_summary' => [
                'total_items_ordered' => (int)$order['total_items_ordered'],
                'total_quantity_ordered' => (float)$order['total_quantity_ordered']
            ],
            
            // Receiving summary
            'receiving_summary' => [
                'status' => $order['receiving_status'],
                'receiving_sessions_count' => (int)$order['receiving_sessions_count'],
                'last_received_date' => $order['last_received_date'],
                'total_items_received' => (int)$order['total_items_received'],
                'total_quantity_received' => (float)$order['total_quantity_received'],
                'receiving_progress_percent' => (float)$order['receiving_progress_percent'],
                'quantity_progress_percent' => (float)$order['quantity_progress_percent']
            ],
            
            // Discrepancies summary
            'discrepancies_summary' => [
                'total_discrepancies' => (int)$order['total_discrepancies'],
                'pending_discrepancies' => (int)$order['pending_discrepancies'],
                'resolved_discrepancies' => (int)$order['resolved_discrepancies'],
                'pending_discrepant_items' => (int)$order['pending_discrepant_items'],
                'pending_items_short' => (int)$order['pending_items_short'],
                'pending_items_over' => (int)$order['pending_items_over'],
                'has_pending_discrepancies' => (int)$order['pending_discrepancies'] > 0
            ]
        ];
    }
    
    // get summary statistics including invoice stats
    $statsStmt = $db->prepare("
        SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN po.status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
            SUM(CASE WHEN po.status = 'partial_delivery' THEN 1 ELSE 0 END) as partial_orders,
            SUM(CASE WHEN rs.id IS NOT NULL THEN 1 ELSE 0 END) as orders_with_receiving,
            SUM(CASE WHEN rd.id IS NOT NULL AND rd.resolution_status = 'pending' THEN 1 ELSE 0 END) as orders_with_pending_discrepancies,
            SUM(CASE WHEN po.invoiced = TRUE THEN 1 ELSE 0 END) as invoiced_orders,
            SUM(CASE WHEN po.invoiced = FALSE AND po.status IN ('delivered', 'partial_delivery') THEN 1 ELSE 0 END) as pending_invoices
        FROM purchase_orders po
        LEFT JOIN receiving_sessions rs ON po.id = rs.purchase_order_id AND rs.status = 'completed'
        LEFT JOIN receiving_discrepancies rd ON rs.id = rd.receiving_session_id AND rd.resolution_status = 'pending'
        WHERE po.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Return response with invoice stats
    echo json_encode([
        'success' => true,
        'data' => $formattedOrders,
        'pagination' => [
            'total_returned' => count($formattedOrders),
            'limit' => $limit
        ],
        'summary_stats' => [
            'total_orders_last_30_days' => (int)$stats['total_orders'],
            'delivered_orders' => (int)$stats['delivered_orders'],
            'partial_orders' => (int)$stats['partial_orders'],
            'orders_with_receiving' => (int)$stats['orders_with_receiving'],
            'orders_with_pending_discrepancies' => (int)$stats['orders_with_pending_discrepancies'],
            'invoiced_orders' => (int)$stats['invoiced_orders'],
            'pending_invoices' => (int)$stats['pending_invoices']
        ],
        'filters_applied' => [
            'status' => $statusFilter,
            'seller_id' => $sellerFilter,
            'receiving_status' => $receivingStatusFilter
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Purchase order receiving summary error: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => 'Unable to fetch purchase order receiving summary'
    ]);
}