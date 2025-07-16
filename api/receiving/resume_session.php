<?php
/**
 * API: Resume Receiving Session - SIMPLE VERSION
 * File: api/receiving/resume_session.php
 * 
 * Simple session validation for resuming (if this endpoint is needed)
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
    
    $sessionId = (int)($input['session_id'] ?? 0);
    
    if (!$sessionId) {
        throw new Exception('Session ID is required');
    }
    
    // Validate session exists and user can access it
    $stmt = $db->prepare("
        SELECT rs.*, po.order_number as po_number, s.supplier_name as supplier_name
        FROM receiving_sessions rs
        JOIN purchase_orders po ON rs.purchase_order_id = po.id
        JOIN sellers s ON rs.supplier_id = s.id
        WHERE rs.id = :session_id 
        AND rs.status = 'in_progress'
        AND (rs.received_by = :user_id OR :user_id IN (
            SELECT id FROM users WHERE role IN ('admin', 'manager')
        ))
    ");
    $stmt->execute([
        ':session_id' => $sessionId,
        ':user_id' => $_SESSION['user_id']
    ]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        throw new Exception('Receiving session not found or not accessible');
    }
    
    // If different user is resuming, update the received_by field
    if ($session['received_by'] != $_SESSION['user_id']) {
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
    }
    
    // Return basic session info - the frontend will call get_expected_items.php for details
    echo json_encode([
        'success' => true,
        'message' => 'Session resumed successfully',
        'session_id' => $sessionId
    ]);
    
} catch (Exception $e) {
    error_log("Resume receiving session error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}