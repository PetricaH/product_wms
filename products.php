<?php
// File: products.php - Compact Product Management with Pagination
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';
// Database connection
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    die("Database connection factory not configured correctly.");
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include models
require_once BASE_PATH . '/models/Product.php';
require_once BASE_PATH . '/models/Inventory.php';
require_once BASE_PATH . '/models/Location.php';
$productModel = new Product($db);
$inventoryModel = new Inventory($db);
$locationModel = new Location($db);
$allLocations = $locationModel->getAllLocations();

// Handle CRUD operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $productData = [
                'name' => trim($_POST['name'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'sku' => trim($_POST['sku'] ?? ''),
                'price' => floatval($_POST['price'] ?? 0),
                'category' => trim($_POST['category'] ?? ''),
                'unit_of_measure' => trim($_POST['unit_of_measure'] ?? 'pcs'),
                'status' => isset($_POST['status']) ? 'active' : 'inactive',
                'seller_id' => isset($_POST['seller_id']) ? intval($_POST['seller_id']) : null
            ];
            
            if (empty($productData['name']) || empty($productData['sku'])) {
                $message = 'Numele și SKU-ul produsului sunt obligatorii.';
                $messageType = 'error';
            } else {
                $productId = $productModel->createProduct($productData);
                if ($productId) {
                    $message = 'Produsul a fost creat cu succes.';
                    $messageType = 'success';
                    // Explicit activity log to ensure product actions are tracked
                    if (function_exists('logActivity')) {
                        $userId = $_SESSION['user_id'] ?? 0;
                        logActivity(
                            $userId,
                            'create',
                            'product',
                            $productId,
                            'Product created via products.php'
                        );
                    }
                } else {
                    $message = 'Eroare la crearea produsului. Verificați dacă SKU-ul nu există deja.';
                    $messageType = 'error';
                }
            }
            break;
            
            case 'update':
                $productId = intval($_POST['product_id'] ?? 0);
                $productData = [
                    'name' => trim($_POST['name'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'sku' => trim($_POST['sku'] ?? ''),
                    'price' => floatval($_POST['price'] ?? 0),
                    'category' => trim($_POST['category'] ?? ''),
                    'unit_of_measure' => trim($_POST['unit_of_measure'] ?? 'pcs'),
                    'status' => isset($_POST['status']) ? 'active' : 'inactive',
                    'seller_id' => (!empty($_POST['seller_id']) && $_POST['seller_id'] !== '') ? intval($_POST['seller_id']) : null
                ];
                
                if ($productId <= 0 || empty($productData['name']) || empty($productData['sku'])) {
                    $message = 'Date invalide pentru actualizarea produsului.';
                    $messageType = 'error';
                } else {
                    $success = $productModel->updateProduct($productId, $productData);
                    if ($success) {
                        $message = 'Produsul a fost actualizat cu succes.';
                        $messageType = 'success';
                        if (function_exists('logActivity')) {
                            $userId = $_SESSION['user_id'] ?? 0;
                            logActivity(
                                $userId,
                                'update',
                                'product',
                                $productId,
                                'Product updated via products.php'
                            );
                        }
                    } else {
                        $message = 'Eroare la actualizarea produsului.';
                        $messageType = 'error';
                    }
                }
                break;
            
        case 'delete':
            $productId = intval($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                $message = 'ID produs invalid.';
                $messageType = 'error';
            } else {
                $success = $productModel->deleteProduct($productId);
                if ($success) {
                    $message = 'Produsul a fost șters cu succes.';
                    $messageType = 'success';
                    if (function_exists('logActivity')) {
                        $userId = $_SESSION['user_id'] ?? 0;
                        logActivity(
                            $userId,
                            'delete',
                            'product',
                            $productId,
                            'Product deleted via products.php'
                        );
                    }
                } else {
                    $message = 'Eroare la ștergerea produsului.';
                    $messageType = 'error';
                }
            }
            break;

        case 'add_stock':
            $stockData = [
                'product_id' => intval($_POST['product_id'] ?? 0),
                'location_id' => intval($_POST['location_id'] ?? 0),
                'quantity' => intval($_POST['quantity'] ?? 0),
                'batch_number' => trim($_POST['batch_number'] ?? ''),
                'lot_number' => trim($_POST['lot_number'] ?? ''),
                'expiry_date' => $_POST['expiry_date'] ?? null,
                'received_at' => $_POST['received_at'] ?? date('Y-m-d H:i:s'),
                'shelf_level' => $_POST['shelf_level'] ?? null,
                'subdivision_number' => isset($_POST['subdivision_number']) ? intval($_POST['subdivision_number']) : null
            ];

            if (!empty($stockData['received_at']) && strpos($stockData['received_at'], 'T') !== false) {
                $dateTime = DateTime::createFromFormat('Y-m-d\TH:i', $stockData['received_at']);
                if ($dateTime) {
                    $stockData['received_at'] = $dateTime->format('Y-m-d H:i:s');
                }
            }

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

        case 'assign_location':
            $assignData = [
                'product_id' => intval($_POST['product_id'] ?? 0),
                'location_id' => intval($_POST['location_id'] ?? 0),
                'shelf_level' => $_POST['shelf_level'] ?? null,
                'subdivision_number' => isset($_POST['subdivision_number']) ? intval($_POST['subdivision_number']) : null
            ];

            if ($assignData['product_id'] <= 0 || $assignData['location_id'] <= 0) {
                $message = 'Produsul și locația sunt obligatorii.';
                $messageType = 'error';
            } else {
                if ($inventoryModel->assignProductLocation($assignData)) {
                    $message = 'Locația a fost atribuită cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la atribuirea locației.';
                    $messageType = 'error';
                }
            }
            break;

            case 'bulk_action':
                $bulkAction = $_POST['bulk_action'] ?? '';
                $selectedIds = $_POST['selected_products'] ?? [];
                $newCategory = trim($_POST['category'] ?? '');

                // Debug logging
                error_log("Bulk action: $bulkAction");
                error_log("Selected IDs: " . implode(', ', $selectedIds));

                if (empty($selectedIds)) {
                    $message = 'Niciun produs selectat.';
                    $messageType = 'error';
                } elseif ($bulkAction === 'change_category' && empty($newCategory)) {
                    $message = 'Selectați o categorie.';
                    $messageType = 'error';
                } else {
                    $successCount = 0;
                    $errorCount = 0;
                    
                    foreach ($selectedIds as $productId) {
                        $productId = intval($productId);
                        if ($productId <= 0) {
                            error_log("Invalid product ID: $productId");
                            continue;
                        }
                        
                        try {
                            switch ($bulkAction) {
                                case 'delete':
                                    error_log("Attempting to delete product ID: $productId");
                                    if ($productModel->deleteProduct($productId)) {
                                        $successCount++;
                                        error_log("Successfully deleted product ID: $productId");
                                        if (function_exists('logActivity')) {
                                            $userId = $_SESSION['user_id'] ?? 0;
                                            logActivity(
                                                $userId,
                                                'delete',
                                                'product',
                                                $productId,
                                                'Product deleted via bulk action'
                                            );
                                        }
                                    } else {
                                        $errorCount++;
                                        error_log("Failed to delete product ID: $productId");
                                    }
                                    break;
                                    
                                case 'activate':
                                    error_log("Attempting to activate product ID: $productId");
                                    if ($productModel->updateStatus($productId, 'active')) {
                                        $successCount++;
                                        error_log("Successfully activated product ID: $productId");
                                        if (function_exists('logActivity')) {
                                            $userId = $_SESSION['user_id'] ?? 0;
                                            logActivity(
                                                $userId,
                                                'update',
                                                'product',
                                                $productId,
                                                'Product activated via bulk action'
                                            );
                                        }
                                    } else {
                                        $errorCount++;
                                        error_log("Failed to activate product ID: $productId");
                                    }
                                    break;

                                case 'deactivate':
                                    error_log("Attempting to deactivate product ID: $productId");
                                    if ($productModel->updateStatus($productId, 'inactive')) {
                                        $successCount++;
                                        error_log("Successfully deactivated product ID: $productId");
                                        if (function_exists('logActivity')) {
                                            $userId = $_SESSION['user_id'] ?? 0;
                                            logActivity(
                                                $userId,
                                                'update',
                                                'product',
                                                $productId,
                                                'Product deactivated via bulk action'
                                            );
                                        }
                                    } else {
                                        $errorCount++;
                                        error_log("Failed to deactivate product ID: $productId");
                                    }
                                    break;

                                case 'change_category':
                                    error_log("Attempting to change category for product ID: $productId to $newCategory");
                                    if ($productModel->updateCategory($productId, $newCategory)) {
                                        $successCount++;
                                        error_log("Successfully changed category for product ID: $productId");
                                        if (function_exists('logActivity')) {
                                            $userId = $_SESSION['user_id'] ?? 0;
                                            logActivity(
                                                $userId,
                                                'update',
                                                'product',
                                                $productId,
                                                'Category changed via bulk action'
                                            );
                                        }
                                    } else {
                                        $errorCount++;
                                        error_log("Failed to change category for product ID: $productId");
                                    }
                                    break;

                                default:
                                    $errorCount++;
                                    error_log("Unknown bulk action: $bulkAction");
                                    break;
                            }
                        } catch (Exception $e) {
                            $errorCount++;
                            error_log("Exception in bulk operation: " . $e->getMessage());
                        }
                    }
                    
                    // Log final results
                    error_log("Bulk operation completed - Success: $successCount, Errors: $errorCount");
                    
                    if ($successCount > 0) {
                        $message = "Operațiune completă: {$successCount} produse procesate cu succes";
                        if ($errorCount > 0) {
                            $message .= ", {$errorCount} erori.";
                        } else {
                            $message .= ".";
                        }
                        $messageType = $errorCount > 0 ? 'warning' : 'success';
                    } else {
                        $message = 'Nicio operațiune a fost finalizată cu succes.';
                        $messageType = 'error';
                    }
                }
                break;
    }
}

// Pagination and filtering
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 25; // Compact pagination
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$sellerId = intval($_GET['seller_id'] ?? 0);

// Get total count - THIS LINE WILL NOW WORK
$totalCount = $productModel->getTotalCountWithSellers($search, $category, $sellerId);
$totalPages = max(1, ceil($totalCount / $pageSize));
$offset = ($page - 1) * $pageSize;

// Get products with pagination
$allProducts = $productModel->getProductsPaginatedWithSellers($pageSize, $offset, $search, $category, $sellerId);

// Get unique categories for filter
$categories = $productModel->getCategories();
$category = trim($_GET['category'] ?? '');
$sellerId = intval($_GET['seller_id'] ?? 0);
// Define current page for footer
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Gestionare Produse - WMS</title>
</head>
<body>
    <div class="app">
        <?php require_once __DIR__ . '/includes/navbar.php'; ?>
        
        <div class="main-content">
            <div class="page-container">
                <!-- Compact Page Header -->
                <div class="page-header">
                    <div class="header-left">
                        <h1 class="page-title">
                            <span class="material-symbols-outlined">inventory_2</span>
                            Produse
                        </h1>
                        <div class="page-stats">
                            <span class="stat-badge">
                                <strong><?= number_format($totalCount) ?></strong> produse totale
                            </span>
                        </div>
                    </div>
                    <div class="header-actions">
                        <button type="button" class="btn btn-success" onclick="openImportModal()">
                            <span class="material-symbols-outlined">cloud_upload</span>
                            Import Excel
                        </button>
                        <!-- <button class="btn btn-secondary" onclick="syncSmartBillStock()">
                            <span class="material-symbols-outlined">sync</span>
                            Sincronizează Stoc
                        </button> -->
                        <button class="btn btn-primary" onclick="openCreateModal()">
                            <span class="material-symbols-outlined">add</span>
                            Nou Produs
                        </button>
                    </div>
                </div>
                
                <!-- Compact Search and Filters -->
                <div class="filters-bar">
                <form method="GET" class="filters-form" id="filters-form">
                        <input type="hidden" name="page" value="1">
                        <div class="filters-row">
                            <div class="filter-group">
                                <input type="text" name="search" placeholder="Caută după nume, SKU sau furnizor..." 
                                    value="<?= htmlspecialchars($search) ?>" class="form-control">
                            </div>
                            <div class="filter-group">
                                <select name="category" class="form-control">
                                    <option value="">Toate categoriile</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <select name="seller_id" class="form-control">
                                    <option value="">Toți furnizorii</option>
                                    <?php foreach ($allSellers as $seller): ?>
                                        <option value="<?= $seller['id'] ?>" <?= $sellerId == $seller['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($seller['supplier_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <span class="material-symbols-outlined">search</span>
                                    Caută
                                </button>
                                <a href="?" class="btn btn-secondary">Șterge filtrele</a>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Alert Messages -->
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'danger') ?>">
                        <span class="material-symbols-outlined">
                            <?= $messageType === 'success' ? 'check_circle' : ($messageType === 'warning' ? 'warning' : 'error') ?>
                        </span>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <!-- Bulk Actions Bar -->
                <div class="bulk-actions-bar" id="bulkActionsBar" style="display: none;">
                    <div class="bulk-actions-content">
                        <span class="bulk-selection-count">
                            <span id="selectedCount">0</span> produse selectate
                        </span>
                        <div class="bulk-actions">
                            <button type="button" class="btn btn-sm btn-success" onclick="performBulkAction('activate')">
                                <span class="material-symbols-outlined">check_circle</span>
                                Activează
                            </button>
                            <button type="button" class="btn btn-sm btn-warning" onclick="performBulkAction('deactivate')">
                                <span class="material-symbols-outlined">block</span>
                                Dezactivează
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="performBulkAction('delete')">
                                <span class="material-symbols-outlined">delete</span>
                                Șterge
                            </button>
                            <button type="button" class="btn btn-sm btn-info" onclick="showCategoryBulk()">
                                <span class="material-symbols-outlined">category</span>
                                Schimbă categorie
                            </button>
                            <select id="bulkCategorySelect" class="form-control" style="display:none; margin-left:10px;">
                                <option value="">Selectează categorie</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="applyCategoryBtn" class="btn btn-sm btn-primary" style="display:none; margin-left:5px;" onclick="applyBulkCategory()">
                                Aplică
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Compact Products Table -->
                <div class="table-container">
                    <?php if (!empty($allProducts)): ?>
                        <form id="bulkForm" method="POST" action="">
                            <input type="hidden" name="action" value="bulk_action">
                            <input type="hidden" name="bulk_action" id="bulkActionInput">
                            <input type="hidden" name="category" id="bulkCategoryInput">
                            
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th width="30">
                                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                        </th>
                                        <th>SKU</th>
                                        <th>Nume Produs</th>
                                        <th>Categorie</th>
                                        <th>Furnizor</th>
                                        <th>U.M.</th>
                                        <th>Locație</th>
                                        <th>Stoc</th>
                                        <th>Status</th>
                                        <th width="100">Acțiuni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($allProducts)): ?>
                                        <?php foreach ($allProducts as $product): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" name="selected_products[]" 
                                                        value="<?= $product['product_id'] ?>" class="product-checkbox">
                                                </td>
                                                <td>
                                                    <span class="sku-badge"><?= htmlspecialchars($product['sku']) ?></span>
                                                </td>
                                                <td>
                                                    <div class="product-info">
                                                        <strong><?= htmlspecialchars($product['name']) ?></strong>
                                                        <?php if (!empty($product['description'])): ?>
                                                            <small class="product-description">
                                                                <?= htmlspecialchars(substr($product['description'], 0, 60)) ?>
                                                                <?= strlen($product['description']) > 60 ? '...' : '' ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($product['category'])): ?>
                                                        <span class="category-badge"><?= htmlspecialchars($product['category']) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($product['seller_name'])): ?>
                                                        <div class="seller-info">
                                                            <strong class="seller-name"><?= htmlspecialchars($product['seller_name']) ?></strong>
                                                            <?php if (!empty($product['seller_contact'])): ?>
                                                                <small class="seller-contact">
                                                                    <?= htmlspecialchars($product['seller_contact']) ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted no-seller">Fără furnizor</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($product['unit_of_measure'])): ?>
                                                        <span class="unit-badge"><?= htmlspecialchars($product['unit_of_measure']) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($product['location_details'])): ?>
                                                        <span class="location-info"><?= htmlspecialchars($product['location_details']) ?></span>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="assignLocationForProduct(<?= $product['product_id'] ?>)">
                                                            Atribuie Locatie
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="quantity-info">
                                                        <span class="quantity-value"><?= number_format($product['quantity']) ?></span>
                                                        <?php if ($product['quantity'] <= ($product['min_stock_level'] ?? 5)): ?>
                                                            <span class="low-stock-indicator" title="Stoc scăzut">
                                                                <span class="material-symbols-outlined">warning</span>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?= $product['status'] ?? 'active' ?>">
                                                        <?= ($product['status'] ?? 'active') === 'active' ? 'Activ' : 'Inactiv' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button type="button" class="btn btn-sm btn-primary" 
                                                                onclick="openEditModal(<?= htmlspecialchars(json_encode($product)) ?>)"
                                                                title="Editează produs">
                                                            <span class="material-symbols-outlined">edit</span>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-danger" 
                                                                onclick="confirmDelete(<?= $product['product_id'] ?>, '<?= htmlspecialchars($product['name']) ?>')"
                                                                title="Șterge produs">
                                                            <span class="material-symbols-outlined">delete</span>
                                                        </button>
                                                        
                                                        <!-- SmartBill sync button if product has no seller -->
                                                        <!-- <?php if (empty($product['seller_id'])): ?>
                                                            <button type="button" class="btn btn-sm btn-info" 
                                                                    onclick="suggestSeller(<?= $product['product_id'] ?>)"
                                                                    title="Sugerează furnizor">
                                                                <span class="material-symbols-outlined">auto_fix_high</span>
                                                            </button>
                                                        <?php endif; ?> -->
                                                        
                                                        <!-- Existing label printing and other actions -->
                                                        <button type="button" class="btn btn-sm btn-secondary" 
                                                                onclick="printLabels(<?= $product['product_id'] ?>, '<?= htmlspecialchars($product['name']) ?>')"
                                                                title="Printează etichete">
                                                            <span class="material-symbols-outlined">local_printshop</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center">
                                                <div class="empty-state">
                                                    <span class="material-symbols-outlined">inventory_2</span>
                                                    <h3>Nu există produse</h3>
                                                    <p>
                                                        <?php if (!empty($search) || !empty($category) || !empty($sellerId)): ?>
                                                            Nu s-au găsit produse cu criteriile selectate.
                                                            <a href="?" class="btn btn-secondary">Șterge filtrele</a>
                                                        <?php else: ?>
                                                            Adaugă primul produs folosind butonul "Nou Produs".
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </form>
                        
                        <!-- Compact Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination-info">
                                    Afișare <?= ($offset + 1) ?>-<?= min($offset + $pageSize, $totalCount) ?> din <?= number_format($totalCount) ?> produse
                                </div>
                                <div class="pagination-controls">
                                    <?php if ($page > 1): ?>
                                        <a href="?page=1&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>" class="pagination-btn">Prima</a>
                                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>&seller_id=<?= $sellerId ?>" class="pagination-btn">‹</a>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($totalPages, $page + 2);
                                    
                                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <?php if ($i == $page): ?>
                                            <span class="pagination-btn active"><?= $i ?></span>
                                        <?php else: ?>
                                            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>" class="pagination-btn"><?= $i ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $totalPages): ?>
                                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>" class="pagination-btn">›</a>
                                        <a href="?page=<?= $totalPages ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>" class="pagination-btn">Ultima</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="empty-state">
                            <span class="material-symbols-outlined">inventory_2</span>
                            <h3>Nu există produse</h3>
                            <p>
                                <?php if ($search || $category): ?>
                                    Nu s-au găsit produse cu criteriile selectate.
                                    <a href="?" class="btn btn-secondary">Șterge filtrele</a>
                                <?php else: ?>
                                    Adaugă primul produs folosind butonul "Nou Produs".
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Import Products Modal -->
<div class="modal" id="importProductModal" style="display: none;">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h3><span class="material-symbols-outlined">cloud_upload</span> Import Produse din Excel</h3>
                <button class="modal-close" onclick="closeImportModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- File Upload Section -->
                <div id="step-upload" class="import-step">
                    <div class="upload-section">
                        <div class="upload-area" id="uploadArea">
                            <div class="upload-content">
                                <span class="material-symbols-outlined upload-icon">cloud_upload</span>
                                <h4>Încarcă fișierul Excel</h4>
                                <p>Selectează sau trage fișierul .xls sau .xlsx cu produsele</p>
                                <input type="file" id="excelFile" accept=".xls,.xlsx" style="display: none;">
                                <button type="button" class="btn btn-primary" onclick="document.getElementById('excelFile').click()">
                                    <span class="material-symbols-outlined">folder_open</span>
                                    Selectează Fișier
                                </button>
                                <div class="upload-help">
                                    <small>
                                        <a href="#" onclick="downloadSampleTemplate()" class="link-primary">
                                            <span class="material-symbols-outlined">download</span>
                                            Descarcă template exemplu
                                        </a>
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- File Information -->
                        <div id="fileInfo" class="file-info" style="display: none;">
                            <div class="file-details">
                                <span class="material-symbols-outlined">description</span>
                                <div class="file-meta">
                                    <strong id="fileName">-</strong>
                                    <span id="fileSize" class="text-muted">-</span>
                                </div>
                                <button type="button" class="btn-remove" onclick="resetImportForm()">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Import Options -->
                    <div class="import-options">
                        <h5><span class="material-symbols-outlined">settings</span> Opțiuni Import</h5>
                        
                        <div class="option-group">
                            <label class="checkbox-option">
                                <input type="checkbox" id="overwriteExisting" checked>
                                <span class="checkmark"></span>
                                <div class="option-text">
                                    <strong>Actualizează produsele existente</strong>
                                    <small>Suprascrie datele produselor care au același SKU</small>
                                </div>
                            </label>
                        </div>
                        
                        <div class="option-group">
                            <label class="checkbox-option">
                                <input type="checkbox" id="syncSmartBill">
                                <span class="checkmark"></span>
                                <div class="option-text">
                                    <strong>Sincronizează cu SmartBill</strong>
                                    <small>Caută și actualizează produsele din SmartBill după import</small>
                                </div>
                            </label>
                        </div>
                        
                        <div class="format-help">
                            <h6><span class="material-symbols-outlined">info</span> Format acceptat</h6>
                            <p>Fișierul Excel trebuie să conțină următoarele coloane:</p>
                            <div class="columns-list">
                                <span class="column required">SKU/Cod</span>
                                <span class="column required">Nume Produs</span>
                                <span class="column optional">Descriere</span>
                                <span class="column optional">Categorie</span>
                                <span class="column optional">Cantitate</span>
                                <span class="column optional">Preț</span>
                                <span class="column optional">Stoc Minim</span>
                                <span class="column optional">Unitate Măsură</span>
                            </div>
                            <small class="text-muted">
                                <strong>Obligatoriu:</strong> roșu | <strong>Opțional:</strong> albastru
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Progress Section -->
                <div id="progressContainer" class="progress-container" style="display: none;">
                    <div class="progress-header">
                        <h5><span class="material-symbols-outlined">hourglass_empty</span> Procesez fișierul...</h5>
                        <p>Vă rog să așteptați. Nu închideți această fereastră.</p>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar" id="progressBar" style="width: 0%"></div>
                    </div>
                </div>
                
                <!-- Results Section -->
                <div id="importResults" class="import-results" style="display: none;">
                    <!-- Results will be inserted here by JavaScript -->
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeImportModal()">
                    <span class="material-symbols-outlined">close</span>
                    Închide
                </button>
                <button type="button" class="btn btn-primary" id="processBtn" disabled onclick="startProcessing()">
                    <span class="material-symbols-outlined">play_arrow</span>
                    Selectează un fișier
                </button>
            </div>
        </div>
    </div>
</div>

    <!-- Modals (Create, Edit, Delete) -->
    <!-- The HTML for your modals is unchanged. -->
    <!-- Create Product Modal -->
    <div class="modal" id="createProductModal" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Adaugă Produs Nou</h3>
                <button class="modal-close" onclick="closeCreateModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="create-name">Nume Produs *</label>
                            <input type="text" id="create-name" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="create-sku">SKU *</label>
                            <input type="text" id="create-sku" name="sku" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="create-description">Descriere</label>
                        <textarea id="create-description" name="description" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="create-price">Preț (RON)</label>
                            <input type="number" id="create-price" name="price" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="form-group">
                            <label for="create-category">Categorie</label>
                            <input type="text" id="create-category" name="category" class="form-control">
                        </div>
                    </div>
                    
                    <!-- NEW SELLER SELECTION FIELD -->
                    <div class="form-group">
                        <label for="create-seller">Furnizor</label>
                        <div class="seller-search-container">
                            <input type="hidden" id="create-seller-id" name="seller_id" value="">
                            <input type="text" id="create-seller-search" class="form-control seller-search-input" 
                                   placeholder="Caută furnizor după nume sau contact..." autocomplete="off">
                            <div class="seller-search-results" id="create-seller-results">
                                
                            </div>
                            <div class="selected-seller" id="create-selected-seller" style="display: none;">
                                <div class="selected-seller-info">
                                    <span class="selected-seller-name"></span>
                                    <span class="selected-seller-contact"></span>
                                </div>
                                <button type="button" class="remove-seller-btn" onclick="clearSelectedSeller('create')">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="create-unit">Unitate</label>
                            <select id="create-unit" name="unit_of_measure" class="form-control">
                                <option value="pcs">Bucată</option>
                                <option value="kg">Kilogram</option>
                                <option value="l">Litru</option>
                                <option value="m">Metru</option>
                                <option value="set">Set</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="create-status" name="status" checked>
                                <span class="checkmark"></span>
                                Activ
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Anulează</button>
                    <button type="submit" class="btn btn-primary">Salvează Produs</button>
                </div>
            </form>
        </div>
    </div>
</div>


    <!-- Edit Product Modal -->
    <div class="modal" id="editProductModal" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Editează Produs</h3>
                <button class="modal-close" onclick="closeEditModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" id="edit-product-id" name="product_id" value="">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit-name">Nume Produs *</label>
                            <input type="text" id="edit-name" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit-sku">SKU *</label>
                            <input type="text" id="edit-sku" name="sku" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit-description">Descriere</label>
                        <textarea id="edit-description" name="description" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit-price">Preț (RON)</label>
                            <input type="number" id="edit-price" name="price" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="form-group">
                            <label for="edit-category">Categorie</label>
                            <input type="text" id="edit-category" name="category" class="form-control">
                        </div>
                    </div>
                    
                    <!-- NEW SELLER SELECTION FIELD FOR EDIT -->
                    <div class="form-group">
                        <label for="edit-seller">Furnizor</label>
                        <div class="seller-search-container">
                            <input type="hidden" id="edit-seller-id" name="seller_id" value="">
                            <input type="text" id="edit-seller-search" class="form-control seller-search-input" 
                                   placeholder="Caută furnizor după nume sau contact..." autocomplete="off">
                            <div class="seller-search-results" id="edit-seller-results"></div>
                            <div class="selected-seller" id="edit-selected-seller" style="display: none;">
                                <div class="selected-seller-info">
                                    <span class="selected-seller-name"></span>
                                    <span class="selected-seller-contact"></span>
                                </div>
                                <button type="button" class="remove-seller-btn" onclick="clearSelectedSeller('edit')">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit-unit">Unitate</label>
                            <select id="edit-unit" name="unit_of_measure" class="form-control">
                                <option value="pcs">Bucată</option>
                                <option value="kg">Kilogram</option>
                                <option value="l">Litru</option>
                                <option value="m">Metru</option>
                                <option value="set">Set</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="edit-status" name="status">
                                <span class="checkmark"></span>
                                Activ
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Anulează</button>
                    <button type="submit" class="btn btn-primary">Actualizează Produs</button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- Assign Location Modal -->
    <div class="modal" id="assignLocationModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Atribuie Locație</h3>
                    <button class="modal-close" onclick="closeAssignLocationModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="assign_location">
                        <input type="hidden" id="assign-product-id" name="product_id">

                        <div class="form-group">
                            <label for="assign-location" class="form-label">Locație *</label>
                            <select id="assign-location" name="location_id" class="form-control" required onchange="loadAssignLocationLevels(this.value)">
                                <option value="">Selectează locația</option>
                                <?php foreach ($allLocations as $location): ?>
                                    <option value="<?= $location['id'] ?>">
                                        <?= htmlspecialchars($location['location_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="assign-shelf-level" class="form-label">Nivel raft</label>
                            <select id="assign-shelf-level" name="shelf_level" class="form-control" onchange="updateAssignSubdivisionOptions()">
                                <option value="">--</option>
                            </select>
                        </div>

                        <div class="form-group" id="assign-subdivision-container" style="display:none;">
                            <label for="assign-subdivision-number" class="form-label">Subdiviziune</label>
                            <select id="assign-subdivision-number" name="subdivision_number" class="form-control">
                                <option value="">--</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeAssignLocationModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Atribuie</button>
                    </div>
                </form>
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
                            <label class="form-label">Produs *</label>
                            <div class="product-search-container">
                                <input type="hidden" id="add-product" name="product_id">
                                <input type="text" id="add-product-search" class="form-control product-search-input" placeholder="Caută produs..." autocomplete="off"
                                       onkeyup="searchProducts(this.value)" onfocus="showProductResults()">
                                <div class="product-search-results" id="add-product-results"></div>
                            </div>
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
                            <select id="shelf_level" name="shelf_level" class="form-control" onchange="updateSubdivisionOptions()">
                                <option value="">--</option>
                            </select>
                        </div>
                        <div class="form-group" id="subdivision-container" style="display:none;">
                            <label for="subdivision_number" class="form-label">Subdiviziune</label>
                            <select id="subdivision_number" name="subdivision_number" class="form-control"></select>
                        </div>

                        <div class="row">
                            <div class="form-group">
                                <label for="add-quantity" class="form-label">Cantitate <span id="total-articles" style="font-weight:normal;color:var(--text-secondary);"></span> *</label>
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

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteProductModal" style="display: none;">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Confirmă Ștergerea</h3>
                    <button class="modal-close" onclick="closeDeleteModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Ești sigur că vrei să ștergi produsul <strong id="delete-product-name"></strong>?</p>
                    <p class="warning-text">Această acțiune nu poate fi anulată.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Anulează</button>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" id="delete-product-id" name="product_id">
                        <button type="submit" class="btn btn-danger">Șterge</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>