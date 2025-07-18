<?php
/**
 * Receiving Tasks Timing API - Fixed Version
 * File: api/timing/receiving_tasks.php
 * 
 * Handles starting, stopping, and managing receiving task timing
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
                startReceivingTask($db);
            } elseif ($action === 'complete') {
                completeReceivingTask($db);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;
            
        case 'GET':
            if ($action === 'active') {
                getActiveReceivingTasks($db);
            } elseif ($action === 'analytics') {
                getReceivingAnalytics($db);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;
            
        case 'PUT':
            if ($action === 'pause') {
                pauseReceivingTask($db);
            } elseif ($action === 'resume') {
                resumeReceivingTask($db);
            } elseif ($action === 'quality_check') {
                qualityCheckReceivingTask($db);
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
    error_log("Receiving timing API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}

/**
 * Start a receiving task timer
 */
function startReceivingTask($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $receivingSessionId = $input['receiving_session_id'] ?? null;
    $receivingItemId = $input['receiving_item_id'] ?? null;
    $productId = $input['product_id'] ?? null;
    $quantityToReceive = $input['quantity_to_receive'] ?? null;
    $locationId = $input['location_id'] ?? null;
    $operatorId = $_SESSION['user_id'];
    
    if (!$receivingSessionId || !$receivingItemId || !$productId || !$quantityToReceive) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        return;
    }
    
    // Check if there's already an active task for this item
    $checkStmt = $db->prepare("
        SELECT id FROM receiving_tasks 
        WHERE receiving_item_id = ? AND operator_id = ? AND status IN ('active', 'quality_check')
    ");
    $checkStmt->execute([$receivingItemId, $operatorId]);
    
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Task already active for this item']);
        return;
    }
    
    // Create new receiving task
    $stmt = $db->prepare("
        INSERT INTO receiving_tasks (
            receiving_session_id, receiving_item_id, product_id, operator_id, 
            quantity_to_receive, location_id, start_time, status
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'active')
    ");
    
    $stmt->execute([
        $receivingSessionId, $receivingItemId, $productId, $operatorId, 
        $quantityToReceive, $locationId
    ]);
    
    $taskId = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'task_id' => $taskId,
        'message' => 'Receiving task started',
        'start_time' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Complete a receiving task
 */
function completeReceivingTask($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $taskId = $input['task_id'] ?? null;
    $quantityReceived = $input['quantity_received'] ?? null;
    $qualityCheckNotes = $input['quality_check_notes'] ?? null;
    $discrepancyNotes = $input['discrepancy_notes'] ?? null;
    $operatorId = $_SESSION['user_id'];
    
    if (!$taskId || $quantityReceived === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        return;
    }
    
    // Update task completion
    $stmt = $db->prepare("
        UPDATE receiving_tasks 
        SET end_time = NOW(), 
            quantity_received = ?, 
            status = 'completed', 
            quality_check_notes = ?,
            discrepancy_notes = ?,
            updated_at = NOW()
        WHERE id = ? AND operator_id = ? AND status IN ('active', 'quality_check')
    ");
    
    $result = $stmt->execute([$quantityReceived, $qualityCheckNotes, $discrepancyNotes, $taskId, $operatorId]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Task not found or not active']);
        return;
    }
    
    echo json_encode(['success' => true, 'message' => 'Task completed successfully']);
}

/**
 * Move task to quality check status
 */
function qualityCheckReceivingTask($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $taskId = $input['task_id'] ?? null;
    $qualityCheckNotes = $input['quality_check_notes'] ?? null;
    $operatorId = $_SESSION['user_id'];
    
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Task ID required']);
        return;
    }
    
    $stmt = $db->prepare("
        UPDATE receiving_tasks 
        SET status = 'quality_check', 
            quality_check_notes = ?,
            updated_at = NOW()
        WHERE id = ? AND operator_id = ? AND status = 'active'
    ");
    
    $result = $stmt->execute([$qualityCheckNotes, $taskId, $operatorId]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Task not found or not active']);
        return;
    }
    
    echo json_encode(['success' => true, 'message' => 'Task moved to quality check']);
}

/**
 * Pause a receiving task
 */
function pauseReceivingTask($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $taskId = $input['task_id'] ?? null;
    $operatorId = $_SESSION['user_id'];
    
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Task ID required']);
        return;
    }
    
    $stmt = $db->prepare("
        UPDATE receiving_tasks 
        SET status = 'paused', updated_at = NOW()
        WHERE id = ? AND operator_id = ? AND status IN ('active', 'quality_check')
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
 * Resume a paused receiving task
 */
function resumeReceivingTask($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $taskId = $input['task_id'] ?? null;
    $operatorId = $_SESSION['user_id'];
    
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Task ID required']);
        return;
    }
    
    $stmt = $db->prepare("
        UPDATE receiving_tasks 
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
 * Get active receiving tasks for current operator
 */
function getActiveReceivingTasks($db) {
    $operatorId = $_SESSION['user_id'];
    
    $stmt = $db->prepare("
        SELECT rt.*, p.name as product_name, p.sku, l.location_code,
               TIMESTAMPDIFF(SECOND, rt.start_time, NOW()) as elapsed_seconds
        FROM receiving_tasks rt
        JOIN products p ON rt.product_id = p.product_id
        LEFT JOIN locations l ON rt.location_id = l.id
        WHERE rt.operator_id = ? AND rt.status IN ('active', 'quality_check')
        ORDER BY rt.start_time ASC
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
 * Get receiving analytics
 */
function getReceivingAnalytics($db) {
    $operatorId = $_GET['operator_id'] ?? $_SESSION['user_id'];
    $days = $_GET['days'] ?? 7;
    $productId = $_GET['product_id'] ?? null;
    
    $whereClause = "WHERE rt.operator_id = ? AND rt.status = 'completed' 
                   AND rt.start_time >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $params = [$operatorId, $days];
    
    if ($productId) {
        $whereClause .= " AND rt.product_id = ?";
        $params[] = $productId;
    }
    
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_tasks,
            AVG(rt.duration_seconds) as avg_duration,
            MIN(rt.duration_seconds) as min_duration,
            MAX(rt.duration_seconds) as max_duration,
            SUM(rt.quantity_received) as total_quantity
        FROM receiving_tasks rt
        $whereClause
    ");
    
    $stmt->execute($params);
    $analytics = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'analytics' => $analytics
    ]);
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