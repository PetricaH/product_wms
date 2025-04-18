<?php 
require_once './bootstrap.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
<require_once '/config.php'; ?>
<link rel="stylesheet" href="<?= getAsset('global', 'styles', true) ?>">
    
<?php
    // Determine current page for page-specific assets
    $uri = $_SERVER['REQUEST_URI'];
    $currentPage = 'index';
    
    if (strpos($uri, 'inventory') !== false) {
        $currentPage = 'items';
    } elseif (strpos($uri, 'users') !== false) {
        $currentPage = 'users';
    }
    // Add more conditions as needed
    
    // Load page-specific CSS if exists
    echo loadPageAsset($currentPage, 'styles');
?>

<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet" />

<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$activeClass = 'active';

function getActiveClass($page) {
    global $currentPage, $activeClass;
    return ($currentPage == $page) ? $activeClass : '';
}
?>