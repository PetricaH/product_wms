<?php
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// File: orders.php - Updated with table layout and fixed modals
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

$allowedRoles = ['admin', 'warehouse', 'warehouse_worker'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
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
require_once BASE_PATH . '/models/Order.php';
require_once BASE_PATH . '/models/Product.php';

$orderModel = new Order($db);
$productModel = new Product($db);

// Admin reset hint:
// To reset AWB attempts for an order run:
// UPDATE orders SET awb_generation_attempts = 0, awb_generation_last_attempt_at = NULL WHERE id = :order_id;

// Handle operations
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
                    'status' => $_POST['status'] ?? 'Pending',
                    'priority' => $_POST['priority'] ?? 'normal',
                    'notes' => trim($_POST['notes'] ?? ''),
                    'created_by' => $_SESSION['user_id'],
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

                if ($orderModel->createOrder($orderData, $items)) {
                    $message = 'Comanda a fost creată cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la crearea comenzii.');
                }
                break;
                
            case 'update_status':
                $orderId = intval($_POST['order_id'] ?? 0);
                $newStatus = $_POST['status'] ?? '';
                
                if ($orderId <= 0 || empty($newStatus)) {
                    throw new Exception('Date invalide pentru actualizare.');
                }
                
                if ($orderModel->updateStatus($orderId, $newStatus)) {
                    $message = 'Statusul comenzii a fost actualizat cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la actualizarea statusului.');
                }
                break;
                
            case 'delete':
                $orderId = intval($_POST['order_id'] ?? 0);
                
                if ($orderId <= 0) {
                    throw new Exception('ID comandă invalid.');
                }
                
                if ($orderModel->deleteOrder($orderId)) {
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

// Get filters and search
$statusFilter = $_GET['status'] ?? '';
$priorityFilter = $_GET['priority'] ?? '';
$search = trim($_GET['search'] ?? '');

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($page - 1) * $pageSize;

// Get data
$totalCount = $orderModel->getTotalCount($statusFilter, $priorityFilter, $search);
$totalPages = max(1, ceil($totalCount / $pageSize));
$orders = $orderModel->getOrdersPaginated($pageSize, $offset, $statusFilter, $priorityFilter, $search);

// Get unique statuses and priorities for filters
$statuses = $orderModel->getStatuses();
$priorities = $orderModel->getPriorities();
$allProducts = $productModel->getAllProducts();

// Define current page for footer
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title>Gestionare Comenzi - WMS</title>
    <link rel="stylesheet" href="styles/awb_generation.css?v=20250804.2">
</head>
<body>
    <div class="app">
        <?php require_once __DIR__ . '/includes/navbar.php'; ?>
        
        <div class="main-content">
            <div class="page-container">
                <!-- Page Header -->
                <header class="page-header">
                    <div class="page-header-content">
                        <h1 class="page-title">
                            <span class="material-symbols-outlined">shopping_cart</span>
                            Gestionare Comenzi
                        </h1>
                        <!-- <button class="btn btn-primary" onclick="openCreateModal()">
                            <span class="material-symbols-outlined">add_shopping_cart</span>
                            Comandă Nouă
                        </button> -->
                    </div>
                </header>
                
                <!-- Alert Messages -->
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>" role="alert">
                        <span class="material-symbols-outlined">
                            <?= $messageType === 'success' ? 'check_circle' : 'error' ?>
                        </span>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <!-- Filters -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Filtrare Comenzi</h3>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="filter-form">
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="">Toate statusurile</option>
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?= htmlspecialchars($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($status) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Prioritate</label>
                                <select name="priority" class="form-control">
                                    <option value="">Toate prioritățile</option>
                                    <?php foreach ($priorities as $priority): ?>
                                        <option value="<?= htmlspecialchars($priority) ?>" <?= $priorityFilter === $priority ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(ucfirst($priority)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Căutare</label>
                                <input type="text" name="search" class="form-control search-input" 
                                       placeholder="Număr comandă, client..." value="<?= htmlspecialchars($search) ?>">
                            </div>
                            
                            <button type="submit" class="btn btn-secondary">
                                <span class="material-symbols-outlined">filter_alt</span>
                                Filtrează
                            </button>
                            
                            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">
                                <span class="material-symbols-outlined">refresh</span>
                                Reset
                            </a>
                        </form>
                    </div>
                </div>

                <!-- Orders Table -->
                <div class="card">
                    <div class="card-body">
                        <?php if (!empty($orders)): ?>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Număr Comandă</th>
                                            <th>Client</th>
                                            <th>Data Comandă</th>
                                            <th>Status</th>
                                            <th>Prioritate</th>
                                            <th>Valoare</th>
                                            <th>Produse</th>
                                            <th>Greutate</th>
                                            <th>AWB</th>
                                            <th>Acțiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orders as $order): ?>
                                            <?php
                                                $orderItems = $orderModel->getOrderItems($order['id']);
                                                $weightParts = [];
                                                $calculatedWeight = 0;
                                                foreach ($orderItems as $item) {
                                                    $itemWeightPerUnit = (float)($item['weight_per_unit'] ?? 0);
                                                    $itemWeight = $itemWeightPerUnit * (int)$item['quantity'];
                                                    $calculatedWeight += $itemWeight;
                                                    $weightParts[] = $item['product_name'] . ' (' . $item['quantity'] . '×' . number_format($itemWeightPerUnit, 3, '.', '') . 'kg)';
                                                }
                                                $displayWeight = $order['total_weight'] > 0 ? $order['total_weight'] : $calculatedWeight;
                                                $weightBreakdown = htmlspecialchars(implode(' + ', $weightParts));
                                            ?>
                                            <tr>
                                                <td>
                                                    <code class="order-number"><?= htmlspecialchars($order['order_number']) ?></code>
                                                </td>
                                                <td>
                                                    <div class="customer-info">
                                                        <strong><?= htmlspecialchars($order['customer_name']) ?></strong>
                                                        <?php if (!empty($order['customer_email'])): ?>
                                                            <br><small><?= htmlspecialchars($order['customer_email']) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <small><?= date('d.m.Y H:i', strtotime($order['order_date'])) ?></small>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?= strtolower($order['status']) ?>">
                                                        <?= htmlspecialchars($order['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="priority-badge priority-<?= strtolower($order['priority'] ?? 'normal') ?>">
                                                        <?= htmlspecialchars(ucfirst($order['priority'] ?? 'Normal')) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong><?= number_format($order['total_value'] ?? 0, 2) ?> Lei</strong>
                                                </td>
                                                <td>
                                                    <span class="text-center"><?= $order['total_items'] ?? 0 ?> produse</span>
                                                </td>
                                                <td>
                                                    <span title="<?= $weightBreakdown ?>"><?= number_format($displayWeight, 3, '.', '') ?> kg</span>
                                                </td>
                                                <td class="awb-column">
                                                    <?php
                                                    $attempts = (int)($order['awb_generation_attempts'] ?? 0);
                                                    $awbBarcode = trim($order['awb_barcode'] ?? '');
                                                    $hasValidAwb = ($awbBarcode !== '' && preg_match('/^\\d+$/', $awbBarcode));
                                                    $attemptClass = ($attempts >= 3 && !$hasValidAwb) ? 'text-danger' : '';
                                                    ?>
                                                    <?php if (!$hasValidAwb): ?>
                                                        <div class="awb-attempts <?= $attemptClass ?>"><?= $attempts ?>/3</div>
                                                    <?php endif; ?>
                                                    <?php if ($hasValidAwb): ?>
                                                        <div class="awb-info">
                                                            <span class="awb-barcode"><?= htmlspecialchars($awbBarcode) ?></span>
                                                            <?php if (!empty($order['awb_created_at'])): ?>
                                                                <small><?= date('d.m.Y H:i', strtotime($order['awb_created_at'])) ?></small>
                                                            <?php endif; ?>
                                                            <div class="awb-status" data-awb="<?= htmlspecialchars($awbBarcode) ?>">Se verifică...</div>
                                                            <button type="button" class="btn btn-sm btn-outline-secondary refresh-status-btn" data-awb="<?= htmlspecialchars($awbBarcode) ?>">
                                                                <span class="material-symbols-outlined">refresh</span> Track AWB
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-success print-awb-btn" onclick="printAWB(<?= $order['id'] ?>, '<?= htmlspecialchars($awbBarcode) ?>', '<?= htmlspecialchars(addslashes($order['order_number'])) ?>')" title="Printează AWB">
                                                                <span class="material-symbols-outlined">print</span> Printează AWB
                                                            </button>
                                                        </div>
                                                    <?php else: ?>
                                                        <button type="button" class="generate-awb-btn" data-order-id="<?= $order['id'] ?>" title="Generează AWB">
                                                            <span class="material-symbols-outlined">local_shipping</span>
                                                            Generează AWB
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-primary" onclick="viewOrderDetails(<?= $order['id'] ?>)" title="Vezi detalii">
                                                            <span class="material-symbols-outlined">visibility</span>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-secondary" onclick="openStatusModal(<?= $order['id'] ?>, '<?= htmlspecialchars($order['status']) ?>')" title="Schimbă status">
                                                            <span class="material-symbols-outlined">edit</span>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-info" onclick="printInvoiceWithSelection(<?= $order['id'] ?>)" title="Printează Factura">
                                                            <span class="material-symbols-outlined">print</span>
                                                        </button>
                    
                                                        <button class="btn btn-sm btn-outline-danger" onclick="openDeleteModal(<?= $order['id'] ?>, '<?= htmlspecialchars(addslashes($order['order_number'])) ?>')" title="Șterge">
                                                            <span class="material-symbols-outlined">delete</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <div class="pagination-container">
                                    <div class="pagination-info">
                                        Afișare <?= ($offset + 1) ?>-<?= min($offset + $pageSize, $totalCount) ?> din <?= number_format($totalCount) ?> comenzi
                                    </div>
                                    <div class="pagination-controls">
                                        <?php if ($page > 1): ?>
                                            <a href="?page=1&status=<?= urlencode($statusFilter) ?>&priority=<?= urlencode($priorityFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn">Prima</a>
                                            <a href="?page=<?= $page - 1 ?>&status=<?= urlencode($statusFilter) ?>&priority=<?= urlencode($priorityFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn">‹</a>
                                        <?php endif; ?>
                                        
                                        <?php 
                                        $startPage = max(1, $page - 2);
                                        $endPage = min($totalPages, $page + 2);
                                        
                                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                                            <?php if ($i == $page): ?>
                                                <span class="pagination-btn active"><?= $i ?></span>
                                            <?php else: ?>
                                                <a href="?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>&priority=<?= urlencode($priorityFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn"><?= $i ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $totalPages): ?>
                                            <a href="?page=<?= $page + 1 ?>&status=<?= urlencode($statusFilter) ?>&priority=<?= urlencode($priorityFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn">›</a>
                                            <a href="?page=<?= $totalPages ?>&status=<?= urlencode($statusFilter) ?>&priority=<?= urlencode($priorityFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn">Ultima</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">shopping_cart</span>
                                <h3>Nu există comenzi</h3>
                                <p>
                                    <?php if ($search || $statusFilter || $priorityFilter): ?>
                                        Nu s-au găsit comenzi cu criteriile selectate.
                                        <a href="?" class="btn btn-secondary">Șterge filtrele</a>
                                    <?php else: ?>
                                        Creează prima comandă folosind butonul de mai sus.
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Order Modal -->
    <div class="modal" id="createOrderModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Comandă Nouă</h3>
                    <button class="modal-close" onclick="closeCreateModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form id="createOrderForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="order_number" class="form-label">Număr Comandă *</label>
                                <input type="text" name="order_number" id="order_number" class="form-control" 
                                       value="<?= 'ORD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="order_date" class="form-label">Data Comandă</label>
                                <input type="datetime-local" name="order_date" id="order_date" class="form-control" 
                                       value="<?= date('Y-m-d\TH:i') ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="customer_name" class="form-label">Nume Client *</label>
                                <input type="text" name="customer_name" id="customer_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="customer_email" class="form-label">Email Client</label>
                                <input type="email" name="customer_email" id="customer_email" class="form-control">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="shipping_address" class="form-label">Adresă Livrare</label>
                            <textarea name="shipping_address" id="shipping_address" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="status" class="form-label">Status</label>
                                <select name="status" id="status" class="form-control">
                                    <option value="Pending">Pending</option>
                                    <option value="Processing">Processing</option>
                                    <option value="Picked">Picked</option>
                                    <option value="Shipped">Shipped</option>
                                    <option value="Delivered">Delivered</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="priority" class="form-label">Prioritate</label>
                                <select name="priority" id="priority" class="form-control">
                                    <option value="normal">Normal</option>
                                    <option value="high">Înaltă</option>
                                    <option value="urgent">Urgentă</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes" class="form-label">Observații</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <!-- Order Items -->
                        <h4>Produse Comandă</h4>
                        <div id="orderItems">
                            <div class="order-item">
                                <div class="row">
                                    <div class="form-group">
                                        <label class="form-label">Produs</label>
                                        <select name="items[0][product_id]" class="form-control" required>
                                            <option value="">Selectează produs</option>
                                            <?php foreach ($allProducts as $product): ?>
                                                <option value="<?= $product['product_id'] ?>" data-price="<?= $product['price'] ?>">
                                                    <?= htmlspecialchars($product['name']) ?> (<?= htmlspecialchars($product['sku']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Cantitate</label>
                                        <input type="number" name="items[0][quantity]" class="form-control" min="1" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Preț Unitar</label>
                                        <input type="number" name="items[0][unit_price]" class="form-control" step="0.01" min="0" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">&nbsp;</label>
                                        <button type="button" class="btn btn-danger" onclick="removeOrderItem(this)">
                                            <span class="material-symbols-outlined">delete</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn btn-secondary" onclick="addOrderItem()">
                            <span class="material-symbols-outlined">add</span>
                            Adaugă Produs
                        </button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Creează Comanda</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal" id="statusModal">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Actualizare Status</h3>
                    <button class="modal-close" onclick="closeStatusModal()">
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
                                <option value="Pending">Pending</option>
                                <option value="Processing">Processing</option>
                                <option value="Picked">Picked</option>
                                <option value="Shipped">Shipped</option>
                                <option value="Delivered">Delivered</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeStatusModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Actualizează</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Confirmare Ștergere</h3>
                    <button class="modal-close" onclick="closeDeleteModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="order_id" id="deleteOrderId">
                        
                        <div class="alert alert-warning">
                            <span class="material-symbols-outlined">warning</span>
                            Ești sigur că vrei să ștergi comanda <strong id="deleteOrderNumber"></strong>?
                        </div>
                        
                        <p><small class="text-muted">Această acțiune nu poate fi anulată.</small></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Anulează</button>
                        <button type="submit" class="btn btn-danger">Șterge Comanda</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="scripts/orders_awb.js?v=20250804.2"></script>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>