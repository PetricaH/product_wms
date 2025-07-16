<?php
/**
 * Detailed SmartBill Import Debug
 * Save this as debug_smartbill_detailed.php and run it
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

echo "🔍 Detailed SmartBill Import Debug\n";
echo str_repeat("=", 60) . "\n\n";

require_once BASE_PATH . '/models/SmartBillService.php';
$smartBillService = new SmartBillService($db);

// Enable debug mode
$reflection = new ReflectionClass($smartBillService);
$debugProperty = $reflection->getProperty('debugMode');
$debugProperty->setAccessible(true);
$debugProperty->setValue($smartBillService, true);

// Get private methods for testing
$findProductMethod = $reflection->getMethod('findProductByCode');
$findProductMethod->setAccessible(true);

$updateStockMethod = $reflection->getMethod('updateProductStock');
$updateStockMethod->setAccessible(true);

$updateInfoMethod = $reflection->getMethod('updateProductSmartBillInfo');
$updateInfoMethod->setAccessible(true);

$createProductMethod = $reflection->getMethod('createProductFromSmartBill');
$createProductMethod->setAccessible(true);

$createInventoryMethod = $reflection->getMethod('createInventoryRecord');
$createInventoryMethod->setAccessible(true);

try {
    echo "1. Getting SmartBill stocks data...\n";
    
    // Get the actual stocks data 
    $stocksData = $smartBillService->getStocks('Marfa');
    
    echo "Raw API response structure:\n";
    echo "  - Response type: " . gettype($stocksData) . "\n";
    
    if (is_array($stocksData)) {
        echo "  - Top level keys: " . implode(', ', array_keys($stocksData)) . "\n";
        
        if (isset($stocksData['list'])) {
            echo "  - List type: " . gettype($stocksData['list']) . "\n";
            echo "  - List count: " . count($stocksData['list']) . "\n";
            
            if (count($stocksData['list']) > 0) {
                echo "  - First warehouse structure:\n";
                $firstWarehouse = $stocksData['list'][0];
                echo "    * Warehouse keys: " . implode(', ', array_keys($firstWarehouse)) . "\n";
                
                if (isset($firstWarehouse['warehouse'])) {
                    echo "    * Warehouse info: " . json_encode($firstWarehouse['warehouse']) . "\n";
                }
                
                if (isset($firstWarehouse['products'])) {
                    echo "    * Products type: " . gettype($firstWarehouse['products']) . "\n";
                    echo "    * Products count: " . count($firstWarehouse['products']) . "\n";
                    
                    if (count($firstWarehouse['products']) > 0) {
                        echo "    * First product sample:\n";
                        $firstProduct = $firstWarehouse['products'][0];
                        echo "      - Product keys: " . implode(', ', array_keys($firstProduct)) . "\n";
                        echo "      - Product sample: " . json_encode($firstProduct) . "\n";
                    }
                }
            }
        } else {
            echo "  ❌ 'list' key not found in response!\n";
            echo "  Available keys: " . implode(', ', array_keys($stocksData)) . "\n";
        }
    }
    
    echo "\n" . str_repeat("-", 60) . "\n";
    
    echo "2. Testing individual methods with sample data...\n";
    
    // Test if we can find any existing products
    $testCodes = ['00001217', 'TEST001', 'SAMPLE001']; // Use actual code from API + test codes
    foreach ($testCodes as $testCode) {
        $existingProduct = $findProductMethod->invoke($smartBillService, $testCode);
        if ($existingProduct) {
            echo "  ✅ Found existing product with code: $testCode\n";
            echo "    Product data: " . json_encode($existingProduct) . "\n";
            break;
        }
    }
    
    // Check if we have any products in the database at all
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM products");
    $stmt->execute();
    $totalProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "  📊 Total products in database: $totalProducts\n";
    
    if ($totalProducts > 0) {
        $stmt = $db->prepare("SELECT product_id, sku, name FROM products LIMIT 3");
        $stmt->execute();
        $sampleProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "  📝 Sample existing products:\n";
        foreach ($sampleProducts as $product) {
            echo "    - ID: {$product['product_id']}, SKU: {$product['sku']}, Name: {$product['name']}\n";
        }
    }
    
    echo "\n" . str_repeat("-", 60) . "\n";
    
    echo "4. Checking inventory and locations tables...\n";
    
    // Check if inventory table exists
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE 'inventory'");
        $stmt->execute();
        $inventoryExists = $stmt->fetch() !== false;
        echo "  - Inventory table: " . ($inventoryExists ? "✅ EXISTS" : "❌ MISSING") . "\n";
        
        if ($inventoryExists) {
            $stmt = $db->prepare("DESCRIBE inventory");
            $stmt->execute();
            $inventoryColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo "    Columns: " . implode(', ', $inventoryColumns) . "\n";
        }
    } catch (Exception $e) {
        echo "  ❌ Error checking inventory table: " . $e->getMessage() . "\n";
    }
    
    // Check if locations table exists
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE 'locations'");
        $stmt->execute();
        $locationsExists = $stmt->fetch() !== false;
        echo "  - Locations table: " . ($locationsExists ? "✅ EXISTS" : "❌ MISSING") . "\n";
        
        if ($locationsExists) {
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM locations");
            $stmt->execute();
            $totalLocations = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            echo "    Total locations: $totalLocations\n";
        }
    } catch (Exception $e) {
        echo "  ❌ Error checking locations table: " . $e->getMessage() . "\n";
    }
    
    echo "\n" . str_repeat("-", 60) . "\n";
    
    echo "3. Simulating import process with first 3 products...\n";
    
    if (isset($stocksData['list']) && count($stocksData['list']) > 0) {
        $warehouse = $stocksData['list'][0];
        $warehouseName = $warehouse['warehouse']['warehouseName'] ?? 'Unknown';
        echo "  📦 Processing warehouse: $warehouseName\n";
        
        if (isset($warehouse['products']) && is_array($warehouse['products'])) {
            $productsToTest = array_slice($warehouse['products'], 0, 3);
            
            foreach ($productsToTest as $index => $product) {
                echo "\n  🔍 Testing product " . ($index + 1) . ":\n";
                echo "    - Full product data: " . json_encode($product) . "\n";
                
                $productCode = $product['productCode'] ?? '';
                $productName = $product['productName'] ?? '';
                $quantity = floatval($product['quantity'] ?? 0);
                
                echo "    - Extracted code: '$productCode'\n";
                echo "    - Extracted name: '$productName'\n";
                echo "    - Extracted quantity: $quantity\n";
                
                if (empty($productCode) || empty($productName)) {
                    echo "    ❌ Would skip: missing code or name\n";
                    continue;
                }
                
                // Test findProductByCode using reflection
                echo "    🔍 Checking if product exists...\n";
                try {
                    $existingProduct = $findProductMethod->invoke($smartBillService, $productCode);
                    if ($existingProduct) {
                        echo "    ✅ Product EXISTS in database\n";
                        echo "      - Database ID: {$existingProduct['product_id']}\n";
                        echo "      - Database SKU: {$existingProduct['sku']}\n";
                        echo "      - Database Name: {$existingProduct['name']}\n";
                        
                        // Test update methods
                        echo "    🔄 Testing update methods...\n";
                        $updateResult = $updateStockMethod->invoke($smartBillService, $existingProduct['product_id'], $quantity);
                        echo "      - Stock update result: " . ($updateResult ? "✅ SUCCESS" : "❌ FAILED") . "\n";
                        
                        $infoResult = $updateInfoMethod->invoke($smartBillService, $existingProduct['product_id'], $productCode);
                        echo "      - Info update result: " . ($infoResult ? "✅ SUCCESS" : "❌ FAILED") . "\n";
                        
                    } else {
                        echo "    ➕ Product NOT found - would create new\n";
                        
                        // Test create method
                        echo "    🆕 Testing product creation...\n";
                        $productData = [
                            'code' => $productCode,
                            'name' => $productName,
                            'measuring_unit' => $product['measuringUnit'] ?? 'bucata',
                            'warehouse' => $warehouseName,
                            'quantity' => $quantity
                        ];
                        
                        $newProductId = $createProductMethod->invoke($smartBillService, $productData);
                        echo "      - Create result: " . ($newProductId ? "✅ SUCCESS (ID: $newProductId)" : "❌ FAILED") . "\n";
                        
                        if ($newProductId && $quantity > 0) {
                            $inventoryResult = $createInventoryMethod->invoke($smartBillService, $newProductId, $quantity, $warehouseName);
                            echo "      - Inventory create result: " . ($inventoryResult ? "✅ SUCCESS" : "❌ FAILED") . "\n";
                        }
                    }
                    
                } catch (Exception $e) {
                    echo "    ❌ ERROR: " . $e->getMessage() . "\n";
                }
                
                echo "    " . str_repeat(".", 40) . "\n";
            }
        } else {
            echo "  ❌ No products array found in warehouse data\n";
        }
    } else {
        echo "  ❌ No warehouse data to process\n";
    }
    
} catch (Exception $e) {
    echo "❌ Critical error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n🏁 Detailed debug complete!\n";
?>