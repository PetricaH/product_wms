<?php
ob_start();
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/bootstrap.php';

function respond($data, int $code = 200): void {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$allowedRoles = ['admin', 'warehouse', 'warehouse_worker'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    respond(['status' => 'error', 'message' => 'Access denied'], 403);
}

try {
    $config = require BASE_PATH . '/config/config.php';
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        respond(['status' => 'error', 'message' => 'Database configuration error'], 500);
    }
    $db = $config['connection_factory']();
    require_once BASE_PATH . '/models/Product.php';
    $productModel = new Product($db);
} catch (Exception $e) {
    respond(['status' => 'error', 'message' => $e->getMessage()], 500);
}

$templateDir = BASE_PATH . '/storage/templates/product_labels/';
if (!is_dir($templateDir)) {
    @mkdir($templateDir, 0777, true);
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet($db, $templateDir);
        break;
    case 'POST':
        handlePost($db, $templateDir);
        break;
    case 'DELETE':
        handleDelete($db, $templateDir);
        break;
    default:
        respond(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

function handleGet(PDO $db, string $templateDir): void {
    $search = trim($_GET['search'] ?? '');
    $status = $_GET['status'] ?? '';
    $limit = max(0, (int)($_GET['limit'] ?? 50));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    // Statistics for all products
    $statsStmt = $db->query('SELECT sku FROM products');
    $total = 0;
    $with = 0;
    while ($row = $statsStmt->fetch(PDO::FETCH_ASSOC)) {
        $total++;
        if (findLabelTemplate($row['sku'], $templateDir)) {
            $with++;
        }
    }
    $stats = [
        'total' => $total,
        'with_label' => $with,
        'without_label' => $total - $with
    ];

    $products = [];
    $totalFiltered = 0;
    if ($limit > 0) {
        $query = 'SELECT product_id, sku, name FROM products';
        $params = [];
        if ($search !== '') {
            $query .= ' WHERE (name LIKE :s OR sku LIKE :s)';
            $params[':s'] = "%$search%";
        }
        $query .= ' ORDER BY name ASC';
        $stmt = $db->prepare($query);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filtered = [];
        foreach ($rows as $row) {
            $template = findLabelTemplate($row['sku'], $templateDir);
            $has = $template !== null;
            if ($status === 'with' && !$has) {
                continue;
            }
            if ($status === 'without' && $has) {
                continue;
            }
            $row['has_label'] = $has;
            if ($has) {
                $row['template'] = basename($template);
            }
            $filtered[] = $row;
        }
        $totalFiltered = count($filtered);
        $products = array_slice($filtered, $offset, $limit);
    }

    respond(['status' => 'success', 'data' => [
        'products' => $products,
        'stats' => $stats,
        'total' => $totalFiltered,
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'has_next' => $limit > 0 ? ($offset + $limit) < $totalFiltered : false
        ]
    ]]);
}

function handlePost(PDO $db, string $templateDir): void {
    $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    if ($productId <= 0 || !isset($_FILES['template'])) {
        respond(['status' => 'error', 'message' => 'Missing data'], 400);
    }

    $product = findProduct($db, $productId);
    if (!$product) {
        respond(['status' => 'error', 'message' => 'Product not found'], 404);
    }

    $file = $_FILES['template'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        respond(['status' => 'error', 'message' => 'Upload failed'], 400);
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'png') {
        respond(['status' => 'error', 'message' => 'Only PNG templates are allowed'], 400);
    }

    $code = extractCodeFromSku($product['sku']);
    $slug = slugify($product['name']);
    $fileName = $slug . '-' . $code . '.png';
    $target = $templateDir . $fileName;

    deleteExistingTemplates($product['sku'], $templateDir);
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        respond(['status' => 'error', 'message' => 'Failed to save template'], 500);
    }

    respond(['status' => 'success', 'message' => 'Template uploaded', 'file' => $fileName]);
}

function handleDelete(PDO $db, string $templateDir): void {
    parse_str(file_get_contents('php://input'), $data);
    $productId = isset($data['product_id']) ? (int)$data['product_id'] : 0;
    if ($productId <= 0) {
        respond(['status' => 'error', 'message' => 'Missing product_id'], 400);
    }

    $product = findProduct($db, $productId);
    if (!$product) {
        respond(['status' => 'error', 'message' => 'Product not found'], 404);
    }

    $deleted = deleteExistingTemplates($product['sku'], $templateDir);
    if (!$deleted) {
        respond(['status' => 'error', 'message' => 'Template not found'], 404);
    }

    respond(['status' => 'success', 'message' => 'Template deleted']);
}

function findProduct(PDO $db, int $id): ?array {
    $stmt = $db->prepare('SELECT product_id, sku, name FROM products WHERE product_id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function findLabelTemplate(string $sku, string $dir): ?string {
    $code = extractCodeFromSku($sku);
    if ($code === '') {
        return null;
    }
    foreach (glob($dir . '*.png') as $file) {
        $name = basename($file);
        if (preg_match('/-(\d+)(?:_[^.]*)?\.png$/', $name, $m) && $m[1] === $code) {
            return $file;
        }
    }
    return null;
}

function deleteExistingTemplates(string $sku, string $dir): bool {
    $code = extractCodeFromSku($sku);
    $deleted = false;
    foreach (glob($dir . '*.png') as $file) {
        $name = basename($file);
        if (preg_match('/-(\d+)(?:_[^.]*)?\.png$/', $name, $m) && $m[1] === $code) {
            @unlink($file);
            $deleted = true;
        }
    }
    return $deleted;
}

function extractCodeFromSku(string $sku): string {
    if (preg_match('/(\d+)/', $sku, $m)) {
        return $m[1];
    }
    return '';
}

function slugify(string $text): string {
    $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    $text = strtolower($text);
    $text = preg_replace('~[^-a-z0-9]+~', '', $text);
    return $text ?: 'product';
}
?>
