<?php
// File: api/excel_import.php - Complete Excel Import with SmartBill Sync
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
require_once BASE_PATH . '/vendor/autoload.php'; // For PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ExcelImportHandler {
    private $db;
    private $smartBillService;
    private $results;
    
    public function __construct($database) {
        $this->db = $database;
        $this->results = [
            'success' => false,
            'processed' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'smartbill_synced' => 0,
            'errors' => [],
            'warnings' => [],
            'message' => ''
        ];
        
        // Initialize SmartBill service if available
        try {
            require_once BASE_PATH . '/models/SmartBillService.php';
            $this->smartBillService = new SmartBillService($database);
        } catch (Exception $e) {
            $this->results['warnings'][] = 'SmartBill service not available: ' . $e->getMessage();
        }
    }
    
    public function processUpload() {
        try {
            if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No file uploaded or upload error occurred');
            }
            
            $file = $_FILES['excel_file'];
            $tmpPath = $file['tmp_name'];
            
            // Validate file type
            $allowedTypes = ['xls', 'xlsx'];
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($fileExtension, $allowedTypes)) {
                throw new Exception('Invalid file type. Only .xls and .xlsx files are allowed.');
            }
            
            // Process the Excel file
            $this->processExcelFile($tmpPath);
            
            // Sync with SmartBill if enabled
            if ($this->smartBillService && $_POST['sync_smartbill'] === 'true') {
                $this->syncWithSmartBill();
            }
            
            $this->results['success'] = true;
            $this->results['message'] = "Import completed successfully. Processed: {$this->results['processed']}, Imported: {$this->results['imported']}, Updated: {$this->results['updated']}";
            
        } catch (Exception $e) {
            $this->results['success'] = false;
            $this->results['message'] = 'Import failed: ' . $e->getMessage();
            $this->results['errors'][] = $e->getMessage();
        }
        
        return $this->results;
    }
    
    private function processExcelFile($filePath) {
        $transactionStarted = false;
        
        try {
            // Load the spreadsheet
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            if (empty($rows)) {
                throw new Exception('Excel file is empty');
            }
            
            // Find header row - look through first 20 rows for headers
            $headerRow = $this->findHeaderRow($rows);
            if ($headerRow === -1) {
                throw new Exception('Nu s-au găsit coloanele necesare în fișier. Verificați formatul Excel.');
            }
            
            $headers = array_map(function($header) {
                return trim((string)($header ?? ''));
            }, $rows[$headerRow]);
            
            $headerMap = $this->mapHeaders($headers);
            
            if (empty($headerMap['sku']) && empty($headerMap['name'])) {
                // Show available headers for debugging
                $availableHeaders = array_filter($headers);
                $debugInfo = [
                    'found_headers' => $availableHeaders,
                    'header_row' => $headerRow,
                    'mapped_fields' => $headerMap
                ];
                
                throw new Exception('Excel file must contain at least SKU or Product Name columns. ' . 
                    'Available headers: ' . implode(', ', $availableHeaders) . '. ' .
                    'Header found at row: ' . ($headerRow + 1) . '. ' .
                    'Debug info: ' . json_encode($debugInfo));
            }
            
            // Start transaction only if not already in one
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $transactionStarted = true;
            }
            
            // Process data rows starting after header row
            for ($i = $headerRow + 1; $i < count($rows); $i++) {
                try {
                    $rowData = $rows[$i];
                    $productData = $this->extractProductData($rowData, $headerMap);
                    
                    if (empty($productData['sku']) && empty($productData['name'])) {
                        $this->results['skipped']++;
                        continue;
                    }
                    
                    $this->results['processed']++;
                    $this->processProductData($productData, $i + 1);
                    
                } catch (Exception $e) {
                    $this->results['errors'][] = "Row " . ($i + 1) . ": " . $e->getMessage();
                }
            }
            
            // Commit only if we started the transaction
            if ($transactionStarted) {
                $this->db->commit();
            }
            
        } catch (Exception $e) {
            // Rollback only if we started the transaction
            if ($transactionStarted && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Find the header row by looking for key column indicators
     * @param array $rows All rows from Excel
     * @return int Header row index, or -1 if not found
     */
    private function findHeaderRow($rows) {
        // Common Romanian header indicators
        $indicators = [
            'cod', 'produs', 'nume', 'sku', 'stoc', 'cantitate', 'pret', 'cost', 
            'gestiune', 'u.m.', 'um', 'sold', 'final', 'unitar'
        ];
        
        for ($i = 0; $i < min(20, count($rows)); $i++) {
            $row = $rows[$i];
            if (!$row || count($row) < 2) continue;
            
            $foundIndicators = 0;
            foreach ($row as $cell) {
                if (!$cell) continue;
                
                $cellLower = strtolower(trim((string)$cell));
                foreach ($indicators as $indicator) {
                    if (strpos($cellLower, $indicator) !== false) {
                        $foundIndicators++;
                        break;
                    }
                }
            }
            
            // If we found at least 2 indicators in this row, it's likely the header
            if ($foundIndicators >= 2) {
                return $i;
            }
        }
        
        return -1; // Header not found
    }
    
    private function mapHeaders($headers) {
        $map = [];
        
        // Extended Romanian/English header mappings
        $mappings = [
            'sku' => ['sku', 'cod', 'code', 'product_code', 'cod produs', 'cod_produs'],
            'name' => ['name', 'nume', 'product_name', 'nume produs', 'produs', 'denumire', 'nume_produs'],
            'description' => ['description', 'descriere', 'desc', 'detalii'],
            'category' => ['category', 'categorie', 'cat', 'gestiune', 'tip'],
            'quantity' => ['quantity', 'cantitate', 'qty', 'stock', 'stoc', 'stoc final', 'stoc_final', 'cant'],
            'price' => ['price', 'pret', 'cost', 'unit_price', 'pret_unitar', 'cost unitar', 'cost_unitar', 'pret unitar'],
            'min_stock_level' => ['min_stock', 'stoc_minim', 'minimum_stock', 'minim'],
            'unit_of_measure' => ['unit', 'unitate', 'um', 'u.m.', 'unit_of_measure', 'unitate_masura'],
            'supplier' => ['supplier', 'furnizor', 'vendor', 'prov'],
            'barcode' => ['barcode', 'cod_bare', 'ean', 'cod bare'],
            'weight' => ['weight', 'greutate', 'kg', 'masa'],
            'dimensions' => ['dimensions', 'dimensiuni', 'dim'],
            'total_value' => ['sold final', 'sold_final', 'total', 'valoare']
        ];
        
        foreach ($headers as $index => $header) {
            $header = strtolower(trim((string)($header ?? '')));
            
            if (empty($header)) {
                continue; // Skip empty headers
            }
            
            foreach ($mappings as $field => $variants) {
                foreach ($variants as $variant) {
                    // Exact match or contains match
                    if ($header === $variant || strpos($header, $variant) !== false) {
                        $map[$field] = $index;
                        break 2; // Break both loops
                    }
                }
            }
        }
        
        return $map;
    }
    
    private function extractProductData($rowData, $headerMap) {
        $productData = [];
        
        foreach ($headerMap as $field => $index) {
            if (isset($rowData[$index]) && $rowData[$index] !== null) {
                $value = trim((string)$rowData[$index]);
                
                // Skip empty values
                if ($value === '') {
                    continue;
                }
                
                // Handle different data types
                switch ($field) {
                    case 'quantity':
                    case 'min_stock_level':
                        // Handle Romanian number format (comma as decimal separator)
                        $cleanValue = str_replace(['.', ','], ['', '.'], $value);
                        $productData[$field] = max(0, intval(floatval($cleanValue)));
                        break;
                    case 'price':
                        // Handle Romanian price format (comma as decimal separator)
                        $cleanValue = str_replace(',', '.', $value);
                        $productData[$field] = max(0, floatval($cleanValue));
                        break;
                    case 'weight':
                        $cleanValue = str_replace(',', '.', $value);
                        $productData[$field] = floatval($cleanValue);
                        break;
                    case 'unit_of_measure':
                        // Map common Romanian units to standard units
                        $unitMap = [
                            'bucata' => 'pcs',
                            'bucati' => 'pcs', 
                            'buc' => 'pcs',
                            'litru' => 'l',
                            'litri' => 'l',
                            'kg' => 'kg',
                            'set' => 'set',
                            'metru' => 'm',
                            'metri' => 'm'
                        ];
                        $lowerValue = strtolower($value);
                        $productData[$field] = $unitMap[$lowerValue] ?? $value;
                        break;
                    default:
                        $productData[$field] = $value;
                }
            }
        }
        
        // Special handling for Romanian report format
        // If we have a combined product name with code (like "CODE123 - Product Name")
        if (!empty($productData['name']) && empty($productData['sku'])) {
            $nameParts = explode(' - ', $productData['name'], 2);
            if (count($nameParts) === 2) {
                $potentialSku = trim($nameParts[0]);
                $potentialName = trim($nameParts[1]);
                
                // If first part looks like a code (alphanumeric, no spaces)
                if (preg_match('/^[A-Z0-9\-\.]+$/', $potentialSku)) {
                    $productData['sku'] = $potentialSku;
                    $productData['name'] = $potentialName;
                    $productData['description'] = $productData['name']; // Use full original as description
                }
            }
        }
        
        // Set defaults for required fields
        if (empty($productData['sku']) && !empty($productData['name'])) {
            $productData['sku'] = $this->generateSku($productData['name']);
        }
        
        if (empty($productData['category'])) {
            $productData['category'] = 'Import Excel';
        }
        
        if (!isset($productData['quantity'])) {
            $productData['quantity'] = 0;
        }
        
        if (!isset($productData['price'])) {
            $productData['price'] = 0.00;
        }
        
        // Ensure all string fields are properly trimmed and not null
        foreach (['sku', 'name', 'description', 'category', 'unit_of_measure', 'supplier', 'barcode'] as $field) {
            if (isset($productData[$field])) {
                $productData[$field] = trim((string)$productData[$field]);
            }
        }
        
        return $productData;
    }
    
    private function processProductData($productData, $rowNumber) {
        // Check if product exists by SKU
        $existingProduct = $this->findProductBySku($productData['sku']);
        
        if ($existingProduct) {
            // Update existing product
            $this->updateProduct($existingProduct['product_id'], $productData);
            $this->results['updated']++;
        } else {
            // Create new product
            $productId = $this->createProduct($productData);
            if ($productId) {
                $this->results['imported']++;
            } else {
                throw new Exception("Failed to create product");
            }
        }
    }
    
    private function findProductBySku($sku) {
        $query = "SELECT * FROM products WHERE sku = ? LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$sku]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function createProduct($productData) {
        // Use only the columns that exist in your database
        $query = "INSERT INTO products (
            sku, name, description, category, quantity, price
        ) VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($query);
        $params = [
            trim((string)($productData['sku'] ?? '')),
            trim((string)($productData['name'] ?? '')),
            trim((string)($productData['description'] ?? '')),
            trim((string)($productData['category'] ?? 'Import Excel')),
            intval($productData['quantity'] ?? 0),
            floatval($productData['price'] ?? 0.00)
        ];
        
        if ($stmt->execute($params)) {
            return $this->db->lastInsertId();
        }
        
        return false;
    }
    
    private function updateProduct($productId, $productData) {
        if (empty($productData)) {
            return false;
        }
        
        $fields = [];
        $params = [];
        
        // Only update basic fields that exist in your database
        $allowedFields = ['name', 'description', 'category', 'quantity', 'price'];
        
        foreach ($allowedFields as $field) {
            if (isset($productData[$field])) {
                $fields[] = "{$field} = ?";
                if ($field === 'quantity') {
                    $params[] = intval($productData[$field]);
                } elseif ($field === 'price') {
                    $params[] = floatval($productData[$field]);
                } else {
                    $params[] = trim((string)$productData[$field]);
                }
            }
        }
        
        if (!empty($fields)) {
            $params[] = $productId;
            $query = "UPDATE products SET " . implode(', ', $fields) . " WHERE product_id = ?";
            $stmt = $this->db->prepare($query);
            return $stmt->execute($params);
        }
        
        return false;
    }
    
    private function generateSku($name) {
        $safeName = trim((string)($name ?? ''));
        $sku = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $safeName), 0, 8));
        $sku .= rand(100, 999);
        
        // Ensure uniqueness
        while ($this->findProductBySku($sku)) {
            $sku = substr($sku, 0, -3) . rand(100, 999);
        }
        
        return $sku;
    }
    
    private function syncWithSmartBill() {
        if (!$this->smartBillService) {
            $this->results['warnings'][] = 'SmartBill service not available for sync';
            return;
        }
        
        try {
            // Use the existing SmartBill sync functionality instead of direct method calls
            $syncResult = $this->smartBillService->syncProductsFromSmartBill(100);
            
            if ($syncResult['success']) {
                $this->results['smartbill_synced'] = $syncResult['updated'] ?? 0;
                $this->results['message'] .= " SmartBill sync: {$this->results['smartbill_synced']} products updated.";
            } else {
                $this->results['warnings'][] = 'SmartBill sync failed: ' . ($syncResult['message'] ?? 'Unknown error');
            }
            
        } catch (Exception $e) {
            $this->results['warnings'][] = 'SmartBill sync error: ' . $e->getMessage();
        }
    }
    
    private function updateProductSmartBillInfo($sku, $smartBillData) {
        $query = "UPDATE products SET 
                 smartbill_product_id = ?,
                 quantity = COALESCE(?, quantity),
                 price = COALESCE(?, price),
                 smartbill_synced_at = NOW()
                 WHERE sku = ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            $smartBillData['code'] ?? $sku,
            $smartBillData['quantity'] ?? null,
            $smartBillData['price'] ?? null,
            $sku
        ]);
    }
}

// Main execution
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method allowed');
    }
    
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();
    
    $importer = new ExcelImportHandler($db);
    $result = $importer->processUpload();
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'errors' => [$e->getMessage()]
    ]);
}
?>