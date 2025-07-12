<?php
/**
 * Utility Scripts & Testing Tools
 * File: utils/maintenance_tools.php
 * 
 * Collection of utility scripts for testing, maintenance, and debugging
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !isset($_GET['admin_token'])) {
    die('Access denied. Use CLI or provide admin token.');
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/models/Order.php';
require_once BASE_PATH . '/models/CargusService.php';
require_once BASE_PATH . '/models/WeightCalculator.php';

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

// Command line tool
if (php_sapi_name() === 'cli') {
    $command = $argv[1] ?? 'help';
    
    switch ($command) {
        case 'test-cargus':
            testCargusConnection($db);
            break;
        case 'recalc-weights':
            recalculateAllWeights($db);
            break;
        case 'check-products':
            checkProductConfiguration($db);
            break;
        case 'test-awb':
            $orderId = $argv[2] ?? null;
            testAWBGeneration($db, $orderId);
            break;
        case 'stats':
            showSystemStats($db);
            break;
        case 'setup-demo':
            setupDemoData($db);
            break;
        default:
            showHelp();
    }
} else {
    // Web interface for admin
    $action = $_GET['action'] ?? 'help';
    header('Content-Type: text/plain');
    
    switch ($action) {
        case 'test-cargus':
            testCargusConnection($db);
            break;
        case 'stats':
            showSystemStats($db);
            break;
        default:
            echo "Available actions: test-cargus, stats\n";
            echo "Add ?action=ACTION&admin_token=YOUR_TOKEN to URL\n";
    }
}

/**
 * Test Cargus API connection
 */
function testCargusConnection($db) {
    echo "🔍 Testing Cargus API Connection...\n";
    echo str_repeat("=", 50) . "\n";
    
    try {
        $cargusService = new CargusService($db);
        
        // Test authentication
        $reflection = new ReflectionClass($cargusService);
        $authenticateMethod = $reflection->getMethod('authenticate');
        $authenticateMethod->setAccessible(true);
        
        echo "🔐 Testing authentication...\n";
        $authResult = $authenticateMethod->invoke($cargusService);
        
        if ($authResult) {
            echo "✅ Authentication successful!\n";
            
            // Get token info
            $tokenProperty = $reflection->getProperty('token');
            $tokenProperty->setAccessible(true);
            $token = $tokenProperty->getValue($cargusService);
            
            $expiryProperty = $reflection->getProperty('tokenExpiry');
            $expiryProperty->setAccessible(true);
            $expiry = $expiryProperty->getValue($cargusService);
            
            echo "📝 Token length: " . strlen($token) . " characters\n";
            echo "⏰ Token expires: " . date('Y-m-d H:i:s', $expiry) . "\n";
            
            // Test token verification
            echo "\n🔍 Testing token verification...\n";
            $verifyResult = $cargusService->verifyToken();
            
            if ($verifyResult) {
                echo "✅ Token verification successful!\n";
            } else {
                echo "❌ Token verification failed!\n";
            }
            
            // Test localities API (optional)
            echo "\n🌍 Testing localities API...\n";
            $localitiesResult = $cargusService->getLocalities(1); // Test with county ID 1
            
            if ($localitiesResult['success']) {
                echo "✅ Localities API working!\n";
                echo "📊 Sample data available\n";
            } else {
                echo "❌ Localities API failed: " . $localitiesResult['error'] . "\n";
            }
            
        } else {
            echo "❌ Authentication failed!\n";
            echo "💡 Check your Cargus credentials in the admin interface\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        echo "💡 Make sure Cargus configuration is set up correctly\n";
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
}

/**
 * Recalculate weights for all orders
 */
function recalculateAllWeights($db) {
    echo "⚖️ Recalculating Weights for All Orders...\n";
    echo str_repeat("=", 50) . "\n";
    
    $orderModel = new Order($db);
    $results = $orderModel->bulkRecalculateShipping();
    
    echo "📊 Results:\n";
    echo "   Processed: " . $results['processed'] . "\n";
    echo "   Successful: " . $results['successful'] . "\n";
    echo "   Failed: " . $results['failed'] . "\n";
    
    if (!empty($results['errors'])) {
        echo "\n❌ Errors:\n";
        foreach ($results['errors'] as $error) {
            echo "   - " . $error . "\n";
        }
    } else {
        echo "\n✅ All calculations completed successfully!\n";
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
}

/**
 * Check product configuration status
 */
function checkProductConfiguration($db) {
    echo "🔍 Checking Product Configuration...\n";
    echo str_repeat("=", 50) . "\n";
    
    // Products without unit configuration
    $stmt = $db->prepare("
        SELECT p.id, p.name, p.code
        FROM products p
        LEFT JOIN product_units pu ON p.id = pu.product_id AND pu.active = 1
        WHERE p.active = 1 AND pu.id IS NULL
        ORDER BY p.name
    ");
    $stmt->execute();
    $unconfiguredProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($unconfiguredProducts)) {
        echo "⚠️ Products without unit configuration (" . count($unconfiguredProducts) . "):\n";
        foreach ($unconfiguredProducts as $product) {
            echo "   - #{$product['id']}: {$product['name']} ({$product['code']})\n";
        }
    } else {
        echo "✅ All products have unit configuration!\n";
    }
    
    // Unit types summary
    echo "\n📊 Unit Types Summary:\n";
    $stmt = $db->prepare("
        SELECT 
            ut.unit_code,
            ut.unit_name,
            COUNT(pu.id) as products_count
        FROM unit_types ut
        LEFT JOIN product_units pu ON ut.id = pu.unit_type_id AND pu.active = 1
        WHERE ut.active = 1
        GROUP BY ut.id, ut.unit_code, ut.unit_name
        ORDER BY products_count DESC, ut.unit_code
    ");
    $stmt->execute();
    $unitStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($unitStats as $stat) {
        echo "   - {$stat['unit_code']} ({$stat['unit_name']}): {$stat['products_count']} products\n";
    }
    
    // Recent orders missing weight
    echo "\n⚖️ Recent orders missing weight calculation:\n";
    $stmt = $db->prepare("
        SELECT id, order_number, status, created_at
        FROM orders 
        WHERE (total_weight IS NULL OR total_weight <= 0)
        AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAYS)
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $ordersWithoutWeight = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($ordersWithoutWeight)) {
        foreach ($ordersWithoutWeight as $order) {
            echo "   - #{$order['id']}: {$order['order_number']} ({$order['status']}) - {$order['created_at']}\n";
        }
    } else {
        echo "   ✅ All recent orders have weight calculated!\n";
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
}

/**
 * Test AWB generation for specific order
 */
function testAWBGeneration($db, $orderId) {
    if (!$orderId) {
        echo "❌ Please provide order ID: php maintenance_tools.php test-awb ORDER_ID\n";
        return;
    }
    
    echo "🧪 Testing AWB Generation for Order #{$orderId}...\n";
    echo str_repeat("=", 50) . "\n";
    
    $orderModel = new Order($db);
    $cargusService = new CargusService($db);
    
    // Step 1: Validate order
    echo "1️⃣ Validating order...\n";
    $validation = $orderModel->validateOrderForAWB($orderId);
    
    if (!$validation['valid']) {
        echo "❌ Validation failed:\n";
        foreach ($validation['errors'] as $error) {
            echo "   - " . $error . "\n";
        }
        return;
    }
    
    echo "✅ Order validation passed!\n";
    $order = $validation['order'];
    
    // Step 2: Show order details
    echo "\n2️⃣ Order details:\n";
    echo "   Order: #{$order['id']} - {$order['order_number']}\n";
    echo "   Status: {$order['status']}\n";
    echo "   Weight: {$order['total_weight']} kg\n";
    echo "   Parcels: {$order['parcels_count']}\n";
    echo "   Recipient: {$order['recipient_name']}\n";
    echo "   Phone: {$order['recipient_phone']}\n";
    echo "   Items: " . count($order['items']) . "\n";
    
    // Step 3: Show calculated shipping
    echo "\n3️⃣ Shipping calculation:\n";
    $weightCalculator = new WeightCalculator($db);
    $shippingData = $weightCalculator->calculateOrderShipping($orderId);
    
    echo "   Total weight: {$shippingData['total_weight']} kg\n";
    echo "   Parcels count: {$shippingData['parcels_count']}\n";
    echo "   Content: {$shippingData['package_content']}\n";
    
    if (!empty($shippingData['shipping_notes'])) {
        echo "   Notes: {$shippingData['shipping_notes']}\n";
    }
    
    echo "\n   Parcel details:\n";
    foreach ($shippingData['parcels_detail'] as $i => $parcel) {
        echo "   - Parcel " . ($i + 1) . ": {$parcel['weight']} kg, {$parcel['items']} items, type: {$parcel['type']}\n";
        if (!empty($parcel['content'])) {
            echo "     Content: {$parcel['content']}\n";
        }
    }
    
    // Step 4: Test AWB generation (dry run first)
    echo "\n4️⃣ Testing AWB generation (DRY RUN)...\n";
    
    // Check if AWB already exists
    if (!empty($order['awb_barcode'])) {
        echo "⚠️ AWB already exists: {$order['awb_barcode']}\n";
        echo "   Created: {$order['awb_created_at']}\n";
        return;
    }
    
    echo "💡 This would generate AWB with Cargus API\n";
    echo "   Run with --confirm flag to actually generate AWB\n";
    
    // If --confirm flag is present, actually generate
    global $argv;
    if (in_array('--confirm', $argv ?? [])) {
        echo "\n5️⃣ Generating real AWB...\n";
        
        $result = $cargusService->generateAWB($order);
        
        if ($result['success']) {
            echo "✅ AWB generated successfully!\n";
            echo "   Barcode: {$result['barcode']}\n";
            
            if (!empty($result['parcelCodes'])) {
                echo "   Parcel codes: " . implode(', ', $result['parcelCodes']) . "\n";
            }
            
            // Update order
            $orderModel->updateAWBInfo($orderId, [
                'awb_barcode' => $result['barcode'],
                'awb_created_at' => date('Y-m-d H:i:s'),
                'cargus_order_id' => $result['cargusOrderId'] ?? ''
            ]);
            
            echo "   Order updated with AWB info\n";
        } else {
            echo "❌ AWB generation failed:\n";
            echo "   Error: {$result['error']}\n";
            
            if (!empty($result['raw'])) {
                echo "   Raw response: " . substr($result['raw'], 0, 200) . "...\n";
            }
        }
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
}

/**
 * Show system statistics
 */
function showSystemStats($db) {
    echo "📊 System Statistics\n";
    echo str_repeat("=", 50) . "\n";
    
    $orderModel = new Order($db);
    $stats = $orderModel->getDashboardStats();
    
    echo "📦 Orders:\n";
    echo "   Ready for AWB: " . $stats['orders_ready_for_awb'] . "\n";
    echo "   AWB generated today: " . $stats['awb_generated_today'] . "\n";
    echo "   Missing weight: " . $stats['orders_missing_weight'] . "\n";
    
    echo "\n🏭 Products:\n";
    echo "   Without units config: " . $stats['products_without_units'] . "\n";
    
    // Additional stats
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = CURDATE()");
    $stmt->execute();
    $ordersToday = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "   Orders created today: " . $ordersToday . "\n";
    
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM product_units WHERE active = 1");
    $stmt->execute();
    $configuredUnits = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "   Configured product units: " . $configuredUnits . "\n";
    
    // Cargus config status
    echo "\n🚀 Cargus Configuration:\n";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM cargus_config WHERE setting_key IN ('subscription_key', 'username') AND active = 1");
    $stmt->execute();
    $cargusConfig = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "   Subscription key: " . (!empty($cargusConfig['subscription_key']) ? "✅ Configured" : "❌ Missing") . "\n";
    echo "   Username: " . (!empty($cargusConfig['username']) ? "✅ Configured" : "❌ Missing") . "\n";
    
    echo "\n" . str_repeat("=", 50) . "\n";
}

/**
 * Setup demo data for testing
 */
function setupDemoData($db) {
    echo "🎭 Setting Up Demo Data...\n";
    echo str_repeat("=", 50) . "\n";
    
    try {
        $db->beginTransaction();
        
        // Insert demo products if they don't exist
        $demoProducts = [
            ['name' => 'Soluție Curățare 10L', 'code' => 'SOL-10L', 'category' => 'Liquids'],
            ['name' => 'Cartus Filter Standard', 'code' => 'CART-STD', 'category' => 'Filters'],
            ['name' => 'Senzor Temperatură', 'code' => 'SENS-TEMP', 'category' => 'Sensors'],
            ['name' => 'Kit Instalare Complet', 'code' => 'KIT-INST', 'category' => 'Kits']
        ];
        
        $productIds = [];
        foreach ($demoProducts as $product) {
            // Check if exists
            $stmt = $db->prepare("SELECT id FROM products WHERE code = ?");
            $stmt->execute([$product['code']]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                $productIds[] = $existing['id'];
                echo "   📦 Product exists: {$product['name']}\n";
            } else {
                // Insert new product
                $stmt = $db->prepare("
                    INSERT INTO products (name, code, category, active, created_at)
                    VALUES (?, ?, ?, 1, NOW())
                ");
                $stmt->execute([$product['name'], $product['code'], $product['category']]);
                $productIds[] = $db->lastInsertId();
                echo "   ➕ Created product: {$product['name']}\n";
            }
        }
        
        // Setup product units
        $unitConfigs = [
            ['product_index' => 0, 'unit_type_id' => 1, 'weight' => 10.5], // Soluție - litri
            ['product_index' => 1, 'unit_type_id' => 3, 'weight' => 0.2],  // Cartus - cartus
            ['product_index' => 2, 'unit_type_id' => 2, 'weight' => 0.3],  // Senzor - buc
            ['product_index' => 3, 'unit_type_id' => 7, 'weight' => 2.5],  // Kit - set
        ];
        
        foreach ($unitConfigs as $config) {
            $productId = $productIds[$config['product_index']];
            
            // Check if unit config exists
            $stmt = $db->prepare("
                SELECT id FROM product_units 
                WHERE product_id = ? AND unit_type_id = ? AND active = 1
            ");
            $stmt->execute([$productId, $config['unit_type_id']]);
            
            if (!$stmt->fetch()) {
                $stmt = $db->prepare("
                    INSERT INTO product_units (
                        product_id, unit_type_id, weight_per_unit, active, created_at
                    ) VALUES (?, ?, ?, 1, NOW())
                ");
                $stmt->execute([$productId, $config['unit_type_id'], $config['weight']]);
                echo "   ⚖️ Configured weight for product #{$productId}: {$config['weight']} kg\n";
            }
        }
        
        // Create a demo customer if needed
        $stmt = $db->prepare("SELECT id FROM customers WHERE email = 'demo@example.com'");
        $stmt->execute();
        $demoCustomer = $stmt->fetch();
        
        if (!$demoCustomer) {
            $stmt = $db->prepare("
                INSERT INTO customers (name, email, phone, active, created_at)
                VALUES ('Client Demo', 'demo@example.com', '0721234567', 1, NOW())
            ");
            $stmt->execute();
            $customerId = $db->lastInsertId();
            echo "   👤 Created demo customer\n";
        } else {
            $customerId = $demoCustomer['id'];
            echo "   👤 Demo customer exists\n";
        }
        
        $db->commit();
        echo "\n✅ Demo data setup completed!\n";
        echo "💡 You can now:\n";
        echo "   - Test weight calculations\n";
        echo "   - Create demo orders\n";
        echo "   - Test AWB generation\n";
        
    } catch (Exception $e) {
        $db->rollBack();
        echo "❌ Error setting up demo data: " . $e->getMessage() . "\n";
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
}

/**
 * Show help information
 */
function showHelp() {
    echo "🛠️ AWB System Maintenance Tools\n";
    echo str_repeat("=", 50) . "\n";
    echo "Usage: php maintenance_tools.php COMMAND [OPTIONS]\n\n";
    echo "Available commands:\n";
    echo "  test-cargus     Test Cargus API connection\n";
    echo "  recalc-weights  Recalculate weights for all orders\n";
    echo "  check-products  Check product configuration status\n";
    echo "  test-awb ID     Test AWB generation for order (add --confirm to actually generate)\n";
    echo "  stats           Show system statistics\n";
    echo "  setup-demo      Setup demo data for testing\n";
    echo "  help            Show this help message\n\n";
    echo "Examples:\n";
    echo "  php maintenance_tools.php test-cargus\n";
    echo "  php maintenance_tools.php test-awb 123\n";
    echo "  php maintenance_tools.php test-awb 123 --confirm\n";
    echo "  php maintenance_tools.php recalc-weights\n\n";
    echo "Web access (admin only):\n";
    echo "  /utils/maintenance_tools.php?action=test-cargus&admin_token=YOUR_TOKEN\n";
    echo "  /utils/maintenance_tools.php?action=stats&admin_token=YOUR_TOKEN\n";
    echo "\n" . str_repeat("=", 50) . "\n";
}

//===============================================================================
// SEPARATE FILE: api/recalculate_weight.php  
//===============================================================================

/**
 * Weight Recalculation API Endpoint
 * File: api/recalculate_weight.php
 */

// Copy this to a separate file: api/recalculate_weight.php
/*

<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/models/Order.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

try {
    $orderModel = new Order($db);
    
    if (isset($input['order_id'])) {
        // Recalculate single order
        $result = $orderModel->recalculateShipping($input['order_id']);
        echo json_encode($result);
    } elseif (isset($input['order_ids']) && is_array($input['order_ids'])) {
        // Recalculate multiple orders
        $result = $orderModel->bulkRecalculateShipping($input['order_ids']);
        echo json_encode($result);
    } else {
        // Recalculate all orders needing it
        $result = $orderModel->bulkRecalculateShipping();
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}

*/

//===============================================================================
// SEPARATE FILE: utils/import_products.php
//===============================================================================

/**
 * Product Import Utility
 * File: utils/import_products.php
 */

// Copy this to a separate file: utils/import_products.php
/*

<?php
// Import products from CSV with automatic unit configuration
// Usage: php import_products.php products.csv

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    die('This script must be run from command line');
}

$csvFile = $argv[1] ?? null;
if (!$csvFile || !file_exists($csvFile)) {
    die("Usage: php import_products.php products.csv\n");
}

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

echo "📦 Importing products from {$csvFile}...\n";

// Expected CSV format: name,code,category,unit_measure,weight_per_unit,fragile,hazardous
$handle = fopen($csvFile, 'r');
$header = fgetcsv($handle);
$imported = 0;
$errors = 0;

while (($row = fgetcsv($handle)) !== false) {
    $data = array_combine($header, $row);
    
    try {
        $db->beginTransaction();
        
        // Insert or update product
        $stmt = $db->prepare("
            INSERT INTO products (name, code, category, active, created_at)
            VALUES (?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            category = VALUES(category),
            updated_at = NOW()
        ");
        $stmt->execute([$data['name'], $data['code'], $data['category']]);
        
        $productId = $db->lastInsertId() ?: $db->query("SELECT id FROM products WHERE code = '{$data['code']}'")->fetchColumn();
        
        // Get unit type ID
        $stmt = $db->prepare("SELECT id FROM unit_types WHERE unit_code = ?");
        $stmt->execute([$data['unit_measure']]);
        $unitTypeId = $stmt->fetchColumn();
        
        if ($unitTypeId) {
            // Insert product unit configuration
            $stmt = $db->prepare("
                INSERT INTO product_units (
                    product_id, unit_type_id, weight_per_unit, fragile, hazardous, active, created_at
                ) VALUES (?, ?, ?, ?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                weight_per_unit = VALUES(weight_per_unit),
                fragile = VALUES(fragile),
                hazardous = VALUES(hazardous),
                updated_at = NOW()
            ");
            $stmt->execute([
                $productId,
                $unitTypeId,
                $data['weight_per_unit'],
                filter_var($data['fragile'] ?? false, FILTER_VALIDATE_BOOLEAN),
                filter_var($data['hazardous'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ]);
        }
        
        $db->commit();
        $imported++;
        echo "✅ Imported: {$data['name']}\n";
        
    } catch (Exception $e) {
        $db->rollBack();
        $errors++;
        echo "❌ Error importing {$data['name']}: " . $e->getMessage() . "\n";
    }
}

fclose($handle);

echo "\n📊 Import completed:\n";
echo "   Imported: {$imported}\n";
echo "   Errors: {$errors}\n";

*/
?>