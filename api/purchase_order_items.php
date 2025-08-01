<?php
// File: api/purchase_order_items.php - API endpoint for fetching purchase order items
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Database connection
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection error']);
    exit;
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include PurchaseOrder model
require_once BASE_PATH . '/models/PurchaseOrder.php';
$purchaseOrderModel = new PurchaseOrder($db);

// Get order ID from request
$orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order ID']);
    exit;
}

try {
    // Get order items with delivery and invoice quantities
    $query = "SELECT 
        poi.id,
        poi.quantity as ordered_quantity,
        poi.unit_price,
        poi.total_price,
        poi.quantity_delivered,
        poi.quantity_invoiced,
        poi.notes,
        pp.supplier_product_name as product_name,
        pp.supplier_product_code,
        pp.unit_measure as unit
    FROM purchase_order_items poi
    LEFT JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
    WHERE poi.purchase_order_id = :order_id
    ORDER BY poi.id ASC";
    
    $stmt = $db->prepare($query);
    $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($items)) {
        echo json_encode([
            'items' => [],
            'message' => 'No items found for this order'
        ]);
        exit;
    }
    
    // Process items for frontend consumption
    $processedItems = [];
    foreach ($items as $item) {
        $processedItems[] = [
            'id' => (int)$item['id'],
            'product_name' => $item['product_name'] ?? 'Unknown Product',
            'product_code' => $item['supplier_product_code'] ?? '',
            'ordered_quantity' => (float)$item['ordered_quantity'],
            'delivered_quantity' => (float)($item['quantity_delivered'] ?? 0),
            'invoiced_quantity' => (float)($item['quantity_invoiced'] ?? 0),
            'unit_price' => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price'],
            'unit' => $item['unit'] ?? 'bucata',
            'notes' => $item['notes'] ?? '',
            // Calculate remaining quantities
            'remaining_to_deliver' => (float)$item['ordered_quantity'] - (float)($item['quantity_delivered'] ?? 0),
            'remaining_to_invoice' => (float)$item['ordered_quantity'] - (float)($item['quantity_invoiced'] ?? 0)
        ];
    }
    
    echo json_encode([
        'success' => true,
        'items' => $processedItems,
        'total_items' => count($processedItems)
    ]);

} catch (Exception $e) {
    error_log("Error fetching purchase order items: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
?>