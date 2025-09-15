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
$inventoryId = intval($input['inventory_id'] ?? 0);
$barcode = trim($input['barcode'] ?? '');
if ($taskId <= 0 || ($inventoryId <= 0 && $barcode === '')) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid data']);
    exit;
}
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();
require_once BASE_PATH . '/models/BarcodeCaptureTask.php';
$taskModel = new BarcodeCaptureTask($db);
$task = $taskModel->getTaskById($taskId, true);
if (!$task) {
    http_response_code(404);
    echo json_encode(['status'=>'error','message'=>'Task not found']);
    exit;
}
try {
    $db->beginTransaction();
    if ($inventoryId > 0) {
        $stmt = $db->prepare('DELETE FROM inventory WHERE id = :id AND product_id = :p AND location_id = :l');
        $stmt->execute([':id'=>$inventoryId, ':p'=>$task['product_id'], ':l'=>$task['location_id']]);
        if ($stmt->rowCount() === 0) {
            throw new Exception('Scan not found');
        }
    } else {
        $stmt = $db->prepare('DELETE FROM inventory WHERE product_id = :p AND location_id = :l AND product_barcode = :b LIMIT 1');
        $stmt->execute([':p'=>$task['product_id'], ':l'=>$task['location_id'], ':b'=>$barcode]);
        if ($stmt->rowCount() === 0) {
            throw new Exception('Scan not found');
        }
    }
    $taskModel->decrementScannedQuantity($taskId, 1);
    $task = $taskModel->getTaskById($taskId, true);
    $db->commit();
    echo json_encode(['status'=>'success','scanned'=>$task['scanned_quantity'],'expected'=>$task['expected_quantity']]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
