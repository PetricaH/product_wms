<?php
/**
 * Fixed WMS API
 * File: api/index.php (replace your existing one)
 * 
 * Fixed endpoint detection and routing
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../bootstrap.php';
    require_once __DIR__ . '/../models/Order.php';
    require_once __DIR__ . '/../models/Product.php';
    require_once __DIR__ . '/../models/Inventory.php';
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load dependencies: ' . $e->getMessage()
    ]);
    exit;
}

class WMSAPI {
    private $db;
    private $config;
    private $orderModel;
    private $productModel;
    private $inventoryModel;
    
    public function __construct() {
        try {
            // Load database
            $dbConfig = require __DIR__ . '/../config/config.php';
            $this->db = $dbConfig['connection_factory']();
            
            // Load API config
            $this->config = require __DIR__ . '/config.php';
            
            // Initialize models
            $this->orderModel = new Order($this->db);
            $this->productModel = new Product($this->db);
            $this->inventoryModel = new Inventory($this->db);
            
        } catch (Exception $e) {
            $this->sendError('Initialization failed: ' . $e->getMessage(), 500);
        }
        
        // Set headers
        $this->setHeaders();
    }
    
    public function handleRequest(): void {
        try {
            // Handle preflight
            if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                http_response_code(200);
                exit;
            }
            
            // Authenticate
            if (!$this->authenticate()) {
                $this->sendError('Unauthorized - Invalid API key', 401);
                return;
            }
            
            // Get endpoint and method
            $endpoint = $this->getEndpoint();
            $method = $_SERVER['REQUEST_METHOD'];
            
            // Debug logging
            if ($this->config['debug']) {
                error_log("WMS API: {$method} {$endpoint}");
                error_log("Request URI: " . $_SERVER['REQUEST_URI']);
                error_log("GET params: " . json_encode($_GET));
            }
            
            // Route request
            $this->routeRequest($method, $endpoint);
            
        } catch (Exception $e) {
            error_log("WMS API Error: " . $e->getMessage());
            $this->sendError('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    
    private function getEndpoint(): string {
        // Method 1: Direct URL parameter (for non-rewrite mode)
        if (!empty($_GET['endpoint'])) {
            $endpoint = $_GET['endpoint'];
            // Clean up the endpoint
            $endpoint = ltrim($endpoint, '/');
            return $endpoint ?: 'health';
        }
        
        // Method 2: Parse REQUEST_URI for rewrite mode
        $uri = $_SERVER['REQUEST_URI'];
        $path = parse_url($uri, PHP_URL_PATH);
        
        // Remove the base path to get the endpoint
        if (preg_match('#/api/(.+)$#', $path, $matches)) {
            $endpoint = $matches[1];
            // Remove index.php if present
            $endpoint = preg_replace('#^index\.php/?#', '', $endpoint);
            return $endpoint ?: 'health';
        }
        
        // Method 3: PATH_INFO (alternative method)
        if (!empty($_SERVER['PATH_INFO'])) {
            return ltrim($_SERVER['PATH_INFO'], '/') ?: 'health';
        }
        
        // Default to health check
        return 'health';
    }
    
    private function setHeaders(): void {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
        
        // Handle CORS
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, $this->config['allowed_origins'])) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
    }
    
    private function authenticate(): bool {
        // Try multiple authentication methods
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? $_POST['api_key'] ?? '';
        return $apiKey === $this->config['api_key'];
    }
    
    private function routeRequest(string $method, string $endpoint): void {
        // Clean endpoint
        $endpoint = trim($endpoint, '/');
        
        // Add debug info to response for troubleshooting
        $debugInfo = [];
        if ($this->config['debug']) {
            $debugInfo = [
                'detected_endpoint' => $endpoint,
                'method' => $method,
                'request_uri' => $_SERVER['REQUEST_URI'],
                'get_params' => $_GET
            ];
        }
        
        switch (true) {
            // Health check
            case $endpoint === 'health' || $endpoint === '':
                $this->healthCheck($debugInfo);
                break;
                
            // Orders
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
                
            // Inventory
            case $endpoint === 'inventory/check' && $method === 'GET':
                $this->checkInventory();
                break;
                
            case $endpoint === 'inventory/low-stock' && $method === 'GET':
                $this->getLowStockItems();
                break;
                
            // Products
            case $endpoint === 'products' && $method === 'GET':
                $this->getProducts();
                break;
                
            case $endpoint === 'products' && $method === 'POST':
                $this->createProduct();
                break;
                
            default:
                $this->sendError("Endpoint not found: {$method} /{$endpoint}", 404, $debugInfo);
        }
    }
    
    // === ENDPOINT METHODS ===
    
    private function healthCheck(array $debugInfo = []): void {
        $response = [
            'success' => true,
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0.0'
        ];
        
        if (!empty($debugInfo)) {
            $response['debug'] = $debugInfo;
        }
        
        $this->sendResponse($response);
    }
    
    private function checkInventory(): void {
        $skus = $_GET['skus'] ?? '';
        
        if (empty($skus)) {
            $this->sendError('SKUs parameter is required', 400);
            return;
        }
        
        $skuList = array_map('trim', explode(',', $skus));
        $inventory = [];
        
        foreach ($skuList as $sku) {
            if (empty($sku)) continue;
            
            try {
                $product = $this->productModel->findBySku($sku);
                
                if ($product) {
                    $stock = $this->inventoryModel->getStockSummaryBySku($sku);
                    $inventory[] = [
                        'sku' => $sku,
                        'product_name' => $product['name'],
                        'available_quantity' => $stock['total_quantity'] ?? 0,
                        'locations_count' => $stock['locations_count'] ?? 0,
                        'in_stock' => ($stock['total_quantity'] ?? 0) > 0
                    ];
                } else {
                    $inventory[] = [
                        'sku' => $sku,
                        'product_name' => null,
                        'available_quantity' => 0,
                        'locations_count' => 0,
                        'in_stock' => false,
                        'error' => 'Product not found'
                    ];
                }
            } catch (Exception $e) {
                $inventory[] = [
                    'sku' => $sku,
                    'error' => 'Error checking stock: ' . $e->getMessage()
                ];
            }
        }
        
        $this->sendResponse([
            'success' => true,
            'inventory' => $inventory,
            'checked_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    private function createOrder(): void {
        $input = $this->getJsonInput();
        
        // Validate input
        $validation = $this->validateOrderInput($input);
        if (!$validation['valid']) {
            $this->sendError($validation['error'], 400);
            return;
        }
        
        try {
            // Check if order exists
            if ($this->orderExists($input['order_number'])) {
                $this->sendError('Order already exists', 409);
                return;
            }
            
            // Validate inventory
            $inventoryCheck = $this->validateInventoryForOrder($input['items']);
            if (!$inventoryCheck['available']) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Insufficient inventory',
                    'details' => $inventoryCheck['issues']
                ], 400);
                return;
            }
            
            // Create order
            $orderData = [
                'order_number' => $input['order_number'],
                'customer_name' => $input['customer_name'],
                'customer_email' => $input['customer_email'] ?? '',
                'shipping_address' => $input['shipping_address'] ?? '',
                'order_date' => $input['order_date'] ?? date('Y-m-d H:i:s'),
                'status' => Order::STATUS_PENDING,
                'notes' => $input['notes'] ?? 'Created via API from CRM',
                'source' => 'CRM'
            ];
            
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
                $this->sendError('No valid products in order', 400);
                return;
            }
            
            $orderId = $this->orderModel->create($orderData, $orderItems);
            
            if ($orderId) {
                $this->sendResponse([
                    'success' => true,
                    'order_id' => $orderId,
                    'order_number' => $orderData['order_number'],
                    'status' => $orderData['status'],
                    'message' => 'Order created successfully'
                ], 201);
            } else {
                $this->sendError('Failed to create order', 500);
            }
            
        } catch (Exception $e) {
            error_log("Order creation error: " . $e->getMessage());
            $this->sendError('Order creation failed: ' . $e->getMessage(), 500);
        }
    }
    
    private function getOrders(): void {
        try {
            $filters = [
                'status' => $_GET['status'] ?? '',
                'date_from' => $_GET['date_from'] ?? '',
                'date_to' => $_GET['date_to'] ?? '',
                'customer_name' => $_GET['customer_name'] ?? '',
                'order_number' => $_GET['order_number'] ?? ''
            ];
            
            $filters = array_filter($filters);
            $orders = $this->orderModel->getAllOrders($filters);
            
            $this->sendResponse([
                'success' => true,
                'orders' => $orders,
                'count' => count($orders)
            ]);
        } catch (Exception $e) {
            $this->sendError('Failed to get orders: ' . $e->getMessage(), 500);
        }
    }
    
    private function getOrder(int $orderId): void {
        try {
            $order = $this->orderModel->getOrderById($orderId);
            
            if ($order) {
                $this->sendResponse([
                    'success' => true,
                    'order' => $order
                ]);
            } else {
                $this->sendError('Order not found', 404);
            }
        } catch (Exception $e) {
            $this->sendError('Failed to get order: ' . $e->getMessage(), 500);
        }
    }
    
    private function updateOrderStatus(int $orderId): void {
        try {
            $input = $this->getJsonInput();
            
            if (empty($input['status'])) {
                $this->sendError('Status is required', 400);
                return;
            }
            
            $result = $this->orderModel->updateStatus($orderId, $input['status']);
            
            if ($result) {
                $this->sendResponse([
                    'success' => true,
                    'order_id' => $orderId,
                    'new_status' => $input['status'],
                    'message' => 'Order status updated successfully'
                ]);
            } else {
                $this->sendError('Failed to update order status', 500);
            }
        } catch (Exception $e) {
            $this->sendError('Failed to update order status: ' . $e->getMessage(), 500);
        }
    }
    
    private function getLowStockItems(): void {
        try {
            $lowStock = $this->inventoryModel->getLowStockProducts();
            
            $this->sendResponse([
                'success' => true,
                'low_stock_items' => $lowStock,
                'count' => count($lowStock)
            ]);
        } catch (Exception $e) {
            $this->sendError('Failed to get low stock items: ' . $e->getMessage(), 500);
        }
    }
    
    private function getProducts(): void {
        try {
            $search = $_GET['search'] ?? '';
            $category = $_GET['category'] ?? '';
            $limit = intval($_GET['limit'] ?? 100);
            
            $filters = array_filter([
                'search' => $search,
                'category' => $category
            ]);
            
            $products = $this->productModel->getAllProducts($filters, $limit);
            
            $this->sendResponse([
                'success' => true,
                'products' => $products,
                'count' => count($products)
            ]);
        } catch (Exception $e) {
            $this->sendError('Failed to get products: ' . $e->getMessage(), 500);
        }
    }
    
    private function createProduct(): void {
        try {
            $input = $this->getJsonInput();
            
            if (empty($input['sku']) || empty($input['name'])) {
                $this->sendError('SKU and name are required', 400);
                return;
            }
            
            $productId = $this->productModel->create($input);
            
            if ($productId) {
                $product = $this->productModel->findById($productId);
                $this->sendResponse([
                    'success' => true,
                    'product' => $product,
                    'message' => 'Product created successfully'
                ], 201);
            } else {
                $this->sendError('Failed to create product', 500);
            }
        } catch (Exception $e) {
            $this->sendError('Failed to create product: ' . $e->getMessage(), 500);
        }
    }
    
    // === HELPER METHODS ===
    
    private function getJsonInput(): array {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?: [];
    }
    
    private function sendResponse(array $data, int $code = 200): void {
        http_response_code($code);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    private function sendError(string $message, int $code = 400, array $debug = []): void {
        $response = [
            'success' => false,
            'error' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if (!empty($debug)) {
            $response['debug'] = $debug;
        }
        
        $this->sendResponse($response, $code);
    }
    
    private function validateOrderInput(array $input): array {
        $required = ['order_number', 'customer_name', 'items'];
        
        foreach ($required as $field) {
            if (empty($input[$field])) {
                return [
                    'valid' => false,
                    'error' => "Field '{$field}' is required"
                ];
            }
        }
        
        if (!is_array($input['items']) || empty($input['items'])) {
            return [
                'valid' => false,
                'error' => 'Items must be a non-empty array'
            ];
        }
        
        foreach ($input['items'] as $index => $item) {
            if (empty($item['sku']) || empty($item['quantity'])) {
                return [
                    'valid' => false,
                    'error' => "Item {$index}: SKU and quantity are required"
                ];
            }
        }
        
        return ['valid' => true];
    }
    
    private function orderExists(string $orderNumber): bool {
        try {
            $query = "SELECT id FROM orders WHERE order_number = :order_number";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':order_number' => $orderNumber]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("Error checking if order exists: " . $e->getMessage());
            return false;
        }
    }
    
    private function validateInventoryForOrder(array $items): array {
        $issues = [];
        $available = true;
        
        foreach ($items as $item) {
            $sku = $item['sku'] ?? '';
            $requiredQty = floatval($item['quantity'] ?? 0);
            
            if (empty($sku)) continue;
            
            try {
                $product = $this->productModel->findBySku($sku);
                if (!$product) {
                    $issues[] = "Product with SKU '{$sku}' not found";
                    continue;
                }
                
                $stock = $this->inventoryModel->getStockSummaryBySku($sku);
                $availableQty = $stock['total_quantity'] ?? 0;
                
                if ($availableQty < $requiredQty) {
                    $available = false;
                    $issues[] = "SKU '{$sku}': Required {$requiredQty}, Available {$availableQty}";
                }
            } catch (Exception $e) {
                $issues[] = "Error checking SKU '{$sku}': " . $e->getMessage();
            }
        }
        
        return [
            'available' => $available,
            'issues' => $issues
        ];
    }
    
    private function ensureProductExists(array $itemData): ?array {
        $sku = $itemData['sku'] ?? '';
        if (empty($sku)) return null;
        
        try {
            $product = $this->productModel->findBySku($sku);
            
            if (!$product) {
                $productData = [
                    'sku' => $sku,
                    'name' => $itemData['product_name'] ?? $sku,
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
}

// Initialize and run
try {
    $api = new WMSAPI();
    $api->handleRequest();
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'API initialization failed: ' . $e->getMessage()
    ]);
}
?>