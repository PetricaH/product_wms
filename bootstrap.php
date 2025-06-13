<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

define('BASE_URL', 'http://localhost/product_wms/');

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
    $baseUrl = '/product_wms';
    
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