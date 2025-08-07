<?php
// File: includes/warehouse_footer.php - Enhanced with Timing & AWB Integration

// The $currentPage variable should be defined in the main page file.
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
}

// Load TimingManager first (required for timing integration)
$timingManagerPath = BASE_PATH . '/scripts/TimingManager.js';
if (file_exists($timingManagerPath)) {
    $timingManagerUrl = in_prod() ? asset('scripts/TimingManager.js') : BASE_URL . 'scripts/TimingManager.js';
    echo '<script src="' . $timingManagerUrl . '?v=' . filemtime($timingManagerPath) . '"></script>';
}

// Load universal warehouse script
$universalJsPath = BASE_PATH . '/scripts/warehouse-js/warehouse_universal.js';
if (file_exists($universalJsPath)) {
    $universalJsUrl = in_prod() ? asset('scripts/warehouse-js/warehouse_universal.js') : BASE_URL . 'scripts/warehouse-js/warehouse_universal.js';
    echo '<script src="' . $universalJsUrl . '?v=' . filemtime($universalJsPath) . '"></script>';
}

// Include orders_awb.js for the orders page (stored in /scripts/)
$specialPageScripts = [
    'mobile_picker' => 'orders_awb.js',
];
if (isset($specialPageScripts[$currentPage])) {
    $jsFileName = $specialPageScripts[$currentPage];
    $jsFilePath = BASE_PATH . '/scripts/' . $jsFileName;
    if (file_exists($jsFilePath)) {
        if (function_exists('in_prod') && in_prod() && function_exists('asset')) {
            $jsUrl = asset('scripts/' . $jsFileName);
        } else {
            $jsUrl = BASE_URL . 'scripts/' . $jsFileName;
        }
        echo '<script src="' . $jsUrl . '?v=' . filemtime($jsFilePath) . '"></script>';
    }
}

// Define warehouse-specific JavaScript files mapping (from warehouse-js folder)
$warehousePageJS = [
    'warehouse_orders'    => 'warehouse_orders.js',
    'warehouse_hub'       => 'warehouse_hub.js',
    'mobile_picker'       => 'mobile_picker.js',        // Will use silent timing
    'warehouse_receiving' => 'warehouse_receiving.js',  // Will use silent timing
    'warehouse_inventory' => 'warehouse_inventory.js',
    'warehouse_relocation' => 'warehouse_relocation.js'
];

// Check if a specific script is defined for the current warehouse page
if (isset($warehousePageJS[$currentPage])) {
    $jsFileName = $warehousePageJS[$currentPage];
    $jsFilePath = BASE_PATH . '/scripts/warehouse-js/' . $jsFileName;

    // Check if the file physically exists
    if (file_exists($jsFilePath)) {
        $jsUrl = '';
        
        // Determine the correct URL based on the environment
        if (function_exists('in_prod') && in_prod()) {
            if (function_exists('asset')) {
                $jsUrl = asset('scripts/warehouse-js/' . $jsFileName);
            }
        } else {
            // Development environment path
            $jsUrl = BASE_URL . 'scripts/warehouse-js/' . $jsFileName;
        }
        
        // Output the script tag with cache busting
        if ($jsUrl) {
            echo '<script src="' . $jsUrl . '?v=' . filemtime($jsFilePath) . '"></script>';
        }
    }
}

// Initialize silent timing for compatible pages
$timingCompatiblePages = ['mobile_picker', 'warehouse_receiving'];
if (in_array($currentPage, $timingCompatiblePages)) {
    echo '<script>';
    echo 'console.log("⏱️ Silent timing integration active for ' . $currentPage . '");';
    echo 'window.TIMING_ENABLED = true;';
    echo '</script>';
}
?>
</body>
</html>