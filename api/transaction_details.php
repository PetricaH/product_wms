<?php
// File: api/transaction_details.php - API endpoint for fetching transaction details
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Database connection
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection error']);
    exit;
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include Transaction model
require_once BASE_PATH . '/models/Transaction.php';
$transactionModel = new Transaction($db);

// Get transaction ID from request
$transactionId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$fullDetails = isset($_GET['full']) && $_GET['full'] == '1';
$includeAudit = isset($_GET['audit']) && $_GET['audit'] == '1';

if ($transactionId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid transaction ID']);
    exit;
}

try {
    if ($fullDetails) {
        // Get full transaction details with items and audit trail
        $transaction = $transactionModel->getTransactionDetails($transactionId);
        
        if (!$transaction) {
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found']);
            exit;
        }

        // Add status and type translations
        $statuses = [
            'pending' => 'În așteptare',
            'processing' => 'În procesare',
            'completed' => 'Finalizat',
            'failed' => 'Eșuat',
            'cancelled' => 'Anulat'
        ];
        
        $types = [
            'sales' => 'Vânzare',
            'purchase' => 'Achiziție',
            'adjustment' => 'Ajustare',
            'transfer' => 'Transfer',
            'return' => 'Retur'
        ];
        
        $referenceTypes = [
            'order' => 'Comandă',
            'inventory' => 'Inventar',
            'location' => 'Locație',
            'manual' => 'Manual'
        ];

        $transaction['status_label'] = $statuses[$transaction['status']] ?? $transaction['status'];
        $transaction['type_label'] = $types[$transaction['transaction_type']] ?? $transaction['transaction_type'];
        $transaction['reference_type_label'] = $referenceTypes[$transaction['reference_type']] ?? $transaction['reference_type'];
        
        // Include audit trail if requested
        if ($includeAudit) {
            $transaction['audit_trail'] = $transactionModel->getTransactionAudit($transactionId);
        }
        
        echo json_encode($transaction);
        
    } else {
        // Get basic transaction details (for edit modal)
        $query = "SELECT id, transaction_type, status, amount, tax_amount, net_amount, 
                         currency, description, customer_name, supplier_name, 
                         reference_type, reference_id, invoice_date, series, 
                         created_at, updated_at
                  FROM transactions 
                  WHERE id = :id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $transactionId, PDO::PARAM_INT);
        $stmt->execute();
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction) {
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found']);
            exit;
        }

        // Add formatted amounts
        $transaction['amount_formatted'] = number_format($transaction['amount'], 2);
        $transaction['tax_amount_formatted'] = number_format($transaction['tax_amount'], 2);
        $transaction['net_amount_formatted'] = number_format($transaction['net_amount'], 2);
        
        echo json_encode($transaction);
    }

} catch (Exception $e) {
    error_log("Error fetching transaction details: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
?>