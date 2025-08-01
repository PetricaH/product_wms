
<?php
/**
 * SmartBill Sync Dashboard
 * Compact admin interface for monitoring and managing SmartBill synchronization
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
// require_once BASE_PATH . '/models/SmartBillService.php';
// require_once BASE_PATH . '/smartbill_sync_service.php';

// $smartBillService = new SmartBillService($db);
// $syncService = new SmartBillSyncService($db);

require_once BASE_PATH . '/models/SmartBillService.php';
require_once BASE_PATH . '/models/MultiWarehouseSmartBillService.php';
require_once BASE_PATH . '/smartbill_sync_service.php';

$smartBillService = new MultiWarehouseSmartBillService($db);
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
            
        case 'save_config':
            try {
                $apiUrl = $_POST['api_url'] ?? '';
                $apiUsername = $_POST['api_username'] ?? '';
                $apiToken = $_POST['api_token'] ?? '';
                $companyVatCode = $_POST['company_vat_code'] ?? '';
                
                $success = true;
                
                if (!empty($apiUrl)) {
                    $success &= $smartBillService->updateConfig('api_url', $apiUrl, false);
                }
                if (!empty($apiUsername)) {
                    $success &= $smartBillService->updateConfig('api_username', $apiUsername, true);
                }
                if (!empty($apiToken)) {
                    $success &= $smartBillService->updateConfig('api_token', $apiToken, true);
                }
                if (!empty($companyVatCode)) {
                    $success &= $smartBillService->updateConfig('company_vat_code', $companyVatCode, false);
                }
                
                if ($success) {
                    $message = 'Configurația SmartBill a fost salvată cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la salvarea configurației.';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                $message = 'Eroare la salvarea configurației: ' . $e->getMessage();
                $messageType = 'error';
            }
            break;
            
        case 'test_connection':
            $result = $smartBillService->testConnection();
            if ($result['success']) {
                $message = 'Conexiunea la SmartBill a fost testată cu succes.';
                $messageType = 'success';
            } else {
                $message = 'Eroare la testarea conexiunii: ' . $result['message'];
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
    // Get sync schedule
    $stmt = $db->prepare("SELECT * FROM smartbill_sync_schedule ORDER BY sync_type");
    $stmt->execute();
    $syncSchedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent logs
    $stmt = $db->prepare("SELECT * FROM smartbill_sync_log ORDER BY created_at DESC LIMIT 20");
    $stmt->execute();
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get pending invoices count (if table exists)
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM smartbill_invoices WHERE sync_status = 'pending'");
        $stmt->execute();
        $pendingInvoices = $stmt->fetchColumn();
    } catch (PDOException $e) {
        $pendingInvoices = 0;
    }
    
} catch (PDOException $e) {
    error_log("Error loading sync dashboard data: " . $e->getMessage());
    $syncSchedule = [];
    $recentLogs = [];
    $pendingInvoices = 0;
}

// Get current config values
$currentConfig = [
    'api_url' => $smartBillService->getConfig('api_url', 'https://ws.smartbill.ro/SBORO/api'),
    'api_username' => $smartBillService->getConfig('api_username', ''),
    'api_token' => $smartBillService->getConfig('api_token', ''),
    'company_vat_code' => $smartBillService->getConfig('company_vat_code', '')
];

$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>SmartBill Sync - WMS Admin</title>
</head>
<body class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <div class="main-content">
        <div class="sync-container">
            <div class="container">
                <div class="page-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <div class="header-left">
                            <h1 class="page-title">
                                <span class="material-symbols-outlined">sync</span>
                                SmartBill Sync
                            </h1>
                            <div class="page-stats">
                                <div class="stat-badge">
                                    <strong id="successful-syncs">0</strong> succes (24h)
                                </div>
                                <div class="stat-badge">
                                    <strong id="failed-syncs">0</strong> eșuate (24h)
                                </div>
                                <div class="stat-badge">
                                    <strong id="pending-invoices"><?= $pendingInvoices ?></strong> în așteptare
                                </div>
                            </div>
                        </div>
                        <div class="header-actions">
                            <button class="btn btn-primary btn-sm" onclick="manualSync('invoice_pull')">
                                <span class="material-symbols-outlined">download</span>
                                Facturi
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="manualSync('product_sync')">
                                <span class="material-symbols-outlined">category</span>
                                Produse
                            </button>
                            <button class="btn btn-outline btn-sm" onclick="testConnection()">
                                <span class="material-symbols-outlined">wifi_protected_setup</span>
                                Test
                            </button>
                        </div>
                    </div>

                    <!-- Status Messages -->
                    <?php if ($message): ?>
                        <div class="message <?= $messageType ?>">
                            <span class="material-symbols-outlined">
                                <?= $messageType === 'success' ? 'check_circle' : 'error' ?>
                            </span>
                            <?= htmlspecialchars($message) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Content Tabs -->
                    <div class="content-tabs">
                        <div class="tab-buttons">
                            <button class="tab-button active" onclick="showTab('schedule')">
                                <span class="material-symbols-outlined">schedule</span>
                                Programare
                            </button>
                            <button class="tab-button" onclick="showTab('logs')">
                                <span class="material-symbols-outlined">history</span>
                                Istoric
                            </button>
                            <button class="tab-button" onclick="showTab('config')">
                                <span class="material-symbols-outlined">settings</span>
                                Configurare
                            </button>
                        </div>

                        <!-- Schedule Tab -->
                        <div id="schedule-tab" class="tab-content active">
                            <div class="schedule-grid">
                                <?php foreach ($syncSchedule as $schedule): ?>
                                    <div class="schedule-card">
                                        <div class="schedule-header">
                                            <div class="schedule-title">
                                                <span class="material-symbols-outlined">
                                                    <?php
                                                    switch ($schedule['sync_type']) {
                                                        case 'invoice_pull': echo 'download'; break;
                                                        case 'order_push': echo 'upload'; break;
                                                        case 'inventory_update': echo 'inventory'; break;
                                                        case 'product_sync': echo 'category'; break;
                                                        default: echo 'sync';
                                                    }
                                                    ?>
                                                </span>
                                                <?= ucfirst(str_replace('_', ' ', $schedule['sync_type'])) ?>
                                            </div>
                                            <div class="status-badge <?= $schedule['is_enabled'] ? 'status-success' : 'status-danger' ?>">
                                                <span class="material-symbols-outlined">
                                                    <?= $schedule['is_enabled'] ? 'check_circle' : 'cancel' ?>
                                                </span>
                                                <?= $schedule['is_enabled'] ? 'Activă' : 'Inactivă' ?>
                                            </div>
                                        </div>
                                        
                                        <div class="schedule-body">
                                            <form method="POST" class="schedule-form">
                                                <input type="hidden" name="action" value="update_schedule">
                                                <input type="hidden" name="sync_type" value="<?= $schedule['sync_type'] ?>">
                                                
                                                <div class="checkbox-group">
                                                    <input type="checkbox" id="enabled_<?= $schedule['sync_type'] ?>" 
                                                           name="is_enabled" <?= $schedule['is_enabled'] ? 'checked' : '' ?>>
                                                    <label for="enabled_<?= $schedule['sync_type'] ?>">Activă</label>
                                                </div>
                                                
                                                <div class="form-row">
                                                    <div class="form-group">
                                                        <label for="interval_<?= $schedule['sync_type'] ?>">Interval (min)</label>
                                                        <input type="number" id="interval_<?= $schedule['sync_type'] ?>" 
                                                               name="interval_minutes" value="<?= $schedule['interval_minutes'] ?>" 
                                                               min="5" max="1440">
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label for="max_items_<?= $schedule['sync_type'] ?>">Max elemente</label>
                                                        <input type="number" id="max_items_<?= $schedule['sync_type'] ?>" 
                                                               name="max_items_per_run" value="<?= $schedule['max_items_per_run'] ?>" 
                                                               min="1" max="1000">
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label>&nbsp;</label>
                                                        <button type="submit" class="btn btn-primary btn-sm">
                                                            <span class="material-symbols-outlined">save</span>
                                                            Salvează
                                                        </button>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($schedule['last_run'] || $schedule['next_run']): ?>
                                                    <div class="schedule-info">
                                                        <?php if ($schedule['last_run']): ?>
                                                            <div><strong>Ultima:</strong> <?= date('d.m.Y H:i', strtotime($schedule['last_run'])) ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($schedule['next_run']): ?>
                                                            <div><strong>Următoarea:</strong> <?= date('d.m.Y H:i', strtotime($schedule['next_run'])) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Logs Tab -->
                        <div id="logs-tab" class="tab-content">
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Data/Ora</th>
                                            <th>Tip</th>
                                            <th>Status</th>
                                            <th>Procesate</th>
                                            <th>Erori</th>
                                            <th>Durată</th>
                                        </tr>
                                    </thead>
                                    <tbody id="logs-tbody">
                                        <?php foreach ($recentLogs as $log): ?>
                                            <tr>
                                                <td><?= date('d.m H:i', strtotime($log['created_at'])) ?></td>
                                                <td><?= ucfirst(str_replace('_', ' ', $log['sync_type'])) ?></td>
                                                <td>
                                                    <div class="status-badge status-<?= $log['status'] === 'success' ? 'success' : ($log['status'] === 'partial' ? 'warning' : 'danger') ?>">
                                                        <span class="material-symbols-outlined">
                                                            <?= $log['status'] === 'success' ? 'check_circle' : ($log['status'] === 'partial' ? 'warning' : 'error') ?>
                                                        </span>
                                                        <?= ucfirst($log['status']) ?>
                                                    </div>
                                                </td>
                                                <td><?= $log['processed_count'] ?></td>
                                                <td><?= $log['error_count'] ?></td>
                                                <td><?= number_format($log['execution_time'], 1) ?>s</td>
                                            </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if (empty($recentLogs)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted">Nu există înregistrări</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Configuration Tab -->
                        <div id="config-tab" class="tab-content">
                            <div class="config-container">
                                <div class="config-header">
                                    <span class="material-symbols-outlined">settings</span>
                                    <h3>Configurare SmartBill API</h3>
                                </div>
                                
                                <div class="config-body">
                                    <form method="POST" class="config-form">
                                        <input type="hidden" name="action" value="save_config">
                                        
                                        <div class="config-grid">
                                            <div class="config-item">
                                                <label for="api_url">URL API</label>
                                                <input type="text" id="api_url" name="api_url" 
                                                       value="<?= htmlspecialchars($currentConfig['api_url']) ?>"
                                                       placeholder="https://ws.smartbill.ro/SBORO/api">
                                            </div>
                                            
                                            <div class="config-item">
                                                <label for="api_username">Utilizator API</label>
                                                <input type="text" id="api_username" name="api_username" 
                                                       value="<?= htmlspecialchars($currentConfig['api_username']) ?>"
                                                       placeholder="Nume utilizator SmartBill">
                                            </div>
                                            
                                            <div class="config-item">
                                                <label for="api_token">Token API</label>
                                                <input type="password" id="api_token" name="api_token" 
                                                       value="<?= !empty($currentConfig['api_token']) ? '••••••••••••••••' : '' ?>"
                                                       placeholder="Token SmartBill API">
                                            </div>
                                            
                                            <div class="config-item">
                                                <label for="company_vat_code">Cod TVA Companie</label>
                                                <input type="text" id="company_vat_code" name="company_vat_code" 
                                                       value="<?= htmlspecialchars($currentConfig['company_vat_code']) ?>"
                                                       placeholder="RO12345678">
                                            </div>
                                        </div>
                                        
                                        <div class="config-actions">
                                            <button type="submit" class="btn btn-primary">
                                                <span class="material-symbols-outlined">save</span>
                                                Salvează Configurația
                                            </button>
                                            <button type="submit" name="action" value="test_connection" class="btn btn-secondary">
                                                <span class="material-symbols-outlined">wifi_protected_setup</span>
                                                Testează Conexiunea
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>