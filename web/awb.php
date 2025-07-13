<?php
/**
 * AWB Entry Point - Simple Query Parameter Approach
 * File: web/awb.php
 * Handles AWB generation requests using query parameters
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/web/controllers/AWBController.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Log for debugging
error_log("AWB Request Debug:");
error_log("- Method: " . $method);
error_log("- GET params: " . json_encode($_GET));
error_log("- POST body: " . file_get_contents('php://input'));

try {
    if ($method === 'POST') {
        // Get order ID from query parameters
        $orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : null;
        
        // If not in GET, try POST body
        if (!$orderId) {
            $input = file_get_contents('php://input');
            $postData = json_decode($input, true);
            if ($postData && isset($postData['order_id'])) {
                $orderId = (int)$postData['order_id'];
            }
        }
        
        if (!$orderId) {
            throw new Exception('Order ID is required', 400);
        }
        
        error_log("AWB Generation requested for order ID: " . $orderId);
        
        $controller = new AWBController();
        $controller->generateAWB($orderId);
        
    } else {
        throw new Exception('Only POST method is allowed', 405);
    }
    
} catch (Exception $e) {
    $code = $e->getCode() ?: 500;
    http_response_code($code);
    
    error_log("AWB Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'method' => $method,
            'get_params' => $_GET,
            'order_id_received' => $orderId ?? 'not found'
        ],
        'timestamp' => date('c')
    ]);
}
?>