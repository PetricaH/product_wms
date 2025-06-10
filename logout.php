<?php
// File: logout.php (in project root)

// Enable full error reporting AT THE VERY TOP
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__); // __DIR__ here refers to the root directory
}

// Check if bootstrap.php exists at the expected path
$bootstrap_path = BASE_PATH . '/bootstrap.php';
echo "Attempting to include: " . htmlspecialchars($bootstrap_path) . "<br>";

if (!file_exists($bootstrap_path)) {
    die("CRITICAL ERROR: bootstrap.php not found at the specified path: " . htmlspecialchars($bootstrap_path));
}

// Try including bootstrap.php
require_once $bootstrap_path;
echo "bootstrap.php should have been included.<br>";

// Check if the function exists AFTER including bootstrap.php
if (function_exists('getNavUrl')) {
    echo "getNavUrl() function IS available.<br>";
} else {
    echo "CRITICAL ERROR: getNavUrl() function IS NOT available after including bootstrap.php.<br>";
    // You can add a die() here if you want to stop further execution if the function isn't found
    // die("Stopping because getNavUrl is not defined.");
}

// Proceed with session destruction (original logic)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// This is line 39 (or around there now with debug echos)
echo "About to call getNavUrl...<br>";
if (!function_exists('getNavUrl')) { // Double check right before calling
    die("FATAL: getNavUrl still not defined right before redirection.");
}
header('Location: ' . getNavUrl('login.php'));
exit;
?>