<?php
// File: api/awb/print_awb.php
// FIXED: Stable AWB printing with Cargus API integration - No more crashes!

ob_start();
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/models/CargusService.php';

function respond($data, int $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Security check
$allowedRoles = ['admin', 'warehouse', 'warehouse_worker'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    respond(['success' => false, 'error' => 'Access denied'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Method not allowed'], 405);
}

// Get parameters - support both 'awb' and 'awb_code' for compatibility
$orderId = intval($_POST['order_id'] ?? 0);
$awbCode = trim($_POST['awb'] ?? $_POST['awb_code'] ?? '');
$printerId = intval($_POST['printer_id'] ?? 0);
$format = $_POST['format'] ?? 'label'; // 'a4' or 'label'

if (!$orderId || !$awbCode || !preg_match('/^\d+$/', $awbCode)) {
    respond(['success' => false, 'error' => 'Invalid parameters'], 400);
}

try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();
    
    // Get order details
    $orderStmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        respond(['success' => false, 'error' => 'Order not found'], 404);
    }
    
    // Verify AWB belongs to this order
    if ($order['awb_barcode'] !== $awbCode) {
        respond(['success' => false, 'error' => 'AWB does not match order'], 400);
    }
    
    // Get printer details - SAME AS TEST PRINT APPROACH
    if ($printerId) {
        $stmt = $db->prepare('
            SELECT p.*, ps.ip_address, ps.port, ps.is_active as server_active
            FROM printers p
            JOIN print_servers ps ON p.print_server_id = ps.id
            WHERE p.id = ? AND p.is_active = 1
        ');
        $stmt->execute([$printerId]);
    } else {
        // Find default AWB printer - SAME AS TEST PRINT LOGIC
        $stmt = $db->prepare('
            SELECT p.*, ps.ip_address, ps.port, ps.is_active as server_active
            FROM printers p
            JOIN print_servers ps ON p.print_server_id = ps.id
            WHERE p.is_default = 1 
            AND (p.printer_type = "awb" OR p.printer_type = "label" OR p.printer_type = "universal")
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
    
    // Get or create AWB PDF using Cargus API
    $awbPdfUrl = getOrCreateAwbPDF($awbCode, $format, $db);
    
    if (!$awbPdfUrl) {
        respond(['success' => false, 'error' => 'Failed to get AWB PDF from Cargus API'], 500);
    }
    
    // Create print job record - SAME AS WORKING INVOICE APPROACH
    $jobId = createPrintJob($db, $orderId, $printer['id'], $printer['print_server_id'] ?? null, $awbPdfUrl);
    
    // Send to print server - EXACT SAME METHOD AS TEST PRINT
    $printServerUrl = "http://{$printer['ip_address']}:{$printer['port']}/print_server.php";
    $printResult = sendToPrintServerSimple($printServerUrl, $awbPdfUrl, $printer['network_identifier']);
    
    // Update print job status
    updatePrintJobStatus($db, $jobId, $printResult['success'], $printResult['error'] ?? null);
    
    if ($printResult['success']) {
        // Update printer last_used timestamp
        $stmt = $db->prepare('UPDATE printers SET last_used = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $printer['id']]);
        
        respond([
            'success' => true,
            'message' => 'AWB sent to printer successfully',
            'job_id' => $jobId,
            'printer' => $printer['name']
        ]);
    } else {
        respond([
            'success' => false,
            'error' => 'Print server error: ' . ($printResult['error'] ?? 'Unknown error'),
            'job_id' => $jobId
        ], 500);
    }
    
} catch (Throwable $e) {
    error_log("AWB print error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    respond(['success' => false, 'error' => 'Internal server error'], 500);
}

// SIMPLE PRINT FUNCTION - SAME AS TEST PRINT
function sendToPrintServerSimple(string $printServerUrl, string $pdfUrl, string $printerName): array {
    // Build URL with parameters - EXACT SAME AS WORKING TEST PRINT
    $url = $printServerUrl . '?' . http_build_query([
        'url' => $pdfUrl,
        'printer' => $printerName
    ]);
    
    error_log("AWB Print URL: " . $url);
    
    // SAME CONTEXT AS TEST PRINT - 10 SECOND TIMEOUT (NOT 15!)
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,  // Same as test print - shorter timeout
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    error_log("AWB Print response: " . ($response ?: 'NO RESPONSE'));
    
    if ($response === false) {
        return ['success' => false, 'error' => 'Failed to connect to print server'];
    }
    
    // SAME SUCCESS CHECK AS TEST PRINT
    if (strpos($response, 'Trimis la imprimantă') !== false || 
        strpos($response, 'sent to printer') !== false ||
        strpos($response, 'Print successful') !== false) {
        return ['success' => true];
    }
    
    return ['success' => false, 'error' => 'Print server returned: ' . $response];
}

function getOrCreateAwbPDF(string $awbCode, string $format, PDO $db): ?string {
    // Check if AWB PDF already exists in PERMANENT storage
    $awbDir = BASE_PATH . '/storage/awb_pdfs/';
    $filename = $awbCode . '_' . $format . '.pdf';
    $filePath = $awbDir . $filename;
    
    if (file_exists($filePath)) {
        // Return direct URL to existing PDF
        return getSimpleBaseUrl() . '/storage/awb_pdfs/' . $filename;
    }
    
    // Generate new PDF from Cargus API
    return generateAwbPDFFromCargus($awbCode, $format, $db);
}

function generateAwbPDFFromCargus(string $awbCode, string $format, PDO $db): ?string {
    try {
        // Initialize Cargus service
        $cargusService = new CargusService($db);
        
        // Get AWB document from Cargus API
        $awbList = [$awbCode];
        $formatCode = ($format === 'a4') ? 0 : 1; // 0=A4, 1=Label 10x14
        $printResult = $cargusService->getAwbDocuments($awbList, 'PDF', $formatCode, 1);
        
        if (!$printResult['success']) {
            error_log("Cargus AWB Documents failed for {$awbCode}: " . $printResult['error']);
            return null;
        }
        
        $pdfBase64 = $printResult['data'];
        $pdfData = base64_decode($pdfBase64);
        
        if (!$pdfData || substr($pdfData, 0, 4) !== '%PDF') {
            error_log("Invalid PDF data received from Cargus API for AWB: {$awbCode}");
            return null;
        }
        
        // Save to PERMANENT storage directory (NOT temp!)
        $awbDir = BASE_PATH . '/storage/awb_pdfs/';
        if (!is_dir($awbDir)) {
            mkdir($awbDir, 0755, true);
        }
        
        $filename = $awbCode . '_' . $format . '.pdf';
        $filePath = $awbDir . $filename;
        
        if (!file_put_contents($filePath, $pdfData)) {
            error_log("Failed to save AWB PDF for: {$awbCode}");
            return null;
        }
        
        // Return simple accessible URL - NO EXTERNAL IP!
        return getSimpleBaseUrl() . '/storage/awb_pdfs/' . $filename;
        
    } catch (Exception $e) {
        error_log("AWB PDF generation error: " . $e->getMessage());
        return null;
    }
}

function createPrintJob($db, $orderId, $printerId, $printServerId, $pdfUrl): int {
    $stmt = $db->prepare('
        INSERT INTO print_jobs (order_id, printer_id, print_server_id, job_type, file_url, status, created_at)
        VALUES (?, ?, ?, "awb", ?, "pending", ?)
    ');
    $stmt->execute([$orderId, $printerId, $printServerId, $pdfUrl, date('Y-m-d H:i:s')]);
    return $db->lastInsertId();
}

function updatePrintJobStatus($db, $jobId, $success, $error = null): void {
    $stmt = $db->prepare('
        UPDATE print_jobs 
        SET status = ?, completed_at = ?, error_message = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $success ? 'success' : 'failed',
        date('Y-m-d H:i:s'),
        $error,
        $jobId
    ]);
}

function getSimpleBaseUrl(): string {
    // Simple base URL - NO external IP complications
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}
?>