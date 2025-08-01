<?php
/**
 * API: Upload Invoice
 * File: api/receiving/upload_invoice.php
 * 
 * Handles invoice file upload and updates purchase order status to confirmed
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

try {
    $orderId = (int)($_POST['order_id'] ?? 0);
    
    if (!$orderId) {
        throw new Exception('Order ID is required');
    }
    
    if (!isset($_FILES['invoice_file']) || $_FILES['invoice_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }
    
    $file = $_FILES['invoice_file'];
    
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Only PDF, JPG, and PNG files are allowed');
    }
    
    if ($file['size'] > $maxSize) {
        throw new Exception('File too large. Maximum size is 5MB');
    }
    
    // Verify purchase order exists and is eligible for invoice
    $stmt = $db->prepare("
        SELECT po.*, s.supplier_name 
        FROM purchase_orders po 
        LEFT JOIN sellers s ON po.seller_id = s.id 
        WHERE po.id = ? AND po.status IN ('sent', 'confirmed', 'delivered', 'partial_delivery', 'completed')
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception('Purchase order not found or not eligible for invoice');
    }
    
    if ($order['invoiced']) {
        throw new Exception('This purchase order is already invoiced');
    }
    
    $uploadDir = BASE_PATH . '/storage/invoices/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'invoice_' . $orderId . '_' . date('Y-m-d_H-i-s') . '.' . $extension;
    $filepath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to save uploaded file');
    }
    
    $db->beginTransaction();
    
    // Update purchase order with invoice info and set status to confirmed
    $stmt = $db->prepare("
        UPDATE purchase_orders SET 
            invoiced = TRUE,
            invoice_file_path = :filepath,
            invoiced_at = NOW(),
            status = 'confirmed',
            updated_at = NOW()
        WHERE id = :order_id
    ");
    
    $stmt->execute([
        ':filepath' => 'invoices/' . $filename,
        ':order_id' => $orderId
    ]);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Invoice uploaded successfully and order status updated to confirmed',
        'invoice_filename' => $filename,
        'new_status' => 'confirmed'
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    
    if (isset($filepath) && file_exists($filepath)) {
        unlink($filepath);
    }
    
    error_log("Invoice upload error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}