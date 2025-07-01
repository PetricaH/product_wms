<?php
/**
 * SmartBill API Service Class - Complete Implementation
 * Based on official SmartBill API documentation
 * 
 * IMPORTANT: SmartBill API does NOT support bulk invoice retrieval
 * This API is designed for CREATING invoices, not retrieving them
 */

class SmartBillService {
    private $conn;
    private $config;
    private $apiUrl;
    private $username;
    private $token;
    private $companyVatCode;
    private $debugMode;
    private $configLoaded = false;
    
    // Correct API base URL from documentation
    const API_BASE_URL = 'https://ws.smartbill.ro/SBORO/api';
    
    // Available API endpoints (from official documentation)
    const ENDPOINT_INVOICE = '/invoice';
    const ENDPOINT_INVOICE_PDF = '/invoice/pdf';
    const ENDPOINT_INVOICE_PAYMENT_STATUS = '/invoice/paymentstatus';
    const ENDPOINT_INVOICE_REVERSE = '/invoice/reverse';
    const ENDPOINT_ESTIMATE = '/estimate';
    const ENDPOINT_ESTIMATE_PDF = '/estimate/pdf';
    const ENDPOINT_ESTIMATE_INVOICES = '/estimate/invoices';
    const ENDPOINT_PAYMENT = '/payment';
    const ENDPOINT_PAYMENT_TEXT = '/payment/text';
    const ENDPOINT_STOCKS = '/stocks';
    const ENDPOINT_EMAIL = '/email';
    
    // Document types
    const DOCTYPE_INVOICE = 'factura';
    const DOCTYPE_ESTIMATE = 'proforma';
    const DOCTYPE_RECEIPT = 'chitanta';
    
    public function __construct($db) {
        $this->conn = $db;
        $this->loadConfiguration();
    }
    
    /**
     * Load SmartBill configuration from database with fallback
     */
    private function loadConfiguration(): void {
        try {
            if (!$this->tableExists('smartbill_config')) {
                $this->loadFallbackConfiguration();
                return;
            }
            
            $query = "SELECT setting_key, setting_value, is_encrypted FROM smartbill_config WHERE is_active = 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->config = [];
            foreach ($settings as $setting) {
                $value = $setting['setting_value'];
                if ($setting['is_encrypted'] && !empty($value)) {
                    $value = $this->decrypt($value);
                }
                $this->config[$setting['setting_key']] = $value;
            }
            
            // Set core configuration
            $this->apiUrl = self::API_BASE_URL;
            $this->username = $this->config['api_username'] ?? '';
            $this->token = $this->config['api_token'] ?? '';
            $this->companyVatCode = $this->config['company_vat_code'] ?? '';
            $this->debugMode = (bool)($this->config['debug_mode'] ?? false);
            
            $this->configLoaded = true;
            
        } catch (PDOException $e) {
            error_log("SmartBill config load error: " . $e->getMessage());
            $this->loadFallbackConfiguration();
        }
    }
    
    /**
     * Load fallback configuration when database table doesn't exist
     */
    private function loadFallbackConfiguration(): void {
        $this->config = [
            'api_username' => '',
            'api_token' => '',
            'company_vat_code' => '',
            'default_series' => 'FACT',
            'warehouse_code' => 'PRINCIPAL',
            'default_currency' => 'RON',
            'default_tax_rate' => '19',
            'debug_mode' => false
        ];
        
        $this->apiUrl = self::API_BASE_URL;
        $this->username = $this->config['api_username'];
        $this->token = $this->config['api_token'];
        $this->companyVatCode = $this->config['company_vat_code'];
        $this->debugMode = (bool)$this->config['debug_mode'];
        
        $this->configLoaded = false;
        error_log("SmartBill: Using fallback configuration. Please run database migration and configure API credentials.");
    }
    
    /**
     * Check if a database table exists
     */
    private function tableExists(string $tableName): bool {
        try {
            $query = "SHOW TABLES LIKE :table_name";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':table_name', $tableName, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Check if configuration is properly loaded
     */
    public function isConfigured(): bool {
        return $this->configLoaded && !empty($this->username) && !empty($this->token) && !empty($this->companyVatCode);
    }
    
    /**
     * Get configuration status for debugging
     */
    public function getConfigurationStatus(): array {
        return [
            'config_loaded' => $this->configLoaded,
            'table_exists' => $this->tableExists('smartbill_config'),
            'has_username' => !empty($this->username),
            'has_token' => !empty($this->token),
            'has_company_vat' => !empty($this->companyVatCode),
            'api_url' => $this->apiUrl,
            'username' => $this->username ? 'Configured' : 'Missing',
            'token' => $this->token ? 'Configured' : 'Missing',
            'company_vat_code' => $this->companyVatCode ? $this->companyVatCode : 'Missing',
            'api_limitation' => 'SmartBill API does not support bulk invoice retrieval - only invoice creation'
        ];
    }
    
    /**
     * Test API connection using stocks endpoint (safest test)
     */
    public function testConnection(): array {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'SmartBill API not configured. Please configure: username (email), token, and company VAT code.',
                'data' => $this->getConfigurationStatus()
            ];
        }
        
        try {
            // Test with stocks endpoint - this is a safe endpoint to test with
            $params = [
                'cif' => $this->companyVatCode,
                'date' => date('Y-m-d') // Today's date
            ];
            
            $queryString = http_build_query($params);
            $endpoint = self::ENDPOINT_STOCKS . '?' . $queryString;
            
            if ($this->debugMode) {
                error_log("SmartBill Test Connection: GET {$endpoint}");
            }
            
            $response = $this->makeApiCall('GET', $endpoint);
            
            return [
                'success' => true,
                'message' => 'Connection successful! API credentials are valid.',
                'data' => [
                    'api_response' => $response,
                    'note' => 'SmartBill API is working. Note: This API only supports creating invoices, not retrieving them.'
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'data' => [
                    'error_details' => $e->getMessage(),
                    'config_status' => $this->getConfigurationStatus(),
                    'troubleshooting' => [
                        'Check your SmartBill email and token',
                        'Verify your company VAT code is correct',
                        'Ensure your SmartBill account has API access',
                        'Check that you are using the token (not password) from SmartBill Integrări section'
                    ]
                ]
            ];
        }
    }
    
    /**
     * Get current stocks from SmartBill API
     * @return array Stocks data
     */
    public function getStocks(?string $warehouseName = null, ?string $productCode = null, ?string $date = null): array {
        if (!$this->isConfigured()) {
            throw new Exception('SmartBill API not configured');
        }
        
        $params = [
            'cif' => $this->companyVatCode,
            'date' => $date ?? date('Y-m-d')
        ];
        
        if ($warehouseName) {
            $params['warehouseName'] = $warehouseName;
        }
        
        if ($productCode) {
            $params['productCode'] = $productCode;
        }
        
        $queryString = http_build_query($params);
        $endpoint = self::ENDPOINT_STOCKS . '?' . $queryString;
        
        if ($this->debugMode) {
            error_log("SmartBill Get Stocks: GET {$endpoint}");
        }
        
        return $this->makeApiCall('GET', $endpoint);
    }
    
    /**
     * Import products from SmartBill stocks response into WMS
     * @param array $stocksData The stocks data from SmartBill API
     * @return array Import results
     */
    public function importProductsFromStocks(array $stocksData): array {
        $results = [
            'success' => true,
            'processed' => 0,
            'imported' => 0,
            'updated' => 0,
            'errors' => [],
            'message' => ''
        ];
        
        try {
            if (!isset($stocksData['list']) || !is_array($stocksData['list'])) {
                throw new Exception('Invalid stocks data format');
            }
            
            foreach ($stocksData['list'] as $warehouse) {
                if (!isset($warehouse['products']) || !is_array($warehouse['products'])) {
                    continue;
                }
                
                $warehouseName = $warehouse['warehouse']['warehouseName'] ?? 'Unknown';
                
                foreach ($warehouse['products'] as $product) {
                    try {
                        $results['processed']++;
                        
                        $productCode = $product['productCode'] ?? '';
                        $productName = $product['productName'] ?? '';
                        $measuringUnit = $product['measuringUnit'] ?? 'bucata';
                        
                        if (empty($productCode) || empty($productName)) {
                            $results['errors'][] = "Skipping product with missing code or name";
                            continue;
                        }
                        
                        // Check if product exists
                        $existingProduct = $this->findProductByCode($productCode);
                        
                        if ($existingProduct) {
                            // Update existing product
                            $this->updateProductSmartBillInfo($existingProduct['product_id'], $productCode);
                            $results['updated']++;
                        } else {
                            // Import new product
                            $productId = $this->createProductFromSmartBill([
                                'code' => $productCode,
                                'name' => $productName,
                                'measuring_unit' => $measuringUnit,
                                'warehouse' => $warehouseName
                            ]);
                            
                            if ($productId) {
                                $results['imported']++;
                            }
                        }
                        
                    } catch (Exception $e) {
                        $results['errors'][] = "Error processing product {$productCode}: " . $e->getMessage();
                    }
                }
            }
            
            $results['message'] = "Processed {$results['processed']} products. Imported: {$results['imported']}, Updated: {$results['updated']}";
            
            if (!empty($results['errors'])) {
                $results['success'] = count($results['errors']) < $results['processed'];
            }
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['message'] = 'Product import failed: ' . $e->getMessage();
            $results['errors'][] = $e->getMessage();
        }
        
        return $results;
    }

    /**
     * Find product by SmartBill code
     * @param string $productCode Product code from SmartBill
     * @return array|null Product data or null if not found
     */
    private function findProductByCode(string $productCode): ?array {
        try {
            $query = "SELECT * FROM products 
                      WHERE sku = ? OR smartbill_product_id = ? 
                      LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$productCode, $productCode]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
            
        } catch (PDOException $e) {
            error_log("Error finding product by code: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create new product from SmartBill data
     * @param array $productData Product data from SmartBill
     * @return int|null Product ID if created successfully
     */
    private function createProductFromSmartBill(array $productData): ?int {
        try {
            $query = "INSERT INTO products (
                        sku, 
                        name, 
                        category_id, 
                        unit_price, 
                        cost_price, 
                        status, 
                        smartbill_product_id,
                        smartbill_synced_at,
                        created_at
                      ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $productData['code'],
                $productData['name'],
                1, // Default category
                0.00, // Default price
                0.00, // Default cost
                'active',
                $productData['code']
            ]);
            
            return (int)$this->conn->lastInsertId();
            
        } catch (PDOException $e) {
            error_log("Error creating product from SmartBill: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update existing product with SmartBill information
     * @param int $productId Product ID
     * @param string $smartBillCode SmartBill product code
     * @return bool Success status
     */
    private function updateProductSmartBillInfo(int $productId, string $smartBillCode): bool {
        try {
            $query = "UPDATE products 
                      SET smartbill_product_id = ?, smartbill_synced_at = NOW() 
                      WHERE product_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$smartBillCode, $productId]);
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Error updating product SmartBill info: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync products from SmartBill to WMS
     * @param int $maxProducts Maximum number of products to sync (ignored for stocks endpoint)
     * @return array Sync results
     */
    public function syncProductsFromSmartBill(int $maxProducts = 100): array {
        try {
            // Get stocks data which contains all products
            $stocksData = $this->getStocks();
            
            // Import products from stocks data
            return $this->importProductsFromStocks($stocksData);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'processed' => 0,
                'imported' => 0,
                'updated' => 0,
                'errors' => [$e->getMessage()],
                'message' => 'Product sync failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Create invoice in SmartBill
     * This is the main function for sending invoices TO SmartBill
     */
    public function createInvoice(array $invoiceData): array {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'SmartBill API not configured',
                'smartbill_id' => null,
                'data' => null
            ];
        }
        
        try {
            $this->validateInvoiceData($invoiceData);
            
            $smartBillInvoice = $this->formatInvoiceForSmartBill($invoiceData);
            
            if ($this->debugMode) {
                error_log("SmartBill Invoice Data: " . json_encode($smartBillInvoice, JSON_PRETTY_PRINT));
            }
            
            $response = $this->makeApiCall('POST', self::ENDPOINT_INVOICE, $smartBillInvoice);
            
            return [
                'success' => true,
                'message' => 'Invoice created successfully in SmartBill',
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
     * Get invoice PDF from SmartBill
     */
    public function getInvoicePdf(string $series, string $number): array {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'pdf_content' => null,
                'message' => 'SmartBill API not configured'
            ];
        }
        
        try {
            $params = [
                'cif' => $this->companyVatCode,
                'seriesName' => $series,
                'number' => $number
            ];
            
            $queryString = http_build_query($params);
            $endpoint = self::ENDPOINT_INVOICE_PDF . '?' . $queryString;
            
            // For PDF, we need to handle binary data differently
            $pdfContent = $this->makeApiCallForPdf('GET', $endpoint);
            
            return [
                'success' => true,
                'pdf_content' => $pdfContent,
                'message' => 'Invoice PDF retrieved successfully'
            ];
            
        } catch (Exception $e) {
            error_log("SmartBill Get Invoice PDF Error: " . $e->getMessage());
            return [
                'success' => false,
                'pdf_content' => null,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get invoice payment status from SmartBill
     */
    public function getInvoicePaymentStatus(string $series, string $number): array {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'payment_info' => null,
                'message' => 'SmartBill API not configured'
            ];
        }
        
        try {
            $params = [
                'cif' => $this->companyVatCode,
                'seriesname' => $series,
                'number' => $number
            ];
            
            $queryString = http_build_query($params);
            $endpoint = self::ENDPOINT_INVOICE_PAYMENT_STATUS . '?' . $queryString;
            
            if ($this->debugMode) {
                error_log("SmartBill Get Payment Status: GET {$endpoint}");
            }
            
            $response = $this->makeApiCall('GET', $endpoint);
            
            return [
                'success' => true,
                'payment_info' => $response,
                'message' => 'Payment status retrieved successfully'
            ];
            
        } catch (Exception $e) {
            error_log("SmartBill Get Payment Status Error: " . $e->getMessage());
            return [
                'success' => false,
                'payment_info' => null,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * IMPORTANT: SmartBill API does NOT support invoice retrieval
     * This method exists to clearly communicate the limitation
     */
    public function getNewInvoices(?string $dateFrom = null, array $filters = []): array {
        return [
            'success' => false,
            'invoices' => [],
            'total' => 0,
            'message' => 'SmartBill API does not support bulk invoice retrieval. The API is designed for creating invoices, not retrieving them.',
            'limitation' => 'To implement invoice-to-order workflow, you need to use webhooks or manual order creation.',
            'alternative_solutions' => [
                'Use SmartBill webhooks (if available) to notify your WMS when invoices are created',
                'Manually create orders in WMS when invoices are created',
                'Use a different workflow: WMS creates orders first, then generates invoices in SmartBill'
            ]
        ];
    }
    
    /**
     * Stub method for compatibility with sync service
     */
    public function syncInvoicesToOrders(int $maxInvoices = 50): array {
        return [
            'success' => false,
            'processed' => 0,
            'created_orders' => [],
            'errors' => ['SmartBill API does not support invoice retrieval'],
            'message' => 'SmartBill API limitation: Cannot retrieve invoices for sync. Use webhooks or manual order creation instead.'
        ];
    }
    
    /**
     * IMPORTANT: SmartBill API does NOT support invoice details retrieval by search
     * You can only get details if you know the exact series and number
     */
    public function getInvoiceDetails(string $series, string $number): array {
        // We can get PDF and payment status, but not full invoice details
        $pdfResult = $this->getInvoicePdf($series, $number);
        $paymentResult = $this->getInvoicePaymentStatus($series, $number);
        
        if ($pdfResult['success'] || $paymentResult['success']) {
            return [
                'success' => true,
                'invoice' => [
                    'series' => $series,
                    'number' => $number,
                    'pdf_available' => $pdfResult['success'],
                    'payment_info' => $paymentResult['payment_info'] ?? null
                ],
                'message' => 'Limited invoice information retrieved (PDF and payment status only)',
                'limitation' => 'SmartBill API does not provide full invoice details retrieval'
            ];
        }
        
        return [
            'success' => false,
            'invoice' => null,
            'message' => 'Unable to retrieve invoice information. SmartBill API has limited retrieval capabilities.'
        ];
    }
    
    /**
     * Make API call to SmartBill with proper authentication
     * Fixed for PHP 8+ compatibility
     */
    private function makeApiCall(string $method, string $endpoint, ?array $data = null): array {
        if (empty($this->username) || empty($this->token)) {
            throw new Exception("SmartBill API credentials not configured");
        }
        
        $url = $this->apiUrl . $endpoint;
        
        // SmartBill uses Basic Authentication with username:token (from documentation)
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->token)
        ];
        
        if ($this->debugMode) {
            error_log("SmartBill API Call: {$method} {$url}");
            error_log("SmartBill Auth: " . $this->username . " / " . str_repeat('*', strlen($this->token)));
            if ($data) {
                error_log("SmartBill API Data: " . json_encode($data, JSON_PRETTY_PRINT));
            }
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'WMS-SmartBill-Integration/1.0',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
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
            throw new Exception("cURL Error: " . $error);
        }
        
        if (empty($response)) {
            throw new Exception("Empty response from SmartBill API");
        }
        
        if ($this->debugMode) {
            error_log("SmartBill API Response (HTTP {$httpCode}): " . $response);
        }
        
        $responseData = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMessage = "SmartBill API Error (HTTP {$httpCode})";
            if (isset($responseData['errorText'])) {
                $errorMessage .= ": " . $responseData['errorText'];
            } elseif (isset($responseData['message'])) {
                $errorMessage .= ": " . $responseData['message'];
            } elseif (isset($responseData['error'])) {
                $errorMessage .= ": " . $responseData['error'];
            }
            
            // Add specific error guidance based on documentation
            if ($httpCode === 401) {
                $errorMessage .= " (Check your username/email and token - both must be from SmartBill account)";
            } elseif ($httpCode === 403) {
                $errorMessage .= " (Check your SmartBill account permissions and company VAT code)";
            }
            
            throw new Exception($errorMessage);
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from SmartBill API: " . json_last_error_msg());
        }
        
        return $responseData;
    }
    
    /**
     * Make API call for PDF content (binary data)
     */
    private function makeApiCallForPdf(string $method, string $endpoint): string {
        if (empty($this->username) || empty($this->token)) {
            throw new Exception("SmartBill API credentials not configured");
        }
        
        $url = $this->apiUrl . $endpoint;
        
        // For PDF, we need different headers as per documentation
        $headers = [
            'Accept: application/octet-stream',
            'Accept: application/xml',
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->token),
            'Content-Disposition: attachment; filename="invoice.pdf"'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'WMS-SmartBill-Integration/1.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }
        
        if ($httpCode >= 400) {
            throw new Exception("SmartBill API Error (HTTP {$httpCode}) - Unable to retrieve PDF");
        }
        
        return $response;
    }
    
    /**
     * Format invoice data for SmartBill API (based on documentation examples)
     */
    private function formatInvoiceForSmartBill(array $invoiceData): array {
        $products = [];
        foreach ($invoiceData['items'] as $item) {
            $products[] = [
                'name' => $item['product_name'],
                'code' => $item['sku'],
                'isUm' => $item['unit_of_measure'] ?? 'buc',
                'quantity' => (float)$item['quantity'],
                'price' => (float)$item['unit_price'],
                'isTaxIncluded' => true, // Assuming prices include VAT
                'taxPercentage' => (float)($item['tax_percent'] ?? 19),
                'isDiscount' => !empty($item['discount_percent']),
                'discountPercentage' => (float)($item['discount_percent'] ?? 0),
                'isService' => false,
                'saveToDb' => false
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
                'country' => $invoiceData['customer_country'] ?? 'Romania',
                'email' => $invoiceData['customer_email'] ?? ''
            ],
            'issueDate' => $invoiceData['invoice_date'] ?? date('Y-m-d'),
            'seriesName' => $invoiceData['series'] ?? $this->config['default_series'],
            'isDraft' => false,
            'useStock' => true, // Enable stock management
            'products' => $products,
            'currency' => $invoiceData['currency'] ?? 'RON',
            'precision' => 2
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
     * Get configuration value
     */
    public function getConfig(string $key, $default = null) {
        return $this->config[$key] ?? $default;
    }
    
    /**
     * Update configuration setting
     */
    public function updateConfig(string $key, string $value, bool $encrypt = false): bool {
        try {
            if (!$this->tableExists('smartbill_config')) {
                throw new Exception("Configuration table not found. Please run database migration.");
            }
            
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
            
            if ($result) {
                $this->loadConfiguration();
            }
            
            return $result;
            
        } catch (PDOException $e) {
            error_log("SmartBill config update error: " . $e->getMessage());
            return false;
        }
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
}
?>