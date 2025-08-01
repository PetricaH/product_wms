<?php
/**
 * Debug script to check if invoice columns exist
 */

header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Must be admin to run this debug script');
}

$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

try {
    // Check purchase_orders table structure
    echo "=== Purchase Orders Table Structure ===\n";
    $stmt = $db->prepare("DESCRIBE purchase_orders");
    $stmt->execute();
    $structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hasInvoiced = false;
    $hasInvoiceFilePath = false;
    $hasInvoicedAt = false;
    
    foreach ($structure as $column) {
        echo $column['Field'] . " - " . $column['Type'] . "\n";
        
        if ($column['Field'] === 'invoiced') $hasInvoiced = true;
        if ($column['Field'] === 'invoice_file_path') $hasInvoiceFilePath = true;
        if ($column['Field'] === 'invoiced_at') $hasInvoicedAt = true;
    }
    
    echo "\n=== Invoice Columns Check ===\n";
    echo "invoiced column exists: " . ($hasInvoiced ? "YES" : "NO") . "\n";
    echo "invoice_file_path column exists: " . ($hasInvoiceFilePath ? "YES" : "NO") . "\n";
    echo "invoiced_at column exists: " . ($hasInvoicedAt ? "YES" : "NO") . "\n";
    
    // Check current status enum values
    echo "\n=== Status ENUM Values ===\n";
    $stmt = $db->prepare("SHOW COLUMNS FROM purchase_orders LIKE 'status'");
    $stmt->execute();
    $statusColumn = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Status ENUM: " . $statusColumn['Type'] . "\n";
    
    // Test a simple query
    echo "\n=== Test Simple Query ===\n";
    $stmt = $db->prepare("SELECT id, order_number, status FROM purchase_orders LIMIT 3");
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($orders) . " orders:\n";
    foreach ($orders as $order) {
        echo "- Order {$order['id']}: {$order['order_number']} (Status: {$order['status']})\n";
    }
    
    // Test API endpoint
    echo "\n=== Test API Endpoint ===\n";
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://localhost/product_wms/api/receiving/purchase_order_summary.php?limit=1');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "HTTP Status: $httpCode\n";
        echo "Response: " . substr($response, 0, 200) . "...\n";
        
        $data = json_decode($response, true);
        if ($data) {
            echo "JSON Valid: YES\n";
            echo "Success: " . ($data['success'] ? 'YES' : 'NO') . "\n";
            if (!$data['success']) {
                echo "Error: " . ($data['message'] ?? 'No message') . "\n";
            }
        } else {
            echo "JSON Valid: NO\n";
        }
    } catch (Exception $e) {
        echo "API Test Error: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}