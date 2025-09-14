<?php
/**
 * Production Ready WMS API v2.0
 * Enhanced with AWB support for Cargus integration
 * File: api/index.php
 */

header('Content-Type: application/json');
ini_set('display_errors', 0); // Disable for production
error_reporting(E_ALL);

// Enable CORS for production
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Bootstrap
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

/**
 * Production WMS API with Enhanced Security and AWB Support
 */
class ProductionWMSAPI {
    private $conn;
    private $config;
    private $orderModel;
    private $productModel;
    private $inventoryModel;
    
    public function __construct() {
        $this->loadConfig();
        $this->connectDatabase();
        $this->loadModels();
    }
    
    private function loadConfig() {
        $config = require BASE_PATH . '/config/config.php';
        
        $this->config = [
            'api_key' => $config['api']['key'] ?? '',
            'allowed_origins' => $config['api']['allowed_origins'] ?? ['*'],
            'rate_limit' => $config['api']['rate_limit'] ?? 100,
            'debug' => $config['environment'] === 'development',
            'max_request_size' => 1024 * 1024 * 5, // 5MB
            'request_timeout' => 30
        ];
        
        if (empty($this->config['api_key'])) {
            throw new Exception('API key not configured');
        }
    }
    
    private function connectDatabase() {
        $config = require BASE_PATH . '/config/config.php';
        
        if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
            throw new Exception('Database connection not configured');
        }
        
        $this->conn = $config['connection_factory']();
        
        if (!$this->conn) {
            throw new Exception('Database connection failed');
        }
    }
    
    private function loadModels() {
        require_once BASE_PATH . '/models/Order.php';
        require_once BASE_PATH . '/models/Product.php';
        require_once BASE_PATH . '/models/Inventory.php';
        
        $this->orderModel = new Order($this->conn);
        $this->productModel = new Product($this->conn);
        $this->inventoryModel = new Inventory($this->conn);
    }
    
    public function handleRequest() {
        try {
            $this->validateRequest();
            $this->setCORSHeaders();
            $this->authenticate();
            $this->routeRequest();
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    private function validateRequest() {
        // Check request size
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
        if ($contentLength > $this->config['max_request_size']) {
            throw new Exception('Request too large', 413);
        }
        
        // Rate limiting (basic implementation)
        $this->checkRateLimit();
    }
    
    private function checkRateLimit() {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $cacheKey = "rate_limit:$clientIp";
        
        // Basic file-based rate limiting (use Redis in production)
        $rateLimitFile = sys_get_temp_dir() . "/wms_rate_limit_" . md5($clientIp);
        $currentTime = time();
        
        if (file_exists($rateLimitFile)) {
            $data = json_decode(file_get_contents($rateLimitFile), true);
            if ($data && $data['timestamp'] > ($currentTime - 3600)) { // 1 hour window
                if ($data['count'] >= $this->config['rate_limit']) {
                    throw new Exception('Rate limit exceeded', 429);
                }
                $data['count']++;
            } else {
                $data = ['timestamp' => $currentTime, 'count' => 1];
            }
        } else {
            $data = ['timestamp' => $currentTime, 'count' => 1];
        }
        
        file_put_contents($rateLimitFile, json_encode($data));
    }
    
    private function setCORSHeaders() {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array('*', $this->config['allowed_origins']) || in_array($origin, $this->config['allowed_origins'])) {
            header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
        }
        header('Access-Control-Allow-Credentials: true');
    }
    
    private function authenticate() {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $apiKey = $this->getApiKey();
        
        // Option 1: API key authentication (for external systems)
        if (!empty($apiKey)) {
            if ($apiKey !== $this->config['api_key']) {
                throw new Exception('Invalid API key', 401);
            }
            return; // API key is valid, proceed
        }
        
        // Option 2: Session authentication (for logged-in users)
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            return; // User is logged in, proceed
        }
        
        // No valid authentication found
        throw new Exception('Authentication required - provide API key or login', 401);
    }
    
    private function getApiKey() {
        // Multiple ways to send API key
        return $_SERVER['HTTP_X_API_KEY'] 
            ?? $_SERVER['HTTP_AUTHORIZATION'] 
            ?? $_GET['api_key'] 
            ?? $_POST['api_key'] 
            ?? '';
    }
    
    private function routeRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $endpoint = $this->getEndpoint();
        
        // Log request for debugging
        if ($this->config['debug']) {
            error_log("API Request: $method $endpoint");
        }
        
        switch (true) {
            case $endpoint === 'health':
                $this->healthCheck();
                break;
                
            case $endpoint === 'orders' && $method === 'POST':
                $this->createOrder();
                break;
                
            case $endpoint === 'orders' && $method === 'GET':
                $this->getOrders();
                break;
                
            case preg_match('#^orders/(\d+)$#', $endpoint, $matches) && $method === 'GET':
                $this->getOrder(intval($matches[1]));
                break;
                
            case preg_match('#^orders/(\d+)/status$#', $endpoint, $matches) && $method === 'PUT':
                $this->updateOrderStatus(intval($matches[1]));
                break;
                
            case preg_match('#^orders/(\d+)/awb$#', $endpoint, $matches) && $method === 'POST':
                $this->generateAWB(intval($matches[1]));
                break;
                
            case $endpoint === 'inventory/check' && $method === 'GET':
                $this->checkInventory();
                break;

            case $endpoint === 'products' && $method === 'GET':
                $this->getProducts();
                break;

            case $endpoint === 'products' && $method === 'POST':
                $this->createProduct();
                break;

            case preg_match('#^products/lookup/(.+)$#', $endpoint, $matches) && $method === 'GET':
                $this->lookupProduct($matches[1]);
                break;
                
            default:
                throw new Exception("Endpoint not found: $method /$endpoint", 404);
        }
    }
    
    private function getEndpoint() {
        if (isset($_GET['endpoint'])) {
            return trim($_GET['endpoint'], '/');
        }

        if (!empty($_SERVER['PATH_INFO'])) {
            return trim($_SERVER['PATH_INFO'], '/');
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '') ?: '';
        if (strpos($uri, $scriptDir) === 0) {
            $uri = substr($uri, strlen($scriptDir));
        }
        if (false !== ($pos = strpos($uri, '?'))) {
            $uri = substr($uri, 0, $pos);
        }
        return trim($uri, '/');
    }
    
    // === API ENDPOINTS ===
    
    private function healthCheck() {
        $health = [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'version' => '2.0.0',
            'database' => 'connected',
            'environment' => $this->config['debug'] ? 'development' : 'production'
        ];
        
        // Check database health
        try {
            $this->conn->query('SELECT 1');
        } catch (Exception $e) {
            $health['status'] = 'unhealthy';
            $health['database'] = 'disconnected';
            http_response_code(503);
        }
        
        $this->sendResponse($health);
    }
    
    private function createOrder() {
        $input = $this->getJsonInput();
        $this->validateOrderInput($input);
        
        try {
            $this->conn->beginTransaction();
            
            // Prepare order data with AWB fields
            $orderData = [
                'order_number' => $input['order_number'] ?? $this->generateOrderNumber(),
                'customer_name' => $input['customer_name'],
                'customer_email' => $input['customer_email'] ?? '',
                'shipping_address' => $input['shipping_address'] ?? '',
                'order_date' => $input['order_date'] ?? date('Y-m-d H:i:s'),
                'status' => Order::STATUS_PENDING,
                'notes' => $input['notes'] ?? 'Created via API from CRM',
                'source' => 'CRM',
                
                // AWB fields
                'recipient_county_id' => $input['recipient_county_id'] ?? null,
                'recipient_county_name' => $input['recipient_county_name'] ?? '',
                'recipient_locality_id' => $input['recipient_locality_id'] ?? null,
                'recipient_locality_name' => $input['recipient_locality_name'] ?? '',
                'recipient_street_id' => $input['recipient_street_id'] ?? null,
                'recipient_street_name' => $input['recipient_street_name'] ?? '',
                'recipient_building_number' => $input['recipient_building_number'] ?? '',
                'recipient_contact_person' => $input['recipient_contact_person'] ?? $input['customer_name'],
                'recipient_phone' => $input['recipient_phone'] ?? '',
                'recipient_email' => $input['recipient_email'] ?? $input['customer_email'],
                
                // Shipping details
                'total_weight' => floatval($input['total_weight'] ?? 1.0),
                'declared_value' => floatval($input['declared_value'] ?? 0.0),
                'parcels_count' => intval($input['parcels_count'] ?? 1),
                'envelopes_count' => intval($input['envelopes_count'] ?? 0),
                'cash_repayment' => floatval($input['cash_repayment'] ?? 0.0),
                'bank_repayment' => floatval($input['bank_repayment'] ?? 0.0),
                'saturday_delivery' => !empty($input['saturday_delivery']),
                'morning_delivery' => !empty($input['morning_delivery']),
                'open_package' => !empty($input['open_package']),
                'observations' => $input['observations'] ?? '',
                'package_content' => $input['package_content'] ?? '',
                
                // References
                'sender_reference1' => $input['sender_reference1'] ?? '',
                'recipient_reference1' => $input['recipient_reference1'] ?? '',
                'recipient_reference2' => $input['recipient_reference2'] ?? '',
                'invoice_reference' => $input['invoice_reference'] ?? '',
                'sender_location_id' => $input['sender_location_id'] ?? null
            ];
            
            // Validate AWB data if provided
            if (!empty($orderData['recipient_county_id'])) {
                $this->validateAWBData($orderData);
            }
            
            // Process items
            $orderItems = [];
            foreach ($input['items'] as $item) {
                $product = $this->ensureProductExists($item);
                if ($product) {
                    $orderItems[] = [
                        'product_id' => $product['product_id'],
                        'quantity_ordered' => floatval($item['quantity']),
                        'unit_price' => floatval($item['unit_price'] ?? 0)
                    ];
                }
            }
            
            if (empty($orderItems)) {
                throw new Exception('No valid products in order');
            }
            
            $orderId = $this->orderModel->create($orderData, $orderItems);
            
            if (!$orderId) {
                throw new Exception('Failed to create order');
            }
            
            $this->conn->commit();
            
            $this->sendResponse([
                'success' => true,
                'order_id' => $orderId,
                'order_number' => $orderData['order_number'],
                'status' => $orderData['status'],
                'awb_ready' => !empty($orderData['recipient_county_id']),
                'message' => 'Order created successfully'
            ], 201);
            
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
    
    private function validateOrderInput($input) {
        $required = ['customer_name', 'items'];
        
        foreach ($required as $field) {
            if (empty($input[$field])) {
                throw new Exception("Missing required field: $field", 400);
            }
        }
        
        if (!is_array($input['items']) || empty($input['items'])) {
            throw new Exception('Items must be a non-empty array', 400);
        }
        
        foreach ($input['items'] as $i => $item) {
            if (empty($item['sku']) && empty($item['code'])) {
                throw new Exception("Item $i missing SKU/code", 400);
            }
            if (empty($item['quantity']) || floatval($item['quantity']) <= 0) {
                throw new Exception("Item $i invalid quantity", 400);
            }
        }
    }
    
    private function validateAWBData($orderData) {
        $required = ['recipient_county_id', 'recipient_locality_id', 'recipient_contact_person', 'recipient_phone'];
        
        foreach ($required as $field) {
            if (empty($orderData[$field])) {
                throw new Exception("AWB data incomplete: missing $field", 400);
            }
        }
        
        // Validate phone format
        if (!preg_match('/^\+?[0-9\s\-\(\)]{10,15}$/', $orderData['recipient_phone'])) {
            throw new Exception('Invalid phone number format', 400);
        }
        
        // Validate weight and counts
        if ($orderData['total_weight'] <= 0) {
            throw new Exception('Total weight must be greater than 0', 400);
        }
        
        if ($orderData['envelopes_count'] > 9) {
            throw new Exception('Maximum 9 envelopes allowed', 400);
        }
    }
    
    private function generateAWB($orderId) {
        $order = $this->orderModel->getOrderById($orderId);
        
        if (!$order) {
            throw new Exception('Order not found', 404);
        }

        if (($order['status'] ?? '') !== 'picked') {
            throw new Exception('AWB can only be generated for orders with status picked', 400);
        }

        if (empty($order['recipient_county_id'])) {
            throw new Exception('Order missing AWB data', 400);
        }

        require_once BASE_PATH . '/models/CargusService.php';
        $cargus = new CargusService();
        $result = $cargus->generateAWB($order);

        if (!$result['success']) {
            throw new Exception('Cargus API error: ' . $result['error'], 500);
        }

        $this->orderModel->updateAWBInfo($orderId, [
            'awb_barcode' => $result['barcode'],
            'awb_created_at' => date('Y-m-d H:i:s'),
            'cargus_order_id' => $result['parcelCodes'][0] ?? ''
        ]);

        $this->sendResponse([
            'success' => true,
            'order_id' => $orderId,
            'awb_barcode' => $result['barcode'],
            'message' => 'AWB generated successfully'
        ]);
    }
    
    private function getOrders() {
        $filters = [
            'status' => $_GET['status'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'customer_name' => $_GET['customer_name'] ?? '',
            'order_number' => $_GET['order_number'] ?? '',
            'awb_barcode' => $_GET['awb_barcode'] ?? ''
        ];
        
        $filters = array_filter($filters);
        $orders = $this->orderModel->getAllOrders($filters);
        
        $this->sendResponse([
            'success' => true,
            'orders' => $orders,
            'count' => count($orders)
        ]);
    }
    
    private function getOrder($orderId) {
        $order = $this->orderModel->getOrderById($orderId);
        
        if (!$order) {
            throw new Exception('Order not found', 404);
        }
        
        $this->sendResponse([
            'success' => true,
            'order' => $order
        ]);
    }
    
    private function updateOrderStatus($orderId) {
        $input = $this->getJsonInput();
        
        if (empty($input['status'])) {
            throw new Exception('Status is required', 400);
        }
        
        $result = $this->orderModel->updateStatus($orderId, $input['status']);
        
        if (!$result) {
            throw new Exception('Failed to update order status', 500);
        }
        
        $this->sendResponse([
            'success' => true,
            'order_id' => $orderId,
            'new_status' => $input['status'],
            'message' => 'Order status updated successfully'
        ]);
    }
    
    private function checkInventory() {
        $skus = $_GET['skus'] ?? '';
        if (empty($skus)) {
            throw new Exception('SKUs parameter required', 400);
        }
        
        $skuList = explode(',', $skus);
        $results = [];
        
        foreach ($skuList as $sku) {
            $sku = trim($sku);
            if (empty($sku)) continue;
            
            try {
                $stock = $this->inventoryModel->getStockSummaryBySku($sku);
                $results[$sku] = [
                    'sku' => $sku,
                    'available_quantity' => $stock['total_quantity'] ?? 0,
                    'locations' => $stock['locations'] ?? []
                ];
            } catch (Exception $e) {
                $results[$sku] = [
                    'sku' => $sku,
                    'available_quantity' => 0,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $this->sendResponse([
            'success' => true,
            'inventory' => $results
        ]);
    }
    
    private function getProducts() {
        $search = $_GET['search'] ?? '';
        $limit = min(intval($_GET['limit'] ?? 50), 500); // Max 500
        
        $products = $this->productModel->search($search, $limit);
        
        $this->sendResponse([
            'success' => true,
            'products' => $products,
            'count' => count($products)
        ]);
    }
    
    private function createProduct() {
        $input = $this->getJsonInput();
        
        $required = ['sku', 'name'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                throw new Exception("Missing required field: $field", 400);
            }
        }
        
        $productData = [
            'sku' => $input['sku'],
            'name' => $input['name'],
            'unit_of_measure' => $input['unit_of_measure'] ?? 'buc',
            'price' => floatval($input['price'] ?? 0),
            'category' => $input['category'] ?? 'General'
        ];
        
        $productId = $this->productModel->create($productData);
        
        if (!$productId) {
            throw new Exception('Failed to create product', 500);
        }
        
        $this->sendResponse([
            'success' => true,
            'product_id' => $productId,
            'message' => 'Product created successfully'
        ], 201);
    }

    private function lookupProduct(string $barcode) {
        $code = trim($barcode);
        if ($code === '') {
            throw new Exception('Barcode required', 400);
        }
        $query = "
            SELECT p.product_id, p.name, p.sku, p.barcode,
                   COALESCE(SUM(i.quantity),0) AS current_stock
            FROM products p
            LEFT JOIN inventory i ON p.product_id = i.product_id
            WHERE p.sku = :code OR p.barcode = :code
            GROUP BY p.product_id
            LIMIT 1
        ";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':code' => $code]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $this->sendResponse([
                'id' => (int)$product['product_id'],
                'name' => $product['name'],
                'sku' => $product['sku'],
                'barcode' => $product['barcode'] ?: $product['sku'],
                'scanned_barcode' => $code,
                'current_stock' => (int)$product['current_stock']
            ]);
            return;
        }

        $query = "
            SELECT p.product_id, p.name, p.sku, p.barcode,
                   i.id AS inventory_id, i.location_id, i.subdivision_number, i.quantity,
                   l.location_code,
                   (SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE product_id = p.product_id) AS current_stock
            FROM inventory i
            JOIN products p ON i.product_id = p.product_id
            JOIN locations l ON i.location_id = l.id
            WHERE i.batch_number = :code AND i.quantity > 0
            LIMIT 1
        ";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':code' => $code]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$unit) {
            throw new Exception('Product not found', 404);
        }

        $this->sendResponse([
            'id' => (int)$unit['product_id'],
            'name' => $unit['name'],
            'sku' => $unit['sku'],
            'barcode' => $unit['barcode'] ?: $unit['sku'],
            'scanned_barcode' => $code,
            'current_stock' => (int)$unit['current_stock'],
            'unit' => [
                'inventory_id' => (int)$unit['inventory_id'],
                'location_id' => (int)$unit['location_id'],
                'location_code' => $unit['location_code'],
                'subdivision_number' => $unit['subdivision_number'] !== null ? (int)$unit['subdivision_number'] : null,
                'quantity' => (int)$unit['quantity']
            ]
        ]);
    }
    
    // === HELPER METHODS ===
    
    private function ensureProductExists($itemData) {
        $sku = $itemData['sku'] ?? $itemData['code'] ?? '';
        if (empty($sku)) return null;
        
        try {
            $product = $this->productModel->findBySku($sku);
            
            if (!$product) {
                $productData = [
                    'sku' => $sku,
                    'name' => $itemData['name'] ?? $itemData['product_name'] ?? $sku,
                    'unit_of_measure' => $itemData['unit_of_measure'] ?? 'buc',
                    'price' => floatval($itemData['unit_price'] ?? 0),
                    'category' => 'CRM Import'
                ];
                
                $productId = $this->productModel->create($productData);
                if ($productId) {
                    $product = $this->productModel->findById($productId);
                }
            }
            
            return $product;
        } catch (Exception $e) {
            error_log("Error ensuring product exists: " . $e->getMessage());
            return null;
        }
    }
    
    private function generateOrderNumber() {
        $prefix = 'ORD';
        $date = date('Ymd');
        
        // Get next sequence number for today
        $query = "SELECT COUNT(*) + 1 as next_num FROM orders WHERE DATE(order_date) = CURDATE()";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $sequence = str_pad($result['next_num'], 4, '0', STR_PAD_LEFT);
        
        return "{$prefix}-{$date}-{$sequence}";
    }
    
    private function getJsonInput() {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return [];
        }
        
        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input', 400);
        }
        
        return $data;
    }
    
    private function sendResponse($data, $code = 200) {
        http_response_code($code);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    private function handleError(Exception $e) {
        $code = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 
            ? $e->getCode() 
            : 500;
        
        $response = [
            'success' => false,
            'error' => $e->getMessage(),
            'timestamp' => date('c')
        ];
        
        if ($this->config['debug']) {
            $response['debug'] = [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
        }
        
        // Log error
        error_log("API Error ($code): " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        
        $this->sendResponse($response, $code);
    }
}

// Initialize and run
try {
    $api = new ProductionWMSAPI();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'API initialization failed',
        'message' => $e->getMessage()
    ]);
}