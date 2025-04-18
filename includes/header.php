<require_once '/config.php'; ?>
<link rel="stylesheet" href="styles/navbar.css">
<link rel="stylesheet" href="styles/index.css">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet" />

<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$activeClass = 'active';

function getActiveClass($page) {
    global $currentPage, $activeClass;
    return ($currentPage == $page) ? $activeClass : '';
}
?>