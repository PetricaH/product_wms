<?php
/**
 * API: Resume Receiving Session
 * File: api/receiving/resume_session.php
 * 
 * Allows resuming an existing receiving session
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
    
    $sessionId = (int)($input['session_id'] ?? 0);
    
    if (!$sessionId) {
        throw new Exception('Session ID is required');
    }
    
    // Check if user can resume this session
    $stmt = $db->prepare("
        SELECT rs.*, po.order_number as po_number, s.supplier_name as supplier_name,
               u.username as received_by_name, u.role as user_role
        FROM receiving_sessions rs
        JOIN purchase_orders po ON rs.purchase_order_id = po.id
        JOIN sellers s ON rs.supplier_id = s.id
        JOIN users u ON rs.received_by = u.id
        WHERE rs.id = :session_id 
        AND rs.status = 'in_progress'
    ");
    $stmt->execute([':session_id' => $sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        throw new Exception('Receiving session not found or not active');
    }
    
    // Check permissions - user must be the original receiver or admin/manager
    $stmt = $db->prepare("SELECT role FROM users WHERE id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $currentUserRole = $stmt->fetchColumn();
    
    $canResume = (
        $session['received_by'] == $_SESSION['user_id'] || 
        in_array($currentUserRole, ['admin', 'manager'])
    );
    
    if (!$canResume) {
        throw new Exception('You do not have permission to resume this receiving session');
    }
    
    // If different user is resuming, update the received_by field
    if ($session['received_by'] != $_SESSION['user_id'] && in_array($currentUserRole, ['admin', 'manager'])) {
        $stmt = $db->prepare("
            UPDATE receiving_sessions SET 
                received_by = :new_user_id,
                updated_at = NOW()
            WHERE id = :session_id
        ");
        $stmt->execute([
            ':new_user_id' => $_SESSION['user_id'],
            ':session_id' => $sessionId
        ]);
        
        // Update session data for response
        $session['received_by'] = $_SESSION['user_id'];
    }
    
    // Return session info for resuming
    echo json_encode([
        'success' => true,
        'session' => [
            'id' => (int)$session['id'],
            'session_number' => $session['session_number'],
            'supplier_document_number' => $session['supplier_document_number'],
            'supplier_document_type' => $session['supplier_document_type'],
            'supplier_document_date' => $session['supplier_document_date'],
            'purchase_order_id' => (int)$session['purchase_order_id'],
            'po_number' => $session['po_number'],
            'supplier_name' => $session['supplier_name'],
            'supplier_id' => (int)$session['supplier_id'],
            'status' => $session['status'],
            'total_items_expected' => (int)$session['total_items_expected'],
            'total_items_received' => (int)$session['total_items_received'],
            'created_at' => $session['created_at'],
            'resumed_at' => date('Y-m-d H:i:s')
        ],
        'message' => 'Session resumed successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Resume receiving session error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}