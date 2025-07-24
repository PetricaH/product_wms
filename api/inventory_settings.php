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
        $query = "SELECT p.product_id, p.sku, p.name, p.quantity,
                         p.min_stock_level, p.min_order_quantity,
                         p.auto_order_enabled, p.last_auto_order_date,
                         s.supplier_name
                  FROM products p
                  LEFT JOIN sellers s ON p.seller_id = s.id
                  ORDER BY p.name ASC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = array_map(function($r){
            return [
                'product_id' => (int)$r['product_id'],
                'sku' => $r['sku'],
                'product_name' => $r['name'],
                'current_stock' => (int)$r['quantity'],
                'min_stock_level' => (int)$r['min_stock_level'],
                'min_order_quantity' => (int)($r['min_order_quantity'] ?? 1),
                'auto_order_enabled' => (bool)$r['auto_order_enabled'],
                'last_auto_order_date' => $r['last_auto_order_date'],
                'supplier_name' => $r['supplier_name']
            ];
        }, $rows);
        echo json_encode($result);
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
        $query = "UPDATE products
                  SET min_stock_level = :min_stock,
                      min_order_quantity = :min_order,
                      auto_order_enabled = :auto_order
                  WHERE product_id = :id";
        $stmt = $db->prepare($query);
        $success = $stmt->execute([
            ':min_stock' => $minStock,
            ':min_order' => $minOrder,
            ':auto_order' => $autoOrder,
            ':id' => $productId
        ]);
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
