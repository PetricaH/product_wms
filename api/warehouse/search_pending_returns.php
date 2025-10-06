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
        'returns' => [],
        'message' => $company === '' ? 'No search term provided' : 'Search term too short'
    ]);
    exit;
}

try {
    $normalizedSearch = function_exists('mb_strtolower') ? mb_strtolower($company) : strtolower($company);

    $stmt = $db->prepare(
        "SELECT 
            r.id AS return_id,
            r.order_id,
            o.order_number,
            o.customer_name,
            r.status AS return_status,
            r.return_awb,
            r.return_date,
            r.notes,
            r.created_at,
            COUNT(oi.id) AS total_items
        FROM returns r
        JOIN orders o ON r.order_id = o.id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE r.status IN ('pending', 'in_progress')
          AND LOWER(o.customer_name) LIKE :search
        GROUP BY r.id, r.order_id, o.order_number, o.customer_name, r.status, r.return_awb, r.return_date, r.notes, r.created_at
        ORDER BY r.return_date DESC, r.created_at DESC
        LIMIT 50"
    );

    $stmt->execute([':search' => '%' . $normalizedSearch . '%']);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusMap = [
        'pending' => 'În așteptare',
        'in_progress' => 'În curs',
        'completed' => 'Finalizat',
        'cancelled' => 'Anulat'
    ];

    $formatted = array_map(function ($return) use ($statusMap) {
        $status = strtolower($return['return_status'] ?? '');

        return [
            'return_id' => (int)($return['return_id'] ?? 0),
            'order_id' => isset($return['order_id']) ? (int)$return['order_id'] : null,
            'id' => isset($return['order_id']) ? (int)$return['order_id'] : null,
            'order_number' => $return['order_number'] ?? null,
            'customer_name' => $return['customer_name'] ?? 'Client necunoscut',
            'return_status' => $status,
            'status_label' => $statusMap[$status] ?? ($return['return_status'] ?? 'Necunoscut'),
            'return_awb' => $return['return_awb'] ?? null,
            'return_date' => $return['return_date'] ?? null,
            'notes' => $return['notes'] ?? null,
            'created_at' => $return['created_at'] ?? null,
            'total_items' => (int)($return['total_items'] ?? 0)
        ];
    }, $returns);

    echo json_encode([
        'success' => true,
        'returns' => $formatted,
        'count' => count($formatted)
    ]);
} catch (Exception $e) {
    error_log('search_pending_returns error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}
