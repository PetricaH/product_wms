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

// Include Product model
require_once BASE_PATH . '/models/Product.php';
$productModel = new Product($db);

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
                'unit' => trim($_POST['unit'] ?? 'pcs'),
                'status' => isset($_POST['status']) ? 1 : 0
            ];
            
            if (empty($productData['name']) || empty($productData['sku'])) {
                $message = 'Numele și SKU-ul produsului sunt obligatorii.';
                $messageType = 'error';
            } else {
                $productId = $productModel->createProduct($productData);
                if ($productId) {
                    $message = 'Produsul a fost creat cu succes.';
                    $messageType = 'success';
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
                'unit' => trim($_POST['unit'] ?? 'pcs'),
                'status' => isset($_POST['status']) ? 1 : 0
            ];
            
            if ($productId <= 0 || empty($productData['name']) || empty($productData['sku'])) {
                $message = 'Date invalide pentru actualizarea produsului.';
                $messageType = 'error';
            } else {
                $success = $productModel->updateProduct($productId, $productData);
                if ($success) {
                    $message = 'Produsul a fost actualizat cu succes.';
                    $messageType = 'success';
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
                } else {
                    $message = 'Eroare la ștergerea produsului.';
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

// Get total count - THIS LINE WILL NOW WORK
$totalCount = $productModel->getTotalCount($search, $category);
$totalPages = max(1, ceil($totalCount / $pageSize));
$offset = ($page - 1) * $pageSize;

// Get products with pagination
$allProducts = $productModel->getProductsPaginated($pageSize, $offset, $search, $category);

// Get unique categories for filter
$categories = $productModel->getCategories();

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
                    <button class="btn btn-primary" onclick="openCreateModal()">
                        <span class="material-symbols-outlined">add</span>
                        Nou Produs
                    </button>
                </div>
                
                <!-- Compact Search and Filters -->
                <div class="filters-bar">
                    <form method="GET" class="filters-form" id="filters-form">
                        <div class="search-group">
                            <div class="search-box">
                                <span class="material-symbols-outlined">search</span>
                                <input type="search" name="search" value="<?= htmlspecialchars($search) ?>" 
                                       placeholder="Caută produse..." class="search-input">
                                <?php if ($search): ?>
                                    <a href="?" class="clear-search" title="Șterge căutarea">
                                        <span class="material-symbols-outlined">close</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <select name="category" class="filter-select" onchange="this.form.submit()">
                            <option value="">Toate categoriile</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <input type="hidden" name="page" value="1">
                    </form>
                </div>
                
                <!-- Alert Messages -->
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>">
                        <span class="material-symbols-outlined">
                            <?= $messageType === 'success' ? 'check_circle' : 'error' ?>
                        </span>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>
                
                <!-- Compact Products Table -->
                <div class="table-container">
                    <?php if (!empty($allProducts)): ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Nume Produs</th>
                                    <th>Categorie</th>
                                    <th>Preț</th>
                                    <th>Stoc</th>
                                    <th>Status</th>
                                    <th width="100">Acțiuni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allProducts as $product): ?>
                                    <tr>
                                        <td>
                                            <code class="sku-code"><?= htmlspecialchars($product['sku'] ?? 'N/A') ?></code>
                                        </td>
                                        <td>
                                            <div class="product-info">
                                                <strong><?= htmlspecialchars($product['name'] ?? 'Produs fără nume') ?></strong>
                                                <?php if (!empty($product['description'])): ?>
                                                    <small><?= htmlspecialchars(substr($product['description'], 0, 60)) ?><?= strlen($product['description'] ?? '') > 60 ? '...' : '' ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($product['category'])): ?>
                                                <span class="category-tag"><?= htmlspecialchars($product['category']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= number_format($product['price'] ?? 0, 2) ?> RON</strong>
                                        </td>
                                        <td>
                                            <span class="stock-info">
                                                <?= number_format($product['quantity'] ?? 0) ?> 
                                                <small><?= htmlspecialchars($product['unit'] ?? 'pcs') ?></small>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= ($product['status'] ?? 1) == 1 ? 'active' : 'inactive' ?>">
                                                <?= ($product['status'] ?? 1) == 1 ? 'Activ' : 'Inactiv' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="actions-group">
                                                <button class="action-btn edit-btn" 
                                                        onclick="openEditModal(<?= htmlspecialchars(json_encode($product)) ?>)"
                                                        title="Editează">
                                                    <span class="material-symbols-outlined">edit</span>
                                                </button>
                                                <button class="action-btn delete-btn" 
                                                        onclick="confirmDelete(<?= $product['id'] ?? 0 ?>, '<?= htmlspecialchars(addslashes($product['name'] ?? 'Produs')) ?>')"
                                                        title="Șterge">
                                                    <span class="material-symbols-outlined">delete</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- Compact Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination-info">
                                    Afișare <?= ($offset + 1) ?>-<?= min($offset + $pageSize, $totalCount) ?> din <?= number_format($totalCount) ?> produse
                                </div>
                                <div class="pagination-controls">
                                    <?php if ($page > 1): ?>
                                        <a href="?page=1&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>" class="pagination-btn">Prima</a>
                                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>" class="pagination-btn">‹</a>
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
                        <div class="form-row">
                            <div class="form-group">
                                <label for="create-unit">Unitate</label>
                                <select id="create-unit" name="unit" class="form-control">
                                    <option value="pcs">Bucăți</option>
                                    <option value="kg">Kilograme</option>
                                    <option value="l">Litri</option>
                                    <option value="m">Metri</option>
                                    <option value="set">Set</option>
                                </select>
                            </div>
                            <div class="form-group form-check-group">
                                <label class="form-check">
                                    <input type="checkbox" id="create-status" name="status" checked>
                                    <span class="checkmark"></span>
                                    Produs activ
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Creează Produs</button>
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
                        <input type="hidden" id="edit-product-id" name="product_id">
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
                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit-unit">Unitate</label>
                                <select id="edit-unit" name="unit" class="form-control">
                                    <option value="pcs">Bucăți</option>
                                    <option value="kg">Kilograme</option>
                                    <option value="l">Litri</option>
                                    <option value="m">Metri</option>
                                    <option value="set">Set</option>
                                </select>
                            </div>
                            <div class="form-group form-check-group">
                                <label class="form-check">
                                    <input type="checkbox" id="edit-status" name="status">
                                    <span class="checkmark"></span>
                                    Produs activ
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Actualizează</button>
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
