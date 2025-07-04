<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

// Auto-detect base URL with correct protocol
$serverName = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
$isProduction = !in_array($serverName, ['localhost', '127.0.0.1', '::1']) && 
                !preg_match('/\.(local|test|dev)$/', $serverName);

$protocol = $isProduction ? 'https' : 'http';
$baseUrl = $protocol . '://' . $serverName;

// For production, assume root directory. For dev, use /product_wms/
$basePath = $isProduction ? '/' : '/product_wms/';
define('BASE_URL', $baseUrl . $basePath);

// Function to generate correct navigation URLs
function getNavUrl($path) {
    // Remove any leading slash from the path
    $path = ltrim($path, '/');
    
    // Combine BASE_URL with path, ensuring no double slashes
    return rtrim(BASE_URL, '/') . '/' . $path;
}

//Get asset URL based on current environment
function getAsset($file, $type, $isUniversal = false) {
    global $config;
    $isProd = ($config['environment'] ?? 'development') === 'production';
    
    $fileExt = $type === 'styles' ? 'css' : 'js';
    $baseUrl = rtrim(BASE_URL, '/');
    
    if (!$isProd) {
        if ($type === 'styles') {
            return $baseUrl . "/styles/{$file}.css";
        } else {
            return $baseUrl . "/scripts/{$file}.js";
        }
    } else {
        return $baseUrl . "/dist/{$type}/{$file}.min.{$fileExt}";
    }
}

//Load page-specific assets
function loadPageAsset($page, $type) {
    $fileExt = $type === 'styles' ? 'css' : 'js';
    $devFolder = $type === 'styles' ? 'styles' : 'scripts';
    
    $devFile = BASE_PATH . "/{$devFolder}/{$page}.{$fileExt}";
    $prodFile = BASE_PATH . "/dist/{$devFolder}/{$page}.min.{$fileExt}";
    
    if (file_exists($devFile) || file_exists($prodFile)) {
        if ($type === 'styles') {
            return '<link rel="stylesheet" href="' . getAsset($page, $type) . '">';
        } else {
            return '<script src="' . getAsset($page, $type) . '"></script>';
        }
    }
    
    return '';
}
?>