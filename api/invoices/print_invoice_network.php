<?php
// File: api/invoices/print_invoice_network.php
// Enhanced invoice printing with print server support

ob_start();
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/config/config.php';

if (file_exists(BASE_PATH . '/vendor/autoload.php')) {
    require_once BASE_PATH . '/vendor/autoload.php';
}

function respond($data, int $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Session and authentication check
$allowedRoles = ['admin', 'warehouse', 'warehouse_worker'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    respond(['status' => 'error', 'message' => 'Access denied'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$orderId = $_POST['order_id'] ?? '';
$printerId = $_POST['printer_id'] ?? null;

if (empty($orderId)) {
    respond(['status' => 'error', 'message' => 'Missing order_id'], 400);
}

try {
    $config = require BASE_PATH . '/config/config.php';
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        respond(['status' => 'error', 'message' => 'Database configuration error'], 500);
    }
    $db = $config['connection_factory']();

    require_once BASE_PATH . '/models/Order.php';

    $orderModel = new Order($db);
    $order = $orderModel->getOrderById((int)$orderId);
    if (!$order) {
        respond(['status' => 'error', 'message' => 'Order not found'], 404);
    }

    // Determine printer to use
    if ($printerId) {
        $printerQuery = '
            SELECT p.*, ps.ip_address, ps.port, ps.is_active as server_active
            FROM printers p
            LEFT JOIN print_servers ps ON p.print_server_id = ps.id
            WHERE p.id = ? AND p.is_active = 1
        ';
        $stmt = $db->prepare($printerQuery);
        $stmt->execute([$printerId]);
    } else {
        // Find default invoice printer
        $printerQuery = '
            SELECT p.*, ps.ip_address, ps.port, ps.is_active as server_active
            FROM printers p
            LEFT JOIN print_servers ps ON p.print_server_id = ps.id
            WHERE p.is_default = 1 AND p.printer_type = "invoice" AND p.is_active = 1
            LIMIT 1
        ';
        $stmt = $db->prepare($printerQuery);
        $stmt->execute();
    }

    $printer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$printer) {
        respond(['status' => 'error', 'message' => 'No suitable printer found'], 404);
    }

    // Check if print server is available
    if (!$printer['ip_address'] || !$printer['server_active']) {
        respond(['status' => 'error', 'message' => 'Print server is not available'], 503);
    }

    // Find existing invoice PDF
    $invoiceUrl = findExistingInvoicePDF($order, $db);
    if (!$invoiceUrl) {
        respond(['status' => 'error', 'message' => 'Failed to find invoice PDF'], 500);
    }

    // Create print job record
    $jobId = createPrintJob($db, $orderId, $printer['id'], $printer['print_server_id'] ?? null, $invoiceUrl);

    // Send to print server - using same method as successful test print
    $printServerUrl = "http://{$printer['ip_address']}:{$printer['port']}/print_server.php";
    $printResult = sendToPrintServer($printServerUrl, $invoiceUrl);

    // Update print job status
    updatePrintJobStatus($db, $jobId, $printResult['success'], $printResult['error'] ?? null);

    if ($printResult['success']) {
        // Update printer last_used timestamp
        $stmt = $db->prepare('UPDATE printers SET last_used = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $printer['id']]);

        respond([
            'status' => 'success', 
            'message' => 'Invoice sent to printer successfully',
            'job_id' => $jobId,
            'printer' => $printer['name']
        ]);
    } else {
        respond([
            'status' => 'error',
            'message' => 'Printing failed: ' . ($printResult['error'] ?? 'Unknown error'),
            'job_id' => $jobId
        ], 500);
    }

} catch (Exception $e) {
    error_log("Print invoice error: " . $e->getMessage());
    respond(['status' => 'error', 'message' => 'Internal error occurred'], 500);
}

function findExistingInvoicePDF($order, PDO $db): ?string {
    try {
        // Look for existing invoice PDF in the order_pdf_invoices directory
        $orderInvoicesDir = BASE_PATH . '/storage/order_pdf_invoices';
        
        if (!is_dir($orderInvoicesDir)) {
            error_log("Order PDF invoices directory not found: " . $orderInvoicesDir);
            return null;
        }
        
        // Use invoice_reference instead of order_number for the PDF filename
        $invoiceReference = $order['invoice_reference'];
        
        if (empty($invoiceReference)) {
            error_log("ERROR: No invoice_reference found for order ID: " . $order['id']);
            return null;
        }
        
        error_log("DEBUG: Looking for invoice PDF for invoice reference: '" . $invoiceReference . "'");
        error_log("DEBUG: Searching in directory: " . $orderInvoicesDir);
        
        // Since all invoices follow the format: factura-{invoice_reference}-cui-{CUI}.pdf
        $pattern = $orderInvoicesDir . "/factura-{$invoiceReference}-cui-*.pdf";
        error_log("DEBUG: Search pattern: " . $pattern);
        
        $matchingFiles = glob($pattern);
        error_log("DEBUG: Matching files found: " . print_r(array_map('basename', $matchingFiles), true));
        
        if (empty($matchingFiles)) {
            error_log("ERROR: No invoice PDF found for invoice reference: " . $invoiceReference);
            error_log("Pattern used: " . $pattern);
            
            // List all PDF files in directory for debugging
            $allPdfs = glob($orderInvoicesDir . "/*.pdf");
            error_log("DEBUG: All PDF files in directory: " . print_r(array_map('basename', $allPdfs), true));
            
            return null;
        }
        
        // Get the first (and should be only) matching file
        $foundPdfPath = $matchingFiles[0];
        $foundFilename = basename($foundPdfPath);
        
        error_log("SUCCESS: Found invoice PDF: " . $foundFilename);
        
        // Use external IP URL directly
        $externalUrl = getExternalBaseUrl() . '/storage/order_pdf_invoices/' . $foundFilename;
        error_log("Using external IP URL: " . $externalUrl);
        
        return $externalUrl;

    } catch (Exception $e) {
        error_log("PDF search error: " . $e->getMessage());
        return null;
    }
}

function createAccessiblePdfUrl(string $originalPdfPath, string $fileName): ?string {
    try {
        // Create a publicly accessible temp directory
        $publicTempDir = BASE_PATH . '/temp';
        if (!is_dir($publicTempDir)) {
            @mkdir($publicTempDir, 0755, true);
        }
        
        $publicTempPath = $publicTempDir . '/' . $fileName;
        
        // Copy existing PDF file to public location
        if (!copy($originalPdfPath, $publicTempPath)) {
            error_log("Failed to copy PDF to temp location: " . $originalPdfPath . " -> " . $publicTempPath);
            return null;
        }
        
        // Return external IP URL instead of localhost
        $externalUrl = getExternalBaseUrl() . '/temp/' . $fileName;
        error_log("Created accessible PDF URL: " . $externalUrl);
        
        return $externalUrl;
        
    } catch (Exception $e) {
        error_log("Failed to create accessible PDF URL: " . $e->getMessage());
        return null;
    }
}

function sendToPrintServer(string $printServerUrl, string $pdfUrl): array {
    // Use EXACT same method as working test print
    $url = $printServerUrl . '?url=' . urlencode($pdfUrl);
    
    error_log("Sending to print server: " . $url);
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    error_log("Print server response: " . ($response ?: 'NO RESPONSE'));
    
    if ($response === false) {
        return ['success' => false, 'error' => 'Failed to connect to print server'];
    }
    
    if (strpos($response, 'Trimis la imprimantă') !== false || 
        strpos($response, 'sent to printer') !== false) {
        return ['success' => true];
    }
    
    return ['success' => false, 'error' => 'Print server returned: ' . $response];
}

function createPrintJob(PDO $db, int $orderId, int $printerId, ?int $printServerId, string $fileUrl): int {
    $stmt = $db->prepare('
        INSERT INTO print_jobs (order_id, printer_id, print_server_id, job_type, file_url, status)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    
    $stmt->execute([
        $orderId,
        $printerId,
        $printServerId,
        'invoice',
        $fileUrl,
        'pending'
    ]);

    return $db->lastInsertId();
}

function updatePrintJobStatus(PDO $db, int $jobId, bool $success, ?string $error): void {
    $status = $success ? 'success' : 'failed';
    $timestamp = date('Y-m-d H:i:s');
    
    if ($success) {
        $stmt = $db->prepare('
            UPDATE print_jobs 
            SET status = ?, completed_at = ?, sent_at = ?
            WHERE id = ?
        ');
        $stmt->execute([$status, $timestamp, $timestamp, $jobId]);
    } else {
        $stmt = $db->prepare('
            UPDATE print_jobs 
            SET status = ?, error_message = ?, attempts = attempts + 1
            WHERE id = ?
        ');
        $stmt->execute([$status, $error, $jobId]);
    }
}

function getBaseUrl(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = dirname($_SERVER['SCRIPT_NAME'], 2); // Go up 2 levels from api/invoices/
    
    $baseUrl = $protocol . $host . $path;
    error_log("getBaseUrl() returned: " . $baseUrl);
    
    return $baseUrl;
}

function getExternalBaseUrl(): string {
    // Your web server's external IP that print servers can access
    $externalHost = '195.133.74.33';
    
    $protocol = 'http://'; // Change to https:// if using SSL
    
    return $protocol . $externalHost;
}
?>