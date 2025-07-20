<?php
ob_start();
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/bootstrap.php';

if (file_exists(BASE_PATH . '/vendor/autoload.php')) {
    require_once BASE_PATH . '/vendor/autoload.php';
}
if (!class_exists('FPDF') && file_exists(BASE_PATH . '/lib/fpdf.php')) {
    require_once BASE_PATH . '/lib/fpdf.php';
}

function respond($data, int $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$allowedRoles = ['admin', 'warehouse', 'warehouse_worker'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    respond(['status' => 'error', 'message' => 'Access denied'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$quantity  = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;

if ($productId <= 0 || $quantity <= 0) {
    respond(['status' => 'error', 'message' => 'Invalid product_id or quantity'], 400);
}

try {
    $config = require BASE_PATH . '/config/config.php';
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        respond(['status' => 'error', 'message' => 'Database configuration error'], 500);
    }
    $db = $config['connection_factory']();

    require_once BASE_PATH . '/models/Product.php';
    $productModel = new Product($db);
    $product = $productModel->findById($productId);
    if (!$product) {
        respond(['status' => 'error', 'message' => 'Product not found'], 404);
    }

    $storageDir = BASE_PATH . '/storage/label_pdfs';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0777, true);
    }

    $fileName = 'label_' . ($product['sku'] ?? 'prod') . '_' . time() . '.pdf';
    $filePath = $storageDir . '/' . $fileName;

    $pdf = new FPDF('P', 'mm', [50, 30]);
    for ($i = 0; $i < $quantity; $i++) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, $product['name'], 0, 1, 'C');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 8, 'SKU: ' . $product['sku'], 0, 1, 'C');
    }
    $pdf->Output('F', $filePath);

    $baseUrl = getBaseUrl();
    $pdfUrl  = $baseUrl . '/storage/label_pdfs/' . $fileName;

    $printServerUrl = $baseUrl . '/print_server.php?url=' . urlencode($pdfUrl);
    $response = @file_get_contents($printServerUrl);
    if ($response === false) {
        respond(['status' => 'error', 'message' => 'Failed to contact print server'], 500);
    }

    if (stripos($response, 'Trimis la imprimantă') !== false || stripos($response, 'sent to printer') !== false) {
        respond(['status' => 'success', 'message' => 'Labels sent to printer']);
    }

    respond(['status' => 'error', 'message' => 'Print server response: ' . $response], 500);

} catch (Exception $e) {
    respond(['status' => 'error', 'message' => $e->getMessage()], 500);
}

function getBaseUrl(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = dirname($_SERVER['SCRIPT_NAME'], 2); // from api/labels/
    return rtrim($protocol . $host . $path, '/');
}
