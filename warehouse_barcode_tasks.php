<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();
require_once BASE_PATH . '/models/BarcodeCaptureTask.php';
$taskModel = new BarcodeCaptureTask($db);
$tasks = $taskModel->getPendingTasks();
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php include_once 'includes/warehouse_header.php'; ?>
    <title>Sarcini Coduri - WMS</title>
</head>
<body>
<?php include_once 'includes/warehouse_navbar.php'; ?>
<div class="main-container">
    <h1>📋 Pending Barcode Tasks</h1>
    <?php if (empty($tasks)): ?>
        <p>Nu există sarcini de scanare.</p>
    <?php else: ?>
        <ul class="task-list">
            <?php foreach ($tasks as $task): ?>
                <li>
                    <a href="warehouse_barcode_scan.php?task_id=<?= $task['task_id'] ?>">
                        Scan <?= $task['expected_quantity'] ?> units - <?= htmlspecialchars($task['product_name']) ?> - Location <?= htmlspecialchars($task['location_code']) ?> (<?= $task['scanned_quantity'] ?>/<?= $task['expected_quantity'] ?>)
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php include_once 'includes/warehouse_footer.php'; ?>
</body>
</html>
