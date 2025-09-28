
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

// Configure and start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = 60 * 60 * 24 * 365 * 10; // 10 years
    ini_set('session.gc_maxlifetime', $sessionLifetime);
    ini_set('session.cookie_lifetime', $sessionLifetime);
    session_set_cookie_params($sessionLifetime);
    session_start();
}

if (!defined('REMEMBER_ME_COOKIE_NAME')) {
    define('REMEMBER_ME_COOKIE_NAME', 'wms_remember');
}

function rememberMeCookieBaseOptions(): array
{
    $params = session_get_cookie_params();

    return [
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => $params['samesite'] ?? 'Lax',
    ];
}

function queueRememberMeCookie(string $value, int $expiresAt): void
{
    $options = rememberMeCookieBaseOptions();
    $options['expires'] = $expiresAt;

    setcookie(REMEMBER_ME_COOKIE_NAME, $value, $options);
}

function forgetRememberMeCookie(): void
{
    $options = rememberMeCookieBaseOptions();
    $options['expires'] = time() - 3600;

    setcookie(REMEMBER_ME_COOKIE_NAME, '', $options);
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Environment detection and URL setup
$serverName = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
$isProduction = !in_array($serverName, ['localhost', '127.0.0.1', '::1']) && 
                !preg_match('/\.(local|test|dev)$/', $serverName);

$protocol = $isProduction ? 'https' : 'http';
$baseUrl = $protocol . '://' . $serverName;
$basePath = $isProduction ? '/' : '/product_wms/';
define('BASE_URL', $baseUrl . $basePath);

// Authentication system
$publicPages = ['login.php', 'logout.php'];
$currentScript = basename($_SERVER['SCRIPT_NAME']);

// Skip authentication for specific API endpoints
$apiWhitelist = [
    'webhook_process_import.php',
    'index.php' // if you have api/index.php
];
$isApiRequest = strpos($_SERVER['REQUEST_URI'], '/api/') !== false &&
                in_array($currentScript, $apiWhitelist);
$requiresAuth = !in_array($currentScript, $publicPages) && !$isApiRequest;

if (!isset($_SESSION['user_id']) && !empty($_COOKIE[REMEMBER_ME_COOKIE_NAME] ?? '')) {
    $cookieValue = $_COOKIE[REMEMBER_ME_COOKIE_NAME];
    $parts = explode(':', $cookieValue, 2);

    if (count($parts) === 2) {
        [$selector, $validator] = $parts;

        try {
            $config = $config ?? require __DIR__ . '/config/config.php';
            $dbFactory = $config['connection_factory'];
            $pdo = $dbFactory();

            require_once __DIR__ . '/models/User.php';
            $usersModel = new Users($pdo);

            $token = $usersModel->findRememberToken($selector);
            $expectedHash = $token ? $token['validator_hash'] : null;

            if ($token && $expectedHash && hash_equals($expectedHash, hash('sha256', $validator)) && strtotime($token['expires_at']) > time()) {
                $user = $usersModel->findById((int)$token['user_id']);

                if ($user && (int)$user['status'] === 1) {
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['extended_session'] = true;

                    $usersModel->deleteRememberTokenById((int)$token['id']);

                    $newSelector = bin2hex(random_bytes(9));
                    $newValidator = bin2hex(random_bytes(32));
                    $newValidatorHash = hash('sha256', $newValidator);
                    $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));

                    $usersModel->createRememberToken((int)$user['id'], $newSelector, $newValidatorHash, $expiresAt);
                    queueRememberMeCookie($newSelector . ':' . $newValidator, time() + (30 * 24 * 60 * 60));
                } else {
                    $usersModel->deleteRememberTokenById((int)$token['id']);
                    forgetRememberMeCookie();
                }
            } else {
                if ($token) {
                    $usersModel->deleteRememberTokenById((int)$token['id']);
                }
                forgetRememberMeCookie();
            }
        } catch (Throwable $rememberException) {
            error_log('Remember me auto-login failed: ' . $rememberException->getMessage());
            forgetRememberMeCookie();
        }
    } else {
        forgetRememberMeCookie();
    }
}

if ($requiresAuth && !isset($_SESSION['user_id'])) {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

// Verify active user session against database
if ($requiresAuth && isset($_SESSION['user_id'])) {
    $config = $config ?? require __DIR__ . '/config/config.php';
    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    require_once __DIR__ . '/models/User.php';
    $usersModel = new Users($db);
    $user = $usersModel->findById($_SESSION['user_id']);
    
    if (!$user || $user['status'] != 1) {
        session_destroy();
        header('Location: ' . getNavUrl('login.php'));
        exit;
    }
}

// Activity logging setup
$config = $config ?? require __DIR__ . '/config/config.php';
require_once __DIR__ . '/models/ActivityLog.php';

try {
    $dbFactory = $config['connection_factory'];
    $logDb = $dbFactory();
    $GLOBALS['activityLog'] = new ActivityLog($logDb);
} catch (Exception $e) {
    $GLOBALS['activityLog'] = null;
    error_log('Activity log init failed: ' . $e->getMessage());
}

// URL helper
function getNavUrl($path) {
    $path = ltrim($path, '/');
    return rtrim(BASE_URL, '/') . '/' . $path;
}

// CSRF helpers
function getCsrfToken() {
    return $_SESSION['csrf_token'] ?? '';
}

function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Activity logging
function logActivity($userId, $action, $entityType, $entityId, $description, $oldValues = null, $newValues = null, $resourceType = null, $resourceId = null) {
    $logger = $GLOBALS['activityLog'] ?? null;
    if ($logger instanceof ActivityLog) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return $logger->log($userId, $action, $entityType, $entityId, $description, $oldValues, $newValues, $resourceType, $resourceId, $ip, $agent);
    }
    return false;
}

// Asset management
function getAsset($file, $type, $isUniversal = false) {
    global $config;
    $isProd = ($config['environment'] ?? 'development') === 'production';
    $fileExt = $type === 'styles' ? 'css' : 'js';
    $baseUrl = rtrim(BASE_URL, '/');
    
    if (!$isProd) {
        return $baseUrl . ($type === 'styles' ? "/styles/{$file}.css" : "/scripts/{$file}.js");
    } else {
        return $baseUrl . "/dist/{$type}/{$file}.min.{$fileExt}";
    }
}

function loadPageAsset($page, $type) {
    $fileExt = $type === 'styles' ? 'css' : 'js';
    $devFolder = $type === 'styles' ? 'styles' : 'scripts';
    $devFile = BASE_PATH . "/{$devFolder}/{$page}.{$fileExt}";
    $prodFile = BASE_PATH . "/dist/{$devFolder}/{$page}.min.{$fileExt}";
    
    if (file_exists($devFile) || file_exists($prodFile)) {
        if ($type === 'styles') {
            return '<link rel="stylesheet" href="' . getAsset($page, $type) . '">';
        } else {
            return '<script src="' . getAsset($page, $type) . '"></script>';
        }
    }
    return '';
}