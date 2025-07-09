<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        if (!array_key_exists($name, $_ENV) && !getenv($name)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Check if the script is being run from the command line (CLI)
$isCli = (php_sapi_name() === 'cli');

// Auto-detect environment based on server name
$serverName = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
$isWebHost = !in_array($serverName, ['localhost', '127.0.0.1', '::1']) && 
             !preg_match('/\.(local|test|dev)$/', $serverName);

// It's production if it's a non-dev web host OR if it's run from the command line
$isProduction = $isWebHost || $isCli;

$environment = $isProduction ? 'production' : 'development';

// Auto-set base URL with correct protocol
$protocol = $isProduction ? 'https' : 'http';
$baseUrl = $protocol . '://' . $serverName;

// Database configuration - environment specific
if ($isProduction) {
    // Production database settings - UPDATE THESE WITH YOUR ACTUAL CREDENTIALS
    $dbCfg = [
        'driver' => 'mysql',
        'host' => getenv('DB_HOST') ?: '195.133.74.33',        // Your hosting DB host
        'port' => getenv('DB_PORT') ?: '3306',             // Standard MySQL port
        'database' => getenv('DB_NAME') ?: 'product_wms', // Your actual DB name
        'username' => getenv('DB_USER') ?: 'wms_user', // Your DB username
        'password' => getenv('DB_PASS') ?: '', // DB password from environment
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
    
    // Cargus API credentials (set via environment variables or directly here)
    
    'cargus' => [
        'username' => getenv('CARGUS_USER') ?: '',
        'password' => getenv('CARGUS_PASS') ?: '',
        'subscriptionKey' => getenv('CARGUS_SUBSCRIPTION_KEY') ?: '', // Add this line
        'api_url'  => getenv('CARGUS_API_URL') ?: 'https://urgentcargus.azure-api.net/api/'
    ],

    'api' => [
        'key' => getenv('WMS_API_KEY') ?: '',
        'allowed_origins' => ['*'],
        'rate_limit' => 100,
    ],];