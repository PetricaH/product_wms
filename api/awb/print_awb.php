<?php
// File: api/awb/print_awb.php
// Production-ready AWB printing with Cargus API integration

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

$orderId = intval($_POST['order_id'] ?? 0);
$awbCode = trim($_POST['awb'] ?? '');
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
    
    // Get printer details if specified
    $printer = null;
    if ($printerId) {
        $printerStmt = $db->prepare('
            SELECT p.*, ps.ip_address, ps.port, ps.is_active as server_active
            FROM printers p
            LEFT JOIN print_servers ps ON p.print_server_id = ps.id
            WHERE p.id = ? AND p.is_active = 1
        ');
        $printerStmt->execute([$printerId]);
        $printer = $printerStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$printer) {
            respond(['success' => false, 'error' => 'Printer not found or inactive'], 404);
        }
    }
    
    // Initialize Cargus service
    $cargusService = new CargusService($db);
    
    // Get AWB document from Cargus API
    $awbList = [$awbCode];
    $formatCode = ($format === 'a4') ? 0 : 1; // 0=A4, 1=Label 10x14
    $printResult = $cargusService->getAwbDocuments($awbList, 'PDF', $formatCode, 1);
    
    if (!$printResult['success']) {
        error_log("Cargus AWB Documents failed for {$awbCode}: " . $printResult['error']);
        respond(['success' => false, 'error' => 'Failed to generate AWB document: ' . $printResult['error']], 500);
    }
    
    $pdfBase64 = $printResult['data'];
    $pdfData = base64_decode($pdfBase64);
    
    if (!$pdfData) {
        respond(['success' => false, 'error' => 'Invalid PDF data received from Cargus API'], 500);
    }
    
    // Save PDF to temporary directory
    $tempDir = BASE_PATH . '/storage/temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0775, true);
    }
    
    $tempFile = $tempDir . '/awb_' . $awbCode . '_' . time() . '.pdf';
    if (!file_put_contents($tempFile, $pdfData)) {
        respond(['success' => false, 'error' => 'Failed to save PDF file'], 500);
    }
    
    // Create print job record
    $jobStmt = $db->prepare('
        INSERT INTO print_jobs (order_id, printer_id, print_server_id, job_type, file_url, status)
        VALUES (?, ?, ?, ?, ?, ?)
    ');

    if (!$printer || !$printer['id'] || !$printer['print_server_id']) {
        respond(['success' => false, 'error' => 'Printer configuration incomplete'], 400);
    }
    
    $jobStmt->execute([
        $orderId,
        $printer['id'],                    // Remove ?? null since it's NOT NULL
        $printer['print_server_id'],       // Remove ?? null since it's NOT NULL  
        'awb',
        $tempFile,
        'pending'
    ]);
    $jobId = $db->lastInsertId();
    
    $result = ['success' => true, 'job_id' => $jobId, 'awb' => $awbCode];
    
    // If printer is specified and available, send to print server
    if ($printer && $printer['ip_address'] && $printer['server_active']) {
        $printServerUrl = "http://{$printer['ip_address']}:{$printer['port']}/print_server.php";
        $printSuccess = sendToPrintServer($printServerUrl, $tempFile, $printer['network_identifier']);
        
        // Update job status
        $statusStmt = $db->prepare('
            UPDATE print_jobs 
            SET status = ?, completed_at = ?, error_message = ? 
            WHERE id = ?
        ');
        $statusStmt->execute([
            $printSuccess['success'] ? 'success' : 'failed',  // 'success' matches your enum
            date('Y-m-d H:i:s'),
            $printSuccess['error'] ?? null,
            $jobId
        ]);
        
        if ($printSuccess['success']) {
            // Update printer last_used
            $updateStmt = $db->prepare('UPDATE printers SET last_used = ? WHERE id = ?');
            $updateStmt->execute([date('Y-m-d H:i:s'), $printer['id']]);
            
            $result['message'] = 'AWB sent to printer successfully';
            $result['printer'] = $printer['name'];
        } else {
            $result['success'] = false;
            $result['error'] = 'Print server error: ' . ($printSuccess['error'] ?? 'Unknown error');
        }
    } else {
        // No printer specified or available - return PDF URL for download
        $result['pdf_url'] = '/storage/temp/' . basename($tempFile);
        $result['message'] = 'AWB PDF generated successfully. Download to print manually.';
    }
    
    // Log the print action
    error_log("AWB print job {$jobId} created for order {$orderId} awb {$awbCode} by user {$_SESSION['user_id']}");
    
    respond($result);
    
} catch (Throwable $e) {
    error_log("AWB print error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    respond(['success' => false, 'error' => 'Internal server error'], 500);
}

function sendToPrintServer($serverUrl, $pdfPath, $printerName = null) {
    try {
        // Create accessible URL for the PDF file (same method as invoice printing)
        $baseUrl = getExternalBaseUrl();
        $fileName = basename($pdfPath);
        $pdfUrl = $baseUrl . '/storage/temp/' . $fileName;
        
        // Build print server URL with parameters (same as working invoice printing)
        $params = ['url' => $pdfUrl];
        if ($printerName) {
            $params['printer'] = $printerName;  // This was missing!
        }
        
        $printUrl = $serverUrl . '?' . http_build_query($params);
        
        error_log("AWB Print server URL: " . $printUrl);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true
            ]
        ]);
        
        $response = @file_get_contents($printUrl, false, $context);
        
        error_log("AWB Print server response: " . ($response ?: 'NO RESPONSE'));
        
        if ($response === false) {
            return ['success' => false, 'error' => 'Failed to connect to print server'];
        }
        
        // Check for success indicators (same as invoice printing)
        if (strpos($response, 'Trimis la imprimantă') !== false || 
            strpos($response, 'sent to printer') !== false ||
            strpos($response, 'Print successful') !== false) {
            return ['success' => true];
        }
        
        return ['success' => false, 'error' => 'Print server returned: ' . $response];
        
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Print server exception: ' . $e->getMessage()];
    }
}

function getExternalBaseUrl() {
    // Use the same external IP as your invoice printing
    return 'http://195.133.74.33';
}
?>