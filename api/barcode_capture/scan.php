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

function updateReceivingSessionProgress(PDO $db, int $sessionId): void {
    $stmt = $db->prepare("
        UPDATE receiving_sessions rs
        SET total_items_received = (
            SELECT COUNT(DISTINCT filtered.purchase_order_item_id)
            FROM (
                SELECT
                    ri.purchase_order_item_id,
                    ri.tracking_method,
                    bct.status AS barcode_status,
                    bct.scanned_quantity,
                    bct.expected_quantity
                FROM receiving_items ri
                LEFT JOIN barcode_capture_tasks bct ON ri.barcode_task_id = bct.task_id
                WHERE ri.receiving_session_id = :session_id
            ) AS filtered
            WHERE filtered.purchase_order_item_id IS NOT NULL
              AND (
                filtered.tracking_method = 'bulk'
                OR (
                    filtered.tracking_method = 'individual'
                    AND (
                        filtered.barcode_status = 'completed'
                        OR filtered.scanned_quantity >= filtered.expected_quantity
                    )
                )
            )
        ),
        updated_at = NOW()
        WHERE rs.id = :session_id
    ");
    $stmt->execute([':session_id' => $sessionId]);
}
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
    $dupStmt = $db->prepare('SELECT COUNT(*) FROM inventory WHERE product_barcode = :b');
    $dupStmt->execute([':b' => $barcode]);
    if ($dupStmt->fetchColumn() > 0) {
        throw new Exception('Codul de bare a fost deja scanat', 409);
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
        $sessionStmt = $db->prepare('SELECT receiving_session_id FROM receiving_items WHERE barcode_task_id = :task_id LIMIT 1');
        $sessionStmt->execute([':task_id' => $taskId]);
        $sessionId = $sessionStmt->fetchColumn();
        if ($sessionId) {
            updateReceivingSessionProgress($db, (int)$sessionId);
        }
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
    $code = $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    http_response_code($code);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
