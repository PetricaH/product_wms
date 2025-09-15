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
<div class="main-container scan-container">
    <h2><?= htmlspecialchars($task['product_name']) ?> - <?= htmlspecialchars($task['location_code']) ?></h2>
    <p id="scan-progress">Unit <?= $task['scanned_quantity'] ?>/<?= $task['expected_quantity'] ?> scanned</p>
    <input type="text" id="scan-input" class="barcode-input" autofocus placeholder="Scan barcode...">
    <div id="scanned-list" class="scanned-list"></div>
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
        this.list = document.getElementById('scanned-list');
        this.expected = <?= $task['expected_quantity'] ?>;
        this.scanned = <?= $task['scanned_quantity'] ?>;
        this.editingCard = null;
        this.init();
    }
    init() {
        this.input.focus();
        this.input.addEventListener('change', () => this.submit());
        this.input.addEventListener('keypress', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.submit();
            }
        });
        this.loadScans();
    }
    async loadScans() {
        try {
            const res = await fetch(`${this.apiBase}/barcode_capture/list.php?task_id=${this.taskId}`);
            const data = await res.json();
            if (data.status === 'success') {
                this.scanned = data.scanned;
                this.progress.textContent = `Unit ${data.scanned}/${data.expected} scanned`;
                data.scans.forEach(s => this.addCard(s.barcode, s.inventory_id, false));
            }
        } catch (e) {}
    }
    addCard(barcode, inventoryId, prepend = true) {
        const card = document.createElement('div');
        card.className = 'barcode-card';
        card.dataset.barcode = barcode;
        card.dataset.inventoryId = inventoryId;
        card.textContent = barcode;
        card.addEventListener('click', () => this.startEditing(card));
        if (prepend && this.list.firstChild) {
            this.list.insertBefore(card, this.list.firstChild);
        } else {
            this.list.appendChild(card);
        }
    }
    startEditing(card) {
        const barcode = card.dataset.barcode;
        if (!barcode) return;
        if (!confirm('Delete this barcode?')) return;
        this.deleteScan(card);
    }
    async deleteScan(card) {
        const inventoryId = card.dataset.inventoryId;
        const barcode = card.dataset.barcode;
        try {
            const res = await fetch(`${this.apiBase}/barcode_capture/delete_scan.php`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({task_id: this.taskId, inventory_id: inventoryId, barcode})
            });
            const data = await res.json();
            if (data.status === 'success') {
                this.scanned = data.scanned;
                this.progress.textContent = `Unit ${data.scanned}/${data.expected} scanned`;
                card.classList.add('editing');
                card.dataset.barcode = '';
                card.dataset.inventoryId = '';
                card.textContent = 'Scan barcode...';
                this.editingCard = card;
                this.input.focus();
            } else {
                alert(data.message || 'Error');
            }
        } catch (e) {
            alert('Network error');
        }
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
                if (this.editingCard) {
                    this.editingCard.classList.remove('editing');
                    this.editingCard.dataset.barcode = code;
                    this.editingCard.dataset.inventoryId = data.inventory_id;
                    this.editingCard.textContent = code;
                    this.editingCard = null;
                } else {
                    this.addCard(code, data.inventory_id);
                }
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
