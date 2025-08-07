<?php

/**
 * Multi-Warehouse SmartBill Service
 * Imports products from ALL warehouses instead of just "Marfa"
 * Note: SmartBill stocks API does NOT provide pricing data (Cost Unitar/Sold Final)
 */

class MultiWarehouseSmartBillService extends SmartBillService {
    
    /**
     * Sync products from ALL SmartBill warehouses to WMS
     * @param int $maxProducts Maximum number of products to sync
     * @return array Sync results
     */
    public function syncProductsFromSmartBill(int $maxProducts = 100): array {
        $results = [
            'success' => true,
            'processed' => 0,
            'imported' => 0,
            'updated' => 0,
            'inventory_created' => 0,
            'warehouses_processed' => 0,
            'errors' => [],
            'message' => ''
        ];
        
        try {
            // Step 1: Get stocks from ALL warehouses (remove warehouse filter)
            $stocksData = $this->getStocks(); // No warehouse parameter = all warehouses
            
            if (!isset($stocksData['list']) || !is_array($stocksData['list'])) {
                throw new Exception('Invalid stocks data format from SmartBill API');
            }
            
            // Step 2: Process each warehouse
            foreach ($stocksData['list'] as $warehouse) {
                if (!isset($warehouse['products']) || !is_array($warehouse['products'])) {
                    continue;
                }
                
                $warehouseName = $warehouse['warehouse']['warehouseName'] ?? 'Unknown';
                $results['warehouses_processed']++;
                
                // Step 3: Process each product in the warehouse
                foreach ($warehouse['products'] as $product) {
                    try {
                        $results['processed']++;
                        
                        $productCode = $product['productCode'] ?? '';
                        $productName = $product['productName'] ?? '';
                        $measuringUnit = $product['measuringUnit'] ?? 'bucata';
                        $quantity = floatval($product['quantity'] ?? 0);
                        
                        if (empty($productCode) || empty($productName)) {
                            $results['errors'][] = "Missing product code or name for warehouse: {$warehouseName}";
                            continue;
                        }
                        
                        // Step 4: Check if product exists in WMS
                        $existingProduct = $this->findProductByCode($productCode);
                        $productId = null;
                        
                        if ($existingProduct) {
                            // Update existing product
                            $this->updateProductStock($existingProduct['product_id'], $quantity);
                            $this->updateProductSmartBillInfo($existingProduct['product_id'], $productCode);
                            $results['updated']++;
                            $productId = $existingProduct['product_id'];
                        } else {
                            // Create new product
                            $newProductId = $this->createProductFromSmartBill([
                                'code' => $productCode,
                                'name' => $productName,
                                'unit' => $measuringUnit,
                                'quantity' => $quantity,
                                'warehouse' => $warehouseName
                            ]);
                            
                            if ($newProductId) {
                                $results['imported']++;
                                $productId = $newProductId;
                            } else {
                                // Get the last error from the log to include in results
                                $results['errors'][] = "Failed to create product {$productCode} for warehouse {$warehouseName} - check error log for details";
                                continue; // Skip inventory creation if product creation failed
                            }
                        }
                        
                        // Step 5: Create/update inventory record for this warehouse
                        if ($productId) {
                            if ($this->createInventoryRecord($productId, $quantity, $warehouseName)) {
                                $results['inventory_created']++;
                            } else {
                                $results['errors'][] = "Failed to create inventory record for product {$productCode} in warehouse {$warehouseName}";
                            }
                        }
                        
                    } catch (Exception $e) {
                        $results['errors'][] = "Error processing product {$productCode}: " . $e->getMessage();
                    }
                }
            }
            
            $results['message'] = "Processed {$results['processed']} products from {$results['warehouses_processed']} warehouses. " .
                                "Imported: {$results['imported']}, Updated: {$results['updated']}, " .
                                "Inventory records: {$results['inventory_created']}. " .
                                "NOTE: SmartBill stocks API does not provide pricing data (Cost Unitar/Sold Final).";
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['message'] = "Sync failed: " . $e->getMessage();
            $results['errors'][] = $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Find product by code (uses parent class method)
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
     * Create product without pricing information (SmartBill stocks doesn't provide it)
     */
    private function createProductFromSmartBill(array $productData): ?int {
        try {
            $query = "INSERT INTO products (
                        sku, name, description, category, unit_of_measure, 
                        supplier, quantity, min_stock_level, price,
                        smartbill_product_id, smartbill_synced_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $success = $stmt->execute([
                $productData['code'],
                $productData['name'],
                'Imported from SmartBill - ' . ($productData['warehouse'] ?? 'Unknown warehouse'),
                'SmartBill Import',
                $productData['unit'] ?? 'bucata',
                'SmartBill',
                $productData['quantity'] ?? 0,
                5, // default min_stock_level
                0.00, // No pricing available from SmartBill stocks API
                $productData['code']
            ]);

            if ($success) {
                return (int)$this->conn->lastInsertId();
            } else {
                $errorInfo = $stmt->errorInfo();
                error_log("Product creation failed for {$productData['code']}: " . $errorInfo[2]);
                return null;
            }
            
        } catch (PDOException $e) {
            error_log("Error creating product {$productData['code']} from SmartBill: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update product stock (uses parent class method if available, otherwise implements)
     */
    private function updateProductStock(int $productId, float $quantity): bool {
        try {
            $query = "UPDATE products 
                    SET quantity = ?, smartbill_synced_at = NOW() 
                    WHERE product_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$quantity, $productId]);
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Error updating product stock: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update product SmartBill info (uses parent class method if available, otherwise implements)
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
     * Create inventory record (improved with better error handling)
     */
    private function createInventoryRecord(int $productId, float $quantity, string $warehouse): bool {
        try {
            // Get or create default location for this warehouse
            $locationId = $this->getOrCreateWarehouseLocation($warehouse);
            require_once __DIR__ . '/ShelfLevelResolver.php';
            $shelfLevel = ShelfLevelResolver::getCorrectShelfLevel(
                $this->conn,
                $locationId,
                $productId,
                null
            ) ?? 'middle';

            $query = "INSERT INTO inventory (
                        product_id,
                        location_id,
                        shelf_level,
                        quantity,
                        received_at,
                        batch_number
                    ) VALUES (?, ?, ?, ?, NOW(), ?)
                    ON DUPLICATE KEY UPDATE
                        quantity = VALUES(quantity),
                        received_at = NOW()";

            $stmt = $this->conn->prepare($query);
            $batchNumber = 'SB-' . date('Ymd-His') . '-' . $warehouse;

            $success = $stmt->execute([
                $productId,
                $locationId,
                $shelfLevel,
                $quantity,
                $batchNumber
            ]);
            
            return $success;
            
        } catch (PDOException $e) {
            error_log("Error creating inventory record for product {$productId}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get or create warehouse location
     */
    private function getOrCreateWarehouseLocation(string $warehouseName): int {
        try {
            // Check if location exists by location_code
            $query = "SELECT id FROM locations WHERE location_code = ? LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$warehouseName]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                return (int)$existing['id'];
            }
            
            // Create new location with proper fields
            $query = "INSERT INTO locations (location_code, zone, type, status) VALUES (?, ?, 'warehouse', 'active')";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$warehouseName, $warehouseName]);
            
            return (int)$this->conn->lastInsertId();
            
        } catch (PDOException $e) {
            error_log("Error getting/creating warehouse location: " . $e->getMessage());
            return 1; // Default to location ID 1
        }
    }
    
    /**
     * Get all available warehouses from SmartBill
     */
    public function getAllWarehouses(): array {
        try {
            $stocksData = $this->getStocks(); // Get all warehouses
            $warehouses = [];
            
            if (isset($stocksData['list']) && is_array($stocksData['list'])) {
                foreach ($stocksData['list'] as $warehouse) {
                    if (isset($warehouse['warehouse']['warehouseName'])) {
                        $warehouses[] = [
                            'name' => $warehouse['warehouse']['warehouseName'],
                            'type' => $warehouse['warehouse']['warehouseType'] ?? 'unknown',
                            'product_count' => count($warehouse['products'] ?? [])
                        ];
                    }
                }
            }
            
            return $warehouses;
            
        } catch (Exception $e) {
            error_log("Error getting warehouses: " . $e->getMessage());
            return [];
        }
    }
}