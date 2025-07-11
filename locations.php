<?php
// File: locations.php - Updated with table layout and fixed modals
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
require_once BASE_PATH . '/models/Location.php';

$locationModel = new Location($db);

// Handle operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $locationData = [
                'location_code' => trim($_POST['location_code'] ?? ''),
                'zone' => trim($_POST['zone'] ?? ''),
                'type' => trim($_POST['type'] ?? ''),
                'capacity' => intval($_POST['capacity'] ?? 0),
                'description' => trim($_POST['description'] ?? ''),
                'status' => intval($_POST['status'] ?? 1)
            ];
            
            if (empty($locationData['location_code']) || empty($locationData['zone'])) {
                $message = 'Codul locației și zona sunt obligatorii.';
                $messageType = 'error';
            } else {
                if ($locationModel->createLocation($locationData)) {
                    $message = 'Locația a fost creată cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la crearea locației. Verificați dacă codul nu există deja.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'update':
            $locationId = intval($_POST['location_id'] ?? 0);
            $locationData = [
                'location_code' => trim($_POST['location_code'] ?? ''),
                'zone' => trim($_POST['zone'] ?? ''),
                'type' => trim($_POST['type'] ?? ''),
                'capacity' => intval($_POST['capacity'] ?? 0),
                'description' => trim($_POST['description'] ?? ''),
                'status' => intval($_POST['status'] ?? 1)
            ];
            
            if ($locationId <= 0 || empty($locationData['location_code']) || empty($locationData['zone'])) {
                $message = 'Date invalide pentru actualizare.';
                $messageType = 'error';
            } else {
                if ($locationModel->updateLocation($locationId, $locationData)) {
                    $message = 'Locația a fost actualizată cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la actualizarea locației.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'delete':
            $locationId = intval($_POST['location_id'] ?? 0);
            
            if ($locationId <= 0) {
                $message = 'ID locație invalid.';
                $messageType = 'error';
            } else {
                if ($locationModel->deleteLocation($locationId)) {
                    $message = 'Locația a fost ștearsă cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la ștergerea locației. Verificați dacă nu conține stoc.';
                    $messageType = 'error';
                }
            }
            break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'get_warehouse_data':
            try {
                $zoneFilter = trim($_POST['zone'] ?? '');
                $typeFilter = trim($_POST['type'] ?? '');
                $search = trim($_POST['search'] ?? '');
                
                $warehouseData = $locationModel->getWarehouseVisualizationData($zoneFilter, $typeFilter, $search);
                
                echo json_encode([
                    'success' => true,
                    'locations' => $warehouseData,
                    'timestamp' => time()
                ]);
                exit;
                
            } catch (Exception $e) {
                error_log("Error fetching warehouse data: " . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'Eroare la încărcarea datelor depozitului.'
                ]);
                exit;
            }
            break;
            
        case 'get_location_details':
            try {
                $locationId = intval($_POST['id'] ?? 0);
                
                if ($locationId <= 0) {
                    throw new InvalidArgumentException('ID locație invalid.');
                }
                
                $locationDetails = $locationModel->getLocationDetails($locationId);
                
                if (!$locationDetails) {
                    throw new Exception('Locația nu a fost găsită.');
                }
                
                // Calculate additional metrics using geometry constants
                $levelCapacity = 36; // 2.98m x 1m shelf fits 36 x 25L barrels per level
                $totalCapacity = $levelCapacity * 3;
                $locationDetails['level_capacity'] = $levelCapacity;

                $locationDetails['occupancy'] = [
                    'total' => $totalCapacity > 0 ?
                        round(($locationDetails['total_items'] / $totalCapacity) * 100, 1) : 0,
                    'bottom' => $levelCapacity > 0 ?
                        round(($locationDetails['bottom_items'] / $levelCapacity) * 100, 1) : 0,
                    'middle' => $levelCapacity > 0 ?
                        round(($locationDetails['middle_items'] / $levelCapacity) * 100, 1) : 0,
                    'top' => $levelCapacity > 0 ?
                        round(($locationDetails['top_items'] / $levelCapacity) * 100, 1) : 0
                ];
                
                echo json_encode([
                    'success' => true,
                    'location' => $locationDetails
                ]);
                exit;
                
            } catch (Exception $e) {
                error_log("Error fetching location details: " . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => $e->getMessage()
                ]);
                exit;
            }
            break;
            
        case 'update_shelf_level':
            try {
                $locationId = intval($_POST['location_id'] ?? 0);
                $level = trim($_POST['level'] ?? '');
                $productId = intval($_POST['product_id'] ?? 0);
                $quantity = intval($_POST['quantity'] ?? 0);
                
                if ($locationId <= 0 || !in_array($level, ['bottom', 'middle', 'top']) || $productId <= 0) {
                    throw new InvalidArgumentException('Parametrii inválizi.');
                }
                
                $success = $locationModel->updateShelfLevel($locationId, $level, $productId, $quantity);
                
                if ($success) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Nivelul a fost actualizat cu succes.'
                    ]);
                } else {
                    throw new Exception('Eroare la actualizarea nivelului.');
                }
                exit;
                
            } catch (Exception $e) {
                error_log("Error updating shelf level: " . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => $e->getMessage()
                ]);
                exit;
            }
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acțiune necunoscută.'
            ]);
            exit;
    }
}

// Handle GET requests for AJAX
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_warehouse_data':
            try {
                $zoneFilter = trim($_GET['zone'] ?? '');
                $typeFilter = trim($_GET['type'] ?? '');
                $search = trim($_GET['search'] ?? '');
                
                $warehouseData = $locationModel->getWarehouseVisualizationData($zoneFilter, $typeFilter, $search);
                
                echo json_encode([
                    'success' => true,
                    'locations' => $warehouseData,
                    'timestamp' => time()
                ]);
                exit;
                
            } catch (Exception $e) {
                error_log("Error fetching warehouse data: " . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'Eroare la încărcarea datelor depozitului.'
                ]);
                exit;
            }
            break;
    }
}

$zoneFilter = trim($_GET['zone'] ?? $_POST['zone'] ?? '');
$typeFilter = trim($_GET['type'] ?? $_POST['type'] ?? '');
$search = trim($_GET['search'] ?? $_POST['search'] ?? '');

try {
    $warehouseData = $locationModel->getWarehouseVisualizationData($zoneFilter, $typeFilter, $search);
    $warehouseStats = [
        'total_locations' => count($warehouseData),
        'occupied_locations' => count(array_filter($warehouseData, function($loc) { 
            return isset($loc['total_items']) && $loc['total_items'] > 0; 
        })),
        'total_items' => array_sum(array_column($warehouseData, 'total_items')),
        'high_occupancy_count' => count(array_filter($warehouseData, function($loc) { 
            return isset($loc['occupancy']['total']) && $loc['occupancy']['total'] >= 80; 
        }))
    ];
    
    $overallOccupancy = $warehouseStats['total_locations'] > 0 
        ? round(($warehouseStats['occupied_locations'] / $warehouseStats['total_locations']) * 100, 1) 
        : 0;
} catch (Exception $e) {
    error_log("Warehouse visualization error: " . $e->getMessage());
    $warehouseData = [];
    $warehouseStats = [
        'total_locations' => 0, 
        'occupied_locations' => 0, 
        'total_items' => 0, 
        'high_occupancy_count' => 0
    ];
    $overallOccupancy = 0;
}

// Get filters
$zoneFilter = $_GET['zone'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($page - 1) * $pageSize;

// Get total count and data with proper pagination
$totalCount = $locationModel->getTotalCount($zoneFilter, $typeFilter, $search);
$totalPages = max(1, ceil($totalCount / $pageSize));
$locations = $locationModel->getLocationsPaginated($pageSize, $offset, $zoneFilter, $typeFilter, $search);

// Get unique zones and types for filters
$zones = $locationModel->getZones();
$types = $locationModel->getTypes();

// Define current page for footer
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Gestionare Locații - WMS</title>
    <link rel="stylesheet" href="styles/locations.css">
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
                            <span class="material-symbols-outlined">pin_drop</span>
                            Gestionare Locații
                        </h1>
                        <button class="btn btn-primary" onclick="openCreateModal()">
                            <span class="material-symbols-outlined">add_location</span>
                            Adaugă Locație
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
                        <h3 class="card-title">Filtrare Locații</h3>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="filter-form">
                            <div class="form-group">
                                <label class="form-label">Zonă</label>
                                <select name="zone" class="form-control">
                                    <option value="">Toate zonele</option>
                                    <?php foreach ($zones as $zone): ?>
                                        <option value="<?= htmlspecialchars($zone) ?>" <?= $zoneFilter === $zone ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($zone) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Tip</label>
                                <select name="type" class="form-control">
                                    <option value="">Toate tipurile</option>
                                    <?php foreach ($types as $type): ?>
                                        <option value="<?= htmlspecialchars($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($type) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Căutare</label>
                                <input type="text" name="search" class="form-control search-input" 
                                       placeholder="Cod locație..." value="<?= htmlspecialchars($search) ?>">
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

                <!-- Warehouse Visualization Section -->
                <div class="warehouse-visualization-section" id="warehouseVisualization">
                <div class="visualization-header">
                    <h3 class="section-title">
                        <span class="material-symbols-outlined">warehouse</span>
                        Vizualizare Depozit
                    </h3>
                    <div class="view-controls">
                        <button class="view-btn active" data-view="total" title="Vizualizare generală">
                            <span class="material-symbols-outlined">visibility</span>
                            <span>Total</span>
                        </button>
                        <button class="view-btn" data-view="bottom" title="Nivel inferior">
                            <span class="material-symbols-outlined">vertical_align_bottom</span>
                            <span>Jos</span>
                        </button>
                        <button class="view-btn" data-view="middle" title="Nivel mijloc">
                            <span class="material-symbols-outlined">horizontal_rule</span>
                            <span>Mijloc</span>
                        </button>
                        <button class="view-btn" data-view="top" title="Nivel superior">
                            <span class="material-symbols-outlined">vertical_align_top</span>
                            <span>Sus</span>
                        </button>
                        <button class="view-btn" data-view="table" title="Comutare la vizualizarea tabel">
                            <span class="material-symbols-outlined">table_view</span>
                            <span>Tabel</span>
                        </button>
                    </div>
                </div>

                <div class="warehouse-stats">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <span class="material-symbols-outlined">assessment</span>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= $overallOccupancy ?>%</div>
                            <div class="stat-label">Ocupare Generală</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <span class="material-symbols-outlined">shelves</span>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= $warehouseStats['occupied_locations'] ?>/<?= $warehouseStats['total_locations'] ?></div>
                            <div class="stat-label">Locații Active</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <span class="material-symbols-outlined">inventory_2</span>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= number_format($warehouseStats['total_items']) ?></div>
                            <div class="stat-label">Total Articole</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <span class="material-symbols-outlined">warning</span>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= $warehouseStats['high_occupancy_count'] ?></div>
                            <div class="stat-label">Ocupare Înaltă</div>
                        </div>
                    </div>
                </div>

                <div class="warehouse-layout-container" id="warehouseLayoutContainer">
                    <div class="warehouse-grid">
                        <!-- Admin Area -->
                        <div class="zone-area admin-zone">
                            <span class="material-symbols-outlined">admin_panel_settings</span>
                            <span class="zone-label">Admin<br>Area</span>
                        </div>
                        
                        <!-- Main Shelves Grid -->
                        <div class="shelves-grid" id="shelvesGrid">
                            <!-- Shelves will be populated by JavaScript -->
                        </div>
                        
                        <!-- Storage Area -->
                        <div class="zone-area storage-zone">
                            <span class="material-symbols-outlined">inventory</span>
                            <span class="zone-label">Stocare</span>
                        </div>
                        
                        <!-- Entrance -->
                        <div class="zone-area entrance-zone">
                            <span class="material-symbols-outlined">door_front</span>
                            <span class="zone-label">Intrare</span>
                        </div>
                        
                        <!-- Exit -->
                        <div class="zone-area exit-zone">
                            <span class="material-symbols-outlined">exit_to_app</span>
                            <span class="zone-label">Ieșire</span>
                        </div>
                    </div>
                </div>

                <div class="occupancy-legend">
                    <div class="legend-title">Legenda ocupării:</div>
                    <div class="legend-items">
                        <div class="legend-item">
                            <div class="legend-indicator occupancy-empty"></div>
                            <span>Gol (0%)</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-indicator occupancy-low"></div>
                            <span>Scăzut (1-50%)</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-indicator occupancy-medium"></div>
                            <span>Mediu (51-79%)</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-indicator occupancy-high"></div>
                            <span>Ridicat (80-94%)</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-indicator occupancy-full"></div>
                            <span>Complet (95%+)</span>
                        </div>
                    </div>
                </div>
            </div>


                <!-- Locations Table -->
                <div class="card">
                    <div class="card-body">
                        <?php if (!empty($locations)): ?>
                            <div class="table-container table-responsive d-none">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Cod Locație</th>
                                            <th>Zonă</th>
                                            <th>Tip</th>
                                            <th>Capacitate</th>
                                            <th>Ocupare</th>
                                            <th>Status</th>
                                            <th>Descriere</th>
                                            <th>Acțiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($locations as $location): ?>
                                            <tr>
                                                <td>
                                                    <code class="location-code"><?= htmlspecialchars($location['location_code']) ?></code>
                                                </td>
                                                <td>
                                                    <span class="zone-badge"><?= htmlspecialchars($location['zone']) ?></span>
                                                </td>
                                                <td>
                                                    <span class="type-badge"><?= htmlspecialchars($location['type'] ?? 'Standard') ?></span>
                                                </td>
                                                <td>
                                                    <span class="text-center">
                                                        <?= ($location['capacity'] ?? 0) ? number_format($location['capacity']) : '-' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $totalItems = $location['total_items'] ?? 0;
                                                    $capacity = $location['capacity'] ?? 0;
                                                    $occupancyPercentage = $capacity > 0 ? ($totalItems / $capacity) * 100 : 0;
                                                    ?>
                                                    <div class="occupancy-info">
                                                        <strong class="<?= 
                                                            $totalItems == 0 ? 'occupancy-empty' : 
                                                            ($occupancyPercentage >= 100 ? 'occupancy-full' : 'occupancy-partial') 
                                                        ?>">
                                                            <?= number_format($totalItems) ?>
                                                        </strong>
                                                        <?php if ($capacity > 0): ?>
                                                            <small class="text-muted">/ <?= number_format($capacity) ?></small>
                                                            <div>
                                                                <small class="text-muted"><?= number_format($occupancyPercentage, 1) ?>%</small>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                <?php if (($location['status'] ?? 'active') === 'active'): ?>
                                                    <span class="status-badge status-active">
                                                        <span class="material-symbols-outlined">check_circle</span>
                                                        Activ
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-badge status-inactive">
                                                        <span class="material-symbols-outlined">cancel</span>
                                                        Inactiv
                                                    </span>
                                                <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($location['description'])): ?>
                                                        <small title="<?= htmlspecialchars($location['description']) ?>">
                                                            <?= htmlspecialchars(substr($location['description'], 0, 50)) ?>
                                                            <?= strlen($location['description']) > 50 ? '...' : '' ?>
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="openEditModal(<?= htmlspecialchars(json_encode($location)) ?>)"
                                                                title="Editează">
                                                            <span class="material-symbols-outlined">edit</span>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="openDeleteModal(<?= $location['id'] ?>, '<?= htmlspecialchars(addslashes($location['location_code'])) ?>')"
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
                                        Afișare <?= ($offset + 1) ?>-<?= min($offset + $pageSize, $totalCount) ?> din <?= number_format($totalCount) ?> locații
                                    </div>
                                    <div class="pagination-controls">
                                        <?php if ($page > 1): ?>
                                            <a href="?page=1&zone=<?= urlencode($zoneFilter) ?>&type=<?= urlencode($typeFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn">Prima</a>
                                            <a href="?page=<?= $page - 1 ?>&zone=<?= urlencode($zoneFilter) ?>&type=<?= urlencode($typeFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn">‹</a>
                                        <?php endif; ?>
                                        
                                        <?php 
                                        $startPage = max(1, $page - 2);
                                        $endPage = min($totalPages, $page + 2);
                                        
                                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                                            <?php if ($i == $page): ?>
                                                <span class="pagination-btn active"><?= $i ?></span>
                                            <?php else: ?>
                                                <a href="?page=<?= $i ?>&zone=<?= urlencode($zoneFilter) ?>&type=<?= urlencode($typeFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn"><?= $i ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $totalPages): ?>
                                            <a href="?page=<?= $page + 1 ?>&zone=<?= urlencode($zoneFilter) ?>&type=<?= urlencode($typeFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn">›</a>
                                            <a href="?page=<?= $totalPages ?>&zone=<?= urlencode($zoneFilter) ?>&type=<?= urlencode($typeFilter) ?>&search=<?= urlencode($search) ?>" class="pagination-btn">Ultima</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">pin_drop</span>
                                <h3>Nu există locații</h3>
                                <p>Adaugă prima locație folosind butonul de mai sus.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Location Details Modal -->
    <div class="modal fade" id="locationDetailsModal" tabindex="-1" aria-labelledby="locationDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="locationDetailsModalLabel">
                        <span class="material-symbols-outlined">location_on</span>
                        Detalii Locație
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="locationDetailsContent">
                    <!-- Content populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Închide</button>
                    <button type="button" class="btn btn-primary" id="editLocationBtn">Editează Locația</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Create/Edit Location Modal -->
    <div class="modal" id="locationModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title" id="modalTitle">Adaugă Locație</h3>
                    <button class="modal-close" onclick="closeModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form id="locationForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="create">
                        <input type="hidden" name="location_id" id="locationId" value="">
                        
                        <div class="form-group">
                            <label for="location_code" class="form-label">Cod Locație *</label>
                            <input type="text" name="location_code" id="location_code" class="form-control" 
                                   placeholder="ex: A-01-B-03" required>
                            <small class="form-help">Format sugerat: [Zonă]-[Culoar]-[Raft]-[Poziție]</small>
                        </div>
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="zone" class="form-label">Zonă *</label>
                                <input type="text" name="zone" id="zone" class="form-control" 
                                       placeholder="ex: A, B, C" required>
                            </div>
                            <div class="form-group">
                                <label for="type" class="form-label">Tip</label>
                                <select name="type" id="type" class="form-control">
                                    <option value="warehouse">Warehouse</option>
                                    <option value="zone">Zone</option>
                                    <option value="rack">Rack</option>
                                    <option value="shelf">Shelf</option>
                                    <option value="bin">Bin</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="capacity" class="form-label">Capacitate</label>
                                <input type="number" name="capacity" id="capacity" class="form-control" 
                                       min="0" placeholder="Nr. max articole">
                            </div>
                            <div class="form-group">
                                <label for="status" class="form-label">Status</label>
                                <select name="status" id="status" class="form-control">
                                    <option value="1">Activ</option>
                                    <option value="0">Inactiv</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description" class="form-label">Descriere</label>
                            <textarea name="description" id="description" class="form-control" rows="3" 
                                      placeholder="Descriere opțională..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">Salvează</button>
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
                        <input type="hidden" name="location_id" id="deleteLocationId">
                        
                        <div class="alert alert-warning">
                            <span class="material-symbols-outlined">warning</span>
                            Ești sigur că vrei să ștergi locația <strong id="deleteLocationCode"></strong>?
                        </div>
                        
                        <p><small class="text-muted">Această acțiune nu poate fi anulată. Locația va fi ștearsă permanent.</small></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Anulează</button>
                        <button type="submit" class="btn btn-danger">Șterge Locația</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
// Pass PHP data to JavaScript
window.warehouseData = <?= json_encode($warehouseData) ?>;
window.warehouseStats = <?= json_encode($warehouseStats) ?>;
window.currentFilters = {
    zone: '<?= htmlspecialchars($zoneFilter) ?>',
    type: '<?= htmlspecialchars($typeFilter) ?>',
    search: '<?= htmlspecialchars($search) ?>'
};
</script>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
// SQL for updating warehouse layout
/*
DELETE FROM locations;

INSERT INTO locations (location_code, type, grid_row, grid_col, available_levels) VALUES
('S1', 'shelf', 1, 1, 'bottom,middle,top'),
('S2', 'shelf', 1, 2, 'bottom,middle,top'),
('S3', 'shelf', 2, 1, 'bottom,middle,top'),
('PASS1', 'shelf', 2, 2, 'middle,top'),
('STORAGE', 'zone', 1, 3, ''),
('ADMIN', 'zone', 2, 3, ''),
('PACK', 'zone', 3, 3, ''),
('ENTRANCE', 'zone', 3, 1, ''),
('TOILET', 'zone', 4, 1, '');
*/
?>