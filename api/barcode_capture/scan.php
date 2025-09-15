<?php
header('Content-Type: application/json');
if (!defined('BASE_PATH')) {
    $possiblePaths = [dirname(__DIR__,2), dirname(__DIR__,3)];
    foreach ($possiblePaths as $path) {
        if (file_exists($path . '/config/config.php')) {
            define('BASE_PATH', $path);
            break;
        }
    }
}
if (!defined('BASE_PATH')) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Config path not found']);
    exit;
}
require_once BASE_PATH . '/bootstrap.php';
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Unauthorized']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$taskId = intval($input['task_id'] ?? 0);
$barcode = trim($input['barcode'] ?? '');
if ($taskId <= 0 || $barcode === '') {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid data']);
    exit;
}
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();
require_once BASE_PATH . '/models/BarcodeCaptureTask.php';
require_once BASE_PATH . '/models/Inventory.php';
$taskModel = new BarcodeCaptureTask($db);
$inventoryModel = new Inventory($db);
try {
    $db->beginTransaction();
    $task = $taskModel->getTaskById($taskId, true);
    if (!$task || $task['status'] === 'completed') {
        throw new Exception('Task invalid or completed');
    }
    if (empty($task['assigned_to'])) {
        $taskModel->assignToWorker($taskId, $_SESSION['user_id']);
    }
    $stockData = [
        'product_id' => $task['product_id'],
        'location_id' => $task['location_id'],
        'quantity' => 1,
        'batch_number' => null,
        'lot_number' => null,
        'expiry_date' => null,
        'received_at' => date('Y-m-d H:i:s'),
        'shelf_level' => null,
        'subdivision_number' => null
    ];
    $inventoryId = $inventoryModel->addStock($stockData, false);
    if (!$inventoryId) {
        throw new Exception('Failed to add inventory');
    }
    $stmt = $db->prepare('UPDATE inventory SET product_barcode = :barcode WHERE id = :id');
    $stmt->execute([':barcode'=>$barcode, ':id'=>$inventoryId]);
    $taskModel->incrementScannedQuantity($taskId, 1);
    $task = $taskModel->getTaskById($taskId, true);
    if ($task['scanned_quantity'] >= $task['expected_quantity']) {
        $taskModel->markCompleted($taskId);
        $task['status'] = 'completed';
    }
    $db->commit();
    echo json_encode([
        'status'=>'success',
        'scanned'=>$task['scanned_quantity'],
        'expected'=>$task['expected_quantity'],
        'completed'=>$task['status']==='completed',
        'inventory_id'=>$inventoryId
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
