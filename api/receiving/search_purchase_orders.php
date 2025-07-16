<?php
/**
 * API: Search Purchase Orders for Receiving
 * File: api/receiving/search_purchase_orders.php
 * 
 * Searches for purchase orders based on supplier document information
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF check
$headers = apache_request_headers();
$csrfToken = $headers['X-CSRF-Token'] ?? '';
if (!$csrfToken || $csrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Database connection
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

try {
    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    $supplierDocNumber = trim($input['supplier_doc_number'] ?? '');
    $docType = trim($input['doc_type'] ?? '');
    $docDate = trim($input['doc_date'] ?? '');
    
    if (empty($supplierDocNumber) || empty($docType)) {
        throw new Exception('Supplier document number and type are required');
    }
    
    // Search strategy:
    // 1. First try to find by supplier invoice number (if exists in supplier_invoices table)
    // 2. Then search by supplier name matching and date range
    // 3. Finally search by partial document number matching
    
    $purchaseOrders = [];
    
    // Strategy 1: Search by supplier invoice
    if ($docType === 'invoice') {
        $stmt = $db->prepare("
            SELECT DISTINCT po.*, s.supplier_name as supplier_name, 
                   COUNT(poi.id) as items_count
            FROM purchase_orders po
            JOIN sellers s ON po.seller_id = s.id
            LEFT JOIN purchase_order_items poi ON po.id = poi.purchase_order_id
            LEFT JOIN supplier_invoices si ON po.id = si.purchase_order_id
            WHERE si.invoice_number = :doc_number
            GROUP BY po.id
            ORDER BY po.created_at DESC
        ");
        $stmt->execute([':doc_number' => $supplierDocNumber]);
        $purchaseOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Strategy 2: Search by supplier name and date range (if no exact match)
    if (empty($purchaseOrders)) {
        // Extract potential supplier name from document number
        $supplierKeywords = [];
        
        // Try to extract supplier name from document pattern
        if (preg_match('/([A-Za-z]+)/', $supplierDocNumber, $matches)) {
            $supplierKeywords[] = $matches[1];
        }
        
        // Search by date range (±30 days)
        $searchDate = $docDate ?: date('Y-m-d');
        $startDate = date('Y-m-d', strtotime($searchDate . ' -30 days'));
        $endDate = date('Y-m-d', strtotime($searchDate . ' +30 days'));
        
        $stmt = $db->prepare("
            SELECT DISTINCT po.*, s.supplier_name as supplier_name, 
                   COUNT(poi.id) as items_count
            FROM purchase_orders po
            JOIN sellers s ON po.seller_id = s.id
            LEFT JOIN purchase_order_items poi ON po.id = poi.purchase_order_id
            WHERE po.status IN ('sent', 'confirmed', 'partial_delivery', 'delivered')
            AND po.created_at BETWEEN :start_date AND :end_date
            AND (po.expected_delivery_date IS NULL OR po.expected_delivery_date BETWEEN :start_date AND :end_date)
            GROUP BY po.id
            ORDER BY po.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        $purchaseOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Strategy 3: Broad search if still no results
    if (empty($purchaseOrders)) {
        $stmt = $db->prepare("
            SELECT DISTINCT po.*, s.supplier_name as supplier_name, 
                   COUNT(poi.id) as items_count
            FROM purchase_orders po
            JOIN sellers s ON po.seller_id = s.id
            LEFT JOIN purchase_order_items poi ON po.id = poi.purchase_order_id
            WHERE po.status IN ('sent', 'confirmed', 'partial_delivery', 'delivered')
            AND po.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            GROUP BY po.id
            ORDER BY po.created_at DESC
            LIMIT 20
        ");
        $stmt->execute();
        $purchaseOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Format the response
    $formattedPOs = array_map(function($po) {
        return [
            'id' => (int)$po['id'],
            'order_number' => $po['order_number'],
            'supplier_name' => $po['supplier_name'],
            'total_amount' => number_format($po['total_amount'], 2),
            'currency' => $po['currency'],
            'status' => $po['status'],
            'expected_delivery_date' => $po['expected_delivery_date'],
            'created_at' => $po['created_at'],
            'items_count' => (int)$po['items_count']
        ];
    }, $purchaseOrders);
    
    echo json_encode([
        'success' => true,
        'purchase_orders' => $formattedPOs,
        'search_info' => [
            'document_number' => $supplierDocNumber,
            'document_type' => $docType,
            'document_date' => $docDate,
            'found_count' => count($formattedPOs)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Search purchase orders error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}