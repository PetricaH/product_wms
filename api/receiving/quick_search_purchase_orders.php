<?php
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/bootstrap.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

$search = trim($_GET['q'] ?? '');
if ($search === '') {
    echo json_encode(['success' => true, 'orders' => []]);
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT po.id, po.order_number, s.supplier_name, po.status,
               po.total_amount, po.currency
        FROM purchase_orders po
        JOIN sellers s ON po.seller_id = s.id
        WHERE po.order_number LIKE :search
          AND po.status IN ('sent','confirmed','partial_delivery','delivered')
        ORDER BY po.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([':search' => '%' . $search . '%']);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = array_map(function($po) {
        return [
            'id' => (int)$po['id'],
            'order_number' => $po['order_number'],
            'supplier_name' => $po['supplier_name'],
            'status' => $po['status'],
            'total_amount' => number_format((float)$po['total_amount'], 2),
            'currency' => $po['currency']
        ];
    }, $orders);

    echo json_encode(['success' => true, 'orders' => $formatted]);

} catch (Exception $e) {
    error_log('Quick search PO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
