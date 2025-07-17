<?php
/**
 * Debug script to test the exact queries from purchase_order_details.php
 * This will help us find the exact SQL error
 */

header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';

// Start session for admin check
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Must be admin to run this debug script');
}

$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

$orderId = 1; // Test with order ID 1

try {
    $results = [];
    
    // Test 1: Basic purchase order query
    echo "Testing basic purchase order query...\n";
    try {
        $stmt = $db->prepare("
            SELECT po.*, s.supplier_name, u.username as created_by_name
            FROM purchase_orders po
            LEFT JOIN sellers s ON po.seller_id = s.id
            LEFT JOIN users u ON po.created_by = u.id
            WHERE po.id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $results['basic_order_query'] = [
            'success' => true,
            'found' => !empty($order),
            'data' => $order
        ];
        echo "✅ Basic order query: SUCCESS\n";
    } catch (Exception $e) {
        $results['basic_order_query'] = [
            'success' => false,
            'error' => $e->getMessage()
        ];
        echo "❌ Basic order query: FAILED - " . $e->getMessage() . "\n";
    }
    
    // Test 2: Receiving sessions query
    echo "\nTesting receiving sessions query...\n";
    try {
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
            WHERE rs.purchase_order_id = ?
            GROUP BY rs.id
            ORDER BY rs.created_at DESC
        ");
        $stmt->execute([$orderId]);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results['receiving_sessions_query'] = [
            'success' => true,
            'count' => count($sessions),
            'data' => $sessions
        ];
        echo "✅ Receiving sessions query: SUCCESS\n";
    } catch (Exception $e) {
        $results['receiving_sessions_query'] = [
            'success' => false,
            'error' => $e->getMessage()
        ];
        echo "❌ Receiving sessions query: FAILED - " . $e->getMessage() . "\n";
    }
    
    // Test 3: Detailed item breakdown query
    echo "\nTesting detailed item breakdown query...\n";
    try {
        $stmt = $db->prepare("
            SELECT 
                poi.id as item_id,
                pp.supplier_product_name as product_name,
                pp.supplier_product_code as sku,
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
            LEFT JOIN receiving_items ri ON poi.id = ri.purchase_order_item_id
            LEFT JOIN receiving_sessions rs ON ri.receiving_session_id = rs.id
            LEFT JOIN locations l ON ri.location_id = l.id
            LEFT JOIN users u ON rs.received_by = u.id
            WHERE poi.purchase_order_id = ?
            ORDER BY poi.id, ri.created_at DESC
        ");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results['detailed_items_query'] = [
            'success' => true,
            'count' => count($items),
            'data' => $items
        ];
        echo "✅ Detailed items query: SUCCESS\n";
    } catch (Exception $e) {
        $results['detailed_items_query'] = [
            'success' => false,
            'error' => $e->getMessage()
        ];
        echo "❌ Detailed items query: FAILED - " . $e->getMessage() . "\n";
    }
    
    // Test 4: Discrepancies query
    echo "\nTesting discrepancies query...\n";
    try {
        $stmt = $db->prepare("
            SELECT 
                rd.*,
                pp.supplier_product_name as product_name,
                pp.supplier_product_code as sku,
                rs.session_number,
                rs.supplier_document_number,
                u1.username as received_by_name,
                u2.username as resolved_by_name
            FROM receiving_discrepancies rd
            JOIN receiving_sessions rs ON rd.receiving_session_id = rs.id
            JOIN products p ON rd.product_id = p.product_id
            LEFT JOIN purchasable_products pp ON p.sku = pp.supplier_product_code
            LEFT JOIN users u1 ON rs.received_by = u1.id
            LEFT JOIN users u2 ON rd.resolved_by = u2.id
            WHERE rs.purchase_order_id = ?
            ORDER BY rd.created_at DESC
        ");
        $stmt->execute([$orderId]);
        $discrepancies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results['discrepancies_query'] = [
            'success' => true,
            'count' => count($discrepancies),
            'data' => $discrepancies
        ];
        echo "✅ Discrepancies query: SUCCESS\n";
    } catch (Exception $e) {
        $results['discrepancies_query'] = [
            'success' => false,
            'error' => $e->getMessage()
        ];
        echo "❌ Discrepancies query: FAILED - " . $e->getMessage() . "\n";
    }
    
    // Test 5: Check if required tables exist
    echo "\nChecking required tables...\n";
    $requiredTables = [
        'purchase_orders',
        'purchase_order_items', 
        'purchasable_products',
        'sellers',
        'users',
        'receiving_sessions',
        'receiving_items',
        'receiving_discrepancies',
        'products',
        'locations'
    ];
    
    $tableStatus = [];
    foreach ($requiredTables as $table) {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM $table LIMIT 1");
            $stmt->execute();
            $tableStatus[$table] = 'EXISTS';
        } catch (Exception $e) {
            $tableStatus[$table] = 'MISSING: ' . $e->getMessage();
        }
    }
    
    $results['table_status'] = $tableStatus;
    
    echo "\n" . json_encode($results, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}