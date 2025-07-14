<?php
// File: api/invoices/print_invoice_network.php
// Enhanced invoice printing with print server support

ob_start();
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

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
session_start();
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

    // Generate PDF invoice
    $invoiceUrl = generateInvoicePDF($order, $db);
    if (!$invoiceUrl) {
        respond(['status' => 'error', 'message' => 'Failed to generate invoice PDF'], 500);
    }

    // Create print job record
    $jobId = createPrintJob($db, $orderId, $printer['id'], $printer['print_server_id'] ?? null, $invoiceUrl);

    // Send to print server
    $printResult = sendToPrintServer(
        $printer['ip_address'],
        $printer['port'],
        $invoiceUrl,
        $printer['network_identifier']
    );

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

function generateInvoicePDF($order, PDO $db): ?string {
    try {
        // Create storage directory for invoice PDFs
        $storageDir = BASE_PATH . '/storage/invoice_pdfs';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0777, true);
        }

        // Generate unique filename
        $fileName = 'inv_' . $order['order_number'] . '_' . time() . '.pdf';
        $filePath = $storageDir . '/' . $fileName;

        // Generate PDF using FPDF
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'FACTURA', 0, 1, 'C');

        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 8, 'Comanda: ' . $order['order_number'], 0, 1);
        $pdf->Cell(0, 8, 'Client: ' . $order['customer_name'], 0, 1);
        $pdf->Cell(0, 8, 'Data: ' . date('d.m.Y H:i', strtotime($order['order_date'])), 0, 1);
        $pdf->Ln(8);

        // Table header
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(80, 8, 'Produs', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Cantitate', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Pret', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Total', 1, 1, 'C');

        // Table content
        $pdf->SetFont('Arial', '', 9);
        $total = 0;
        foreach ($order['items'] as $item) {
            $name = $item['product_name'] ?? '';
            if (strlen($name) > 35) {
                $name = substr($name, 0, 32) . '...';
            }
            $qty = (float)($item['quantity'] ?? 0);
            $price = (float)($item['unit_price'] ?? 0);
            $lineTotal = $qty * $price;
            $total += $lineTotal;
            
            $pdf->Cell(80, 8, $name, 1);
            $pdf->Cell(30, 8, number_format($qty, 2), 1, 0, 'R');
            $pdf->Cell(30, 8, number_format($price, 2), 1, 0, 'R');
            $pdf->Cell(30, 8, number_format($lineTotal, 2), 1, 1, 'R');
        }

        // Total
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(140, 8, 'TOTAL:', 1, 0, 'R');
        $pdf->Cell(30, 8, number_format($total, 2) . ' RON', 1, 1, 'R');

        // Save PDF
        $pdf->Output('F', $filePath);

        // Return public URL to the PDF
        $baseUrl = getBaseUrl();
        return $baseUrl . '/storage/invoice_pdfs/' . $fileName;

    } catch (Exception $e) {
        error_log("PDF generation error: " . $e->getMessage());
        return null;
    }
}

function sendToPrintServer(string $ip, int $port, string $pdfUrl, string $printerName): array {
    try {
        $printServerUrl = "http://$ip:$port/print_server.php";
        $requestUrl = $printServerUrl . '?' . http_build_query([
            'url' => $pdfUrl,
            'printer' => $printerName // Optional: if your print server supports printer selection
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

        // Check for success indicators in response
        $successIndicators = ['Trimis la imprimantă', 'sent to printer', 'Print successful'];
        foreach ($successIndicators as $indicator) {
            if (stripos($response, $indicator) !== false) {
                return ['success' => true];
            }
        }

        return ['success' => false, 'error' => 'Print server response: ' . $response];

    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Exception: ' . $e->getMessage()];
    }
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
    
    return $protocol . $host . $path;
}

// Enhanced print server script for your local machines
function getEnhancedPrintServerScript(): string {
    return '<?php
// Enhanced print_server.php for local machines
// Save this file in your local web server directory

error_reporting(E_ALL);
ini_set("display_errors", 1);

// CORS headers for web requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit(0);
}

// Log requests
$logFile = __DIR__ . "/print_server.log";
$timestamp = date("Y-m-d H:i:s");
$logEntry = "[$timestamp] " . $_SERVER["REQUEST_METHOD"] . " " . $_SERVER["REQUEST_URI"] . "\n";
file_put_contents($logFile, $logEntry, FILE_APPEND);

if (!isset($_GET["url"])) {
    http_response_code(400);
    echo "Missing URL parameter.";
    exit;
}

$url = $_GET["url"];
$printer = $_GET["printer"] ?? "Brother_DCP_L3520CDW_series"; // Default printer

try {
    // Download PDF
    $tempFile = sys_get_temp_dir() . "/invoice_" . time() . ".pdf";
    $pdfContent = file_get_contents($url);
    
    if ($pdfContent === false) {
        throw new Exception("Failed to download PDF from: $url");
    }
    
    file_put_contents($tempFile, $pdfContent);
    
    // Print the file
    if (PHP_OS_FAMILY === "Darwin") { // macOS
        $cmd = "lp -d " . escapeshellarg($printer) . " " . escapeshellarg($tempFile);
    } else { // Linux
        $cmd = "lp -d " . escapeshellarg($printer) . " " . escapeshellarg($tempFile);
    }
    
    $output = [];
    $returnCode = 0;
    exec($cmd . " 2>&1", $output, $returnCode);
    
    // Clean up temp file
    @unlink($tempFile);
    
    if ($returnCode === 0) {
        $logEntry = "[$timestamp] SUCCESS: Printed $url to $printer\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        echo "Trimis la imprimantă: $printer";
    } else {
        $error = implode("\n", $output);
        $logEntry = "[$timestamp] ERROR: $error\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        throw new Exception("Print command failed: $error");
    }
    
} catch (Exception $e) {
    http_response_code(500);
    $logEntry = "[$timestamp] EXCEPTION: " . $e->getMessage() . "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    echo "Error: " . $e->getMessage();
}
?>';
}