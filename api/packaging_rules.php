<?php
/**
 * UNIT TYPES API - api/unit_types.php
 * Create this new file for unit types management
 */
?>
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
        case 'PUT':
            handlePut($db);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}

function handleGet($db) {
    $id = $_GET['id'] ?? null;
    
    $query = "
        SELECT 
            id, unit_code, unit_name, base_type, default_weight_per_unit,
            packaging_type, max_items_per_parcel, requires_separate_parcel,
            active, created_at, updated_at
        FROM unit_types
    ";
    
    $params = [];
    
    if ($id) {
        $query .= " WHERE id = ?";
        $params[] = $id;
    } else {
        $query .= " ORDER BY unit_code ASC";
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedResults = array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'unit_code' => $row['unit_code'],
            'unit_name' => $row['unit_name'],
            'base_type' => $row['base_type'],
            'default_weight_per_unit' => (float)$row['default_weight_per_unit'],
            'packaging_type' => $row['packaging_type'],
            'max_items_per_parcel' => $row['max_items_per_parcel'] ? (int)$row['max_items_per_parcel'] : null,
            'requires_separate_parcel' => (bool)$row['requires_separate_parcel'],
            'active' => (bool)$row['active'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }, $results);
    
    echo json_encode($id ? ($formattedResults[0] ?? null) : $formattedResults);
}

function handlePut($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing unit type ID']);
        return;
    }
    
    $checkQuery = "SELECT id FROM unit_types WHERE id = ?";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$input['id']]);
    
    if (!$checkStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Unit type not found']);
        return;
    }
    
    $updateFields = [];
    $updateValues = [];
    
    $allowedFields = [
        'unit_name', 'base_type', 'default_weight_per_unit',
        'packaging_type', 'max_items_per_parcel', 'requires_separate_parcel', 'active'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateFields[] = "$field = ?";
            
            if (in_array($field, ['requires_separate_parcel', 'active'])) {
                $updateValues[] = (bool)$input[$field];
            } else {
                $updateValues[] = $input[$field];
            }
        }
    }
    
    if (empty($updateFields)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid fields to update']);
        return;
    }
    
    $updateFields[] = 'updated_at = CURRENT_TIMESTAMP';
    $updateValues[] = $input['id'];
    
    $query = "UPDATE unit_types SET " . implode(', ', $updateFields) . " WHERE id = ?";
    
    $stmt = $db->prepare($query);
    $result = $stmt->execute($updateValues);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Unit type updated successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update unit type']);
    }
}
?>

<?php
/**
 * PACKAGING RULES API - api/packaging_rules.php  
 * Create this new file for packaging rules management
 */
?>
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
        case 'PUT':
            handlePut($db);
            break;
        case 'DELETE':
            handleDelete($db);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}

function handleDelete($db) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing packaging rule ID']);
        return;
    }
    
    $checkQuery = "SELECT id, rule_name FROM packaging_rules WHERE id = ?";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$id]);
    $rule = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$rule) {
        http_response_code(404);
        echo json_encode(['error' => 'Packaging rule not found']);
        return;
    }
    
    // Delete the packaging rule
    $query = "DELETE FROM packaging_rules WHERE id = ?";
    $stmt = $db->prepare($query);
    $result = $stmt->execute([$id]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Packaging rule deleted successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete packaging rule']);
    }
}
?>