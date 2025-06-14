<?php
// File: transactions.php - Transactions Management Interface with SmartBill Integration
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
    
    switch ($action) {
        case 'create_manual':
            $transactionData = [
                'transaction_type' => $_POST['transaction_type'] ?? '',
                'amount' => floatval($_POST['amount'] ?? 0),
                'tax_amount' => floatval($_POST['tax_amount'] ?? 0),
                'net_amount' => floatval($_POST['net_amount'] ?? 0),
                'currency' => $_POST['currency'] ?? 'RON',
                'description' => trim($_POST['description'] ?? ''),
                'customer_name' => trim($_POST['customer_name'] ?? ''),
                'supplier_name' => trim($_POST['supplier_name'] ?? ''),
                'invoice_date' => $_POST['invoice_date'] ?? date('Y-m-d'),
                'series' => trim($_POST['series'] ?? '')
            ];
            
            // Process items
            $items = [];
            if (!empty($_POST['items'])) {
                foreach ($_POST['items'] as $item) {
                    if (!empty($item['product_id']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                        $items[] = [
                            'product_id' => intval($item['product_id']),
                            'sku' => trim($item['sku'] ?? ''),
                            'product_name' => trim($item['product_name'] ?? ''),
                            'quantity' => floatval($item['quantity']),
                            'unit_price' => floatval($item['unit_price']),
                            'tax_percent' => floatval($item['tax_percent'] ?? 19.00),
                            'discount_percent' => floatval($item['discount_percent'] ?? 0),
                            'unit_of_measure' => trim($item['unit_of_measure'] ?? 'buc')
                        ];
                    }
                }
            }
            
            if (empty($transactionData['transaction_type'])) {
                $message = 'Tipul tranzacției este obligatoriu.';
                $messageType = 'error';
            } elseif (empty($items) && in_array($transactionData['transaction_type'], ['sales', 'purchase'])) {
                $message = 'Tranzacțiile de vânzare și cumpărare trebuie să conțină articole.';
                $messageType = 'error';
            } else {
                $transactionId = $transactionModel->createManualTransaction($transactionData, $items);
                if ($transactionId) {
                    $message = 'Tranzacția a fost creată cu succes și programată pentru sincronizare.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la crearea tranzacției.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'retry':
            $transactionId = intval($_POST['transaction_id'] ?? 0);
            
            if ($transactionId <= 0) {
                $message = 'ID tranzacție invalid.';
                $messageType = 'error';
            } else {
                if ($transactionModel->retryTransaction($transactionId)) {
                    $message = 'Tranzacția a fost reprogramată pentru procesare.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la reprogramarea tranzacției.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'cancel':
            $transactionId = intval($_POST['transaction_id'] ?? 0);
            $reason = trim($_POST['cancel_reason'] ?? '');
            
            if ($transactionId <= 0) {
                $message = 'ID tranzacție invalid.';
                $messageType = 'error';
            } else {
                if ($transactionModel->cancelTransaction($transactionId, $reason)) {
                    $message = 'Tranzacția a fost anulată cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la anularea tranzacției. Doar tranzacțiile în așteptare sau eșuate pot fi anulate.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'sync_now':
            $transactionId = intval($_POST['transaction_id'] ?? 0);
            
            if ($transactionId <= 0) {
                $message = 'ID tranzacție invalid.';
                $messageType = 'error';
            } else {
                if ($transactionModel->processSync($transactionId)) {
                    $message = 'Tranzacția a fost sincronizată cu succes cu SmartBill.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la sincronizarea tranzacției cu SmartBill.';
                    $messageType = 'error';
                }
            }
            break;
    }
}

// Get data for display
$filters = [
    'transaction_type' => $_GET['transaction_type'] ?? '',
    'status' => $_GET['status'] ?? '',
    'reference_type' => $_GET['reference_type'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'customer_name' => $_GET['customer_name'] ?? '',
    'smartbill_doc_number' => $_GET['smartbill_doc_number'] ?? ''
];

$limit = 50;
$page = intval($_GET['page'] ?? 1);
$offset = ($page - 1) * $limit;

$transactions = $transactionModel->getAllTransactions($filters, $limit, $offset);
$products = $productModel->getProductsWithInventory();
$statusCounts = $transactionModel->getStatusCounts();
$transactionStats = $transactionModel->getTransactionStats(30);

// Transaction types for dropdown
$transactionTypes = [
    'sales' => 'Vânzare',
    'purchase' => 'Cumpărare',
    'adjustment' => 'Ajustare',
    'transfer' => 'Transfer',
    'return' => 'Retur'
];

// Transaction statuses for dropdown
$transactionStatuses = [
    'pending' => 'În Așteptare',
    'processing' => 'În Procesare',
    'completed' => 'Finalizat',
    'failed' => 'Eșuat',
    'cancelled' => 'Anulat'
];

// Reference types for dropdown
$referenceTypes = [
    'order' => 'Comandă',
    'inventory' => 'Inventar',
    'location' => 'Locație',
    'manual' => 'Manual'
];
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Gestionare Tranzacții - WMS</title>
</head>
<body class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <main class="main-content">
        <div class="transactions-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Gestionare Tranzacții SmartBill</h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="openCreateModal()">
                        <span class="material-symbols-outlined">add_business</span>
                        Tranzacție Nouă
                    </button>
                    <button class="btn btn-info" onclick="openConfigModal()">
                        <span class="material-symbols-outlined">settings</span>
                        Configurare SmartBill
                    </button>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if (!empty($message)): ?>
                <div class="message <?= $messageType ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <?php foreach ($statusCounts as $status => $count): ?>
                    <div class="stat-card stat-<?= $status ?>">
                        <div class="stat-icon">
                            <span class="material-symbols-outlined">
                                <?php
                                $icons = [
                                    'pending' => 'schedule',
                                    'processing' => 'sync',
                                    'completed' => 'check_circle',
                                    'failed' => 'error',
                                    'cancelled' => 'cancel'
                                ];
                                echo $icons[$status] ?? 'description';
                                ?>
                            </span>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?= number_format($count) ?></div>
                            <div class="stat-label"><?= $transactionStatuses[$status] ?? ucfirst($status) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <select name="transaction_type" onchange="this.form.submit()">
                        <option value="">Toate tipurile</option>
                        <?php foreach ($transactionTypes as $typeKey => $typeLabel): ?>
                            <option value="<?= htmlspecialchars($typeKey) ?>" <?= $filters['transaction_type'] === $typeKey ? 'selected' : '' ?>>
                                <?= htmlspecialchars($typeLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="status" onchange="this.form.submit()">
                        <option value="">Toate statusurile</option>
                        <?php foreach ($transactionStatuses as $statusKey => $statusLabel): ?>
                            <option value="<?= htmlspecialchars($statusKey) ?>" <?= $filters['status'] === $statusKey ? 'selected' : '' ?>>
                                <?= htmlspecialchars($statusLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="reference_type" onchange="this.form.submit()">
                        <option value="">Toate referințele</option>
                        <?php foreach ($referenceTypes as $refKey => $refLabel): ?>
                            <option value="<?= htmlspecialchars($refKey) ?>" <?= $filters['reference_type'] === $refKey ? 'selected' : '' ?>>
                                <?= htmlspecialchars($refLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>" 
                           placeholder="De la data" onchange="this.form.submit()">
                    
                    <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>" 
                           placeholder="Până la data" onchange="this.form.submit()">
                    
                    <input type="text" name="customer_name" value="<?= htmlspecialchars($filters['customer_name']) ?>" 
                           placeholder="Numele clientului" onkeyup="debounceFilter(this.form)">
                    
                    <input type="text" name="smartbill_doc_number" value="<?= htmlspecialchars($filters['smartbill_doc_number']) ?>" 
                           placeholder="Nr. document SmartBill" onkeyup="debounceFilter(this.form)">
                    
                    <button type="submit" class="btn btn-secondary">
                        <span class="material-symbols-outlined">search</span>
                        Filtrează
                    </button>
                </form>
            </div>

            <!-- Transactions Table -->
            <?php if (!empty($transactions)): ?>
                <div class="transactions-table-container">
                    <table class="transactions-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tip</th>
                                <th>Referință</th>
                                <th>Client/Furnizor</th>
                                <th>Suma</th>
                                <th>Status</th>
                                <th>Document SmartBill</th>
                                <th>Data Creării</th>
                                <th>Acțiuni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr class="transaction-row" data-status="<?= htmlspecialchars($transaction['status']) ?>">
                                    <td>
                                        <strong>#<?= $transaction['id'] ?></strong>
                                    </td>
                                    <td>
                                        <span class="type-badge type-<?= htmlspecialchars($transaction['transaction_type']) ?>">
                                            <?= $transactionTypes[$transaction['transaction_type']] ?? $transaction['transaction_type'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="reference-info">
                                            <strong><?= $referenceTypes[$transaction['reference_type']] ?? $transaction['reference_type'] ?></strong>
                                            <?php if ($transaction['reference_id'] > 0): ?>
                                                <small>#<?= $transaction['reference_id'] ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($transaction['customer_name']): ?>
                                            <strong><?= htmlspecialchars($transaction['customer_name']) ?></strong>
                                        <?php elseif ($transaction['supplier_name']): ?>
                                            <strong><?= htmlspecialchars($transaction['supplier_name']) ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="amount-cell">
                                        <div class="amount-info">
                                            <strong><?= number_format($transaction['amount'], 2) ?> <?= $transaction['currency'] ?></strong>
                                            <?php if ($transaction['tax_amount']): ?>
                                                <small>TVA: <?= number_format($transaction['tax_amount'], 2) ?> <?= $transaction['currency'] ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= htmlspecialchars($transaction['status']) ?>">
                                            <span class="material-symbols-outlined">
                                                <?php
                                                $statusIcons = [
                                                    'pending' => 'schedule',
                                                    'processing' => 'sync',
                                                    'completed' => 'check_circle',
                                                    'failed' => 'error',
                                                    'cancelled' => 'cancel'
                                                ];
                                                echo $statusIcons[$transaction['status']] ?? 'help';
                                                ?>
                                            </span>
                                            <?= $transactionStatuses[$transaction['status']] ?? $transaction['status'] ?>
                                        </span>
                                        <?php if ($transaction['status'] === 'failed' && $transaction['retry_count'] > 0): ?>
                                            <small class="retry-info">Încercări: <?= $transaction['retry_count'] ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($transaction['smartbill_doc_number']): ?>
                                            <div class="smartbill-info">
                                                <strong><?= htmlspecialchars($transaction['smartbill_doc_number']) ?></strong>
                                                <?php if ($transaction['series']): ?>
                                                    <small>Serie: <?= htmlspecialchars($transaction['series']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">Nesincronizat</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= date('d.m.Y H:i', strtotime($transaction['created_at'])) ?>
                                        <small>de <?= htmlspecialchars($transaction['created_by_name']) ?></small>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <button class="btn btn-sm btn-secondary" onclick="viewTransaction(<?= $transaction['id'] ?>)" title="Vezi detalii">
                                                <span class="material-symbols-outlined">visibility</span>
                                            </button>
                                            
                                            <?php if ($transaction['status'] === 'failed'): ?>
                                                <button class="btn btn-sm btn-warning" onclick="retryTransaction(<?= $transaction['id'] ?>)" title="Reîncearcă">
                                                    <span class="material-symbols-outlined">refresh</span>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($transaction['status'] === 'pending'): ?>
                                                <button class="btn btn-sm btn-primary" onclick="syncNow(<?= $transaction['id'] ?>)" title="Sincronizează acum">
                                                    <span class="material-symbols-outlined">sync</span>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if (in_array($transaction['status'], ['pending', 'failed'])): ?>
                                                <button class="btn btn-sm btn-danger" onclick="cancelTransaction(<?= $transaction['id'] ?>)" title="Anulează">
                                                    <span class="material-symbols-outlined">cancel</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-outlined">receipt_long</span>
                    <h3>Nu există tranzacții</h3>
                    <p>Creați prima tranzacție folosind butonul de mai sus sau tranzacțiile vor fi generate automat din comenzi și mișcări de stoc.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Create Transaction Modal -->
    <div id="createTransactionModal" class="modal">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2 class="modal-title">Tranzacție Nouă</h2>
                <button class="close" onclick="closeCreateModal()">&times;</button>
            </div>
            <form id="createTransactionForm" method="POST">
                <input type="hidden" name="action" value="create_manual">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="transaction_type" class="form-label">Tip Tranzacție *</label>
                        <select name="transaction_type" id="transaction_type" class="form-input" required onchange="toggleTransactionFields()">
                            <option value="">Selectați tipul</option>
                            <?php foreach ($transactionTypes as $typeKey => $typeLabel): ?>
                                <option value="<?= htmlspecialchars($typeKey) ?>">
                                    <?= htmlspecialchars($typeLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="currency" class="form-label">Moneda</label>
                        <select name="currency" id="currency" class="form-input">
                            <option value="RON" selected>RON</option>
                            <option value="EUR">EUR</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="invoice_date" class="form-label">Data Facturii</label>
                        <input type="date" name="invoice_date" id="invoice_date" class="form-input" value="<?= date('Y-m-d') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="series" class="form-label">Serie Document</label>
                        <input type="text" name="series" id="series" class="form-input" placeholder="FACT">
                    </div>
                </div>
                
                <!-- Customer/Supplier fields -->
                <div id="customerFields" class="form-section" style="display: none;">
                    <div class="form-group">
                        <label for="customer_name" class="form-label">Numele Clientului *</label>
                        <input type="text" name="customer_name" id="customer_name" class="form-input">
                    </div>
                </div>
                
                <div id="supplierFields" class="form-section" style="display: none;">
                    <div class="form-group">
                        <label for="supplier_name" class="form-label">Numele Furnizorului *</label>
                        <input type="text" name="supplier_name" id="supplier_name" class="form-input">
                    </div>
                </div>
                
                <!-- Items Section -->
                <div id="itemsSection" class="form-section" style="display: none;">
                    <div class="section-header">
                        <h3>Articole</h3>
                        <button type="button" class="btn btn-sm btn-success" onclick="addTransactionItem()">
                            <span class="material-symbols-outlined">add</span>
                            Adaugă Articol
                        </button>
                    </div>
                    
                    <div id="transactionItems">
                        <!-- Items will be added here dynamically -->
                    </div>
                </div>
                
                <!-- Totals Section -->
                <div class="totals-section">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="net_amount" class="form-label">Suma fără TVA</label>
                            <input type="number" name="net_amount" id="net_amount" class="form-input" step="0.01" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label for="tax_amount" class="form-label">TVA</label>
                            <input type="number" name="tax_amount" id="tax_amount" class="form-input" step="0.01" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label for="amount" class="form-label">Total</label>
                            <input type="number" name="amount" id="amount" class="form-input" step="0.01" readonly>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="description" class="form-label">Descriere</label>
                    <textarea name="description" id="description" class="form-textarea" rows="3"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Anulează</button>
                    <button type="submit" class="btn btn-primary">Creează Tranzacția</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Transaction Details Modal -->
    <div id="transactionDetailsModal" class="modal">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2 class="modal-title">Detalii Tranzacție</h2>
                <button class="close" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div id="transactionDetailsContent">
                <!-- Transaction details will be loaded here -->
            </div>
        </div>
    </div>

    <!-- SmartBill Configuration Modal -->
    <div id="configModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Configurare SmartBill</h2>
                <button class="close" onclick="closeConfigModal()">&times;</button>
            </div>
            <div class="config-content">
                <p>Configurarea SmartBill se face prin setările avansate ale sistemului.</p>
                <p>Contactați administratorul pentru a configura credențialele API SmartBill.</p>
                
                <div class="config-info">
                    <h4>Informații necesare:</h4>
                    <ul>
                        <li>Username/Email SmartBill</li>
                        <li>Token API SmartBill</li>
                        <li>Cod TVA companie</li>
                        <li>Serie document implicit</li>
                    </ul>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeConfigModal()">Închide</button>
            </div>
        </div>
    </div>

    <!-- Action Modals -->
    <div id="retryModal" class="modal">
        <div class="modal-content modal-small">
            <div class="modal-header">
                <h2 class="modal-title">Confirmare Reîncercare</h2>
                <button class="close" onclick="closeRetryModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Sunteți sigur că doriți să reîncercați această tranzacție?</p>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="retry">
                <input type="hidden" name="transaction_id" id="retryTransactionId">
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeRetryModal()">Anulează</button>
                    <button type="submit" class="btn btn-warning">Reîncearcă</button>
                </div>
            </form>
        </div>
    </div>

    <div id="cancelModal" class="modal">
        <div class="modal-content modal-small">
            <div class="modal-header">
                <h2 class="modal-title">Anulare Tranzacție</h2>
                <button class="close" onclick="closeCancelModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="transaction_id" id="cancelTransactionId">
                
                <div class="modal-body">
                    <p>Sunteți sigur că doriți să anulați această tranzacție?</p>
                    <div class="form-group">
                        <label for="cancel_reason" class="form-label">Motiv anulare (opțional)</label>
                        <textarea name="cancel_reason" id="cancel_reason" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCancelModal()">Renunță</button>
                    <button type="submit" class="btn btn-danger">Anulează Tranzacția</button>
                </div>
            </form>
        </div>
    </div>

    <div id="syncModal" class="modal">
        <div class="modal-content modal-small">
            <div class="modal-header">
                <h2 class="modal-title">Sincronizare Imediată</h2>
                <button class="close" onclick="closeSyncModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Doriți să sincronizați această tranzacție cu SmartBill acum?</p>
                <p><small>Procesul poate dura câteva secunde.</small></p>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="sync_now">
                <input type="hidden" name="transaction_id" id="syncTransactionId">
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeSyncModal()">Anulează</button>
                    <button type="submit" class="btn btn-primary">Sincronizează</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let transactionItemCount = 0;
        let filterTimeout;

        // Modal functions
        function openCreateModal() {
            document.getElementById('createTransactionModal').style.display = 'block';
            resetCreateForm();
        }

        function closeCreateModal() {
            document.getElementById('createTransactionModal').style.display = 'none';
        }

        function openConfigModal() {
            document.getElementById('configModal').style.display = 'block';
        }

        function closeConfigModal() {
            document.getElementById('configModal').style.display = 'none';
        }

        function resetCreateForm() {
            document.getElementById('createTransactionForm').reset();
            document.getElementById('transactionItems').innerHTML = '';
            transactionItemCount = 0;
            toggleTransactionFields();
            calculateTotals();
        }

        function toggleTransactionFields() {
            const transactionType = document.getElementById('transaction_type').value;
            const customerFields = document.getElementById('customerFields');
            const supplierFields = document.getElementById('supplierFields');
            const itemsSection = document.getElementById('itemsSection');

            // Hide all fields first
            customerFields.style.display = 'none';
            supplierFields.style.display = 'none';
            itemsSection.style.display = 'none';

            // Show relevant fields based on transaction type
            if (transactionType === 'sales' || transactionType === 'return') {
                customerFields.style.display = 'block';
                itemsSection.style.display = 'block';
                if (document.getElementById('transactionItems').children.length === 0) {
                    addTransactionItem();
                }
            } else if (transactionType === 'purchase') {
                supplierFields.style.display = 'block';
                itemsSection.style.display = 'block';
                if (document.getElementById('transactionItems').children.length === 0) {
                    addTransactionItem();
                }
            } else if (transactionType === 'adjustment' || transactionType === 'transfer') {
                itemsSection.style.display = 'block';
                if (document.getElementById('transactionItems').children.length === 0) {
                    addTransactionItem();
                }
            }
        }

        function addTransactionItem() {
            transactionItemCount++;
            const itemHtml = `
                <div class="transaction-item" id="transactionItem${transactionItemCount}">
                    <div class="item-grid">
                        <div class="form-group">
                            <select name="items[${transactionItemCount}][product_id]" class="form-input" onchange="updateItemDetails(${transactionItemCount})" required>
                                <option value="">Selectați produsul</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?= $product['product_id'] ?>" 
                                            data-sku="<?= htmlspecialchars($product['sku']) ?>"
                                            data-name="<?= htmlspecialchars($product['name']) ?>"
                                            data-price="<?= $product['price'] ?>">
                                        <?= htmlspecialchars($product['sku']) ?> - <?= htmlspecialchars($product['name']) ?> (<?= $product['current_stock'] ?> în stoc)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <input type="text" name="items[${transactionItemCount}][sku]" class="form-input" placeholder="SKU" readonly>
                        </div>
                        <div class="form-group">
                            <input type="text" name="items[${transactionItemCount}][product_name]" class="form-input" placeholder="Nume produs" readonly>
                        </div>
                        <div class="form-group">
                            <input type="number" name="items[${transactionItemCount}][quantity]" class="form-input" 
                                   placeholder="Cantitate" step="0.001" min="0.001" required onchange="calculateTotals()">
                        </div>
                        <div class="form-group">
                            <input type="number" name="items[${transactionItemCount}][unit_price]" class="form-input" 
                                   placeholder="Preț unitar" step="0.01" min="0" required onchange="calculateTotals()">
                        </div>
                        <div class="form-group">
                            <input type="number" name="items[${transactionItemCount}][tax_percent]" class="form-input" 
                                   placeholder="TVA %" step="0.01" value="19.00" onchange="calculateTotals()">
                        </div>
                        <div class="form-group">
                            <input type="text" name="items[${transactionItemCount}][unit_of_measure]" class="form-input" 
                                   placeholder="UM" value="buc">
                        </div>
                        <div class="form-group">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeTransactionItem(${transactionItemCount})">
                                <span class="material-symbols-outlined">delete</span>
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('transactionItems').insertAdjacentHTML('beforeend', itemHtml);
        }

        function removeTransactionItem(itemId) {
            const item = document.getElementById(`transactionItem${itemId}`);
            if (item) {
                item.remove();
                calculateTotals();
            }
        }

        function updateItemDetails(itemId) {
            const productSelect = document.querySelector(`select[name="items[${itemId}][product_id]"]`);
            const selectedOption = productSelect.options[productSelect.selectedIndex];
            
            if (selectedOption.value) {
                const sku = selectedOption.getAttribute('data-sku');
                const name = selectedOption.getAttribute('data-name');
                const price = selectedOption.getAttribute('data-price');
                
                document.querySelector(`input[name="items[${itemId}][sku]"]`).value = sku || '';
                document.querySelector(`input[name="items[${itemId}][product_name]"]`).value = name || '';
                document.querySelector(`input[name="items[${itemId}][unit_price]"]`).value = price || '';
                
                calculateTotals();
            }
        }

        function calculateTotals() {
            let totalNet = 0;
            let totalTax = 0;
            
            const items = document.querySelectorAll('.transaction-item');
            items.forEach(item => {
                const quantity = parseFloat(item.querySelector('input[name*="[quantity]"]').value) || 0;
                const unitPrice = parseFloat(item.querySelector('input[name*="[unit_price]"]').value) || 0;
                const taxPercent = parseFloat(item.querySelector('input[name*="[tax_percent]"]').value) || 0;
                
                const lineTotal = quantity * unitPrice;
                const lineTax = lineTotal * (taxPercent / 100);
                
                totalNet += lineTotal;
                totalTax += lineTax;
            });
            
            document.getElementById('net_amount').value = totalNet.toFixed(2);
            document.getElementById('tax_amount').value = totalTax.toFixed(2);
            document.getElementById('amount').value = (totalNet + totalTax).toFixed(2);
        }

        // Transaction actions
        function viewTransaction(transactionId) {
            fetch(`transaction_details.php?id=${transactionId}`)
                .then(response => response.json())
                .then(transaction => {
                    const detailsHtml = generateTransactionDetailsHtml(transaction);
                    document.getElementById('transactionDetailsContent').innerHTML = detailsHtml;
                    document.getElementById('transactionDetailsModal').style.display = 'block';
                })
                .catch(error => {
                    console.error('Error loading transaction:', error);
                    alert('Eroare la încărcarea detaliilor tranzacției');
                });
        }

        function closeDetailsModal() {
            document.getElementById('transactionDetailsModal').style.display = 'none';
        }

        function retryTransaction(transactionId) {
            document.getElementById('retryTransactionId').value = transactionId;
            document.getElementById('retryModal').style.display = 'block';
        }

        function closeRetryModal() {
            document.getElementById('retryModal').style.display = 'none';
        }

        function cancelTransaction(transactionId) {
            document.getElementById('cancelTransactionId').value = transactionId;
            document.getElementById('cancelModal').style.display = 'block';
        }

        function closeCancelModal() {
            document.getElementById('cancelModal').style.display = 'none';
        }

        function syncNow(transactionId) {
            document.getElementById('syncTransactionId').value = transactionId;
            document.getElementById('syncModal').style.display = 'block';
        }

        function closeSyncModal() {
            document.getElementById('syncModal').style.display = 'none';
        }

        function generateTransactionDetailsHtml(transaction) {
            let itemsHtml = '';
            if (transaction.items && transaction.items.length > 0) {
                itemsHtml = transaction.items.map(item => `
                    <tr>
                        <td>${item.sku} - ${item.product_name}</td>
                        <td class="text-center">${parseFloat(item.quantity).toFixed(3)}</td>
                        <td class="text-right">${parseFloat(item.unit_price).toFixed(2)} ${transaction.currency}</td>
                        <td class="text-center">${parseFloat(item.tax_percent).toFixed(2)}%</td>
                        <td class="text-right">${parseFloat(item.total_amount).toFixed(2)} ${transaction.currency}</td>
                    </tr>
                `).join('');
            }
            
            return `
                <div class="transaction-details">
                    <div class="details-grid">
                        <div class="detail-section">
                            <h4>Informații Tranzacție</h4>
                            <p><strong>ID:</strong> #${transaction.id}</p>
                            <p><strong>Tip:</strong> ${transaction.transaction_type}</p>
                            <p><strong>Status:</strong> ${transaction.status}</p>
                            <p><strong>Data creării:</strong> ${new Date(transaction.created_at).toLocaleString('ro-RO')}</p>
                            ${transaction.sync_date ? `<p><strong>Data sincronizării:</strong> ${new Date(transaction.sync_date).toLocaleString('ro-RO')}</p>` : ''}
                        </div>
                        <div class="detail-section">
                            <h4>Informații SmartBill</h4>
                            ${transaction.smartbill_doc_number ? `<p><strong>Nr. document:</strong> ${transaction.smartbill_doc_number}</p>` : '<p>Nesincronizat</p>'}
                            ${transaction.series ? `<p><strong>Serie:</strong> ${transaction.series}</p>` : ''}
                            ${transaction.smartbill_doc_type ? `<p><strong>Tip document:</strong> ${transaction.smartbill_doc_type}</p>` : ''}
                        </div>
                    </div>
                    
                    <div class="amounts-section">
                        <h4>Sume</h4>
                        <div class="amounts-grid">
                            <div><strong>Suma fără TVA:</strong> ${parseFloat(transaction.net_amount || 0).toFixed(2)} ${transaction.currency}</div>
                            <div><strong>TVA:</strong> ${parseFloat(transaction.tax_amount || 0).toFixed(2)} ${transaction.currency}</div>
                            <div><strong>Total:</strong> ${parseFloat(transaction.amount || 0).toFixed(2)} ${transaction.currency}</div>
                        </div>
                    </div>
                    
                    ${transaction.items && transaction.items.length > 0 ? `
                        <div class="items-section">
                            <h4>Articole</h4>
                            <table class="details-table">
                                <thead>
                                    <tr>
                                        <th>Produs</th>
                                        <th>Cantitate</th>
                                        <th>Preț unitar</th>
                                        <th>TVA</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${itemsHtml}
                                </tbody>
                            </table>
                        </div>
                    ` : ''}
                    
                    ${transaction.description ? `
                        <div class="description-section">
                            <h4>Descriere</h4>
                            <p>${transaction.description}</p>
                        </div>
                    ` : ''}
                    
                    ${transaction.error_message ? `
                        <div class="error-section">
                            <h4>Eroare</h4>
                            <p class="error-message">${transaction.error_message}</p>
                            ${transaction.retry_count > 0 ? `<p><small>Încercări: ${transaction.retry_count}</small></p>` : ''}
                        </div>
                    ` : ''}
                </div>
            `;
        }

        // Filter with debounce
        function debounceFilter(form) {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                form.submit();
            }, 500);
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['createTransactionModal', 'transactionDetailsModal', 'configModal', 'retryModal', 'cancelModal', 'syncModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // Close modals with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeCreateModal();
                closeDetailsModal();
                closeConfigModal();
                closeRetryModal();
                closeCancelModal();
                closeSyncModal();
            }
        });

        // Initialize form
        document.addEventListener('DOMContentLoaded', function() {
            toggleTransactionFields();
        });
    </script>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>