<?php
// includes/header.php - Refactored with asset versioning
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';

// Get current page name for conditional loading
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<meta charset="UTF-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet" />
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<title>WMS Admin Dashboard</title>

<?php
// --- Asset Loading with Cache-Busting Versioning ---

// Define all potential CSS files
$pageSpecificCSS = [
    'index' => 'index.css',
    'users' => 'users.css',
    'products' => 'products.css',
    'locations' => 'locations.css',
    'inventory' => 'inventory.css',
    'orders' => 'orders.css',
    'transactions' => 'transactions.css',
    'smartbill-sync' => 'smartbill-s,ync.css',
    'activities' => 'activities.css',
    'sellers' => 'sellers.css',
    'purchase_orders' => 'purchase_orders.css',
    'product-units' => 'product-units.css',
    'printer-management' => 'printer-management.css',
    'qc_management' => 'qc-management.css',
    'warehouse_settings' => 'warehouse_settings.css'
];

// Create a list of CSS files to load: start with global ones
$cssFilesToLoad = ['global.css', 'sidebar.css'];

// Add page-specific CSS if it exists for the current page
if (isset($pageSpecificCSS[$currentPage])) {
    $cssFilesToLoad[] = $pageSpecificCSS[$currentPage];
}

// Loop through and output versioned CSS link tags
foreach ($cssFilesToLoad as $cssFile) {
    $cssFilePath = BASE_PATH . '/styles/' . $cssFile;
    if (file_exists($cssFilePath)) {
        $cssUrl = in_prod() ? asset('styles/' . $cssFile) : BASE_URL . 'styles/' . $cssFile;
        echo '<link rel="stylesheet" href="' . $cssUrl . '?v=' . filemtime($cssFilePath) . '">';
    }
}

// --- Script Loading with Cache-Busting Versioning ---

// Load universal script with versioning
$universalJsPath = BASE_PATH . '/scripts/universal.js';
if (file_exists($universalJsPath)) {
    $universalJsUrl = in_prod() ? asset('scripts/universal.js') : BASE_URL . 'scripts/universal.js';
    echo '<script src="' . $universalJsUrl . '?v=' . filemtime($universalJsPath) . '" defer></script>';
}

// Load development-only scripts
if (!in_prod()) {
    echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>';
    
    $themeToggleJsPath = BASE_PATH . '/scripts/theme-toggle.js';
    if(file_exists($themeToggleJsPath)) {
        echo '<script src="' . BASE_URL . 'scripts/theme-toggle.js?v=' . filemtime($themeToggleJsPath) . '" defer></script>';
    }
}
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="scripts/sellers-import.js"></script>