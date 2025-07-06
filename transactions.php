<?php
// File: transactions.php - Updated with table layout and fixed modals
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
require_once BASE_PATH . '/models/Transaction.php';
require_once BASE_PATH . '/models/Product.php';

$transactionModel = new Transaction($db);
$productModel = new Product($db);

// Handle operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create':
                $transactionData = [
                    'transaction_type' => $_POST['transaction_type'] ?? '',
                    'amount' => floatval($_POST['amount'] ?? 0),
                    'tax_amount' => floatval($_POST['tax_amount'] ?? 0),
                    'currency' => $_POST['currency'] ?? 'RON',
                    'description' => trim($_POST['description'] ?? ''),
                    'reference_type' => $_POST['reference_type'] ?? 'manual',
                    'reference_id' => intval($_POST['reference_id'] ?? 0),
                    'customer_name' => trim($_POST['customer_name'] ?? ''),
                    'supplier_name' => trim($_POST['supplier_name'] ?? ''),
                    'status' => $_POST['status'] ?? 'pending',
                    'created_by' => $_SESSION['user_id']
                ];
                
                if (empty($transactionData['transaction_type']) || $transactionData['amount'] <= 0) {
                    throw new Exception('Tipul tranzacției și suma sunt obligatorii.');
                }
                
                if ($transactionModel->createTransaction($transactionData)) {
                    $message = 'Tranzacția a fost creată cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la crearea tranzacției.');
                }
                break;
                
            case 'update_status':
                $transactionId = intval($_POST['transaction_id'] ?? 0);
                $newStatus = $_POST['status'] ?? '';
                
                if ($transactionId <= 0 || empty($newStatus)) {
                    throw new Exception('Date invalide pentru actualizare.');
                }
                
                if ($transactionModel->updateStatus($transactionId, $newStatus)) {
                    $message = 'Statusul tranzacției a fost actualizat cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la actualizarea statusului.');
                }
                break;
                
            case 'delete':
                $transactionId = intval($_POST['transaction_id'] ?? 0);
                
                if ($transactionId <= 0) {
                    throw new Exception('ID tranzacție invalid.');
                }
                
                if ($transactionModel->deleteTransaction($transactionId)) {
                    $message = 'Tranzacția a fost ștearsă cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la ștergerea tranzacției.');
                }
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Get filters and search
$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($page - 1) * $pageSize;

// Get data
$totalCount = $transactionModel->getTotalCount($typeFilter, $statusFilter, $search);
$totalPages = max(1, ceil($totalCount / $pageSize));
$transactions = $transactionModel->getTransactionsPaginated($pageSize, $offset, $typeFilter, $statusFilter, $search);

// Get unique types and statuses for filters
$types = $transactionModel->getTypes();
$statuses = $transactionModel->getStatuses();
$allProducts = $productModel->getAllProducts();

// Define current page for footer
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Gestionare Tranzacții - WMS</title>
    <link rel="stylesheet" href="styles/transactions.css">
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
                            <span class="material-symbols-outlined">receipt_long</span>
                            Gestionare Tranzacții
                        </h1>
                        <button class="btn btn-primary" onclick="openCreateModal()">
                            <span class="material-symbols-outlined">add</span>
                            Tranzacție Nouă
                        </button>
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
                        <h3 class="card-title">Filtrare Tranzacții</h3>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="filter-form">
                            <div class="form-group">
                                <label class="form-label">Tip</label>
                                <select name="type" class="form-control">
                                    <option value="">Toate tipurile</option>
                                    <?php foreach ($types as $type): ?>
                                        <option value="<?= htmlspecialchars($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(ucfirst($type)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="">Toate statusurile</option>
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?= htmlspecialchars($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(ucfirst($status)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Căutare</label>
                                <input type="text" name="search" class="form-control search-input" 
                                       placeholder="Descriere, client, furnizor..." value="<?= htmlspecialchars($search) ?>">
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

                <!-- Transactions Table -->
                <div class="card">
                    <div class="card-body">
                        <?php if (!empty($transactions)): ?>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Tip</th>
                                            <th>Descriere</th>
                                            <th>Client/Furnizor</th>
                                            <th>Sumă</th>
                                            <th>Status</th>
                                            <th>Data</th>
                                            <th>Acțiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $transaction): ?>
                                            <tr>
                                                <td>
                                                    <code class="transaction-id">#<?= $transaction['id'] ?></code>
                                                </td>
                                                <td>
                                                    <span class="type-badge type-<?= strtolower($transaction['transaction_type']) ?>">
                                                        <?= htmlspecialchars(ucfirst($transaction['transaction_type'])) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="description-info">
                                                        <?php if (!empty($transaction['description'])): ?>
                                                            <strong><?= htmlspecialchars(substr($transaction['description'], 0, 50)) ?></strong>
                                                            <?= strlen($transaction['description']) > 50 ? '...' : '' ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">Fără descriere</span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($transaction['reference_type']) && $transaction['reference_type'] !== 'manual'): ?>
                                                            <br><small class="text-muted">Ref: <?= htmlspecialchars($transaction['reference_type']) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($transaction['customer_name'])): ?>
                                                        <strong><?= htmlspecialchars($transaction['customer_name']) ?></strong>
                                                        <br><small class="text-muted">Client</small>
                                                    <?php elseif (!empty($transaction['supplier_name'])): ?>
                                                        <strong><?= htmlspecialchars($transaction['supplier_name']) ?></strong>
                                                        <br><small class="text-muted">Furnizor</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="amount-info">
                                                        <strong><?= number_format($transaction['amount'], 2) ?> <?= $transaction['currency'] ?? 'RON' ?></strong>
                                                        <?php if (!empty($transaction['tax_amount']) && $transaction['tax_amount'] > 0): ?>
                                                            <br><small class="text-muted">TVA: <?= number_format($transaction['tax_amount'], 2) ?> <?= $transaction['currency'] ?? 'RON' ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?= strtolower($transaction['status']) ?>">
                                                        <?= htmlspecialchars(ucfirst($transaction['status'])) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small><?= date('d.m.Y H:i', strtotime($transaction['created_at'])) ?></small>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="viewTransactionDetails(<?= $transaction['id'] ?>)"
                                                                title="Vezi detalii">
                                                            <span class="material-symbols-outlined">visibility</span>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-secondary" 
                                                                onclick="openStatusModal(<?= $transaction['id'] ?>, '<?= htmlspecialchars($transaction['status']) ?>')"
                                                                title="Schimbă status">
                                                            <span class="material-symbols-outlined">edit</span>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="openDeleteModal(<?= $transaction['id'] ?>, '<?= htmlspecialchars($transaction['id']) ?>')"
                                                                title="Șterge">
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
                                        Afișare <?= ($offset + 1) ?>-<?= min($offset + $pageSize, $totalCount) ?> din <?= number_format($totalCount) ?> tranzacții
                                    </div>
                                    <div class="pagination-controls">
                                        <?php if ($page > 1): ?>
                                            <a href="?page=1&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn">Prima</a>
                                            <a href="?page=<?= $page - 1 ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn">‹</a>
                                        <?php endif; ?>
                                        
                                        <?php 
                                        $startPage = max(1, $page - 2);
                                        $endPage = min($totalPages, $page + 2);
                                        
                                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                                            <?php if ($i == $page): ?>
                                                <span class="pagination-btn active"><?= $i ?></span>
                                            <?php else: ?>
                                                <a href="?page=<?= $i ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn"><?= $i ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $totalPages): ?>
                                            <a href="?page=<?= $page + 1 ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn">›</a>
                                            <a href="?page=<?= $totalPages ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn">Ultima</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">receipt_long</span>
                                <h3>Nu există tranzacții</h3>
                                <p>
                                    <?php if ($search || $typeFilter || $statusFilter): ?>
                                        Nu s-au găsit tranzacții cu criteriile selectate.
                                        <a href="?" class="btn btn-secondary">Șterge filtrele</a>
                                    <?php else: ?>
                                        Creează prima tranzacție folosind butonul de mai sus.
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Transaction Modal -->
    <div class="modal" id="createTransactionModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Tranzacție Nouă</h3>
                    <button class="modal-close" onclick="closeCreateModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form id="createTransactionForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="transaction_type" class="form-label">Tip Tranzacție *</label>
                                <select name="transaction_type" id="transaction_type" class="form-control" required>
                                    <option value="">Selectează tip</option>
                                    <option value="sales">Vânzare</option>
                                    <option value="purchase">Achiziție</option>
                                    <option value="adjustment">Ajustare</option>
                                    <option value="transfer">Transfer</option>
                                    <option value="return">Retur</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="status" class="form-label">Status</label>
                                <select name="status" id="status" class="form-control">
                                    <option value="pending">Pending</option>
                                    <option value="processing">Processing</option>
                                    <option value="completed">Completed</option>
                                    <option value="failed">Failed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="amount" class="form-label">Sumă *</label>
                                <input type="number" name="amount" id="amount" class="form-control" step="0.01" min="0" required>
                            </div>
                            <div class="form-group">
                                <label for="tax_amount" class="form-label">TVA</label>
                                <input type="number" name="tax_amount" id="tax_amount" class="form-control" step="0.01" min="0">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="currency" class="form-label">Monedă</label>
                                <select name="currency" id="currency" class="form-control">
                                    <option value="RON">RON</option>
                                    <option value="EUR">EUR</option>
                                    <option value="USD">USD</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="reference_type" class="form-label">Tip Referință</label>
                                <select name="reference_type" id="reference_type" class="form-control">
                                    <option value="manual">Manual</option>
                                    <option value="order">Comandă</option>
                                    <option value="inventory">Inventar</option>
                                    <option value="location">Locație</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description" class="form-label">Descriere</label>
                            <textarea name="description" id="description" class="form-control" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="customer_name" class="form-label">Nume Client</label>
                                <input type="text" name="customer_name" id="customer_name" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="supplier_name" class="form-label">Nume Furnizor</label>
                                <input type="text" name="supplier_name" id="supplier_name" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Creează Tranzacția</button>
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
                        <input type="hidden" name="transaction_id" id="statusTransactionId">
                        
                        <div class="form-group">
                            <label for="updateStatus" class="form-label">Nou Status</label>
                            <select name="status" id="updateStatus" class="form-control" required>
                                <option value="pending">Pending</option>
                                <option value="processing">Processing</option>
                                <option value="completed">Completed</option>
                                <option value="failed">Failed</option>
                                <option value="cancelled">Cancelled</option>
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
                        <input type="hidden" name="transaction_id" id="deleteTransactionId">
                        
                        <div class="alert alert-warning">
                            <span class="material-symbols-outlined">warning</span>
                            Ești sigur că vrei să ștergi tranzacția <strong>#<span id="deleteTransactionNumber"></span></strong>?
                        </div>
                        
                        <p><small class="text-muted">Această acțiune nu poate fi anulată.</small></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Anulează</button>
                        <button type="submit" class="btn btn-danger">Șterge Tranzacția</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>