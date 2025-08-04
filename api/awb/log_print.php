<?php
// File: api/awb/log_print.php
// Simple endpoint to log AWB print actions

ob_start();
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/bootstrap.php';

function respond($data, int $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$allowedRoles = ['admin', 'warehouse', 'warehouse_worker'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    respond(['success' => false, 'error' => 'Access denied'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Method not allowed'], 405);
}

$awb = $_POST['awb'] ?? '';
$orderId = intval($_POST['order_id'] ?? 0);
error_log("AWB print initiated for order {$orderId} awb {$awb} by user {$_SESSION['user_id']}");

respond(['success' => true]);
