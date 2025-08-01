<?php
/**
 * Picking Tasks Timing API
 * File: api/timing/picking_tasks.php
 * 
 * Handles starting, stopping, and managing picking task timing
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Get database connection
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'POST':
            if ($action === 'start') {
                startPickingTask($db);
            } elseif ($action === 'complete') {
                completePickingTask($db);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;
            
        case 'GET':
            if ($action === 'active') {
                getActivePickingTasks($db);
            } elseif ($action === 'analytics') {
                getPickingAnalytics($db);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;
            
        case 'PUT':
            if ($action === 'pause') {
                pausePickingTask($db);
            } elseif ($action === 'resume') {
                resumePickingTask($db);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log("Picking timing API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}

/**
 * Start a picking task timer
 */
function startPickingTask($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $orderId = $input['order_id'] ?? null;
    $orderItemId = $input['order_item_id'] ?? null;
    $productId = $input['product_id'] ?? null;
    $quantityToPick = $input['quantity_to_pick'] ?? null;
    $locationId = $input['location_id'] ?? null;
    $operatorId = $_SESSION['user_id'];
    
    if (!$orderId || !$orderItemId || !$productId || !$quantityToPick) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        return;
    }
    
    // Check if there's already an active task for this item
    $checkStmt = $db->prepare("
        SELECT id FROM picking_tasks 
        WHERE order_item_id = ? AND operator_id = ? AND status = 'active'
    ");
    $checkStmt->execute([$orderItemId, $operatorId]);
    
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Task already active for this item']);
        return;
    }
    
    // Create new picking task
    $stmt = $db->prepare("
        INSERT INTO picking_tasks (
            order_id, order_item_id, product_id, operator_id, 
            quantity_to_pick, location_id, start_time, status
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'active')
    ");
    
    $stmt->execute([
        $orderId, $orderItemId, $productId, $operatorId, 
        $quantityToPick, $locationId
    ]);
    
    $taskId = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'task_id' => $taskId,
        'message' => 'Picking task started',
        'start_time' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Complete a picking task
 */
function completePickingTask($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $taskId = $input['task_id'] ?? null;
    $quantityPicked = $input['quantity_picked'] ?? null;
    $notes = $input['notes'] ?? null;
    $operatorId = $_SESSION['user_id'];
    
    if (!$taskId || $quantityPicked === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        return;
    }
    
    // Update task completion
    $stmt = $db->prepare("
        UPDATE picking_tasks 
        SET end_time = NOW(), 
            quantity_picked = ?, 
            status = 'completed', 
            notes = ?,
            updated_at = NOW()
        WHERE id = ? AND operator_id = ? AND status = 'active'
    ");
    
    $result = $stmt->execute([$quantityPicked, $notes, $taskId, $operatorId]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Task not found or not active']);
        return;
    }
    
    // Get the completed task details
    $getStmt = $db->prepare("
        SELECT pt.*, p.name as product_name, 
               TIMESTAMPDIFF(SECOND, pt.start_time, pt.end_time) as duration_seconds
        FROM picking_tasks pt
        JOIN products p ON pt.product_id = p.product_id
        WHERE pt.id = ?
    ");
    $getStmt->execute([$taskId]);
    $task = $getStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'task' => $task,
        'message' => 'Picking task completed',
        'duration_seconds' => $task['duration_seconds'],
        'duration_formatted' => formatDuration($task['duration_seconds'])
    ]);
}

/**
 * Get active picking tasks for current operator
 */
function getActivePickingTasks($db) {
    $operatorId = $_SESSION['user_id'];
    
    $stmt = $db->prepare("
        SELECT pt.*, p.name as product_name, p.sku, l.location_code,
               TIMESTAMPDIFF(SECOND, pt.start_time, NOW()) as elapsed_seconds
        FROM picking_tasks pt
        JOIN products p ON pt.product_id = p.product_id
        LEFT JOIN locations l ON pt.location_id = l.id
        WHERE pt.operator_id = ? AND pt.status = 'active'
        ORDER BY pt.start_time ASC
    ");
    
    $stmt->execute([$operatorId]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($tasks as &$task) {
        $task['elapsed_formatted'] = formatDuration($task['elapsed_seconds']);
    }
    
    echo json_encode([
        'success' => true,
        'tasks' => $tasks
    ]);
}

/**
 * Get picking analytics
 */
function getPickingAnalytics($db) {
    $operatorId = $_GET['operator_id'] ?? $_SESSION['user_id'];
    $days = $_GET['days'] ?? 7;
    $productId = $_GET['product_id'] ?? null;
    
    $whereClause = "WHERE pt.operator_id = ? AND pt.status = 'completed' 
                   AND pt.start_time >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $params = [$operatorId, $days];
    
    if ($productId) {
        $whereClause .= " AND pt.product_id = ?";
        $params[] = $productId;
    }
    
    // Get detailed analytics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_tasks,
            SUM(pt.quantity_picked) as total_items_picked,
            AVG(pt.duration_seconds) as avg_duration_seconds,
            MIN(pt.duration_seconds) as min_duration_seconds,
            MAX(pt.duration_seconds) as max_duration_seconds,
            AVG(pt.quantity_picked) as avg_items_per_task,
            SUM(pt.duration_seconds) as total_duration_seconds
        FROM picking_tasks pt
        $whereClause
    ");
    
    $stmt->execute($params);
    $analytics = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get per-product breakdown
    $productStmt = $db->prepare("
        SELECT 
            p.product_id, p.name as product_name, p.category,
            COUNT(*) as task_count,
            SUM(pt.quantity_picked) as total_picked,
            AVG(pt.duration_seconds) as avg_duration_seconds
        FROM picking_tasks pt
        JOIN products p ON pt.product_id = p.product_id
        $whereClause
        GROUP BY p.product_id
        ORDER BY avg_duration_seconds DESC
    ");
    
    $productStmt->execute($params);
    $productBreakdown = $productStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'analytics' => $analytics,
        'product_breakdown' => $productBreakdown,
        'period_days' => $days
    ]);
}

/**
 * Pause a picking task
 */
function pausePickingTask($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $taskId = $input['task_id'] ?? null;
    $operatorId = $_SESSION['user_id'];
    
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Task ID required']);
        return;
    }
    
    $stmt = $db->prepare("
        UPDATE picking_tasks 
        SET status = 'paused', updated_at = NOW()
        WHERE id = ? AND operator_id = ? AND status = 'active'
    ");
    
    $result = $stmt->execute([$taskId, $operatorId]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Task not found or not active']);
        return;
    }
    
    echo json_encode(['success' => true, 'message' => 'Task paused']);
}

/**
 * Resume a paused picking task
 */
function resumePickingTask($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $taskId = $input['task_id'] ?? null;
    $operatorId = $_SESSION['user_id'];
    
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Task ID required']);
        return;
    }
    
    $stmt = $db->prepare("
        UPDATE picking_tasks 
        SET status = 'active', updated_at = NOW()
        WHERE id = ? AND operator_id = ? AND status = 'paused'
    ");
    
    $result = $stmt->execute([$taskId, $operatorId]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Task not found or not paused']);
        return;
    }
    
    echo json_encode(['success' => true, 'message' => 'Task resumed']);
}

/**
 * Format duration in seconds to human readable format
 */
function formatDuration($seconds) {
    if ($seconds < 60) {
        return $seconds . 's';
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        return $minutes . 'm ' . $remainingSeconds . 's';
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;
        return $hours . 'h ' . $minutes . 'm ' . $remainingSeconds . 's';
    }
}
?>