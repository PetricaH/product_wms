<?php
// File: warehouse_orders.php - Fixed with existing design language
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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

// For production, uncomment these lines:
// if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['warehouse', 'admin'])) {
//     header('Location: ' . getNavUrl('login.php'));
//     exit;
// }

// Database connection
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include models
require_once BASE_PATH . '/models/Order.php';
require_once BASE_PATH . '/models/Product.php';

$orderModel = new Order($db);
$productModel = new Product($db);

// Handle POST Operations (for order status updates, etc.)
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'update_status':
                $orderId = intval($_POST['order_id'] ?? 0);
                $newStatus = trim($_POST['status'] ?? '');
                
                if ($orderId <= 0 || empty($newStatus)) {
                    throw new Exception('Date invalide pentru actualizarea statusului.');
                }
                
                if ($orderModel->updateStatus($orderId, $newStatus)) {
                    $message = 'Statusul comenzii a fost actualizat cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la actualizarea statusului comenzii.');
                }
                break;

            case 'assign_order':
                $orderId = intval($_POST['order_id'] ?? 0);
                $workerId = $_SESSION['user_id'] ?? 1;
                
                if ($orderId <= 0) {
                    throw new Exception('ID comandă invalid.');
                }
                
                // Update order to processing status and assign worker
                $updateData = [
                    'status' => 'processing',
                    'assigned_to' => $workerId
                ];
                
                if ($orderModel->updateOrder($orderId, $updateData)) {
                    $message = 'Comanda a fost asignată cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la asignarea comenzii.');
                }
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Define current page for header/footer
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');

// Get some basic stats for the warehouse dashboard
try {
    $pendingOrders = $orderModel->countPendingOrders();
    $processingOrders = count($orderModel->getOrdersByStatus('processing'));
    $todayCompleted = $orderModel->countCompletedToday();
} catch (Exception $e) {
    error_log("Error getting warehouse stats: " . $e->getMessage());
    $pendingOrders = $processingOrders = $todayCompleted = 0;
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/warehouse_header.php'; ?>
</head>
<body>
    <?php require_once __DIR__ . '/includes/warehouse_navbar.php'; ?>
    
    <!-- Main Container (matching existing warehouse design) -->
    <div class="main-container">
        <div class="page-header">
            <h1 class="page-title">Comenzi Depozit</h1>
            <p class="page-subtitle">Gestionează și procesează comenzile din depozit rapid și eficient</p>
        </div>

        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>" role="alert">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Stats Section (matching warehouse_hub.css style) -->
        <div class="stats-section">
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="material-symbols-outlined stat-icon">pending_actions</span>
                    <div class="stat-number"><?= $pendingOrders ?></div>
                    <div class="stat-label">În așteptare</div>
                </div>
                
                <div class="stat-card">
                    <span class="material-symbols-outlined stat-icon">engineering</span>
                    <div class="stat-number"><?= $processingOrders ?></div>
                    <div class="stat-label">În procesare</div>
                </div>
                
                <div class="stat-card">
                    <span class="material-symbols-outlined stat-icon">task_alt</span>
                    <div class="stat-number"><?= $todayCompleted ?></div>
                    <div class="stat-label">Finalizate azi</div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-row">
                <select id="status-filter" class="filter-select">
                    <option value="">Toate statusurile</option>
                    <option value="pending">În așteptare</option>
                    <option value="processing">În procesare</option>
                    <option value="ready">Gata pentru livrare</option>
                </select>
                
                <select id="priority-filter" class="filter-select">
                    <option value="">Toate prioritățile</option>
                    <option value="urgent">Urgent</option>
                    <option value="high">Ridicată</option>
                    <option value="normal">Normală</option>
                </select>
                
                <button id="refresh-btn" class="refresh-btn">
                    <span class="material-symbols-outlined">refresh</span>
                    Actualizează
                </button>
            </div>
        </div>

        <!-- Orders Section -->
        <div class="orders-section">
            <!-- Loading indicator -->
            <div id="loading" class="loading-state" style="display: none;">
                <div class="loading-spinner"></div>
                <p>Se încarcă comenzile...</p>
            </div>

            <!-- No orders message -->
            <div id="no-orders" class="no-data-message" style="display: none;">
                <span class="material-symbols-outlined">inventory_2</span>
                <h3>Nu există comenzi disponibile</h3>
                <p>Nu există comenzi care să corespundă criteriilor de filtrare.</p>
            </div>

            <!-- Orders grid container -->
            <div id="orders-grid" class="orders-grid">
                <!-- Orders will be loaded here by JavaScript -->
            </div>
        </div>

        <!-- Order Details Modal -->
        <div id="order-details-modal" class="modal" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>
                        <span class="material-symbols-outlined">receipt_long</span>
                        Detalii Comandă
                    </h2>
                    <button class="modal-close" onclick="closeOrderDetails()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="modal-body" id="order-details-content">
                    <!-- Order details will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeOrderDetails()">Închide</button>
                    <button class="btn btn-primary" id="process-order-btn" onclick="processCurrentOrder()">
                        Procesează Comanda
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Include warehouse footer (loads page-specific JS automatically) -->
    <?php require_once __DIR__ . '/includes/warehouse_footer.php'; ?>
</body>
</html>