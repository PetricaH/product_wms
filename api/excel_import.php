<?php
// File: api/excel_import_fixed.php - Clean Excel Import Handler
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

use PhpOffice\PhpSpreadsheet\IOFactory;

class ImprovedExcelImportHandler {
    private $db;
    private $results;
    
    public function __construct($database) {
        $this->db = $database;
        $this->results = [
            'success' => false,
            'processed' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'warnings' => [],
            'message' => '',
            'debug_info' => []
        ];
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
            
            // Check if this is preview mode
            $previewOnly = isset($_POST['preview_only']) && $_POST['preview_only'] === 'true';
            
            if ($previewOnly) {
                $this->processExcelFilePreview($tmpPath);
                $this->results['success'] = true;
                $this->results['message'] = 'Preview completed successfully';
            } else {
                // Process the Excel file with full import
                $this->processExcelFileImproved($tmpPath);
                $this->results['success'] = true;
                $this->results['message'] = "Import completed successfully. Processed: {$this->results['processed']}, Imported: {$this->results['imported']}, Updated: {$this->results['updated']}";
            }
            
        } catch (Exception $e) {
            $this->results['success'] = false;
            $this->results['message'] = 'Operation failed: ' . $e->getMessage();
            $this->results['errors'][] = $e->getMessage();
        }
        
        return $this->results;
    }
    
    private function processExcelFilePreview($filePath) {
        try {
            // Load the spreadsheet
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            if (empty($rows)) {
                throw new Exception('Excel file is empty');
            }
            
            // Debug: Show first few rows to understand structure
            $this->results['debug_info']['first_10_rows'] = array_slice($rows, 0, 10);
            
            // Find header row
            $headerInfo = $this->findHeaderRowImproved($rows);
            
            if ($headerInfo['row'] === -1) {
                throw new Exception('Could not find header row in Excel file');
            }
            
            $headerRow = $headerInfo['row'];
            $headers = $headerInfo['headers'];
            $headerMap = $this->mapHeadersImproved($headers);
            
            $this->results['debug_info']['header_row'] = $headerRow;
            $this->results['debug_info']['headers'] = $headers;
            $this->results['debug_info']['header_map'] = $headerMap;
            $this->results['debug_info']['total_rows'] = count($rows);
            $this->results['debug_info']['data_rows'] = count($rows) - $headerRow - 1;
            
            // Show sample data mapping
            if (count($rows) > $headerRow + 1) {
                $sampleRow = $rows[$headerRow + 1];
                $sampleProduct = $this->extractProductDataImproved($sampleRow, $headerMap);
                $this->results['debug_info']['sample_product'] = $sampleProduct;
            }
            
            // Validate mapping
            $requiredFields = ['sku', 'name'];
            $missingRequired = [];
            foreach ($requiredFields as $field) {
                if (!isset($headerMap[$field])) {
                    $missingRequired[] = $field;
                }
            }
            
            if (!empty($missingRequired)) {
                $this->results['warnings'][] = 'Missing required fields: ' . implode(', ', $missingRequired);
            }
            
            $this->results['processed'] = 0; // Preview mode
            
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    private function processExcelFileImproved($filePath) {
        $transactionStarted = false;
        
        try {
            // Load the spreadsheet
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            if (empty($rows)) {
                throw new Exception('Excel file is empty');
            }
            
            // Debug: Show first few rows to understand structure
            $this->results['debug_info']['first_10_rows'] = array_slice($rows, 0, 10);
            
            // Find header row with improved detection
            $headerInfo = $this->findHeaderRowImproved($rows);
            
            if ($headerInfo['row'] === -1) {
                $availableContent = [];
                for ($i = 0; $i < min(15, count($rows)); $i++) {
                    $availableContent[] = "Row $i: " . implode(' | ', array_filter($rows[$i]));
                }
                
                throw new Exception('Could not find header row. Available content: ' . implode('; ', $availableContent));
            }
            
            $headerRow = $headerInfo['row'];
            $headers = $headerInfo['headers'];
            $headerMap = $this->mapHeadersImproved($headers);
            
            $this->results['debug_info']['header_row'] = $headerRow;
            $this->results['debug_info']['headers'] = $headers;
            $this->results['debug_info']['header_map'] = $headerMap;
            
            if (empty($headerMap)) {
                throw new Exception('No recognizable columns found. Headers: ' . implode(', ', $headers));
            }
            
            // Start transaction
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $transactionStarted = true;
            }
            
            // Process data rows starting after header row
            for ($i = $headerRow + 1; $i < count($rows); $i++) {
                try {
                    $rowData = $rows[$i];
                    
                    // Skip empty rows
                    if ($this->isEmptyRow($rowData)) {
                        continue;
                    }
                    
                    $productData = $this->extractProductDataImproved($rowData, $headerMap);
                    
                    if (empty($productData)) {
                        $this->results['skipped']++;
                        continue;
                    }
                    
                    $this->results['processed']++;
                    $this->processProductDataImproved($productData, $i + 1);
                    
                } catch (Exception $e) {
                    $this->results['errors'][] = "Row " . ($i + 1) . ": " . $e->getMessage();
                }
            }
            
            // Commit transaction
            if ($transactionStarted) {
                $this->db->commit();
            }
            
        } catch (Exception $e) {
            if ($transactionStarted && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Optimized header row detection for the specific Excel format
     */
    private function findHeaderRowImproved($rows) {
        // Known exact headers for this Excel format
        $expectedHeaders = ['gestiune', 'produs', 'cod', 'u.m.', 'stoc final', 'sold final', 'stoc minim'];
        
        // First, try to find the exact header row (should be row 0)
        for ($i = 0; $i < min(10, count($rows)); $i++) {
            $row = $rows[$i];
            if (!$row || count($row) < 5) continue;
            
            $cleanRow = [];
            $exactMatches = 0;
            
            foreach ($row as $cell) {
                $cellValue = trim((string)($cell ?? ''));
                $cleanRow[] = $cellValue;
                
                $cellLower = strtolower($cellValue);
                if (in_array($cellLower, $expectedHeaders)) {
                    $exactMatches++;
                }
            }
            
            // If we found at least 5 exact matches, this is our header row
            if ($exactMatches >= 5) {
                return [
                    'row' => $i,
                    'headers' => $cleanRow,
                    'match_count' => $exactMatches
                ];
            }
        }
        
        // Fallback: look for partial matches with key Romanian patterns
        $patterns = [
            'gestiune', 'produs', 'cod', 'u.m.', 'stoc', 'sold', 'minim',
            'GESTIUNE', 'PRODUS', 'COD', 'U.M.', 'STOC', 'SOLD', 'MINIM'
        ];
        
        for ($i = 0; $i < min(15, count($rows)); $i++) {
            $row = $rows[$i];
            if (!$row || count($row) < 3) continue;
            
            $matchCount = 0;
            $cleanRow = [];
            
            foreach ($row as $cell) {
                $cellValue = trim((string)($cell ?? ''));
                $cleanRow[] = $cellValue;
                
                if (empty($cellValue)) continue;
                
                foreach ($patterns as $pattern) {
                    if (stripos($cellValue, $pattern) !== false) {
                        $matchCount++;
                        break;
                    }
                }
            }
            
            // If we found at least 4 matches, this is likely the header
            if ($matchCount >= 4) {
                return [
                    'row' => $i,
                    'headers' => $cleanRow,
                    'match_count' => $matchCount
                ];
            }
        }
        
        return ['row' => -1, 'headers' => [], 'match_count' => 0];
    }
    
    /**
     * Optimized header mapping for the specific Excel format
     */
    private function mapHeadersImproved($headers) {
        $map = [];
        
        // Exact mappings for the specific Excel format we analyzed
        $exactMappings = [
            'gestiune' => 'category',
            'produs' => 'name', 
            'cod' => 'sku',
            'u.m.' => 'unit_of_measure',
            'stoc final' => 'quantity',
            'sold final' => 'total_value',
            'stoc minim' => 'min_stock_level'
        ];
        
        // Enhanced mappings with fallbacks for variations
        $fallbackMappings = [
            'sku' => [
                'cod', 'code', 'sku', 'cod produs', 'cod_produs', 'product_code'
            ],
            'name' => [
                'produs', 'product', 'nume', 'name', 'denumire', 'nume produs', 'nume_produs', 'product_name'
            ],
            'category' => [
                'gestiune', 'category', 'categorie', 'tip', 'management'
            ],
            'quantity' => [
                'stoc final', 'stoc_final', 'stoc', 'stock', 'cantitate', 'quantity', 'qty'
            ],
            'unit_of_measure' => [
                'u.m.', 'um', 'u.m', 'unit', 'unitate', 'unit_of_measure', 'unitate_masura'
            ],
            'total_value' => [
                'sold final', 'sold_final', 'sold', 'valoare', 'value', 'total'
            ],
            'min_stock_level' => [
                'stoc minim', 'stoc_minim', 'minimum', 'minim'
            ],
            'location_id' => [
                'location_id', 'locatie', 'location', 'loc', 'location code', 'locatie_id'
            ],
            'subdivision_number' => [
                'subdivision', 'subdivision_number', 'raft', 'compartiment', 'slot'
            ],
            'shelf_level' => [
                'shelf_level', 'nivel', 'level', 'nivel raft', 'raft nivel'
            ],
            'batch_number' => [
                'batch', 'batch_number', 'batch no', 'batch_no', 'cod lot', 'nr lot'
            ],
            'lot_number' => [
                'lot', 'lot_number', 'numar lot', 'nr lot'
            ],
            'expiry_date' => [
                'expiry_date', 'expirare', 'data expirare', 'exp date', 'exp', 'expiration'
            ]
        ];
        
        // First try exact mappings
        foreach ($headers as $index => $header) {
            $headerLower = strtolower(trim((string)($header ?? '')));
            
            if (isset($exactMappings[$headerLower])) {
                $map[$exactMappings[$headerLower]] = $index;
                continue;
            }
        }
        
        // Then try fallback mappings for any unmapped fields
        foreach ($headers as $index => $header) {
            $headerLower = strtolower(trim((string)($header ?? '')));
            
            if (empty($headerLower)) {
                continue;
            }
            
            foreach ($fallbackMappings as $field => $variants) {
                // Skip if already mapped
                if (isset($map[$field])) {
                    continue;
                }
                
                foreach ($variants as $variant) {
                    if ($headerLower === $variant || strpos($headerLower, $variant) !== false) {
                        $map[$field] = $index;
                        break 2;
                    }
                }
            }
        }
        
        return $map;
    }
    
    /**
     * Improved data extraction optimized for the specific Excel format
     */
    private function extractProductDataImproved($rowData, $headerMap) {
        $productData = [];
        
        foreach ($headerMap as $field => $index) {
            if (!isset($rowData[$index]) || $rowData[$index] === null) {
                continue;
            }
            
            $value = trim((string)$rowData[$index]);
            
            // Skip empty values, NULL, or dash placeholders
            if ($value === '' || $value === '-' || $value === 'NULL' || $value === 'null') {
                continue;
            }
            
            switch ($field) {
                case 'sku':
                    $productData['sku'] = $value;
                    break;
                    
                case 'name':
                    $productData['name'] = $value;
                    // Also use as description if not set elsewhere
                    if (!isset($productData['description'])) {
                        $productData['description'] = $value;
                    }
                    break;
                    
                case 'category':
                    // Clean up category value
                    $cleanCategory = trim($value);
                    if ($cleanCategory !== 'Total') { // Skip summary rows
                        $productData['category'] = $cleanCategory;
                    }
                    break;
                    
                case 'unit_of_measure':
                    $productData['unit_of_measure'] = $value;
                    break;
                    
                case 'quantity':
                    // Handle Romanian number format and ensure integer
                    $cleanValue = str_replace(['.', ','], ['', '.'], $value);
                    $numericValue = floatval($cleanValue);
                    $productData['quantity'] = max(0, intval($numericValue));
                    break;
                    
                case 'min_stock_level':
                    // Handle Romanian number format
                    $cleanValue = str_replace(['.', ','], ['', '.'], $value);
                    $numericValue = floatval($cleanValue);
                    $productData['min_stock_level'] = max(0, intval($numericValue));
                    break;

                case 'total_value':
                    // Handle Romanian currency format - convert to unit price if quantity exists
                    $cleanValue = str_replace(['.', ','], ['', '.'], $value);
                    $totalValue = max(0, floatval($cleanValue));

                    // Try to calculate unit price if we have quantity
                    if (isset($productData['quantity']) && $productData['quantity'] > 0) {
                        $productData['price'] = round($totalValue / $productData['quantity'], 2);
                    } else {
                        $productData['price'] = $totalValue;
                    }
                    break;

                case 'location_id':
                    $productData['location_id'] = is_numeric($value) ? intval($value) : null;
                    break;

                case 'subdivision_number':
                    $productData['subdivision_number'] = is_numeric($value) ? intval($value) : null;
                    break;

                case 'shelf_level':
                    $level = trim((string)$value);
                    $productData['shelf_level'] = $level !== '' ? $level : null;
                    break;

                case 'batch_number':
                    $productData['batch_number'] = $value;
                    break;

                case 'lot_number':
                    $productData['lot_number'] = $value;
                    break;

                case 'expiry_date':
                    $ts = strtotime($value);
                    if ($ts !== false) {
                        $productData['expiry_date'] = date('Y-m-d', $ts);
                    }
                    break;
            }
        }
        
        // Skip if this looks like a summary row
        if (isset($productData['category']) && $productData['category'] === 'Total') {
            return null;
        }
        
        // Validate minimum required data
        if (empty($productData['sku']) && empty($productData['name'])) {
            return null;
        }
        
        // Set intelligent defaults
        if (!isset($productData['category']) || empty($productData['category'])) {
            $productData['category'] = 'Import Excel';
        }
        
        if (!isset($productData['quantity'])) {
            $productData['quantity'] = 0;
        }
        
        if (!isset($productData['price'])) {
            $productData['price'] = 0.00;
        }
        
        if (!isset($productData['min_stock_level'])) {
            $productData['min_stock_level'] = 0;
        }
        
        // Clean and validate SKU
        if (isset($productData['sku'])) {
            $productData['sku'] = trim($productData['sku']);
        }
        
        // Clean and validate name
        if (isset($productData['name'])) {
            $productData['name'] = trim($productData['name']);
        }
        
        return $productData;
    }
    
    /**
     * Process a product row using SKU as the sole identifier
     */
    private function processProductDataImproved($productData, $rowNumber) {
        if (empty($productData['sku'])) {
            $this->results['warnings'][] = "Row $rowNumber: missing SKU, skipped";
            $this->results['skipped']++;
            return;
        }

        $existingProduct = $this->findProductBySku($productData['sku']);

        if ($existingProduct) {
            // Update existing product
            $productId = (int)$existingProduct['product_id'];
            $this->updateProductImproved($productId, $productData, $existingProduct);
            $this->results['updated']++;
        } else {
            // Create new product
            $productId = $this->createProductImproved($productData);
            if ($productId) {
                $this->results['imported']++;
            } else {
                throw new Exception("Failed to create product for row $rowNumber");
            }
        }

        // Ensure inventory reflects imported quantity and location
        $this->updateInventoryRecord($productId, $productData);
    }

    private function findProductBySku($sku) {
        $query = "SELECT * FROM products WHERE sku = ? LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$sku]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getProductNameById(int $productId): ?string {
        $stmt = $this->db->prepare("SELECT name FROM products WHERE product_id = ? LIMIT 1");
        $stmt->execute([$productId]);
        $name = $stmt->fetchColumn();
        return $name !== false ? $name : null;
    }
    
    private function updateProductImproved($productId, $productData, $existingProduct) {
        $updateFields = [];
        $params = [];
        
        // Fields to check for updates
        $fieldsToUpdate = [
            'name' => 'string',
            'category' => 'string', 
            'quantity' => 'int',
            'price' => 'float',
            'min_stock_level' => 'int',
            'unit_of_measure' => 'string'
        ];
        
        foreach ($fieldsToUpdate as $field => $type) {
            if (!isset($productData[$field])) {
                continue;
            }
            
            $newValue = $productData[$field];
            $existingValue = $existingProduct[$field] ?? null;
            
            // Convert values based on type for comparison
            switch ($type) {
                case 'int':
                    $newValue = intval($newValue);
                    $existingValue = intval($existingValue);
                    break;
                case 'float':
                    $newValue = floatval($newValue);
                    $existingValue = floatval($existingValue);
                    break;
                case 'string':
                    $newValue = trim((string)$newValue);
                    $existingValue = trim((string)$existingValue);
                    break;
            }
            
            // Only update if values are different
            if ($newValue != $existingValue) {
                $updateFields[] = "$field = ?";
                $params[] = $newValue;
            }
        }
        
        if (empty($updateFields)) {
            return; // Nothing to update
        }
        
        $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
        $params[] = $productId;
        
        $query = "UPDATE products SET " . implode(', ', $updateFields) . " WHERE product_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
    }
    
    private function createProductImproved($productData) {
        // Check which columns exist in the products table
        $availableColumns = $this->getTableColumns();
        
        // Base required columns
        $baseColumns = ['sku', 'name', 'description', 'category', 'quantity', 'price'];
        $baseValues = [
            trim((string)($productData['sku'] ?? '')),
            trim((string)($productData['name'] ?? '')),
            trim((string)($productData['description'] ?? $productData['name'] ?? '')),
            trim((string)($productData['category'] ?? 'Import Excel')),
            intval($productData['quantity'] ?? 0),
            floatval($productData['price'] ?? 0.00)
        ];
        
        // Optional columns (add if they exist in table)
        $optionalColumns = [];
        $optionalValues = [];
        
        if (in_array('min_stock_level', $availableColumns) && isset($productData['min_stock_level'])) {
            $optionalColumns[] = 'min_stock_level';
            $optionalValues[] = intval($productData['min_stock_level']);
        }
        
        if (in_array('unit_of_measure', $availableColumns) && isset($productData['unit_of_measure'])) {
            $optionalColumns[] = 'unit_of_measure';
            $optionalValues[] = trim((string)$productData['unit_of_measure']);
        }
        
        // Timestamps
        $timestampColumns = ['created_at', 'updated_at'];
        $timestampValues = ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP'];
        
        // Combine all columns and values
        $allColumns = array_merge($baseColumns, $optionalColumns, $timestampColumns);
        $allValues = array_merge($baseValues, $optionalValues, $timestampValues);
        
        // Build the query
        $columnsList = implode(', ', $allColumns);
        $placeholders = array_fill(0, count($baseValues) + count($optionalValues), '?');
        $placeholders = array_merge($placeholders, $timestampValues);
        $placeholdersList = implode(', ', $placeholders);
        
        $query = "INSERT INTO products ($columnsList) VALUES ($placeholdersList)";
        
        $stmt = $this->db->prepare($query);
        $executeValues = array_merge($baseValues, $optionalValues);
        $stmt->execute($executeValues);

        return $this->db->lastInsertId();
    }

    private function getProductLocationData(int $productId): ?array {
        // Try dedicated subdivision first
        $stmt = $this->db->prepare(
            "SELECT location_id, level_number, subdivision_number
             FROM location_subdivisions
             WHERE dedicated_product_id = ?
             LIMIT 1"
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        // Fallback to level assignment
        $stmt = $this->db->prepare(
            "SELECT location_id, level_number
             FROM location_level_settings
             WHERE dedicated_product_id = ?
             LIMIT 1"
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['subdivision_number'] = null;
            return $row;
        }

        // Last resort: use existing inventory record if available
        $stmt = $this->db->prepare(
            "SELECT location_id, shelf_level, subdivision_number
             FROM inventory
             WHERE product_id = ?
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'location_id' => $row['location_id'],
                'subdivision_number' => $row['subdivision_number'],
                'shelf_level' => $row['shelf_level'],
            ];
        }

        return null;
    }

    private function updateInventoryRecord(int $productId, array $productData): void {
        try {
            $quantity = intval($productData['quantity'] ?? 0);
            $productName = $productData['name'] ?? $this->getProductNameById($productId) ?? 'unknown';
            if ($quantity <= 0) {
                // Remove any existing inventory when quantity is zero
                $del = $this->db->prepare("DELETE FROM inventory WHERE product_id = ?");
                $del->execute([$productId]);
                return;
            }

            $locationId = isset($productData['location_id']) ? intval($productData['location_id']) : null;
            $subdivision = isset($productData['subdivision_number']) ? intval($productData['subdivision_number']) : null;
            $shelfLevel = $productData['shelf_level'] ?? null;

            if ($locationId === null || $subdivision === null || $shelfLevel === null) {
                $locData = $this->getProductLocationData($productId);
                if ($locData) {
                    if ($locationId === null && isset($locData['location_id'])) {
                        $locationId = (int)$locData['location_id'];
                    }
                    if ($subdivision === null && array_key_exists('subdivision_number', $locData)) {
                        $subdivision = $locData['subdivision_number'] !== null ? (int)$locData['subdivision_number'] : null;
                    }
                    if ($shelfLevel === null) {
                        if (isset($locData['shelf_level']) && $locData['shelf_level'] !== null) {
                            $shelfLevel = $locData['shelf_level'];
                        } elseif (isset($locData['level_number'])) {
                            require_once BASE_PATH . '/models/LocationLevelSettings.php';
                            $lls = new LocationLevelSettings($this->db);
                            $levelName = $lls->getLevelNameByNumber((int)$locData['location_id'], (int)$locData['level_number']);
                            if ($levelName) {
                                $shelfLevel = $levelName;
                            }
                        }
                    }
                }
            }

            // Remove existing inventory after location resolution
            $del = $this->db->prepare("DELETE FROM inventory WHERE product_id = ?");
            $del->execute([$productId]);

            // Skip inventory creation if essential data like location or shelf level is missing
            if ($locationId === null || $shelfLevel === null) {
                $this->results['warnings'][] = 'Skipped inventory for product ' . $productId . ' (' . $productName . ') due to missing location or shelf level';
                return;
            }

            require_once BASE_PATH . '/models/Inventory.php';
            $inventory = new Inventory($this->db);

            $data = [
                'product_id' => $productId,
                'location_id' => $locationId,
                'subdivision_number' => $subdivision,
                'shelf_level' => $shelfLevel,
                'quantity' => $quantity,
            ];

            if (!empty($productData['batch_number'])) {
                $data['batch_number'] = $productData['batch_number'];
            }
            if (!empty($productData['lot_number'])) {
                $data['lot_number'] = $productData['lot_number'];
            }
            if (!empty($productData['expiry_date'])) {
                $data['expiry_date'] = $productData['expiry_date'];
            }

            // Use outer transaction, so disable internal transaction
            $inventory->addStock($data, false);

        } catch (Exception $e) {
            $productName = $productData['name'] ?? $this->getProductNameById($productId) ?? 'unknown';
            $this->results['warnings'][] = 'Inventory update failed for product ' . $productId . ' (' . $productName . '): ' . $e->getMessage();
        }
    }
    
    private function getTableColumns() {
        static $columns = null;
        
        if ($columns === null) {
            try {
                $stmt = $this->db->query("DESCRIBE products");
                $columns = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $columns[] = $row['Field'];
                }
            } catch (Exception $e) {
                // Fallback to basic columns if describe fails
                $columns = ['product_id', 'sku', 'name', 'description', 'category', 'quantity', 'price', 'created_at', 'updated_at'];
            }
        }
        
        return $columns;
    }
    
    private function isEmptyRow($rowData) {
        if (!$rowData) return true;
        
        foreach ($rowData as $cell) {
            if ($cell !== null && trim((string)$cell) !== '') {
                return false;
            }
        }
        return true;
    }
}

// Usage
try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();
    
    $importer = new ImprovedExcelImportHandler($db);
    $result = $importer->processUpload();
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'System error: ' . $e->getMessage(),
        'errors' => [$e->getMessage()]
    ]);
}
?>