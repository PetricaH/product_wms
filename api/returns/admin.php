<?php
/**
 * Admin Returns API
 * Provides summary, list, detail and stats for returns management.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require admin or manager role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','manager'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'summary':
            summary($db);
            break;
        case 'stats':
            stats($db);
            break;
        case 'detail':
            $id = (int)($_GET['id'] ?? 0);
            detail($db, $id);
            break;
        case 'list':
        default:
            listReturns($db);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function summary(PDO $db) {
    $stmt = $db->query("SELECT
            SUM(status='in_progress') AS in_progress,
            SUM(status='pending') AS pending,
            SUM(status='completed') AS completed,
            SUM(auto_created = 1) AS auto_created
        FROM returns");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $disc = $db->query("SELECT COUNT(*) FROM return_discrepancies WHERE resolution_status='pending'")->fetchColumn();
    echo json_encode([
        'success' => true,
        'summary' => [
            'in_progress' => (int)$row['in_progress'],
            'pending' => (int)$row['pending'],
            'completed' => (int)$row['completed'],
            'discrepancies' => (int)$disc,
            'auto_created' => (int)$row['auto_created']
        ]
    ]);
}

function stats(PDO $db) {
    $stmt = $db->query("SELECT DATE(created_at) AS day, COUNT(*) AS total
                        FROM returns
                        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        GROUP BY day ORDER BY day");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'stats' => $rows]);
}

function listReturns(PDO $db) {
    $status = $_GET['status'] ?? '';
    $search = trim($_GET['search'] ?? '');
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';

    $params = [];
    $where = [];

    if ($status !== '') {
        $where[] = 'r.status = :status';
        $params[':status'] = $status;
    }
    if ($search !== '') {
        $where[] = '(o.order_number LIKE :search OR o.customer_name LIKE :search)';
        $params[':search'] = "%$search%";
    }
    if ($from !== '') {
        $where[] = 'r.created_at >= :from';
        $params[':from'] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $where[] = 'r.created_at <= :to';
        $params[':to'] = $to . ' 23:59:59';
    }

    $sql = "SELECT r.id, o.order_number, o.customer_name, r.status,
                   r.return_awb, r.auto_created, r.return_date,
                   u.username AS processed_by, r.created_at, r.verified_at,
                   (SELECT COUNT(*) FROM return_discrepancies rd WHERE rd.return_id = r.id) AS discrepancies
            FROM returns r
            JOIN orders o ON r.order_id = o.id
            LEFT JOIN users u ON r.processed_by = u.id";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY r.created_at DESC LIMIT 100';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="returns.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Order','Customer','Status','Return AWB','Auto Created','Return Date','Processed By','Created At','Verified At','Discrepancies']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'],
                $r['order_number'],
                $r['customer_name'],
                $r['status'],
                $r['return_awb'],
                (int)$r['auto_created'],
                $r['return_date'],
                $r['processed_by'],
                $r['created_at'],
                $r['verified_at'],
                $r['discrepancies']
            ]);
        }
        fclose($out);
        return;
    }

    foreach ($rows as &$row) {
        $row['auto_created'] = (bool)$row['auto_created'];
    }

    echo json_encode(['success' => true, 'returns' => $rows]);
}

function detail(PDO $db, int $id) {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        return;
    }

    $stmt = $db->prepare("SELECT r.id, r.status, r.notes, r.created_at, r.verified_at,
                                   r.return_awb, r.auto_created, r.return_date,
                                   o.order_number, o.customer_name,
                                   u.username AS processed_by, v.username AS verified_by
                           FROM returns r
                           JOIN orders o ON r.order_id = o.id
                           LEFT JOIN users u ON r.processed_by = u.id
                           LEFT JOIN users v ON r.verified_by = v.id
                           WHERE r.id = :id");
    $stmt->execute([':id' => $id]);
    $return = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$return) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Return not found']);
        return;
    }

    $itemsStmt = $db->prepare("SELECT ri.id, p.sku, p.name, ri.quantity_returned, ri.item_condition, ri.is_extra
                               FROM return_items ri
                               JOIN products p ON ri.product_id = p.product_id
                               WHERE ri.return_id = :id");
    $itemsStmt->execute([':id' => $id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $discStmt = $db->prepare("SELECT rd.id, p.sku, p.name, rd.discrepancy_type, rd.expected_quantity,
                                     rd.actual_quantity, rd.item_condition, rd.resolution_status
                              FROM return_discrepancies rd
                              JOIN products p ON rd.product_id = p.product_id
                              WHERE rd.return_id = :id");
    $discStmt->execute([':id' => $id]);
    $discrepancies = $discStmt->fetchAll(PDO::FETCH_ASSOC);

    $return['auto_created'] = (bool)$return['auto_created'];

    echo json_encode([
        'success' => true,
        'return' => $return,
        'items' => $items,
        'discrepancies' => $discrepancies
    ]);
}
