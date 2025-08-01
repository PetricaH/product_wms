<?php
header('Content-Type: application/json');
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$db = $config['connection_factory']();
require_once BASE_PATH . '/models/Seller.php';
$sellerModel = new Seller($db);

$sellerId = intval($_GET['id'] ?? 0);
if ($sellerId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid seller ID']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $seller = $sellerModel->getSellerById($sellerId);
    if (!$seller) {
        http_response_code(404);
        echo json_encode(['error' => 'Seller not found']);
        exit;
    }
    echo json_encode([
        'order_deadline_day' => $seller['order_deadline_day'],
        'order_deadline_time' => $seller['order_deadline_time'],
        'next_order_date' => $sellerModel->getNextOrderDate($sellerId)
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $day = isset($input['order_deadline_day']) ? (int)$input['order_deadline_day'] : null;
    $time = $input['order_deadline_time'] ?? '23:59:00';
    $sellerModel->updateOrderDeadline($sellerId, $day ?: null, $time);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
?>
