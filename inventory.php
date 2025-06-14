<?php
// File: inventory.php - Inventory Management Interface
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
                'received_at' => $_POST['received_at'] ?? date('Y-m-d H:i:s')
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
                    $message = 'Stocul a fost eliminat cu succes (FIFO).';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la eliminarea stocului. Verificați cantitatea disponibilă.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'move_stock':
            $inventoryId = intval($_POST['inventory_id'] ?? 0);
            $newLocationId = intval($_POST['new_location_id'] ?? 0);
            $quantity = !empty($_POST['move_quantity']) ? intval($_POST['move_quantity']) : null;
            
            if ($inventoryId <= 0 || $newLocationId <= 0) {
                $message = 'Inventarul și noua locație sunt obligatorii.';
                $messageType = 'error';
            } else {
                if ($inventoryModel->moveStock($inventoryId, $newLocationId, $quantity)) {
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

// Get data for display
$view = $_GET['view'] ?? 'summary';
$filters = [
    'product_id' => $_GET['product_id'] ?? '',
    'location_id' => $_GET['location_id'] ?? '',
    'zone' => $_GET['zone'] ?? '',
    'low_stock' => isset($_GET['low_stock'])
];

switch ($view) {
    case 'detailed':
        $inventory = $inventoryModel->getAllInventory($filters);
        break;
    case 'low_stock':
        $inventory = $inventoryModel->getLowStockProducts();
        break;
    default:
        $inventory = $inventoryModel->getStockSummary();
        break;
}

// Get data for dropdowns
$products = $productModel->getProductsWithInventory();
$locations = $locationModel->getAllLocations();
$zones = $locationModel->getZones();

$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Gestionare Inventar - WMS</title>
</head>
<body class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <main class="main-content">
        <div class="inventory-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Gestionare Inventar</h1>
                <div class="header-actions">
                    <button class="btn btn-success" onclick="openAddStockModal()">
                        <span class="material-symbols-outlined">add_box</span>
                        Adaugă Stoc
                    </button>
                    <button class="btn btn-warning" onclick="openRemoveStockModal()">
                        <span class="material-symbols-outlined">remove_circle</span>
                        Elimină Stoc
                    </button>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if (!empty($message)): ?>
                <div class="message <?= $messageType ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- View Toggle & Filters -->
            <div class="inventory-controls">
                <div class="view-toggle">
                    <a href="?view=summary" class="toggle-link <?= $view === 'summary' ? 'active' : '' ?>">
                        <span class="material-symbols-outlined">summarize</span>
                        Sumar
                    </a>
                    <a href="?view=detailed" class="toggle-link <?= $view === 'detailed' ? 'active' : '' ?>">
                        <span class="material-symbols-outlined">list</span>
                        Detaliat
                    </a>
                    <a href="?view=low_stock" class="toggle-link <?= $view === 'low_stock' ? 'active' : '' ?>">
                        <span class="material-symbols-outlined">warning</span>
                        Stoc Scăzut
                    </a>
                </div>

                <?php if ($view !== 'low_stock'): ?>
                <form method="GET" class="filter-form">
                    <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                    
                    <select name="product_id" onchange="this.form.submit()">
                        <option value="">Toate produsele</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?= $product['product_id'] ?>" <?= $filters['product_id'] == $product['product_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($product['sku']) ?> - <?= htmlspecialchars($product['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="location_id" onchange="this.form.submit()">
                        <option value="">Toate locațiile</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?= $location['id'] ?>" <?= $filters['location_id'] == $location['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($location['location_code']) ?> (<?= htmlspecialchars($location['zone']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="zone" onchange="this.form.submit()">
                        <option value="">Toate zonele</option>
                        <?php foreach ($zones as $zone): ?>
                            <option value="<?= htmlspecialchars($zone) ?>" <?= $filters['zone'] === $zone ? 'selected' : '' ?>>
                                <?= htmlspecialchars($zone) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <label class="checkbox-label">
                        <input type="checkbox" name="low_stock" <?= $filters['low_stock'] ? 'checked' : '' ?> onchange="this.form.submit()">
                        Doar stoc scăzut
                    </label>
                </form>
                <?php endif; ?>
            </div>

            <!-- Inventory Display -->
            <?php if (!empty($inventory)): ?>
                <?php if ($view === 'summary'): ?>
                    <!-- Summary View -->
                    <div class="inventory-summary">
                        <?php foreach ($inventory as $item): ?>
                            <div class="summary-card <?= $item['total_stock'] <= $item['min_stock_level'] ? 'low-stock' : '' ?>">
                                <div class="card-header">
                                    <h3><?= htmlspecialchars($item['name']) ?></h3>
                                    <span class="sku"><?= htmlspecialchars($item['sku']) ?></span>
                                </div>
                                
                                <div class="card-content">
                                    <div class="stock-info">
                                        <div class="stock-number">
                                            <span class="stock-value"><?= number_format($item['total_stock']) ?></span>
                                            <span class="stock-label">Total</span>
                                        </div>
                                        <div class="stock-details">
                                            <div class="detail">
                                                <span class="material-symbols-outlined">pin_drop</span>
                                                <?= $item['locations_count'] ?> locații
                                            </div>
                                            <div class="detail">
                                                <span class="material-symbols-outlined">category</span>
                                                <?= htmlspecialchars($item['category'] ?? 'N/A') ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($item['total_stock'] <= $item['min_stock_level']): ?>
                                        <div class="low-stock-warning">
                                            <span class="material-symbols-outlined">warning</span>
                                            Stoc scăzut! (Min: <?= $item['min_stock_level'] ?>)
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="card-actions">
                                    <button class="btn btn-sm btn-secondary" onclick="viewProductDetails(<?= $item['product_id'] ?>)">
                                        Detalii
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                <?php elseif ($view === 'detailed'): ?>
                    <!-- Detailed View -->
                    <div class="inventory-table-container">
                        <table class="inventory-table">
                            <thead>
                                <tr>
                                    <th>Produs</th>
                                    <th>Locație</th>
                                    <th>Cantitate</th>
                                    <th>Lot/Batch</th>
                                    <th>Data Primirii</th>
                                    <th>Expirare</th>
                                    <th>Acțiuni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inventory as $item): ?>
                                    <tr>
                                        <td>
                                            <div class="product-info">
                                                <strong><?= htmlspecialchars($item['sku']) ?></strong>
                                                <span><?= htmlspecialchars($item['product_name']) ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="location-info">
                                                <strong><?= htmlspecialchars($item['location_code']) ?></strong>
                                                <span><?= htmlspecialchars($item['zone']) ?> - <?= htmlspecialchars($item['location_type']) ?></span>
                                            </div>
                                        </td>
                                        <td class="quantity"><?= number_format($item['quantity']) ?></td>
                                        <td>
                                            <?php if ($item['batch_number'] || $item['lot_number']): ?>
                                                <div class="batch-info">
                                                    <?php if ($item['batch_number']): ?>
                                                        <span>Batch: <?= htmlspecialchars($item['batch_number']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($item['lot_number']): ?>
                                                        <span>Lot: <?= htmlspecialchars($item['lot_number']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('d.m.Y H:i', strtotime($item['received_at'])) ?></td>
                                        <td>
                                            <?php if ($item['expiry_date']): ?>
                                                <?php 
                                                $expiryDate = new DateTime($item['expiry_date']);
                                                $today = new DateTime();
                                                $diff = $today->diff($expiryDate);
                                                $isExpired = $expiryDate < $today;
                                                $isExpiringSoon = !$isExpired && $diff->days <= 30;
                                                ?>
                                                <span class="expiry-date <?= $isExpired ? 'expired' : ($isExpiringSoon ? 'expiring-soon' : '') ?>">
                                                    <?= $expiryDate->format('d.m.Y') ?>
                                                    <?php if ($isExpired): ?>
                                                        <small>(Expirat)</small>
                                                    <?php elseif ($isExpiringSoon): ?>
                                                        <small>(<?= $diff->days ?> zile)</small>
                                                    <?php endif; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="table-actions">
                                                <button class="btn btn-sm btn-secondary" onclick="openMoveStockModal(<?= htmlspecialchars(json_encode($item)) ?>)">
                                                    <span class="material-symbols-outlined">move_location</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                <?php else: ?>
                    <!-- Low Stock View -->
                    <div class="low-stock-alerts">
                        <?php foreach ($inventory as $item): ?>
                            <div class="alert-card">
                                <div class="alert-icon">
                                    <span class="material-symbols-outlined">warning</span>
                                </div>
                                <div class="alert-content">
                                    <h3><?= htmlspecialchars($item['name']) ?></h3>
                                    <div class="alert-details">
                                        <span class="sku"><?= htmlspecialchars($item['sku']) ?></span>
                                        <span class="stock-info">
                                            Stoc: <strong><?= number_format($item['current_stock']) ?></strong> / 
                                            Min: <strong><?= number_format($item['min_stock_level']) ?></strong>
                                        </span>
                                    </div>
                                </div>
                                <div class="alert-actions">
                                    <button class="btn btn-primary" onclick="quickAddStock(<?= $item['product_id'] ?>)">
                                        Adaugă Stoc
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-outlined">inventory_2</span>
                    <h3>Nu există date de inventar</h3>
                    <p>Adăugați primul stoc folosind butonul de mai sus.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Add Stock Modal -->
    <div id="addStockModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Adaugă Stoc</h2>
                <button class="close" onclick="closeAddStockModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_stock">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="add_product_id" class="form-label">Produs *</label>
                        <select name="product_id" id="add_product_id" class="form-input" required>
                            <option value="">Selectați produsul</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= $product['product_id'] ?>">
                                    <?= htmlspecialchars($product['sku']) ?> - <?= htmlspecialchars($product['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_location_id" class="form-label">Locație *</label>
                        <select name="location_id" id="add_location_id" class="form-input" required>
                            <option value="">Selectați locația</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= $location['id'] ?>">
                                    <?= htmlspecialchars($location['location_code']) ?> (<?= htmlspecialchars($location['zone']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_quantity" class="form-label">Cantitate *</label>
                        <input type="number" name="quantity" id="add_quantity" class="form-input" min="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_received_at" class="form-label">Data Primirii</label>
                        <input type="datetime-local" name="received_at" id="add_received_at" class="form-input" value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="add_batch_number" class="form-label">Numărul Batch</label>
                        <input type="text" name="batch_number" id="add_batch_number" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label for="add_lot_number" class="form-label">Numărul Lot</label>
                        <input type="text" name="lot_number" id="add_lot_number" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label for="add_expiry_date" class="form-label">Data Expirării</label>
                        <input type="date" name="expiry_date" id="add_expiry_date" class="form-input">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAddStockModal()">Anulează</button>
                    <button type="submit" class="btn btn-success">Adaugă Stoc</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Remove Stock Modal -->
    <div id="removeStockModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Elimină Stoc (FIFO)</h2>
                <button class="close" onclick="closeRemoveStockModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="remove_stock">
                
                <div class="form-group">
                    <label for="remove_product_id" class="form-label">Produs *</label>
                    <select name="product_id" id="remove_product_id" class="form-input" required>
                        <option value="">Selectați produsul</option>
                        <?php foreach ($products as $product): ?>
                            <?php if ($product['current_stock'] > 0): ?>
                                <option value="<?= $product['product_id'] ?>">
                                    <?= htmlspecialchars($product['sku']) ?> - <?= htmlspecialchars($product['name']) ?> (Stoc: <?= $product['current_stock'] ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="remove_location_id" class="form-label">Locație (opțional)</label>
                    <select name="location_id" id="remove_location_id" class="form-input">
                        <option value="">Toate locațiile (FIFO global)</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?= $location['id'] ?>">
                                <?= htmlspecialchars($location['location_code']) ?> (<?= htmlspecialchars($location['zone']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="remove_quantity" class="form-label">Cantitate *</label>
                    <input type="number" name="quantity" id="remove_quantity" class="form-input" min="1" required>
                </div>
                
                <div class="form-help">
                    <p><strong>FIFO:</strong> Stocul va fi eliminat automat din cele mai vechi loturi primul.</p>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeRemoveStockModal()">Anulează</button>
                    <button type="submit" class="btn btn-warning">Elimină Stoc</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Move Stock Modal -->
    <div id="moveStockModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Mutare Stoc</h2>
                <button class="close" onclick="closeMoveStockModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="move_stock">
                <input type="hidden" name="inventory_id" id="move_inventory_id">
                
                <div id="move_stock_info" class="info-section"></div>
                
                <div class="form-group">
                    <label for="new_location_id" class="form-label">Noua Locație *</label>
                    <select name="new_location_id" id="new_location_id" class="form-input" required>
                        <option value="">Selectați noua locație</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?= $location['id'] ?>">
                                <?= htmlspecialchars($location['location_code']) ?> (<?= htmlspecialchars($location['zone']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="move_quantity" class="form-label">Cantitate (opțional - implicit tot stocul)</label>
                    <input type="number" name="move_quantity" id="move_quantity" class="form-input" min="1">
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeMoveStockModal()">Anulează</button>
                    <button type="submit" class="btn btn-primary">Mută Stocul</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openAddStockModal() {
            document.getElementById('addStockModal').style.display = 'block';
        }

        function closeAddStockModal() {
            document.getElementById('addStockModal').style.display = 'none';
        }

        function openRemoveStockModal() {
            document.getElementById('removeStockModal').style.display = 'block';
        }

        function closeRemoveStockModal() {
            document.getElementById('removeStockModal').style.display = 'none';
        }

        function openMoveStockModal(item) {
            document.getElementById('move_inventory_id').value = item.id;
            document.getElementById('move_quantity').max = item.quantity;
            
            const infoHtml = `
                <h4>Informații Stoc</h4>
                <p><strong>Produs:</strong> ${item.sku} - ${item.product_name}</p>
                <p><strong>Locația Curentă:</strong> ${item.location_code} (${item.zone})</p>
                <p><strong>Cantitate Disponibilă:</strong> ${parseInt(item.quantity).toLocaleString()}</p>
                ${item.batch_number ? `<p><strong>Batch:</strong> ${item.batch_number}</p>` : ''}
                ${item.lot_number ? `<p><strong>Lot:</strong> ${item.lot_number}</p>` : ''}
            `;
            
            document.getElementById('move_stock_info').innerHTML = infoHtml;
            document.getElementById('moveStockModal').style.display = 'block';
        }

        function closeMoveStockModal() {
            document.getElementById('moveStockModal').style.display = 'none';
        }

        function viewProductDetails(productId) {
            window.location.href = `?view=detailed&product_id=${productId}`;
        }

        function quickAddStock(productId) {
            document.getElementById('add_product_id').value = productId;
            openAddStockModal();
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['addStockModal', 'removeStockModal', 'moveStockModal'];
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
                closeAddStockModal();
                closeRemoveStockModal();
                closeMoveStockModal();
            }
        });
    </script>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>