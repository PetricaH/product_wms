<?php
// File: locations.php - Enhanced with storage zones focus
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
                'type' => trim($_POST['type'] ?? 'Shelf'),
                'capacity' => intval($_POST['capacity'] ?? 0),
                'levels' => intval($_POST['levels'] ?? 3),
                'description' => trim($_POST['description'] ?? ''),
                'status' => intval($_POST['status'] ?? 1)
            ];
            
            // Validate location code format
            $validation = $locationModel->validateLocationCode($locationData['location_code'], $locationData['type']);
            
            if (!$validation['valid']) {
                $message = implode('. ', $validation['errors']);
                $messageType = 'error';
            } else {
                // Use enhanced creation method with auto zone extraction
                if ($locationModel->createLocationWithAutoZone($locationData)) {
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
                'type' => trim($_POST['type'] ?? 'Shelf'),
                'capacity' => intval($_POST['capacity'] ?? 0),
                'levels' => intval($_POST['levels'] ?? 3),
                'description' => trim($_POST['description'] ?? ''),
                'status' => intval($_POST['status'] ?? 1)
            ];
            
            if ($locationId <= 0 || empty($locationData['location_code'])) {
                $message = 'Date invalide pentru actualizare.';
                $messageType = 'error';
            } else {
                // Use enhanced update method with auto zone extraction
                if ($locationModel->updateLocationWithAutoZone($locationId, $locationData)) {
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
            if ($locationId > 0) {
                if ($locationModel->deleteLocation($locationId)) {
                    $message = 'Locația a fost ștearsă cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la ștergerea locației.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'get_location_details':
            // AJAX request for location details
            $locationId = intval($_POST['id'] ?? 0);
            $details = $locationModel->getLocationDetails($locationId);
            
            header('Content-Type: application/json');
            if ($details) {
                echo json_encode(['success' => true, 'location' => $details]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Locația nu a fost găsită.']);
            }
            exit;
    }
}

// Get filter parameters
$zoneFilter = trim($_GET['zone'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$search = trim($_GET['search'] ?? '');

// Get enhanced warehouse data for visualization
$warehouseData = $locationModel->getEnhancedWarehouseData($zoneFilter, $typeFilter, $search);
$warehouseStats = $locationModel->getEnhancedWarehouseStats();
$dynamicZones = $locationModel->getDynamicZones();
$uniqueZones = $locationModel->getUniqueZones();

// Calculate overall occupancy
$totalCapacity = array_sum(array_column($warehouseData, 'capacity'));
$totalItems = array_sum(array_column($warehouseData, 'total_items'));
$overallOccupancy = $totalCapacity > 0 ? round(($totalItems / $totalCapacity) * 100, 1) : 0;

// Get all locations for table display
$allLocations = $locationModel->getAllLocations();

// Define current page for footer
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Locații Depozit - WMS</title>
</head>
<body>
    <div class="app">
        <?php require_once __DIR__ . '/includes/navbar.php'; ?>
        
        <div class="main-content">
            <div class="page-container">
                <!-- Page Header -->
                <header class="page-header">
                    <h1 class="page-title">
                        <span class="material-symbols-outlined">shelves</span>
                        Locații Depozit
                    </h1>
                    <button class="btn btn-primary" onclick="openCreateModal()">
                        <span class="material-symbols-outlined">add_location</span>
                        Adaugă Locație
                    </button>
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

                <!-- Enhanced Warehouse Section -->
                <section class="warehouse-section">
                    <!-- Visualization Header -->
                    <div class="visualization-header">
                        <div class="current-view-indicator">
                            <span class="material-symbols-outlined" id="currentViewIcon">shelves</span>
                            <span class="current-view-text" id="currentViewText">Vizualizare Zone și Rafturi</span>
                        </div>

                        <div class="view-controls">
                            <button class="view-btn active" data-view="total" title="Vizualizare zone și rafturi cu niveluri">
                                <span class="material-symbols-outlined">shelves</span>
                                <span>Total</span>
                            </button>
                            <button class="view-btn" data-view="table" title="Vizualizare tabel cu toate locațiile">
                                <span class="material-symbols-outlined">table_view</span>
                                <span>Tabel</span>
                            </button>
                        </div>
                    </div>

                    <!-- Content Area -->
                    <div class="content-area">
                        <!-- Warehouse Visualization -->
                        <div class="warehouse-visualization" id="warehouseVisualization">
                            <div class="storage-zones-container">
                                <!-- Zones Header -->
                                <div class="zones-header">
                                    <h2 class="zones-title">Zone de Stocare</h2>
                                    <p class="zones-subtitle">Selectează o zonă pentru a vedea rafturile și nivelurile de ocupare</p>
                                </div>

                                <!-- Storage Zones Grid -->
                                <div class="storage-zones-grid" id="storageZonesGrid">
                                    <!-- Zones will be populated by JavaScript -->
                                </div>

                                <!-- Shelves Container -->
                                <div class="shelves-container" id="shelvesContainer">
                                    <h3 id="shelvesTitle">Selectează o zonă pentru a vedea rafturile</h3>
                                    <div class="shelves-grid" id="shelvesGrid">
                                        <!-- Shelves will be populated by JavaScript -->
                                    </div>

                                    <!-- Legend -->
                                    <div class="occupancy-legend" id="occupancyLegend" style="display: none;">
                                        <div class="legend-item">
                                            <div class="legend-indicator occupancy-empty"></div>
                                            <span>Gol (0%)</span>
                                        </div>
                                        <div class="legend-item">
                                            <div class="legend-indicator occupancy-low"></div>
                                            <span>Ușor (1-50%)</span>
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
                                            <span>Complet (95-100%)</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Integrated Table View -->
                        <div class="table-container" id="tableContainer">
                            <div class="table-wrapper">
                                <div class="table-header">
                                    <h3 class="table-title">Toate Locațiile de Stocare</h3>
                                    <div class="table-filters">
                                        <select class="filter-input" id="zoneFilter">
                                            <option value="">Toate Zonele</option>
                                            <?php foreach ($uniqueZones as $zone): ?>
                                                <option value="<?= htmlspecialchars($zone) ?>" <?= $zoneFilter === $zone ? 'selected' : '' ?>>
                                                    Zona <?= htmlspecialchars($zone) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select class="filter-input" id="typeFilter">
                                            <option value="">Toate Tipurile</option>
                                            <option value="Shelf">Rafturi</option>
                                            <option value="Zone">Zone</option>
                                            <option value="Warehouse">Depozit</option>
                                        </select>
                                        <input type="text" class="filter-input" id="searchFilter" placeholder="Caută cod locație...">
                                    </div>
                                </div>
                                <div style="overflow-x: auto;">
                                    <table class="locations-table">
                                        <thead>
                                            <tr>
                                                <th>Cod Locație</th>
                                                <th>Zonă</th>
                                                <th>Tip</th>
                                                <th>Ocupare Totală</th>
                                                <th>Jos</th>
                                                <th>Mijloc</th>
                                                <th>Sus</th>
                                                <th>Articole</th>
                                                <th>Produse Unice</th>
                                                <th>Acțiuni</th>
                                            </tr>
                                        </thead>
                                        <tbody id="locationsTableBody">
                                            <!-- Dynamic table rows will be populated here -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <!-- Location Modal -->
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
                                   placeholder="ex: MID-1A, LEFT-2B, RIGHT-3C" required>
                            <small class="form-help">Format pentru rafturi: [ZONĂ]-[POZIȚIE] (ex: MID-1A)</small>
                        </div>
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="zone" class="form-label">Zonă *</label>
                                <input type="text" name="zone" id="zone" class="form-control" 
                                       placeholder="Se completează automat" required>
                                <small class="form-help">Se extrage automat din codul locației</small>
                            </div>
                            <div class="form-group">
                                <label for="type" class="form-label">Tip</label>
                                <select name="type" id="type" class="form-control">
                                    <option value="Warehouse">Warehouse</option>
                                    <option value="Zone">Zone</option>
                                    <option value="Rack">Rack</option>
                                    <option value="Shelf" selected>Shelf</option>
                                    <option value="Bin">Bin</option>
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
                                <label for="levels" class="form-label">Niveluri</label>
                                <input type="number" name="levels" id="levels" class="form-control" min="1" value="3">
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
                            <textarea name="description" id="description" class="form-control" 
                                      rows="3" placeholder="Detalii suplimentare despre locație..."></textarea>
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

    <!-- Delete Modal -->
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
                        
                        <p>Ești sigur că vrei să ștergi locația <strong id="deleteLocationCode"></strong>?</p>
                        
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
        // Pass enhanced PHP data to JavaScript
        window.warehouseData = <?= json_encode($warehouseData) ?>;
        window.warehouseStats = <?= json_encode($warehouseStats) ?>;
        window.dynamicZones = <?= json_encode($dynamicZones) ?>;
        window.uniqueZones = <?= json_encode($uniqueZones) ?>;
        window.allLocations = <?= json_encode($allLocations) ?>;
        window.currentFilters = {
            zone: '<?= htmlspecialchars($zoneFilter) ?>',
            type: '<?= htmlspecialchars($typeFilter) ?>',
            search: '<?= htmlspecialchars($search) ?>'
        };
        
        // Add zone validation support
        window.locationValidation = {
            validateLocationCode: function(code, type) {
                if (!code) return { valid: false, errors: ['Codul este obligatoriu'] };
                
                const errors = [];
                if (type === 'Shelf' && !code.includes('-')) {
                    errors.push('Pentru rafturi, codul trebuie să conțină cratimă (ex: MID-1A)');
                }
                
                if (!/^[A-Z0-9\-]+$/i.test(code)) {
                    errors.push('Codul poate conține doar litere, cifre și cratimă');
                }
                
                return {
                    valid: errors.length === 0,
                    errors: errors,
                    extractedZone: code.includes('-') ? code.split('-')[0].toUpperCase() : null
                };
            }
        };
    </script>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>