<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// Session and authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

// Database connection
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    die("Database connection factory not configured correctly.");
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

require_once BASE_PATH . '/models/ActivityLog.php';
$activityModel = new ActivityLog($db);

// Pagination setup
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($page - 1) * $pageSize;
$totalCount = $activityModel->getTotalCount();
$totalPages = max(1, ceil($totalCount / $pageSize));
$logs = $activityModel->getLogsPaginated($pageSize, $offset);

$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Jurnal Activități - WMS</title>
    <link rel="stylesheet" href="styles/activities.css">
</head>
<body>
<div class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <div class="main-content">
        <div class="page-container">
            <header class="page-header">
                <div class="page-header-content">
                    <h1 class="page-title">
                        <span class="material-symbols-outlined">history</span>
                        Jurnal Activități
                    </h1>
                </div>
            </header>

            <div class="activity-grid">
                <div class="activity-card" style="grid-column: 1 / -1;">
                    <div class="card-header">
                        <h3 class="card-title">
                            <span class="material-symbols-outlined">list</span>
                            Înregistrări Recente
                        </h3>
                    </div>
                    <div class="card-content">
                        <?php if (!empty($logs)): ?>
                            <div class="table-container">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Utilizator</th>
                                                <th>Acțiune</th>
                                                <th>Resursă</th>
                                                <th>Descriere</th>
                                                <th>Data</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($logs as $log): ?>
                                                <tr>
                                                    <td><?= $log['id'] ?></td>
                                                    <td><?= htmlspecialchars($log['username'] ?? 'System') ?></td>
                                                    <td><?= htmlspecialchars($log['action']) ?></td>
                                                    <td><?= htmlspecialchars($log['resource_type']) ?>#<?= htmlspecialchars($log['resource_id']) ?></td>
                                                    <td><?= htmlspecialchars($log['description']) ?></td>
                                                    <td><?= date('d.m.Y H:i', strtotime($log['created_at'])) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <?php if ($totalPages > 1): ?>
                                <div class="pagination-container">
                                    <div class="pagination-info">
                                        Afișare <?= ($offset + 1) ?>-<?= min($offset + $pageSize, $totalCount) ?> din <?= number_format($totalCount) ?> înregistrări
                                    </div>
                                    <div class="pagination-controls">
                                        <?php if ($page > 1): ?>
                                            <a href="?page=1" class="pagination-btn">Prima</a>
                                            <a href="?page=<?= $page - 1 ?>" class="pagination-btn">‹</a>
                                        <?php endif; ?>

                                        <?php
                                        $startPage = max(1, $page - 2);
                                        $endPage = min($totalPages, $page + 2);
                                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                                            <?php if ($i == $page): ?>
                                                <span class="pagination-btn active"><?= $i ?></span>
                                            <?php else: ?>
                                                <a href="?page=<?= $i ?>" class="pagination-btn"><?= $i ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>

                                        <?php if ($page < $totalPages): ?>
                                            <a href="?page=<?= $page + 1 ?>" class="pagination-btn">›</a>
                                            <a href="?page=<?= $totalPages ?>" class="pagination-btn">Ultima</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p>Nu există înregistrări în jurnal.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
