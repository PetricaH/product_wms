<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'] ?? null;

if (!$dbFactory || !is_callable($dbFactory)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection factory not configured.'
    ]);
    exit;
}

try {
    $db = $dbFactory();

    $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo = $_GET['date_to'] ?? date('Y-m-d');
    $statusFilter = $_GET['status'] ?? '';

    $fromDate = DateTime::createFromFormat('Y-m-d', $dateFrom) ?: new DateTime('-30 days');
    $toDate = DateTime::createFromFormat('Y-m-d', $dateTo) ?: new DateTime();

    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }

    $query = $db->prepare(
        "SELECT po.id, po.order_number, po.status, po.total_amount, po.currency,
                po.created_at, po.email_sent_at, po.notes, po.invoiced,
                s.supplier_name,
                GROUP_CONCAT(DISTINCT pr.name ORDER BY pr.name SEPARATOR ', ') AS product_names,
                MAX(poi.quantity) AS max_quantity,
                SUM(poi.quantity) AS total_quantity
         FROM purchase_orders po
         LEFT JOIN sellers s ON po.seller_id = s.id
         LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
         LEFT JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
         LEFT JOIN products pr ON pp.internal_product_id = pr.product_id
         WHERE (po.notes LIKE 'Autocomandă%' OR po.notes LIKE 'Auto-generată%' OR poi.notes LIKE 'Autocomandă%')
           AND DATE(po.created_at) BETWEEN :date_from AND :date_to
         GROUP BY po.id
         ORDER BY po.created_at DESC"
    );

    $query->bindValue(':date_from', $fromDate->format('Y-m-d'));
    $query->bindValue(':date_to', $toDate->format('Y-m-d'));
    $query->execute();

    $orders = $query->fetchAll(PDO::FETCH_ASSOC);

    $normalizeStatus = static function (array $row): string {
        $notes = mb_strtolower((string)($row['notes'] ?? ''), 'UTF-8');
        $isAuto = strpos($notes, 'autocomandă') !== false
            || strpos($notes, 'auto-generată') !== false
            || strpos($notes, 'autocomanda') !== false;

        if (!$isAuto) {
            return 'manual';
        }

        $emailSent = !empty($row['email_sent_at']);
        $status = $row['status'];

        if ($status === 'cancelled') {
            return 'failed';
        }

        if ($emailSent || in_array($status, ['sent', 'confirmed', 'delivered', 'invoiced', 'completed'], true)) {
            return 'success';
        }

        if ($status === 'draft') {
            return 'pending';
        }

        return 'processing';
    };

    $history = [];
    foreach ($orders as $order) {
        $computedStatus = $normalizeStatus($order);
        if ($statusFilter && $statusFilter !== $computedStatus) {
            continue;
        }

        $history[] = [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'created_at' => $order['created_at'],
            'supplier_name' => $order['supplier_name'],
            'status' => $order['status'],
            'auto_order_status' => $computedStatus,
            'total_amount' => (float)$order['total_amount'],
            'currency' => $order['currency'],
            'email_sent_at' => $order['email_sent_at'],
            'products' => $order['product_names'],
            'total_quantity' => (float)$order['total_quantity'],
            'max_quantity' => (float)$order['max_quantity'],
            'invoiced' => (bool)$order['invoiced']
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $history
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load auto order history.',
        'error' => $e->getMessage()
    ]);
}
