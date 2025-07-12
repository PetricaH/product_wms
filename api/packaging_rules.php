<?php
/**
 * Packaging Rules API
 * File: api/packaging_rules.php
 * 
 * Handles CRUD operations for packaging rules management
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
 * Handle GET requests - retrieve packaging rules
 */
function handleGet($db) {
    $query = "
        SELECT 
            id,
            rule_name,
            rule_type,
            max_weight_per_parcel,
            max_volume_per_parcel,
            max_items_per_parcel,
            applies_to_unit_types,
            priority,
            active,
            created_at,
            updated_at
        FROM packaging_rules
        ORDER BY priority DESC, rule_name ASC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format results for frontend
    $formattedResults = array_map(function($row) {
        $appliesTo = null;
        if ($row['applies_to_unit_types']) {
            try {
                $appliesTo = json_decode($row['applies_to_unit_types'], true);
            } catch (Exception $e) {
                $appliesTo = null;
            }
        }
        
        return [
            'id' => (int)$row['id'],
            'rule_name' => $row['rule_name'],
            'rule_type' => $row['rule_type'],
            'max_weight_per_parcel' => $row['max_weight_per_parcel'] ? (float)$row['max_weight_per_parcel'] : null,
            'max_volume_per_parcel' => $row['max_volume_per_parcel'] ? (float)$row['max_volume_per_parcel'] : null,
            'max_items_per_parcel' => $row['max_items_per_parcel'] ? (int)$row['max_items_per_parcel'] : null,
            'applies_to_unit_types' => $appliesTo,
            'priority' => (int)$row['priority'],
            'active' => (bool)$row['active'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }, $results);
    
    echo json_encode($formattedResults);
}

/**
 * Handle POST requests - create new packaging rule
 */
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }
    
    // Validate required fields
    $required = ['rule_name', 'rule_type'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }
    
    // Validate rule_type
    $validTypes = ['weight_based', 'volume_based', 'count_based', 'product_type'];
    if (!in_array($input['rule_type'], $validTypes)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid rule type']);
        return;
    }
    
    // Check if rule name already exists
    $checkQuery = "SELECT id FROM packaging_rules WHERE rule_name = ?";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$input['rule_name']]);
    
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Rule name already exists']);
        return;
    }
    
    // Process applies_to_unit_types
    $appliesTo = null;
    if (isset($input['applies_to_unit_types']) && is_array($input['applies_to_unit_types'])) {
        $appliesTo = json_encode(array_map('intval', $input['applies_to_unit_types']));
    }
    
    // Insert new packaging rule
    $query = "
        INSERT INTO packaging_rules (
            rule_name, rule_type, max_weight_per_parcel, max_volume_per_parcel,
            max_items_per_parcel, applies_to_unit_types, priority, active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ";
    
    $stmt = $db->prepare($query);
    $result = $stmt->execute([
        $input['rule_name'],
        $input['rule_type'],
        $input['max_weight_per_parcel'] ?? null,
        $input['max_volume_per_parcel'] ?? null,
        $input['max_items_per_parcel'] ?? null,
        $appliesTo,
        $input['priority'] ?? 0,
        isset($input['active']) ? (bool)$input['active'] : true
    ]);
    
    if ($result) {
        $id = $db->lastInsertId();
        echo json_encode([
            'success' => true,
            'id' => $id,
            'message' => 'Packaging rule created successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create packaging rule']);
    }
}

/**
 * Handle PUT requests - update existing packaging rule
 */
function handlePut($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing packaging rule ID']);
        return;
    }
    
    // Verify packaging rule exists
    $checkQuery = "SELECT id FROM packaging_rules WHERE id = ?";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$input['id']]);
    
    if (!$checkStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Packaging rule not found']);
        return;
    }
    
    // Check if updating rule name conflicts with existing
    if (isset($input['rule_name'])) {
        $nameCheckQuery = "SELECT id FROM packaging_rules WHERE rule_name = ? AND id != ?";
        $nameCheckStmt = $db->prepare($nameCheckQuery);
        $nameCheckStmt->execute([$input['rule_name'], $input['id']]);
        
        if ($nameCheckStmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Rule name already exists']);
            return;
        }
    }
    
    // Validate rule_type if provided
    if (isset($input['rule_type'])) {
        $validTypes = ['weight_based', 'volume_based', 'count_based', 'product_type'];
        if (!in_array($input['rule_type'], $validTypes)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid rule type']);
            return;
        }
    }
    
    // Build dynamic update query
    $updateFields = [];
    $updateValues = [];
    
    $allowedFields = [
        'rule_name', 'rule_type', 'max_weight_per_parcel', 'max_volume_per_parcel',
        'max_items_per_parcel', 'priority', 'active'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateFields[] = "$field = ?";
            
            if ($field === 'active') {
                $updateValues[] = (bool)$input[$field];
            } else {
                $updateValues[] = $input[$field];
            }
        }
    }
    
    // Handle applies_to_unit_types separately
    if (isset($input['applies_to_unit_types'])) {
        $updateFields[] = "applies_to_unit_types = ?";
        
        if (is_array($input['applies_to_unit_types'])) {
            $updateValues[] = json_encode(array_map('intval', $input['applies_to_unit_types']));
        } else if (is_string($input['applies_to_unit_types'])) {
            // Assume it's already JSON encoded
            $updateValues[] = $input['applies_to_unit_types'];
        } else {
            $updateValues[] = null;
        }
    }
    
    if (empty($updateFields)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid fields to update']);
        return;
    }
    
    $updateFields[] = 'updated_at = CURRENT_TIMESTAMP';
    $updateValues[] = $input['id'];
    
    $query = "UPDATE packaging_rules SET " . implode(', ', $updateFields) . " WHERE id = ?";
    
    $stmt = $db->prepare($query);
    $result = $stmt->execute($updateValues);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Packaging rule updated successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update packaging rule']);
    }
}

/**
 * Handle DELETE requests - hard delete packaging rule
 */
function handleDelete($db) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing packaging rule ID']);
        return;
    }
    
    // Verify packaging rule exists
    $checkQuery = "SELECT id, rule_name FROM packaging_rules WHERE id = ?";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$id]);
    $rule = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$rule) {
        http_response_code(404);
        echo json_encode(['error' => 'Packaging rule not found']);
        return;
    }
    
    // Check if rule is a system default (protect important rules)
    $protectedRules = ['Default - standard', 'Lichide - parcel separat'];
    if (in_array($rule['rule_name'], $protectedRules)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Cannot delete system default packaging rule'
        ]);
        return;
    }
    
    // Hard delete the packaging rule
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