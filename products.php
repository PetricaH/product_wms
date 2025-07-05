<?php
/**
 * Products.php - Modern Products Management with API endpoints
 * Integrated search, pagination, and CRUD operations
 */

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

// API endpoint handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
    header('Content-Type: application/json');
    handleApiRequest();
    exit;
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

/**
 * Handle API requests for AJAX operations
 */
function handleApiRequest() {
    global $config;
    
    try {
        // Database connection
        if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
            throw new Exception("Database connection factory not configured correctly.");
        }
        
        $dbFactory = $config['connection_factory'];
        $db = $dbFactory();
        
        require_once BASE_PATH . '/models/Product.php';
        $productModel = new Product($db);
        
        $action = $_POST['api_action'] ?? '';
        
        switch ($action) {
            case 'search':
                echo json_encode(handleSearch($productModel));
                break;
                
            case 'create':
                echo json_encode(handleCreate($productModel));
                break;
                
            case 'update':
                echo json_encode(handleUpdate($productModel));
                break;
                
            case 'delete':
                echo json_encode(handleDelete($productModel));
                break;
                
            case 'get_products':
                echo json_encode(getProducts($productModel));
                break;
                
            default:
                throw new Exception('Invalid API action');
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Handle product search with filters and pagination
 */
function handleSearch($productModel) {
    $search = $_POST['search'] ?? '';
    $category = $_POST['category'] ?? '';
    $stockFilter = $_POST['stock_filter'] ?? '';
    $sortBy = $_POST['sort_by'] ?? 'name-asc';
    $page = max(1, intval($_POST['page'] ?? 1));
    $pageSize = max(10, min(100, intval($_POST['page_size'] ?? 20)));
    
    // Build the search query
    $whereConditions = [];
    $params = [];
    
    // Text search across multiple fields
    if (!empty($search)) {
        $whereConditions[] = "(p.name LIKE :search OR p.sku LIKE :search OR p.description LIKE :search OR p.category LIKE :search)";
        $params[':search'] = "%{$search}%";
    }
    
    // Category filter
    if (!empty($category)) {
        $whereConditions[] = "p.category = :category";
        $params[':category'] = $category;
    }
    
    // Stock filter
    switch ($stockFilter) {
        case 'in-stock':
            $whereConditions[] = "COALESCE(SUM(i.quantity), 0) > p.min_stock_level";
            break;
        case 'low-stock':
            $whereConditions[] = "COALESCE(SUM(i.quantity), 0) > 0 AND COALESCE(SUM(i.quantity), 0) <= p.min_stock_level";
            break;
        case 'out-of-stock':
            $whereConditions[] = "COALESCE(SUM(i.quantity), 0) = 0";
            break;
    }
    
    // Build WHERE clause
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Handle sorting
    [$sortField, $sortDirection] = explode('-', $sortBy);
    $orderClause = '';
    
    switch ($sortField) {
        case 'name':
            $orderClause = "ORDER BY p.name " . strtoupper($sortDirection);
            break;
        case 'sku':
            $orderClause = "ORDER BY p.sku " . strtoupper($sortDirection);
            break;
        case 'created':
            $orderClause = "ORDER BY p.created_at " . strtoupper($sortDirection);
            break;
        case 'stock':
            $orderClause = "ORDER BY current_stock " . strtoupper($sortDirection);
            break;
        default:
            $orderClause = "ORDER BY p.name ASC";
    }
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(DISTINCT p.product_id) as total
                   FROM products p
                   LEFT JOIN inventory i ON p.product_id = i.product_id
                   {$whereClause}";
    
    $stmt = $productModel->getConnection()->prepare($countQuery);
    $stmt->execute($params);
    $totalCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get paginated results
    $offset = ($page - 1) * $pageSize;
    $query = "SELECT p.*, 
                     COALESCE(SUM(i.quantity), 0) as current_stock,
                     COUNT(DISTINCT i.location_id) as locations_count
              FROM products p
              LEFT JOIN inventory i ON p.product_id = i.product_id
              {$whereClause}
              GROUP BY p.product_id
              {$orderClause}
              LIMIT :offset, :page_size";
    
    $stmt = $productModel->getConnection()->prepare($query);
    
    // Bind search parameters
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    // Bind pagination parameters
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
    
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format products for frontend
    $formattedProducts = array_map(function($product) {
        return [
            'product_id' => intval($product['product_id']),
            'sku' => $product['sku'],
            'name' => $product['name'],
            'description' => $product['description'],
            'category' => $product['category'],
            'quantity' => intval($product['current_stock']),
            'min_stock_level' => intval($product['min_stock_level']),
            'price' => floatval($product['price']),
            'created_at' => $product['created_at'],
            'locations_count' => intval($product['locations_count'])
        ];
    }, $products);
    
    return [
        'success' => true,
        'data' => $formattedProducts,
        'pagination' => [
            'current_page' => $page,
            'page_size' => $pageSize,
            'total_count' => intval($totalCount),
            'total_pages' => ceil($totalCount / $pageSize)
        ]
    ];
}

/**
 * Get all products with inventory data
 */
function getProducts($productModel) {
    try {
        $products = $productModel->getProductsWithInventory();
        
        $formattedProducts = array_map(function($product) {
            return [
                'product_id' => intval($product['product_id']),
                'sku' => $product['sku'],
                'name' => $product['name'],
                'description' => $product['description'],
                'category' => $product['category'],
                'quantity' => intval($product['current_stock']),
                'min_stock_level' => intval($product['min_stock_level']),
                'price' => floatval($product['price']),
                'created_at' => $product['created_at'],
                'locations_count' => intval($product['locations_count'])
            ];
        }, $products);
        
        return [
            'success' => true,
            'data' => $formattedProducts
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error loading products: ' . $e->getMessage()
        ];
    }
}

/**
 * Create new product
 */
function handleCreate($productModel) {
    try {
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
        
        // Validate required fields
        if (empty($productData['name'])) {
            throw new Exception('Numele produsului este obligatoriu.');
        }
        
        // Check if SKU already exists
        if ($productModel->skuExists($productData['sku'])) {
            throw new Exception('SKU-ul există deja în sistem.');
        }
        
        $productId = $productModel->create($productData);
        
        if (!$productId) {
            throw new Exception('Eroare la crearea produsului.');
        }
        
        return [
            'success' => true,
            'message' => 'Produsul a fost creat cu succes.',
            'product_id' => $productId
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Update existing product
 */
function handleUpdate($productModel) {
    try {
        $productId = intval($_POST['product_id'] ?? 0);
        
        if (!$productId) {
            throw new Exception('ID produs invalid.');
        }
        
        $productData = [
            'sku' => trim($_POST['sku'] ?? ''),
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'category' => trim($_POST['category'] ?? ''),
            'quantity' => !empty($_POST['quantity']) ? intval($_POST['quantity']) : 0,
            'min_stock_level' => !empty($_POST['min_stock_level']) ? intval($_POST['min_stock_level']) : 0,
            'price' => !empty($_POST['price']) ? floatval($_POST['price']) : 0.00
        ];
        
        // Validate required fields
        if (empty($productData['name'])) {
            throw new Exception('Numele produsului este obligatoriu.');
        }
        
        // Check if SKU exists for other products
        if ($productModel->skuExists($productData['sku'], $productId)) {
            throw new Exception('SKU-ul este folosit de alt produs.');
        }
        
        $success = $productModel->update($productId, $productData);
        
        if (!$success) {
            throw new Exception('Eroare la actualizarea produsului.');
        }
        
        return [
            'success' => true,
            'message' => 'Produsul a fost actualizat cu succes.'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Delete product
 */
function handleDelete($productModel) {
    try {
        $productId = intval($_POST['product_id'] ?? 0);
        
        if (!$productId) {
            throw new Exception('ID produs invalid.');
        }
        
        // Check if product has inventory
        $product = $productModel->getById($productId);
        if (!$product) {
            throw new Exception('Produsul nu a fost găsit.');
        }
        
        $totalStock = $productModel->getTotalProductQuantity($productId);
        if ($totalStock > 0) {
            throw new Exception('Nu se poate șterge produsul. Există stoc în inventar.');
        }
        
        $success = $productModel->delete($productId);
        
        if (!$success) {
            throw new Exception('Eroare la ștergerea produsului.');
        }
        
        return [
            'success' => true,
            'message' => 'Produsul a fost șters cu succes.'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

// If not an API request, serve the HTML page
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WMS - Produse</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <link rel="stylesheet" href="styles/global.css">
    <link rel="stylesheet" href="styles/products.css">
</head>
<body>
    <!-- Include Sidebar Navigation -->
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <!-- Main Content Area -->
    <!-- Main Content Area -->
    <div class="main-content-wrapper">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <div class="header-title">
                    <span class="material-symbols-outlined">inventory_2</span>
                    Produse
                </div>
                <div class="header-actions">
                    <button class="header-btn" id="add-product-btn">
                        <span class="material-symbols-outlined">add</span>
                        Adaugă Produs
                    </button>
                    <button class="header-btn" id="export-btn">
                        <span class="material-symbols-outlined">download</span>
                        Export
                    </button>
                </div>
            </div>
        </div>

        <!-- Main Container -->
        <div class="container">
        <!-- Search and Filters -->
        <div class="search-section">
            <div class="search-container">
                <div class="search-box">
                    <span class="material-symbols-outlined">search</span>
                    <input type="text" id="search-input" placeholder="Caută după nume, SKU sau categorie...">
                    <button class="clear-search" id="clear-search" style="display: none;">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                
                <div class="filters">
                    <select id="category-filter" class="filter-select">
                        <option value="">Toate categoriile</option>
                        <?php
                        try {
                            $dbFactory = $config['connection_factory'];
                            $db = $dbFactory();
                            require_once BASE_PATH . '/models/Product.php';
                            $productModel = new Product($db);
                            $categories = $productModel->getCategories();
                            
                            foreach ($categories as $category) {
                                echo "<option value=\"" . htmlspecialchars($category['name']) . "\">" . 
                                     htmlspecialchars($category['name']) . "</option>";
                            }
                        } catch (Exception $e) {
                            // Handle error silently for now
                        }
                        ?>
                    </select>
                    
                    <select id="stock-filter" class="filter-select">
                        <option value="">Toate stocurile</option>
                        <option value="in-stock">În stoc</option>
                        <option value="low-stock">Stoc scăzut</option>
                        <option value="out-of-stock">Fără stoc</option>
                    </select>
                    
                    <select id="sort-filter" class="filter-select">
                        <option value="name-asc">Nume A-Z</option>
                        <option value="name-desc">Nume Z-A</option>
                        <option value="sku-asc">SKU A-Z</option>
                        <option value="created-desc">Cel mai recent</option>
                        <option value="stock-desc">Stoc mare</option>
                        <option value="stock-asc">Stoc mic</option>
                    </select>
                </div>
            </div>
            
            <div class="search-stats">
                <span id="results-count">0 produse găsite</span>
                <button class="filter-toggle" id="filter-toggle">
                    <span class="material-symbols-outlined">filter_list</span>
                    Filtre
                </button>
            </div>
        </div>

        <!-- Products Grid -->
        <div class="products-grid" id="products-grid">
            <!-- Products will be loaded here -->
        </div>

        <!-- Pagination -->
        <div class="pagination-container">
            <div class="pagination-info">
                <span id="pagination-info">Afișare 1-20 din 100 produse</span>
            </div>
            <div class="pagination-controls">
                <button class="pagination-btn" id="first-page" disabled>
                    <span class="material-symbols-outlined">first_page</span>
                </button>
                <button class="pagination-btn" id="prev-page" disabled>
                    <span class="material-symbols-outlined">chevron_left</span>
                </button>
                
                <div class="page-numbers" id="page-numbers">
                    <!-- Page numbers will be generated here -->
                </div>
                
                <button class="pagination-btn" id="next-page">
                    <span class="material-symbols-outlined">chevron_right</span>
                </button>
                <button class="pagination-btn" id="last-page">
                    <span class="material-symbols-outlined">last_page</span>
                </button>
            </div>
            <div class="page-size-selector">
                <label for="page-size">Produse per pagină:</label>
                <select id="page-size">
                    <option value="10">10</option>
                    <option value="20" selected>20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
        </div> <!-- End .container -->

        <!-- Product Modal -->
        <div class="modal" id="product-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="modal-title">Adaugă Produs</h2>
                    <button class="modal-close" id="modal-close">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                
                <form id="product-form" class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="product-sku">SKU</label>
                            <input type="text" id="product-sku" name="sku" placeholder="Se va genera automat dacă e gol">
                        </div>
                        <div class="form-group">
                            <label for="product-name">Nume Produs*</label>
                            <input type="text" id="product-name" name="name" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="product-description">Descriere</label>
                        <textarea id="product-description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="product-category">Categorie</label>
                            <input type="text" id="product-category" name="category" list="categories">
                            <datalist id="categories">
                                <?php
                                try {
                                    foreach ($categories as $category) {
                                        echo "<option value=\"" . htmlspecialchars($category['name']) . "\">";
                                    }
                                } catch (Exception $e) {
                                    // Default categories if DB fails
                                    echo '<option value="SmartBill">';
                                    echo '<option value="Electronics">';
                                    echo '<option value="Office">';
                                }
                                ?>
                            </datalist>
                        </div>
                        <div class="form-group">
                            <label for="product-price">Preț (RON)</label>
                            <input type="number" id="product-price" name="price" step="0.01" min="0">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="product-quantity">Cantitate</label>
                            <input type="number" id="product-quantity" name="quantity" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label for="product-min-stock">Stoc Minim</label>
                            <input type="number" id="product-min-stock" name="min_stock_level" min="0" value="0">
                        </div>
                    </div>
                    
                    <input type="hidden" id="product-id" name="product_id">
                    <input type="hidden" id="form-action" name="api_action" value="create">
                </form>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancel-btn">Anulare</button>
                    <button type="submit" form="product-form" class="btn btn-primary">Salvează</button>
                </div>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div class="loading-overlay" id="loading-overlay" style="display: none;">
            <div class="loading-spinner">
                <div class="spinner"></div>
                <p>Se încarcă...</p>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <div class="message-container" id="message-container"></div>

    </div> <!-- End .main-content-wrapper -->

    <script src="scripts/products.js"></script>
    <div class="modal" id="product-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modal-title">Adaugă Produs</h2>
                <button class="modal-close" id="modal-close">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            
            <form id="product-form" class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="product-sku">SKU</label>
                        <input type="text" id="product-sku" name="sku" placeholder="Se va genera automat dacă e gol">
                    </div>
                    <div class="form-group">
                        <label for="product-name">Nume Produs*</label>
                        <input type="text" id="product-name" name="name" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="product-description">Descriere</label>
                    <textarea id="product-description" name="description" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="product-category">Categorie</label>
                        <input type="text" id="product-category" name="category" list="categories">
                        <datalist id="categories">
                            <?php
                            try {
                                foreach ($categories as $category) {
                                    echo "<option value=\"" . htmlspecialchars($category['name']) . "\">";
                                }
                            } catch (Exception $e) {
                                // Default categories if DB fails
                                echo '<option value="SmartBill">';
                                echo '<option value="Electronics">';
                                echo '<option value="Office">';
                            }
                            ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label for="product-price">Preț (RON)</label>
                        <input type="number" id="product-price" name="price" step="0.01" min="0">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="product-quantity">Cantitate</label>
                        <input type="number" id="product-quantity" name="quantity" min="0" value="0">
                    </div>
                    <div class="form-group">
                        <label for="product-min-stock">Stoc Minim</label>
                        <input type="number" id="product-min-stock" name="min_stock_level" min="0" value="0">
                    </div>
                </div>
                
                <input type="hidden" id="product-id" name="product_id">
                <input type="hidden" id="form-action" name="api_action" value="create">
            </form>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancel-btn">Anulare</button>
                <button type="submit" form="product-form" class="btn btn-primary">Salvează</button>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loading-overlay" style="display: none;">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>Se încarcă...</p>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <div class="message-container" id="message-container"></div>

    <script src="scripts/products.js"></script>
    <script>
        // Pass API endpoint to JavaScript
        window.PRODUCTS_API_URL = window.location.href;
    </script>
</body>
</html>