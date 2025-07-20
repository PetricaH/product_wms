<?php
// Security and bootstrap
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Generate CSRF token
// if (!isset($_SESSION['csrf_token'])) {
//     $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
// }

// Database connection
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include models
require_once BASE_PATH . '/models/PurchaseOrder.php';
require_once BASE_PATH . '/models/Seller.php';
require_once BASE_PATH . '/models/Product.php';
require_once BASE_PATH . '/models/Location.php';
require_once BASE_PATH . '/models/ReceivingSession.php';
require_once BASE_PATH . '/models/User.php';

$purchaseOrderModel = new PurchaseOrder($db);
$sellerModel = new Seller($db);
$productModel = new Product($db);
$locationModel = new Location($db);
$receivingSessionModel = new ReceivingSession($db);
$userModel = new Users($db);

// Get current user info
$currentUser = $userModel->findById($_SESSION['user_id']);
$userName = $currentUser['username'] ?? 'Worker';

// Message handling
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        // CSRF validation
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Invalid CSRF token');
        }
        
        switch ($action) {
            case 'start_receiving':
                // This will be handled by JavaScript/API
                break;
                
            case 'complete_receiving':
                // This will be handled by JavaScript/API
                break;
                
            default:
                throw new Exception('Unknown action');
        }
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
        error_log("Receiving error: " . $e->getMessage());
    }
}

// Get active locations for dropdown
$locations = $locationModel->getActiveLocations();

// Get recent receiving sessions for quick access
$recentSessions = [];
$activeSessions = [];
try {
    // Get recent sessions
    $stmt = $db->prepare("
        SELECT rs.*, s.name as supplier_name, u.username as received_by_name
        FROM receiving_sessions rs
        LEFT JOIN sellers s ON rs.supplier_id = s.id
        LEFT JOIN users u ON rs.received_by = u.id
        WHERE rs.status = 'completed'
        ORDER BY rs.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recentSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get active sessions for current user or all if admin/manager
    $userRole = $currentUser['role'] ?? 'worker';
    if (in_array($userRole, ['admin', 'manager'])) {
        $stmt = $db->prepare("
            SELECT rs.*, s.name as supplier_name, u.username as received_by_name,
                   po.order_number as po_number
            FROM receiving_sessions rs
            LEFT JOIN sellers s ON rs.supplier_id = s.id
            LEFT JOIN users u ON rs.received_by = u.id
            LEFT JOIN purchase_orders po ON rs.purchase_order_id = po.id
            WHERE rs.status = 'in_progress'
            ORDER BY rs.created_at DESC
        ");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("
            SELECT rs.*, s.name as supplier_name, u.username as received_by_name,
                   po.order_number as po_number
            FROM receiving_sessions rs
            LEFT JOIN sellers s ON rs.supplier_id = s.id
            LEFT JOIN users u ON rs.received_by = u.id
            LEFT JOIN purchase_orders po ON rs.purchase_order_id = po.id
            WHERE rs.status = 'in_progress' AND rs.received_by = :user_id
            ORDER BY rs.created_at DESC
        ");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
    }
    $activeSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error fetching sessions: " . $e->getMessage());
}

// Define current page for footer
$currentPage = 'warehouse_receiving';
?>
<!DOCTYPE html>
<html lang="ro" data-theme="dark">
<head>
    <?php require_once BASE_PATH . '/includes/warehouse_header.php'; ?>
    <title>Recepție Marfă - WMS</title>
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?>">
</head>
<body>
      <div class="app">
        <?php require_once BASE_PATH . '/includes/warehouse_navbar.php'; ?>
        
        <div class="main-content">
            <div class="receiving-container">
                <!-- Loading Overlay -->
                <div id="loading-overlay" class="loading-overlay" style="display: none;">
                    <div class="loading-spinner"></div>
                    <div class="loading-text">Se procesează...</div>
                </div>

                <!-- Alert Messages -->
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>" role="alert">
                        <span class="material-symbols-outlined">
                            <?= $messageType === 'success' ? 'check_circle' : 'error' ?>
                        </span>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                 <!-- Step 1: Order Search -->
                <div id="step-1" class="step-section active">
                    <div class="step-header">
                        <h2 class="step-title">
                            <span class="material-symbols-outlined">search</span>
                            Caută Comanda
                        </h2>
                        <p class="step-subtitle">Scrie numărul comenzii sau numele furnizorului</p>
                    </div>

                    <div class="step-content">
                        <div class="form-group">
                            <label for="po-search-input" class="form-label">Comandă sau Furnizor</label>
                            <input type="text" id="po-search-input" class="form-input" placeholder="ex: PO-2024-001 sau Furnizor">
                        </div>
                        <div id="po-search-results" class="purchase-orders-list"></div>
                    </div>
                </div>


                <!-- Step 3: Item Receiving -->
                <div id="step-3" class="step-section">
                    <div class="step-header">
                        <h2 class="step-title">
                            <span class="material-symbols-outlined">inventory</span>
                            Recepționează Produsele
                        </h2>
                        <p class="step-subtitle">Verifică și înregistrează produsele primite</p>
                    </div>
                    
                    <div class="step-content">
                        <div class="receiving-summary">
                            <div class="summary-card">
                                <div class="summary-item">
                                    <span class="summary-label">Comanda:</span>
                                    <span class="summary-value" id="current-po-number">-</span>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Furnizor:</span>
                                    <span class="summary-value" id="current-supplier">-</span>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Progres:</span>
                                    <span class="summary-value">
                                        <span id="items-received">0</span> / <span id="items-expected">0</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="receiving-items">
                            <div class="items-header">
                                <h3>Produse de Recepționat</h3>
                                <button type="button" class="btn btn-secondary" id="scan-barcode-btn">
                                    <span class="material-symbols-outlined">qr_code_scanner</span>
                                    Scanează Barcode
                                </button>
                            </div>
                            
                            <div id="expected-items-list" class="expected-items-list">
                                <!-- Expected items will be populated here -->
                            </div>
                        </div>
                        
                        <div class="step-actions">
                            <button type="button" class="btn btn-secondary" onclick="goToPreviousStep()">
                                <span class="material-symbols-outlined">arrow_back</span>
                                Înapoi
                            </button>
                            <button type="button" class="btn btn-primary" id="complete-receiving-btn">
                                <span class="material-symbols-outlined">check</span>
                                Finalizează Recepția
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Completion -->
                <div id="step-4" class="step-section">
                    <div class="step-header">
                        <h2 class="step-title">
                            <span class="material-symbols-outlined">check_circle</span>
                            Recepție Finalizată
                        </h2>
                        <p class="step-subtitle">Recepția a fost înregistrată cu succes</p>
                    </div>
                    
                    <div class="step-content">
                        <div class="completion-summary">
                            <div class="summary-card">
                                <div id="completion-details">
                                    <!-- Completion details will be populated here -->
                                </div>
                            </div>
                        </div>
                        
                        <div class="step-actions">
                            <button type="button" class="btn btn-secondary" onclick="startNewReceiving()">
                                <span class="material-symbols-outlined">add</span>
                                Recepție Nouă
                            </button>
                            <button type="button" class="btn btn-primary" onclick="viewReceivingHistory()">
                                <span class="material-symbols-outlined">history</span>
                                Istoric Recepții
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Recent Sessions Sidebar -->
                <div class="sidebar">
                    <h3 class="sidebar-title">Recepții Recente</h3>
                    <div class="recent-sessions">
                        <?php if (empty($recentSessions)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">inbox</span>
                                <p>Nicio recepție înregistrată</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentSessions as $session): ?>
                                <div class="session-item">
                                    <div class="session-header">
                                        <span class="session-number"><?= htmlspecialchars($session['session_number']) ?></span>
                                        <span class="session-status status-<?= $session['status'] ?>">
                                            <?= ucfirst($session['status']) ?>
                                        </span>
                                    </div>
                                    <div class="session-details">
                                        <div class="session-supplier"><?= htmlspecialchars($session['supplier_name'] ?? 'N/A') ?></div>
                                        <div class="session-date"><?= date('d.m.Y H:i', strtotime($session['created_at'])) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Barcode Scanner Modal -->
    <div id="scanner-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Scanează Barcode</h3>
                <button type="button" class="modal-close" onclick="closeScannerModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="scanner-container">
                    <div id="scanner-placeholder" class="scanner-placeholder">
                        <span class="material-symbols-outlined">qr_code_scanner</span>
                        <p>Apasă "Pornește Scanner" pentru a scana</p>
                    </div>
                </div>
                <div class="scanner-controls">
                    <button type="button" class="btn btn-primary" id="start-scanner">
                        <span class="material-symbols-outlined">play_arrow</span>
                        Pornește Scanner
                    </button>
                    <button type="button" class="btn btn-secondary" id="stop-scanner">
                        <span class="material-symbols-outlined">stop</span>
                        Oprește Scanner
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Pass configuration to JavaScript
        window.WMS_CONFIG = {
            baseUrl: '<?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>',
            apiBase: '<?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>/api',
            currentUser: {
                id: <?= $_SESSION['user_id'] ?>,
                username: '<?= htmlspecialchars($userName) ?>'
            },
            csrfToken: '<?= $_SESSION['csrf_token'] ?>',
            locations: <?= json_encode(array_map(function($loc) {
                return [
                    'id' => $loc['id'],
                    'location_code' => $loc['location_code'],
                    'zone' => $loc['zone'],
                    'type' => $loc['type']
                ];
            }, $locations)) ?>,
            defaultLocation: '<?= htmlspecialchars($locations[0]['location_code'] ?? '') ?>'
        };
    </script>

    <?php require_once BASE_PATH . '/includes/warehouse_footer.php'; ?>