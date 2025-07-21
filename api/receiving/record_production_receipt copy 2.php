<?php
/**
 * API: Record Production Receipt and Print Labels
 * UPDATED: Uses combined template labels with SKU barcode + tracking info
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

$input = json_decode(file_get_contents('php://input'), true);

// Enhanced validation with logging
$productId = $input['product_id'] ?? null;
$quantity = (int)($input['quantity'] ?? 0);
$batchNumber = trim($input['batch_number'] ?? '');
$producedAt = $input['produced_at'] ?? date('Y-m-d H:i:s');
$locationInput = $input['location_id'] ?? null;
$printer   = $input['printer'] ?? null;

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

    // Generate and send label using new combined template system
    $labelUrl = generateCombinedTemplateLabel($db, $productId, $quantity, $batchNumber, $producedAt);
    if ($labelUrl) {
        $headers = @get_headers($labelUrl);
        if ($headers && strpos($headers[0], '200') !== false) {
            sendToPrintServer($labelUrl, $printer, $config);
            error_log("Production Receipt Debug - Combined template label generated: $labelUrl");
        } else {
            error_log("Label not available yet: $labelUrl");
        }
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

/**
 * Combined Template Label Generator for Godex Printer
 * Creates a PDF overlay that adds barcode + tracking info to your existing PNG template
 */
function generateCombinedTemplateLabel(PDO $db, int $productId, int $qty, string $batch, string $date): ?string {
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

    $fileName = 'combined_template_label_' . time() . '_' . $batch . '.pdf';
    $dir = BASE_PATH . '/storage/label_pdfs';
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    $filePath = $dir . '/' . $fileName;

    try {
        // Template dimensions - match your actual PNG template size
        $labelWidth = 101.6;   // mm - Godex label width
        $labelHeight = 152.4;  // mm - Godex label height
        
        $pdf = new FPDF('P', 'mm', [$labelWidth, $labelHeight]);
        $pdf->SetMargins(5, 5, 5);
        $pdf->AddPage();
        
        $sku = $product['sku'] ?? 'N/A';
        $productName = $product['name'] ?? 'Unknown Product';
        
        // === LOAD EXISTING PNG TEMPLATE AS BACKGROUND ===
        $templatePath = findProductTemplate($sku, $productName);
        if ($templatePath && file_exists($templatePath)) {
            $pdf->Image($templatePath, 0, 0, $labelWidth, $labelHeight);
            error_log("SUCCESS: Using template: $templatePath for SKU: $sku");
        } else {
            // Log all attempted paths for debugging
            error_log("TEMPLATE NOT FOUND for SKU: $sku, Product: $productName");
            error_log("Attempted path: " . ($templatePath ?? 'null'));
            error_log("Template directory: " . BASE_PATH . '/storage/templates/product_labels/');
            
            // List existing files in template directory for debugging
            $templateDir = BASE_PATH . '/storage/templates/product_labels/';
            if (is_dir($templateDir)) {
                $files = scandir($templateDir);
                error_log("Available template files: " . implode(', ', array_filter($files, function($f) { return $f !== '.' && $f !== '..'; })));
            } else {
                error_log("Template directory does not exist: $templateDir");
            }
        }
        
        // Generate barcode that contains ONLY SKU (for scanning)
        $barcodePath = generateSKUBarcode($sku, $batch);
        
        // === TRACKING BARCODE SECTION ===
        // Position barcode in center-right area to avoid template text and QR code
        $barcodeY = 70; // Middle vertical position
        $barcodeX = 20; // Moved to center-right area to avoid left-side text
        $rotateBarcode = true; // Set to true for vertical barcode, false for horizontal
        
        if ($barcodePath && file_exists($barcodePath)) {
            if ($rotateBarcode) {
                // Create a rotated version of the barcode image
                $rotatedBarcodePath = rotateImageFile($barcodePath, 90);
                if ($rotatedBarcodePath) {
                    // Use rotated image with swapped dimensions
                    $barcodeWidth = 10; // Height becomes width after 90° rotation
                    $barcodeHeight = 45; // Width becomes height after 90° rotation
                    $pdf->Image($rotatedBarcodePath, $barcodeX, $barcodeY, $barcodeWidth, $barcodeHeight);
                    unlink($rotatedBarcodePath); // Clean up rotated temp file
                } else {
                    // Fallback to original if rotation fails
                    $barcodeWidth = 60;
                    $barcodeHeight = 15;
                    $pdf->Image($barcodePath, $barcodeX, $barcodeY, $barcodeWidth, $barcodeHeight);
                }
            } else {
                // Use original horizontal barcode
                $barcodeWidth = 60;
                $barcodeHeight = 15;
                $pdf->Image($barcodePath, $barcodeX, $barcodeY, $barcodeWidth, $barcodeHeight);
            }
            unlink($barcodePath); // Clean up original temp file
        }
        
        // === TRACKING INFORMATION SECTION ===
        // Create and rotate text as image when barcode is rotated
        if ($rotateBarcode) {
            // Create text as an image first, then rotate it
            $textImagePath = createTrackingTextImage($batch, $qty, $date);
            if ($textImagePath) {
                $rotatedTextPath = rotateImageFile($textImagePath, 90);
                if ($rotatedTextPath) {
                    // Position rotated text next to rotated barcode
                    $textX = $barcodeX + $barcodeWidth + 2; // Right of vertical barcode (15mm + 2mm)
                    $textY = $barcodeY; // Same Y as barcode
                    $textWidth = 35; // Rotated text width (was height: 35)
                    $textHeight = 120; // Rotated text height (was width: 120)
                    
                    $pdf->Image($rotatedTextPath, $textX, $textY, $textWidth, $textHeight);
                    unlink($rotatedTextPath); // Clean up rotated text
                }
                unlink($textImagePath); // Clean up original text image
            }
        } else {
            // Use regular horizontal text for horizontal barcode
            $textStartX = $barcodeX;
            $textStartY = $barcodeY + $barcodeHeight + 3; // Below horizontal barcode
            
            $pdf->SetFont('Arial', '', 5); // Small font
            $lineHeight = 3;
            $currentY = $textStartY;
            
            // Lot number
            if ($batch) {
                $pdf->SetXY($textStartX, $currentY);
                $pdf->Cell(30, $lineHeight, 'LOT: ' . $batch, 0, 0, 'L');
                $currentY += $lineHeight;
            }
            
            // Quantity  
            $pdf->SetXY($textStartX, $currentY);
            $pdf->Cell(30, $lineHeight, 'CANTITATE: ' . $qty . ' buc', 0, 0, 'L');
            $currentY += $lineHeight;
            
            // Production date
            $pdf->SetXY($textStartX, $currentY);
            $pdf->Cell(30, $lineHeight, 'DATA: ' . date('d.m.Y H:i', strtotime($date)), 0, 0, 'L');
        }
        
        $pdf->Output('F', $filePath);

        $baseUrl = rtrim(getBaseUrl(), '/');
        return $baseUrl . '/storage/label_pdfs/' . $fileName;
        
    } catch (Exception $e) {
        error_log("Error generating combined template label: " . $e->getMessage());
        return null;
    }
}

function generateSKUBarcode(string $sku, string $batch): ?string {
    try {
        $tempDir = BASE_PATH . '/storage/temp';
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        
        $barcodeFileName = 'sku_barcode_' . $batch . '_' . time() . '.png';
        $barcodeFilePath = $tempDir . '/' . $barcodeFileName;
        
        // Generate barcode with ONLY SKU (Code 128 format)
        $barcodeUrl = 'https://barcode.tec-it.com/barcode.ashx?data=' . urlencode($sku) . '&code=Code128&multiplebarcodes=false&translate-esc=false&unit=Fit&dpi=96&imagetype=Png&rotation=0&color=%23000000&bgcolor=%23ffffff&qunit=Mm&quiet=0';
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Mozilla/5.0 (compatible; WMS-Template-Labels)'
            ]
        ]);
        
        $barcodeData = @file_get_contents($barcodeUrl, false, $context);
        
        if ($barcodeData !== false) {
            file_put_contents($barcodeFilePath, $barcodeData);
            return $barcodeFilePath;
        }
        
        // Fallback to manually generated barcode
        return createSKUBarcodeFallback($sku, $barcodeFilePath);
        
    } catch (Exception $e) {
        error_log("SKU barcode generation failed: " . $e->getMessage());
        return null;
    }
}

function createSKUBarcodeFallback(string $sku, string $filePath): ?string {
    try {
        $width = 200;  // Standard barcode width
        $height = 50;  // Standard barcode height
        
        $image = imagecreate($width, $height);
        if (!$image) return null;
        
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        
        imagefill($image, 0, 0, $white);
        
        // Create Code 39-style barcode pattern for SKU
        $barWidth = 2;
        $barSpacing = 1;
        $currentX = 10;
        
        // Start pattern
        for ($i = 0; $i < 3; $i++) {
            imagefilledrectangle($image, $currentX, 5, $currentX + $barWidth, 30, $black);
            $currentX += $barWidth + $barSpacing;
        }
        
        // SKU data bars - simplified encoding
        for ($i = 0; $i < strlen($sku); $i++) {
            $char = $sku[$i];
            $ascii = ord($char);
            
            // Create pattern based on character
            for ($j = 0; $j < 4; $j++) {
                if (($ascii + $j) % 2 == 0) {
                    imagefilledrectangle($image, $currentX, 5, $currentX + $barWidth, 30, $black);
                }
                $currentX += $barWidth + $barSpacing;
            }
            $currentX += $barSpacing; // Extra space between characters
        }
        
        // End pattern
        for ($i = 0; $i < 3; $i++) {
            imagefilledrectangle($image, $currentX, 5, $currentX + $barWidth, 30, $black);
            $currentX += $barWidth + $barSpacing;
        }
        
        // Add SKU text below barcode
        $font = 3;
        $textX = ($width - strlen($sku) * imagefontwidth($font)) / 2;
        imagestring($image, $font, $textX, 35, $sku, $black);
        
        imagepng($image, $filePath);
        imagedestroy($image);
        
        return $filePath;
        
    } catch (Exception $e) {
        error_log("SKU barcode fallback failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Rotate an image file by specified degrees
 */
function rotateImageFile(string $imagePath, int $degrees): ?string {
    try {
        // Create image from file
        $image = imagecreatefrompng($imagePath);
        if (!$image) {
            error_log("Failed to load image for rotation: $imagePath");
            return null;
        }
        
        // Rotate the image
        $rotatedImage = imagerotate($image, -$degrees, 0); // Negative for clockwise rotation
        if (!$rotatedImage) {
            error_log("Failed to rotate image");
            imagedestroy($image);
            return null;
        }
        
        // Create rotated file path
        $rotatedPath = str_replace('.png', '_rotated.png', $imagePath);
        
        // Save rotated image
        if (!imagepng($rotatedImage, $rotatedPath)) {
            error_log("Failed to save rotated image");
            imagedestroy($image);
            imagedestroy($rotatedImage);
            return null;
        }
        
        // Clean up
        imagedestroy($image);
        imagedestroy($rotatedImage);
        
        return $rotatedPath;
        
    } catch (Exception $e) {
        error_log("Image rotation failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Create tracking text as an image (for rotation)
 */
function createTrackingTextImage(string $batch, int $qty, string $date): ?string {
    try {
        $tempDir = BASE_PATH . '/storage/temp';
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        
        $textImagePath = $tempDir . '/tracking_text_' . time() . '.png';
        
        $width = 120;  // Text image width
        $height = 35;  // Text image height
        
        // Create image with white background (not transparent)
        $image = imagecreate($width, $height);
        if (!$image) return null;
        
        // Create colors
        $white = imagecolorallocate($image, 255, 255, 255); // White background
        $black = imagecolorallocate($image, 0, 0, 0);       // Black text
        
        // Fill with white background
        imagefill($image, 0, 0, $white);
        
        // Set font and create text lines
        $font = 2; // Built-in font size
        $lineHeight = 10;
        $startY = 2;
        
        // Draw text lines
        $lines = [];
        if ($batch) {
            $lines[] = 'LOT: ' . $batch;
        }
        $lines[] = 'CANTITATE: ' . $qty . ' buc';
        $lines[] = 'DATA: ' . date('d.m.Y H:i', strtotime($date));
        
        $currentY = $startY;
        foreach ($lines as $line) {
            imagestring($image, $font, 2, $currentY, $line, $black);
            $currentY += $lineHeight;
        }
        
        // Save the image
        if (!imagepng($image, $textImagePath)) {
            imagedestroy($image);
            return null;
        }
        
        imagedestroy($image);
        return $textImagePath;
        
    } catch (Exception $e) {
        error_log("Text image creation failed: " . $e->getMessage());
        return null;
    }
}

function findProductTemplate(string $sku, string $productName): ?string {
    $templateDir = BASE_PATH . '/storage/templates/product_labels/';
    
    // Strategy 1: Extract product code from product name (PRIMARY METHOD)
    $templatePath = extractProductCodeTemplate($productName, $templateDir);
    if ($templatePath && file_exists($templatePath)) {
        return $templatePath;
    }
    
    // Strategy 2: Generic template (fallback)
    $templatePath = $templateDir . 'generic_template.png';
    if (file_exists($templatePath)) {
        return $templatePath;
    }
    
    return null; // No template found
}

function extractProductCodeTemplate(string $productName, string $templateDir): ?string {
    error_log("Searching template for product: $productName");
    
    // Method 1: Look for LILLIOS specifically (your example)
    if (stripos($productName, 'LILLIOS') !== false) {
        if (preg_match('/(\d+)/', $productName, $matches)) {
            $number = $matches[1];
            $templatePath = $templateDir . 'LILLIOS-' . $number . '.png';
            error_log("Trying LILLIOS pattern: $templatePath");
            return $templatePath;
        }
    }
    
    // Method 2: Extract brand name and number from product name
    // Looks for: BRAND + number pattern
    if (preg_match('/\b([A-Z][A-Z\-]*[A-Z])\b.*?(\d+)/i', $productName, $matches)) {
        $brand = strtoupper($matches[1]);
        $number = $matches[2];
        $templatePath = $templateDir . $brand . '-' . $number . '.png';
        error_log("Trying brand-number pattern: $templatePath");
        return $templatePath;
    }
    
    // Method 3: Look for common product patterns with numbers
    // Pattern: "WORD-NUMBER" or "WORD NUMBER"
    if (preg_match('/\b([A-Z]+(?:\-[A-Z]+)?)\s*[\-\s]*(\d+)/i', $productName, $matches)) {
        $brand = strtoupper($matches[1]);
        $number = $matches[2];
        $templatePath = $templateDir . $brand . '-' . $number . '.png';
        error_log("Trying word-number pattern: $templatePath");
        return $templatePath;
    }
    
    // Method 4: Extract from specific brand keywords (customize for your brands)
    $brandPatterns = [
        '/\bLILLIOS\b/i' => 'LILLIOS',
        '/\bCURATATOR\b/i' => 'CURATATOR', 
        '/\bCLEAN[\-\s]?PRO\b/i' => 'CLEAN-PRO',
        '/\bDETERGENT\b/i' => 'DETERGENT',
        '/\bUNIVERSAL\b/i' => 'UNIVERSAL'
    ];
    
    foreach ($brandPatterns as $pattern => $brand) {
        if (preg_match($pattern, $productName)) {
            // Find any number in the product name
            if (preg_match('/(\d+)/', $productName, $matches)) {
                $number = $matches[1];
                $templatePath = $templateDir . $brand . '-' . $number . '.png';
                error_log("Trying brand pattern ($brand): $templatePath");
                return $templatePath;
            }
        }
    }
    
    error_log("No template pattern matched for: $productName");
    return null;
}

function sendToPrintServer(string $pdfUrl, ?string $printer, array $config): void {
    $printerName = $printer ?: ($config['default_printer'] ?? 'godex');
    $printServerUrl = $config['print_server_url'] ?? 'http://86.124.196.102:3000/print_server.php';
    $result = sendPdfToServer($printServerUrl, $pdfUrl, $printerName);
    if (!$result['success']) {
        error_log('Label print failed: ' . $result['error']);
    }
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
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $scheme . '://' . $host . $path;
}
?>