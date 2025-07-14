<?php
/**
 * API: Start Receiving Session
 * File: api/receiving/start_session.php
 * 
 * Creates a new receiving session for a purchase order
 * FIXED: Consistent supplier_name usage and proper session creation
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

// Session check
session_start();
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
    
    $purchaseOrderId = (int)($input['purchase_order_id'] ?? 0);
    $supplierDocNumber = trim($input['supplier_doc_number'] ?? '');
    $docType = trim($input['doc_type'] ?? '');
    $docDate = trim($input['doc_date'] ?? '');
    
    if (!$purchaseOrderId || !$supplierDocNumber || !$docType) {
        throw new Exception('Purchase order ID, supplier document number and type are required');
    }
    
    // Validate purchase order exists
    // FIXED: Use s.supplier_name instead of s.name and proper JOIN
    $stmt = $db->prepare("
        SELECT po.*, s.supplier_name as supplier_name, s.id as supplier_id
        FROM purchase_orders po
        JOIN sellers s ON po.seller_id = s.id
        WHERE po.id = :po_id
    ");
    $stmt->execute([':po_id' => $purchaseOrderId]);
    $purchaseOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$purchaseOrder) {
        throw new Exception('Purchase order not found');
    }
    
    // Check if there's already an active session for this PO
    $stmt = $db->prepare("
        SELECT rs.*, u.username as received_by_name
        FROM receiving_sessions rs
        JOIN users u ON rs.received_by = u.id
        WHERE rs.purchase_order_id = :po_id 
        AND rs.status = 'in_progress'
    ");
    $stmt->execute([':po_id' => $purchaseOrderId]);
    $existingSession = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingSession) {
        // Return existing session info for resume option
        echo json_encode([
            'success' => false,
            'error_type' => 'existing_session',
            'message' => 'There is already an active receiving session for this purchase order',
            'existing_session' => [
                'id' => $existingSession['id'],
                'session_number' => $existingSession['session_number'],
                'supplier_document_number' => $existingSession['supplier_document_number'],
                'created_at' => $existingSession['created_at'],
                'received_by_name' => $existingSession['received_by_name'],
                'can_resume' => ($existingSession['received_by'] == $_SESSION['user_id'] || 
                               in_array($_SESSION['role'] ?? '', ['admin', 'manager']))
            ]
        ]);
        exit;
    }
    
    // Generate unique session number
    $prefix = 'REC-' . date('Y') . '-';
    
    // Get the last session number for this year
    $stmt = $db->prepare("
        SELECT session_number 
        FROM receiving_sessions 
        WHERE session_number LIKE :prefix 
        ORDER BY session_number DESC 
        LIMIT 1
    ");
    $stmt->execute([':prefix' => $prefix . '%']);
    $lastNumber = $stmt->fetchColumn();
    
    if ($lastNumber) {
        // Extract the number part and increment
        $lastNum = (int)substr($lastNumber, strlen($prefix));
        $nextNum = $lastNum + 1;
    } else {
        $nextNum = 1;
    }
    
    $sessionNumber = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
    
    // Start transaction
    $db->beginTransaction();
    
    // Create receiving session
    $stmt = $db->prepare("
        INSERT INTO receiving_sessions (
            session_number, supplier_document_number, supplier_document_type, 
            supplier_document_date, purchase_order_id, supplier_id, received_by,
            status, created_at
        ) VALUES (
            :session_number, :supplier_doc_number, :doc_type, :doc_date,
            :purchase_order_id, :supplier_id, :received_by, 'in_progress', NOW()
        )
    ");
    
    $stmt->execute([
        ':session_number' => $sessionNumber,
        ':supplier_doc_number' => $supplierDocNumber,
        ':doc_type' => $docType,
        ':doc_date' => $docDate ?: null,
        ':purchase_order_id' => $purchaseOrderId,
        ':supplier_id' => $purchaseOrder['supplier_id'],
        ':received_by' => $_SESSION['user_id']
    ]);
    
    $sessionId = $db->lastInsertId();
    
    // Count expected items
    $stmt = $db->prepare("
        SELECT COUNT(*) as item_count 
        FROM purchase_order_items 
        WHERE purchase_order_id = :po_id
    ");
    $stmt->execute([':po_id' => $purchaseOrderId]);
    $itemCount = $stmt->fetchColumn();
    
    // Update session with expected items count
    $stmt = $db->prepare("
        UPDATE receiving_sessions 
        SET total_items_expected = :item_count 
        WHERE id = :session_id
    ");
    $stmt->execute([
        ':item_count' => $itemCount,
        ':session_id' => $sessionId
    ]);
    
    // Commit transaction
    $db->commit();
    
    // Return session info
    echo json_encode([
        'success' => true,
        'session' => [
            'id' => $sessionId,
            'session_number' => $sessionNumber,
            'supplier_document_number' => $supplierDocNumber,
            'supplier_document_type' => $docType,
            'supplier_document_date' => $docDate,
            'purchase_order_id' => $purchaseOrderId,
            'po_number' => $purchaseOrder['order_number'],
            'supplier_name' => $purchaseOrder['supplier_name'],
            'supplier_id' => $purchaseOrder['supplier_id'],
            'status' => 'in_progress',
            'total_items_expected' => $itemCount,
            'total_items_received' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("Start receiving session error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}