<?php
/**
 * API: Record Production Receipt and Print Labels
 * FIXED: Handles location_code conversion and provides detailed error logging
 */
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

$input = json_decode(file_get_contents('php://input'), true);

// Enhanced validation with logging
$productId = $input['product_id'] ?? null;
$quantity = (int)($input['quantity'] ?? 0);
$batchNumber = trim($input['batch_number'] ?? '');
$producedAt = $input['produced_at'] ?? date('Y-m-d H:i:s');
$locationInput = $input['location_id'] ?? null;

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

    // Handle location_id conversion (string location_code to integer id)
    $locationId = null;
    
    if ($locationInput) {
        if (is_numeric($locationInput)) {
            // It's already a location ID, verify it exists
            $stmt = $db->prepare("SELECT id FROM locations WHERE id = ? AND status = 'active'");
            $stmt->execute([(int)$locationInput]);
            $locationId = $stmt->fetchColumn();
            
            if (!$locationId) {
                throw new Exception('Invalid or inactive location ID: ' . $locationInput);
            }
            error_log("Production Receipt Debug - Location ID verified: $locationId");
        } else {
            // It's a location_code, convert to location_id
            $stmt = $db->prepare("SELECT id FROM locations WHERE location_code = ? AND status = 'active'");
            $stmt->execute([$locationInput]);
            $locationId = $stmt->fetchColumn();
            
            if (!$locationId) {
                throw new Exception('Location not found with code: ' . $locationInput);
            }
            error_log("Production Receipt Debug - Converted location_code '$locationInput' to location_id: $locationId");
        }
    }
    
    // If no location provided or found, find a default production location
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

    $inventoryModel = new Inventory($db);

    // Prepare inventory data with proper types
    $inventoryData = [
        'product_id'   => (int)$productId,
        'location_id'  => (int)$locationId,
        'quantity'     => (int)$quantity,
        'batch_number' => $batchNumber ?: null,
        'received_at'  => $producedAt
    ];

    error_log("Production Receipt Debug - Inventory data: " . json_encode($inventoryData));

    // Check for existing inventory with same product/location/batch to avoid unique constraint violation
    if ($batchNumber) {
        $stmt = $db->prepare("
            SELECT id, quantity 
            FROM inventory 
            WHERE product_id = ? AND location_id = ? AND batch_number = ?
        ");
        $stmt->execute([$productId, $locationId, $batchNumber]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update existing record instead of creating new one
            $stmt = $db->prepare("
                UPDATE inventory 
                SET quantity = quantity + ?, 
                    received_at = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $result = $stmt->execute([$quantity, $producedAt, $existing['id']]);
            if (!$result) {
                throw new Exception('Failed to update existing inventory record');
            }
            $invId = $existing['id'];
            error_log("Production Receipt Debug - Updated existing inventory record: $invId");
        } else {
            // Create new record
            $invId = $inventoryModel->addStock($inventoryData);
            error_log("Production Receipt Debug - Created new inventory record: $invId");
        }
    } else {
        // No batch number, create new record
        $invId = $inventoryModel->addStock($inventoryData);
        error_log("Production Receipt Debug - Created inventory record without batch: $invId");
    }

    if (!$invId) {
        throw new Exception('Failed to add inventory - database insert failed');
    }

    // Generate and send label
    $labelUrl = generateProductionLabel($db, $productId, $quantity, $batchNumber, $producedAt);
    if ($labelUrl) {
        sendToPrintServer($labelUrl);
        error_log("Production Receipt Debug - Label generated: $labelUrl");
    }

    echo json_encode([
        'success' => true, 
        'inventory_id' => $invId,
        'message' => 'Production receipt recorded successfully',
        'debug' => [
            'product_id' => $productId,
            'location_id' => $locationId,
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

// Include the fixed label generation functions from previous artifact
function generateProductionLabel(PDO $db, int $productId, int $qty, string $batch, string $date): ?string {
    $fpdfPath = BASE_PATH . '/lib/fpdf.php';
    if (!file_exists($fpdfPath)) {
        error_log("FPDF library not found at: " . $fpdfPath);
        return null;
    }
    
    require_once $fpdfPath;
    
    if (!class_exists('FPDF')) {
        error_log("FPDF class not available");
        return null;
    }

    $productModel = new Product($db);
    $product = $productModel->findById($productId);
    if (!$product) {
        error_log("Product not found for label generation: " . $productId);
        return null;
    }

    $fileName = 'thermal_label_' . time() . '_' . $batch . '.pdf';
    $dir = BASE_PATH . '/storage/label_pdfs';
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    $filePath = $dir . '/' . $fileName;

    try {
        // Godex 500 thermal label dimensions
        $pdf = new FPDF('P', 'mm', [101.6, 152.4]);
        $pdf->SetMargins(5, 5, 5);
        $pdf->AddPage();
        
        $sku = $product['sku'] ?? 'N/A';
        $qrCodePath = generateProductionQRCode($sku, $batch);
        
        // Product info
        $pdf->SetFont('Courier', 'B', 14);
        $pdf->Cell(0, 8, $product['name'], 0, 1, 'C');
        $pdf->Ln(2);
        
        $pdf->SetFont('Courier', 'B', 16);
        $pdf->Cell(0, 10, 'SKU: ' . $sku, 0, 1, 'C');
        $pdf->Ln(5);
        
        // QR Code
        if ($qrCodePath && file_exists($qrCodePath)) {
            $qrSize = 40;
            $qrX = (101.6 - $qrSize) / 2;
            $qrY = $pdf->GetY();
            
            $pdf->Image($qrCodePath, $qrX, $qrY, $qrSize, $qrSize);
            $pdf->SetY($qrY + $qrSize + 5);
            unlink($qrCodePath);
        }
        
        // Details
        $pdf->SetFont('Courier', '', 12);
        $pdf->Cell(0, 6, 'Lot: ' . $batch, 0, 1, 'C');
        $pdf->Cell(0, 6, 'Quantity: ' . $qty, 0, 1, 'C');
        $pdf->Cell(0, 6, 'Date: ' . date('d.m.Y H:i', strtotime($date)), 0, 1, 'C');
        
        $pdf->Ln(3);
        $pdf->SetFont('Courier', 'B', 10);
        $pdf->Cell(0, 5, 'SCAN CODE: ' . $sku, 0, 1, 'C');
        
        $pdf->Output('F', $filePath);

        $baseUrl = rtrim(getBaseUrl(), '/');
        return $baseUrl . '/storage/label_pdfs/' . $fileName;
        
    } catch (Exception $e) {
        error_log("Error generating thermal label: " . $e->getMessage());
        return null;
    }
}

function generateProductionQRCode(string $sku, string $batch): ?string {
    try {
        $tempDir = BASE_PATH . '/storage/temp';
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        
        $qrFileName = 'production_qr_' . $batch . '_' . time() . '.png';
        $qrFilePath = $tempDir . '/' . $qrFileName;
        
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&format=png&data=' . urlencode($sku);
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Mozilla/5.0 (compatible; WMS-Production-Labels)'
            ]
        ]);
        
        $qrData = @file_get_contents($qrUrl, false, $context);
        
        if ($qrData !== false) {
            file_put_contents($qrFilePath, $qrData);
            return $qrFilePath;
        }
        
        return createProductionBarcodeFallback($sku, $qrFilePath);
        
    } catch (Exception $e) {
        error_log("Production QR code generation failed: " . $e->getMessage());
        return null;
    }
}

function createProductionBarcodeFallback(string $sku, string $filePath): ?string {
    try {
        $width = 300;
        $height = 100;
        
        $image = imagecreate($width, $height);
        if (!$image) return null;
        
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        
        imagefill($image, 0, 0, $white);
        
        $font = 5;
        $textX = ($width - strlen($sku) * imagefontwidth($font)) / 2;
        $textY = ($height - imagefontheight($font)) / 2;
        
        imagestring($image, $font, $textX, $textY, $sku, $black);
        
        for ($i = 0; $i < strlen($sku); $i++) {
            $x = $textX + ($i * imagefontwidth($font));
            imageline($image, $x, 10, $x, 25, $black);
            imageline($image, $x, $height - 25, $x, $height - 10, $black);
        }
        
        imagerectangle($image, 0, 0, $width-1, $height-1, $black);
        
        imagepng($image, $filePath);
        imagedestroy($image);
        
        return $filePath;
        
    } catch (Exception $e) {
        error_log("Production barcode fallback failed: " . $e->getMessage());
        return null;
    }
}

function sendToPrintServer(string $pdfUrl): void {
    try {
        $serverUrl = getBaseUrl() . '/print_server.php?url=' . urlencode($pdfUrl);
        @file_get_contents($serverUrl);
    } catch (Exception $e) {
        error_log("Error sending to print server: " . $e->getMessage());
    }
}

function getBaseUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $scheme . '://' . $host . $path;
}
?>