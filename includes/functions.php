<?php

$conn;

function getTotalIncomeFromDB($conn, $tableName, $columnName) {
    try {
        $query = "SELECT SUM($columnName) as total FROM $tableName";
        $result = $conn->query($query);
        $row = $result->fetch_assoc();
        return $row['total'] ?? 0;
    } catch (Exception $e) {
        error_log("Error getting total: " . $e->getMessage());
        return 0;
    }
}

//Get asset URL based on current environment
function getAsset($file, $type, $isUniversal = false) {
    // Read environment from config via bootstrap.php
    global $config;
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

//Load page-specific assets
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