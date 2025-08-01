<?php
/**
 * Unit Types API
 * File: api/unit_types.php
 * 
 * Handles CRUD operations for unit types management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

// Session and authentication check
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Database connection
$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
        case 'POST':
            handlePost($db);
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

/**
 * Handle GET requests - retrieve unit types
 */
function handleGet($db) {
    $query = "
        SELECT 
            id,
            unit_code,
            unit_name,
            base_type,
            default_weight_per_unit,
            packaging_type,
            max_items_per_parcel,
            requires_separate_parcel,
            active,
            created_at,
            updated_at
        FROM unit_types
        ORDER BY unit_code ASC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format results for frontend
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
    
    echo json_encode($formattedResults);
}

/**
 * Handle POST requests - create new unit type
 */
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }
    
    // Validate required fields
    $required = ['unit_code', 'unit_name', 'base_type', 'default_weight_per_unit'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }
    
    // Check if unit code already exists
    $checkQuery = "SELECT id FROM unit_types WHERE unit_code = ?";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$input['unit_code']]);
    
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Unit code already exists']);
        return;
    }
    
    // Insert new unit type
    $query = "
        INSERT INTO unit_types (
            unit_code, unit_name, base_type, default_weight_per_unit,
            packaging_type, max_items_per_parcel, requires_separate_parcel, active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ";
    
    $stmt = $db->prepare($query);
    $result = $stmt->execute([
        $input['unit_code'],
        $input['unit_name'],
        $input['base_type'],
        $input['default_weight_per_unit'],
        $input['packaging_type'] ?? 'standard',
        $input['max_items_per_parcel'] ?? null,
        isset($input['requires_separate_parcel']) ? (bool)$input['requires_separate_parcel'] : false,
        isset($input['active']) ? (bool)$input['active'] : true
    ]);
    
    if ($result) {
        $id = $db->lastInsertId();
        echo json_encode([
            'success' => true,
            'id' => $id,
            'message' => 'Unit type created successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create unit type']);
    }
}

/**
 * Handle PUT requests - update existing unit type
 */
function handlePut($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing unit type ID']);
        return;
    }
    
    // Verify unit type exists
    $checkQuery = "SELECT id FROM unit_types WHERE id = ?";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$input['id']]);
    
    if (!$checkStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Unit type not found']);
        return;
    }
    
    // Build dynamic update query
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

/**
 * Handle DELETE requests - soft delete unit type
 */
function handleDelete($db) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing unit type ID']);
        return;
    }
    
    // Check if unit type is in use
    $usageQuery = "SELECT COUNT(*) as count FROM product_units WHERE unit_type_id = ? AND active = 1";
    $usageStmt = $db->prepare($usageQuery);
    $usageStmt->execute([$id]);
    $usage = $usageStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($usage['count'] > 0) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Cannot delete unit type. It is currently in use by ' . $usage['count'] . ' product(s).'
        ]);
        return;
    }
    
    // Soft delete (set active = 0)
    $query = "UPDATE unit_types SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $db->prepare($query);
    $result = $stmt->execute([$id]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Unit type deleted successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete unit type']);
    }
}
?>