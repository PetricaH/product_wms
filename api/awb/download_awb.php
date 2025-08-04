<?php
/**
 * API: Download AWB Document - Using Existing CargusService
 * File: api/awb/download_awb.php
 * Uses the existing getAwbDocuments() method from CargusService
 */

ob_start();
header('Cache-Control: no-cache');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/config/config.php';

function respond($data, $code = 200) {
    ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function servePDF($base64Content, $filename) {
    ob_end_clean();
    
    // Decode base64 content
    $pdfContent = base64_decode($base64Content, false);
    
    if ($pdfContent === false || strlen($pdfContent) === 0) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Invalid PDF content']);
        exit;
    }
    
    // Verify it's a PDF
    if (substr($pdfContent, 0, 4) !== '%PDF') {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Invalid PDF format']);
        exit;
    }
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $pdfContent;
    exit;
}

// Authentication check
$allowedRoles = ['admin', 'warehouse', 'warehouse_worker'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    respond(['success' => false, 'error' => 'Access denied'], 403);
}

// CSRF protection for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        respond(['success' => false, 'error' => 'Invalid CSRF token'], 403);
    }
}

// Get parameters
$awbNumber = trim($_POST['awb'] ?? $_GET['awb'] ?? '');
$format = $_POST['format'] ?? $_GET['format'] ?? 'label';
$orderId = intval($_POST['order_id'] ?? $_GET['order_id'] ?? 0);

// Validate parameters
if (empty($awbNumber)) {
    respond(['success' => false, 'error' => 'AWB number is required'], 400);
}

if (!preg_match('/^\d+$/', $awbNumber)) {
    respond(['success' => false, 'error' => 'Invalid AWB number format'], 400);
}

// Convert format parameter
$formatCode = ($format === 'label') ? 1 : 0; // 1=Label, 0=A4

try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();
    
    require_once BASE_PATH . '/models/CargusService.php';
    $cargusService = new CargusService($db);
    
    error_log("AWB download request: AWB=$awbNumber, Format=$format($formatCode), OrderID=$orderId, User={$_SESSION['user_id']}");
    
    // Use the existing getAwbDocuments method
    $result = $cargusService->getAwbDocuments(
        [$awbNumber],           // awbCodes array
        'PDF',                  // type
        $formatCode,           // format (1=label, 0=A4)
        1                      // printMainOnce
    );
    
    if ($result['success']) {
        $base64Data = $result['data'];
        $filename = "awb_{$awbNumber}.pdf";
        
        error_log("AWB download successful: AWB=$awbNumber, Base64 length=" . strlen($base64Data) . " chars");
        
        // Serve the PDF file
        servePDF($base64Data, $filename);
        
    } else {
        error_log("AWB download failed: AWB=$awbNumber, Error=" . $result['error']);
        respond([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to download AWB document',
            'debug_info' => [
                'awb' => $awbNumber,
                'format' => $format,
                'format_code' => $formatCode
            ]
        ], 500);
    }
    
} catch (Exception $e) {
    error_log("AWB download exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    respond([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ], 500);
}
?>