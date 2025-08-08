<?php
/**
 * API: Record Production Receipt and Print Labels
 * UPDATED: Uses PNG transparent labels with fixed portrait orientation
 */

// Enable error logging for debugging
ini_set('log_errors', 1);
ini_set('error_log', '/var/www/notsowms.ro/logs/php_debug.log');
error_log("=== PRODUCTION RECEIPT API CALLED ===");

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

require_once BASE_PATH . '/models/Inventory.php';
require_once BASE_PATH . '/models/Product.php';

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
} else {
    $input = $_POST;
}

// Enhanced validation with logging
$productId = $input['product_id'] ?? null;
$quantity = (int)($input['quantity'] ?? 0);
$batchNumber = trim($input['batch_number'] ?? '');
$producedAt = $input['produced_at'] ?? date('Y-m-d H:i:s');
$locationInput = $input['location_id'] ?? null;
$printer   = $input['printer'] ?? null;
$photoDescription = trim($input['photo_description'] ?? '');

$action = $input['action'] ?? 'print_and_add';
$doPrint = in_array($action, ['print', 'print_and_add']);
$doAddStock = in_array($action, ['add_stock', 'print_and_add']);

error_log("Production Receipt Debug - Input: " . json_encode($input));

if (!$productId || $quantity <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Product ID and quantity required']);
    exit;
}

try {
    // Convert product_id to integer if it's a string (SKU lookup)
    if (!is_numeric($productId)) {
        $productModel = new Product($db);
        $product = $productModel->findBySku($productId);
        if (!$product) {
            throw new Exception('Product not found with SKU: ' . $productId);
        }
        $productId = (int)$product['product_id'];
        error_log("Production Receipt Debug - Converted SKU to product_id: $productId");
    } else {
        $productId = (int)$productId;
        // Verify product exists
        $productModel = new Product($db);
        $product = $productModel->findById($productId);
        if (!$product) {
            throw new Exception('Product not found with ID: ' . $productId);
        }
        error_log("Production Receipt Debug - Product ID verified: $productId");
    }

    $locationId = null;
    $designatedLocationId = null;
    $finalLocationId = null;
    $finalLocationCode = null;
    $finalLocationType = null;
    $message = '';
    if ($doAddStock) {
        // Try to find designated location for this product
        $stmt = $db->prepare("SELECT location_id FROM location_subdivisions WHERE dedicated_product_id = ? LIMIT 1");
        $stmt->execute([$productId]);
        $designatedLocationId = $stmt->fetchColumn();
        if (!$designatedLocationId) {
            $stmt = $db->prepare("SELECT location_id FROM location_level_settings WHERE dedicated_product_id = ? LIMIT 1");
            $stmt->execute([$productId]);
            $designatedLocationId = $stmt->fetchColumn();
        }

        if ($designatedLocationId) {
            $locationId = (int)$designatedLocationId;
            error_log("Production Receipt Debug - Using designated location_id: $locationId");
        } elseif ($locationInput) {
            // Handle location_id conversion (string location_code to integer id)
            if (is_numeric($locationInput)) {
                $stmt = $db->prepare("SELECT id FROM locations WHERE id = ? AND status = 'active'");
                $stmt->execute([(int)$locationInput]);
                $locationId = $stmt->fetchColumn();

                if (!$locationId) {
                    throw new Exception('Invalid or inactive location ID: ' . $locationInput);
                }
                error_log("Production Receipt Debug - Location ID verified: $locationId");
            } else {
                $stmt = $db->prepare("SELECT id FROM locations WHERE location_code = ? AND status = 'active'");
                $stmt->execute([$locationInput]);
                $locationId = $stmt->fetchColumn();

                if (!$locationId) {
                    throw new Exception('Location not found with code: ' . $locationInput);
                }
                error_log("Production Receipt Debug - Converted location_code '$locationInput' to location_id: $locationId");
            }
        }

        // If no location determined yet, use default production location
        if (!$locationId) {
            $stmt = $db->prepare("SELECT id FROM locations WHERE type = 'production' AND status = 'active' LIMIT 1");
            $stmt->execute();
            $locationId = $stmt->fetchColumn();

            if (!$locationId) {
                // Try to find any active location as fallback
                $stmt = $db->prepare("SELECT id FROM locations WHERE status = 'active' LIMIT 1");
                $stmt->execute();
                $locationId = $stmt->fetchColumn();

                if (!$locationId) {
                    throw new Exception('No active locations found in the system');
                }
            }
            error_log("Production Receipt Debug - Using default location_id: $locationId");
        }
    }

    // Format the produced_at date properly
    if ($producedAt && $producedAt !== date('Y-m-d H:i:s')) {
        try {
            $dateObj = new DateTime($producedAt);
            $producedAt = $dateObj->format('Y-m-d H:i:s');
            error_log("Production Receipt Debug - Formatted date: $producedAt");
        } catch (Exception $e) {
            $producedAt = date('Y-m-d H:i:s');
            error_log("Production Receipt Debug - Invalid date format, using current: $producedAt");
        }
    }

    $invId = null;
    if ($doAddStock) {
        $inventoryModel = new Inventory($db);
        $inventoryData = [
            'product_id'   => (int)$productId,
            'location_id'  => (int)$locationId,
            'quantity'     => (int)$quantity,
            'batch_number' => $batchNumber ?: null,
            'received_at'  => $producedAt
        ];
        error_log("Production Receipt Debug - Inventory data: " . json_encode($inventoryData));
        if ($batchNumber) {
            $stmt = $db->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND location_id = ? AND batch_number = ?");
            $stmt->execute([$productId, $locationId, $batchNumber]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $stmt = $db->prepare("UPDATE inventory SET quantity = quantity + ?, received_at = ?, updated_at = NOW() WHERE id = ?");
                $result = $stmt->execute([$quantity, $producedAt, $existing['id']]);
                if (!$result) { throw new Exception('Failed to update existing inventory record'); }
                $invId = $existing['id'];
                error_log("Production Receipt Debug - Updated existing inventory record: $invId");
            } else {
                $invId = $inventoryModel->addStock($inventoryData);
                error_log("Production Receipt Debug - Created new inventory record: $invId");
            }
        } else {
            $invId = $inventoryModel->addStock($inventoryData);
            error_log("Production Receipt Debug - Created inventory record without batch: $invId");
        }
        if (!$invId) { throw new Exception('Failed to add inventory - database insert failed'); }

        // Fetch final location details for user feedback
        $stmt = $db->prepare("SELECT location_id FROM inventory WHERE id = ?");
        $stmt->execute([$invId]);
        $finalLocationId = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT location_code, type FROM locations WHERE id = ?");
        $stmt->execute([$finalLocationId]);
        $locInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        $finalLocationCode = $locInfo['location_code'] ?? '';
        $finalLocationType = $locInfo['type'] ?? '';

        $designatedCode = null;
        if ($designatedLocationId) {
            $stmt = $db->prepare("SELECT location_code FROM locations WHERE id = ?");
            $stmt->execute([$designatedLocationId]);
            $designatedCode = $stmt->fetchColumn();
        }

        if ($designatedLocationId && $finalLocationId !== (int)$designatedLocationId) {
            $message = "Locația dedicată {$designatedCode} este plină. Stocul a fost adăugat în zona temporară {$finalLocationCode}.";
        } else {
            if ($finalLocationType === 'temporary') {
                $message = "Stoc adăugat în zona temporară {$finalLocationCode}.";
            } elseif ($designatedCode) {
                $message = "Stoc adăugat în locația dedicată {$finalLocationCode}.";
            } else {
                $message = "Stoc adăugat în locația {$finalLocationCode}.";
            }
        }
    }

    if ($doPrint) {
        $printed = printGodexLabel($db, $productId, $quantity, $batchNumber, $producedAt, $printer, $config);
        if ($printed) {
            error_log("Production Receipt Debug - Label sent to Godex printer");
        } else {
            error_log("Label print failed for Godex printer");
        }
    }

    $savedPhotos = [];
    if ($doAddStock && !empty($_FILES['photos']['name'][0])) {
        $baseDir = BASE_PATH . '/storage/receiving/factory/';
        if (!file_exists($baseDir)) {
            mkdir($baseDir, 0755, true);
        }
        foreach ($_FILES['photos']['tmp_name'] as $idx => $tmp) {
            if ($_FILES['photos']['error'][$idx] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['photos']['name'][$idx], PATHINFO_EXTENSION);
                $filename = 'receipt_' . $invId . '_' . time() . "_{$idx}." . $ext;
                if (move_uploaded_file($tmp, $baseDir . $filename)) {
                    $savedPhotos[] = 'receiving/factory/' . $filename;
                }
            }
        }
    }

    if ($doAddStock && $photoDescription && !empty($savedPhotos)) {
        $dir = BASE_PATH . '/storage/receiving/factory/';
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . 'receipt_' . $invId . '_desc.txt', $photoDescription);
    }

    echo json_encode([
        'success' => true,
        'inventory_id' => $invId,
        'location_id' => $doAddStock ? $finalLocationId : null,
        'location_code' => $doAddStock ? $finalLocationCode : null,
        'message' => $doAddStock ? $message : 'Labels printed successfully',
        'saved_photos' => $doAddStock ? $savedPhotos : [],
        'debug' => [
            'product_id' => $productId,
            'location_id' => $doAddStock ? $finalLocationId : null,
            'quantity' => $quantity,
            'batch_number' => $batchNumber,
            'produced_at' => $producedAt
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Production receipt error: " . $e->getMessage());
    error_log("Production receipt error trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
/**
 * Print label using Godex G500 commands without rasterizing barcode.
 */
function printGodexLabel(PDO $db, int $productId, int $qty, string $batch, string $date, ?string $printer, array $config): bool {
    $productModel = new Product($db);
    $product = $productModel->findById($productId);
    if (!$product) {
        error_log("Product not found: {$productId}");
        return false;
    }

    $sku = $product['sku'] ?? 'N/A';
    $name = $product['name'] ?? '';

    $commands = generateGodexCommands($sku, $name, $batch, $date, $qty);

    $printerName = $printer ?: ($config['default_printer'] ?? 'godex');
    $printServerUrl = $config['print_server_url'] ?? 'http://86.124.196.102:3000/print_server.php';
    $result = sendRawToServer($printServerUrl, $commands, $printerName);

    if (!$result['success']) {
        error_log('Label print failed: ' . $result['error']);
    }
    return $result['success'];
}

/**
 * Build TSPL commands for Godex G500.
 */
function generateGodexCommands(string $sku, string $name, string $batch, string $date, int $qty): string {
    $safeName = substr(preg_replace('/[^A-Za-z0-9\s]/', '', $name), 0, 30);
    return implode("\n", [
        'SIZE 100 mm,150 mm',
        'GAP 3 mm,0 mm',
        'DIRECTION 1',
        'CLS',
        "TEXT 20,20,\"0\",0,1,1,\"{$safeName}\"",
        "TEXT 20,50,\"0\",0,1,1,\"SKU: {$sku}\"",
        "TEXT 20,80,\"0\",0,1,1,\"Batch: {$batch}\"",
        "TEXT 20,110,\"0\",0,1,1,\"{$date}\"",
        "BARCODE 20,150,\"128\",100,1,0,2,2,\"{$sku}\"",
        "PRINT {$qty},1"
    ]) . "\n";
}

/**
 * Send raw printer commands to print server.
 */
function sendRawToServer(string $url, string $commands, string $printer): array {
    $postData = http_build_query([
        'commands' => $commands,
        'printer'  => $printer,
        'format'   => 'raw'
    ]);

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 15,
            'ignore_errors' => true,
            'user_agent' => 'WMS-PrintClient/1.0'
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return ['success' => false, 'error' => 'Failed to connect to print server'];
    }

    foreach (['Trimis la imprimantă', 'sent to printer', 'Print successful'] as $indicator) {
        if (stripos($response, $indicator) !== false) {
            return ['success' => true];
        }
    }

    return ['success' => false, 'error' => 'Print server response: ' . $response];
}
?>
