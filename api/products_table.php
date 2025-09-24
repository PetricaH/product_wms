<?php
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection not configured.']);
    exit;
}

try {
    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    require_once BASE_PATH . '/models/Product.php';

    $productModel = new Product($db);

    $page = max(1, intval($_GET['page'] ?? 1));
    $pageSize = intval($_GET['pageSize'] ?? 25);
    if ($pageSize <= 0) {
        $pageSize = 25;
    }
    $pageSize = min($pageSize, 100);

    $search = trim($_GET['search'] ?? '');
    $category = trim($_GET['category'] ?? '');
    $sellerId = intval($_GET['seller_id'] ?? 0);

    $totalCount = $productModel->getTotalCountWithSellers($search, $category, $sellerId);
    $totalPages = max(1, (int)ceil($totalCount / $pageSize));

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $pageSize;

    $allProducts = $productModel->getProductsPaginatedWithSellers($pageSize, $offset, $search, $category, $sellerId);

    ob_start();
    include BASE_PATH . '/views/products/products_table.php';
    $html = ob_get_clean();

    echo json_encode([
        'success' => true,
        'html' => $html,
        'totalCount' => $totalCount,
        'page' => $page,
        'totalPages' => $totalPages,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'details' => $e->getMessage(),
    ]);
}
