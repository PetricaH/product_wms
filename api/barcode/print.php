<?php
ob_start();
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/utils/GodexPrinter.php';
require_once BASE_PATH . '/utils/BarcodeGenerator.php';

use Utils\BarcodeGenerator;
use Utils\GodexPrinter;

function respond(array $payload, int $code = 200): void
{
    ob_end_clean();
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

$allowedRoles = ['admin'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    respond(['status' => 'error', 'message' => 'Access denied'], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = $_POST ?: [];
}

$productUnitIds = [];
if (isset($input['product_unit_ids']) && is_array($input['product_unit_ids'])) {
    $productUnitIds = array_values(array_filter(array_map('intval', $input['product_unit_ids']), static fn($id) => $id > 0));
}

if (isset($input['product_unit_id'])) {
    $single = (int)$input['product_unit_id'];
    if ($single > 0) {
        $productUnitIds = [$single];
    }
}

$productIds = [];
if (isset($input['product_ids']) && is_array($input['product_ids'])) {
    $productIds = array_values(array_filter(array_map('intval', $input['product_ids']), static fn($id) => $id > 0));
}

if (isset($input['product_id'])) {
    $singleProduct = (int)$input['product_id'];
    if ($singleProduct > 0) {
        $productIds[] = $singleProduct;
    }
}

$requestedPrinter = '';
if (isset($input['printer']) && is_string($input['printer'])) {
    $requestedPrinter = trim($input['printer']);
}

$productUnitIds = array_values(array_unique($productUnitIds));
$productIds = array_values(array_unique($productIds));

if (!$productUnitIds && !$productIds) {
    respond(['status' => 'error', 'message' => 'No products provided for printing'], 400);
}

try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();
} catch (Exception $e) {
    respond(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()], 500);
}

$baseUrl = getBaseUrl();
$storageDir = BASE_PATH . '/storage/label_pdfs';
$printServerUrl = $config['print_server_url'] ?? (getenv('PRINT_SERVER_URL') ?: null);
$printerName = $requestedPrinter !== ''
    ? $requestedPrinter
    : ($config['default_printer'] ?? (getenv('GODEX_PRINTER_QUEUE') ?: 'godex'));

try {
    $printer = new GodexPrinter([
        'print_server_url' => $printServerUrl,
        'printer' => $printerName,
        'storage_dir' => $storageDir,
        'storage_url_path' => '/storage/label_pdfs',
        'base_url' => $baseUrl,
    ]);
} catch (Exception $e) {
    respond(['status' => 'error', 'message' => $e->getMessage()], 500);
}

try {
    $products = fetchProductsForPrinting($db, $productUnitIds, $productIds);
} catch (Exception $e) {
    respond(['status' => 'error', 'message' => $e->getMessage()], 500);
}

if (!$products) {
    respond(['status' => 'error', 'message' => 'No printable products found'], 404);
}

$prepared = [];
$errors = [];

foreach ($products as $product) {
    $sku = trim((string)($product['sku'] ?? ''));
    if ($sku === '') {
        $errors[] = [
            'product_id' => $product['product_id'],
            'message' => 'SKU missing. Cannot print label.',
        ];
        continue;
    }

    if (!BarcodeGenerator::validateEAN13($sku) && strlen($sku) < 6) {
        $errors[] = [
            'product_id' => $product['product_id'],
            'message' => 'SKU too short for printing. Generate barcode first.',
        ];
        continue;
    }

    $prepared[] = [
        'product_id' => $product['product_id'],
        'name' => $product['name'],
        'sku' => $sku,
        'product_code' => $product['product_code'] ?? $sku,
        'weight' => $product['weight_per_unit'],
        'unit_code' => $product['unit_code'] ?? 'kg',
    ];
}

if (!$prepared) {
    respond([
        'status' => 'error',
        'message' => 'No labels ready for printing.',
        'errors' => $errors,
    ], 400);
}

try {
    $result = $printer->printBatch($prepared);
} catch (Exception $e) {
    respond(['status' => 'error', 'message' => $e->getMessage()], 500);
}

$totalErrors = array_merge($errors, $result['errors'] ?? []);
$status = $totalErrors ? 'partial' : 'success';

$response = [
    'status' => $status,
    'printed' => $result['printed'] ?? 0,
    'errors' => $totalErrors,
    'printer' => $printerName,
];

$httpCode = $status === 'success' ? 200 : 207;
respond($response, $httpCode);

function fetchProductsForPrinting(PDO $db, array $unitIds, array $productIds): array
{
    $results = [];

    if ($unitIds) {
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $query = "SELECT pu.id AS unit_id, pu.product_id, pu.weight_per_unit, ut.unit_code, p.name, p.sku
                  FROM product_units pu
                  JOIN products p ON pu.product_id = p.product_id
                  LEFT JOIN unit_types ut ON pu.unit_type_id = ut.id
                  WHERE pu.id IN ($placeholders)";
        $stmt = $db->prepare($query);
        $stmt->execute($unitIds);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $row;
        }
    }

    if ($productIds) {
        $knownProductIds = array_column($results, 'product_id');
        $missing = array_values(array_diff($productIds, $knownProductIds));
        if ($missing) {
            $placeholders = implode(',', array_fill(0, count($missing), '?'));
            $query = "SELECT pu.id AS unit_id, pu.product_id, pu.weight_per_unit, ut.unit_code, p.name, p.sku
                      FROM product_units pu
                      JOIN products p ON pu.product_id = p.product_id
                      LEFT JOIN unit_types ut ON pu.unit_type_id = ut.id
                      WHERE pu.product_id IN ($placeholders)
                      ORDER BY pu.active DESC, pu.id ASC";
            $stmt = $db->prepare($query);
            $stmt->execute($missing);
            $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $byProduct = [];
            foreach ($fetched as $row) {
                $pid = (int)$row['product_id'];
                if (!isset($byProduct[$pid])) {
                    $byProduct[$pid] = $row;
                }
            }
            $results = array_merge($results, array_values($byProduct));
        }
    }

    return $results;
}

function getBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

