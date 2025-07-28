<?php
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

try {
    $search = trim($_GET['search'] ?? '');
    $limit  = min(100, max(1, intval($_GET['limit'] ?? 10)));

    $baseQuery = "
        SELECT
            p.product_id as id,
            p.name,
            p.sku as code,
            p.category,
            COUNT(pu.id) as configured_units
        FROM products p
        LEFT JOIN product_units pu ON p.product_id = pu.product_id AND pu.active = 1
    ";

    $where = '';
    $params = [];
    if ($search !== '') {
        $where = "WHERE p.name LIKE :search OR p.sku LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }

    $query = "$baseQuery $where GROUP BY p.product_id, p.name, p.sku, p.category ORDER BY p.name ASC LIMIT :limit";

    $stmt = $db->prepare($query);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedProducts = array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'code' => $row['code'] ?: 'N/A',
            'category' => $row['category'] ?: 'General',
            'configured_units' => (int)$row['configured_units']
        ];
    }, $products);
    
    echo json_encode($formattedProducts);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
?>