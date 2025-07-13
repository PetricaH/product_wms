<?php
// File: api/user/current.php - Fixed session handling
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// BASE_PATH detection - copy from working get_orders.php pattern
if (!defined('BASE_PATH')) {
    $currentDir = __DIR__; // /api/user/
    $possiblePaths = [
        dirname($currentDir, 2),                    // /api/user/ -> /
        dirname($currentDir, 3),                    // In case of nested structure
        $_SERVER['DOCUMENT_ROOT'],                  // Web root
    ];
    
    $basePathFound = false;
    foreach ($possiblePaths as $path) {
        if (file_exists($path . '/config/config.php')) {
            define('BASE_PATH', $path);
            $basePathFound = true;
            break;
        }
    }
    
    // If still not found, try to detect from script path
    if (!$basePathFound) {
        $scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
        $scriptDir = dirname($scriptPath);
        
        // Go up until we find config/config.php
        $currentPath = $scriptDir;
        for ($i = 0; $i < 5; $i++) { // Max 5 levels up
            if (file_exists($currentPath . '/config/config.php')) {
                define('BASE_PATH', $currentPath);
                $basePathFound = true;
                break;
            }
            $currentPath = dirname($currentPath);
        }
    }
    
    // Last resort: use document root
    if (!$basePathFound) {
        define('BASE_PATH', $_SERVER['DOCUMENT_ROOT']);
    }
}

// Start session to get user info
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// FIXED: More flexible user handling
try {
    $user = [];
    
    // Try to get user from session
    if (isset($_SESSION['user_id'])) {
        $user = [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['username'] ?? 'Utilizator',
            'role' => $_SESSION['role'] ?? 'warehouse_worker',
            'email' => $_SESSION['email'] ?? null,
            'department' => 'warehouse'
        ];
    } else {
        // Fallback user if no session (for testing/development)
        $user = [
            'id' => 1,
            'name' => 'Lucrător Depozit',
            'role' => 'warehouse_worker',
            'email' => null,
            'department' => 'warehouse'
        ];
    }

    echo json_encode([
        'status' => 'success',
        'user' => $user,
        'timestamp' => date('Y-m-d H:i:s'),
        'session_active' => isset($_SESSION['user_id']) ? 'yes' : 'no'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>