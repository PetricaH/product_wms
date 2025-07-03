<?php
// File: order_details.php - AJAX endpoint for order details
header('Content-Type: application/json');

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
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

// Database connection
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include model
require_once BASE_PATH . '/models/Order.php';
$orderModel = new Order($db);

// Get Order ID from request
$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$orderId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Order ID.']);
    exit;
}

// Fetch order data using the model method
$order = $orderModel->getOrderById($orderId);

if (!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'Order not found.']);
    exit;
}

// Get statuses for display mapping
$statuses = $orderModel->getStatuses();
$order['status'] = $statuses[$order['status']] ?? $order['status']; // Map to Romanian label

// Return the data as JSON
echo json_encode($order);