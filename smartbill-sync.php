<?php
/**
 * SmartBill Sync Dashboard
 * Web interface for monitoring and managing SmartBill synchronization
 */

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin
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

// Include required files
require_once BASE_PATH . '/models/SmartBillService.php';
require_once BASE_PATH . '/smartbill_sync_service.php';

$smartBillService = new SmartBillService($db);
$syncService = new SmartBillSyncService($db);

// Handle AJAX requests
if (isset($_GET['action']) && $_GET['action'] === 'ajax') {
    header('Content-Type: application/json');
    
    $ajaxAction = $_POST['ajax_action'] ?? $_GET['ajax_action'] ?? '';
    
    switch ($ajaxAction) {
        case 'manual_sync':
            $syncType = $_POST['sync_type'] ?? 'invoice_pull';
            $result = $syncService->manualSync($syncType);
            echo json_encode($result);
            break;
            
        case 'get_status':
            $status = $syncService->getSyncStatus();
            echo json_encode($status);
            break;
            
        case 'test_connection':
            $result = $smartBillService->testConnection();
            echo json_encode($result);
            break;
            
        case 'update_config':
            $key = $_POST['config_key'] ?? '';
            $value = $_POST['config_value'] ?? '';
            $encrypt = in_array($key, ['api_username', 'api_token']);
            
            $result = $smartBillService->updateConfig($key, $value, $encrypt);
            echo json_encode(['success' => $result]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_schedule':
            try {
                $syncType = $_POST['sync_type'] ?? '';
                $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
                $intervalMinutes = intval($_POST['interval_minutes'] ?? 15);
                $maxItems = intval($_POST['max_items_per_run'] ?? 50);
                
                $query = "UPDATE smartbill_sync_schedule 
                         SET is_enabled = ?, interval_minutes = ?, max_items_per_run = ?
                         WHERE sync_type = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$isEnabled, $intervalMinutes, $maxItems, $syncType]);
                
                $message = 'Programarea a fost actualizată cu succes.';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Eroare la actualizarea programării: ' . $e->getMessage();
                $messageType = 'error';
            }
            break;
    }
}

// Get data for display
$syncStatus = $syncService->getSyncStatus();
$recentLogs = [];
$pendingInvoices = [];
$syncSchedule = [];

try {
    // Get recent sync logs
    $stmt = $db->prepare("SELECT * FROM smartbill_sync_log ORDER BY created_at DESC LIMIT 20");
    $stmt->execute();
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get pending invoices
    $stmt = $db->prepare("SELECT * FROM view_smartbill_pending_invoices ORDER BY created_at ASC LIMIT 50");
    $stmt->execute();
    $pendingInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get sync schedule
    $stmt = $db->prepare("SELECT * FROM smartbill_sync_schedule ORDER BY sync_type");
    $stmt->execute();
    $syncSchedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error loading sync dashboard data: " . $e->getMessage());
}

$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>SmartBill Sincronizare - WMS</title>
    <style>
        .sync-dashboard {
            padding: 2rem;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid #007bff;
        }
        
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-card.danger { border-left-color: #dc3545; }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .sync-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .content-tabs {
            margin-bottom: 2rem;
        }
        
        .tab-buttons {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            border-bottom: 1px solid #dee2e6;
        }
        
        .tab-button {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .tab-button.active {
            border-bottom-color: #007bff;
            color: #007bff;
            font-weight: 500;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .schedule-form {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }
        
        .logs-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .logs-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .logs-table th,
        .logs-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        .logs-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-success { background: #d4edda; color: #155724; }
        .status-warning { background: #fff3cd; color: #856404; }
        .status-danger { background: #f8d7da; color: #721c24; }
        .status-info { background: #d1ecf1; color: #0c5460; }
        
        .config-section {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .auto-refresh {
            font-size: 0.8rem;
            color: #6c757d;
        }
    </style>
</head>
<body class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <main class="main-content">
        <div class="sync-dashboard">
            <!-- Dashboard Header -->
            <div class="dashboard-header">
                <div>
                    <h1 class="page-title">SmartBill Sincronizare</h1>
                    <p class="auto-refresh">Actualizare automată în <span id="refresh-countdown">30</span>s</p>
                </div>
                <div class="sync-actions">
                    <button class="btn btn-success" onclick="testConnection()">
                        <span class="material-symbols-outlined">wifi</span>
                        Test Conexiune
                    </button>
                    <button class="btn btn-primary" onclick="refreshStatus()">
                        <span class="material-symbols-outlined">refresh</span>
                        Reîmprospătează
                    </button>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if (!empty($message)): ?>
                <div class="message <?= $messageType ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Dashboard Stats -->
            <div class="dashboard-stats">
                <div class="stat-card success">
                    <div class="stat-value" id="successful-syncs">-</div>
                    <div class="stat-label">Sincronizări Reușite (30 zile)</div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-value" id="failed-syncs">-</div>
                    <div class="stat-label">Sincronizări Eșuate (30 zile)</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-value" id="pending-invoices"><?= count($pendingInvoices) ?></div>
                    <div class="stat-label">Facturi în Așteptare</div>
                </div>
                <div class="stat-card info">
                    <div class="stat-value" id="last-sync">-</div>
                    <div class="stat-label">Ultima Sincronizare</div>
                </div>
            </div>

            <!-- Manual Sync Actions -->
            <div class="sync-actions">
                <button class="btn btn-primary" onclick="manualSync('invoice_pull')">
                    <span class="material-symbols-outlined">download</span>
                    Sincronizare Facturi
                </button>
                <button class="btn btn-secondary" onclick="manualSync('order_push')">
                    <span class="material-symbols-outlined">upload</span>
                    Actualizare Comenzi
                </button>
                <button class="btn btn-info" onclick="manualSync('inventory_update')">
                    <span class="material-symbols-outlined">inventory</span>
                    Sincronizare Inventar
                </button>
                <button class="btn btn-warning" onclick="manualSync('product_sync')">
                    <span class="material-symbols-outlined">category</span>
                    Sincronizare Produse
                </button>
            </div>

            <!-- Content Tabs -->
            <div class="content-tabs">
                <div class="tab-buttons">
                    <button class="tab-button active" onclick="showTab('schedule')">Programare</button>
                    <button class="tab-button" onclick="showTab('logs')">Jurnale</button>
                    <button class="tab-button" onclick="showTab('pending')">Facturi în Așteptare</button>
                    <button class="tab-button" onclick="showTab('config')">Configurare</button>
                </div>

                <!-- Schedule Tab -->
                <div id="tab-schedule" class="tab-content active">
                    <h3>Programare Sincronizări</h3>
                    <?php foreach ($syncSchedule as $schedule): ?>
                        <div class="schedule-form">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_schedule">
                                <input type="hidden" name="sync_type" value="<?= htmlspecialchars($schedule['sync_type']) ?>">
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Tip Sincronizare</label>
                                        <input type="text" value="<?= htmlspecialchars($schedule['sync_type']) ?>" readonly class="form-control">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>
                                            <input type="checkbox" name="is_enabled" <?= $schedule['is_enabled'] ? 'checked' : '' ?>>
                                            Activată
                                        </label>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="interval_<?= $schedule['sync_type'] ?>">Interval (minute)</label>
                                        <input type="number" id="interval_<?= $schedule['sync_type'] ?>" name="interval_minutes" 
                                               value="<?= $schedule['interval_minutes'] ?>" min="1" max="1440" class="form-control">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="max_items_<?= $schedule['sync_type'] ?>">Elemente Max</label>
                                        <input type="number" id="max_items_<?= $schedule['sync_type'] ?>" name="max_items_per_run" 
                                               value="<?= $schedule['max_items_per_run'] ?>" min="1" max="1000" class="form-control">
                                    </div>
                                    
                                    <div class="form-group">
                                        <button type="submit" class="btn btn-primary">Salvează</button>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <small class="text-muted">
                                        Ultima rulare: <?= $schedule['last_run'] ? date('d.m.Y H:i:s', strtotime($schedule['last_run'])) : 'Niciodată' ?> |
                                        Următoarea rulare: <?= $schedule['next_run'] ? date('d.m.Y H:i:s', strtotime($schedule['next_run'])) : 'Neplanificată' ?>
                                    </small>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Logs Tab -->
                <div id="tab-logs" class="tab-content">
                    <h3>Jurnale Sincronizări</h3>
                    <div class="logs-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Data/Ora</th>
                                    <th>Tip</th>
                                    <th>Status</th>
                                    <th>Procesate</th>
                                    <th>Erori</th>
                                    <th>Timp Execuție</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentLogs as $log): ?>
                                    <tr>
                                        <td><?= date('d.m.Y H:i:s', strtotime($log['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($log['sync_type']) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= $log['status'] === 'success' ? 'success' : ($log['status'] === 'partial' ? 'warning' : 'danger') ?>">
                                                <?= ucfirst($log['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= $log['processed_count'] ?></td>
                                        <td><?= $log['error_count'] ?></td>
                                        <td><?= $log['execution_time'] ?>ms</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pending Invoices Tab -->
                <div id="tab-pending" class="tab-content">
                    <h3>Facturi în Așteptare</h3>
                    <div class="logs-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Serie-Număr</th>
                                    <th>Status</th>
                                    <th>Timp în Așteptare</th>
                                    <th>Mesaj Eroare</th>
                                    <th>Acțiuni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingInvoices as $invoice): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($invoice['smartbill_series'] . '-' . $invoice['smartbill_number']) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= $invoice['status'] === 'pending' ? 'warning' : 'danger' ?>">
                                                <?= ucfirst($invoice['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= $invoice['minutes_pending'] ?> minute</td>
                                        <td><?= htmlspecialchars($invoice['error_message'] ?: '-') ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="retryInvoice('<?= $invoice['id'] ?>')">
                                                Reîncearcă
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Configuration Tab -->
                <div id="tab-config" class="tab-content">
                    <h3>Configurare SmartBill</h3>
                    <div class="config-section">
                        <div class="form-group">
                            <label for="api_url">URL API</label>
                            <input type="text" id="api_url" class="form-control" value="<?= htmlspecialchars($smartBillService->getConfig('api_url', 'https://ws.smartbill.ro')) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="api_username">Utilizator API</label>
                            <input type="text" id="api_username" class="form-control" value="<?= htmlspecialchars($smartBillService->getConfig('api_username', '')) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="api_token">Token API</label>
                            <input type="password" id="api_token" class="form-control" value="<?= str_repeat('*', strlen($smartBillService->getConfig('api_token', ''))) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="company_vat_code">CIF Companie</label>
                            <input type="text" id="company_vat_code" class="form-control" value="<?= htmlspecialchars($smartBillService->getConfig('company_vat_code', '')) ?>">
                        </div>
                        
                        <div class="form-group">
                            <button type="button" class="btn btn-primary" onclick="saveConfig()">Salvează Configurația</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        let refreshTimer;
        let countdownTimer;
        let countdownSeconds = 30;
        
        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            refreshStatus();
            startAutoRefresh();
        });
        
        // Auto refresh functionality
        function startAutoRefresh() {
            refreshTimer = setInterval(refreshStatus, 30000); // Refresh every 30 seconds
            startCountdown();
        }
        
        function startCountdown() {
            countdownSeconds = 30;
            countdownTimer = setInterval(function() {
                countdownSeconds--;
                document.getElementById('refresh-countdown').textContent = countdownSeconds;
                if (countdownSeconds <= 0) {
                    countdownSeconds = 30;
                }
            }, 1000);
        }
        
        // Tab switching
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById('tab-' + tabName).classList.add('active');
            event.target.classList.add('active');
        }
        
        // AJAX functions
        function refreshStatus() {
            fetch('?action=ajax&ajax_action=get_status')
                .then(response => response.json())
                .then(data => {
                    updateDashboardStats(data);
                })
                .catch(error => {
                    console.error('Error refreshing status:', error);
                });
        }
        
        function updateDashboardStats(data) {
            if (data.metrics) {
                let successfulSyncs = 0;
                let failedSyncs = 0;
                let lastRun = 'Niciodată';
                
                data.metrics.forEach(metric => {
                    successfulSyncs += parseInt(metric.successful_runs || 0);
                    failedSyncs += parseInt(metric.failed_runs || 0);
                    if (metric.last_run && metric.last_run > lastRun) {
                        lastRun = new Date(metric.last_run).toLocaleString('ro-RO');
                    }
                });
                
                document.getElementById('successful-syncs').textContent = successfulSyncs;
                document.getElementById('failed-syncs').textContent = failedSyncs;
                document.getElementById('last-sync').textContent = lastRun;
            }
            
            if (data.pending_invoices !== undefined) {
                document.getElementById('pending-invoices').textContent = data.pending_invoices;
            }
        }
        
        function manualSync(syncType) {
            const button = event.target.closest('button');
            const originalText = button.innerHTML;
            
            button.innerHTML = '<span class="material-symbols-outlined">sync</span> Se sincronizează...';
            button.disabled = true;
            
            const formData = new FormData();
            formData.append('ajax_action', 'manual_sync');
            formData.append('sync_type', syncType);
            
            fetch('?action=ajax', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message || (data.success ? 'Sincronizare reușită!' : 'Sincronizare eșuată!'));
                refreshStatus();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Eroare la sincronizare!');
            })
            .finally(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }
        
        function testConnection() {
            const button = event.target.closest('button');
            const originalText = button.innerHTML;
            
            button.innerHTML = '<span class="material-symbols-outlined">sync</span> Se testează...';
            button.disabled = true;
            
            const formData = new FormData();
            formData.append('ajax_action', 'test_connection');
            
            fetch('?action=ajax', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message || (data.success ? 'Conexiune reușită!' : 'Conexiune eșuată!'));
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Eroare la testarea conexiunii!');
            })
            .finally(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }
        
        function saveConfig() {
            const configs = [
                'api_url', 'api_username', 'api_token', 'company_vat_code'
            ];
            
            configs.forEach(configKey => {
                const value = document.getElementById(configKey).value;
                if (value && !value.startsWith('*')) { // Don't save masked passwords
                    const formData = new FormData();
                    formData.append('ajax_action', 'update_config');
                    formData.append('config_key', configKey);
                    formData.append('config_value', value);
                    
                    fetch('?action=ajax', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .catch(error => {
                        console.error('Error saving config:', error);
                    });
                }
            });
            
            alert('Configurația a fost salvată!');
        }
        
        function retryInvoice(invoiceId) {
            // Implement retry functionality
            alert('Funcționalitatea de reîncercare va fi implementată în curând.');
        }
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (refreshTimer) clearInterval(refreshTimer);
            if (countdownTimer) clearInterval(countdownTimer);
        });
    </script>
</body>
</html>