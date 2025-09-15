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
require_once BASE_PATH . '/models/Inventory.php';
$taskId = intval($_GET['task_id'] ?? 0);
$taskModel = new BarcodeCaptureTask($db);
$task = $taskModel->getTaskById($taskId);
if (!$task) {
    die('Task not found');
}
if (empty($task['assigned_to'])) {
    $taskModel->assignToWorker($taskId, $_SESSION['user_id']);
    $task['assigned_to'] = $_SESSION['user_id'];
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php include_once 'includes/warehouse_header.php'; ?>
    <title>Scan Task - WMS</title>
</head>
<body>
<?php include_once 'includes/warehouse_navbar.php'; ?>
<div class="main-container">
    <h2><?= htmlspecialchars($task['product_name']) ?> - <?= htmlspecialchars($task['location_code']) ?></h2>
    <p id="scan-progress">Unit <?= $task['scanned_quantity'] ?>/<?= $task['expected_quantity'] ?> scanned</p>
    <input type="text" id="scan-input" autofocus placeholder="Scan barcode...">
</div>
<?php include_once 'includes/warehouse_footer.php'; ?>
<script>
window.BARCODE_TASK_CONFIG = {
    taskId: <?= $taskId ?>,
    apiBase: window.WMS_CONFIG ? window.WMS_CONFIG.apiBase : '/api'
};

class BarcodeCapture {
    constructor(config) {
        this.taskId = config.taskId;
        this.apiBase = config.apiBase;
        this.input = document.getElementById('scan-input');
        this.progress = document.getElementById('scan-progress');
        this.expected = <?= $task['expected_quantity'] ?>;
        this.scanned = <?= $task['scanned_quantity'] ?>;
        this.init();
    }
    init() {
        this.input.focus();
        this.input.addEventListener('change', () => this.submit());
    }
    async submit() {
        const code = this.input.value.trim();
        if (!code) return;
        try {
            const res = await fetch(`${this.apiBase}/barcode_capture/scan.php`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({task_id: this.taskId, barcode: code})
            });
            const data = await res.json();
            if (data.status === 'success') {
                this.scanned = data.scanned;
                this.progress.textContent = `Unit ${data.scanned}/${data.expected} scanned`;
                if (data.completed) {
                    alert('Task completed');
                    window.location.href = 'warehouse_barcode_tasks.php';
                }
            } else {
                alert(data.message || 'Error');
            }
        } catch (e) {
            alert('Network error');
        }
        this.input.value = '';
        this.input.focus();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (window.BARCODE_TASK_CONFIG) {
        new BarcodeCapture(window.BARCODE_TASK_CONFIG);
    }
});
</script>
</body>
</html>
