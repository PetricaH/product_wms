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
require_once BASE_PATH . '/models/Product.php';

$locationModel = new Location($db);
$levelSettingsModel = new LocationLevelSettings($db);
$productModel = new Product($db);

// Handle operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            error_log("=== LOCATION CREATION DEBUG START ===");
            
            $locationData = [
                'location_code' => trim($_POST['location_code'] ?? ''),
                'zone' => trim($_POST['zone'] ?? ''),
                'type' => trim($_POST['type'] ?? 'shelf'),
                'capacity' => intval($_POST['capacity'] ?? 0),
                'levels' => intval($_POST['levels'] ?? 3),
                'length_mm' => intval($_POST['length_mm'] ?? 1000),
                'depth_mm' => intval($_POST['depth_mm'] ?? 400),
                'height_mm' => intval($_POST['height_mm'] ?? 900),
                'max_weight_kg' => floatval($_POST['max_weight_kg'] ?? 150),
                'description' => trim($_POST['description'] ?? ''),
                'status' => intval($_POST['status'] ?? 1)
            ];
            
            error_log("Location Data: " . json_encode($locationData));
            
            // Check if location code already exists first
            $existingLocation = $locationModel->getLocationByCode($locationData['location_code']);
            if ($existingLocation) {
                error_log("ERROR: Location code already exists: " . $locationData['location_code']);
                $message = 'Eroare: Codul locației există deja!';
                $messageType = 'error';
                break;
            }
            
            try {
                // Start transaction manually to get better error handling
                $db->beginTransaction();
                error_log("Transaction started");
                
                // Insert location record directly
                $statusMap = [0 => 'inactive', 1 => 'active', 2 => 'maintenance'];
                $status = $statusMap[$locationData['status'] ?? 1] ?? 'active';
                
                $insertQuery = "INSERT INTO locations
                                (location_code, zone, type, levels, capacity, length_mm, depth_mm, height_mm, max_weight_kg, notes, status, created_at)
                                VALUES (:location_code, :zone, :type, :levels, :capacity, :length_mm, :depth_mm, :height_mm, :max_weight_kg, :notes, :status, NOW())";
                
                $stmt = $db->prepare($insertQuery);
                $params = [
                    ':location_code' => $locationData['location_code'],
                    ':zone' => $locationData['zone'],
                    ':type' => $locationData['type'],
                    ':levels' => $locationData['levels'],
                    ':capacity' => $locationData['capacity'],
                    ':length_mm' => $locationData['length_mm'],
                    ':depth_mm' => $locationData['depth_mm'],
                    ':height_mm' => $locationData['height_mm'],
                    ':max_weight_kg' => $locationData['max_weight_kg'],
                    ':notes' => $locationData['description'],
                    ':status' => $status
                ];
                
                error_log("Executing location insert with params: " . json_encode($params));
                
                if (!$stmt->execute($params)) {
                    $errorInfo = $stmt->errorInfo();
                    error_log("Location insert failed: " . json_encode($errorInfo));
                    throw new Exception("Failed to insert location: " . $errorInfo[2]);
                }
                
                $locationId = (int)$db->lastInsertId();
                error_log("Location inserted successfully with ID: " . $locationId);
                
                // Now try to create level settings one by one
                $levels = $locationData['levels'];
                error_log("Creating level settings for $levels levels");
                
                $customLevelData = [];
                if (!empty($_POST['level_settings_data'])) {
                    $customLevelData = json_decode($_POST['level_settings_data'], true) ?? [];
                }

                for ($level = 1; $level <= $levels; $level++) {
                    error_log("Creating settings for level $level");

                    $levelSettings = [
                        'level_name' => match($level) {
                            1 => 'Bottom',
                            2 => 'Middle', 
                            3 => 'Top',
                            default => "Level $level"
                        },
                        'storage_policy' => 'multiple_products',
                        'allowed_product_types' => null,
                        'max_different_products' => null,
                        'length_mm' => 1000,
                        'depth_mm' => 400,
                        'height_mm' => 300,
                        'max_weight_kg' => 50.0,
                        'volume_min_liters' => null,
                        'volume_max_liters' => null,
                        'weight_min_kg' => null,
                        'weight_max_kg' => null,
                        'enable_auto_repartition' => false,
                        'repartition_trigger_threshold' => 80,
                        'priority_order' => $levels - $level + 1,
                        'requires_special_handling' => false,
                        'temperature_controlled' => false,
                        'items_capacity' => null,
                        'dedicated_product_id' => null,
                        'allow_other_products' => true,
                        'notes' => null
                    ];

                    if (isset($customLevelData[$level])) {
                        $levelSettings = array_merge($levelSettings, $customLevelData[$level]);
                    }
                    
                    error_log("Level $level settings: " . json_encode($levelSettings));
                    
                    // Insert level settings directly
                    $levelQuery = "INSERT INTO location_level_settings
                                  (location_id, level_number, level_name, storage_policy, allowed_product_types,
                                   max_different_products, length_mm, depth_mm, height_mm, max_weight_kg, items_capacity,
                                   dedicated_product_id, allow_other_products,
                                   volume_min_liters, volume_max_liters, weight_min_kg, weight_max_kg,
                                   enable_auto_repartition, repartition_trigger_threshold, priority_order,
                                   requires_special_handling, temperature_controlled, notes)
                                  VALUES
                                  (:location_id, :level_number, :level_name, :storage_policy, :allowed_product_types,
                                   :max_different_products, :length_mm, :depth_mm, :height_mm, :max_weight_kg, :items_capacity,
                                   :dedicated_product_id, :allow_other_products,
                                   :volume_min_liters, :volume_max_liters, :weight_min_kg, :weight_max_kg,
                                   :enable_auto_repartition, :repartition_trigger_threshold, :priority_order,
                                   :requires_special_handling, :temperature_controlled, :notes)";
                    
                    $levelStmt = $db->prepare($levelQuery);
                    $levelParams = [
                        ':location_id' => $locationId,
                        ':level_number' => $level,
                        ':level_name' => $levelSettings['level_name'],
                        ':storage_policy' => $levelSettings['storage_policy'],
                        ':allowed_product_types' => $levelSettings['allowed_product_types'],
                        ':max_different_products' => $levelSettings['max_different_products'],
                        ':length_mm' => $levelSettings['length_mm'],
                        ':depth_mm' => $levelSettings['depth_mm'],
                        ':height_mm' => $levelSettings['height_mm'],
                        ':max_weight_kg' => $levelSettings['max_weight_kg'],
                       ':items_capacity' => $levelSettings['items_capacity'],
                        ':dedicated_product_id' => $levelSettings['dedicated_product_id'],
                        ':allow_other_products' => $levelSettings['allow_other_products'] ? 1 : 0,
                        ':volume_min_liters' => $levelSettings['volume_min_liters'],
                        ':volume_max_liters' => $levelSettings['volume_max_liters'],
                        ':weight_min_kg' => $levelSettings['weight_min_kg'],
                        ':weight_max_kg' => $levelSettings['weight_max_kg'],
                        ':enable_auto_repartition' => $levelSettings['enable_auto_repartition'] ? 1 : 0,
                        ':repartition_trigger_threshold' => $levelSettings['repartition_trigger_threshold'],
                        ':priority_order' => $levelSettings['priority_order'],
                        ':requires_special_handling' => $levelSettings['requires_special_handling'] ? 1 : 0,
                        ':temperature_controlled' => $levelSettings['temperature_controlled'] ? 1 : 0,
                        ':notes' => $levelSettings['notes']
                    ];
                    
                    error_log("Executing level settings insert for level $level with params: " . json_encode($levelParams));
                    
                    if (!$levelStmt->execute($levelParams)) {
                        $errorInfo = $levelStmt->errorInfo();
                        error_log("Level settings insert failed for level $level: " . json_encode($errorInfo));
                        throw new Exception("Failed to insert level settings for level $level: " . $errorInfo[2]);
                    }
                    
                    error_log("Level $level settings created successfully");
                }
                
                $db->commit();
                error_log("Transaction committed successfully");
                
                $message = 'Locația a fost creată cu succes.';
                $messageType = 'success';
                
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                    error_log("Transaction rolled back");
                }
                error_log("CREATION FAILED: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                $message = 'Eroare la crearea locației: ' . $e->getMessage();
                $messageType = 'error';
            }
            
            error_log("=== LOCATION CREATION DEBUG END ===");
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
            exit;

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
$allProducts = $productModel->getAllProductsForDropdown();

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

                    <div class="form-row">
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
                        <div class="form-group">
                            <label for="status" class="form-label">Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="1">Activ</option>
                                <option value="0">Inactiv</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="capacity" class="form-label">Capacitate</label>
                            <input type="number" name="capacity" id="capacity" class="form-control" min="0" placeholder="Nr. max articole" onchange="distributeItemCapacity()">
                        </div>
                        <div class="form-group">
                            <label for="levels" class="form-label">Niveluri</label>
                            <input type="number" name="levels" id="levels" class="form-control" min="1" value="3">
                        </div>
                    </div>
                    
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
                            </div>
                    </div>

                    <input type="hidden" name="level_settings_data" id="level_settings_data" value="">
                </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">Salvează</button>
                        <button class="btn btn-sm btn-outline" onclick="analyzeRepartition(document.getElementById('locationId').value)"
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
        window.allProducts = <?= json_encode($allProducts) ?>;
        window.levelSettingsAvailable = true;
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