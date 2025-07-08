<?php
// File: debug_production.php - Place this in your web root to diagnose issues
// Visit: https://notsowms.ro/debug_production.php

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

$debug = [
    'timestamp' => date('Y-m-d H:i:s'),
    'server_info' => [],
    'paths' => [],
    'files' => [],
    'database' => [],
    'errors' => []
];

// Server information
$debug['server_info'] = [
    'PHP_VERSION' => PHP_VERSION,
    'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'unknown',
    'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'unknown',
    'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
    'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? 'unknown',
    'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
    'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'unknown',
    'HTTPS' => $_SERVER['HTTPS'] ?? 'not set'
];

// Path detection
$currentDir = __DIR__;
$debug['paths']['current_dir'] = $currentDir;
$debug['paths']['document_root'] = $_SERVER['DOCUMENT_ROOT'] ?? 'unknown';

// Try to detect BASE_PATH like the API does
$possibleBasePaths = [
    $currentDir,                                // Current directory
    dirname($currentDir),                       // Parent directory
    $_SERVER['DOCUMENT_ROOT'],                  // Web root
    $_SERVER['DOCUMENT_ROOT'] . '/product_wms', // Development path
];

foreach ($possibleBasePaths as $index => $path) {
    $debug['paths']["possible_base_path_$index"] = $path;
    $debug['files']["config_exists_$index"] = file_exists($path . '/config/config.php') ? 'YES' : 'NO';
}

// Check for important files
$importantFiles = [
    'bootstrap.php',
    'config/config.php',
    'api/warehouse/get_orders.php',
    'api/picking/get_next_task.php',
    'includes/warehouse_header.php',
    'models/Order.php'
];

foreach ($importantFiles as $file) {
    foreach ($possibleBasePaths as $basePath) {
        $fullPath = $basePath . '/' . $file;
        if (file_exists($fullPath)) {
            $debug['files'][$file] = $fullPath;
            break;
        }
    }
    
    if (!isset($debug['files'][$file])) {
        $debug['files'][$file] = 'NOT FOUND';
    }
}

// Try to load bootstrap and config
try {
    // Find working base path
    $workingBasePath = null;
    foreach ($possibleBasePaths as $path) {
        if (file_exists($path . '/config/config.php')) {
            $workingBasePath = $path;
            break;
        }
    }
    
    if ($workingBasePath) {
        $debug['paths']['working_base_path'] = $workingBasePath;
        
        // Try to load bootstrap
        if (file_exists($workingBasePath . '/bootstrap.php')) {
            if (!defined('BASE_PATH')) {
                define('BASE_PATH', $workingBasePath);
            }
            require_once $workingBasePath . '/bootstrap.php';
            $debug['bootstrap'] = 'LOADED';
            
            if (defined('BASE_URL')) {
                $debug['BASE_URL'] = BASE_URL;
            }
        }
        
        // Try to load config
        $config = require $workingBasePath . '/config/config.php';
        $debug['config'] = 'LOADED';
        $debug['config_keys'] = array_keys($config);
        
        // Test database connection
        if (isset($config['connection_factory']) && is_callable($config['connection_factory'])) {
            try {
                $dbFactory = $config['connection_factory'];
                $db = $dbFactory();
                $debug['database']['connection'] = 'SUCCESS';
                
                // Test a simple query
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders LIMIT 1");
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $debug['database']['orders_count'] = $result['count'] ?? 'unknown';
                
            } catch (Exception $e) {
                $debug['database']['connection'] = 'FAILED';
                $debug['database']['error'] = $e->getMessage();
            }
        } else {
            $debug['database']['connection'] = 'NO_FACTORY';
        }
        
    } else {
        $debug['errors'][] = 'Could not find working base path with config.php';
    }
    
} catch (Exception $e) {
    $debug['errors'][] = 'Exception: ' . $e->getMessage();
} catch (Error $e) {
    $debug['errors'][] = 'Error: ' . $e->getMessage();
}

// Check PHP extensions
$requiredExtensions = ['pdo', 'pdo_mysql', 'json'];
foreach ($requiredExtensions as $ext) {
    $debug['php_extensions'][$ext] = extension_loaded($ext) ? 'LOADED' : 'MISSING';
}

// Check permissions (basic check)
$debug['permissions']['current_dir_writable'] = is_writable($currentDir) ? 'YES' : 'NO';
if (isset($workingBasePath)) {
    $debug['permissions']['base_path_readable'] = is_readable($workingBasePath) ? 'YES' : 'NO';
}

echo json_encode($debug, JSON_PRETTY_PRINT);
?>