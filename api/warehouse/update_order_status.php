<?php
// File: /api/warehouse/update_order_status.php
// Handles updating the status of an order.

// Start output buffering to catch any stray output and ensure a clean JSON response.
ob_start();

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Define Base Path
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

// A reusable function to send a clean JSON response and terminate the script.
function send_json_response(array $data, int $http_code = 200) {
    ob_end_clean(); // Discard any previous output buffer
    http_response_code($http_code);
    echo json_encode($data);
    exit;
}

// Ensure the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(['status' => 'error', 'message' => 'Metodă nepermisă. Doar POST este acceptat.'], 405);
}

// Check for essential configuration
if (!file_exists(BASE_PATH . '/config/config.php')) {
    send_json_response(['status' => 'error', 'message' => 'Fișierul de configurare lipsește.'], 500);
}

try {
    $config = require BASE_PATH . '/config/config.php';
    
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        send_json_response(['status' => 'error', 'message' => 'Configurarea bazei de date este invalidă.'], 500);
    }

    // Establish database connection
    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    // Get and decode the JSON input from the request body
    $input = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        send_json_response(['status' => 'error', 'message' => 'JSON invalid în corpul cererii.'], 400);
    }

    // Validate the input and convert to lowercase for consistent matching
    $orderNumber = trim($input['order_number'] ?? '');
    $newStatus = trim(strtolower($input['status'] ?? ''));

    if (empty($orderNumber) || empty($newStatus)) {
        send_json_response(['status' => 'error', 'message' => 'Numărul comenzii și noul status sunt obligatorii.'], 400);
    }

    // --- CORRECTED: Map incoming status to the correct database ENUM value ---
    $dbStatus = '';
    switch ($newStatus) {
        case 'assigned': // Added this case to handle the status from the frontend
        case 'picking':
        case 'processing':
            $dbStatus = 'Processing';
            break;
        case 'picked':
            $dbStatus = 'Picked';
            break;
        case 'completed':
            $dbStatus = 'Completed';
            break;
        case 'shipped':
            $dbStatus = 'Shipped';
            break;
        case 'pending':
            $dbStatus = 'Pending';
            break;
        case 'cancelled':
            $dbStatus = 'Cancelled';
            break;
        default:
            // If the status is not recognized, reject the request.
            send_json_response(['status' => 'error', 'message' => "Statusul '" . htmlspecialchars($newStatus) . "' este invalid."], 400);
    }

    // Prepare and execute the update query using the mapped status
    $updateQuery = "UPDATE orders SET status = :status, updated_at = NOW() WHERE order_number = :order_number";
    
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindParam(':status', $dbStatus, PDO::PARAM_STR);
    $updateStmt->bindParam(':order_number', $orderNumber, PDO::PARAM_STR);
    
    $success = $updateStmt->execute();

    if ($success && $updateStmt->rowCount() > 0) {
        send_json_response([
            'status' => 'success',
            'message' => 'Statusul comenzii a fost actualizat cu succes la ' . $dbStatus
        ]);
    } elseif ($success) {
        send_json_response([
            'status' => 'info',
            'message' => 'Nicio comandă nu a fost găsită cu acest număr sau statusul este deja cel solicitat.'
        ]);
    } else {
        throw new Exception('Execuția interogării de actualizare a eșuat.');
    }

} catch (PDOException $e) {
    error_log("Database error in update_order_status.php: " . $e->getMessage());
    send_json_response([
        'status' => 'error',
        'message' => 'Eroare la baza de date.',
        'error_details' => $e->getMessage()
    ], 500);
} catch (Exception $e) {
    error_log("General error in update_order_status.php: " . $e->getMessage());
    send_json_response([
        'status' => 'error',
        'message' => 'Eroare de server.',
        'error_details' => $e->getMessage()
    ], 500);
}
