<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Auto-detect environment based on server name
$serverName = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
$isProduction = !in_array($serverName, ['localhost', '127.0.0.1', '::1']) && 
                !preg_match('/\.(local|test|dev)$/', $serverName);

$environment = $isProduction ? 'production' : 'development';

// Auto-set base URL with correct protocol
$protocol = $isProduction ? 'https' : 'http';
$baseUrl = $protocol . '://' . $serverName;

// Database configuration - environment specific
if ($isProduction) {
    // Production database settings - UPDATE THESE WITH YOUR ACTUAL CREDENTIALS
    $dbCfg = [
        'driver' => 'mysql',
        'host' => getenv('DB_HOST') ?: 'localhost',        // Your hosting DB host
        'port' => getenv('DB_PORT') ?: '3306',             // Standard MySQL port
        'database' => getenv('DB_NAME') ?: 'product_wms', // Your actual DB name
        'username' => getenv('DB_USER') ?: 'wms_user', // Your DB username
        'password' => getenv('DB_PASS') ?: 'Hr545389###', // Your DB password
        'charset' => 'utf8mb4',
    ];
} else {
    // Development database settings
    $dbCfg = [
        'driver' => 'mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_NAME') ?: 'product_wms',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ];
}

// A closure that, when invoked, returns a configured PDO instance
$connectionFactory = function() use ($dbCfg) {
    $dsn = sprintf(
        '%s:host=%s;port=%s;dbname=%s;charset=%s',
        $dbCfg['driver'],
        $dbCfg['host'],
        $dbCfg['port'],
        $dbCfg['database'],
        $dbCfg['charset']
    );
    
    try {
        return new PDO($dsn, $dbCfg['username'], $dbCfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        // Fail fast if we can't connect
        die("Database connection failed: " . $e->getMessage());
    }
};

return [
    // app environment (development, production, etc.)
    'environment' => $environment,
    
    // Base URL with auto-detected protocol
    'base_url' => $baseUrl,
    
    // raw DB settings, if you need them elsewhere
    'db' => $dbCfg,
    
    // call this to get your PDO instance:
    'connection_factory' => $connectionFactory,
];