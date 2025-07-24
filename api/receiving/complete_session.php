<?php
/**
 * API: Complete Receiving Session
 * File: api/receiving/complete_session.php
 * 
 * Completes a receiving session and updates purchase order status
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

// CSRF check with fallback for non-Apache servers
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (function_exists('apache_request_headers')) {
    $headers = array_change_key_case(apache_request_headers(), CASE_UPPER);
    $csrfToken = $csrfToken ?: ($headers['X-CSRF-TOKEN'] ?? '');
}

if (!validateCsrfToken($csrfToken)) {
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
    // Get input data (support JSON and form-data)
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        $input = $_POST;
    }

    $sessionId = (int)($input['session_id'] ?? 0);
    $completionNotes = trim($input['completion_notes'] ?? '');
    $source = $input['source'] ?? 'sellers';
    
    if (!$sessionId) {
        throw new Exception('Session ID is required');
    }
    
    // Validate session exists and is active
    $stmt = $db->prepare("
        SELECT rs.*, po.id as purchase_order_id, po.order_number as po_number,
               s.supplier_name as supplier_name
        FROM receiving_sessions rs
        JOIN purchase_orders po ON rs.purchase_order_id = po.id
        JOIN sellers s ON rs.supplier_id = s.id
        WHERE rs.id = :session_id 
        AND rs.status = 'in_progress'
        AND rs.received_by = :user_id
    ");
    $stmt->execute([
        ':session_id' => $sessionId,
        ':user_id' => $_SESSION['user_id']
    ]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        throw new Exception('Receiving session not found or not accessible');
    }
    
    // Get receiving statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT poi.id) as total_expected_items,
            COUNT(DISTINCT ri.purchase_order_item_id) as total_received_items,
            COUNT(DISTINCT rd.id) as total_discrepancies,
            SUM(CASE WHEN ri.received_quantity > 0 THEN ri.received_quantity ELSE 0 END) as total_received_quantity,
            SUM(poi.quantity) as total_expected_quantity
        FROM purchase_order_items poi
        LEFT JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
        LEFT JOIN receiving_items ri ON poi.id = ri.purchase_order_item_id
            AND ri.receiving_session_id = :session_id
        LEFT JOIN receiving_discrepancies rd ON rd.receiving_session_id = :session_id
            AND rd.product_id = pp.internal_product_id
        WHERE poi.purchase_order_id = :purchase_order_id
    ");
    $stmt->execute([
        ':session_id' => $sessionId,
        ':purchase_order_id' => $session['purchase_order_id']
    ]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($stats['total_received_items'] == 0) {
        throw new Exception('Cannot complete receiving session without any received items');
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Complete the receiving session
    $stmt = $db->prepare("
        UPDATE receiving_sessions SET 
            status = 'completed',
            total_items_received = :total_received_items,
            discrepancy_notes = :completion_notes,
            completed_at = NOW(),
            updated_at = NOW()
        WHERE id = :session_id
    ");
    $stmt->execute([
        ':total_received_items' => $stats['total_received_items'],
        ':completion_notes' => $completionNotes,
        ':session_id' => $sessionId
    ]);
    
    // Determine purchase order status based on receiving completion
    $receivingPercentage = ($stats['total_received_items'] / $stats['total_expected_items']) * 100;
    
    $newPOStatus = 'delivered'; // Default to delivered
    
    if ($receivingPercentage < 100) {
        $newPOStatus = 'partial_delivery';
    }
    
    // Update purchase order status
    $stmt = $db->prepare("
        UPDATE purchase_orders SET 
            status = :new_status,
            actual_delivery_date = CURDATE(),
            updated_at = NOW()
        WHERE id = :purchase_order_id
    ");
    $stmt->execute([
        ':new_status' => $newPOStatus,
        ':purchase_order_id' => $session['purchase_order_id']
    ]);
    
    // Get final session details for response
    $stmt = $db->prepare("
        SELECT 
            rs.*,
            u.username as received_by_name,
            COUNT(DISTINCT ri.id) as items_received,
            COUNT(DISTINCT rd.id) as discrepancies_count
        FROM receiving_sessions rs
        JOIN users u ON rs.received_by = u.id
        LEFT JOIN receiving_items ri ON rs.id = ri.receiving_session_id
        LEFT JOIN receiving_discrepancies rd ON rs.id = rd.receiving_session_id
        WHERE rs.id = :session_id
        GROUP BY rs.id
    ");
    $stmt->execute([':session_id' => $sessionId]);
    $completedSession = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get item details for summary
    $stmt = $db->prepare("
        SELECT 
            ri.*,
            pp.supplier_product_name as product_name,
            pp.supplier_product_code as sku,
            l.location_code
        FROM receiving_items ri
        JOIN purchase_order_items poi ON ri.purchase_order_item_id = poi.id
        JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
        LEFT JOIN locations l ON ri.location_id = l.id
        WHERE ri.receiving_session_id = :session_id
        ORDER BY ri.created_at ASC
    ");
    $stmt->execute([':session_id' => $sessionId]);
    $receivedItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Commit transaction
    $db->commit();

    $savedPhotos = [];
    if (!empty($_FILES['photos']['name'][0])) {
        $baseDir = BASE_PATH . '/storage/receiving/' . ($source === 'factory' ? 'factory' : 'sellers') . '/';
        if (!file_exists($baseDir)) {
            mkdir($baseDir, 0755, true);
        }
        foreach ($_FILES['photos']['tmp_name'] as $idx => $tmp) {
            if ($_FILES['photos']['error'][$idx] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['photos']['name'][$idx], PATHINFO_EXTENSION);
                $filename = 'session_' . $sessionId . '_' . time() . "_{$idx}." . $ext;
                if (move_uploaded_file($tmp, $baseDir . $filename)) {
                    $savedPhotos[] = 'receiving/' . ($source === 'factory' ? 'factory' : 'sellers') . '/' . $filename;
                }
            }
        }
    }

    if ($completionNotes) {
        $dir = BASE_PATH . '/storage/receiving/' . ($source === 'factory' ? 'factory' : 'sellers') . '/';
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . 'session_' . $sessionId . '_desc.txt', $completionNotes);
    }
    
    // Format response
    echo json_encode([
        'success' => true,
        'message' => 'Receiving session completed successfully',
        'summary' => [
            'session_number' => $completedSession['session_number'],
            'supplier_document_number' => $completedSession['supplier_document_number'],
            'po_number' => $session['po_number'],
            'supplier_name' => $session['supplier_name'],
            'items_expected' => (int)$stats['total_expected_items'],
            'items_received' => (int)$stats['total_received_items'],
            'discrepancies' => (int)$stats['total_discrepancies'],
            'completion_percentage' => round($receivingPercentage, 2),
            'completed_at' => $completedSession['completed_at'],
            'received_by' => $completedSession['received_by_name'],
            'purchase_order_status' => $newPOStatus,
            'total_expected_quantity' => (float)$stats['total_expected_quantity'],
            'total_received_quantity' => (float)$stats['total_received_quantity']
        ],
        'saved_photos' => $savedPhotos,
        'received_items' => array_map(function($item) {
            return [
                'product_name' => $item['product_name'],
                'sku' => $item['sku'],
                'expected_quantity' => (float)$item['expected_quantity'],
                'received_quantity' => (float)$item['received_quantity'],
                'location_code' => $item['location_code'],
                'condition_status' => $item['condition_status'],
                'batch_number' => $item['batch_number'],
                'notes' => $item['notes']
            ];
        }, $receivedItems)
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("Complete receiving session error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}