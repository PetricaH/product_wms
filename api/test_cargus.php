<?php
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/models/CargusService.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();
    
    $cargusService = new CargusService($db);
    
    // Test authentication
    $reflection = new ReflectionClass($cargusService);
    $authenticateMethod = $reflection->getMethod('authenticate');
    $authenticateMethod->setAccessible(true);
    
    $authResult = $authenticateMethod->invoke($cargusService);
    
    if ($authResult) {
        // Test token verification
        $verifyResult = $cargusService->verifyToken();
        
        if ($verifyResult) {
            echo json_encode([
                'success' => true,
                'message' => 'Cargus connection successful',
                'token_expiry' => date('Y-m-d H:i:s', $reflection->getProperty('tokenExpiry')->getValue($cargusService))
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Token verification failed'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Authentication failed'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Connection test failed: ' . $e->getMessage()
    ]);
}
?>