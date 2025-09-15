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
$taskId = intval($_GET['task_id'] ?? 0);
if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid task']);
    exit;
}
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();
require_once BASE_PATH . '/models/BarcodeCaptureTask.php';
$taskModel = new BarcodeCaptureTask($db);
$task = $taskModel->getTaskById($taskId);
if (!$task) {
    http_response_code(404);
    echo json_encode(['status'=>'error','message'=>'Task not found']);
    exit;
}
$stmt = $db->prepare('SELECT id, product_barcode FROM inventory WHERE product_id = :p AND location_id = :l AND product_barcode IS NOT NULL AND product_barcode != "" ORDER BY id DESC');
$stmt->execute([':p'=>$task['product_id'], ':l'=>$task['location_id']]);
$scans = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!empty($row['product_barcode'])) {
        $scans[] = ['inventory_id'=>$row['id'], 'barcode'=>$row['product_barcode']];
    }
}
echo json_encode(['status'=>'success','scans'=>$scans,'scanned'=>$task['scanned_quantity'],'expected'=>$task['expected_quantity']]);
