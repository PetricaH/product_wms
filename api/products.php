<?php
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

try {
    $query = "
        SELECT 
            p.product_id as id,
            p.name,
            p.sku as code,
            p.category,
            COUNT(pu.id) as configured_units
        FROM products p
        LEFT JOIN product_units pu ON p.product_id = pu.product_id AND pu.active = 1
        GROUP BY p.product_id, p.name, p.sku, p.category
        ORDER BY p.name ASC
    ";
    
    $stmt = $db->prepare($query);
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