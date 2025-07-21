<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// === LOAD .ENV FILE MANUALLY ===
function loadEnvFile($envPath) {
    if (!file_exists($envPath)) {
        return false;
    }
    
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Skip lines without =
        if (strpos($line, '=') === false) {
            continue;
        }
        
        // Parse key=value
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        // Remove quotes if present
        if (preg_match('/^["\'].*["\']$/', $value)) {
            $value = substr($value, 1, -1);
        }
        
        // Set environment variable if not already set
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
    
    return true;
}

// Load .env file from project root
$envPath = __DIR__ . '/.env';
loadEnvFile($envPath);

// === REST OF YOUR EXISTING CONFIG ===

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

    // API Configuration - REQUIRED FOR AWB GENERATION
    'api' => [
        'key' => getenv('WMS_API_KEY') ?: 'your-secure-api-key-here',
        'allowed_origins' => ['*'], // Restrict in production: ['https://yourdomain.com']
        'rate_limit' => 100,
        'timeout' => 30
    ],

    // Cargus API credentials - REQUIRED FOR AWB GENERATION
    'cargus' => [
        'username' => getenv('CARGUS_USER') ?: '',
        'password' => getenv('CARGUS_PASS') ?: '',
        'subscription_key' => getenv('CARGUS_SUBSCRIPTION_KEY') ?: '', // Primary Key from Azure portal
        'api_url' => getenv('CARGUS_API_URL') ?: 'https://urgentcargus.azure-api.net/api/',
        
        // Your company information for sender details
        'company' => [
            'name' => getenv('COMPANY_NAME') ?: '',
            'contact_person' => getenv('COMPANY_CONTACT') ?: '',
            'phone' => getenv('COMPANY_PHONE') ?: '',
            'email' => getenv('COMPANY_EMAIL') ?: '',
            'county_id' => (int)(getenv('COMPANY_COUNTY_ID') ?: 0), // Cargus county ID
            'locality_id' => (int)(getenv('COMPANY_LOCALITY_ID') ?: 0), // Cargus locality ID
            'street_id' => (int)(getenv('COMPANY_STREET_ID') ?: 0), // Cargus street ID
            'building_number' => getenv('COMPANY_BUILDING') ?: '',
            'pickup_location_id' => (int)(getenv('CARGUS_PICKUP_LOCATION_ID') ?: 0), // Your registered pickup location
        ]
    ],
];