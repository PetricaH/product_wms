<?php
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

$company = trim($_GET['company'] ?? '');
$length = function_exists('mb_strlen') ? mb_strlen($company) : strlen($company);

if ($company === '' || $length < 2) {
    echo json_encode([
        'success' => true,
        'orders' => [],
        'message' => $company === '' ? 'No search term provided' : 'Search term too short'
    ]);
    exit;
}

try {
    $normalizedSearch = function_exists('mb_strtolower') ? mb_strtolower($company) : strtolower($company);

    $stmt = $db->prepare(
        "SELECT 
            o.id,
            o.order_number,
            o.customer_name,
            o.status,
            o.order_date,
            o.updated_at,
            COALESCE(o.total_value, 0) AS total_value,
            COUNT(oi.id) AS total_items
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE LOWER(o.status) = 'picked'
          AND LOWER(o.customer_name) LIKE :search
        GROUP BY o.id, o.order_number, o.customer_name, o.status, o.order_date, o.updated_at, o.total_value
        ORDER BY COALESCE(o.updated_at, o.order_date) DESC, o.id DESC
        LIMIT 50"
    );

    $stmt->execute([':search' => '%' . $normalizedSearch . '%']);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusMap = [
        'picked' => 'Pregătită',
        'ready_to_ship' => 'Gata de expediere',
        'completed' => 'Finalizată'
    ];

    $formatted = array_map(function ($order) use ($statusMap) {
        $status = strtolower($order['status'] ?? '');
        $latest = $order['updated_at'] ?? $order['order_date'];

        return [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'customer_name' => $order['customer_name'] ?? 'Client necunoscut',
            'status' => $status,
            'status_label' => $statusMap[$status] ?? ($order['status'] ?? 'Necunoscut'),
            'order_date' => $order['order_date'],
            'updated_at' => $order['updated_at'],
            'latest_activity' => $latest,
            'total_value' => (float)$order['total_value'],
            'currency' => 'RON',
            'total_items' => (int)$order['total_items']
        ];
    }, $orders);

    echo json_encode([
        'success' => true,
        'orders' => $formatted,
        'count' => count($formatted)
    ]);
} catch (Exception $e) {
    error_log('search_picked_orders error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}
