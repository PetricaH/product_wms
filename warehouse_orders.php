<?php
// File: warehouse_orders.php
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
// if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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

// Handle POST Operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'create':
                $orderData = [
                    'order_number' => trim($_POST['order_number'] ?? ''),
                    'customer_name' => trim($_POST['customer_name'] ?? ''),
                    'customer_email' => trim($_POST['customer_email'] ?? ''),
                    'shipping_address' => trim($_POST['shipping_address'] ?? ''),
                    'order_date' => $_POST['order_date'] ?? date('Y-m-d H:i:s'),
                    'status' => $_POST['status'] ?? Order::STATUS_PENDING,
                    'notes' => trim($_POST['notes'] ?? ''),
                    'created_by' => $_SESSION['user_id'] ?? 1,
                ];

                $items = [];
                if (!empty($_POST['items'])) {
                    foreach ($_POST['items'] as $item) {
                        if (!empty($item['product_id']) && !empty($item['quantity']) && isset($item['unit_price'])) {
                            $items[] = [
                                'product_id' => intval($item['product_id']),
                                'quantity' => intval($item['quantity']),
                                'unit_price' => floatval($item['unit_price'])
                            ];
                        }
                    }
                }

                if (empty($orderData['customer_name'])) {
                    throw new Exception('Numele clientului este obligatoriu.');
                }
                if (empty($items)) {
                    throw new Exception('Comanda trebuie să conțină cel puțin un produs.');
                }

                if ($orderModel->create($orderData, $items)) {
                    $message = 'Comanda a fost creată cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la crearea comenzii în model.');
                }
                break;

            case 'update':
                $orderId = intval($_POST['order_id'] ?? 0);
                $orderData = [
                    'customer_name' => trim($_POST['customer_name'] ?? ''),
                    'customer_email' => trim($_POST['customer_email'] ?? ''),
                    'shipping_address' => trim($_POST['shipping_address'] ?? ''),
                    'status' => $_POST['status'] ?? '',
                    'tracking_number' => trim($_POST['tracking_number'] ?? ''),
                    'notes' => trim($_POST['notes'] ?? '')
                ];
                
                if ($orderId <= 0 || empty($orderData['customer_name'])) {
                    throw new Exception('Date invalide pentru actualizarea comenzii.');
                }
                
                if ($orderModel->update($orderId, array_filter($orderData))) {
                    $message = 'Comanda a fost actualizată cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la actualizarea comenzii.');
                }
                break;

            case 'delete':
                $orderId = intval($_POST['order_id'] ?? 0);
                if ($orderId <= 0) {
                    throw new Exception('ID comandă invalid.');
                }
                
                if ($orderModel->delete($orderId)) {
                    $message = 'Comanda a fost ștearsă cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la ștergerea comenzii.');
                }
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Get data for display
$filters = [
    'status' => $_GET['status'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'customer_name' => $_GET['customer_name'] ?? ''
];

$orders = $orderModel->getAllOrders($filters);
$products = $productModel->getProductsWithInventory();
$statuses = $orderModel->getStatuses();
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');

// Get the base URL from bootstrap
$baseUrl = rtrim(BASE_URL, '/');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionare Comenzi - WMS</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="styles/global.css">
    <link rel="stylesheet" href="styles/warehouse_orders.css">
    
    <!-- Pass configuration to JavaScript -->
    <script>
    window.WMS_CONFIG = {
        baseUrl: '<?= htmlspecialchars($config['base_url']) ?>',
        apiBase: '/api'  // Always use relative for API calls
    };
</script>
</head>
<body>

    <main class="main-content">
        <div class="warehouse-container">
            <div class="container">
                <div class="page-header">
                    <h1 class="page-title">📦 Warehouse Orders Dashboard</h1>
                    <p class="page-subtitle">Manage and monitor all warehouse orders</p>
                    <button class="btn btn-primary" onclick="openCreateModal()">
                        <span class="material-symbols-outlined">add_shopping_cart</span>
                        Comandă Nouă
                    </button>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="message <?= htmlspecialchars($messageType) ?>">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <!-- Stats Section -->
                <div class="stats-section">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <span class="material-symbols-outlined stat-icon">inventory_2</span>
                            <span class="stat-value" id="total-orders">0</span>
                            <span class="stat-label">Total Orders</span>
                        </div>
                        <div class="stat-card">
                            <span class="material-symbols-outlined stat-icon">schedule</span>
                            <span class="stat-value" id="pending-orders">0</span>
                            <span class="stat-label">Pending</span>
                        </div>
                        <div class="stat-card">
                            <span class="material-symbols-outlined stat-icon">work</span>
                            <span class="stat-value" id="in-progress-orders">0</span>
                            <span class="stat-label">In Progress</span>
                        </div>
                        <div class="stat-card">
                            <span class="material-symbols-outlined stat-icon">check_circle</span>
                            <span class="stat-value" id="completed-orders">0</span>
                            <span class="stat-label">Completed</span>
                        </div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="filters-section">
                    <form method="GET" class="filter-form">
                        <div class="filter-group">
                            <label for="status-filter">Status:</label>
                            <select name="status" id="status-filter" onchange="this.form.submit()">
                                <option value="">Toate statusurile</option>
                                <?php foreach ($statuses as $statusKey => $statusLabel): ?>
                                    <option value="<?= htmlspecialchars($statusKey) ?>" <?= ($filters['status'] ?? '') === $statusKey ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($statusLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="priority-filter">Priority:</label>
                            <select id="priority-filter" onchange="filterOrders()">
                                <option value="">All Priorities</option>
                                <option value="urgent">Urgent</option>
                                <option value="high">High</option>
                                <option value="normal">Normal</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="date-from">From Date:</label>
                            <input type="date" name="date_from" id="date-from" value="<?= htmlspecialchars($filters['date_from']) ?>" onchange="this.form.submit()">
                        </div>
                        
                        <div class="filter-group">
                            <label for="date-to">To Date:</label>
                            <input type="date" name="date_to" id="date-to" value="<?= htmlspecialchars($filters['date_to']) ?>" onchange="this.form.submit()">
                        </div>
                        
                        <div class="filter-group">
                            <label for="customer-search">Customer:</label>
                            <input type="text" name="customer_name" id="customer-search" value="<?= htmlspecialchars($filters['customer_name']) ?>" placeholder="Numele clientului" onkeyup="debounceFilter(this.form)">
                        </div>
                        
                        <button type="button" class="btn btn-secondary" onclick="loadOrders()">
                            <span class="material-symbols-outlined">refresh</span>
                            Refresh
                        </button>
                    </form>
                </div>

                <!-- Loading State -->
                <div id="loading" class="loading" style="display: none;">
                    <div class="spinner"></div>
                    <p>Loading orders...</p>
                </div>

                <!-- Orders Grid -->
                <div id="orders-grid" class="orders-grid"></div>
                
                <!-- Empty State -->
                <div id="no-orders" class="empty-state" style="display: none;">
                    <span class="material-symbols-outlined">inbox</span>
                    <h3>No Orders Found</h3>
                    <p>No orders match the current filters.</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Create Order Modal -->
    <div id="createOrderModal" class="modal">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2 class="modal-title">Comandă Nouă</h2>
                <button class="close" onclick="closeCreateModal()">&times;</button>
            </div>
            <form id="createOrderForm" method="POST">
                <input type="hidden" name="action" value="create">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="order_number">Numărul Comenzii</label>
                            <input type="text" name="order_number" id="order_number" class="form-input" placeholder="Auto-generat dacă este gol">
                        </div>
                        <div class="form-group">
                            <label for="customer_name">Numele Clientului *</label>
                            <input type="text" name="customer_name" id="customer_name" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label for="customer_email">Email Client</label>
                            <input type="email" name="customer_email" id="customer_email" class="form-input">
                        </div>
                        <div class="form-group">
                            <label for="order_date">Data Comenzii</label>
                            <input type="datetime-local" name="order_date" id="order_date" class="form-input" value="<?= date('Y-m-d\TH:i') ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="shipping_address">Adresa de Livrare</label>
                        <textarea name="shipping_address" id="shipping_address" class="form-textarea" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status" class="form-input">
                            <?php foreach ($statuses as $statusKey => $statusLabel): ?>
                                <option value="<?= htmlspecialchars($statusKey) ?>" <?= $statusKey === Order::STATUS_PENDING ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($statusLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-section">
                        <div class="section-header">
                            <h3>Produse Comandate</h3>
                            <button type="button" class="btn btn-sm btn-success" onclick="addOrderItem()">
                                <span class="material-symbols-outlined">add</span> Adaugă Produs
                            </button>
                        </div>
                        <div id="orderItems"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notițe</label>
                        <textarea name="notes" id="notes" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Anulează</button>
                    <button type="submit" class="btn btn-primary">Creează Comanda</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Order Modal -->
    <div id="editOrderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Editează Comanda</h2>
                <button class="close" onclick="closeEditModal()">&times;</button>
            </div>
            <form id="editOrderForm" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="order_id" id="edit_order_id">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_customer_name">Numele Clientului *</label>
                            <input type="text" name="customer_name" id="edit_customer_name" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_customer_email">Email Client</label>
                            <input type="email" name="customer_email" id="edit_customer_email" class="form-input">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_shipping_address">Adresa de Livrare</label>
                        <textarea name="shipping_address" id="edit_shipping_address" class="form-textarea" rows="3"></textarea>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_status">Status</label>
                            <select name="status" id="edit_status" class="form-input">
                                <?php foreach ($statuses as $statusKey => $statusLabel): ?>
                                    <option value="<?= htmlspecialchars($statusKey) ?>"><?= htmlspecialchars($statusLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_tracking_number">Numărul de Urmărire (AWB)</label>
                            <input type="text" name="tracking_number" id="edit_tracking_number" class="form-input">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_notes">Notițe</label>
                        <textarea name="notes" id="edit_notes" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Anulează</button>
                    <button type="submit" class="btn btn-primary">Actualizează</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Order Details Modal -->
    <div id="orderDetailsModal" class="modal">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2 class="modal-title">Detalii Comandă</h2>
                <button class="close" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div id="orderDetailsContent"></div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content modal-small">
            <div class="modal-header">
                <h2 class="modal-title">Confirmare Ștergere</h2>
                <button class="close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Sunteți sigur că doriți să ștergeți comanda <strong id="deleteOrderNumber"></strong>? Această acțiune este ireversibilă.</p>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="order_id" id="deleteOrderId">
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Anulează</button>
                    <button type="submit" class="btn btn-danger">Șterge</button>
                </div>
            </form>
        </div>
    </div>
    <!-- JavaScript Files -->
    <script src="scripts/warehouse_orders.js?v=<?= filemtime('scripts/warehouse_orders.js') ?>"></script>
</body>
</html>