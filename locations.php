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

function debugLog($message) {
    $logFile = __DIR__ . '/subdivision_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    error_log($message); // Also try the regular error_log
}

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

// Detect AJAX requests for JSON responses
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

function jsonResponse(array $data)
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Handle operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            error_log("=== DYNAMIC LOCATION CREATION DEBUG START ===");
            
            // Check if we're using the new dynamic level system
            if (isset($_POST['dynamic_levels_data']) && !empty($_POST['dynamic_levels_data'])) {
                error_log("Using new dynamic level system");
                
                try {
                    $db->beginTransaction();
                    error_log("Transaction started for dynamic location creation");
                    
                    // Extract basic location data (no longer includes dimensions/capacity as those are per-level)
                    $locationData = [
                        'location_code' => trim($_POST['location_code'] ?? ''),
                        'zone' => trim($_POST['zone'] ?? ''),
                        'type' => trim($_POST['type'] ?? 'shelf'),
                        'description' => trim($_POST['description'] ?? ''),
                        'status' => intval($_POST['status'] ?? 1)
                    ];
                    
                    error_log("Basic Location Data: " . json_encode($locationData));
                    
                    // Validate required fields
                    if (empty($locationData['location_code']) || empty($locationData['zone'])) {
                        throw new Exception('Codul locației și zona sunt obligatorii!');
                    }
                    
                    // Check if location code already exists
                    $existingLocation = $locationModel->getLocationByCode($locationData['location_code']);
                    if ($existingLocation) {
                        throw new Exception('Codul locației există deja!');
                    }
                    
                    // Parse dynamic levels data
                    $dynamicLevels = json_decode($_POST['dynamic_levels_data'], true);
                    if (empty($dynamicLevels)) {
                        throw new Exception('Trebuie să adăugați cel puțin un nivel!');
                    }
                    
                    error_log("Dynamic Levels Data: " . json_encode($dynamicLevels));
                    
                    // Calculate totals from all levels
                    $totalHeight = 0;
                    $totalWeight = 0;
                    $totalCapacity = 0;
                    $levelCount = count($dynamicLevels);
                    
                    foreach ($dynamicLevels as $levelData) {
                        $totalHeight += intval($levelData['height_mm'] ?? 300);
                        $totalWeight += floatval($levelData['max_weight_kg'] ?? 50);
                        if (!empty($levelData['items_capacity'])) {
                            $totalCapacity += intval($levelData['items_capacity']);
                        }
                    }
                    
                    // Set default dimensions (can be overridden later)
                    $defaultLength = 1000;  // Default shelf length
                    $defaultDepth = 400;    // Default shelf depth
                    
                    error_log("Calculated totals - Height: {$totalHeight}mm, Weight: {$totalWeight}kg, Items: {$totalCapacity}, Levels: {$levelCount}");
                    
                    // Insert main location record
                    $statusMap = [0 => 'inactive', 1 => 'active', 2 => 'maintenance'];
                    $status = $statusMap[$locationData['status']] ?? 'active';
                    
                    $insertQuery = "INSERT INTO locations
                                   (location_code, zone, type, levels, capacity, length_mm, depth_mm, height_mm, max_weight_kg, notes, status, created_at)
                                   VALUES (:location_code, :zone, :type, :levels, :capacity, :length_mm, :depth_mm, :height_mm, :max_weight_kg, :notes, :status, NOW())";
                    
                    $stmt = $db->prepare($insertQuery);
                    $params = [
                        ':location_code' => $locationData['location_code'],
                        ':zone' => $locationData['zone'],
                        ':type' => $locationData['type'],
                        ':levels' => $levelCount,
                        ':capacity' => $totalCapacity > 0 ? $totalCapacity : null,
                        ':length_mm' => $defaultLength,
                        ':depth_mm' => $defaultDepth,
                        ':height_mm' => $totalHeight,
                        ':max_weight_kg' => $totalWeight,
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
                    
                    // Insert individual level settings from dynamic data
                    $levelQuery = "INSERT INTO location_level_settings
                                  (location_id, level_number, level_name, storage_policy, allowed_product_types,
                                   length_mm, depth_mm, height_mm, max_weight_kg, items_capacity,
                                   dedicated_product_id, allow_other_products,
                                   volume_min_liters, volume_max_liters,
                                   enable_auto_repartition, repartition_trigger_threshold, priority_order,
                                   created_at)
                                  VALUES
                                  (:location_id, :level_number, :level_name, :storage_policy, :allowed_product_types,
                                   :length_mm, :depth_mm, :height_mm, :max_weight_kg, :items_capacity,
                                   :dedicated_product_id, :allow_other_products,
                                   :volume_min_liters, :volume_max_liters,
                                   :enable_auto_repartition, :repartition_trigger_threshold, :priority_order,
                                   NOW())";
                    
                    $levelStmt = $db->prepare($levelQuery);
                    $levelNumber = 1;
                    $levelNames = [];

                    foreach ($dynamicLevels as $levelId => $levelData) {
                        error_log("Processing dynamic level {$levelNumber}: " . json_encode($levelData));

                        $levelParams = [
                            ':location_id' => $locationId,
                            ':level_number' => $levelNumber,
                            ':level_name' => $levelData['name'] ?? "Nivel {$levelNumber}",
                            ':storage_policy' => $levelData['storage_policy'] ?? 'multiple_products',
                            ':allowed_product_types' => !empty($levelData['allowed_product_types']) ? json_encode($levelData['allowed_product_types']) : null,
                            ':length_mm' => $defaultLength,
                            ':depth_mm' => $defaultDepth,
                            ':height_mm' => intval($levelData['height_mm'] ?? 300),
                            ':max_weight_kg' => floatval($levelData['max_weight_kg'] ?? 50),
                            ':items_capacity' => !empty($levelData['items_capacity']) ? intval($levelData['items_capacity']) : null,
                            ':dedicated_product_id' => !empty($levelData['dedicated_product_id']) ? intval($levelData['dedicated_product_id']) : null,
                            ':allow_other_products' => ($levelData['allow_other_products'] ?? true) ? 1 : 0,
                            ':volume_min_liters' => !empty($levelData['volume_min_liters']) ? floatval($levelData['volume_min_liters']) : null,
                            ':volume_max_liters' => !empty($levelData['volume_max_liters']) ? floatval($levelData['volume_max_liters']) : null,
                            ':enable_auto_repartition' => ($levelData['enable_auto_repartition'] ?? false) ? 1 : 0,
                            ':repartition_trigger_threshold' => intval($levelData['repartition_trigger_threshold'] ?? 80),
                            ':priority_order' => intval($levelData['priority_order'] ?? $levelNumber)
                        ];
                        
                        error_log("Executing level settings insert for level {$levelNumber} with params: " . json_encode($levelParams));
                        
                        if (!$levelStmt->execute($levelParams)) {
                            $errorInfo = $levelStmt->errorInfo();
                            error_log("Level settings insert failed for level {$levelNumber}: " . json_encode($errorInfo));
                            throw new Exception("Failed to insert level settings for level {$levelNumber}: " . $errorInfo[2]);
                        }
                        
                        error_log("Level {$levelNumber} settings created successfully");

                        // Store level name for QR generation
                        $levelNames[$levelNumber] = $levelData['name'] ?? "Nivel {$levelNumber}";
                        $levelNumber++;
                    }

                    // Generate individual QR codes for each level
                    $qrCodesGenerated = generateLevelQRCodes($db, $locationId, $locationData['location_code'], $levelNames);
                    if ($qrCodesGenerated) {
                        error_log("QR codes generated successfully for all {$levelCount} levels");
                    } else {
                        error_log("Warning: QR code generation had issues, but location was created");
                    }

                    $subdivisionResult = processSubdivisionData($locationId, $_POST);
                    if (!$subdivisionResult['success']) {
                        throw new Exception($subdivisionResult['message']);
                    }
                    
                    $db->commit();
                    error_log("Transaction committed successfully for dynamic location creation");
                    
                    $message = "Locația '{$locationData['location_code']}' a fost creată cu succes cu {$levelCount} niveluri configurate.";
                    $messageType = 'success';
                    
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                        error_log("Transaction rolled back for dynamic location creation");
                    }
                    error_log("DYNAMIC CREATION FAILED: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    $message = 'Eroare la crearea locației: ' . $e->getMessage();
                    $messageType = 'error';
                    if ($isAjax) {
                        jsonResponse([
                            'success' => false,
                            'message' => $message
                        ]);
                    }
                }
                
            }

            error_log("=== LOCATION CREATION DEBUG END ===");

            if ($isAjax) {
                jsonResponse([
                    'success' => $messageType === 'success',
                    'message' => $message,
                    'location_id' => $locationId ?? null
                ]);
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

                if (isset($_POST['dynamic_levels_data']) && !empty($_POST['dynamic_levels_data'])) {
                    $dynamicLevels = json_decode($_POST['dynamic_levels_data'], true);
                    if ($dynamicLevels) {
                        $totalHeight = $totalWeight = $totalCapacity = 0;
                        $levelSettings = [];
                        $levelNum = 1;
                        foreach ($dynamicLevels as $lvl) {
                            $totalHeight += intval($lvl['height_mm'] ?? 300);
                            $totalWeight += floatval($lvl['max_weight_kg'] ?? 50);
                            if (!empty($lvl['items_capacity'])) { $totalCapacity += intval($lvl['items_capacity']); }
                            $levelSettings[$levelNum] = [
                                'level_name' => $lvl['name'] ?? "Nivel {$levelNum}",
                                'storage_policy' => $lvl['storage_policy'] ?? 'multiple_products',
                                'allowed_product_types' => !empty($lvl['allowed_product_types']) ? $lvl['allowed_product_types'] : null,
                                'length_mm' => 1000,
                                'depth_mm' => 400,
                                'height_mm' => intval($lvl['height_mm'] ?? 300),
                                'max_weight_kg' => floatval($lvl['max_weight_kg'] ?? 50),
                                'items_capacity' => !empty($lvl['items_capacity']) ? intval($lvl['items_capacity']) : null,
                                'dedicated_product_id' => !empty($lvl['dedicated_product_id']) ? intval($lvl['dedicated_product_id']) : null,
                                'allow_other_products' => ($lvl['allow_other_products'] ?? true) ? 1 : 0,
                                'volume_min_liters' => !empty($lvl['volume_min_liters']) ? floatval($lvl['volume_min_liters']) : null,
                                'volume_max_liters' => !empty($lvl['volume_max_liters']) ? floatval($lvl['volume_max_liters']) : null,
                                'enable_auto_repartition' => ($lvl['enable_auto_repartition'] ?? false) ? 1 : 0,
                                'repartition_trigger_threshold' => intval($lvl['repartition_trigger_threshold'] ?? 80),
                                'priority_order' => intval($lvl['priority_order'] ?? $levelNum),
                                'subdivision_count' => intval($lvl['subdivision_count'] ?? 1)
                            ];
                            $levelNum++;
                        }
                        $locationData['levels'] = count($dynamicLevels);
                        $locationData['capacity'] = $totalCapacity > 0 ? $totalCapacity : null;
                        $locationData['length_mm'] = 1000;
                        $locationData['depth_mm'] = 400;
                        $locationData['height_mm'] = $totalHeight;
                        $locationData['max_weight_kg'] = $totalWeight;
                        $locationData['level_settings'] = $levelSettings;
                    }
                }
                
                // Debug: Log cleaned data
                error_log("DEBUG: Cleaned location data: " . json_encode($locationData));
                
                // Validate required fields
                if ($locationId <= 0 || empty($locationData['location_code'])) {
                    error_log("DEBUG: Validation failed - ID: $locationId, Code: " . $locationData['location_code']);
                    $message = 'Date invalide pentru actualizare.';
                    $messageType = 'error';
                    if ($isAjax) {
                        jsonResponse([
                            'success' => false,
                            'message' => $message
                        ]);
                    }
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

                        $subdivisionResult = processSubdivisionData($locationId, $_POST);
                        if (!$subdivisionResult['success']) {
                            throw new Exception($subdivisionResult['message']);
                        }
                        
                        if ($db->inTransaction()) {
                            $db->commit();
                        }
        
                        jsonResponse([
                            'success' => true,
                            'message' => 'Locația a fost actualizată cu succes!',
                            'location_id' => $locationId
                        ]);

                    } catch (Exception $e) {
                        error_log("ERROR: Exception during location update: " . $e->getMessage());
                        error_log("ERROR: Stack trace: " . $e->getTraceAsString());
                        $message = 'Eroare la actualizarea locației: ' . $e->getMessage();
                        $messageType = 'error';
                        if ($isAjax) {
                            jsonResponse([
                                'success' => false,
                                'message' => $message
                            ]);
                        }
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
            
            if ($_POST['action'] === 'get_location_details') {
                $locationId = (int)$_POST['id'];
                $result = getLocationWithSubdivisionData($locationId);
                
                header('Content-Type: application/json');
                echo json_encode($result);
                exit;
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

/**
 * Generate individual QR codes for each level using existing infrastructure
 * @param PDO $db Database connection
 * @param int $locationId Location ID
 * @param string $locationCode Location code
 * @param array $levelNames Array of level names indexed by level number
 * @return bool Success status
*/
function generateLevelQRCodes($db, $locationId, $locationCode, array $levelNames) {
    try {
        $successCount = 0;
        $levelCount = count($levelNames);

        foreach ($levelNames as $level => $name) {
            $levelCode  = $locationCode . "\n" . $name;
            $qrFile     = $locationId . '_level_' . $level . '.png';
            $relative   = 'storage/qr_codes/levels/' . $qrFile;
            $absolute   = BASE_PATH . '/' . $relative;

            error_log("Preparing QR code for level {$level}: {$levelCode}");

            try {
                if (!is_dir(dirname($absolute))) {
                    mkdir(dirname($absolute), 0755, true);
                }

                $qrStmt = $db->prepare(
                    "INSERT INTO location_qr_codes (
                        location_id, level_number, qr_code, file_path, created_at
                    ) VALUES (
                        :location_id, :level_number, :qr_code, :file_path, NOW()
                    ) ON DUPLICATE KEY UPDATE
                        qr_code = VALUES(qr_code),
                        file_path = VALUES(file_path),
                        updated_at = NOW()"
                );

                $qrParams = [
                    ':location_id' => $locationId,
                    ':level_number' => $level,
                    ':qr_code'      => $levelCode,
                    ':file_path'    => $relative
                ];

                if ($qrStmt->execute($qrParams)) {
                    if (generateQRCodeImage($levelCode, $absolute)) {
                        $successCount++;
                    } else {
                        error_log("Failed to generate QR image for level {$level}");
                    }
                } else {
                    error_log("Failed to create QR code database record for level {$level}");
                }

            } catch (Exception $e) {
                error_log("Error storing QR code for level {$level}: " . $e->getMessage());
            }
        }

        error_log("QR code records created. Success: {$successCount}/{$levelCount}");
        return $successCount > 0;

    } catch (Exception $e) {
        error_log("Error in generateLevelQRCodes: " . $e->getMessage());
        return false;
    }
}

function generateQRCodeImage(string $data, string $filePath): bool {
    try {
        $url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&format=png&data=' . urlencode($data);
        $context = stream_context_create([
            'http' => [
                'timeout'    => 10,
                'user_agent' => 'Mozilla/5.0 (WMS-QR)'
            ]
        ]);

        $qrData = @file_get_contents($url, false, $context);
        if ($qrData !== false) {
            file_put_contents($filePath, $qrData);
            return true;
        }
        return false;
    } catch (Exception $e) {
        error_log('QR generation failed: ' . $e->getMessage());
        return false;
    }
}
/**
 * Process subdivision data from form submission
 * Call this method in your CREATE and UPDATE action handlers
 * 
 * @param int $locationId
 * @param array $postData
 * @return array Response array
 */
function processSubdivisionData($locationId, $postData) {
    global $db;
    
    debugLog("=== SUBDIVISION PROCESSING DEBUG START ===");
    debugLog("Location ID: " . $locationId);
    debugLog("POST data keys: " . implode(', ', array_keys($postData)));
    
    try {
        // Check if LocationSubdivision class exists
        if (!class_exists('LocationSubdivision')) {
            debugLog("ERROR: LocationSubdivision class not found!");
            
            // Try to load it manually
            $subdivisionFile = __DIR__ . '/models/LocationSubdivision.php';
            if (file_exists($subdivisionFile)) {
                require_once $subdivisionFile;
                debugLog("Manually loaded LocationSubdivision.php");
            } else {
                debugLog("LocationSubdivision.php file not found at: " . $subdivisionFile);
            }
            
            if (!class_exists('LocationSubdivision')) {
                debugLog("FATAL: LocationSubdivision class still not available");
                return [
                    'success' => false,
                    'message' => 'LocationSubdivision model not loaded'
                ];
            }
        }
        debugLog("LocationSubdivision class is available");
        
        // Check if LocationLevelSettings class exists
        if (!class_exists('LocationLevelSettings')) {
            debugLog("ERROR: LocationLevelSettings class not found!");
            
            // Try to load it manually
            $levelSettingsFile = __DIR__ . '/models/LocationLevelSettings.php';
            if (file_exists($levelSettingsFile)) {
                require_once $levelSettingsFile;
                debugLog("Manually loaded LocationLevelSettings.php");
            } else {
                debugLog("LocationLevelSettings.php file not found at: " . $levelSettingsFile);
            }
            
            if (!class_exists('LocationLevelSettings')) {
                debugLog("FATAL: LocationLevelSettings class still not available");
                return [
                    'success' => false,
                    'message' => 'LocationLevelSettings model not loaded'
                ];
            }
        }
        debugLog("LocationLevelSettings class is available");
        
        // Initialize models
        debugLog("Creating model instances...");
        try {
            $levelSettingsModel = new LocationLevelSettings($db);
            debugLog("LocationLevelSettings model created");
        } catch (Exception $e) {
            debugLog("ERROR creating LocationLevelSettings: " . $e->getMessage());
            throw $e;
        }
        
        try {
            $subdivisionModel = new LocationSubdivision($db);
            debugLog("LocationSubdivision model created");
        } catch (Exception $e) {
            debugLog("ERROR creating LocationSubdivision: " . $e->getMessage());
            throw $e;
        }
        
        debugLog("Both models created successfully");
        
        // Extract subdivision data from form
        debugLog("Extracting subdivision data from form...");
        $subdivisionData = extractSubdivisionDataFromForm($postData);
        debugLog("Extracted subdivision data: " . json_encode($subdivisionData));
        
        // Check if extractSubdivisionDataFromForm returned empty data
        if (empty($subdivisionData)) {
            debugLog("WARNING: No subdivision data extracted from form");
        }
        
        // Validate subdivision data
        debugLog("Validating subdivision data...");
        $validationErrors = validateAllSubdivisions($subdivisionData);
        if (!empty($validationErrors)) {
            debugLog("Validation errors found: " . json_encode($validationErrors));
            return [
                'success' => false,
                'message' => 'Erori de validare pentru subdiviziuni',
                'errors' => $validationErrors
            ];
        }
        debugLog("Validation passed");
        
        // Process each level's subdivision data
        debugLog("Processing " . count($subdivisionData) . " levels");
        foreach ($subdivisionData as $levelNumber => $levelData) {
            debugLog("Processing level {$levelNumber} with data: " . json_encode($levelData));
            
            // Check if method exists
            if (!method_exists($levelSettingsModel, 'updateLevelSettingsWithSubdivisions')) {
                debugLog("ERROR: updateLevelSettingsWithSubdivisions method does not exist!");
                throw new Exception("updateLevelSettingsWithSubdivisions method not found");
            }
            
            // Update level settings with subdivision configuration
            debugLog("Calling updateLevelSettingsWithSubdivisions for level {$levelNumber}");
            try {
                $result = $levelSettingsModel->updateLevelSettingsWithSubdivisions($locationId, $levelNumber, $levelData);
                debugLog("updateLevelSettingsWithSubdivisions returned: " . ($result ? 'true' : 'false'));
                
                if (!$result) {
                    debugLog("updateLevelSettingsWithSubdivisions returned false for level {$levelNumber}");
                    throw new Exception("Failed to update level {$levelNumber} subdivision settings");
                }
                debugLog("Successfully updated level {$levelNumber} subdivision settings");
            } catch (Exception $e) {
                debugLog("Exception in updateLevelSettingsWithSubdivisions for level {$levelNumber}: " . $e->getMessage());
                debugLog("Exception trace: " . $e->getTraceAsString());
                throw $e;
            }
        }
        
        debugLog("=== SUBDIVISION PROCESSING DEBUG SUCCESS ===");
        
        return [
            'success' => true,
            'message' => 'Subdiviziunile au fost configurate cu succes',
            'subdivision_data' => $subdivisionData
        ];
        
    } catch (Exception $e) {
        debugLog("=== SUBDIVISION PROCESSING DEBUG ERROR ===");
        debugLog("Error processing subdivision data: " . $e->getMessage());
        debugLog("Stack trace: " . $e->getTraceAsString());
        return [
            'success' => false,
            'message' => 'Eroare la procesarea subdiviziunilor: ' . $e->getMessage()
        ];
    }
}

/**
 * Extract subdivision data from form POST data
 * 
 * @param array $postData
 * @return array
 */
function extractSubdivisionDataFromForm($postData) {
    $subdivisionData = [];
    
    // Find all subdivision-related fields
    foreach ($postData as $key => $value) {
        // Match patterns like: level_1_enable_subdivisions, level_1_subdivision_1_product_id, etc.
        if (preg_match('/^level_(\d+)_enable_subdivisions$/', $key, $matches)) {
            $levelNumber = (int)$matches[1];
            $subdivisionData[$levelNumber]['subdivisions_enabled'] = !empty($value);
        }
        
        if (preg_match('/^level_(\d+)_subdivision_(\d+)_(.+)$/', $key, $matches)) {
            $levelNumber = (int)$matches[1];
            $subdivisionIndex = (int)$matches[2];
            $fieldType = $matches[3];
            
            $subdivisionData[$levelNumber]['subdivisions'][$subdivisionIndex - 1][$fieldType] = $value;
        }
    }
    
    // Clean up subdivision data - remove empty or incomplete subdivisions
    foreach ($subdivisionData as $levelNumber => &$levelData) {
        if (isset($levelData['subdivisions'])) {
            $levelData['subdivisions'] = array_filter($levelData['subdivisions'], function($subdivision) {
                return !empty($subdivision['product_id']) && !empty($subdivision['capacity']);
            });
            
            // Re-index array to remove gaps
            $levelData['subdivisions'] = array_values($levelData['subdivisions']);
        }
        
        // Convert capacity to integer
        if (isset($levelData['subdivisions'])) {
            foreach ($levelData['subdivisions'] as &$subdivision) {
                $subdivision['capacity'] = (int)($subdivision['capacity'] ?? 0);
                $subdivision['product_id'] = (int)($subdivision['product_id'] ?? 0);
            }
        }
    }
    
    return $subdivisionData;
}

/**
 * Validate all subdivision data
 * 
 * @param array $subdivisionData
 * @return array Array of validation errors
 */
function validateAllSubdivisions($subdivisionData) {
    $allErrors = [];
    $levelSettingsModel = new LocationLevelSettings($GLOBALS['db']);
    
    foreach ($subdivisionData as $levelNumber => $levelData) {
        if ($levelData['subdivisions_enabled'] ?? false) {
            $subdivisions = $levelData['subdivisions'] ?? [];
            $validation = $levelSettingsModel->validateSubdivisionConfiguration($subdivisions);
            
            if (!$validation['valid']) {
                $allErrors["level_{$levelNumber}"] = $validation['errors'];
            }
        }
    }
    
    return $allErrors;
}

/**
 * Get location with subdivision data for editing
 * Call this in your get_location_details action
 * 
 * @param int $locationId
 * @return array
 */
function getLocationWithSubdivisionData($locationId) {
    global $db;
    
    try {
        // Initialize models
        $locationModel = new Location($db);
        $levelSettingsModel = new LocationLevelSettings($db);
        $subdivisionModel = new LocationSubdivision($db);
        
        // Get basic location data
        $location = $locationModel->getLocationById($locationId);
        if (!$location) {
            return [
                'success' => false,
                'message' => 'Location not found'
            ];
        }
        
        // Get level settings with subdivision information
        $levelSettings = $levelSettingsModel->getLevelSettingsWithSubdivisions($locationId);
        
        // Get subdivision details with product information
        $subdivisions = $subdivisionModel->getSubdivisionsWithProducts($locationId);
        
        // Group subdivisions by level for easier frontend processing
        $subdivisionsByLevel = [];
        foreach ($subdivisions as $subdivision) {
            $level = $subdivision['level_number'];
            $subdivisionsByLevel[$level][] = $subdivision;
        }
        
        // Enhance level settings with subdivision data
        foreach ($levelSettings as &$level) {
            $levelNumber = $level['level_number'];
            $level['subdivisions'] = $subdivisionsByLevel[$levelNumber] ?? [];
            $level['has_subdivisions'] = !empty($level['subdivisions']);
        }
        
        // Get subdivision statistics
        $subdivisionStats = $subdivisionModel->getSubdivisionStats($locationId);
        
        $location['level_settings'] = $levelSettings;
        $location['subdivision_stats'] = $subdivisionStats;
        
        return [
            'success' => true,
            'location' => $location
        ];
        
    } catch (Exception $e) {
        error_log("Error getting location with subdivision data: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error loading location data: ' . $e->getMessage()
        ];
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
$allProducts = $productModel->getAllProductsForDropdown();
$categories = $productModel->getCategories();

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
                                            <option value="temporary">Temporary</option>
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
                <form id="locationForm" method="POST" novalidate> 
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
                                <option value="temporary">Temporary</option>
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
        window.allCategories = <?= json_encode($categories) ?>;
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