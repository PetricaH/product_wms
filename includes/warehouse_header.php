<?php
// includes/warehouse_header.php - FIXED: Production-ready API base URL & CSS Versioning
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';

// Get current page name for conditional loading
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');

// FIXED: Properly construct API base URL for all environments
$apiBase = rtrim(BASE_URL, '/') . '/api';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WMS Warehouse Interface</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">

<?php
// Load global CSS with cache busting
$globalCssFile = 'warehouse_global.css';
$globalCssPath = BASE_PATH . '/styles/warehouse-css/' . $globalCssFile;

if (file_exists($globalCssPath)) {
    $globalCssUrl = in_prod() ? asset('styles/warehouse-css/' . $globalCssFile) : BASE_URL . 'styles/warehouse-css/' . $globalCssFile;
    echo '<link rel="stylesheet" href="' . $globalCssUrl . '?v=' . filemtime($globalCssPath) . '">';
}

// Load page-specific CSS from warehouse-css folder with cache busting
$warehousePageCSS = [
    'warehouse_orders' => 'warehouse_orders.css',
    'warehouse_hub' => 'warehouse_hub.css',
    'mobile_picker' => 'mobile_picker.css',
    'mobile_receiving' => 'mobile_receiving.css',
    'warehouse_inventory' => 'warehouse_inventory.css'
];

if (isset($warehousePageCSS[$currentPage])) {
    $cssFileName = $warehousePageCSS[$currentPage];
    $cssFilePath = BASE_PATH . '/styles/warehouse-css/' . $cssFileName;

    // Check if the file physically exists
    if (file_exists($cssFilePath)) {
        $cssUrl = '';
        
        // Determine the correct URL based on the environment
        if (function_exists('in_prod') && in_prod()) {
            if (function_exists('asset')) {
                $cssUrl = asset('styles/warehouse-css/' . $cssFileName);
            }
        } else {
            // Development environment path
            $cssUrl = BASE_URL . 'styles/warehouse-css/' . $cssFileName;
        }
        
        // Output the link tag with cache busting
        if ($cssUrl) {
            echo '<link rel="stylesheet" href="' . $cssUrl . '?v=' . filemtime($cssFilePath) . '">';
        }
    }
}
?>

<script>
    window.WMS_CONFIG = {
        apiBase: '<?= $apiBase ?>', // FIXED: Now works in both dev and production
        warehouseMode: true,
        currentUser: <?= json_encode([
            'id' => $_SESSION['user_id'] ?? 1,
            'name' => $_SESSION['username'] ?? 'Worker',
            'role' => $_SESSION['role'] ?? 'warehouse'
        ]) ?>
    };
    
    // DEBUG: Verify the API base is correct
    console.log('🔧 API Base URL:', '<?= $apiBase ?>');
    console.log('🔧 BASE_URL:', '<?= BASE_URL ?>');
</script>