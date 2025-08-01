<?php
require_once __DIR__ . '/../../bootstrap.php';
$config = require __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    die('Database connection factory not configured correctly.');
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

require_once __DIR__ . '/../../models/User.php';
$userModel = new Users($db);

$userId = $_SESSION['user_id'];
$user = $userModel->findById($userId);

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $smtpData = [
        'smtp_host' => trim($_POST['smtp_host'] ?? ''),
        'smtp_port' => $_POST['smtp_port'] !== '' ? intval($_POST['smtp_port']) : null,
        'smtp_user' => trim($_POST['smtp_user'] ?? ''),
        'smtp_pass' => trim($_POST['smtp_pass'] ?? ''),
        'smtp_secure' => trim($_POST['smtp_secure'] ?? '')
    ];
    $userModel->updateUser($userId, $smtpData);
    $message = 'Setarile au fost salvate.';
    $user = $userModel->findById($userId);
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/../../includes/header.php'; ?>
    <title>Profil Utilizator</title>
</head>
<body>
<div class="app">
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
    <div class="main-content">
        <div class="page-container">
            <h1 class="page-title">Profil Utilizator</h1>
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <form method="POST" class="form">
                <div class="form-group">
                    <label>SMTP Host</label>
                    <input type="text" name="smtp_host" class="form-control" value="<?= htmlspecialchars($user['smtp_host'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>SMTP Port</label>
                    <input type="number" name="smtp_port" class="form-control" value="<?= htmlspecialchars($user['smtp_port'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>SMTP Username</label>
                    <input type="text" name="smtp_user" class="form-control" value="<?= htmlspecialchars($user['smtp_user'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>SMTP Password</label>
                    <input type="password" name="smtp_pass" class="form-control" value="<?= htmlspecialchars($user['smtp_pass'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Secure (tls/ssl)</label>
                    <input type="text" name="smtp_secure" class="form-control" value="<?= htmlspecialchars($user['smtp_secure'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primary">Salveaza</button>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</body>
</html>
