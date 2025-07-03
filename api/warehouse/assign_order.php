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
    define('BASE_PATH', dirname(__DIR__, 2));
}

// Simple error handling for missing files
if (!file_exists(BASE_PATH . '/config/config.php')) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Config file missing.']);
    exit;
}

try {
    $config = require BASE_PATH . '/config/config.php';
    
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database config error.']);
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
    $assignedUserId = 1; // Default user ID for now

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

    // Check if order can be assigned
    if (in_array($order['status'], ['Completed', 'Shipped', 'Cancelled'])) {
        $db->rollback();
        http_response_code(400);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Comanda nu poate fi asignată. Status: ' . $order['status']
        ]);
        exit;
    }

    // Update order status to Processing and assign user
    $updateQuery = "
        UPDATE orders 
        SET status = 'Processing', 
            assigned_to = :assigned_to,
            updated_at = NOW()
        WHERE order_number = :order_number
    ";
    
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindParam(':assigned_to', $assignedUserId, PDO::PARAM_INT);
    $updateStmt->bindParam(':order_number', $orderNumber, PDO::PARAM_STR);
    $updateStmt->execute();

    $db->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Comanda a fost asignată cu succes.',
        'order_id' => $order['id'],
        'order_number' => $orderNumber,
        'customer_name' => $order['customer_name']
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    error_log("Database error in assign_order.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Eroare bază de date.',
        'error_code' => 'DB_ERROR'
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    error_log("General error in assign_order.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Eroare server.',
        'error_code' => 'SERVER_ERROR'
    ]);
}
?>