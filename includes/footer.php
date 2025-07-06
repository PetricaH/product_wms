<?php
// File: includes/footer.php
// This footer explicitly checks for and loads page-specific JavaScript,
// matching the logic from header.php for better reliability.

// The $currentPage variable is assumed to be defined in the main page file.
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
}

// Define an array of page-specific JavaScript files.
// This mirrors the CSS loading logic in header.php and is more robust.
$pageSpecificJS = [
    'products' => 'products.js',
    'inventory' => 'inventory.js',
    'users' => 'users.js',
    'transactions' => 'transactions.js',
    'theme-toggle' => 'theme-toggle.js',
    'universal' => 'universal.js'
    // Add any other page-specific script mappings here.
];

// Check if a specific script is defined for the current page in our array.
if (isset($pageSpecificJS[$currentPage])) {
    $jsFileName = $pageSpecificJS[$currentPage];
    $jsFilePath = BASE_PATH . '/scripts/' . $jsFileName;

    // As a safeguard, we still check if the file physically exists.
    if (file_exists($jsFilePath)) {
        $jsUrl = '';
        
        // Determine the correct URL based on the environment, matching header.php logic.
        if (function_exists('in_prod') && in_prod()) {
            if (function_exists('asset')) {
                $jsUrl = asset('scripts/' . $jsFileName);
            }
        } else {
            // Development environment path.
            $jsUrl = '/product_wms/scripts/' . $jsFileName;
        }
        
        // If a URL was successfully generated, output the script tag.
        if ($jsUrl) {
            // Use defer for consistency and add a version number to prevent caching issues.
            echo '<script src="' . $jsUrl . '?v=' . filemtime($jsFilePath) . '" defer></script>';
        }
    }
}
?>
</body>
</html>
