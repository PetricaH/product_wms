<?php
ob_start();
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/bootstrap.php';

if (file_exists(BASE_PATH . '/vendor/autoload.php')) {
    require_once BASE_PATH . '/vendor/autoload.php';
}
if (!class_exists('FPDF') && file_exists(BASE_PATH . '/lib/fpdf.php')) {
    require_once BASE_PATH . '/lib/fpdf.php';
}

function respond($data, int $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$allowedRoles = ['admin', 'warehouse', 'warehouse_worker'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    respond(['success' => false, 'error' => 'Access denied'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Method not allowed'], 405);
}

// Get and validate parameters
$code = trim($_POST['location_code'] ?? '');
$name = trim($_POST['location_name'] ?? '');
$printerId = intval($_POST['printer_id'] ?? 0);

// Debug logging to identify the WMS_API_KEY issue
error_log("Print QR Debug - Code: " . $code . ", Name: " . $name . ", POST data: " . print_r($_POST, true));

if ($code === '') {
    respond(['success' => false, 'error' => 'Missing location code'], 400);
}

// If name is empty or contains WMS_API_KEY, use the code as fallback
if ($name === '' || strpos($name, 'WMS_API_KEY') !== false) {
    $name = $code;
}

try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();

    // Determine printer
    if ($printerId) {
        $stmt = $db->prepare('
            SELECT p.*, ps.ip_address, ps.port, ps.is_active as server_active
            FROM printers p
            JOIN print_servers ps ON p.print_server_id = ps.id
            WHERE p.id = ? AND p.is_active = 1
        ');
        $stmt->execute([$printerId]);
    } else {
        $stmt = $db->prepare('
            SELECT p.*, ps.ip_address, ps.port, ps.is_active as server_active
            FROM printers p
            JOIN print_servers ps ON p.print_server_id = ps.id
            WHERE p.is_default = 1
              AND (p.printer_type = "label" OR p.printer_type = "universal")
              AND p.is_active = 1
            LIMIT 1
        ');
        $stmt->execute();
    }

    $printer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$printer) {
        respond(['success' => false, 'error' => 'No suitable printer found'], 404);
    }
    if (!$printer['server_active']) {
        respond(['success' => false, 'error' => 'Print server is not active'], 503);
    }

    // Generate QR image (high quality)
    $qrPath = generateTempQRImage($code);
    if (!$qrPath) {
        respond(['success' => false, 'error' => 'Failed to generate QR code'], 500);
    }

    // Create PDF label using the WHOLE paper size so QR/text can be large
    $storageDir = BASE_PATH . '/storage/location_qr_pdfs';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }
    $fileName = 'location_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $code) . '_' . time() . '.pdf';
    $filePath = $storageDir . '/' . $fileName;

    // ==== PHYSICAL PAPER SIZE (portrait feed) ====
    // Your sheet is 10.3 cm x 14.5 cm -> 103 x 145 mm (portrait)
    $outerW = 103.0; // width in mm
    $outerH = 145.0; // height in mm

    $pdf = new FPDF('P', 'mm', [$outerW, $outerH]);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    // ---- Adjustable layout knobs ----
    $margin   = 8.0;   // outer margin (all sides)
    $textBand = 18.0;  // height reserved at the bottom for the location text
    $gap      = 2.0;   // gap between QR and text band

    // Work area above the text band
    $workTop    = $margin;
    $workLeft   = $margin;
    $workWidth  = $outerW - 2 * $margin;
    $workHeight = $outerH - 2 * $margin - $textBand;

    // Biggest square QR that fits
    $qrSize = min($workWidth, $workHeight - $gap);

    // Center the QR horizontally at the top of the work area
    $qrX = $workLeft + ($workWidth - $qrSize) / 2.0;
    $qrY = $workTop;

    // Add QR
    $pdf->Image($qrPath, $qrX, $qrY, $qrSize, $qrSize, 'PNG');
    @unlink($qrPath);

    // ==== Bottom text band ====
    $labelText = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $code);
    $bandX = $margin;
    $bandY = $outerH - $margin - $textBand;
    $bandW = $outerW - 2 * $margin;

    // Find the largest font size that fits the band width/height
    $minFont = 8;
    $maxFont = max($minFont, (int)floor($textBand * 1.6)); // heuristic cap
    $bestSize = $minFont;

    for ($fs = $minFont; $fs <= $maxFont; $fs++) {
        $pdf->SetFont('Arial', 'B', $fs);
        $tw = $pdf->GetStringWidth($labelText);
        if ($tw > $bandW) { // too wide—use previous size
            $bestSize = max($minFont, $fs - 1);
            break;
        }
        $bestSize = $fs;
    }
    $pdf->SetFont('Arial', 'B', $bestSize);

    // If still too long, trim to first segment (optional safety)
    $tw = $pdf->GetStringWidth($labelText);
    if ($tw > $bandW) {
        $parts = preg_split('/[-_\s]/', $labelText, 2);
        if (count($parts) > 1 && strlen($parts[0]) > 2) {
            $labelText = $parts[0];
        }
    }

    // Print centered in the band
    $pdf->SetXY($bandX, $bandY);
    $pdf->Cell($bandW, $textBand, $labelText, 0, 1, 'C');

    // Optional debug border for the text band:
    // $pdf->SetDrawColor(220,220,220);
    // $pdf->Rect($bandX, $bandY, $bandW, $textBand, 'D');

    $pdf->Output('F', $filePath);

    // Build URL and send to print server
    $pdfUrl = getSimpleBaseUrl() . '/storage/location_qr_pdfs/' . $fileName;

    $printServerUrl = "http://{$printer['ip_address']}:{$printer['port']}/print_server.php";
    $result = sendToPrintServerSimple($printServerUrl, $pdfUrl, $printer['network_identifier']);

    if ($result['success']) {
        respond(['success' => true, 'message' => 'QR sent to printer']);
    }
    respond(['success' => false, 'error' => $result['error'] ?? 'Print server error'], 500);

} catch (Throwable $e) {
    error_log('Location QR print error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Internal server error'], 500);
}

function generateTempQRImage(string $data): ?string {
    // Generate larger QR code (high quality)
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=800x800&data=' . urlencode($data);
    $qrData = @file_get_contents($qrUrl);
    if ($qrData === false) return null;
    $tempDir = sys_get_temp_dir();
    $tempFile = $tempDir . '/qr_' . uniqid() . '.png';
    file_put_contents($tempFile, $qrData);
    return $tempFile;
}

function sendToPrintServerSimple(string $printServerUrl, string $pdfUrl, string $printerName): array {
    $url = $printServerUrl . '?' . http_build_query([
        'url' => $pdfUrl,
        'printer' => $printerName
        // Tip (print server side): ensure SumatraPDF uses -print-settings "portrait,center,noscale"
    ]);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return ['success' => false, 'error' => 'Failed to connect to print server'];
    }
    if (strpos($response, 'Trimis la imprimantă') !== false ||
        strpos($response, 'sent to printer') !== false ||
        strpos($response, 'Print successful') !== false) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Print server returned: ' . $response];
}

function getSimpleBaseUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}
?>
