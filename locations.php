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

// Database connection
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    die("Database connection factory not configured correctly.");
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include models
require_once BASE_PATH . '/models/Location.php';
require_once BASE_PATH . '/models/LocationLevelSettings.php';
require_once BASE_PATH . '/models/LocationEnhanced.php';

$locationModel = new LocationEnhanced($db);
$levelSettingsModel = new LocationLevelSettings($db);

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
                'type' => trim($_POST['type'] ?? 'shelf'),
                'capacity' => intval($_POST['capacity'] ?? 0),
                'levels' => intval($_POST['levels'] ?? 3),
                // Add new dimension fields
                'length_mm' => intval($_POST['length_mm'] ?? 1000),
                'depth_mm' => intval($_POST['depth_mm'] ?? 400),
                'height_mm' => intval($_POST['height_mm'] ?? 900),
                'max_weight_kg' => floatval($_POST['max_weight_kg'] ?? 150),
                'description' => trim($_POST['description'] ?? ''),
                'status' => intval($_POST['status'] ?? 1)
            ];
            
            // Parse level settings data if provided
            if (!empty($_POST['level_settings_data'])) {
                try {
                    $levelSettingsData = json_decode($_POST['level_settings_data'], true);
                    if ($levelSettingsData) {
                        $locationData['level_settings'] = $levelSettingsData;
                    }
                } catch (Exception $e) {
                    error_log("Error parsing level settings: " . $e->getMessage());
                }
            }
            
            // Use enhanced creation method
            $locationId = $locationModel->createLocationWithLevelSettings($locationData);
            if ($locationId) {
                $message = 'Locația a fost creată cu succes.';
                $messageType = 'success';
            } else {
                $message = 'Eroare la crearea locației. Verificați dacă codul nu există deja.';
                $messageType = 'error';
            }
            break;
            
            case 'update':
                $locationId = intval($_POST['location_id'] ?? 0);
                
                // Debug: Log all POST data
                error_log("DEBUG: Update request for location ID: $locationId");
                error_log("DEBUG: POST data: " . json_encode($_POST));
                
                // Helper function to get last value from array or single value
                function getLastValue($value, $default) {
                    if (is_array($value)) {
                        return end($value);
                    }
                    return $value ?? $default;
                }
                
                // Clean and validate the data
                $locationData = [
                    'location_code' => trim($_POST['location_code'] ?? ''),
                    'zone' => trim($_POST['zone'] ?? ''),
                    'type' => trim($_POST['type'] ?? 'shelf'),
                    'capacity' => intval($_POST['capacity'] ?? 0),
                    'levels' => intval($_POST['levels'] ?? 3),
                    // Handle potential duplicate fields by getting the last value
                    'length_mm' => intval(getLastValue($_POST['length_mm'] ?? null, 1000)),
                    'depth_mm' => intval(getLastValue($_POST['depth_mm'] ?? null, 400)),
                    'height_mm' => intval(getLastValue($_POST['height_mm'] ?? null, 900)),
                    'max_weight_kg' => floatval(getLastValue($_POST['max_weight_kg'] ?? null, 150)),
                    'description' => trim($_POST['description'] ?? ''),
                    'status' => intval($_POST['status'] ?? 1)
                ];
                
                // Debug: Log cleaned data
                error_log("DEBUG: Cleaned location data: " . json_encode($locationData));
                
                // Validate required fields
                if ($locationId <= 0 || empty($locationData['location_code'])) {
                    error_log("DEBUG: Validation failed - ID: $locationId, Code: " . $locationData['location_code']);
                    $message = 'Date invalide pentru actualizare.';
                    $messageType = 'error';
                } else {
                    try {
                        // Parse level settings data if provided
                        if (!empty($_POST['level_settings_data'])) {
                            try {
                                $levelSettingsData = json_decode($_POST['level_settings_data'], true);
                                if ($levelSettingsData) {
                                    $locationData['level_settings'] = $levelSettingsData;
                                    error_log("DEBUG: Level settings parsed successfully");
                                }
                            } catch (Exception $e) {
                                error_log("ERROR: parsing level settings: " . $e->getMessage());
                            }
                        }
                        
                        // Check if we should use enhanced update or regular update
                        $updateResult = false;
                        if (method_exists($locationModel, 'updateLocationWithLevelSettings')) {
                            error_log("DEBUG: Using enhanced update method");
                            $updateResult = $locationModel->updateLocationWithLevelSettings($locationId, $locationData);
                        } else {
                            error_log("DEBUG: Using basic update method");
                            $updateResult = $locationModel->updateLocation($locationId, $locationData);
                        }
                        
                        if ($updateResult) {
                            $message = 'Locația a fost actualizată cu succes.';
                            $messageType = 'success';
                            
                            $location = $locationModel->getLocationById($locationId);
                            if (!$location) {
                                $location = []; // Fallback to prevent warnings
                            }
                        } else {
                            error_log("DEBUG: Location update failed for ID: $locationId");
                            $message = 'Eroare la actualizarea locației. Verificați logurile pentru detalii.';
                            $messageType = 'error';
                            $location = [];
                        }
                        
                    } catch (Exception $e) {
                        error_log("ERROR: Exception during location update: " . $e->getMessage());
                        error_log("ERROR: Stack trace: " . $e->getTraceAsString());
                        $message = 'Eroare la actualizarea locației: ' . $e->getMessage();
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
            $locationId = intval($_POST['id'] ?? 0);
            $details = $locationModel->getLocationWithLevelSettings($locationId);
            
            header('Content-Type: application/json');
            if ($details) {
                echo json_encode(['success' => true, 'location' => $details]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Locația nu a fost găsită.']);
            }
            break;

        case 'analyze_repartition_needs':
            $locationId = intval($_POST['location_id'] ?? 0);
            
            if ($locationId > 0) {
                try {
                    require_once BASE_PATH . '/models/AutoRepartitionService.php';
                    $repartitionService = new AutoRepartitionService($db, $levelSettingsModel);
                    $repartitionService->setDryRun(true);
                    
                    $analysis = $repartitionService->processLocation($locationId);
                    
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true, 
                        'analysis' => $analysis,
                        'recommendations' => count($analysis['moves'])
                    ]);
                } catch (Exception $e) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'ID locație invalid.']);
            }
            exit;
    }
}

function cleanArrayValue($value, $default = null) {
    if (is_array($value)) {
        return end($value); // Get the last value if it's an array
    }
    return $value ?? $default;
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
                        <div class="form-group">
                            <label class="form-label">QR Code</label>
                            <canvas id="locationQrCanvas" width="150" height="150" style="display:block;margin-bottom:0.5rem;"></canvas>
                            <button type="button" class="btn btn-secondary" id="downloadQrBtn" onclick="downloadLocationQr()">Descarcă QR</button>
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
                                <input type="number" name="capacity" id="capacity" class="form-control" min="0" placeholder="Nr. max articole">
                            </div>
                            <div class="form-group">
                                <label for="length_mm" class="form-label">Lungime (mm)</label>
                                <input type="number" name="length_mm" id="length_mm" class="form-control" min="0">
                            </div>
                            <div class="form-group">
                                <label for="depth_mm" class="form-label">Adâncime (mm)</label>
                                <input type="number" name="depth_mm" id="depth_mm" class="form-control" min="0">
                            </div>
                        </div>

                        <div class="row">
                            <div class="form-group">
                                <label for="levels" class="form-label">Niveluri</label>
                                <input type="number" name="levels" id="levels" class="form-control" min="1" value="3">
                            </div>
                            <div class="form-group">
                                <label for="height_mm" class="form-label">Înălțime (mm)</label>
                                <input type="number" name="height_mm" id="height_mm" class="form-control" min="0">
                            </div>
                            <div class="form-group">
                                <label for="max_weight_kg" class="form-label">Greutate maximă (kg)</label>
                                <input type="number" step="0.01" name="max_weight_kg" id="max_weight_kg" class="form-control" min="0">
                            </div>
                            <div class="form-group">
                                <label for="status" class="form-label">Status</label>
                                <select name="status" id="status" class="form-control">
                                    <option value="1">Activ</option>
                                    <option value="0">Inactiv</option>
                                </select>
                            </div>
                        </div>

                        <!-- Dimensions Section (add after existing form fields) -->
                        <div class="form-group">
                            <h4 class="form-section-title">
                                <span class="material-symbols-outlined">straighten</span>
                                Dimensiuni Fizice
                            </h4>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="length_mm" class="form-label">Lungime (mm)</label>
                                <input type="number" name="length_mm" id="length_mm" class="form-control" 
                                    value="1000" min="100" max="10000">
                            </div>
                            <div class="form-group">
                                <label for="depth_mm" class="form-label">Adâncime (mm)</label>
                                <input type="number" name="depth_mm" id="depth_mm" class="form-control" 
                                    value="400" min="100" max="2000">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="height_mm" class="form-label">Înălțime Totală (mm)</label>
                                <input type="number" name="height_mm" id="height_mm" class="form-control" 
                                    value="900" min="200" max="5000">
                            </div>
                            <div class="form-group">
                                <label for="max_weight_kg" class="form-label">Greutate Maximă (kg)</label>
                                <input type="number" name="max_weight_kg" id="max_weight_kg" class="form-control" 
                                    value="150" min="10" max="2000" step="0.1">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description" class="form-label">Descriere</label>
                            <textarea name="description" id="description" class="form-control" 
                                      rows="3" placeholder="Detalii suplimentare despre locație..."></textarea>
                        </div>

                        <!-- Level Settings Section (add before closing modal-body) -->
                        <div id="level-settings-section" class="form-section" style="margin-top: 2rem;">
                            <h4 class="form-section-title">
                                <span class="material-symbols-outlined">layers</span>
                                Configurare Niveluri Avansată
                            </h4>
                            <div class="form-check" style="margin-bottom: 1rem;">
                                <input type="checkbox" id="enable_global_auto_repartition" name="enable_global_auto_repartition">
                                <label for="enable_global_auto_repartition" class="form-label">
                                    Activează repartizarea automată pentru toate nivelurile
                                </label>
                            </div>
                            <div id="level-settings-container">
                                <!-- Level settings will be generated dynamically by JavaScript -->
                            </div>
                        </div>
                    
                        <input type="hidden" name="level_settings_data" id="level_settings_data" value="">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">Salvează</button>
                        <button class="btn btn-sm btn-outline" onclick="analyzeRepartition(<?= $location['id'] ?>)" 
                                title="Analizează Repartizare" style="margin-left: 0.5rem;">
                            <span class="material-symbols-outlined">analytics</span>
                        </button>
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

    <!-- Repartition Analysis Modal (add after existing delete modal) -->
    <div class="modal" id="repartitionModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Analiză Repartizare</h3>
                    <button class="modal-close" onclick="closeRepartitionModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="repartitionResults">
                        <div class="loading-message">
                            <span class="material-symbols-outlined">hourglass_empty</span>
                            Se analizează necesitățile de repartizare...
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeRepartitionModal()">Închide</button>
                    <button type="button" class="btn btn-primary" id="executeRepartitionBtn" 
                            onclick="executeRepartition()" style="display: none;">
                        <span class="material-symbols-outlined">auto_fix_high</span>
                        Execută Repartizarea
                    </button>
                </div>
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
    <script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>