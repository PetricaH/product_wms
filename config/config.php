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
        
        if ((strlen($value) > 1) &&
            ((($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]))) {
            $value = substr($value, 1, -1);
        }

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

// FIXED: Environment detection with explicit APP_ENV support
$explicitEnv = getenv('APP_ENV');
if ($explicitEnv) {
    // Use explicit environment setting from .env file
    $isProduction = ($explicitEnv === 'production');
} else {
    // Default: production if it's a web host OR if running from CLI
    // This ensures migrations work properly from command line
    $isProduction = $isWebHost || $isCli;
}

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
        // Enhanced error reporting for debugging
        $errorMsg = "Database connection failed: " . $e->getMessage();
        $errorMsg .= "\nDSN: " . $dsn;
        $errorMsg .= "\nUsername: " . $dbCfg['username'];
        $errorMsg .= "\nPassword: " . (empty($dbCfg['password']) ? 'EMPTY' : 'SET');
        $errorMsg .= "\nEnvironment: " . ($GLOBALS['environment'] ?? 'unknown');
        
        error_log($errorMsg);
        die($errorMsg);
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

    // Print server configuration
    'print_server_url' => getenv('PRINT_SERVER_URL') ?: 'http://86.124.196.102:3000/print_server.php',
    'default_printer'  => getenv('DEFAULT_PRINTER') ?: 'godex_ez6250i',
    'product_unit_printer' => getenv('PRODUCT_UNIT_PRINTER') ?: 'GODEX+G500',

    'label_rotation' => 180,

    // Cargus API credentials (set via environment variables or directly here)
    
    'cargus' => [
        'username' => getenv('CARGUS_USER') ?: '',
        'password' => getenv('CARGUS_PASS') ?: '',
        'subscriptionKey' => getenv('CARGUS_SUBSCRIPTION_KEY') ?: '',
        'api_url'  => getenv('CARGUS_API_URL') ?: 'https://urgentcargus.azure-api.net/api/'
    ],

    'api' => [
        'key' => getenv('WMS_API_KEY') ?: 'wms_webhook_2025_secure!',
        'allowed_origins' => ['*'],
        'rate_limit' => 100,
    ],

    'email' => [
        'host' => 'mail.wartung.ro',
        'port' => 465,
        'encryption' => 'ssl',
        'username' => 'comenzi@wartung.ro',
        'password' => 'WTG7498&$%',
        'from_email' => 'comenzi@wartung.ro',
        'from_name' => 'Wartung - Departament Achizitii',
        'reply_to' => 'comenzi@wartung.ro',
        // Optional IMAP settings used for saving a copy of the email in the Sent folder
        'imap_host' => 'mail.wartung.ro',
        'imap_port' => 993,
        // Accept both valid and self-signed certificates by default
        'imap_flags' => [
            '/imap/ssl/validate-cert',
            '/imap/ssl/novalidate-cert',
            '/imap/ssl'
        ],
        // Ordered list of folders to probe when appending the message
        'imap_sent_folders' => ['INBOX.Sent', 'Sent', 'INBOX/Sent', 'Sent Items', 'Sent Messages']
    ],

    'autoorders' => [
        'min_interval_minutes' => max(1, (int)(getenv('AUTOORDERS_MIN_INTERVAL_MINUTES') ?: 30)),
    ],

    'automation' => [
        'auto_return_user_id' => (int)(getenv('AUTO_RETURN_USER_ID') ?: 0),
        'delta_event_hours' => (int)(getenv('AUTO_RETURN_DELTA_HOURS') ?: 6),
        'notification' => [
            'enabled' => filter_var(getenv('AUTO_RETURN_NOTIFY_ENABLED'), FILTER_VALIDATE_BOOLEAN),
            'recipients' => array_values(array_filter(array_map('trim', explode(',', getenv('AUTO_RETURN_NOTIFY_RECIPIENTS') ?: '')))),
        ],
        'log_file' => getenv('AUTO_RETURN_LOG_FILE') ?: (__DIR__ . '/../storage/logs/automated_returns.log'),
    ],

    'label_left_offset_mm' => 4,   // 3–6 mm de obicei e suficient
    'label_top_offset_mm'  => 0
];
