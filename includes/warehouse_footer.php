<?php
// File: includes/warehouse_footer.php - Loads JS from warehouse-js folder

// The $currentPage variable should be defined in the main page file.
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
}

// Define warehouse-specific JavaScript files mapping (from warehouse-js folder)
$warehousePageJS = [
    'warehouse_orders' => 'warehouse_orders.js',
    'warehouse_hub' => 'warehouse_hub.js',
    'mobile_picker' => 'mobile_picker.js',
    'mobile_receiving' => 'mobile_receiving.js',
    'warehouse_inventory' => 'warehouse_inventory.js'
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
?>
</body>
</html>