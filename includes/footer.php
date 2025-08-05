<?php
// File: includes/footer.php - FIXED JavaScript configuration

if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
}

// Get the config to ensure API key is available
$config = require BASE_PATH . '/config/config.php';
$apiKey = $config['api']['key'] ?? '';

// Fallback: try environment variable if config doesn't have it
if (empty($apiKey)) {
    $apiKey = getenv('WMS_API_KEY') ?: '';
}

// Fallback: try $_ENV and $_SERVER
if (empty($apiKey)) {
    $apiKey = $_ENV['WMS_API_KEY'] ?? $_SERVER['WMS_API_KEY'] ?? '';
}
?>

<script>
    window.APP_CONFIG = {
        // Correctly check if the BASE_URL constant is defined.
        baseUrl: '<?php echo defined('BASE_URL') ? rtrim(BASE_URL, '/') : ''; ?>',
        apiKey: '<?php echo htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8'); ?>'
    };
    
    // Debug logging (remove in production)
    if (window.APP_CONFIG.apiKey) {
        console.log('✅ API Key loaded successfully');
    } else {
        console.warn('⚠️ API Key not loaded - this may cause issues');
    }
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
    'orders' => ['orders.js', 'orders_awb.js'],
    'sellers' => 'sellers.js',
    'purchase_orders' => 'purchase_orders.js',
    'product-units' => 'product-units.js',
    'printer-management' => 'printer-management.js',
    'qc_management' => 'qc-management.js',
    'index' => 'index.js',
    'warehouse_settings' => 'warehouse_settings.js'
];

// Check if specific scripts are defined for the current page in our array.
if (isset($pageSpecificJS[$currentPage])) {
    $jsFiles = $pageSpecificJS[$currentPage];
    if (!is_array($jsFiles)) {
        $jsFiles = [$jsFiles];
    }

    foreach ($jsFiles as $jsFileName) {
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
}
?>

</body>
</html>