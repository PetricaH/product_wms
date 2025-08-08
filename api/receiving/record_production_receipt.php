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

    $labelUrl = null;
    if ($doPrint) {
        // Generate label with proper printer dimensions and rotation
        $labelUrl = generateCombinedTemplateLabel($db, $productId, $quantity, $batchNumber, $producedAt);
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
 * Combined Template Label Generator for Godex Printer - EDGE POSITIONED
 * Creates a PNG with proper printer dimensions (100mm x 150mm) and 180° rotation
 * Elements positioned closer to the edge of the canvas
 */
function generateCombinedTemplateLabel(PDO $db, int $productId, int $qty, string $batch, string $date): ?string {
    // ───────────── USER-TWEAKABLE SETTINGS - UPDATED FOR EDGE POSITIONING ─────────────
    // QR code rendered without rotation
    $elementRotation    = 0; // no rotation needed for QR
    // Position QR code in bottom-left corner
    $positionStyle      = 'center';
    // REDUCED margins to move everything closer to edge
    $marginX            = 10;     // Reduced from 50 to 10 - margin from left edge
    $marginY            = 10;     // Reduced from 20 to 10 - margin from bottom edge
    // Additional X-offset for positioning
    $barcodeOffsetXPercent = 0; // No offset needed

    // REDUCED spacing between elements to pack them tighter
    $marginBelowBarcode = 5;      // Reduced from 10 to 5 - spacing between barcode and text block
    $elementSpacing     = 8;      // Space between barcode, SKU text, and other text (reduced from 20 to 8)
    $textLineSpacing    = 8;      // Reduced from 10 to 8 - spacing between vertical text lines
    // Line spacing for text
    $lineHeight         = 25;     // px
    // Built-in font size for imagestring (1-5)
    $fontSize           = 5;
    // Text/barcode color
    $colorBlack         = [0, 0, 0];

    $globalShiftX = -10;   // ~7.5 mm la 203 DPI
    $globalShiftY = 0;
    // ───────────── END SETTINGS ─────────────

    // --- Load template or fallback transparent X ---
    $productModel = new Product($db);
    $product      = $productModel->findById($productId);
    if (!$product) {
        error_log("Product not found: {$productId}");
        return null;
    }
    
    $sku          = $product['sku'] ?? 'N/A';
    $templatePath = findProductTemplate($sku, $product['name'] ?? '');
    
    if ($templatePath && file_exists($templatePath)) {
        $image = imagecreatefrompng($templatePath);
        if (!$image) {
            error_log("Failed loading template: {$templatePath}");
            return null;
        }
        
        // Resize template to match printer dimensions
        // 100mm x 150mm at 203 DPI = 800 x 1200 pixels
        $targetWidth = 800;  // 100mm at 203 DPI
        $targetHeight = 1200; // 150mm at 203 DPI
        
        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);
        
        // Create new image with target dimensions
        $resizedImage = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($resizedImage, false);
        imagesavealpha($resizedImage, true);
        
        // Scale template to fit new dimensions
        imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, 
                          $targetWidth, $targetHeight, $originalWidth, $originalHeight);
        
        imagedestroy($image);
        $image = $resizedImage;
        
        error_log("✅ Template resized from {$originalWidth}x{$originalHeight} to {$targetWidth}x{$targetHeight}");
    } else {
        // Fallback size for printer: 100mm x 150mm at 203 DPI = 800 x 1200 pixels
        $w = 800;  // 100mm width
        $h = 1200; // 150mm height
        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        error_log("⚠️ Using fallback canvas for printer: {$w}x{$h} (100mm x 150mm at 203 DPI)");
    }

    if (function_exists('imageresolution')) {
        imageresolution($image, 203, 203);
    }

    // --- Prepare for overlays ---
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagealphablending($image, true);
    $iw = imagesx($image);
    $ih = imagesy($image);
    
    error_log("Working with image dimensions: {$iw}x{$ih} " . ($ih > $iw ? "(Portrait ✅)" : "(Landscape ⚠️)"));

    // --- Generate QR code (encodes only the SKU) ---
    $barcodeImage = generateQRCodeImage($sku);
    if ($barcodeImage) {
        // Rotate barcode 90 degrees clockwise to make it vertical
        if ($elementRotation !== 0) {
            $trans = imagecolorallocatealpha($barcodeImage, 0, 0, 0, 127);
            $rotImg = imagerotate($barcodeImage, -$elementRotation, $trans); // Negative for clockwise
            if ($rotImg) {
                imagedestroy($barcodeImage);
                $barcodeImage = $rotImg;
            }
        }
        
        $bw = imagesx($barcodeImage);
        $bh = imagesy($barcodeImage);

        // Compute barcode position - bottom-left corner with REDUCED margins
        switch ($positionStyle) {
            case 'bottom-right':
                $bx = $iw - $bw - $marginX; // Right edge - barcode width - margin
                $by = $ih - $bh - $marginY; // Bottom edge - barcode height - margin
                break;
            case 'bottom-left':
                $bx = $marginX; // Left edge + margin (now only 10px)
                $by = $ih - $bh - $marginY; // Bottom edge - barcode height - margin (now only 10px)
                break;
            case 'top-right':
                $bx = $iw - $bw - $marginX;
                $bx -= (int)($iw * $barcodeOffsetXPercent);
                $by = $marginY;
                break;
            case 'center':
                $bx = (int)(($iw - $bw) / 2);
                $by = (int)(($ih - $bh) / 2);
                break;
            default: // custom: marginX from left, marginY from top
                $bx = $marginX;
                $by = $marginY;
        }
        
        // Ensure barcode stays within bounds
        $bx = max(0, min($bx, $iw - $bw));
        $by = max(0, min($by, $ih - $bh));
        
        imagecopy($image, $barcodeImage, $bx, $by, 0, 0, $bw, $bh);
        imagedestroy($barcodeImage);
        
        // Add vertical SKU text next to the vertical barcode with REDUCED spacing
        $skuColor = imagecolorallocate($image, 0, 0, 0);
        $skuFont = 3;
        $skuText = $sku;
        
        // Create a small image for the SKU text and rotate it
        $skuTextWidth = strlen($skuText) * imagefontwidth($skuFont);
        $skuTextHeight = imagefontheight($skuFont);
        
        $skuTextImage = imagecreatetruecolor($skuTextWidth + 10, $skuTextHeight + 10);
        imagealphablending($skuTextImage, false);
        imagesavealpha($skuTextImage, true);
        $transparent = imagecolorallocatealpha($skuTextImage, 0, 0, 0, 127);
        imagefill($skuTextImage, 0, 0, $transparent);
        imagealphablending($skuTextImage, true);
        
        $skuBlack = imagecolorallocate($skuTextImage, 0, 0, 0);
        imagestring($skuTextImage, $skuFont, 5, 5, $skuText, $skuBlack);
        
        // Rotate SKU text 90 degrees clockwise
        $rotatedSkuText = imagerotate($skuTextImage, -90, $transparent);
        imagedestroy($skuTextImage);
        
        // Position rotated SKU text to the right of the barcode with REDUCED gap
        $rotSkuW = imagesx($rotatedSkuText);
        $rotSkuH = imagesy($rotatedSkuText);
        $skuX = $bx + $bw + $elementSpacing; // Reduced gap (now 8px instead of 10px)
        $skuY = $by + (int)(($bh - $rotSkuH) / 2); // Center vertically with barcode

        // Ensure SKU text stays within bounds
        $skuX = max(0, min($skuX, $iw - $rotSkuW));
        $skuY = max(0, min($skuY, $ih - $rotSkuH));

        imagecopy($image, $rotatedSkuText, $skuX, $skuY, 0, 0, $rotSkuW, $rotSkuH);
        imagedestroy($rotatedSkuText);

        // Position other text to the right of the SKU text with REDUCED spacing
        $textStartX = $skuX + $rotSkuW + $elementSpacing; // Reduced space from SKU text (now 8px instead of 20px)
        $textStartY = $by; // Align with top of barcode
        error_log("EDGE-POSITIONED: Vertical barcode placed at: {$bx}, {$by} ({$bw}x{$bh})");
        error_log("EDGE-POSITIONED: Vertical SKU text placed at: {$skuX}, {$skuY}");
        error_log("EDGE-POSITIONED: Text starting at: {$textStartX}, {$textStartY}");
    } else {
        // If no barcode, position text closer to left edge
        $textStartX = $marginX + 50; // Start close to left edge
        $textStartY = $ih - 200; // Near bottom
        error_log("EDGE-POSITIONED: No barcode, text starting at: {$textStartX}, {$textStartY}");
    }

    // --- Prepare text lines ---
    $lines = [];
    if ($batch) {
        $lines[] = "LOT: {$batch}";
    }
    $lines[] = "CANTITATE: {$qty} buc";
    $lines[] = "DATA: " . date('d.m.Y H:i', strtotime($date));
    
    [$r, $g, $b] = $colorBlack;
    $color = imagecolorallocate($image, $r, $g, $b);

    // --- Create and position vertical text lines with REDUCED spacing ---
    $currentX = $textStartX;
    foreach ($lines as $line) {
        // Create a small image for each text line
        $textWidth = strlen($line) * imagefontwidth($fontSize);
        $textHeight = imagefontheight($fontSize);

        $textImage = imagecreatetruecolor($textWidth + 10, $textHeight + 10);
        imagealphablending($textImage, false);
        imagesavealpha($textImage, true);
        $transparent = imagecolorallocatealpha($textImage, 0, 0, 0, 127);
        imagefill($textImage, 0, 0, $transparent);
        imagealphablending($textImage, true);

        $textBlack = imagecolorallocate($textImage, 0, 0, 0);
        imagestring($textImage, $fontSize, 5, 5, $line, $textBlack);

        // Rotate text 90 degrees clockwise to match barcode
        $rotatedText = imagerotate($textImage, -90, $transparent);
        imagedestroy($textImage);

        // Position rotated text
        $rotTextW = imagesx($rotatedText);
        $rotTextH = imagesy($rotatedText);

        $tx = $currentX; // Move right for each text line
        $ty = $textStartY; // Align with barcode

        // Ensure text stays within bounds
        $tx = max(0, min($tx, $iw - $rotTextW));
        $ty = max(0, min($ty, $ih - $rotTextH));

        // Draw vertical text
        imagecopy($image, $rotatedText, $tx, $ty, 0, 0, $rotTextW, $rotTextH);
        imagedestroy($rotatedText);

        error_log("EDGE-POSITIONED: Vertical text line '{$line}' placed at: {$tx}, {$ty} ({$rotTextW}x{$rotTextH})");

        // Move to the right for next text line with REDUCED spacing
        $currentX = $tx + $rotTextW + $textLineSpacing; // Reduced spacing between vertical text lines (now 8px instead of 10px)

        // If we run out of horizontal space, break
        if ($currentX > $iw - 30) break; // Reduced margin check (was 50px, now 30px)
    }

    // --- Finalize transparency ---
    imagesavealpha($image, true);

    // --- Rotate entire label 180 degrees for printer orientation ---
    error_log("Rotating label 180 degrees for printer...");
    $transparent180 = imagecolorallocatealpha($image, 0, 0, 0, 127);
    $rotated180 = imagerotate($image, 180, $transparent180);
    
    if ($rotated180) {
        imagedestroy($image);
        $image = $rotated180;
        imagealphablending($image, false);
        imagesavealpha($image, true);
        error_log("✅ Label rotated 180 degrees successfully - EDGE POSITIONED");
    } else {
        error_log("❌ Failed to rotate label 180 degrees");
    }

    // --- Final dimensions check ---
    $finalWidth = imagesx($image);
    $finalHeight = imagesy($image);
    error_log("Final label: {$finalWidth}x{$finalHeight} (100mm x 150mm at 203 DPI, rotated 180°) - EDGE POSITIONED");

    // --- Ensure canvas matches printer size ---
    $expectedW = 800;  // 100mm at 203 DPI
    $expectedH = 1200; // 150mm at 203 DPI
    if ($finalWidth !== $expectedW || $finalHeight !== $expectedH) {
        $normalized = imagecreatetruecolor($expectedW, $expectedH);
        imagealphablending($normalized, false);
        imagesavealpha($normalized, true);
        $transparent = imagecolorallocatealpha($normalized, 0, 0, 0, 127);
        imagefill($normalized, 0, 0, $transparent);
        imagecopyresampled($normalized, $image, 0, 0, 0, 0,
                           $expectedW, $expectedH, $finalWidth, $finalHeight);
        imagedestroy($image);
        $image = $normalized;
        if (function_exists('imageresolution')) {
            imageresolution($image, 203, 203);
        }
        $finalWidth = imagesx($image);
        $finalHeight = imagesy($image);
        error_log("🔧 Canvas normalized to {$finalWidth}x{$finalHeight} for 100mm x 150mm label - EDGE POSITIONED");
    }

    if ($globalShiftX !== 0 || $globalShiftY !== 0) {
    $shifted = imagecreatetruecolor($expectedW, $expectedH);
    imagealphablending($shifted, false);
    imagesavealpha($shifted, true);
    $transparent = imagecolorallocatealpha($shifted, 0, 0, 0, 127);
    imagefill($shifted, 0, 0, $transparent);
    // Atenție: dest_x/dest_y pot fi negative – asta „taie” dacă depășește muchia
    imagecopy($shifted, $image, (int)$globalShiftX, (int)$globalShiftY, 0, 0, $expectedW, $expectedH);
    imagedestroy($image);
    $image = $shifted;
    error_log("↔️ Applied global shift: X={$globalShiftX}px, Y={$globalShiftY}px");
}

    // --- Save and return URL ---
    $dir = BASE_PATH . '/storage/label_pngs';
    if (!file_exists($dir)) mkdir($dir, 0777, true);
    
    $fileName = 'combined_template_label_edge_' . time() . '_' . $batch . '.png';
    $filePath = "{$dir}/{$fileName}";
    
    if (!imagepng($image, $filePath, 0)) {
        error_log("Failed to save PNG: {$filePath}");
        imagedestroy($image);
        return null;
    }
    
    imagedestroy($image);
    error_log("✅ EDGE-POSITIONED Label saved: {$filePath}");

    return rtrim(getBaseUrl(), '/') . '/storage/label_pngs/' . $fileName;
}

/**
 * Generate a QR code PNG as GD image resource
 * The QR code encodes only the SKU value
 */
function generateQRCodeImage(string $sku): ?GdImage {
    try {
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($sku);
        $qrData = @file_get_contents($qrUrl);
        if ($qrData === false) {
            return null;
        }
        $image = imagecreatefromstring($qrData);
        if (!$image) {
            return null;
        }
        imagealphablending($image, true);
        imagesavealpha($image, true);
        return $image;
    } catch (Exception $e) {
        error_log("QR code generation failed: " . $e->getMessage());
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
    // Return root URL instead of API path
    return $scheme . '://' . $host;
}
?>