<?php
// File: /api/warehouse/assign_order.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Metodă nepermisă.']);
    exit;
}

// Define Base Path
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2)); // Go up 2 levels from /api/warehouse/
}

// Bootstrap and Config
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// Database Connection
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Eroare configurare bază de date.']);
    exit;
}

$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['order_number'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Numărul comenzii este necesar.']);
    exit;
}

$orderNumber = trim($input['order_number']);

// For now, we'll use a default user ID. In production, get from session/auth
$assignedUserId = 1; // TODO: Get from authenticated user session

try {
    $db->beginTransaction();

    // Check if order exists and is available for assignment
    $checkQuery = "
        SELECT id, status, customer_name 
        FROM orders 
        WHERE order_number = :order_number
    ";
    
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':order_number', $orderNumber, PDO::PARAM_STR);
    $checkStmt->execute();
    
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $db->rollback();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Comanda nu a fost găsită.']);
        exit;
    }

    // Check if order is already completed
    if (in_array($order['status'], ['Completed', 'Shipped', 'Cancelled'])) {
        $db->rollback();
        http_response_code(400);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Comanda nu poate fi asignată. Status: ' . $order['status']
        ]);
        exit;
    }

    // Update order status using actual schema
    $updateQuery = "
        UPDATE orders 
        SET 
            status = CASE 
                WHEN status = 'pending' THEN 'processing'
                ELSE status 
            END,
            assigned_to = :assigned_to,
            notes = CONCAT(COALESCE(notes, ''), ' [Asignat pentru picking la ', NOW(), ']'),
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :order_id
    ";
    
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindParam(':assigned_to', $assignedUserId, PDO::PARAM_INT);
    $updateStmt->bindParam(':order_id', $order['id'], PDO::PARAM_INT);
    $updateStmt->execute();

    $db->commit();

    // Return success response
    echo json_encode([
        'status' => 'success',
        'message' => 'Comanda a fost asignată cu succes.',
        'data' => [
            'order_id' => $order['id'],
            'order_number' => $orderNumber,
            'customer_name' => $order['customer_name'],
            'status' => 'Processing',
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (PDOException $e) {
    $db->rollback();
    error_log("Database error in assign_order.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Eroare bază de date.',
        'debug' => $e->getMessage() // Remove in production
    ]);
} catch (Exception $e) {
    $db->rollback();
    error_log("General error in assign_order.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Eroare server.',
        'debug' => $e->getMessage() // Remove in production
    ]);
}
?>