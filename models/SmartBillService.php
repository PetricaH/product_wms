<?php
/**
 * SmartBill API Service Class
 * Handles all communication with SmartBill API for invoicing and inventory management
 */

class SmartBillService {
    private $conn;
    private $config;
    private $apiUrl;
    private $username;
    private $token;
    private $companyVatCode;
    private $debugMode;
    
    // API endpoints
    const ENDPOINT_INVOICE = '/invoice';
    const ENDPOINT_ESTIMATE = '/estimate';
    const ENDPOINT_RECEIPT = '/receipt';
    const ENDPOINT_STOCK = '/stocks';
    const ENDPOINT_PRODUCTS = '/products';
    const ENDPOINT_CLIENTS = '/clients';
    const ENDPOINT_SUPPLIERS = '/suppliers';
    
    // Document types
    const DOCTYPE_INVOICE = 'factura';
    const DOCTYPE_ESTIMATE = 'proforma';
    const DOCTYPE_RECEIPT = 'chitanta';
    
    public function __construct($db) {
        $this->conn = $db;
        $this->loadConfiguration();
    }
    
    /**
     * Load SmartBill configuration from database
     */
    private function loadConfiguration(): void {
        try {
            $query = "SELECT setting_key, setting_value, is_encrypted FROM smartbill_config WHERE is_active = 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->config = [];
            foreach ($settings as $setting) {
                $value = $setting['setting_value'];
                if ($setting['is_encrypted'] && !empty($value)) {
                    // Decrypt encrypted settings (implement your encryption method)
                    $value = $this->decrypt($value);
                }
                $this->config[$setting['setting_key']] = $value;
            }
            
            // Set core configuration
            $this->apiUrl = $this->config['api_url'] ?? 'https://ws.smartbill.ro';
            $this->username = $this->config['api_username'] ?? '';
            $this->token = $this->config['api_token'] ?? '';
            $this->companyVatCode = $this->config['company_vat_code'] ?? '';
            $this->debugMode = (bool)($this->config['debug_mode'] ?? false);
            
        } catch (PDOException $e) {
            error_log("SmartBill config load error: " . $e->getMessage());
            throw new Exception("Failed to load SmartBill configuration");
        }
    }
    
    /**
     * Test API connection and credentials
     */
    public function testConnection(): array {
        try {
            $response = $this->makeApiCall('GET', '/test');
            return [
                'success' => true,
                'message' => 'Connection successful',
                'data' => $response
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Create invoice in SmartBill
     */
    public function createInvoice(array $invoiceData): array {
        try {
            $this->validateInvoiceData($invoiceData);
            
            $smartBillInvoice = $this->formatInvoiceForSmartBill($invoiceData);
            
            if ($this->debugMode) {
                error_log("SmartBill Invoice Data: " . json_encode($smartBillInvoice, JSON_PRETTY_PRINT));
            }
            
            $response = $this->makeApiCall('POST', self::ENDPOINT_INVOICE, $smartBillInvoice);
            
            return [
                'success' => true,
                'message' => 'Invoice created successfully',
                'smartbill_id' => $response['url'] ?? null,
                'document_number' => $response['number'] ?? null,
                'series' => $response['series'] ?? null,
                'data' => $response
            ];
            
        } catch (Exception $e) {
            error_log("SmartBill Invoice Creation Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'smartbill_id' => null,
                'data' => null
            ];
        }
    }
    
    /**
     * Update inventory in SmartBill
     */
    public function updateInventory(array $inventoryData): array {
        try {
            $smartBillStock = $this->formatInventoryForSmartBill($inventoryData);
            
            if ($this->debugMode) {
                error_log("SmartBill Inventory Data: " . json_encode($smartBillStock, JSON_PRETTY_PRINT));
            }
            
            $response = $this->makeApiCall('POST', self::ENDPOINT_STOCK, $smartBillStock);
            
            return [
                'success' => true,
                'message' => 'Inventory updated successfully',
                'data' => $response
            ];
            
        } catch (Exception $e) {
            error_log("SmartBill Inventory Update Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Create or update product in SmartBill
     */
    public function syncProduct(array $productData): array {
        try {
            $smartBillProduct = $this->formatProductForSmartBill($productData);
            
            $response = $this->makeApiCall('POST', self::ENDPOINT_PRODUCTS, $smartBillProduct);
            
            return [
                'success' => true,
                'message' => 'Product synchronized successfully',
                'data' => $response
            ];
            
        } catch (Exception $e) {
            error_log("SmartBill Product Sync Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Create or update customer in SmartBill
     */
    public function syncCustomer(array $customerData): array {
        try {
            $smartBillClient = $this->formatCustomerForSmartBill($customerData);
            
            $response = $this->makeApiCall('POST', self::ENDPOINT_CLIENTS, $smartBillClient);
            
            return [
                'success' => true,
                'message' => 'Customer synchronized successfully',
                'data' => $response
            ];
            
        } catch (Exception $e) {
            error_log("SmartBill Customer Sync Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Get invoice status from SmartBill
     */
    public function getInvoiceStatus(string $smartBillId): array {
        try {
            $response = $this->makeApiCall('GET', self::ENDPOINT_INVOICE . '/' . $smartBillId);
            
            return [
                'success' => true,
                'status' => $response['status'] ?? 'unknown',
                'data' => $response
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Format invoice data for SmartBill API
     */
    private function formatInvoiceForSmartBill(array $invoiceData): array {
        $items = [];
        foreach ($invoiceData['items'] as $item) {
            $netPrice = $item['unit_price'] / (1 + ($item['tax_percent'] / 100));
            
            $items[] = [
                'name' => $item['product_name'],
                'code' => $item['sku'],
                'isUm' => $item['unit_of_measure'] ?? 'buc',
                'quantity' => (float)$item['quantity'],
                'price' => round($netPrice, 4),
                'isTaxIncluded' => false,
                'taxPercentage' => (float)$item['tax_percent'],
                'isDiscount' => !empty($item['discount_percent']),
                'discountPercentage' => (float)($item['discount_percent'] ?? 0),
                'warehouse' => $item['warehouse_code'] ?? $this->config['warehouse_code']
            ];
        }
        
        return [
            'companyVatCode' => $this->companyVatCode,
            'client' => [
                'name' => $invoiceData['customer_name'],
                'vatCode' => $invoiceData['customer_vat'] ?? '',
                'regCode' => $invoiceData['customer_reg'] ?? '',
                'address' => $invoiceData['customer_address'] ?? '',
                'isTaxPayer' => !empty($invoiceData['customer_vat']),
                'city' => $invoiceData['customer_city'] ?? '',
                'country' => $invoiceData['customer_country'] ?? 'România',
                'email' => $invoiceData['customer_email'] ?? '',
                'phone' => $invoiceData['customer_phone'] ?? ''
            ],
            'issueDate' => $invoiceData['invoice_date'] ?? date('Y-m-d'),
            'seriesName' => $invoiceData['series'] ?? $this->config['default_series'],
            'isDraft' => false,
            'useStock' => true,
            'products' => $items,
            'issuerName' => $invoiceData['issuer_name'] ?? '',
            'issuerCnp' => $invoiceData['issuer_cnp'] ?? '',
            'currency' => $invoiceData['currency'] ?? 'RON',
            'precision' => 2,
            'mentions' => $invoiceData['notes'] ?? '',
            'useEstimateDetails' => false
        ];
    }
    
    /**
     * Format inventory data for SmartBill API
     */
    private function formatInventoryForSmartBill(array $inventoryData): array {
        return [
            'companyVatCode' => $this->companyVatCode,
            'warehouseName' => $inventoryData['warehouse_code'] ?? $this->config['warehouse_code'],
            'products' => array_map(function($item) {
                return [
                    'code' => $item['sku'],
                    'quantity' => (float)$item['quantity'],
                    'price' => (float)$item['unit_price']
                ];
            }, $inventoryData['items'])
        ];
    }
    
    /**
     * Format product data for SmartBill API
     */
    private function formatProductForSmartBill(array $productData): array {
        return [
            'companyVatCode' => $this->companyVatCode,
            'name' => $productData['name'],
            'code' => $productData['sku'],
            'isUm' => $productData['unit_of_measure'] ?? 'buc',
            'price' => (float)$productData['price'],
            'taxPercentage' => (float)($productData['tax_percent'] ?? $this->config['default_tax_rate']),
            'currency' => $productData['currency'] ?? $this->config['default_currency'],
            'warehouseName' => $productData['warehouse_code'] ?? $this->config['warehouse_code']
        ];
    }
    
    /**
     * Format customer data for SmartBill API
     */
    private function formatCustomerForSmartBill(array $customerData): array {
        return [
            'companyVatCode' => $this->companyVatCode,
            'name' => $customerData['name'],
            'vatCode' => $customerData['vat_code'] ?? '',
            'regCode' => $customerData['reg_code'] ?? '',
            'address' => $customerData['address'] ?? '',
            'isTaxPayer' => !empty($customerData['vat_code']),
            'city' => $customerData['city'] ?? '',
            'country' => $customerData['country'] ?? 'România',
            'email' => $customerData['email'] ?? '',
            'phone' => $customerData['phone'] ?? '',
            'contact' => $customerData['contact_person'] ?? ''
        ];
    }
    
    /**
     * Validate invoice data before sending to SmartBill
     */
    private function validateInvoiceData(array $data): void {
        $required = ['customer_name', 'items'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }
        
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new Exception("Invoice must contain at least one item");
        }
        
        foreach ($data['items'] as $index => $item) {
            $requiredItemFields = ['product_name', 'sku', 'quantity', 'unit_price'];
            foreach ($requiredItemFields as $field) {
                if (empty($item[$field])) {
                    throw new Exception("Missing required item field '{$field}' at index {$index}");
                }
            }
        }
    }
    
    /**
     * Make API call to SmartBill
     */
    private function makeApiCall(string $method, string $endpoint, array $data = null): array {
        if (empty($this->username) || empty($this->token)) {
            throw new Exception("SmartBill API credentials not configured");
        }
        
        $url = $this->apiUrl . $endpoint;
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->token)
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'WMS-SmartBill-Integration/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        if ($method === 'POST' && $data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT' && $data !== null) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("CURL Error: " . $error);
        }
        
        if ($this->debugMode) {
            error_log("SmartBill API Call: {$method} {$url} - Response Code: {$httpCode}");
            error_log("SmartBill API Response: " . $response);
        }
        
        $responseData = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMessage = "SmartBill API Error (HTTP {$httpCode})";
            if (isset($responseData['errorText'])) {
                $errorMessage .= ": " . $responseData['errorText'];
            } elseif (isset($responseData['message'])) {
                $errorMessage .= ": " . $responseData['message'];
            }
            throw new Exception($errorMessage);
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from SmartBill API");
        }
        
        return $responseData;
    }
    
    /**
     * Update configuration setting
     */
    public function updateConfig(string $key, string $value, bool $encrypt = false): bool {
        try {
            if ($encrypt) {
                $value = $this->encrypt($value);
            }
            
            $query = "INSERT INTO smartbill_config (setting_key, setting_value, is_encrypted) 
                     VALUES (:key, :value, :encrypted) 
                     ON DUPLICATE KEY UPDATE 
                     setting_value = VALUES(setting_value), 
                     is_encrypted = VALUES(is_encrypted),
                     updated_at = CURRENT_TIMESTAMP";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':key', $key, PDO::PARAM_STR);
            $stmt->bindParam(':value', $value, PDO::PARAM_STR);
            $stmt->bindParam(':encrypted', $encrypt, PDO::PARAM_BOOL);
            
            $result = $stmt->execute();
            
            // Reload configuration
            $this->loadConfiguration();
            
            return $result;
            
        } catch (PDOException $e) {
            error_log("SmartBill config update error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get configuration value
     */
    public function getConfig(string $key, $default = null) {
        return $this->config[$key] ?? $default;
    }
    
    /**
     * Get all configuration as array
     */
    public function getAllConfig(): array {
        return $this->config;
    }
    
    /**
     * Simple encryption for sensitive config data
     */
    private function encrypt(string $data): string {
        $key = hash('sha256', 'smartbill-wms-encryption-key-' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Simple decryption for sensitive config data
     */
    private function decrypt(string $data): string {
        $key = hash('sha256', 'smartbill-wms-encryption-key-' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }
    
    /**
     * Get transaction statistics
     */
    public function getTransactionStats(): array {
        try {
            $query = "SELECT 
                        transaction_type,
                        status,
                        COUNT(*) as count,
                        SUM(amount) as total_amount,
                        AVG(amount) as avg_amount,
                        MIN(created_at) as first_transaction,
                        MAX(created_at) as last_transaction
                      FROM transactions 
                      WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      GROUP BY transaction_type, status
                      ORDER BY transaction_type, status";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("SmartBill stats error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get failed transactions for retry
     */
    public function getFailedTransactions(): array {
        try {
            $query = "SELECT id, transaction_type, reference_type, reference_id, 
                            error_message, retry_count, max_retries, next_retry
                     FROM transactions 
                     WHERE status = 'failed' 
                       AND retry_count < max_retries 
                       AND (next_retry IS NULL OR next_retry <= NOW())
                     ORDER BY created_at ASC 
                     LIMIT 50";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("SmartBill failed transactions error: " . $e->getMessage());
            return [];
        }
    }
}