<?php
// File: order_details.php - API endpoint for fetching order details
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
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

// Include Order model
require_once BASE_PATH . '/models/Order.php';
$orderModel = new Order($db);

// Get order ID from request
$orderId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$fullDetails = isset($_GET['full']) && $_GET['full'] == '1';

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order ID']);
    exit;
}

try {
    if ($fullDetails) {
        // Get full order details with items
        $order = $orderModel->findById($orderId);
        
        if (!$order) {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
            exit;
        }

        // Add status translation
        $statuses = $orderModel->getStatuses();
        $order['status_label'] = $statuses[$order['status']] ?? $order['status'];
        
        echo json_encode($order);
    } else {
        // Get basic order details (for edit modal)
        $order = $orderModel->findById($orderId);
        
        if (!$order) {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
            exit;
        }

        // Return only the basic order fields (not items)
        $basicOrder = [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'customer_name' => $order['customer_name'],
            'customer_email' => $order['customer_email'],
            'shipping_address' => $order['shipping_address'],
            'order_date' => $order['order_date'],
            'status' => $order['status'],
            'tracking_number' => $order['tracking_number'],
            'notes' => $order['notes'],
            'total_value' => $order['total_value']
        ];
        
        echo json_encode($basicOrder);
    }

} catch (Exception $e) {
    error_log("Error fetching order details: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>