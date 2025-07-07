<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Get input data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: [];
    
    $importId = $data['import_id'] ?? $_POST['import_id'] ?? null;
    $token = $data['token'] ?? $_POST['token'] ?? '';
    
    // Simple validation
    if ($token !== 'wms_webhook_2025_secure!') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid token']);
        exit;
    }
    
    if (!$importId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing import_id']);
        exit;
    }
    
    // Include your existing processor
    require_once '/var/www/notsowms.ro/config/config.php';
    require_once '/var/www/notsowms.ro/api/enhanced_process_import.php';
    
    $config = require '/var/www/notsowms.ro/config/config.php';
    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();
    
    $processor = new ImportProcessor($db);
    $result = $processor->processImport($importId);
    
    echo json_encode([
        'success' => $result['success'],
        'import_id' => $importId,
        'data' => $result['success'] ? [
            'order_id' => $result['order_id'] ?? null,
            'items_processed' => $result['items_processed'] ?? 0
        ] : null,
        'error' => $result['success'] ? null : $result['error']
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>