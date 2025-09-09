<?php
// Inventory Stock Import Handler
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/models/Inventory.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Session is started in bootstrap.php; no need to start it again here
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$importer = new InventoryStockImporter($db);
$result = $importer->processUpload();

echo json_encode($result);

class InventoryStockImporter {
    private $db;
    private $inventoryModel;
    private array $results = [
        'success' => false,
        'processed' => 0,
        'stock_added' => 0,
        'skipped' => 0,
        'message' => '',
        'warnings' => [],
        'errors' => []
    ];

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->inventoryModel = new Inventory($db);
    }

    public function processUpload(): array {
        try {
            if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No file uploaded or upload error occurred');
            }
            $file = $_FILES['excel_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xls', 'xlsx'])) {
                throw new Exception('Invalid file type. Only .xls and .xlsx allowed');
            }
            if ($file['size'] > 10 * 1024 * 1024) {
                throw new Exception('File too large');
            }
            $tmpPath = $file['tmp_name'];
            $this->processInventoryExcel($tmpPath);
            if (empty($this->results['errors'])) {
                $this->results['success'] = true;
                $this->results['message'] = 'Import completed successfully';
            } else {
                $this->results['message'] = 'Import completed with errors';
            }
        } catch (Exception $e) {
            $this->results['errors'][] = $e->getMessage();
            $this->results['message'] = $e->getMessage();
        }
        return $this->results;
    }

    private function processInventoryExcel(string $filePath): void {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        if (empty($rows)) {
            throw new Exception('Excel file is empty');
        }
        $headerInfo = $this->findHeaderRow($rows);
        if ($headerInfo['row'] === -1) {
            throw new Exception('Could not find header row');
        }
        $headerRow = $headerInfo['row'];
        $map = $this->mapColumns($headerInfo['headers']);
        if (!isset($map['sku']) || !isset($map['quantity'])) {
            throw new Exception('Required columns not found');
        }
        $this->db->beginTransaction();
        try {
            for ($i = $headerRow + 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                if ($this->isEmptyRow($row)) {
                    continue;
                }
                $this->results['processed']++;
                try {
                    $this->processInventoryRow($row, $map, $i + 1);
                } catch (Exception $e) {
                    $this->results['errors'][] = 'Row ' . ($i + 1) . ': ' . $e->getMessage();
                    $this->results['skipped']++;
                }
            }
            $this->db->commit();
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function processInventoryRow(array $row, array $map, int $rowNumber): void {
        $sku = trim((string)($row[$map['sku']] ?? ''));
        $qtyRaw = $row[$map['quantity']] ?? null;
        $qty = is_numeric($qtyRaw) ? (int)$qtyRaw : null;
        if ($sku === '' || $qty === null || $qty <= 0) {
            $this->results['warnings'][] = "Row $rowNumber: Invalid SKU or quantity";
            $this->results['skipped']++;
            return;
        }
        $product = $this->findProductBySku($sku);
        if (!$product) {
            $this->results['warnings'][] = "Row $rowNumber: Product $sku not found";
            $this->results['skipped']++;
            return;
        }
        $location = $this->findProductLocation($product['product_id']);
        if (!$location) {
            $this->results['warnings'][] = "Row $rowNumber: Location not found for $sku";
            $this->results['skipped']++;
            return;
        }
        $added = $this->addStockToProduct($product['product_id'], $location, $qty);
        if ($added) {
            $this->results['stock_added']++;
        } else {
            $this->results['errors'][] = "Row $rowNumber: Failed to add stock for $sku";
            $this->results['skipped']++;
        }
    }

    private function findProductBySku(string $sku): ?array {
        $stmt = $this->db->prepare('SELECT product_id FROM products WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        return $product ?: null;
    }

    private function findProductLocation(int $productId): ?array {
        $stmt = $this->db->prepare('SELECT location_id, shelf_level FROM inventory WHERE product_id = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$productId]);
        $loc = $stmt->fetch(PDO::FETCH_ASSOC);
        return $loc ?: null;
    }

    private function addStockToProduct(int $productId, array $location, int $quantity): bool {
        $stockData = [
            'product_id' => $productId,
            'location_id' => $location['location_id'],
            'quantity' => $quantity,
            'shelf_level' => $location['shelf_level'],
            'subdivision_number' => null,
            'batch_number' => 'EXCEL-' . date('Ymd-Hi') . '-' . $productId,
            'received_at' => date('Y-m-d H:i:s'),
            'reference_type' => 'excel_import'
        ];
        return (bool)$this->inventoryModel->addStock($stockData, false);
    }

    private function findHeaderRow(array $rows): array {
        $skuPatterns = ['sku', 'cod', 'code', 'article', 'produs'];
        $qtyPatterns = ['quantity', 'qty', 'cantitate', 'stoc', 'stock', 'sold'];
        foreach ($rows as $index => $row) {
            $hasSku = false;
            $hasQty = false;
            foreach ($row as $cell) {
                $cellLower = strtolower(trim((string)$cell));
                if ($this->cellMatches($cellLower, $skuPatterns)) {
                    $hasSku = true;
                }
                if ($this->cellMatches($cellLower, $qtyPatterns)) {
                    $hasQty = true;
                }
            }
            if ($hasSku && $hasQty) {
                return ['row' => $index, 'headers' => $row];
            }
        }
        return ['row' => -1, 'headers' => []];
    }

    private function mapColumns(array $headers): array {
        $map = [];
        $skuPatterns = ['sku', 'cod', 'code', 'article', 'produs'];
        $qtyPatterns = ['quantity', 'qty', 'cantitate', 'stoc', 'stock', 'sold'];
        foreach ($headers as $idx => $header) {
            $headerLower = strtolower(trim((string)$header));
            if ($this->cellMatches($headerLower, $skuPatterns)) {
                $map['sku'] = $idx;
            } elseif ($this->cellMatches($headerLower, $qtyPatterns)) {
                $map['quantity'] = $idx;
            }
        }
        return $map;
    }

    private function cellMatches(string $cell, array $patterns): bool {
        foreach ($patterns as $pattern) {
            if (strpos($cell, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isEmptyRow(array $row): bool {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string)$cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
