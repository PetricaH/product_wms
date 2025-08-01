<?php
// File: models/ExcelSmartBillSyncService.php - Handles SmartBill sync for imported products

class ExcelSmartBillSyncService {
    private $db;
    private $smartBillService;
    private $config;
    
    public function __construct($database) {
        $this->db = $database;
        $this->config = require __DIR__ . '/../config/config.php';
        
        // Initialize SmartBill service
        try {
            require_once __DIR__ . '/SmartBillService.php';
            $this->smartBillService = new SmartBillService($database);
        } catch (Exception $e) {
            throw new Exception('SmartBill service initialization failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync imported products with SmartBill
     * @param array $importedProducts Array of product SKUs that were imported
     * @return array Sync results
     */
    public function syncImportedProducts($importedProducts = []) {
        $results = [
            'success' => true,
            'synced_products' => 0,
            'updated_stock' => 0,
            'new_smartbill_links' => 0,
            'errors' => [],
            'warnings' => [],
            'details' => []
        ];
        
        try {
            // If no specific products provided, sync all recently imported products
            if (empty($importedProducts)) {
                $importedProducts = $this->getRecentlyImportedProducts();
            }
            
            if (empty($importedProducts)) {
                $results['warnings'][] = 'No products found for SmartBill sync';
                return $results;
            }
            
            // Get SmartBill products data
            $smartBillProducts = $this->fetchSmartBillProducts();
            
            if (empty($smartBillProducts)) {
                $results['warnings'][] = 'No products found in SmartBill';
                return $results;
            }
            
            // Sync each imported product
            foreach ($importedProducts as $productSku) {
                try {
                    $syncResult = $this->syncSingleProduct($productSku, $smartBillProducts);
                    
                    if ($syncResult['synced']) {
                        $results['synced_products']++;
                        
                        if ($syncResult['stock_updated']) {
                            $results['updated_stock']++;
                        }
                        
                        if ($syncResult['new_link']) {
                            $results['new_smartbill_links']++;
                        }
                        
                        $results['details'][] = $syncResult['message'];
                    } else {
                        $results['warnings'][] = $syncResult['message'];
                    }
                    
                } catch (Exception $e) {
                    $results['errors'][] = "Sync error for {$productSku}: " . $e->getMessage();
                }
            }
            
            // Additional stock synchronization
            $this->performStockSync($importedProducts, $results);
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = 'SmartBill sync failed: ' . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Get recently imported products (last 24 hours)
     * @return array Array of product SKUs
     */
    private function getRecentlyImportedProducts() {
        $query = "SELECT sku FROM products 
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) 
                 OR updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 ORDER BY created_at DESC
                 LIMIT 100";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }
    
    /**
     * Fetch all products from SmartBill
     * @return array SmartBill products indexed by code
     */
    private function fetchSmartBillProducts() {
        try {
            // Use existing SmartBill service to get stocks
            $smartBillData = $this->smartBillService->getStocks();
            
            if (!$smartBillData || !is_array($smartBillData)) {
                throw new Exception('Invalid SmartBill response');
            }
            
            $products = [];
            
            // Process SmartBill response structure
            if (isset($smartBillData['warehouses']) && is_array($smartBillData['warehouses'])) {
                foreach ($smartBillData['warehouses'] as $warehouse) {
                    if (isset($warehouse['products']) && is_array($warehouse['products'])) {
                        foreach ($warehouse['products'] as $product) {
                            $code = $product['productCode'] ?? $product['code'] ?? '';
                            if (!empty($code)) {
                                $products[$code] = [
                                    'code' => $code,
                                    'name' => $product['productName'] ?? $product['name'] ?? '',
                                    'quantity' => floatval($product['quantity'] ?? 0),
                                    'unit' => $product['measuringUnit'] ?? $product['unit'] ?? 'bucata',
                                    'warehouse' => $warehouse['name'] ?? 'Default',
                                    'price' => floatval($product['price'] ?? 0),
                                    'raw_data' => $product
                                ];
                            }
                        }
                    }
                }
            }
            
            return $products;
            
        } catch (Exception $e) {
            throw new Exception('Failed to fetch SmartBill products: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync a single product with SmartBill
     * @param string $productSku Local product SKU
     * @param array $smartBillProducts SmartBill products data
     * @return array Sync result
     */
    private function syncSingleProduct($productSku, $smartBillProducts) {
        $result = [
            'synced' => false,
            'stock_updated' => false,
            'new_link' => false,
            'message' => ''
        ];
        
        // Get local product
        $localProduct = $this->getLocalProduct($productSku);
        if (!$localProduct) {
            $result['message'] = "Local product not found: {$productSku}";
            return $result;
        }
        
        // Look for matching SmartBill product
        $smartBillProduct = null;
        
        // First, try exact SKU match
        if (isset($smartBillProducts[$productSku])) {
            $smartBillProduct = $smartBillProducts[$productSku];
        } else {
            // Try to find by existing SmartBill ID
            $smartBillId = $localProduct['smartbill_product_id'];
            if (!empty($smartBillId) && isset($smartBillProducts[$smartBillId])) {
                $smartBillProduct = $smartBillProducts[$smartBillId];
            } else {
                // Try fuzzy matching by name
                $smartBillProduct = $this->findProductByName($localProduct['name'], $smartBillProducts);
            }
        }
        
        if (!$smartBillProduct) {
            $result['message'] = "No matching SmartBill product found for: {$productSku}";
            return $result;
        }
        
        // Update local product with SmartBill data
        $updateData = [];
        $hasUpdates = false;
        
        // Link SmartBill product if not already linked
        if (empty($localProduct['smartbill_product_id']) || 
            $localProduct['smartbill_product_id'] !== $smartBillProduct['code']) {
            $updateData['smartbill_product_id'] = $smartBillProduct['code'];
            $result['new_link'] = true;
            $hasUpdates = true;
        }
        
        // Update stock quantity if different
        if ($localProduct['quantity'] != $smartBillProduct['quantity']) {
            $updateData['quantity'] = $smartBillProduct['quantity'];
            $result['stock_updated'] = true;
            $hasUpdates = true;
        }
        
        // Update price if SmartBill has a price and local doesn't
        if ($smartBillProduct['price'] > 0 && $localProduct['price'] == 0) {
            $updateData['price'] = $smartBillProduct['price'];
            $hasUpdates = true;
        }
        
        if ($hasUpdates) {
            $updateData['smartbill_synced_at'] = date('Y-m-d H:i:s');
            
            if ($this->updateLocalProduct($localProduct['product_id'], $updateData)) {
                $result['synced'] = true;
                $result['message'] = "Synced {$productSku} with SmartBill product {$smartBillProduct['code']}";
                
                // Create inventory record if stock was updated
                if ($result['stock_updated'] && $smartBillProduct['quantity'] > 0) {
                    $this->createInventoryRecord(
                        $localProduct['product_id'], 
                        $smartBillProduct['quantity'], 
                        $smartBillProduct['warehouse']
                    );
                }
            } else {
                $result['message'] = "Failed to update local product: {$productSku}";
            }
        } else {
            $result['synced'] = true;
            $result['message'] = "Product {$productSku} already in sync";
        }
        
        return $result;
    }
    
    /**
     * Find SmartBill product by name similarity
     * @param string $productName Local product name
     * @param array $smartBillProducts SmartBill products
     * @return array|null Matching product or null
     */
    private function findProductByName($productName, $smartBillProducts) {
        $bestMatch = null;
        $bestScore = 0;
        
        $cleanName = strtolower(trim($productName));
        
        foreach ($smartBillProducts as $smartBillProduct) {
            $smartBillName = strtolower(trim($smartBillProduct['name']));
            
            // Calculate similarity
            $score = 0;
            
            // Exact match
            if ($cleanName === $smartBillName) {
                return $smartBillProduct;
            }
            
            // Substring match
            if (strpos($smartBillName, $cleanName) !== false || strpos($cleanName, $smartBillName) !== false) {
                $score = 0.8;
            } else {
                // Levenshtein distance for fuzzy matching
                $distance = levenshtein($cleanName, $smartBillName);
                $maxLen = max(strlen($cleanName), strlen($smartBillName));
                $score = 1 - ($distance / $maxLen);
            }
            
            if ($score > $bestScore && $score > 0.7) { // 70% similarity threshold
                $bestScore = $score;
                $bestMatch = $smartBillProduct;
            }
        }
        
        return $bestMatch;
    }
    
    /**
     * Get local product by SKU
     * @param string $sku Product SKU
     * @return array|null Product data
     */
    private function getLocalProduct($sku) {
        $query = "SELECT * FROM products WHERE sku = ? LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$sku]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Update local product with sync data
     * @param int $productId Product ID
     * @param array $updateData Data to update
     * @return bool Success status
     */
    private function updateLocalProduct($productId, $updateData) {
        if (empty($updateData)) {
            return true;
        }
        
        $fields = [];
        $params = [':id' => $productId];
        
        foreach ($updateData as $field => $value) {
            $fields[] = "{$field} = :{$field}";
            $params[":{$field}"] = $value;
        }
        
        $query = "UPDATE products SET " . implode(', ', $fields) . " WHERE product_id = :id";
        
        try {
            $stmt = $this->db->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error updating product {$productId}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create inventory record for synced stock
     * @param int $productId Product ID
     * @param float $quantity Stock quantity
     * @param string $warehouse Warehouse name
     * @return bool Success status
     */
    private function createInventoryRecord($productId, $quantity, $warehouse) {
        try {
            // Get or create location for warehouse
            $locationId = $this->getOrCreateWarehouseLocation($warehouse);
            
            $query = "INSERT INTO inventory (
                        product_id, location_id, quantity, 
                        received_at, batch_number, shelf_level
                      ) VALUES (?, ?, ?, NOW(), ?, 'middle')
                      ON DUPLICATE KEY UPDATE
                        quantity = VALUES(quantity),
                        received_at = VALUES(received_at)";
            
            $batchNumber = 'SB-' . date('YmdHi') . '-' . $productId;
            
            $stmt = $this->db->prepare($query);
            return $stmt->execute([$productId, $locationId, $quantity, $batchNumber]);
            
        } catch (Exception $e) {
            error_log("Error creating inventory record: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get or create warehouse location
     * @param string $warehouseName Warehouse name
     * @return int Location ID
     */
    private function getOrCreateWarehouseLocation($warehouseName) {
        // Try to find existing location
        $query = "SELECT id FROM locations WHERE name = ? LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$warehouseName]);
        $locationId = $stmt->fetchColumn();
        
        if ($locationId) {
            return $locationId;
        }
        
        // Create new location
        $query = "INSERT INTO locations (name, type, description, active, created_at) 
                  VALUES (?, 'warehouse', ?, 1, NOW())";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$warehouseName, "SmartBill warehouse: {$warehouseName}"]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Perform additional stock synchronization
     * @param array $productSkus Product SKUs to sync
     * @param array &$results Results array to update
     */
    private function performStockSync($productSkus, &$results) {
        try {
            // Use the existing SmartBill service stock sync if available
            if (method_exists($this->smartBillService, 'syncProductsFromSmartBill')) {
                $stockSyncResult = $this->smartBillService->syncProductsFromSmartBill(count($productSkus));
                
                if ($stockSyncResult['success']) {
                    $results['details'][] = "Additional stock sync completed: {$stockSyncResult['message']}";
                } else {
                    $results['warnings'][] = "Stock sync had issues: {$stockSyncResult['message']}";
                }
            }
        } catch (Exception $e) {
            $results['warnings'][] = "Additional stock sync failed: " . $e->getMessage();
        }
    }
    
    /**
     * Get sync statistics for reporting
     * @return array Sync statistics
     */
    public function getSyncStatistics() {
        try {
            $query = "SELECT 
                        COUNT(*) as total_products,
                        COUNT(CASE WHEN smartbill_product_id IS NOT NULL AND smartbill_product_id != '' THEN 1 END) as linked_products,
                        COUNT(CASE WHEN smartbill_synced_at IS NOT NULL THEN 1 END) as synced_products,
                        COUNT(CASE WHEN smartbill_synced_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as recently_synced
                      FROM products";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [
                'total_products' => 0,
                'linked_products' => 0,
                'synced_products' => 0,
                'recently_synced' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
}
?>