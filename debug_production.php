<?php
/**
 * QC Debug & Test Script
 * File: debug_qc_system.php
 * 
 * Run this script to debug and verify QC system setup
 */

header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';

// Must be admin to run this debug script
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Must be admin to run this debug script');
}

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$debug = [];

try {
    // 1. Check if QC columns exist in receiving_items
    $debug['receiving_items_structure'] = [];
    $stmt = $db->prepare("DESCRIBE receiving_items");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $qcColumns = ['approval_status', 'approved_by', 'approved_at', 'rejection_reason', 'supervisor_notes'];
    foreach ($qcColumns as $col) {
        $exists = false;
        foreach ($columns as $column) {
            if ($column['Field'] === $col) {
                $exists = true;
                break;
            }
        }
        $debug['receiving_items_structure'][$col] = $exists ? 'EXISTS' : 'MISSING';
    }
    
    // 2. Check if qc_decisions table exists
    try {
        $stmt = $db->prepare("DESCRIBE qc_decisions");
        $stmt->execute();
        $debug['qc_decisions_table'] = 'EXISTS';
    } catch (Exception $e) {
        $debug['qc_decisions_table'] = 'MISSING - ' . $e->getMessage();
    }
    
    // 3. Check purchase_orders table structure
    $debug['purchase_orders_structure'] = [];
    $stmt = $db->prepare("DESCRIBE purchase_orders");
    $stmt->execute();
    $poColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $expectedCols = ['order_number', 'seller_id'];
    foreach ($expectedCols as $col) {
        $exists = false;
        foreach ($poColumns as $column) {
            if ($column['Field'] === $col) {
                $exists = true;
                break;
            }
        }
        $debug['purchase_orders_structure'][$col] = $exists ? 'EXISTS' : 'MISSING';
    }
    
    // 4. Check if default QC locations exist
    $stmt = $db->prepare("
        SELECT location_code, type, status 
        FROM locations 
        WHERE type IN ('qc_hold', 'quarantine', 'pending_approval')
    ");
    $stmt->execute();
    $qcLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $debug['qc_locations'] = $qcLocations;
    
    // 5. Check for pending QC items
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM receiving_items 
        WHERE approval_status = 'pending'
    ");
    $stmt->execute();
    $pendingCount = $stmt->fetchColumn();
    $debug['pending_qc_items'] = $pendingCount;
    
    // 6. Test the corrected query
    $testQuery = "
        SELECT 
            ri.id,
            ri.approval_status,
            ri.received_quantity,
            ri.expected_quantity,
            
            -- Product information
            pp.supplier_product_name as product_name,
            pp.supplier_product_code as product_code,
            
            -- Purchase order information
            po.order_number,
            s.supplier_name,
            
            -- Location information
            l.location_code,
            l.type as location_type
            
        FROM receiving_items ri
        LEFT JOIN purchase_order_items poi ON ri.purchase_order_item_id = poi.id
        LEFT JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
        LEFT JOIN receiving_sessions rs ON ri.receiving_session_id = rs.id
        LEFT JOIN purchase_orders po ON rs.purchase_order_id = po.id
        LEFT JOIN sellers s ON po.seller_id = s.id
        LEFT JOIN locations l ON ri.location_id = l.id
        WHERE ri.approval_status = 'pending'
        LIMIT 3
    ";
    
    try {
        $stmt = $db->prepare($testQuery);
        $stmt->execute();
        $testResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $debug['test_query'] = [
            'status' => 'SUCCESS',
            'results_count' => count($testResults),
            'sample_data' => $testResults
        ];
    } catch (Exception $e) {
        $debug['test_query'] = [
            'status' => 'ERROR',
            'error' => $e->getMessage()
        ];
    }
    
    // 7. Check if QC helper functions file exists
    $helperFile = BASE_PATH . '/includes/qc_helpers.php';
    $debug['qc_helpers_file'] = file_exists($helperFile) ? 'EXISTS' : 'MISSING';
    
    // 8. Test helper function (if file exists)
    if (file_exists($helperFile)) {
        try {
            require_once $helperFile;
            if (function_exists('getDefaultLocationId')) {
                $qcHoldId = getDefaultLocationId($db, 'qc_hold');
                $debug['qc_hold_location_id'] = $qcHoldId ?: 'NOT_FOUND';
            } else {
                $debug['qc_hold_location_id'] = 'FUNCTION_NOT_FOUND';
            }
        } catch (Exception $e) {
            $debug['qc_hold_location_id'] = 'ERROR: ' . $e->getMessage();
        }
    }
    
    // 9. Summary
    $debug['summary'] = [
        'database_ready' => $debug['qc_decisions_table'] === 'EXISTS' && 
                           $debug['receiving_items_structure']['approval_status'] === 'EXISTS',
        'locations_ready' => count($debug['qc_locations']) >= 3,
        'query_working' => $debug['test_query']['status'] === 'SUCCESS',
        'helpers_ready' => $debug['qc_helpers_file'] === 'EXISTS'
    ];
    
    $debug['overall_status'] = $debug['summary']['database_ready'] && 
                              $debug['summary']['locations_ready'] && 
                              $debug['summary']['query_working'] && 
                              $debug['summary']['helpers_ready'] ? 'READY' : 'NEEDS_SETUP';
    
} catch (Exception $e) {
    $debug['error'] = $e->getMessage();
    $debug['overall_status'] = 'ERROR';
}

echo json_encode($debug, JSON_PRETTY_PRINT);
?>