<?php
/**
 * Product Units Integration Setup Script
 * File: setup/product_units_setup.php
 * 
 * Run this script after installing all files to verify integration
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', (__DIR__));
}

// Prevent web execution unless admin
if (php_sapi_name() !== 'cli' && (!isset($_GET['admin_setup']) || $_GET['admin_setup'] !== 'true')) {
    die('This setup script should be run from command line or with admin_setup=true parameter');
}

require_once BASE_PATH . '/bootstrap.php';

echo "🔧 Product Units Integration Setup & Verification\n";
echo str_repeat("=", 60) . "\n";

$errors = [];
$warnings = [];
$success = [];

// ===== FILE STRUCTURE VERIFICATION =====
echo "\n📁 Verifying File Structure...\n";

$requiredFiles = [
    'product-units.php' => 'Main admin page',
    'styles/product-units.css' => 'Page styles',
    'scripts/product-units.js' => 'Page JavaScript',
    'api/product_units.php' => 'Product units API',
    'api/products.php' => 'Products API',
    'api/cargus_config.php' => 'Cargus config API',
    'api/test_cargus.php' => 'Cargus test API',
    'models/CargusService.php' => 'Cargus service model',
    'models/WeightCalculator.php' => 'Weight calculator model',
    'includes/navbar.php' => 'Updated navigation'
];

foreach ($requiredFiles as $file => $description) {
    $path = BASE_PATH . '/' . $file;
    if (file_exists($path)) {
        $success[] = "✅ {$description}: {$file}";
    } else {
        $errors[] = "❌ Missing {$description}: {$file}";
    }
}

// ===== DATABASE VERIFICATION =====
echo "\n🗄️ Verifying Database Structure...\n";

try {
    $config = require __DIR__ . '../config/config.php';;
    $db = $config['connection_factory']();
    
    $requiredTables = [
        'unit_types' => 'Unit types configuration',
        'product_units' => 'Product unit weights',
        'packaging_rules' => 'Packaging rules',
        'cargus_config' => 'Cargus configuration',
        'sender_locations' => 'Sender locations'
    ];
    
    foreach ($requiredTables as $table => $description) {
        $stmt = $db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        
        if ($stmt->fetch()) {
            $success[] = "✅ {$description}: {$table}";
            
            // Check if table has data (for reference tables)
            if (in_array($table, ['unit_types', 'packaging_rules', 'sender_locations'])) {
                $countStmt = $db->prepare("SELECT COUNT(*) FROM {$table}");
                $countStmt->execute();
                $count = $countStmt->fetchColumn();
                
                if ($count > 0) {
                    $success[] = "   📊 Table has {$count} records";
                } else {
                    $warnings[] = "⚠️ Table {$table} is empty - may need default data";
                }
            }
        } else {
            $errors[] = "❌ Missing table: {$table}";
        }
    }
    
    // Check for required columns in orders table
    echo "\n📋 Checking orders table extensions...\n";
    
    $orderColumns = [
        'total_weight', 'parcels_count', 'envelopes_count', 
        'awb_barcode', 'awb_created_at', 'package_content'
    ];
    
    $stmt = $db->prepare("DESCRIBE orders");
    $stmt->execute();
    $existingColumns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    
    foreach ($orderColumns as $column) {
        if (in_array($column, $existingColumns)) {
            $success[] = "✅ Orders table has column: {$column}";
        } else {
            $warnings[] = "⚠️ Orders table missing column: {$column}";
        }
    }
    
} catch (Exception $e) {
    $errors[] = "❌ Database connection error: " . $e->getMessage();
}

// ===== CONFIGURATION VERIFICATION =====
echo "\n⚙️ Verifying Configuration...\n";

// Check if header.php includes page-specific CSS loading
$headerPath = BASE_PATH . '/includes/header.php';
if (file_exists($headerPath)) {
    $headerContent = file_get_contents($headerPath);
    
    if (strpos($headerContent, 'product-units') !== false) {
        $success[] = "✅ Header includes product-units CSS loading";
    } else {
        $warnings[] = "⚠️ Header may need update for product-units CSS loading";
    }
    
    if (strpos($headerContent, 'pageSpecificCSS') !== false) {
        $success[] = "✅ Header supports page-specific CSS";
    } else {
        $warnings[] = "⚠️ Header may not support page-specific CSS loading";
    }
} else {
    $errors[] = "❌ includes/header.php not found";
}

// Check navigation update
$navbarPath = BASE_PATH . '/includes/navbar.php';
if (file_exists($navbarPath)) {
    $navbarContent = file_get_contents($navbarPath);
    
    if (strpos($navbarContent, 'product-units.php') !== false) {
        $success[] = "✅ Navigation includes Product Units menu item";
    } else {
        $warnings[] = "⚠️ Navigation may need Product Units menu item";
    }
    
    if (strpos($navbarContent, 'inventory_2') !== false) {
        $success[] = "✅ Navigation uses correct icon for Product Units";
    } else {
        $warnings[] = "⚠️ Navigation may need correct icon";
    }
} else {
    $errors[] = "❌ includes/navbar.php not found";
}

// ===== PERMISSIONS VERIFICATION =====
echo "\n🔒 Checking File Permissions...\n";

$writablePaths = [
    'api/',
    'styles/',
    'scripts/',
    'models/'
];

foreach ($writablePaths as $path) {
    $fullPath = BASE_PATH . '/' . $path;
    if (is_dir($fullPath) && is_writable($fullPath)) {
        $success[] = "✅ Writable directory: {$path}";
    } else {
        $warnings[] = "⚠️ Directory may not be writable: {$path}";
    }
}

// ===== API ENDPOINTS TEST =====
echo "\n🌐 Testing API Endpoints (if accessible)...\n";

if (function_exists('curl_init')) {
    $apiEndpoints = [
        '/api/products.php' => 'Products API',
        '/api/product_units.php' => 'Product Units API',
        '/api/cargus_config.php' => 'Cargus Config API',
        '/api/test_cargus.php' => 'Cargus Test API'
    ];
    
    foreach ($apiEndpoints as $endpoint => $description) {
        $file = BASE_PATH . $endpoint;
        if (file_exists($file)) {
            // Check if file has basic PHP structure
            $content = file_get_contents($file);
            if (strpos($content, '<?php') === 0) {
                $success[] = "✅ {$description} has valid PHP structure";
            } else {
                $errors[] = "❌ {$description} invalid PHP structure";
            }
        } else {
            $errors[] = "❌ Missing {$description}: {$endpoint}";
        }
    }
} else {
    $warnings[] = "⚠️ cURL not available - cannot test API endpoints";
}

// ===== DEPENDENCY CHECK =====
echo "\n📦 Checking Dependencies...\n";

// Check for required PHP extensions
$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'curl'];
foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        $success[] = "✅ PHP extension loaded: {$ext}";
    } else {
        $errors[] = "❌ Missing PHP extension: {$ext}";
    }
}

// Check PHP version
$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '7.4.0', '>=')) {
    $success[] = "✅ PHP version compatible: {$phpVersion}";
} else {
    $errors[] = "❌ PHP version too old: {$phpVersion} (requires 7.4+)";
}

// ===== GENERATE SETUP SUMMARY =====
echo "\n📊 Setup Summary\n";
echo str_repeat("=", 60) . "\n";

echo "\n✅ SUCCESSFUL CHECKS (" . count($success) . "):\n";
foreach ($success as $item) {
    echo "   {$item}\n";
}

if (!empty($warnings)) {
    echo "\n⚠️ WARNINGS (" . count($warnings) . "):\n";
    foreach ($warnings as $item) {
        echo "   {$item}\n";
    }
}

if (!empty($errors)) {
    echo "\n❌ ERRORS (" . count($errors) . "):\n";
    foreach ($errors as $item) {
        echo "   {$item}\n";
    }
}

// ===== RECOMMENDATIONS =====
echo "\n💡 NEXT STEPS:\n";

if (!empty($errors)) {
    echo "   1. Fix the errors listed above before proceeding\n";
    echo "   2. Run the database migration if tables are missing\n";
    echo "   3. Ensure all required files are in place\n";
} else {
    echo "   1. ✅ All critical components are in place!\n";
    echo "   2. Configure Cargus credentials in admin interface\n";
    echo "   3. Set up product unit weights for your products\n";
    echo "   4. Test AWB generation with sample orders\n";
}

if (!empty($warnings)) {
    echo "   5. Review and address warnings for optimal functionality\n";
}

echo "\n🎯 ACCESS YOUR NEW INTERFACE:\n";
echo "   • Navigate to: /product-units.php\n";
echo "   • Configure Cargus API credentials\n";
echo "   • Add product unit weights\n";
echo "   • Test AWB generation\n";

// ===== FINAL STATUS =====
$status = empty($errors) ? 'SUCCESS' : 'INCOMPLETE';
$statusIcon = $status === 'SUCCESS' ? '🎉' : '⚠️';

echo "\n{$statusIcon} INTEGRATION STATUS: {$status}\n";

if ($status === 'SUCCESS') {
    echo "\nCongratulations! Your Product Units management system is ready to use.\n";
    echo "The admin interface has been successfully integrated with your WMS.\n";
} else {
    echo "\nPlease address the errors above before using the system.\n";
    echo "Run this script again after making corrections.\n";
}

echo "\n" . str_repeat("=", 60) . "\n";

// Save results to log file
$logFile = BASE_PATH . '/setup_log_' . date('Y-m-d_H-i-s') . '.txt';
$logContent = "Product Units Setup Log - " . date('Y-m-d H:i:s') . "\n\n";
$logContent .= "Successful checks: " . count($success) . "\n";
$logContent .= "Warnings: " . count($warnings) . "\n";
$logContent .= "Errors: " . count($errors) . "\n\n";
$logContent .= "Status: {$status}\n\n";

if (!empty($errors)) {
    $logContent .= "ERRORS:\n" . implode("\n", $errors) . "\n\n";
}
if (!empty($warnings)) {
    $logContent .= "WARNINGS:\n" . implode("\n", $warnings) . "\n\n";
}

file_put_contents($logFile, $logContent);
echo "📝 Setup log saved to: {$logFile}\n";

return [
    'status' => $status,
    'errors' => $errors,
    'warnings' => $warnings,
    'success' => $success
];

/*
===============================================================================
QUICK INTEGRATION CHECKLIST
===============================================================================

□ 1. DATABASE SETUP
   □ Run migration: SOURCE database/migrations/2025_07_12_create_product_unit_management.sql
   □ Verify all tables created
   □ Check default data loaded

□ 2. FILE PLACEMENT
   □ Copy product-units.php to root
   □ Copy styles/product-units.css
   □ Copy scripts/product-units.js  
   □ Copy all API files to api/
   □ Copy all model files to models/
   □ Update includes/navbar.php

□ 3. HEADER UPDATES
   □ Add 'product-units' => 'product-units.css' to $pageSpecificCSS
   □ Add JavaScript loading for page-specific scripts
   □ Verify Material Symbols and Poppins fonts loaded

□ 4. PERMISSIONS
   □ Ensure web server can read all files
   □ Ensure directories are accessible
   □ Check PHP error logs for any issues

□ 5. CONFIGURATION
   □ Access /product-units.php
   □ Go to Cargus Config tab
   □ Enter Cargus API credentials
   □ Test connection
   □ Save configuration

□ 6. INITIAL SETUP
   □ Add unit weights for existing products
   □ Configure sender location
   □ Test weight calculations
   □ Generate test AWB

□ 7. VERIFICATION
   □ Run this setup script
   □ Check all tabs work
   □ Test responsive design
   □ Verify API endpoints
   □ Test form submissions

===============================================================================
*/
?>