<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

try {
    $config = require BASE_PATH . '/config/config.php';
    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();
    
    // Check what values actually exist in orders table
    $queries = [
        'distinct_types' => "SELECT DISTINCT type FROM orders WHERE type IS NOT NULL",
        'distinct_statuses' => "SELECT DISTINCT status FROM orders WHERE status IS NOT NULL", 
        'total_orders' => "SELECT COUNT(*) as total FROM orders",
        'sample_orders' => "SELECT id, order_number, type, status, customer_name FROM orders LIMIT 5"
    ];
    
    $results = [];
    foreach ($queries as $key => $query) {
        $stmt = $db->prepare($query);
        $stmt->execute();
        $results[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode($results, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>