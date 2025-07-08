<?php
// includes/warehouse_header.php - FIXED: Production-ready API base URL
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

<!-- Fonts (matching existing warehouse files) -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">

<!-- CSS Files (using existing global.css + page-specific) -->
<link rel="stylesheet" href="<?= in_prod() ? asset('styles/warehouse-css/warehouse_global.css') : BASE_URL . 'styles/warehouse-css/warehouse_global.css' ?>">

<?php
// Load page-specific CSS from warehouse-css folder
$warehousePageCSS = [
    'warehouse_orders' => 'warehouse_orders.css',
    'warehouse_hub' => 'warehouse_hub.css',
    'mobile_picker' => 'mobile_picker.css',
    'mobile_receiving' => 'mobile_receiving.css',
    'warehouse_inventory' => 'warehouse_inventory.css'
];

if (isset($warehousePageCSS[$currentPage])) {
    $cssFile = $warehousePageCSS[$currentPage];
    if (in_prod()) {
        echo '<link rel="stylesheet" href="' . asset('styles/warehouse-css/' . $cssFile) . '">';
    } else {
        echo '<link rel="stylesheet" href="' . BASE_URL . 'styles/warehouse-css/' . $cssFile . '">';
    }
}
?>

<!-- Warehouse Configuration -->
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