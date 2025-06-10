<?php
// AT THE VERY TOP of bootstrap.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

define('BASE_URL', 'http://localhost:3000/'); // You confirmed this is correct

// ADD THIS FUNCTION DEFINITION:
// Function to generate correct navigation URLs
function getNavUrl($path) {
    // Remove any leading slash from the path
    $path = ltrim($path, '/');
    
    // Combine BASE_URL with path, ensuring no double slashes
    return rtrim(BASE_URL, '/') . '/' . $path;
}
// END OF FUNCTION TO ADD

//Get asset URL based on current environment (Your existing function)
function getAsset($file, $type, $isUniversal = false) {
    // Read environment from config via bootstrap.php
    global $config; // This line means $config must be defined before getAsset is called
    $isProd = ($config['environment'] ?? 'development') === 'production';
    
    $fileExt = $type === 'styles' ? 'css' : 'js';
    
    if (!$isProd) {
        // Development environment - unminified files
        if ($type === 'styles') {
            return "/styles/{$file}.css"; // Match your existing structure
        } else {
            return "/scripts/{$file}.js";
        }
    } else {
        // Production environment - minified files with versioning
        $manifestFile = BASE_PATH . "/dist/manifest/{$type}-" . 
                       ($isUniversal ? "universal" : "pages") . "-manifest.json";
        
        if (file_exists($manifestFile)) {
            $manifest = json_decode(file_get_contents($manifestFile), true);
            $fileName = "{$file}.min.{$fileExt}";
            $versionedFile = $manifest[$fileName] ?? $fileName;
            return "/dist/{$type}/{$versionedFile}";
        }
        
        return "/dist/{$type}/{$file}.min.{$fileExt}";
    }
}

//Load page-specific assets (Your existing function)
function loadPageAsset($page, $type) {
    $fileExt = $type === 'styles' ? 'css' : 'js';
    $devFolder = $type === 'styles' ? 'styles' : 'scripts';
    
    // Check if the file exists in development location
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