<?php
// File: transactions.php - Updated with stock purchase functionality
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
require_once BASE_PATH . '/models/Seller.php';
require_once BASE_PATH . '/models/PurchasableProduct.php';
require_once BASE_PATH . '/models/PurchaseOrder.php';

$transactionModel = new Transaction($db);
$productModel = new Product($db);
$sellerModel = new Seller($db);
$purchasableProductModel = new PurchasableProduct($db);
$purchaseOrderModel = new PurchaseOrder($db);

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

            case 'create_stock_purchase':
                // Handle stock purchase order creation
                $sellerId = intval($_POST['seller_id'] ?? 0);
                $customMessage = trim($_POST['custom_message'] ?? '');
                $expectedDeliveryDate = $_POST['expected_delivery_date'] ?? null;
                $items = $_POST['items'] ?? [];
                
                if ($sellerId <= 0) {
                    throw new Exception('Trebuie să selectezi un furnizor.');
                }
                
                if (empty($items)) {
                    throw new Exception('Trebuie să adaugi cel puțin un produs.');
                }
                
                // Process items and calculate total
                $processedItems = [];
                $totalAmount = 0;
                
                foreach ($items as $item) {
                    if (empty($item['product_name']) || floatval($item['quantity']) <= 0 || floatval($item['unit_price']) <= 0) {
                        continue;
                    }
                    
                    // Check if product exists or create new purchasable product
                    $purchasableProductId = null;
                    if (!empty($item['purchasable_product_id'])) {
                        $purchasableProductId = intval($item['purchasable_product_id']);
                    } else {
                        // Create new purchasable product
                        $productData = [
                            'supplier_product_name' => $item['product_name'],
                            'supplier_product_code' => $item['product_code'] ?? '',
                            'description' => $item['description'] ?? '',
                            'unit_measure' => 'bucata',
                            'last_purchase_price' => floatval($item['unit_price']),
                            'preferred_seller_id' => $sellerId
                        ];
                        
                        $purchasableProductId = $purchasableProductModel->createProduct($productData);
                        if (!$purchasableProductId) {
                            throw new Exception('Eroare la crearea produsului: ' . $item['product_name']);
                        }
                    }
                    
                    $quantity = floatval($item['quantity']);
                    $unitPrice = floatval($item['unit_price']);
                    $totalPrice = $quantity * $unitPrice;
                    
                    $processedItems[] = [
                        'purchasable_product_id' => $purchasableProductId,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'notes' => $item['notes'] ?? ''
                    ];
                    
                    $totalAmount += $totalPrice;
                }
                
                if (empty($processedItems)) {
                    throw new Exception('Nu s-au putut procesa produsele selectate.');
                }
                
                // Get seller email for purchase order
                $seller = $sellerModel->getSellerById($sellerId);
                $emailRecipient = $_POST['email_recipient'] ?? $seller['email'] ?? '';
                
                // Create purchase order
                $orderData = [
                    'seller_id' => $sellerId,
                    'total_amount' => $totalAmount,
                    'custom_message' => $customMessage,
                    'expected_delivery_date' => $expectedDeliveryDate,
                    'email_recipient' => $emailRecipient,
                    'items' => $processedItems
                ];
                
                $orderId = $purchaseOrderModel->createPurchaseOrder($orderData);
                
                if ($orderId) {
                    // Create transaction record
                    $transactionData = [
                        'transaction_type' => 'stock_purchase',
                        'amount' => $totalAmount,
                        'currency' => 'RON',
                        'description' => 'Comandă stoc furnizor: ' . $seller['supplier_name'],
                        'reference_type' => 'purchase_order',
                        'reference_id' => $orderId,
                        'purchase_order_id' => $orderId,
                        'supplier_name' => $seller['supplier_name'],
                        'status' => 'pending',
                        'created_by' => $_SESSION['user_id']
                    ];
                    
                    $transactionModel->createTransaction($transactionData);
                    
                    $message = 'Comanda de stoc a fost creată cu succes. Numărul comenzii: ' . $purchaseOrderModel->getPurchaseOrderById($orderId)['order_number'];
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la crearea comenzii de stoc.');
                }
                break;
                
            case 'update_status':
                $transactionId = intval($_POST['transaction_id'] ?? 0);
                $newStatus = $_POST['status'] ?? '';
                
                if ($transactionId <= 0 || empty($newStatus)) {
                    throw new Exception('Date invalide pentru actualizare.');
                }
                
                if ($transactionModel->updateTransactionStatus($transactionId, $newStatus)) {
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

// Get filter parameters
$currentPage = intval($_GET['page'] ?? 1);
$pageSize = 20;
$searchQuery = trim($_GET['search'] ?? '');
$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// Get transactions
$transactions = $transactionModel->getPaginatedTransactions(
    $currentPage,
    $pageSize,
    $searchQuery,
    $typeFilter,
    $statusFilter
);

$totalTransactions = $transactionModel->getTotalTransactions($searchQuery, $typeFilter, $statusFilter);
$totalPages = ceil($totalTransactions / $pageSize);

// Get data for dropdowns
$transactionTypes = $transactionModel->getTypes();
$transactionStatuses = $transactionModel->getStatuses();
$sellers = $sellerModel->getAllSellers();
$purchasableProducts = $purchasableProductModel->getAllProducts();

// Include header
$currentPage = 'transactions';
require_once __DIR__ . '/includes/header.php';
?>

<div class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-container">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <h1 class="page-title">Tranzacții</h1>
                    <div class="header-actions">
                        <button class="btn btn-primary" onclick="openCreateModal()">
                            <span class="material-symbols-outlined">add</span>
                            Tranzacție Nouă
                a        </button>
                        <button class="btn btn-success" onclick="openStockPurchaseModal()">
                            <span class="material-symbols-outlined">shopping_cart</span>
                            Cumparare Stoc
                        </button>
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

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label for="search">Căutare</label>
                        <input type="text" name="search" id="search" class="form-control" 
                               value="<?= htmlspecialchars($searchQuery) ?>" 
                               placeholder="Căutare după descriere, nume client...">
                    </div>
                    
                    <div class="form-group">
                        <label for="type">Tip Tranzacție</label>
                        <select name="type" id="type" class="form-control">
                            <option value="">Toate tipurile</option>
                            <?php foreach ($transactionTypes as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>" 
                                        <?= $typeFilter === $type ? 'selected' : '' ?>>
                                    <?= ucfirst($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status" class="form-control">
                            <option value="">Toate statusurile</option>
                            <?php foreach ($transactionStatuses as $status): ?>
                                <option value="<?= htmlspecialchars($status) ?>" 
                                        <?= $statusFilter === $status ? 'selected' : '' ?>>
                                    <?= ucfirst($status) ?>
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

            <!-- Transactions Table -->
            <div class="transactions-table-container">
                <?php if (!empty($transactions)): ?>
                    <table class="transactions-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tip</th>
                                <th>Sumă</th>
                                <th>Descriere</th>
                                <th>Status</th>
                                <th>Data</th>
                                <th>Acțiuni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td><?= $transaction['id'] ?></td>
                                    <td>
                                        <span class="badge badge-<?= $transaction['transaction_type'] ?>">
                                            <?= ucfirst($transaction['transaction_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= number_format($transaction['amount'], 2) ?> <?= $transaction['currency'] ?></td>
                                    <td><?= htmlspecialchars($transaction['description'] ?? '') ?></td>
                                    <td>
                                        <span class="status-badge status-<?= $transaction['status'] ?>">
                                            <?= ucfirst($transaction['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('d.m.Y H:i', strtotime($transaction['created_at'])) ?></td>
                                    <td>
                                        <div class="action-buttons">
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
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination-container">
                            <div class="pagination-info">
                                Pagina <?= $currentPage ?> din <?= $totalPages ?> 
                                (<?= number_format($totalTransactions) ?> tranzacții total)
                            </div>
                            <div class="pagination">
                                <?php if ($currentPage > 1): ?>
                                    <a href="?page=1&search=<?= urlencode($searchQuery) ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>" 
                                       class="btn btn-sm btn-outline-primary">Prima</a>
                                    <a href="?page=<?= $currentPage - 1 ?>&search=<?= urlencode($searchQuery) ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>" 
                                       class="btn btn-sm btn-outline-primary">Precedenta</a>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
                                    <a href="?page=<?= $i ?>&search=<?= urlencode($searchQuery) ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>" 
                                       class="btn btn-sm <?= $i === $currentPage ? 'btn-primary' : 'btn-outline-primary' ?>"><?= $i ?></a>
                                <?php endfor; ?>
                                
                                <?php if ($currentPage < $totalPages): ?>
                                    <a href="?page=<?= $currentPage + 1 ?>&search=<?= urlencode($searchQuery) ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>" 
                                       class="btn btn-sm btn-outline-primary">Următoarea</a>
                                    <a href="?page=<?= $totalPages ?>&search=<?= urlencode($searchQuery) ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>" 
                                       class="btn btn-sm btn-outline-primary">Ultima</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-content">
                            <span class="material-symbols-outlined">receipt_long</span>
                            <h3>Nu există tranzacții</h3>
                            <p>
                                <?php if (!empty($searchQuery) || !empty($typeFilter) || !empty($statusFilter)): ?>
                                    Nu s-au găsit tranzacții care să corespundă criteriilor de filtrare.
                                    <a href="?" class="btn btn-secondary">Șterge filtrele</a>
                                <?php else: ?>
                                    Creează prima tranzacție folosind butonul de mai sus.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
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
                                <input type="number" name="amount" id="amount" step="0.01" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="tax_amount" class="form-label">TVA</label>
                                <input type="number" name="tax_amount" id="tax_amount" step="0.01" class="form-control">
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

    <!-- Stock Purchase Modal -->
    <div class="modal" id="stockPurchaseModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Cumparare Stoc</h3>
                    <button class="modal-close" onclick="closeStockPurchaseModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form id="stockPurchaseForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_stock_purchase">
                        
                        <!-- Seller Selection -->
                        <div class="form-group">
                            <label for="seller_id" class="form-label">Furnizor *</label>
                            <select name="seller_id" id="seller_id" class="form-control" required onchange="updateSellerContact()">
                                <option value="">Selectează furnizor</option>
                                <?php foreach ($sellers as $seller): ?>
                                    <option value="<?= $seller['id'] ?>" 
                                            data-email="<?= htmlspecialchars($seller['email']) ?>"
                                            data-contact="<?= htmlspecialchars($seller['contact_person']) ?>"
                                            data-phone="<?= htmlspecialchars($seller['phone']) ?>">
                                        <?= htmlspecialchars($seller['supplier_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Contact Information -->
                        <div class="row">
                            <div class="form-group">
                                <label for="email_recipient" class="form-label">Email Destinatar</label>
                                <input type="email" name="email_recipient" id="email_recipient" class="form-control" 
                                       placeholder="Se va completa automat din furnizor">
                            </div>
                            <div class="form-group">
                                <label for="expected_delivery_date" class="form-label">Data Livrare Estimată</label>
                                <input type="date" name="expected_delivery_date" id="expected_delivery_date" class="form-control">
                            </div>
                        </div>

                        <!-- Custom Message -->
                        <div class="form-group">
                            <label for="custom_message" class="form-label">Mesaj Personalizat</label>
                            <textarea name="custom_message" id="custom_message" class="form-control" rows="3" 
                                      placeholder="Mesaj opțional pentru furnizor..."></textarea>
                        </div>

                        <!-- Product Selection -->
                        <div class="form-section">
                            <h4>Produse de Comandat</h4>
                            <div id="product-items">
                                <div class="product-item" data-index="0">
                                    <div class="product-item-header">
                                        <h5>Produs 1</h5>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="removeProductItem(0)" style="display: none;">
                                            <span class="material-symbols-outlined">delete</span>
                                        </button>
                                    </div>
                                    <div class="row">
                                        <div class="form-group">
                                            <label>Selectează Produs Existent</label>
                                            <select class="form-control existing-product-select" onchange="selectExistingProduct(0, this)">
                                                <option value="">Sau creează produs nou...</option>
                                                <?php foreach ($purchasableProducts as $product): ?>
                                                    <option value="<?= $product['id'] ?>" 
                                                            data-name="<?= htmlspecialchars($product['supplier_product_name']) ?>"
                                                            data-code="<?= htmlspecialchars($product['supplier_product_code']) ?>"
                                                            data-price="<?= $product['last_purchase_price'] ?>">
                                                        <?= htmlspecialchars($product['supplier_product_name']) ?>
                                                        <?php if ($product['supplier_product_code']): ?>
                                                            (<?= htmlspecialchars($product['supplier_product_code']) ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="form-group">
                                            <label>Nume Produs *</label>
                                            <input type="text" name="items[0][product_name]" class="form-control product-name" required 
                                                   placeholder="Nume produs de la furnizor">
                                            <input type="hidden" name="items[0][purchasable_product_id]" class="purchasable-product-id">
                                        </div>
                                        <div class="form-group">
                                            <label>Cod Produs</label>
                                            <input type="text" name="items[0][product_code]" class="form-control product-code" 
                                                   placeholder="Cod produs furnizor">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="form-group">
                                            <label>Cantitate *</label>
                                            <input type="number" name="items[0][quantity]" class="form-control quantity" 
                                                   step="0.001" min="0.001" required onchange="calculateItemTotal(0)">
                                        </div>
                                        <div class="form-group">
                                            <label>Preț Unitar (RON) *</label>
                                            <input type="number" name="items[0][unit_price]" class="form-control unit-price" 
                                                   step="0.01" min="0.01" required onchange="calculateItemTotal(0)">
                                        </div>
                                        <div class="form-group">
                                            <label>Total</label>
                                            <input type="text" class="form-control item-total" readonly>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Descriere</label>
                                        <textarea name="items[0][description]" class="form-control" rows="2" 
                                                  placeholder="Descriere suplimentară..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="button" class="btn btn-outline-primary" onclick="addProductItem()">
                                <span class="material-symbols-outlined">add</span>
                                Adaugă Produs
                            </button>
                        </div>

                        <!-- Order Total -->
                        <div class="order-summary">
                            <div class="total-row">
                                <span>Total Comandă:</span>
                                <span id="order-total">0.00 RON</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeStockPurchaseModal()">Anulează</button>
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
</div>

<script>
// Make purchasable products available to JavaScript
window.purchasableProducts = <?= json_encode($purchasableProducts) ?>;
</script>