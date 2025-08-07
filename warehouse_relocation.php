<?php
// File: warehouse_relocation.php - UI for processing relocation tasks
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

require_once BASE_PATH . '/models/RelocationTask.php';
require_once BASE_PATH . '/models/Product.php';
require_once BASE_PATH . '/models/Location.php';

$taskModel = new RelocationTask($db);
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'complete_task') {
        $taskId = intval($_POST['task_id'] ?? 0);
        if ($taskId > 0 && $taskModel->updateStatus($taskId, 'completed')) {
            $message = 'Taskul a fost marcat ca finalizat.';
            $messageType = 'success';
        } else {
            $message = 'Eroare la actualizarea taskului.';
            $messageType = 'error';
        }
    }
}

$tasks = $taskModel->getReadyTasks();
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/warehouse_header.php'; ?>
    <title>Relocare Stoc - WMS</title>
</head>
<body>
<?php require_once __DIR__ . '/includes/warehouse_navbar.php'; ?>

<div class="main-container">
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>" role="alert">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="tasks-table">
        <table>
            <thead>
                <tr>
                    <th>Produs</th>
                    <th>De la</th>
                    <th>La</th>
                    <th>Cantitate</th>
                    <th>Acțiune</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($tasks)): ?>
                <tr>
                    <td colspan="5" style="text-align:center;">Nu există taskuri de relocare.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($tasks as $task): ?>
                    <tr>
                        <td><?= htmlspecialchars($task['product_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($task['from_location_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($task['to_location_name'] ?? '') ?></td>
                        <td><?= (int)($task['quantity'] ?? 0) ?></td>
                        <td>
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="action" value="complete_task">
                                <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                <button type="submit" class="btn btn-primary">Finalizează</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/warehouse_footer.php'; ?>
</body>
</html>

 