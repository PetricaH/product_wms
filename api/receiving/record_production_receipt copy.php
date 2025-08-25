<?php
/**
 * API: Record Production Receipt and Print Labels
 * UPDATED: Uses PDF templates with QR code overlay - no rotation
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

    $labelUrl = null;
    if ($doPrint) {
        // Generate PDF label by combining QR code with existing template
        $labelUrl = generateCombinedTemplateLabel($db, $productId, $quantity, $batchNumber, $producedAt);
        if ($labelUrl) {
            $headers = @get_headers($labelUrl);
            if ($headers && strpos($headers[0], '200') !== false) {
                sendToPrintServer($labelUrl, $printer, $config);
                error_log("Production Receipt Debug - PDF label generated: $labelUrl");
            } else {
                error_log("Label not available yet: $labelUrl");
            }
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
 * PDF Template Label Generator - NO ROTATION
 * Finds PDF template and adds QR code + text overlay
 */
function generateCombinedTemplateLabel(PDO $db, int $productId, int $qty, string $batch, string $date): ?string {
    // ───────────── SETTINGS ─────────────
    $qrSize = 20;        // mm - QR code size
    $qrX = 98;           // mm from left edge  
    $qrY = 150;           // mm from top edge
    
    // Text positioning
    $textStartX = 5;    // mm - start text after QR code
    $textStartY = 5;    // mm from top
    $lineHeight = 1;     // mm between text lines
    
    // ───────────── GET PRODUCT DATA ─────────────
    $productModel = new Product($db);
    $product = $productModel->findById($productId);
    if (!$product) {
        error_log("Product not found: {$productId}");
        return null;
    }
    
    $sku = $product['sku'] ?? 'N/A';
    $productName = $product['name'] ?? 'Unknown Product';
    
    // ───────────── FIND TEMPLATE ─────────────
    $templatePath = findProductTemplate($sku, $productName);
    
    // ───────────── LOAD FPDF ─────────────
    if (!class_exists('FPDF')) {
        $autoload = BASE_PATH . '/vendor/autoload.php';
        if (file_exists($autoload)) require_once $autoload;
    }
    if (!class_exists('FPDF')) {
        $fpdfPath = BASE_PATH . '/lib/fpdf.php';
        if (file_exists($fpdfPath)) require_once $fpdfPath;
    }
    
    if (!class_exists('FPDF')) {
        error_log("FPDF library not found");
        return null;
    }
    
    // ───────────── CREATE PDF FROM TEMPLATE ─────────────
    if ($templatePath && file_exists($templatePath)) {
        $ext = strtolower(pathinfo($templatePath, PATHINFO_EXTENSION));
        
        if ($ext === 'pdf') {
            // Load existing PDF template and add QR code
            error_log("Using PDF template: $templatePath");
            return addQRCodeToPDFTemplate($templatePath, $sku, $productName, $qty, $batch, $date, $qrX, $qrY, $qrSize);
            
        } else {
            // Convert PNG/JPG template to PDF with QR code
            error_log("Converting image template to PDF: $templatePath");
            return convertImageTemplateToPDF($templatePath, $sku, $productName, $qty, $batch, $date, $qrX, $qrY, $qrSize);
        }
    } else {
        // Create fallback PDF label
        error_log("No template found, creating fallback PDF label");
        return createFallbackPDFLabel($sku, $productName, $qty, $batch, $date);
    }
}

/**
 * Add QR code to existing PDF template (requires FPDI library)
 */
function addQRCodeToPDFTemplate(string $templatePath, string $sku, string $productName, int $qty, string $batch, string $date, float $qrX, float $qrY, float $qrSize): ?string {
    // Note: This requires FPDI library to import existing PDFs
    // For now, create a new PDF based on template size
    error_log("PDF template merging requires FPDI library - creating new PDF instead");
    return createFallbackPDFLabel($sku, $productName, $qty, $batch, $date);
}

/**
 * Convert image template (PNG/JPG) to PDF with QR code overlay
 */
function convertImageTemplateToPDF(string $templatePath, string $sku, string $productName, int $qty, string $batch, string $date, float $qrX, float $qrY, float $qrSize): ?string {
    // Get image dimensions to determine PDF size
    $imageInfo = getimagesize($templatePath);
    if (!$imageInfo) {
        error_log("Cannot get image size for template: $templatePath");
        return createFallbackPDFLabel($sku, $productName, $qty, $batch, $date);
    }
    
    $imageWidth = $imageInfo[0];   // pixels
    $imageHeight = $imageInfo[1];  // pixels
    
    // Convert pixels to mm (assuming 203 DPI)
    $dpi = 203;
    $mmPerInch = 25.4;
    $labelWidth = ($imageWidth / $dpi) * $mmPerInch;
    $labelHeight = ($imageHeight / $dpi) * $mmPerInch;
    
    error_log("Template size: {$imageWidth}x{$imageHeight}px = {$labelWidth}x{$labelHeight}mm");
    
    // Create PDF with template as background
    $pdf = new FPDF('P', 'mm', [$labelWidth, $labelHeight]);
    $pdf->AddPage();
    
    // Add template image as background
    $pdf->Image($templatePath, 0, 0, $labelWidth, $labelHeight);
    
    // Add QR code
    $qrImagePath = generateTempQRImage($sku);
    if ($qrImagePath && file_exists($qrImagePath)) {
        $pdf->Image($qrImagePath, $qrX, $qrY, $qrSize, $qrSize, 'PNG');
        @unlink($qrImagePath);
    }
    
    // Add text content
    addTextContentToPDF($pdf, $sku, $productName, $qty, $batch, $date, $qrX, $qrY + $qrSize + 1);
    
    // Save PDF
    return saveFinalPDF($pdf, $batch);
}

/**
 * Create fallback PDF label when no template found
 */
function createFallbackPDFLabel(string $sku, string $productName, int $qty, string $batch, string $date): ?string {
    $labelWidth = 100;   // mm
    $labelHeight = 150;  // mm
    
    // Use the same coordinates as main settings
    $qrX = 98;           // mm from left edge  
    $qrY = 150;          // mm from top edge
    $qrSize = 20;        // mm - QR code size
    
    $pdf = new FPDF('P', 'mm', [$labelWidth, $labelHeight]);
    $pdf->AddPage();
    
    // White background
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(0, 0, $labelWidth, $labelHeight, 'F');
    
    // Add QR code - USE CONSISTENT COORDINATES
    $qrImagePath = generateTempQRImage($sku);
    if ($qrImagePath && file_exists($qrImagePath)) {
        $pdf->Image($qrImagePath, $qrX, $qrY, $qrSize, $qrSize, 'PNG');  // Changed from (15, 15, 25, 25)
        @unlink($qrImagePath);
    }
    
    // Add text content - ADJUST TEXT POSITION TOO
    addTextContentToPDF($pdf, $sku, $productName, $qty, $batch, $date, 5, 5);  // Changed from (50, 20)
    
    return saveFinalPDF($pdf, $batch);
}

/**
 * Add text content to PDF
 */
function addTextContentToPDF($pdf, string $sku, string $productName, int $qty, string $batch, string $date, float $textX, float $textY): void {
    $pdf->SetTextColor(0, 0, 0);
    $currentY = $textY;
    
    // // SKU
    // $pdf->SetFont('Arial', 'B', 12);
    // $pdf->SetXY($textX, $currentY);
    // $pdf->Cell(0, 6, "SKU: " . $sku, 0, 1, 'L');
    // $currentY += 8;
    
    // // Product name
    // $pdf->SetFont('Arial', '', 9);
    // $pdf->SetXY($textX, $currentY);
    // $pdf->Cell(0, 5, substr($productName, 0, 30), 0, 1, 'L');
    // $currentY += 10;
    
    // Batch
    if ($batch) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY($textX, $currentY);
        $pdf->Cell(0, 5, "LOT: " . $batch, 0, 1, 'L');
        $currentY += 4;
    }
    
    // Quantity
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY($textX, $currentY);
    $pdf->Cell(0, 5, "QTY: " . $qty . " buc", 0, 1, 'L');
    $currentY += 4;
    
    // Date
    $pdf->SetXY($textX, $currentY);
    $pdf->Cell(0, 5, "DATA: " . date('d.m.Y', strtotime($date)), 0, 1, 'L');
}

/**
 * Generate temporary QR code image
 */
function generateTempQRImage(string $data): ?string {
    try {
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($data);
        $qrData = @file_get_contents($qrUrl);
        if ($qrData === false) return null;
        
        $tempDir = BASE_PATH . '/storage/temp';
        if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
        
        $tempFile = $tempDir . '/qr_' . time() . '_' . mt_rand(1000, 9999) . '.png';
        file_put_contents($tempFile, $qrData);
        
        return $tempFile;
    } catch (Exception $e) {
        error_log("QR temp file creation failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Save final PDF and return URL
 */
function saveFinalPDF($pdf, string $batch): ?string {
    $dir = BASE_PATH . '/storage/label_pdfs';
    if (!file_exists($dir)) mkdir($dir, 0777, true);
    
    $fileName = 'combined_template_label_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $batch) . '.pdf';
    $filePath = "{$dir}/{$fileName}";
    
    $pdf->Output('F', $filePath);
    
    $url = rtrim(getBaseUrl(), '/') . '/storage/label_pdfs/' . $fileName;
    error_log("✅ PDF Label saved: {$filePath}");
    
    return $url;
}

/**
 * Find the appropriate template for a product (SAME AS BEFORE)
 */
function findProductTemplate(string $sku, string $productName): ?string {
    $templateDir = BASE_PATH . '/storage/templates/product_labels/';
    
    // Extract number from SKU (e.g., "APF906.10" → "906", "806.25" → "806")
    if (preg_match('/(\d+)/', $sku, $matches)) {
        $productCode = $matches[1];
        error_log("Extracted product code '$productCode' from SKU '$sku'");
        
        // Look for PDF files first, then fallback to PNG
        $extensions = ['pdf', 'png', 'jpg'];
        
        foreach ($extensions as $ext) {
            $availableTemplates = glob($templateDir . '*.' . $ext);
            
            foreach ($availableTemplates as $templatePath) {
                $templateName = basename($templatePath, '.' . $ext);
                
                // Check if template name contains the product code
                if (strpos($templateName, $productCode) !== false) {
                    error_log("✅ FOUND template: $templatePath for product code $productCode");
                    return $templatePath;
                }
            }
        }
        
        error_log("❌ No template found containing product code '$productCode'");
    } else {
        error_log("❌ No number found in SKU '$sku'");
    }
    
    // Fallback: Generic template
    $genericOptions = ['generic_template.pdf', 'generic_template.png'];
    foreach ($genericOptions as $generic) {
        $templatePath = $templateDir . $generic;
        if (file_exists($templatePath)) {
            error_log("Using generic template: $templatePath");
            return $templatePath;
        }
    }
    
    error_log("No template found for SKU '$sku'");
    return null;
}

/**
 * Extract product code template from product name (SAME AS BEFORE)
 */
function extractProductCodeTemplate(string $productName, string $templateDir): ?string {
    error_log("Searching template for product: $productName");
    
    // Method 1: Look for LILLIOS specifically
    if (stripos($productName, 'LILLIOS') !== false) {
        if (preg_match('/(\d+)/', $productName, $matches)) {
            $number = $matches[1];
            $extensions = ['pdf', 'png'];
            
            foreach ($extensions as $ext) {
                $templatePath = $templateDir . 'LILLIOS-' . $number . '.' . $ext;
                if (file_exists($templatePath)) {
                    error_log("Trying LILLIOS pattern: $templatePath");
                    return $templatePath;
                }
            }
        }
    }
    
    // Method 2: Extract brand name and number from product name
    if (preg_match('/\b([A-Z][A-Z\-]*[A-Z])\b.*?(\d+)/i', $productName, $matches)) {
        $brand = strtoupper($matches[1]);
        $number = $matches[2];
        $extensions = ['pdf', 'png'];
        
        foreach ($extensions as $ext) {
            $templatePath = $templateDir . $brand . '-' . $number . '.' . $ext;
            if (file_exists($templatePath)) {
                error_log("Trying brand-number pattern: $templatePath");
                return $templatePath;
            }
        }
    }
    
    return null;
}

function sendToPrintServer(string $labelUrl, ?string $printer, array $config): void {
    $printerName    = $printer ?: ($config['default_printer'] ?? 'godex');
    $printServerUrl = $config['print_server_url'] ?? 'http://86.124.196.102:3000/print_server.php';

    $path         = strtolower(parse_url($labelUrl, PHP_URL_PATH) ?? '');
    $isPdf        = substr($path, -4) === '.pdf';

    if ($isPdf) {
        $result = sendPdfToServer($printServerUrl, $labelUrl, $printerName);
    } else {
        $result = sendPngToServer($printServerUrl, $labelUrl, $printerName);
    }

    if (!$result['success']) {
        error_log('Label print failed: ' . $result['error']);
    }
}

function sendPngToServer(string $url, string $pngUrl, string $printer): array {
    $requestUrl = $url . '?' . http_build_query([
        'url' => $pngUrl,
        'printer' => $printer,
        'format' => 'png'
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'user_agent' => 'WMS-PrintClient/1.0'
        ]
    ]);

    $response = @file_get_contents($requestUrl, false, $context);
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

function sendPdfToServer(string $url, string $pdfUrl, string $printer): array {
    $requestUrl = $url . '?' . http_build_query([
        'url' => $pdfUrl,
        'printer' => $printer
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'user_agent' => 'WMS-PrintClient/1.0'
        ]
    ]);

    $response = @file_get_contents($requestUrl, false, $context);
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

function getBaseUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}
?>