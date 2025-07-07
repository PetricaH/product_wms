<?php
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
                throw new Exception("No valid products found in invoice");
            }

            // Create the WMS order
            $orderId = $this->createWMSOrder($import, $clientInfo, $invoiceInfo);
            
            // Process each product
            $itemResults = $this->processOrderItems($orderId, $products);
            
            // Update import record with success
            $this->updateImportSuccess($importId, $orderId, $itemResults);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'order_id' => $orderId,
                'items_processed' => $itemResults['processed'],
                'items_skipped' => $itemResults['skipped'],
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
     * Get import record from database
     */
    private function getImportRecord($importId) {
        $query = "SELECT * FROM order_imports WHERE id = :import_id AND processing_status = 'pending'";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':import_id' => $importId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Parse and validate JSON data with error handling
     */
    private function parseAndValidateJSON($jsonString) {
        if (empty($jsonString)) {
            throw new Exception("JSON data is empty");
        }

        $data = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON: " . json_last_error_msg());
        }

        // Log the structure for debugging
        $this->debugInfo['json_structure'] = array_keys($data);
        
        return $data;
    }

    /**
     * Validate client information with fallbacks
     */
    private function validateClientInfo($clientInfo) {
        $validated = [];

        // Required: contact person name
        if (empty($clientInfo['contact_person_name'])) {
            throw new Exception("Missing required field: contact_person_name");
        }
        $validated['contact_person_name'] = trim($clientInfo['contact_person_name']);

        // Optional fields with fallbacks
        $validated['company_name'] = trim($clientInfo['company_name'] ?? '');
        $validated['contact_email'] = $this->validateEmail($clientInfo['contact_email'] ?? '');
        $validated['contact_phone'] = trim($clientInfo['contact_phone'] ?? '');
        $validated['county'] = trim($clientInfo['county'] ?? '');
        $validated['city'] = trim($clientInfo['city'] ?? '');
        $validated['address'] = trim($clientInfo['address'] ?? '');
        $validated['postal_code'] = trim($clientInfo['postal_code'] ?? '');
        $validated['seller_name'] = trim($clientInfo['seller_name'] ?? '');

        // Build full customer name
        $validated['full_customer_name'] = $validated['contact_person_name'];
        if (!empty($validated['company_name'])) {
            $validated['full_customer_name'] .= ' (' . $validated['company_name'] . ')';
        }

        // Build shipping address
        $addressParts = array_filter([
            $validated['address'],
            $validated['city'],
            $validated['county'],
            $validated['postal_code']
        ]);
        $validated['shipping_address'] = implode(', ', $addressParts);

        return $validated;
    }

    /**
     * Validate invoice information
     */
    private function validateInvoiceInfo($invoiceInfo) {
        $validated = [];

        // Required fields
        if (empty($invoiceInfo['invoice_number'])) {
            throw new Exception("Missing required field: invoice_number");
        }
        $validated['invoice_number'] = trim($invoiceInfo['invoice_number']);

        if (!isset($invoiceInfo['total_value']) || !is_numeric($invoiceInfo['total_value'])) {
            throw new Exception("Missing or invalid total_value");
        }
        $validated['total_value'] = floatval($invoiceInfo['total_value']);

        // Optional fields
        $validated['client_cui'] = trim($invoiceInfo['client_cui'] ?? '');
        $validated['payment_method'] = trim($invoiceInfo['payment_method'] ?? '');

        return $validated;
    }

    /**
     * Validate and process products with multiple scenarios
     */
    private function validateAndProcessProducts($products) {
        if (!is_array($products)) {
            throw new Exception("Products must be an array");
        }

        if (empty($products)) {
            throw new Exception("No products found in order");
        }

        $validProducts = [];
        $invalidCount = 0;

        foreach ($products as $index => $product) {
            try {
                $validProduct = $this->validateSingleProduct($product, $index);
                if ($validProduct) {
                    $validProducts[] = $validProduct;
                }
            } catch (Exception $e) {
                $invalidCount++;
                $this->warnings[] = "Product at index $index skipped: " . $e->getMessage();
                continue;
            }
        }

        $this->debugInfo['total_products'] = count($products);
        $this->debugInfo['valid_products'] = count($validProducts);
        $this->debugInfo['invalid_products'] = $invalidCount;

        if (empty($validProducts)) {
            throw new Exception("No valid products could be processed");
        }

        return $validProducts;
    }

    /**
     * Validate a single product
     */
    private function validateSingleProduct($product, $index) {
        $validated = [];

        // Required: product name
        if (empty($product['name'])) {
            throw new Exception("Product name is required");
        }
        $validated['name'] = trim($product['name']);

        // Required: quantity
        if (!isset($product['quantity']) || !is_numeric($product['quantity']) || floatval($product['quantity']) <= 0) {
            throw new Exception("Valid quantity is required");
        }
        $validated['quantity'] = floatval($product['quantity']);

        // Price (can be 0)
        $validated['price'] = floatval($product['price'] ?? 0);
        $validated['total_price'] = floatval($product['total_price'] ?? $validated['price'] * $validated['quantity']);

        // Optional fields
        $validated['code'] = trim($product['code'] ?? '');
        $validated['unit'] = trim($product['unit'] ?? 'bucata');

        // Generate SKU if missing
        if (empty($validated['code'])) {
            $validated['code'] = $this->generateSKUFromName($validated['name']);
            $this->warnings[] = "Generated SKU '{$validated['code']}' for product: {$validated['name']}";
        }

        return $validated;
    }

    /**
     * Create WMS order
     */
    private function createWMSOrder($import, $clientInfo, $invoiceInfo) {
        $orderNumber = $this->generateOrderNumber();
        
        $priority = $this->determinePriority($invoiceInfo['total_value'], $clientInfo['contact_person_name']);
        
        $notes = 'Import automat din factură: ' . $invoiceInfo['invoice_number'];
        if (!empty($clientInfo['seller_name'])) {
            $notes .= ' - Vânzător: ' . $clientInfo['seller_name'];
        }

        $query = "
            INSERT INTO orders (
                order_number, customer_name, customer_email, 
                shipping_address, order_date, status, priority, 
                total_value, notes, source, created_by, type
            ) VALUES (
                :order_number, :customer_name, :customer_email,
                :shipping_address, CURRENT_TIMESTAMP, 'pending', :priority,
                :total_value, :notes, 'email', 1, 'outbound'
            )
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':order_number' => $orderNumber,
            ':customer_name' => $clientInfo['full_customer_name'],
            ':customer_email' => $clientInfo['contact_email'],
            ':shipping_address' => $clientInfo['shipping_address'],
            ':priority' => $priority,
            ':total_value' => $invoiceInfo['total_value'],
            ':notes' => $notes
        ]);
        
        return $this->db->lastInsertId();
    }

    /**
     * Process order items with product mapping
     */
    private function processOrderItems($orderId, $products) {
        $processed = 0;
        $skipped = 0;

        foreach ($products as $product) {
            try {
                $productId = $this->ensureProductExists($product);
                
                if ($productId) {
                    $this->addOrderItem($orderId, $productId, $product);
                    $processed++;
                } else {
                    $skipped++;
                    $this->warnings[] = "Could not create/find product: " . $product['name'];
                }
            } catch (Exception $e) {
                $skipped++;
                $this->warnings[] = "Error processing product '{$product['name']}': " . $e->getMessage();
            }
        }

        return ['processed' => $processed, 'skipped' => $skipped];
    }

    /**
     * Ensure product exists or create it
     */
    private function ensureProductExists($productData) {
        // Try to find existing product by SKU
        $query = "SELECT product_id FROM products WHERE sku = :sku LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':sku' => $productData['code']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            return $existing['product_id'];
        }

        // Create new product
        $query = "INSERT INTO products (name, sku, price, category, description) 
                  VALUES (:name, :sku, :price, 'Email Import', 'Produs importat automat din email')";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':name' => $productData['name'],
            ':sku' => $productData['code'],
            ':price' => $productData['price']
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Add order item
     */
    private function addOrderItem($orderId, $productId, $productData) {
        $query = "INSERT INTO order_items (order_id, product_id, quantity, unit_price) 
                  VALUES (:order_id, :product_id, :quantity, :unit_price)";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':order_id' => $orderId,
            ':product_id' => $productId,
            ':quantity' => $productData['quantity'],
            ':unit_price' => $productData['price']
        ]);
    }

    /**
     * Generate order number
     */
    private function generateOrderNumber() {
        $prefix = 'ORD';
        $date = date('Ymd');
        
        $query = "SELECT COUNT(*) + 1 as next_num FROM orders WHERE DATE(order_date) = CURDATE()";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $sequence = str_pad($result['next_num'], 4, '0', STR_PAD_LEFT);
        
        return "{$prefix}-{$date}-{$sequence}";
    }

    /**
     * Generate SKU from product name
     */
    private function generateSKUFromName($name) {
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', strtoupper($name));
        return substr($clean, 0, 10) . '-' . substr(md5($name), 0, 4);
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
?>