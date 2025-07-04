<?php
// File: debug_test.php - Place this in your /api/warehouse/ folder
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo json_encode(['step' => 1, 'message' => 'Script started']);

// Define Base Path
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

echo json_encode(['step' => 2, 'base_path' => BASE_PATH]);

// Check if config file exists
$configPath = BASE_PATH . '/config/config.php';
if (!file_exists($configPath)) {
    echo json_encode(['error' => 'Config file missing', 'path' => $configPath]);
    exit;
}

echo json_encode(['step' => 3, 'message' => 'Config file found']);

try {
    $config = require $configPath;
    echo json_encode(['step' => 4, 'message' => 'Config loaded']);
    
    if (!isset($config['connection_factory'])) {
        echo json_encode(['error' => 'connection_factory not found in config']);
        exit;
    }
    
    echo json_encode(['step' => 5, 'message' => 'Connection factory found']);
    
    if (!is_callable($config['connection_factory'])) {
        echo json_encode(['error' => 'connection_factory is not callable']);
        exit;
    }
    
    echo json_encode(['step' => 6, 'message' => 'Connection factory is callable']);
    
    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();
    
    echo json_encode(['step' => 7, 'message' => 'Database connected successfully']);
    
    // Test a simple query
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'step' => 8, 
        'message' => 'Query executed successfully', 
        'order_count' => $result['count']
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Exception caught',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>