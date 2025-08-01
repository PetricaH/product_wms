<?php
/**
 * Debug script to check order ID 7 status
 * Run this to see what's wrong with the order
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';

$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

try {
    // Check if order 7 exists and what its status is
    $stmt = $db->prepare("
        SELECT po.id, po.order_number, po.status, po.invoiced, s.supplier_name 
        FROM purchase_orders po 
        LEFT JOIN sellers s ON po.seller_id = s.id 
        WHERE po.id = 7
    ");
    $stmt->execute();
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo "❌ Order ID 7 does not exist in the database\n";
    } else {
        echo "✅ Order ID 7 found:\n";
        echo "- Order Number: " . $order['order_number'] . "\n";
        echo "- Status: " . $order['status'] . "\n";
        echo "- Already Invoiced: " . ($order['invoiced'] ? 'YES' : 'NO') . "\n";
        echo "- Supplier: " . $order['supplier_name'] . "\n";
        
        // Check if status is in allowed list
        $allowedStatuses = ['sent', 'confirmed', 'delivered', 'partial_delivery', 'completed'];
        if (in_array($order['status'], $allowedStatuses)) {
            echo "✅ Status is in allowed list\n";
        } else {
            echo "❌ Status '" . $order['status'] . "' is NOT in allowed list\n";
            echo "   Allowed statuses: " . implode(', ', $allowedStatuses) . "\n";
        }
        
        if ($order['invoiced']) {
            echo "❌ Order is already invoiced\n";
        } else {
            echo "✅ Order is not yet invoiced\n";
        }
    }
    
    // Check all purchase orders and their statuses
    echo "\n=== All Purchase Orders Status Overview ===\n";
    $stmt = $db->prepare("SELECT id, order_number, status, invoiced FROM purchase_orders ORDER BY id");
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($orders as $order) {
        $invoicedText = $order['invoiced'] ? '(Invoiced)' : '';
        echo "ID {$order['id']}: {$order['order_number']} - {$order['status']} {$invoicedText}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
}