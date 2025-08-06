<?php
/**
 * API: Record Production Receipt and Print Labels
 * UPDATED: Uses PNG transparent labels with rotation support
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
    if ($doAddStock) {
        // Handle location_id conversion (string location_code to integer id)
        if ($locationInput) {
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
    }

    $labelUrl = null;
    if ($doPrint) {
        // Get rotation from config (default 90 degrees for most Godex printers)
        $rotation = $config['label_rotation'] ?? 90;
        $labelUrl = generateCombinedTemplateLabel($db, $productId, $quantity, $batchNumber, $producedAt, $rotation);
        if ($labelUrl) {
            $headers = @get_headers($labelUrl);
            if ($headers && strpos($headers[0], '200') !== false) {
                sendToPrintServer($labelUrl, $printer, $config);
                error_log("Production Receipt Debug - PNG label generated: $labelUrl");
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
        'message' => $doAddStock ? 'Production receipt recorded successfully' : 'Labels printed successfully',
        'saved_photos' => $doAddStock ? $savedPhotos : [],
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
 * Creates a PNG overlay with transparent background
 * @param PDO $db Database connection
 * @param int $productId Product ID
 * @param int $qty Quantity
 * @param string $batch Batch number
 * @param string $date Production date
 * @param int $rotation Rotation angle: 0, 90, 180, or 270 degrees
 */
function generateCombinedTemplateLabel(PDO $db, int $productId, int $qty, string $batch, string $date, int $rotation = 90): ?string {
    $productModel = new Product($db);
    $product = $productModel->findById($productId);
    if (!$product) {
        error_log("Product not found for label generation: " . $productId);
        return null;
    }

    // Label dimensions for Godex printer (convert mm to pixels at 203 DPI)
    // 101.6mm = 813px, 152.4mm = 1220px at 203 DPI
    $labelWidth = 813;   // pixels
    $labelHeight = 1220; // pixels

    $fileName = 'combined_template_label_' . time() . '_' . $batch . '.png';
    $dir = BASE_PATH . '/storage/label_pngs';
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    $filePath = $dir . '/' . $fileName;

    try {
        // Get product information first
        $sku = $product['sku'] ?? 'N/A';
        $productName = $product['name'] ?? 'Unknown Product';
        
        // ===== FIRST: LOAD EXISTING PNG TEMPLATE AS BASE =====
        $templatePath = findProductTemplate($sku, $productName);
        $baseImage = null;
        
        if ($templatePath && file_exists($templatePath)) {
            $baseImage = imagecreatefrompng($templatePath);
            if ($baseImage) {
                // Use template dimensions instead of default dimensions
                $labelWidth = imagesx($baseImage);
                $labelHeight = imagesy($baseImage);
                error_log("SUCCESS: Loaded template: $templatePath (${labelWidth}x${labelHeight})");
            } else {
                error_log("ERROR: Failed to load template image: $templatePath");
            }
        } else {
            error_log("WARNING: No template found for SKU: $sku, Product: $productName");
        }
        
        // Create final image - either from template or blank
        if ($baseImage) {
            // Use the loaded template as the base
            $image = $baseImage;
            $labelWidth = imagesx($image);
            $labelHeight = imagesy($image);
            
            // Ensure we can add new elements
            imagealphablending($image, true);
            imagesavealpha($image, true);
        } else {
            // Fallback: Create blank transparent image
            $labelWidth = 813;   // Default pixels
            $labelHeight = 1220; // Default pixels
            
            $image = imagecreatetruecolor($labelWidth, $labelHeight);
            
            // Enable transparency
            imagealphablending($image, false);
            imagesavealpha($image, true);
            
            // Create transparent background
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagefill($image, 0, 0, $transparent);
            
            // Enable alpha blending for adding elements
            imagealphablending($image, true);
        }
        
        // Set up colors for text (work with both template and transparent background)
        $black = imagecolorallocate($image, 0, 0, 0);
        
        // ===== OVERLAY BARCODE AND TEXT ON THE TEMPLATE =====
        // Generate and add barcode
        $barcodeImage = generateBarcodeImageGD($sku, $batch);
        if ($barcodeImage) {
            // Position barcode (adjust coordinates based on your label design)
            // These coordinates should be positioned to not overlap important template elements
            $barcodeX = (int)(($labelWidth - imagesx($barcodeImage)) / 2);  // Center horizontally
            $barcodeY = (int)($labelHeight * 0.4); // Position in middle area of label
            
            // Ensure barcode doesn't go off the label
            if ($barcodeX + imagesx($barcodeImage) > $labelWidth) {
                $barcodeX = $labelWidth - imagesx($barcodeImage) - 10;
            }
            if ($barcodeY + imagesy($barcodeImage) > $labelHeight) {
                $barcodeY = $labelHeight - imagesy($barcodeImage) - 10;
            }
            
            imagecopy($image, $barcodeImage, $barcodeX, $barcodeY, 0, 0, 
                     imagesx($barcodeImage), imagesy($barcodeImage));
            imagedestroy($barcodeImage);
            
            error_log("Barcode positioned at: ${barcodeX}, ${barcodeY}");
        }
        
        // Add text information positioned to avoid template content
        $font = 5; // Built-in font size
        $lineHeight = 25; // Reduced line height for better fitting
        
        // Position text in the bottom portion of the label to avoid template content
        $textStartY = (int)($labelHeight * 0.7); // Start at 70% down the label
        
        // Only add essential tracking information (not product name/SKU since template has it)
        $currentY = $textStartY;
        
        // Batch number
        if ($batch) {
            $batchText = "LOT: " . $batch;
            $textX = (int)(($labelWidth - strlen($batchText) * imagefontwidth($font)) / 2);
            imagestring($image, $font, $textX, $currentY, $batchText, $black);
            $currentY += $lineHeight;
        }
        
        // Quantity
        $qtyText = "CANTITATE: " . $qty . " buc";
        $textX = (int)(($labelWidth - strlen($qtyText) * imagefontwidth($font)) / 2);
        imagestring($image, $font, $textX, $currentY, $qtyText, $black);
        $currentY += $lineHeight;
        
        // Date
        $dateText = "DATA: " . date('d.m.Y H:i', strtotime($date));
        $textX = (int)(($labelWidth - strlen($dateText) * imagefontwidth($font)) / 2);
        imagestring($image, $font, $textX, $currentY, $dateText, $black);
        
        // Rotate the image if needed
        if ($rotation != 0) {
            // For template images, use white background for rotation instead of transparent
            $rotationBg = $baseImage ? imagecolorallocate($image, 255, 255, 255) : imagecolorallocatealpha($image, 0, 0, 0, 127);
            $rotatedImage = imagerotate($image, $rotation, $rotationBg);
            
            if ($rotatedImage !== false) {
                imagedestroy($image);
                $image = $rotatedImage;
                
                // Preserve transparency after rotation only if we started with transparent
                if (!$baseImage) {
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                }
            }
        }
        
        // Save as PNG
        imagepng($image, $filePath);
        imagedestroy($image);
        
        // Debug logging
        error_log("PNG label saved to: " . $filePath);
        error_log("Template used: " . ($templatePath ? $templatePath : 'None - blank label'));
        error_log("Final dimensions: ${labelWidth}x${labelHeight}");
        
        $baseUrl = rtrim(getBaseUrl(), '/');
        return $baseUrl . '/storage/label_pngs/' . $fileName;
        
    } catch (Exception $e) {
        error_log("Error generating transparent PNG label: " . $e->getMessage());
        return null;
    }
}

/**
 * Generate barcode as GD image resource (for PNG labels)
 */
function generateBarcodeImageGD(string $sku, string $batch): ?GdImage {
    try {
        // Simple barcode generation using GD
        $width = 400;
        $height = 100;
        $barcodeImage = imagecreatetruecolor($width, $height);
        
        // Transparent background
        imagealphablending($barcodeImage, false);
        imagesavealpha($barcodeImage, true);
        $transparent = imagecolorallocatealpha($barcodeImage, 0, 0, 0, 127);
        imagefill($barcodeImage, 0, 0, $transparent);
        
        // Colors
        imagealphablending($barcodeImage, true);
        $black = imagecolorallocate($barcodeImage, 0, 0, 0);
        
        // Create simple barcode pattern
        $barWidth = 3;
        $barSpacing = 2;
        $currentX = 20;
        
        // Generate bars based on SKU characters
        for ($i = 0; $i < strlen($sku); $i++) {
            $char = $sku[$i];
            $ascii = ord($char);
            
            // Create pattern based on character
            for ($j = 0; $j < 6; $j++) {
                if (($ascii + $j) % 2 == 0) {
                    imagefilledrectangle($barcodeImage, $currentX, 10, $currentX + $barWidth, 60, $black);
                }
                $currentX += $barWidth + $barSpacing;
                
                if ($currentX > $width - 40) break; // Don't exceed image width
            }
        }
        
        // Add SKU text below barcode
        $font = 3;
        $textX = (int)(($width - strlen($sku) * imagefontwidth($font)) / 2);
        imagestring($barcodeImage, $font, $textX, 70, $sku, $black);
        
        return $barcodeImage;
        
    } catch (Exception $e) {
        error_log("GD barcode generation failed: " . $e->getMessage());
        return null;
    }
}

// ===== KEEPING ALL YOUR EXISTING FUNCTIONS BELOW =====

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
        $textX = (int)(($width - strlen($sku) * imagefontwidth($font)) / 2);
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
 * Find the appropriate PNG template for a product based on SKU number only
 * Simple matching: extract number from SKU and find template containing that number
 */
function findProductTemplate(string $sku, string $productName): ?string {
    $templateDir = BASE_PATH . '/storage/templates/product_labels/';
    
    // Extract number from SKU (e.g., "APF906.10" → "906", "806.25" → "806")
    if (preg_match('/(\d+)/', $sku, $matches)) {
        $productCode = $matches[1];
        error_log("Extracted product code '$productCode' from SKU '$sku'");
        
        // Look for any PNG file containing this number
        $availableTemplates = glob($templateDir . '*.png');
        
        foreach ($availableTemplates as $templatePath) {
            $templateName = basename($templatePath, '.png');
            
            // Check if template name contains the product code
            if (strpos($templateName, $productCode) !== false) {
                error_log("✅ FOUND template: $templatePath for product code $productCode");
                return $templatePath;
            }
        }
        
        error_log("❌ No template found containing product code '$productCode'");
    } else {
        error_log("❌ No number found in SKU '$sku'");
    }
    
    // Fallback: Generic template
    $templatePath = $templateDir . 'generic_template.png';
    if (file_exists($templatePath)) {
        error_log("Using generic template: $templatePath");
        return $templatePath;
    }
    
    error_log("No template found for SKU '$sku'");
    return null; // No template found
}

/**
 * Extract product code template from product name
 * Examples:
 * - "AP.-800 CURATATOR UNIVERSAL LILLIOS 25 LITR" -> "LILLIOS-800.png"
 * - "CLEAN-PRO DETERGENT 500 ML" -> "CLEAN-PRO-500.png" 
 * - "CURATATOR UNIVERSAL 250L" -> "CURATATOR-250.png"
 */
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

function sendToPrintServer(string $labelUrl, ?string $printer, array $config): void {
    $printerName = $printer ?: ($config['default_printer'] ?? 'godex');
    $printServerUrl = $config['print_server_url'] ?? 'http://86.124.196.102:3000/print_server.php';
    
    // Determine if it's a PNG or PDF file
    $isPng = strpos($labelUrl, '.png') !== false;
    
    if ($isPng) {
        $result = sendPngToServer($printServerUrl, $labelUrl, $printerName);
    } else {
        $result = sendPdfToServer($printServerUrl, $labelUrl, $printerName);
    }
    
    if (!$result['success']) {
        error_log('Label print failed: ' . $result['error']);
    }
}

function sendPngToServer(string $url, string $pngUrl, string $printer): array {
    $requestUrl = $url . '?' . http_build_query([
        'url' => $pngUrl,
        'printer' => $printer,
        'format' => 'png' // Tell print server it's a PNG file
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
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $scheme . '://' . $host . $path;
}
?>