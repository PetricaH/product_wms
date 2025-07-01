<?php
// File: products.php - Products Management Interface (Adapted for existing schema)
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
                'sku' => trim($_POST['sku'] ?? ''),
                'name' => trim($_POST['name'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'category' => trim($_POST['category'] ?? ''),
                'quantity' => !empty($_POST['quantity']) ? intval($_POST['quantity']) : 0,
                'min_stock_level' => !empty($_POST['min_stock_level']) ? intval($_POST['min_stock_level']) : 0,
                'price' => !empty($_POST['price']) ? floatval($_POST['price']) : 0.00
            ];
            
            // Generate SKU if not provided
            if (empty($productData['sku'])) {
                $productData['sku'] = $productModel->generateSku();
            }
            
            if (empty($productData['name'])) {
                $message = 'Numele produsului este obligatoriu.';
                $messageType = 'error';
            } else {
                $productId = $productModel->create($productData);
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
                'sku' => trim($_POST['sku'] ?? ''),
                'name' => trim($_POST['name'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'category' => trim($_POST['category'] ?? ''),
                'quantity' => !empty($_POST['quantity']) ? intval($_POST['quantity']) : 0,
                'min_stock_level' => !empty($_POST['min_stock_level']) ? intval($_POST['min_stock_level']) : 0,
                'price' => !empty($_POST['price']) ? floatval($_POST['price']) : 0.00
            ];
            
            if ($productId <= 0 || empty($productData['name'])) {
                $message = 'Date invalide pentru actualizarea produsului.';
                $messageType = 'error';
            } else {
                $success = $productModel->update($productId, $productData);
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
                $success = $productModel->delete($productId);
                if ($success) {
                    $message = 'Produsul a fost dezactivat cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la dezactivarea produsului.';
                    $messageType = 'error';
                }
            }
            break;
    }
}

// Get search and filter parameters
$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$lowStockFilter = isset($_GET['low_stock']);

// Build filters array
$filters = [];
if (!empty($search)) {
    $filters['search'] = $search;
}
if (!empty($categoryFilter)) {
    $filters['category'] = $categoryFilter;
}
if ($lowStockFilter) {
    $filters['low_stock'] = true;
}

// Get products with inventory data
$products = $productModel->getProductsWithInventory();

// Filter products based on search criteria
if (!empty($filters)) {
    $products = array_filter($products, function($product) use ($filters) {
        // Search filter
        if (!empty($filters['search'])) {
            $searchTerm = strtolower($filters['search']);
            $searchable = strtolower($product['name'] . ' ' . $product['sku'] . ' ' . $product['description']);
            if (strpos($searchable, $searchTerm) === false) {
                return false;
            }
        }
        
        // Category filter
        if (!empty($filters['category']) && $product['category'] !== $filters['category']) {
            return false;
        }
        
        // Low stock filter
        if (!empty($filters['low_stock'])) {
            if ($product['current_stock'] > $product['min_stock_level']) {
                return false;
            }
        }
        
        return true;
    });
}

// Get categories for dropdown
$categories = $productModel->getCategories();

// Define current page for footer
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Gestionare Produse - WMS</title>
</head>
<body class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <main class="main-content">
        <div class="products-container">
            <div class="page-header">
                <h1 class="page-title">Gestionare Produse</h1>
                <div class="header-actions">
                    <button class="btn btn-secondary" onclick="toggleFilters()">
                        <span class="material-symbols-outlined">filter_list</span>
                        Filtre
                    </button>
                    <button class="btn btn-primary" onclick="openCreateModal()">
                        <span class="material-symbols-outlined">add</span>
                        Adaugă Produs
                    </button>
                </div>
            </div>
            
            <!-- Filters Section -->
            <div id="filtersSection" class="filters-section" style="display: none;">
                <form method="GET" class="filters-form">
                    <div class="filter-group">
                        <label for="search" class="filter-label">Căutare:</label>
                        <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" 
                               placeholder="Nume, SKU sau descriere..." class="form-control">
                    </div>
                    
                    <div class="filter-group">
                        <label for="category" class="filter-label">Categorie:</label>
                        <select id="category" name="category" class="form-control">
                            <option value="">Toate categoriile</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= htmlspecialchars($category['name']) ?>" 
                                        <?= $categoryFilter == $category['name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">
                            <input type="checkbox" name="low_stock" <?= $lowStockFilter ? 'checked' : '' ?>>
                            Doar produse cu stoc redus
                        </label>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">Aplică Filtre</button>
                        <a href="products.php" class="btn btn-secondary">Resetează</a>
                    </div>
                </form>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="message <?= $messageType ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($products)): ?>
                <div class="products-grid">
                    <?php foreach ($products as $product): ?>
                        <div class="product-card">
                            <div class="product-image">
                                <div class="product-image-placeholder">
                                    <span class="material-symbols-outlined">inventory</span>
                                </div>
                            </div>
                            
                            <div class="product-info">
                                <div class="product-header">
                                    <h3 class="product-name"><?= htmlspecialchars($product['name']) ?></h3>
                                    <span class="product-sku"><?= htmlspecialchars($product['sku']) ?></span>
                                </div>
                                
                                <?php if (!empty($product['description'])): ?>
                                    <p class="product-description"><?= htmlspecialchars(substr($product['description'], 0, 100)) ?>...</p>
                                <?php endif; ?>
                                
                                <div class="product-details">
                                    <div class="product-price">
                                        <span class="price-label">Preț:</span>
                                        <span class="price-value"><?= number_format($product['price'], 2) ?> RON</span>
                                    </div>
                                    
                                    <?php if (!empty($product['category'])): ?>
                                        <div class="product-category">
                                            <span class="category-label">Categorie:</span>
                                            <span class="category-value"><?= htmlspecialchars($product['category']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="product-stock">
                                        <span class="stock-label">Stoc actual:</span>
                                        <span class="stock-value <?= $product['quantity'] <= $product['min_stock_level'] ? 'low-stock' : '' ?>">
                                            <?= number_format($product['quantity']) ?>
                                        </span>
                                    </div>
                                    
                                    <div class="product-min-stock">
                                        <span class="min-stock-label">Stoc minim:</span>
                                        <span class="min-stock-value"><?= number_format($product['min_stock_level']) ?></span>
                                    </div>
                                    
                                    <?php if ($product['locations_count'] > 0): ?>
                                        <div class="product-locations">
                                            <span class="locations-label">Locații:</span>
                                            <span class="locations-value"><?= number_format($product['locations_count']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="product-status">
                                    <?php if ($product['quantity'] <= $product['min_stock_level'] && $product['min_stock_level'] > 0): ?>
                                        <span class="status-badge status-warning">
                                            <span class="material-symbols-outlined">warning</span>
                                            Stoc Redus
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-active">
                                            <span class="material-symbols-outlined">check_circle</span>
                                            În Stoc
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="product-actions">
                                    <button class="btn btn-secondary" onclick="openEditModal(<?= htmlspecialchars(json_encode($product)) ?>)">
                                        <span class="material-symbols-outlined">edit</span>
                                        Editează
                                    </button>
                                    <button class="btn btn-danger" onclick="confirmDelete(<?= $product['product_id'] ?>, '<?= htmlspecialchars($product['name']) ?>')">
                                        <span class="material-symbols-outlined">remove_circle</span>
                                        Dezactivează
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-outlined">inventory_2</span>
                    <h3>Nu există produse</h3>
                    <p>Adăugați primul produs folosind butonul de mai sus.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Create/Edit Product Modal -->
    <div id="productModal" class="modal">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Adaugă Produs</h2>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <form id="productForm" method="POST">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="product_id" id="productId" value="">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="sku" class="form-label">SKU</label>
                        <input type="text" class="form-control" id="sku" name="sku" 
                               placeholder="Generat automat dacă este gol">
                        <small class="form-help">Lăsați gol pentru generare automată</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="name" class="form-label">Nume Produs *</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="description" class="form-label">Descriere</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="category" class="form-label">Categorie</label>
                        <input type="text" class="form-control" id="category" name="category" 
                               placeholder="ex: Electronics, Office Supplies">
                    </div>
                    
                    <div class="form-group">
                        <label for="price" class="form-label">Preț (RON)</label>
                        <input type="number" class="form-control" id="price" name="price" 
                               step="0.01" min="0" value="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label for="quantity" class="form-label">Cantitate Inițială</label>
                        <input type="number" class="form-control" id="quantity" name="quantity" 
                               min="0" value="0">
                        <small class="form-help">Cantitatea de bază (pentru referință)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="min_stock_level" class="form-label">Nivel Minim Stoc</label>
                        <input type="number" class="form-control" id="min_stock_level" name="min_stock_level" 
                               min="0" value="0">
                        <small class="form-help">Alertă când stocul scade sub această valoare</small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Anulează</button>
                    <button type="submit" class="btn btn-success" id="submitBtn">Salvează</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Confirmare Dezactivare</h2>
                <button class="close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <p>Sunteți sigur că doriți să dezactivați produsul <strong id="deleteProductName"></strong>?</p>
            <p style="color: #ffc107; font-weight: 500;">Această acțiune va seta cantitatea produsului la 0.</p>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Anulează</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="product_id" id="deleteProductId" value="">
                    <button type="submit" class="btn btn-danger">Dezactivează</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function toggleFilters() {
            const filtersSection = document.getElementById('filtersSection');
            filtersSection.style.display = filtersSection.style.display === 'none' ? 'block' : 'none';
        }

        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Adaugă Produs';
            document.getElementById('formAction').value = 'create';
            document.getElementById('productId').value = '';
            document.getElementById('productForm').reset();
            document.getElementById('submitBtn').textContent = 'Adaugă';
            document.getElementById('productModal').style.display = 'block';
        }

        function openEditModal(product) {
            document.getElementById('modalTitle').textContent = 'Editează Produs';
            document.getElementById('formAction').value = 'update';
            document.getElementById('productId').value = product.product_id;
            
            // Populate form fields (adapted to your schema)
            document.getElementById('sku').value = product.sku || '';
            document.getElementById('name').value = product.name || '';
            document.getElementById('description').value = product.description || '';
            document.getElementById('category').value = product.category || '';
            document.getElementById('price').value = product.price || '0.00';
            document.getElementById('quantity').value = product.quantity || '0';
            document.getElementById('min_stock_level').value = product.min_stock_level || '0';
            
            document.getElementById('submitBtn').textContent = 'Actualizează';
            document.getElementById('productModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('productModal').style.display = 'none';
        }

        function confirmDelete(productId, productName) {
            document.getElementById('deleteProductId').value = productId;
            document.getElementById('deleteProductName').textContent = productName;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const productModal = document.getElementById('productModal');
            const deleteModal = document.getElementById('deleteModal');
            if (event.target === productModal) {
                closeModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
                closeDeleteModal();
            }
        });
    </script>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>