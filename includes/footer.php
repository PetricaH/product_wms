<?php
// File: includes/footer.php - Corrected with script loading order

if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
}
?>

<script>
    window.APP_CONFIG = {
        // Correctly check if the BASE_URL constant is defined.
        baseUrl: '<?php echo defined('BASE_URL') ? rtrim(BASE_URL, '/') : ''; ?>'
    };
</script>

<?php
// Define an array of page-specific JavaScript files.
$pageSpecificJS = [
    'products' => 'products.js',
    'inventory' => 'inventory.js',
    'users' => 'users.js',
    'transactions' => 'transactions.js',
    'theme-toggle' => 'theme-toggle.js',
    'universal' => 'universal.js',
    'smartbill-sync' => 'smartbill_sync.js',
    'locations' => 'locations.js',
    'orders' => 'orders.js',
    'sellers' => 'sellers.js',
    'purchase_orders' => 'purchase_orders.js',
    'product-units' => 'product-units.js',
    'printer-management' => 'printer-management.js'
];

// Check if a specific script is defined for the current page in our array.
if (isset($pageSpecificJS[$currentPage])) {
    $jsFileName = $pageSpecificJS[$currentPage];
    $jsFilePath = BASE_PATH . '/scripts/' . $jsFileName;

    // As a safeguard, we still check if the file physically exists.
    if (file_exists($jsFilePath)) {
        $jsUrl = '';
        
        // Determine the correct URL based on the environment.
        if (function_exists('in_prod') && in_prod()) {
            if (function_exists('asset')) {
                $jsUrl = asset('scripts/' . $jsFileName);
            }
        } else {
            // Development environment path.
            $jsUrl = BASE_URL . 'scripts/' . $jsFileName;
        }
        
        // If a URL was successfully generated, output the script tag.
        if ($jsUrl) {
            echo '<script src="' . $jsUrl . '?v=' . filemtime($jsFilePath) . '" defer></script>';
        }
    }
}
?>

</body>
</html>