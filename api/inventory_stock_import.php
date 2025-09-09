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
        'errors' => [],
        'debug_info' => [] // Add debug info
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
        
        // DEBUG: Show file structure
        $this->results['debug_info']['total_rows'] = count($rows);
        $this->results['debug_info']['first_5_rows'] = array_slice($rows, 0, 5);
        
        $headerInfo = $this->findHeaderRow($rows);
        if ($headerInfo['row'] === -1) {
            $available = [];
            for ($i = 0; $i < min(3, count($rows)); $i++) {
                $available[] = "Row $i: " . implode(' | ', array_filter($rows[$i]));
            }
            throw new Exception('Could not find header row. Available: ' . implode('; ', $available));
        }
        
        $headerRow = $headerInfo['row'];
        $map = $this->mapColumns($headerInfo['headers']);
        
        // DEBUG: Show mapping with actual header names
        $this->results['debug_info']['header_row'] = $headerRow;
        $this->results['debug_info']['headers'] = $headerInfo['headers'];
        $this->results['debug_info']['column_mapping'] = [
            'sku_column' => $map['sku'] ?? 'NOT_FOUND',
            'sku_header' => isset($map['sku']) ? $headerInfo['headers'][$map['sku']] : 'NOT_FOUND',
            'quantity_column' => $map['quantity'] ?? 'NOT_FOUND', 
            'quantity_header' => isset($map['quantity']) ? $headerInfo['headers'][$map['quantity']] : 'NOT_FOUND',
            'full_mapping' => $map
        ];
        
        if (!isset($map['sku']) || !isset($map['quantity'])) {
            $missing = [];
            if (!isset($map['sku'])) $missing[] = 'SKU';
            if (!isset($map['quantity'])) $missing[] = 'Quantity';
            throw new Exception('Required columns not found: ' . implode(', ', $missing) . '. Headers: ' . implode(', ', $headerInfo['headers']));
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
        // Extract data with better parsing
        $sku = trim((string)($row[$map['sku']] ?? ''));
        $qtyRaw = $row[$map['quantity']] ?? null;
        $qty = $this->parseQuantity($qtyRaw);
        
        // DEBUG: Show extraction for first few rows
        if ($rowNumber <= 5) {
            $this->results['debug_info']['row_' . $rowNumber] = [
                'sku_raw' => $row[$map['sku']] ?? 'NOT_FOUND',
                'sku_clean' => $sku,
                'qty_raw' => $qtyRaw,
                'qty_parsed' => $qty,
                'full_row' => array_slice($row, 0, 7)
            ];
        }
        
        if ($sku === '') {
            $this->results['warnings'][] = "Row $rowNumber: Empty SKU (from column {$map['sku']})";
            $this->results['skipped']++;
            return;
        }
        
        if ($qty === null || $qty <= 0) {
            $this->results['warnings'][] = "Row $rowNumber: Invalid quantity for SKU '$sku' (parsed: $qty from '$qtyRaw')";
            $this->results['skipped']++;
            return;
        }
        
        $product = $this->findProductBySku($sku);
        if (!$product) {
            $this->results['warnings'][] = "Row $rowNumber: Product '$sku' not found in database";
            $this->results['skipped']++;
            return;
        }
        
        $location = $this->findProductLocation($product['product_id']);
        if (!$location) {
            $this->results['warnings'][] = "Row $rowNumber: No existing location found for product '$sku'";
            $this->results['skipped']++;
            return;
        }
        
        $added = $this->addStockToProduct($product['product_id'], $location, $qty, $sku);
        if ($added) {
            $this->results['stock_added']++;
            $this->results['warnings'][] = "Row $rowNumber: Added $qty units to '$sku'";
        } else {
            $this->results['errors'][] = "Row $rowNumber: Failed to add stock for '$sku'";
            $this->results['skipped']++;
        }
    }

    private function parseQuantity($value) {
        if ($value === null || $value === '') {
            return null;
        }
        
        if (is_numeric($value)) {
            return floatval($value);
        }
        
        // Handle string values and Romanian number formats
        $stringValue = (string)$value;
        $cleanValue = preg_replace('/[^\d\.,\-]/', '', $stringValue);
        
        if (empty($cleanValue)) {
            return null;
        }
        
        // Convert Romanian format to standard format
        if (strpos($cleanValue, ',') !== false && strpos($cleanValue, '.') !== false) {
            // Both comma and dot present
            if (strrpos($cleanValue, ',') > strrpos($cleanValue, '.')) {
                // Romanian format: 1.234,56 -> 1234.56
                $cleanValue = str_replace('.', '', $cleanValue);
                $cleanValue = str_replace(',', '.', $cleanValue);
            } else {
                // US format: 1,234.56 -> 1234.56
                $cleanValue = str_replace(',', '', $cleanValue);
            }
        } elseif (strpos($cleanValue, ',') !== false) {
            // Only comma - assume decimal separator
            $cleanValue = str_replace(',', '.', $cleanValue);
        }
        
        return floatval($cleanValue);
    }

    private function findProductBySku(string $sku): ?array {
        $stmt = $this->db->prepare('SELECT product_id, name FROM products WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        return $product ?: null;
    }

    private function findProductLocation(int $productId): ?array {
    // First try: Look for existing inventory with proper level/subdivision
    $stmt = $this->db->prepare('
        SELECT location_id, shelf_level, subdivision_number 
        FROM inventory 
        WHERE product_id = ? AND quantity > 0 
        ORDER BY updated_at DESC LIMIT 1
    ');
    $stmt->execute([$productId]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($location) {
        return $location;
    }
    
    // Second try: Look in location_subdivisions for assigned location with proper level/subdivision
    $stmt = $this->db->prepare('
        SELECT 
            ls.location_id, 
            ls.subdivision_number,
            lls.level_name as shelf_level
        FROM location_subdivisions ls
        LEFT JOIN location_level_settings lls 
            ON ls.location_id = lls.location_id 
            AND ls.level_number = lls.level_number
        WHERE ls.dedicated_product_id = ? 
        LIMIT 1
    ');
    $stmt->execute([$productId]);
    $assignedLocation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($assignedLocation) {
        return [
            'location_id' => $assignedLocation['location_id'],
            'shelf_level' => $assignedLocation['shelf_level'] ?: 'middle',
            'subdivision_number' => $assignedLocation['subdivision_number']
        ];
    }
    
    return null;
}
    private function addStockToProduct(int $productId, array $location, float $quantity, string $sku): bool {
    $stockData = [
        'product_id' => $productId,
        'location_id' => $location['location_id'],
        'quantity' => (int)$quantity,
        'shelf_level' => $location['shelf_level'],
        'subdivision_number' => $location['subdivision_number'] ?? null,  // Use actual subdivision
        'batch_number' => 'EXCEL-' . date('Ymd-Hi') . '-' . $productId,
        'received_at' => date('Y-m-d H:i:s'),
        'reference_type' => 'excel_import',
        'notes' => "Excel import for SKU: $sku"
    ];
    return (bool)$this->inventoryModel->addStock($stockData, false);
}

    private function findHeaderRow(array $rows): array {
        // More specific patterns for your Romanian Excel format
        $skuPatterns = [
            'cod',           // Exact match for "Cod"
            'sku', 'code', 'article', 'produs'
        ];
        
        $qtyPatterns = [
            'stoc final',    // Exact match for "Stoc final"  
            'sold final',    // Alternative
            'stoc', 'cantitate', 'quantity', 'qty', 'stock', 'sold'
        ];
        
        foreach ($rows as $index => $row) {
            if ($index > 15) break; // Don't search too far
            
            $hasSku = false;
            $hasQty = false;
            
            foreach ($row as $cell) {
                $cellLower = strtolower(trim((string)$cell));
                
                if (!$hasSku && $this->cellMatches($cellLower, $skuPatterns)) {
                    $hasSku = true;
                }
                if (!$hasQty && $this->cellMatches($cellLower, $qtyPatterns)) {
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
        
        $map['sku'] = 2;      // Force Column 2 ("Cod") for SKU
        $map['quantity'] = 4; // Force Column 4 ("Stoc final") for quantity
        
        return $map;
    }

    private function cellMatches(string $cell, array $patterns): bool {
        foreach ($patterns as $pattern) {
            // Try exact match first, then contains
            if ($cell === $pattern || strpos($cell, $pattern) !== false) {
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