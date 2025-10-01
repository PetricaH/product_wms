<?php
// File: api/warehouse/get_picking_status.php - Polling endpoint for order picking progress
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$allowedRoles = ['admin', 'warehouse', 'warehouse_worker'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Autentificare necesară.'
    ]);
    exit;
}

$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
if (!$orderId) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Parametru order_id invalid.'
    ]);
    exit;
}

if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Configurația bazei de date lipsește.'
    ]);
    exit;
}

try {
    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    $stmt = $db->prepare(
        'SELECT 
            oi.id AS order_item_id,
            oi.quantity AS quantity_ordered,
            COALESCE(oi.picked_quantity, 0) AS picked_quantity
        FROM order_items oi
        WHERE oi.order_id = :order_id
        ORDER BY oi.id'
    );
    $stmt->execute([':order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => [
            'order_id' => $orderId,
            'items' => array_map(static function ($item) {
                return [
                    'order_item_id' => (int) $item['order_item_id'],
                    'quantity_ordered' => (int) $item['quantity_ordered'],
                    'picked_quantity' => (int) $item['picked_quantity']
                ];
            }, $items),
            'fetched_at' => date('c')
        ]
    ]);
} catch (Throwable $e) {
    error_log('get_picking_status error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'A apărut o eroare la interogarea bazei de date.'
    ]);
}
