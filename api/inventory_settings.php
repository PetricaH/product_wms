<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $search   = trim($_GET['search'] ?? '');
        $category = trim($_GET['category'] ?? '');
        $seller   = trim($_GET['seller'] ?? '');
        $limit    = min(100, max(1, intval($_GET['limit'] ?? 20))); // cap results
        $offset   = max(0, intval($_GET['offset'] ?? 0));

        $conditions = [];
        $params     = [];

        if ($search !== '') {
            $conditions[]        = '(p.name LIKE :search OR p.sku LIKE :search)';
            $params[':search']   = '%' . $search . '%';
        }
        if ($category !== '') {
            $conditions[]        = 'p.category = :category';
            $params[':category'] = $category;
        }
        if ($seller === 'assigned') {
            $conditions[] = 'p.seller_id IS NOT NULL';
        } elseif ($seller === 'unassigned') {
            $conditions[] = 'p.seller_id IS NULL';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $query = "SELECT p.product_id, p.sku, p.name, p.category, p.seller_id,
                         COALESCE(inv.current_stock, 0) AS current_stock,
                         p.min_stock_level, p.min_order_quantity,
                         p.auto_order_enabled, p.last_auto_order_date,
                         s.supplier_name
                  FROM products p
                  LEFT JOIN (
                        SELECT product_id, SUM(quantity) AS current_stock
                        FROM inventory
                        GROUP BY product_id
                  ) inv ON inv.product_id = p.product_id
                  LEFT JOIN sellers s ON p.seller_id = s.id
                  $where
                  ORDER BY p.name ASC
                  LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($query);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countQuery = "SELECT COUNT(*) FROM products p
                        LEFT JOIN sellers s ON p.seller_id = s.id $where";
        $countStmt = $db->prepare($countQuery);
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        $result = array_map(function($r){
            return [
                'product_id' => (int)$r['product_id'],
                'sku' => $r['sku'],
                'product_name' => $r['name'],
                'category' => $r['category'],
                'current_stock' => (int)$r['current_stock'],
                'min_stock_level' => (int)$r['min_stock_level'],
                'min_order_quantity' => (int)($r['min_order_quantity'] ?? 1),
                'auto_order_enabled' => (bool)$r['auto_order_enabled'],
                'last_auto_order_date' => $r['last_auto_order_date'],
                'seller_id' => isset($r['seller_id']) ? (int)$r['seller_id'] : null,
                'supplier_name' => $r['supplier_name']
            ];
        }, $rows);

        echo json_encode([
            'success' => true,
            'data' => $result,
            'total' => $total,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'has_next' => ($offset + $limit) < $total
            ]
        ]);
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['product_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid input']);
            exit;
        }
        $productId = (int)$input['product_id'];
        $minStock = max(0, (int)($input['min_stock_level'] ?? 0));
        $minOrder = max(1, (int)($input['min_order_quantity'] ?? 1));
        $autoOrder = !empty($input['auto_order_enabled']) ? 1 : 0;
        $sellerId = null;
        if (array_key_exists('seller_id', $input) && $input['seller_id'] !== null && $input['seller_id'] !== '') {
            $sellerId = (int)$input['seller_id'];
        }

        $query = "UPDATE products
                  SET min_stock_level = :min_stock,
                      min_order_quantity = :min_order,
                      auto_order_enabled = :auto_order,
                      seller_id = :seller_id
                  WHERE product_id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':min_stock', $minStock, PDO::PARAM_INT);
        $stmt->bindValue(':min_order', $minOrder, PDO::PARAM_INT);
        $stmt->bindValue(':auto_order', $autoOrder, PDO::PARAM_INT);
        if ($sellerId === null) {
            $stmt->bindValue(':seller_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':seller_id', $sellerId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
        $success = $stmt->execute();
        if ($success) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update settings']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
