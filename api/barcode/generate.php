<?php
ob_start();
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/utils/BarcodeGenerator.php';

use Utils\BarcodeGenerator;

function respond(array $payload, int $statusCode = 200): void
{
    ob_end_clean();
    http_response_code($statusCode);
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

$productIds = [];
if (isset($input['product_ids']) && is_array($input['product_ids'])) {
    $productIds = array_values(array_filter(array_map('intval', $input['product_ids']), static fn($id) => $id > 0));
}

if (isset($input['product_id'])) {
    $singleId = (int)$input['product_id'];
    if ($singleId > 0) {
        $productIds = [$singleId];
    }
}

$productIds = array_values(array_unique($productIds));
if (!$productIds) {
    respond(['status' => 'error', 'message' => 'No product IDs supplied'], 400);
}

$forceOverwrite = filter_var($input['force_overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);

try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();
} catch (Exception $e) {
    respond(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()], 500);
}

$userId = (int)($_SESSION['user_id'] ?? 0);

$logTableExists = null;
$results = [];
$successCount = 0;
$warningCount = 0;
$errorCount = 0;

foreach ($productIds as $productId) {
    $result = processProductBarcode($db, $productId, $forceOverwrite, $userId, $logTableExists);
    $results[] = $result;

    $status = $result['status'] ?? 'error';
    if ($status === 'success') {
        $successCount++;
    } elseif (in_array($status, ['confirm', 'exists'], true)) {
        $warningCount++;
    } else {
        $errorCount++;
    }
}

if (count($productIds) > 1) {
    $response = [
        'status' => $errorCount > 0 && $successCount === 0 ? 'error' : 'success',
        'generated_count' => $successCount,
        'warnings_count' => $warningCount,
        'errors_count' => $errorCount,
        'results' => $results,
    ];

    if ($errorCount > 0) {
        respond($response, 207);
    }

    respond($response);
}

$singleResult = $results[0] ?? ['status' => 'error', 'message' => 'Unexpected error'];
$status = $singleResult['status'] ?? 'error';

if ($status === 'error') {
    $code = $singleResult['code'] ?? 400;
    respond($singleResult, $code);
}

respond($singleResult);

function processProductBarcode(PDO $db, int $productId, bool $force, int $userId, ?bool &$logTableExists): array
{
    try {
        $stmt = $db->prepare('SELECT product_id, sku, name FROM products WHERE product_id = ? LIMIT 1');
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage(),
            'code' => 500,
            'product_id' => $productId,
        ];
    }

    if (!$product) {
        return [
            'status' => 'error',
            'message' => 'Product not found',
            'code' => 404,
            'product_id' => $productId,
        ];
    }

    $currentSku = trim((string)($product['sku'] ?? ''));
    $response = [
        'product_id' => $productId,
        'product_name' => $product['name'] ?? '',
    ];

    if ($currentSku !== '') {
        if (BarcodeGenerator::isEAN13Format($currentSku)) {
            return $response + [
                'status' => 'exists',
                'sku' => $currentSku,
                'message' => 'Product already has an EAN-13 barcode.',
            ];
        }

        $proposed = null;
        try {
            $proposed = BarcodeGenerator::generateEAN13($productId);
        } catch (InvalidArgumentException $e) {
            return $response + [
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => 400,
            ];
        }

        if (!$force) {
            return $response + [
                'status' => 'confirm',
                'message' => 'Product already has a non-standard SKU. Confirmation required.',
                'current_sku' => $currentSku,
                'proposed_sku' => $proposed,
                'current_type' => BarcodeGenerator::isAlphanumeric($currentSku) ? 'alphanumeric' : 'numeric',
            ];
        }
    }

    try {
        $generatedSku = BarcodeGenerator::generateEAN13($productId);
    } catch (InvalidArgumentException $e) {
        return $response + [
            'status' => 'error',
            'message' => $e->getMessage(),
            'code' => 400,
        ];
    }

    if ($currentSku === $generatedSku) {
        return $response + [
            'status' => 'success',
            'sku' => $generatedSku,
            'message' => 'EAN-13 barcode already assigned to this product.',
            'overwritten' => false,
        ];
    }

    try {
        $checkStmt = $db->prepare('SELECT product_id FROM products WHERE sku = ? AND product_id <> ? LIMIT 1');
        $checkStmt->execute([$generatedSku, $productId]);
        if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
            return $response + [
                'status' => 'error',
                'message' => 'Generated barcode already assigned to a different product.',
                'code' => 409,
            ];
        }
    } catch (PDOException $e) {
        return $response + [
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage(),
            'code' => 500,
        ];
    }

    try {
        $update = $db->prepare('UPDATE products SET sku = ? WHERE product_id = ? LIMIT 1');
        $update->execute([$generatedSku, $productId]);
    } catch (PDOException $e) {
        return $response + [
            'status' => 'error',
            'message' => 'Failed to update product SKU: ' . $e->getMessage(),
            'code' => 500,
        ];
    }

    if ($logTableExists !== false) {
        $logTableExists = $logTableExists ?? logTableExists($db);
        if ($logTableExists) {
            try {
                $log = $db->prepare('INSERT INTO barcode_generation_log (product_id, old_sku, new_sku, generated_by) VALUES (?, ?, ?, ?)');
                $log->execute([$productId, $currentSku ?: null, $generatedSku, $userId ?: null]);
            } catch (PDOException $e) {
                // Ignore logging errors but prevent repeated attempts if table missing
                if ($logTableExists === null) {
                    $logTableExists = logTableExists($db, true);
                }
            }
        }
    }

    return $response + [
        'status' => 'success',
        'sku' => $generatedSku,
        'previous_sku' => $currentSku !== '' ? $currentSku : null,
        'overwritten' => $currentSku !== '',
        'message' => $currentSku !== '' ? 'Existing SKU replaced with EAN-13 barcode.' : 'EAN-13 barcode generated successfully.',
    ];
}

function logTableExists(PDO $db, bool $forceRecheck = false): bool
{
    static $cache = null;

    if ($forceRecheck) {
        $cache = null;
    }

    if ($cache !== null) {
        return $cache;
    }

    try {
        $db->query('SELECT 1 FROM barcode_generation_log LIMIT 1');
        $cache = true;
    } catch (PDOException $e) {
        $cache = false;
    }

    return $cache;
}

