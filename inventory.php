<?php
// File: inventory.php - Updated with table layout and fixed modals
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
require_once BASE_PATH . '/models/Inventory.php';
require_once BASE_PATH . '/models/Product.php';
require_once BASE_PATH . '/models/Location.php';
require_once BASE_PATH . '/models/LocationLevelSettings.php';

$inventoryModel = new Inventory($db);
$productModel = new Product($db);
$locationModel = new Location($db);

// Handle operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_stock':
            $stockData = [
                'product_id' => intval($_POST['product_id'] ?? 0),
                'location_id' => intval($_POST['location_id'] ?? 0),
                'quantity' => intval($_POST['quantity'] ?? 0),
                'batch_number' => trim($_POST['batch_number'] ?? ''),
                'lot_number' => trim($_POST['lot_number'] ?? ''),
                'expiry_date' => $_POST['expiry_date'] ?? null,
                'received_at' => $_POST['received_at'] ?? date('Y-m-d H:i:s'),
                'shelf_level' => $_POST['shelf_level'] ?? null
            ];
            
            if ($stockData['product_id'] <= 0 || $stockData['location_id'] <= 0 || $stockData['quantity'] <= 0) {
                $message = 'Produsul, locația și cantitatea sunt obligatorii.';
                $messageType = 'error';
            } else {
                if ($inventoryModel->addStock($stockData)) {
                    $message = 'Stocul a fost adăugat cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la adăugarea stocului.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'remove_stock':
            $productId = intval($_POST['product_id'] ?? 0);
            $quantity = intval($_POST['quantity'] ?? 0);
            $locationId = !empty($_POST['location_id']) ? intval($_POST['location_id']) : null;
            
            if ($productId <= 0 || $quantity <= 0) {
                $message = 'Produsul și cantitatea sunt obligatorii.';
                $messageType = 'error';
            } else {
                if ($inventoryModel->removeStock($productId, $quantity, $locationId)) {
                    $message = 'Stocul a fost redus cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la reducerea stocului sau stoc insuficient.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'move_stock':
            $productId      = intval($_POST['product_id'] ?? 0);
            $fromLocationId = intval($_POST['from_location_id'] ?? 0);
            $newLocationId  = intval($_POST['new_location_id'] ?? 0);
            $moveQuantity   = intval($_POST['move_quantity'] ?? 0);
            $inventoryId    = intval($_POST['inventory_id'] ?? 0) ?: null;

            if ($productId <= 0 || $fromLocationId <= 0 || $newLocationId <= 0 || $moveQuantity <= 0) {
                $message = 'Toate câmpurile sunt obligatorii pentru mutarea stocului.';
                $messageType = 'error';
            } else {
                if ($inventoryModel->moveStock($productId, $fromLocationId, $newLocationId, $moveQuantity, $inventoryId)) {
                    $message = 'Stocul a fost mutat cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la mutarea stocului.';
                    $messageType = 'error';
                }
            }
            break;
    }
}

// Get view mode and filters
$view = $_GET['view'] ?? 'detailed';
$productFilter = $_GET['product'] ?? '';
$locationFilter = $_GET['location'] ?? '';
$lowStockOnly = isset($_GET['low_stock']);

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($page - 1) * $pageSize;

// Get data based on view
switch ($view) {
    case 'summary':
        $allInventory = $inventoryModel->getStockSummary();
        $totalCount = count($allInventory);
        $inventory = array_slice($allInventory, $offset, $pageSize);
        break;
    case 'detailed':
        $allInventory = $inventoryModel->getInventoryWithFilters($productFilter, $locationFilter, $lowStockOnly);
        $totalCount = count($allInventory);
        $inventory = array_slice($allInventory, $offset, $pageSize);
        break;
    case 'low-stock':
        $allInventory = $inventoryModel->getLowStockItems();
        $totalCount = count($allInventory);
        $inventory = array_slice($allInventory, $offset, $pageSize);
        break;
    default:
        $allInventory = $inventoryModel->getInventoryWithFilters($productFilter, $locationFilter, $lowStockOnly);
        $totalCount = count($allInventory);
        $inventory = array_slice($allInventory, $offset, $pageSize);
}

$totalPages = ceil($totalCount / $pageSize);

$allProducts = $productModel->getAllProductsForDropdown();
$allLocations = $locationModel->getAllLocations();
$lowStockItems = $inventoryModel->getLowStockItems();
$expiringProducts = $inventoryModel->getExpiringProducts();

// Define current page for footer
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Gestionare Inventar - WMS</title>
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
                            <span class="material-symbols-outlined">inventory_2</span>
                            Gestionare Inventar
                        </h1>
                        <button class="btn btn-primary" onclick="openAddStockModal()">
                            <span class="material-symbols-outlined">add_box</span>
                            Adaugă Stoc
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

                <!-- Low Stock & Expiry Alerts -->
                <?php if (!empty($lowStockItems) && $view !== 'low-stock'): ?>
                    <div class="low-stock-warning">
                        <span class="material-symbols-outlined">warning</span>
                        <strong>Atenție:</strong> Există <?= count($lowStockItems) ?> produse cu stoc scăzut.
                        <a href="?view=low-stock" class="alert-link">Vezi produsele</a>
                    </div>
                <?php endif; ?>

                <?php if (!empty($expiringProducts)): ?>
                    <div class="low-stock-warning">
                        <span class="material-symbols-outlined">schedule</span>
                        <strong>Atenție:</strong> Există <?= count($expiringProducts) ?> produse care expiră în 30 de zile.
                    </div>
                <?php endif; ?>

                <!-- View Controls -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Control Inventar</h3>
                        <div class="card-actions">
                            <!-- View Toggle -->
                            <div class="view-toggle">
                                <a href="?view=detailed<?= $productFilter ? '&product=' . $productFilter : '' ?><?= $locationFilter ? '&location=' . $locationFilter : '' ?><?= $lowStockOnly ? '&low_stock=1' : '' ?>" 
                                   class="toggle-link <?= $view === 'detailed' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">table_view</span>
                                    Detaliat
                                </a>
                                <a href="?view=summary<?= $productFilter ? '&product=' . $productFilter : '' ?><?= $locationFilter ? '&location=' . $locationFilter : '' ?><?= $lowStockOnly ? '&low_stock=1' : '' ?>" 
                                   class="toggle-link <?= $view === 'summary' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">dashboard</span>
                                    Sumar
                                </a>
                                <a href="?view=low-stock" 
                                   class="toggle-link <?= $view === 'low-stock' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">warning</span>
                                    Stoc Scăzut
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($view === 'detailed'): ?>
                    <div class="card-body">
                        <!-- Filters -->
                        <form method="GET" class="filter-form">
                            <input type="hidden" name="view" value="detailed">
                            
                            <div class="form-group">
                                <label class="form-label">Produs</label>
                                <select name="product" class="form-control">
                                    <option value="">Toate produsele</option>
                                    <?php foreach ($allProducts as $product): ?>
                                        <option value="<?= $product['product_id'] ?>" <?= $productFilter == $product['product_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($product['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Locație</label>
                                <select name="location" class="form-control">
                                    <option value="">Toate locațiile</option>
                                    <?php foreach ($allLocations as $location): ?>
                                        <option value="<?= $location['id'] ?>" <?= $locationFilter == $location['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($location['location_code']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="low_stock" value="1" <?= $lowStockOnly ? 'checked' : '' ?>>
                                    Doar stoc scăzut
                                </label>
                            </div>
                            
                            <button type="submit" class="btn btn-secondary">
                                <span class="material-symbols-outlined">filter_alt</span>
                                Filtrează
                            </button>
                            
                            <a href="?view=detailed" class="btn btn-secondary">
                                <span class="material-symbols-outlined">refresh</span>
                                Reset
                            </a>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Inventory Table -->
                <div class="card">
                    <div class="card-body">
                        <?php if (!empty($inventory)): ?>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <?php if ($view === 'summary'): ?>
                                                <th>SKU</th>
                                                <th>Nume Produs</th>
                                                <th>Categorie</th>
                                                <th>Stoc Total</th>
                                                <th>Locații</th>
                                                <th>Status</th>
                                                <th>Acțiuni</th>
                                            <?php elseif ($view === 'low-stock'): ?>
                                                <th>SKU</th>
                                                <th>Nume Produs</th>
                                                <th>Stoc Curent</th>
                                                <th>Stoc Minim</th>
                                                <th>Diferență</th>
                                                <th>Locații</th>
                                                <th>Acțiuni</th>
                                            <?php else: ?>
                                                <th>SKU</th>
                                                <th>Produs</th>
                                                <th>Locație</th>
                                                <th>Cantitate</th>
                                                <th>Batch/Lot</th>
                                                <th>Primire</th>
                                                <th>Expirare</th>
                                                <th>Acțiuni</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($inventory as $item): ?>
                                            <tr>
                                                <?php if ($view === 'summary'): ?>
                                                    <td>
                                                        <code class="sku-code"><?= htmlspecialchars($item['sku']) ?></code>
                                                    </td>
                                                    <td>
                                                        <div class="product-info">
                                                            <strong><?= htmlspecialchars($item['name']) ?></strong>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="location-badge"><?= htmlspecialchars($item['category'] ?? 'N/A') ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="stock-info <?= ($item['total_stock'] ?? 0) <= ($item['min_stock_level'] ?? 0) ? 'stock-low' : 'stock-good' ?>">
                                                            <?= number_format($item['total_stock'] ?? 0) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= $item['locations_count'] ?? 0 ?> locații</td>
                                                    <td>
                                                        <?php if (($item['total_stock'] ?? 0) <= ($item['min_stock_level'] ?? 0)): ?>
                                                            <span class="badge badge-danger">Stoc Scăzut</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-success">OK</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group">
                                                            <button class="btn btn-sm btn-outline-primary" onclick="addStockForProduct(<?= $item['product_id'] ?>)" title="Adaugă stoc">
                                                                <span class="material-symbols-outlined">add</span>
                                                            </button>
                                                            <a href="?view=detailed&product=<?= $item['product_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Vezi detalii">
                                                                <span class="material-symbols-outlined">visibility</span>
                                                            </a>
                                                        </div>
                                                    </td>
                                                <?php elseif ($view === 'low-stock'): ?>
                                                    <td>
                                                        <code class="sku-code"><?= htmlspecialchars($item['sku']) ?></code>
                                                    </td>
                                                    <td>
                                                        <div class="product-info">
                                                            <strong><?= htmlspecialchars($item['name']) ?></strong>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="stock-info stock-low"><?= number_format($item['current_stock'] ?? 0) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="stock-info"><?= number_format($item['min_stock_level'] ?? 0) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="stock-info stock-low">
                                                            <?= number_format(($item['min_stock_level'] ?? 0) - ($item['current_stock'] ?? 0)) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= $item['locations_count'] ?? 0 ?> locații</td>
                                                    <td>
                                                        <div class="btn-group">
                                                            <button class="btn btn-sm btn-outline-primary" onclick="addStockForProduct(<?= $item['product_id'] ?>)" title="Adaugă stoc">
                                                                <span class="material-symbols-outlined">add</span>
                                                            </button>
                                                        </div>
                                                    </td>
                                                <?php else: ?>
                                                    <td>
                                                        <code class="sku-code"><?= htmlspecialchars($item['sku']) ?></code>
                                                    </td>
                                                    <td>
                                                        <div class="product-info">
                                                            <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="location-badge"><?= htmlspecialchars($item['location_code']) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="stock-info <?= $item['quantity'] <= ($item['min_stock_level'] ?? 0) ? 'stock-low' : 'stock-good' ?>">
                                                            <?= number_format($item['quantity']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="batch-lot-info">
                                                            <?php if (!empty($item['batch_number'])): ?>
                                                                <div><small>Batch: <code><?= htmlspecialchars($item['batch_number']) ?></code></small></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($item['lot_number'])): ?>
                                                                <div><small>Lot: <code><?= htmlspecialchars($item['lot_number']) ?></code></small></div>
                                                            <?php endif; ?>
                                                            <?php if (empty($item['batch_number']) && empty($item['lot_number'])): ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($item['received_at'])): ?>
                                                            <small><?= date('d.m.Y', strtotime($item['received_at'])) ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($item['expiry_date'])): ?>
                                                            <?php 
                                                            $expiryDate = new DateTime($item['expiry_date']);
                                                            $today = new DateTime();
                                                            $diff = $today->diff($expiryDate);
                                                            $isExpired = $expiryDate < $today;
                                                            $isExpiringSoon = $diff->days <= 30 && !$isExpired;
                                                            ?>
                                                            <span class="expiry-date <?= $isExpired ? 'expired' : ($isExpiringSoon ? 'expiring-soon' : '') ?>">
                                                                <?= $expiryDate->format('d.m.Y') ?>
                                                                <?php if ($isExpired): ?>
                                                                    <br><small>(Expirat)</small>
                                                                <?php elseif ($isExpiringSoon): ?>
                                                                    <br><small>(<?= $diff->days ?> zile)</small>
                                                                <?php endif; ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group">
                                                            <button class="btn btn-sm btn-outline-primary" 
                                                                    onclick="openMoveStockModal(<?= htmlspecialchars(json_encode($item)) ?>)"
                                                                    title="Mută stoc">
                                                                <span class="material-symbols-outlined">move_location</span>
                                                            </button>
                                                            <button class="btn btn-sm btn-outline-danger" 
                                                                    onclick="openRemoveStockModal(<?= $item['product_id'] ?>, '<?= htmlspecialchars(addslashes($item['product_name'])) ?>')"
                                                                    title="Reduce stoc">
                                                                <span class="material-symbols-outlined">remove</span>
                                                            </button>
                                                        </div>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <div class="pagination-container">
                                    <div class="pagination-info">
                                        Afișare <?= ($offset + 1) ?>-<?= min($offset + $pageSize, $totalCount) ?> din <?= number_format($totalCount) ?> elemente
                                    </div>
                                    <div class="pagination-controls">
                                        <?php if ($page > 1): ?>
                                            <a href="?view=<?= $view ?><?= $productFilter ? '&product=' . $productFilter : '' ?><?= $locationFilter ? '&location=' . $locationFilter : '' ?><?= $lowStockOnly ? '&low_stock=1' : '' ?>&page=<?= ($page - 1) ?>" 
                                               class="pagination-btn">
                                                <span class="material-symbols-outlined">chevron_left</span>
                                                Anterior
                                            </a>
                                        <?php endif; ?>
                                        
                                        <span class="pagination-current">
                                            Pagina <?= $page ?> din <?= $totalPages ?>
                                        </span>
                                        
                                        <?php if ($page < $totalPages): ?>
                                            <a href="?view=<?= $view ?><?= $productFilter ? '&product=' . $productFilter : '' ?><?= $locationFilter ? '&location=' . $locationFilter : '' ?><?= $lowStockOnly ? '&low_stock=1' : '' ?>&page=<?= ($page + 1) ?>" 
                                               class="pagination-btn">
                                                Următor
                                                <span class="material-symbols-outlined">chevron_right</span>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">inventory_2</span>
                                <h3>Nu există produse în inventar</h3>
                                <p>Adaugă primul produs în inventar folosind butonul de mai sus.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Stock Modal -->
    <div class="modal" id="addStockModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Adaugă Stoc</h3>
                    <button class="modal-close" onclick="closeAddStockModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_stock">
                        
                        <div class="form-group">
                            <label for="add-product" class="form-label">Produs *</label>
                            <select id="add-product" name="product_id" class="form-control" required>
                                <option value="">Selectează produs</option>
                                <?php foreach ($allProducts as $product): ?>
                                    <option value="<?= $product['product_id'] ?>">
                                        <?= htmlspecialchars($product['name']) ?> (<?= htmlspecialchars($product['sku']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="add-location" class="form-label">Locație *</label>
                            <select id="add-location" name="location_id" class="form-control" required>
                                <option value="">Selectează locația</option>
                                <?php foreach ($allLocations as $location): ?>
                                    <option value="<?= $location['id'] ?>">
                                        <?= htmlspecialchars($location['location_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="shelf_level" class="form-label">Nivel raft</label>
                            <select id="shelf_level" name="shelf_level" class="form-control">
                                <option value="">--</option>
                                <option value="top">Top</option>
                                <option value="middle">Middle</option>
                                <option value="bottom">Bottom</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="add-quantity" class="form-label">Cantitate *</label>
                                <input type="number" id="add-quantity" name="quantity" class="form-control" min="1" required>
                            </div>
                            <div class="form-group">
                                <label for="add-expiry" class="form-label">Data Expirării</label>
                                <input type="date" id="add-expiry" name="expiry_date" class="form-control">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="add-batch" class="form-label">Număr Batch</label>
                                <input type="text" id="add-batch" name="batch_number" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="add-lot" class="form-label">Număr Lot</label>
                                <input type="text" id="add-lot" name="lot_number" class="form-control">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="add-received" class="form-label">Data Primirii</label>
                            <input type="datetime-local" id="add-received" name="received_at" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeAddStockModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Adaugă Stoc</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Remove Stock Modal -->
    <div class="modal" id="removeStockModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Reduce Stoc</h3>
                    <button class="modal-close" onclick="closeRemoveStockModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="remove_stock">
                        <input type="hidden" id="remove-product-id" name="product_id">
                        
                        <div class="alert alert-warning">
                            <span class="material-symbols-outlined">warning</span>
                            Vei reduce stocul pentru produsul: <strong id="remove-product-name"></strong>
                        </div>
                        
                        <div class="form-group">
                            <label for="remove-quantity" class="form-label">Cantitate de redus *</label>
                            <input type="number" id="remove-quantity" name="quantity" class="form-control" min="1" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="remove-location" class="form-label">Locație (opțional)</label>
                            <select id="remove-location" name="location_id" class="form-control">
                                <option value="">Din toate locațiile</option>
                                <?php foreach ($allLocations as $location): ?>
                                    <option value="<?= $location['id'] ?>">
                                        <?= htmlspecialchars($location['location_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeRemoveStockModal()">Anulează</button>
                        <button type="submit" class="btn btn-danger">Reduce Stoc</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Move Stock Modal -->
    <div class="modal" id="moveStockModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Mută Stoc</h3>
                    <button class="modal-close" onclick="closeMoveStockModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="move_stock">
                        <input type="hidden" id="move-inventory-id" name="inventory_id">
                        <input type="hidden" id="move-product-id" name="product_id">
                        <input type="hidden" id="move-from-location-id" name="from_location_id">
                        
                        <div class="alert alert-info">
                            <span class="material-symbols-outlined">info</span>
                            Mutare stoc pentru: <strong id="move-product-name"></strong>
                        </div>
                        
                        <div class="form-group">
                            <label for="move-new-location" class="form-label">Locație nouă *</label>
                            <select id="move-new-location" name="new_location_id" class="form-control" required>
                                <option value="">Selectează locația</option>
                                <?php foreach ($allLocations as $location): ?>
                                    <option value="<?= $location['id'] ?>">
                                        <?= htmlspecialchars($location['location_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="move-quantity" class="form-label">Cantitate de mutat *</label>
                            <input type="number" id="move-quantity" name="move_quantity" class="form-control" min="1" required>
                            <small class="form-text text-muted">Cantitate disponibilă: <span id="available-quantity"></span></small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeMoveStockModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Mută Stoc</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>