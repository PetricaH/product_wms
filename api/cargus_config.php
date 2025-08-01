<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

// if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
//     http_response_code(403);
//     echo json_encode(['error' => 'Access denied']);
//     exit;
// }

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Get current configuration
        $query = "SELECT setting_key, setting_value, setting_type FROM cargus_config WHERE active = 1";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $config = [];
        foreach ($settings as $setting) {
            $value = $setting['setting_value'];
            
            switch ($setting['setting_type']) {
                case 'integer':
                    $value = (int)$value;
                    break;
                case 'decimal':
                    $value = (float)$value;
                    break;
                case 'boolean':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'json':
                    $value = json_decode($value, true);
                    break;
            }
            
            $config[$setting['setting_key']] = $value;
        }
        
        echo json_encode($config);
        
    } elseif ($method === 'POST') {
        // Update configuration
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON input']);
            exit;
        }
        
        $db->beginTransaction();
        
        foreach ($input as $key => $value) {
            // Determine type
            $type = 'string';
            if (is_int($value)) {
                $type = 'integer';
            } elseif (is_float($value)) {
                $type = 'decimal';
            } elseif (is_bool($value)) {
                $type = 'boolean';
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $type = 'json';
                $value = json_encode($value);
            }
            
            $query = "
                INSERT INTO cargus_config (setting_key, setting_value, setting_type, active)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                setting_value = VALUES(setting_value),
                setting_type = VALUES(setting_type),
                updated_at = CURRENT_TIMESTAMP
            ";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$key, $value, $type]);
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Configuration updated successfully'
        ]);
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}