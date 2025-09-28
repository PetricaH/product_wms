<?php
require_once __DIR__ . '/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = $_SESSION['user_id'] ?? 0;
logActivity($userId, 'logout', 'user', $userId, 'User logged out');

$config = $config ?? require __DIR__ . '/config/config.php';

if ($userId) {
    try {
        $dbFactory = $config['connection_factory'];
        $pdo = $dbFactory();

        require_once __DIR__ . '/models/User.php';
        $usersModel = new Users($pdo);
        $usersModel->deleteRememberTokens((int)$userId);
    } catch (Throwable $logoutCleanupError) {
        error_log('Failed to clean remember tokens on logout: ' . $logoutCleanupError->getMessage());
    }
}

forgetRememberMeCookie();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

header('Location: ' . getNavUrl('login.php'));
exit;?>
