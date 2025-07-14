<?php
/**
 * Check the actual structure of purchase_order_items table
 */

header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', (__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

try {
    // Get purchase_order_items structure
    $stmt = $db->prepare("DESCRIBE purchase_order_items");
    $stmt->execute();
    $poiStructure = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get sample data from purchase_order_items
    $stmt = $db->prepare("SELECT * FROM purchase_order_items LIMIT 3");
    $stmt->execute();
    $sampleData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get purchasable_products structure
    $stmt = $db->prepare("DESCRIBE purchasable_products");
    $stmt->execute();
    $ppStructure = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Test the actual query that's failing
    $testQuery = "
        SELECT 
            poi.id,
            poi.purchasable_product_id as product_id,
            pp.supplier_product_name as product_name,
            pp.supplier_product_code as sku,
            COALESCE(p.barcode, pp.supplier_product_code) as barcode,
            poi.quantity as expected_quantity,
            poi.unit_price
        FROM purchase_order_items poi
        JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
        LEFT JOIN products p ON pp.supplier_product_code = p.sku
        WHERE poi.purchase_order_id = 1
        LIMIT 1
    ";
    
    try {
        $stmt = $db->prepare($testQuery);
        $stmt->execute();
        $testResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $querySuccess = true;
        $queryError = null;
    } catch (Exception $e) {
        $querySuccess = false;
        $queryError = $e->getMessage();
        $testResult = null;
    }
    
    echo json_encode([
        'success' => true,
        'purchase_order_items_structure' => $poiStructure,
        'purchasable_products_structure' => $ppStructure,
        'sample_purchase_order_items' => $sampleData,
        'test_query_success' => $querySuccess,
        'test_query_error' => $queryError,
        'test_query_result' => $testResult
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}