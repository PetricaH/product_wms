<?php
/**
 * API: Upload Invoice
 * File: api/upload_invoice.php
 * 
 * Handles invoice file upload and marks purchase order as invoiced
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

// Session and authentication check
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Database connection
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

try {
    // Get purchase order ID
    $orderId = (int)($_POST['order_id'] ?? 0);
    
    if (!$orderId) {
        throw new Exception('Order ID is required');
    }
    
    // Check if file was uploaded
    if (!isset($_FILES['invoice_file']) || $_FILES['invoice_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }
    
    $file = $_FILES['invoice_file'];
    
    // Validate file
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
        WHERE po.id = ? AND po.status IN ('delivered', 'partial_delivery', 'completed')
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception('Purchase order not found or not eligible for invoice');
    }
    
    // Check if already invoiced
    if ($order['invoiced']) {
        throw new Exception('This purchase order is already invoiced');
    }
    
    // Create upload directory if it doesn't exist
    $uploadDir = BASE_PATH . '/storage/invoices/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'invoice_' . $orderId . '_' . date('Y-m-d_H-i-s') . '.' . $extension;
    $filepath = $uploadDir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to save uploaded file');
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Update purchase order with invoice information
    $stmt = $db->prepare("
        UPDATE purchase_orders 
        SET invoiced = TRUE,
            invoice_file_path = ?,
            invoiced_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute(['storage/invoices/' . $filename, $orderId]);
    
    // Log the activity
    $stmt = $db->prepare("
        INSERT INTO activity_logs (
            user_id, action, entity_type, entity_id, 
            description, ip_address, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        'upload_invoice',
        'purchase_order',
        $orderId,
        "Invoice uploaded for purchase order {$order['order_number']}",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // Commit transaction
    $db->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Invoice uploaded successfully',
        'data' => [
            'order_id' => $orderId,
            'order_number' => $order['order_number'],
            'invoice_file_path' => 'storage/invoices/' . $filename,
            'invoiced_at' => date('Y-m-d H:i:s'),
            'uploaded_by' => $_SESSION['username'] ?? 'Unknown'
        ]
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    // Delete uploaded file if it exists
    if (isset($filepath) && file_exists($filepath)) {
        unlink($filepath);
    }
    
    error_log("Upload invoice error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}