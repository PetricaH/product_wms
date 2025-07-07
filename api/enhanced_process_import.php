/**
     * Process a single import record
     */
    public function processImport($importId) {
        try {
            $this->db->beginTransaction();
            
            // Get the import record
            $import = $this->getImportRecord($importId);
            if (!$import) {
                throw new Exception("Import record not found or already processed");
            }

            // Update status to processing
            $this->updateImportStatus($importId, 'processing');

            // Parse and validate JSON data
            $jsonData = $this->parseAndValidateJSON($import['json_data']);
            
            // Extract and validate required data
            $clientInfo = $this->validateClientInfo($jsonData['client_info'] ?? []);
            $invoiceInfo = $this->validateInvoiceInfo($jsonData['invoice_info'] ?? []);
            
            // Handle missing products scenario
            $products = $this->validateAndProcessProducts($invoiceInfo['ordered_products'] ?? []);
            
            if (empty($products)) {
                throw new Exception("No products found in order");
            }

            // Generate unique order number
            $orderNumber = $this->generateOrderNumber();
            
            // Determine priority based on invoice value or customer
            $priority = $this->determinePriority($import['total_value'], $import['contact_person_name']);
            
            // Create the main order
            $orderId = $this->createOrder($orderNumber, $import, $clientInfo, $priority);
            
            // Process order items
            $itemResults = $this->processOrderItems($orderId, $products);
            
            // Update import with success
            $this->updateImportSuccess($importId, $orderId, $itemResults);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'items_processed' => $itemResults['processed'],
                'items_skipped' => $itemResults['skipped'],
                'errors' => $this->errors,
                'warnings' => $this->warnings,
                'debug_info' => $this->debugInfo
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            $this->updateImportFailure($importId, $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'errors' => $this->errors,
                'warnings' => $this->warnings,
                'debug_info' => $this->debugInfo
            ];
        }
    }<?php
// File: api/enhanced_process_import.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Bootstrap and Config
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

class ImportProcessor {
    private $db;
    private $errors = [];
    private $warnings = [];
    private $debugInfo = [];

    public function __construct($database) {
        $this->db = $database;
    }

    /**
     * Process a single import record
     */
    public function processImport($importId) {
        try {
            $this->db->beginTransaction();
            
            // Get the import record
            $import = $this->getImportRecord($importId);
            if (!$import) {
                throw new Exception("Import record not found or already processed");
            }

            // Update status to processing
            $this->updateImportStatus($importId, 'processing');

            // Parse and validate JSON data
            $jsonData = $this->parseAndValidateJSON($import['json_data']);
            
            // Extract and validate required data
            $clientInfo = $this->validateClientInfo($jsonData['client_info'] ?? []);
            $invoiceInfo = $this->validateInvoiceInfo($jsonData['invoice_info'] ?? []);
            
            // Handle missing products scenario
            $products = $this->validateAndProcessProducts($invoiceInfo['ordered_products'] ?? []);
            
            if (empty($products)) {
                throw new Exception("No products found in order");
            }

            // Generate unique order number
            $orderNumber = $this->generateOrderNumber();
            
            // Determine priority based on invoice value or customer
            $priority = $this->determinePriority($import['total_value'], $import['contact_person_name']);
            
            // Create the main order
            $orderId = $this->createOrder($orderNumber, $import, $clientInfo, $priority);
            
            // Process order items
            $itemResults = $this->processOrderItems($orderId, $products);
            
            // Update import with success
            $this->updateImportSuccess($importId, $orderId, $itemResults);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'items_processed' => $itemResults['processed'],
                'items_skipped' => $itemResults['skipped'],
                'errors' => $this->errors,
                'warnings' => $this->warnings,
                'debug_info' => $this->debugInfo
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            $this->updateImportFailure($importId, $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'errors' => $this->errors,
                'warnings' => $this->warnings,
                'debug_info' => $this->debugInfo
            ];
        }
    }

    /**
     * Get import record
     */
    private function getImportRecord($importId) {
        $query = "SELECT * FROM order_imports WHERE id = :import_id AND processing_status = 'pending'";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':import_id', $importId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Parse and validate JSON data
     */
    private function parseAndValidateJSON($jsonString) {
        $data = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON data in import record');
        }
        
        $this->debugInfo['json_structure'] = array_keys($data);
        return $data;
    }

    /**
     * Validate client information
     */
    private function validateClientInfo($clientInfo) {
        return [
            'name' => $clientInfo['name'] ?? '',
            'email' => $this->validateEmail($clientInfo['email'] ?? ''),
            'phone' => $clientInfo['phone'] ?? '',
            'address' => $clientInfo['address'] ?? ''
        ];
    }

    /**
     * Validate invoice information
     */
    private function validateInvoiceInfo($invoiceInfo) {
        return [
            'number' => $invoiceInfo['number'] ?? '',
            'date' => $invoiceInfo['date'] ?? '',
            'total' => floatval($invoiceInfo['total'] ?? 0),
            'ordered_products' => $invoiceInfo['ordered_products'] ?? []
        ];
    }

    /**
     * Validate and process products
     */
    private function validateAndProcessProducts($products) {
        if (empty($products) || !is_array($products)) {
            return [];
        }

        $validProducts = [];
        foreach ($products as $product) {
            if (isset($product['name']) && isset($product['quantity'])) {
                $validProducts[] = [
                    'name' => $product['name'],
                    'code' => $product['code'] ?? $product['sku'] ?? $product['product_code'] ?? '', // Try multiple possible SKU fields
                    'quantity' => floatval($product['quantity']),
                    'unit_price' => floatval($product['unit_price'] ?? $product['price'] ?? 0),
                    'total_price' => floatval($product['total_price'] ?? 0)
                ];
            }
        }

        return $validProducts;
    }

    /**
     * Create order in database
     */
    private function createOrder($orderNumber, $import, $clientInfo, $priority) {
        $query = "
            INSERT INTO orders (
                order_number, customer_name, customer_email, 
                shipping_address, order_date, status, priority, 
                total_value, notes, created_by, type
            ) VALUES (
                :order_number, :customer_name, :customer_email,
                :shipping_address, CURRENT_TIMESTAMP, 'pending', :priority,
                :total_value, :notes, 1, 'inbound'
            )
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':order_number' => $orderNumber,
            ':customer_name' => $import['contact_person_name'] . ($import['company_name'] ? ' (' . $import['company_name'] . ')' : ''),
            ':customer_email' => $this->validateEmail($import['contact_email']),
            ':shipping_address' => $this->buildShippingAddress($import),
            ':priority' => $priority,
            ':total_value' => $import['total_value'],
            ':notes' => 'Import automat din factură: ' . $import['invoice_number']
        ]);
        
        return $this->db->lastInsertId();
    }

    /**
     * Process order items
     */
    private function processOrderItems($orderId, $products) {
        $processed = 0;
        $skipped = 0;

        foreach ($products as $product) {
            try {
                // Try to find matching product in warehouse by SKU first, then name
                $warehouseProduct = $this->findWarehouseProduct($product['code'], $product['name']);
                
                if ($warehouseProduct) {
                    $this->addOrderItem($orderId, $warehouseProduct, $product);
                    $processed++;
                } else {
                    $this->warnings[] = "Product not found in warehouse: " . $product['name'] . 
                                       ($product['code'] ? " (SKU: " . $product['code'] . ")" : "");
                    $skipped++;
                }
            } catch (Exception $e) {
                $this->errors[] = "Error processing product {$product['name']}: " . $e->getMessage();
                $skipped++;
            }
        }

        return ['processed' => $processed, 'skipped' => $skipped];
    }

    /**
     * Find product in warehouse by SKU
     */
    private function findWarehouseProduct($productCode, $productName = '') {
        // Primary match: exact SKU match
        if (!empty($productCode)) {
            $query = "SELECT * FROM products WHERE sku = :sku LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':sku' => $productCode]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) return $result;
        }

        // Fallback: try partial SKU match (in case of prefix differences)
        if (!empty($productCode)) {
            $query = "SELECT * FROM products WHERE sku LIKE :sku LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':sku' => '%' . $productCode . '%']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) return $result;
        }

        // Last resort: name matching (if no SKU provided)
        if (!empty($productName)) {
            $query = "SELECT * FROM products WHERE LOWER(name) LIKE LOWER(:name) LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':name' => '%' . $productName . '%']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) return $result;
        }
        
        return null;
    }

    /**
     * Add order item
     */
    private function addOrderItem($orderId, $warehouseProduct, $orderProduct) {
        $query = "
            INSERT INTO order_items (
                order_id, product_id, quantity_ordered, unit_price, notes
            ) VALUES (
                :order_id, :product_id, :quantity_ordered, :unit_price, :notes
            )
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':order_id' => $orderId,
            ':product_id' => $warehouseProduct['product_id'],
            ':quantity_ordered' => $orderProduct['quantity'],
            ':unit_price' => $orderProduct['unit_price'],
            ':notes' => 'Matched SKU: ' . ($orderProduct['code'] ?: 'N/A')
        ]);
    }

    /**
     * Build shipping address from import data
     */
    private function buildShippingAddress($import) {
        $parts = array_filter([
            $import['shipping_address'] ?? '',
            $import['shipping_city'] ?? '',
            $import['shipping_county'] ?? '',
            $import['shipping_postal_code'] ?? ''
        ]);
        return implode(', ', $parts);
    }

    /**
     * Generate unique order number
     */
    private function generateOrderNumber() {
        $prefix = 'ORD-' . date('Y');
        $query = "SELECT MAX(CAST(SUBSTRING(order_number, 10) AS UNSIGNED)) as max_num 
                  FROM orders WHERE order_number LIKE :prefix";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':prefix' => $prefix . '-%']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $nextNum = ($result['max_num'] ?? 0) + 1;
        return $prefix . '-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Determine priority based on business rules
     */
    private function determinePriority($totalValue, $customerName) {
        if ($totalValue > 5000) return 'high';
        if ($totalValue > 1000) return 'normal';
        return 'low';
    }

    /**
     * Validate email address
     */
    private function validateEmail($email) {
        if (empty($email)) return '';
        
        // Handle encrypted email (from your example)
        if (strlen($email) > 100 && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->warnings[] = "Email appears to be encrypted or invalid";
            return '';
        }
        
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * Update import status
     */
    private function updateImportStatus($importId, $status) {
        $query = "UPDATE order_imports SET processing_status = :status, 
                  conversion_attempts = conversion_attempts + 1, 
                  last_attempt_at = CURRENT_TIMESTAMP WHERE id = :import_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':status' => $status, ':import_id' => $importId]);
    }

    /**
     * Update import with success
     */
    private function updateImportSuccess($importId, $orderId, $itemResults) {
        $orderNumber = $this->getOrderNumber($orderId);
        
        $query = "UPDATE order_imports SET 
                  processing_status = 'converted',
                  wms_order_id = :order_id,
                  wms_order_number = :order_number,
                  conversion_errors = :summary
                  WHERE id = :import_id";
        
        $summary = "Success: {$itemResults['processed']} items processed, {$itemResults['skipped']} items skipped";
        if (!empty($this->warnings)) {
            $summary .= ". Warnings: " . implode("; ", $this->warnings);
        }
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':import_id' => $importId,
            ':order_id' => $orderId,
            ':order_number' => $orderNumber,
            ':summary' => $summary
        ]);
    }

    /**
     * Update import with failure
     */
    private function updateImportFailure($importId, $error) {
        $query = "UPDATE order_imports SET 
                  processing_status = 'failed',
                  conversion_errors = :error
                  WHERE id = :import_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':import_id' => $importId, ':error' => $error]);
    }

    /**
     * Get order number by ID
     */
    private function getOrderNumber($orderId) {
        $query = "SELECT order_number FROM orders WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $orderId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['order_number'] : null;
    }
}

// Only run main execution if called directly, not when included
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    // Main execution
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    $importId = $_GET['import_id'] ?? null;
    if (!$importId || !is_numeric($importId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'import_id is required and must be numeric']);
        exit;
    }

    $processor = new ImportProcessor($db);
    $result = $processor->processImport($importId);

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>