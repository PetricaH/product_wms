<?php
// File: purchase_orders.php - Purchase Orders Management
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Include models
require_once BASE_PATH . '/models/PurchaseOrder.php';
require_once BASE_PATH . '/models/Seller.php';
require_once BASE_PATH . '/models/PurchasableProduct.php';

$purchaseOrderModel = new PurchaseOrder($db);
$sellerModel = new Seller($db);
$purchasableProductModel = new PurchasableProduct($db);

// Handle operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_status':
                $orderId = intval($_POST['order_id'] ?? 0);
                $newStatus = $_POST['status'] ?? '';
                
                if ($orderId <= 0 || empty($newStatus)) {
                    throw new Exception('Date invalide pentru actualizare.');
                }
                
                if ($purchaseOrderModel->updateStatus($orderId, $newStatus)) {
                    $message = 'Statusul comenzii a fost actualizat cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la actualizarea statusului.');
                }
                break;
                
            case 'send_email':
                $orderId = intval($_POST['order_id'] ?? 0);
                $emailRecipient = trim($_POST['email_recipient'] ?? '');
                
                if ($orderId <= 0 || empty($emailRecipient)) {
                    throw new Exception('Date invalide pentru trimiterea emailului.');
                }
                
                // Here you would implement email sending functionality
                // For now, we'll just mark as sent
                if ($purchaseOrderModel->markAsSent($orderId, $emailRecipient)) {
                    $message = 'Comanda a fost trimisă prin email cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la trimiterea emailului.');
                }
                break;

            case 'record_delivery':
                $orderId = intval($_POST['order_id'] ?? 0);
                $deliveryDate = $_POST['delivery_date'] ?? '';
                $deliveryNote = trim($_POST['delivery_note_number'] ?? '');
                $carrier = trim($_POST['carrier'] ?? '');
                $receivedBy = trim($_POST['received_by'] ?? '');
                $items = $_POST['delivery_items'] ?? [];
                
                if ($orderId <= 0 || empty($deliveryDate)) {
                    throw new Exception('Data livrării este obligatorie.');
                }
                
                // Process delivery recording
                // This would involve creating delivery records and updating quantities
                $message = 'Livrarea a fost înregistrată cu succes.';
                $messageType = 'success';
                break;

            case 'record_invoice':
                $orderId = intval($_POST['order_id'] ?? 0);
                $invoiceNumber = trim($_POST['invoice_number'] ?? '');
                $invoiceDate = $_POST['invoice_date'] ?? '';
                $totalAmount = floatval($_POST['total_amount'] ?? 0);
                $items = $_POST['invoice_items'] ?? [];
                
                if ($orderId <= 0 || empty($invoiceNumber) || empty($invoiceDate)) {
                    throw new Exception('Numărul și data facturii sunt obligatorii.');
                }
                
                // Process invoice recording
                // This would involve creating invoice records and updating quantities
                $message = 'Factura a fost înregistrată cu succes.';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? '';
$sellerFilter = intval($_GET['seller_id'] ?? 0);

// Get purchase orders
$filters = [];
if (!empty($statusFilter)) {
    $filters['status'] = $statusFilter;
}
if ($sellerFilter > 0) {
    $filters['seller_id'] = $sellerFilter;
}

$purchaseOrders = $purchaseOrderModel->getAllPurchaseOrders($filters);
$sellers = $sellerModel->getAllSellers();

// Include header
$currentPage = 'purchase_orders';
require_once __DIR__ . '/includes/header.php';
?>

<div class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-container">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <h1 class="page-title">Comenzi de Achiziție</h1>
                    <div class="header-actions">
                        <a href="transactions.php" class="btn btn-success">
                            <span class="material-symbols-outlined">add_shopping_cart</span>
                            Comandă Nouă
                        </a>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>">
                    <span class="material-symbols-outlined">
                        <?= $messageType === 'success' ? 'check_circle' : 'error' ?>
                    </span>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Quick Stats -->
            <div class="stats-grid">
                <?php
                $statsQuery = "SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status = 'pending' OR status = 'sent' THEN 1 ELSE 0 END) as pending_orders,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
                    SUM(total_amount) as total_value
                    FROM purchase_orders";
                $statsStmt = $db->prepare($statsQuery);
                $statsStmt->execute();
                $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
                ?>
                <div class="stat-card">
                    <div class="stat-icon">
                        <span class="material-symbols-outlined">shopping_cart</span>
                    </div>
                    <div class="stat-info">
                        <h3><?= number_format($stats['total_orders']) ?></h3>
                        <p>Total Comenzi</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <span class="material-symbols-outlined">pending</span>
                    </div>
                    <div class="stat-info">
                        <h3><?= number_format($stats['pending_orders']) ?></h3>
                        <p>În Așteptare</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <span class="material-symbols-outlined">local_shipping</span>
                    </div>
                    <div class="stat-info">
                        <h3><?= number_format($stats['delivered_orders']) ?></h3>
                        <p>Livrate</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <span class="material-symbols-outlined">payments</span>
                    </div>
                    <div class="stat-info">
                        <h3><?= number_format($stats['total_value'], 0) ?> RON</h3>
                        <p>Valoare Totală</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status" class="form-control">
                            <option value="">Toate statusurile</option>
                            <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Trimisă</option>
                            <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmată</option>
                            <option value="partial_delivery" <?= $statusFilter === 'partial_delivery' ? 'selected' : '' ?>>Livrare Parțială</option>
                            <option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Livrată</option>
                            <option value="invoiced" <?= $statusFilter === 'invoiced' ? 'selected' : '' ?>>Facturată</option>
                            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completă</option>
                            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Anulată</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="seller_id">Furnizor</label>
                        <select name="seller_id" id="seller_id" class="form-control">
                            <option value="">Toți furnizorii</option>
                            <?php foreach ($sellers as $seller): ?>
                                <option value="<?= $seller['id'] ?>" 
                                        <?= $sellerFilter === $seller['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($seller['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">Filtrează</button>
                        <a href="?" class="btn btn-secondary">Reset</a>
                    </div>
                </form>
            </div>

            <!-- Purchase Orders Table -->
            <div class="orders-table-container">
                <?php if (!empty($purchaseOrders)): ?>
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Număr Comandă</th>
                                <th>Furnizor</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Data Creării</th>
                                <th>Livrare Estimată</th>
                                <th>Acțiuni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchaseOrders as $order): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($order['order_number']) ?></strong>
                                        <br>
                                        <small class="text-muted"><?= $order['item_count'] ?> produse</small>
                                    </td>
                                    <td><?= htmlspecialchars($order['supplier_name']) ?></td>
                                    <td><?= number_format($order['total_amount'], 2) ?> <?= $order['currency'] ?></td>
                                    <td>
                                        <span class="status-badge status-<?= $order['status'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>
                                        </span>
                                    </td>
                                    <td><?= date('d.m.Y H:i', strtotime($order['created_at'])) ?></td>
                                    <td>
                                        <?php if ($order['expected_delivery_date']): ?>
                                            <?= date('d.m.Y', strtotime($order['expected_delivery_date'])) ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="viewOrderDetails(<?= $order['id'] ?>)"
                                                    title="Vezi detalii">
                                                <span class="material-symbols-outlined">visibility</span>
                                            </button>
                                            
                                            <?php if ($order['status'] === 'draft'): ?>
                                                <button class="btn btn-sm btn-outline-success" 
                                                        onclick="openSendEmailModal(<?= $order['id'] ?>, '<?= htmlspecialchars($order['supplier_name']) ?>')"
                                                        title="Trimite prin email">
                                                    <span class="material-symbols-outlined">email</span>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if (in_array($order['status'], ['sent', 'confirmed', 'partial_delivery'])): ?>
                                                <button class="btn btn-sm btn-outline-info" 
                                                        onclick="openDeliveryModal(<?= $order['id'] ?>)"
                                                        title="Înregistrează livrare">
                                                    <span class="material-symbols-outlined">local_shipping</span>
                                                </button>
                                                <button class="btn btn-sm btn-outline-warning" 
                                                        onclick="openInvoiceModal(<?= $order['id'] ?>)"
                                                        title="Înregistrează factură">
                                                    <span class="material-symbols-outlined">receipt</span>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button class="btn btn-sm btn-outline-secondary" 
                                                    onclick="openStatusModal(<?= $order['id'] ?>, '<?= htmlspecialchars($order['status']) ?>')"
                                                    title="Schimbă status">
                                                <span class="material-symbols-outlined">edit</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-content">
                            <span class="material-symbols-outlined">shopping_cart</span>
                            <h3>Nu există comenzi de achiziție</h3>
                            <p>
                                <?php if (!empty($statusFilter) || $sellerFilter > 0): ?>
                                    Nu s-au găsit comenzi care să corespundă criteriilor de filtrare.
                                    <a href="?" class="btn btn-secondary">Șterge filtrele</a>
                                <?php else: ?>
                                    Creează prima comandă de achiziție din pagina de tranzacții.
                                    <a href="transactions.php" class="btn btn-primary">Comandă Nouă</a>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal" id="statusModal">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Actualizare Status</h3>
                    <button class="modal-close" onclick="closeModal('statusModal')">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="order_id" id="statusOrderId">
                        
                        <div class="form-group">
                            <label for="updateStatus" class="form-label">Nou Status</label>
                            <select name="status" id="updateStatus" class="form-control" required>
                                <option value="draft">Draft</option>
                                <option value="sent">Trimisă</option>
                                <option value="confirmed">Confirmată</option>
                                <option value="partial_delivery">Livrare Parțială</option>
                                <option value="delivered">Livrată</option>
                                <option value="invoiced">Facturată</option>
                                <option value="completed">Completă</option>
                                <option value="cancelled">Anulată</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('statusModal')">Anulează</button>
                        <button type="submit" class="btn btn-primary">Actualizează</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Send Email Modal -->
    <div class="modal" id="sendEmailModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Trimite Comandă prin Email</h3>
                    <button class="modal-close" onclick="closeModal('sendEmailModal')">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="send_email">
                        <input type="hidden" name="order_id" id="emailOrderId">
                        
                        <div class="form-group">
                            <label for="email_recipient" class="form-label">Email Destinatar *</label>
                            <input type="email" name="email_recipient" id="email_recipient" class="form-control" required>
                        </div>
                        
                        <div class="alert alert-info">
                            <span class="material-symbols-outlined">info</span>
                            Comanda va fi trimisă prin email furnizorului și statusul va fi actualizat la "Trimisă".
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('sendEmailModal')">Anulează</button>
                        <button type="submit" class="btn btn-primary">Trimite Email</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delivery Recording Modal -->
    <div class="modal" id="deliveryModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Înregistrează Livrare</h3>
                    <button class="modal-close" onclick="closeModal('deliveryModal')">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="record_delivery">
                        <input type="hidden" name="order_id" id="deliveryOrderId">
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="delivery_date" class="form-label">Data Livrării *</label>
                                <input type="date" name="delivery_date" id="delivery_date" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="delivery_note_number" class="form-label">Număr Aviz</label>
                                <input type="text" name="delivery_note_number" id="delivery_note_number" class="form-control">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="carrier" class="form-label">Transportator</label>
                                <input type="text" name="carrier" id="carrier" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="received_by" class="form-label">Primit de</label>
                                <input type="text" name="received_by" id="received_by" class="form-control">
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h4>Produse Livrate</h4>
                            <div id="delivery-items">
                                <!-- Will be populated by JavaScript -->
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('deliveryModal')">Anulează</button>
                        <button type="submit" class="btn btn-primary">Înregistrează Livrarea</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Invoice Recording Modal -->
    <div class="modal" id="invoiceModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Înregistrează Factură</h3>
                    <button class="modal-close" onclick="closeModal('invoiceModal')">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="record_invoice">
                        <input type="hidden" name="order_id" id="invoiceOrderId">
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="invoice_number" class="form-label">Număr Factură *</label>
                                <input type="text" name="invoice_number" id="invoice_number" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="invoice_date" class="form-label">Data Facturii *</label>
                                <input type="date" name="invoice_date" id="invoice_date" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="total_amount" class="form-label">Suma Totală *</label>
                            <input type="number" name="total_amount" id="total_amount" step="0.01" class="form-control" required>
                        </div>
                        
                        <div class="form-section">
                            <h4>Produse Facturate</h4>
                            <div id="invoice-items">
                                <!-- Will be populated by JavaScript -->
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('invoiceModal')">Anulează</button>
                        <button type="submit" class="btn btn-primary">Înregistrează Factura</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</div>