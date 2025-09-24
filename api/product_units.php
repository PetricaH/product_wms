<?php
/**
 * Product Units API
 * File: api/product_units.php
 * 
 * Handles CRUD operations for product unit management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

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
 * Handle GET requests - retrieve product units
 */
function handleGet($db) {
    $id = $_GET['id'] ?? null;

    $query = "
        SELECT
            pu.id,
            pu.product_id,
            pu.weight_per_unit,
            pu.volume_per_unit,
            pu.dimensions_length,
            pu.dimensions_width,
            pu.dimensions_height,
            pu.max_stack_height,
            pu.fragile,
            pu.hazardous,
            pu.temperature_controlled,
            pu.packaging_cost,
            pu.active,
            pu.created_at,
            pu.updated_at,
            p.name as product_name,
            p.sku as product_code,
            ut.unit_code,
            ut.unit_name,
            ut.base_type,
            ut.packaging_type,
            ut.max_items_per_parcel,
            ut.requires_separate_parcel
        FROM product_units pu
        JOIN products p ON pu.product_id = p.product_id
        JOIN unit_types ut ON pu.unit_type_id = ut.id
    ";

    $params = [];
    if ($id) {
        $query .= " WHERE pu.id = ?";
        $params[] = $id;
    } else {
        $query .= " ORDER BY p.name ASC, ut.unit_code ASC";
    }

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format results for frontend
    $formattedResults = array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'product_id' => (int)$row['product_id'],
            'product_name' => $row['product_name'],
            'product_code' => $row['product_code'],
            'unit_code' => $row['unit_code'],
            'unit_name' => $row['unit_name'],
            'base_type' => $row['base_type'],
            'packaging_type' => $row['packaging_type'],
            'weight_per_unit' => (float)$row['weight_per_unit'],
            'volume_per_unit' => $row['volume_per_unit'] ? (float)$row['volume_per_unit'] : null,
            'dimensions' => [
                'length' => $row['dimensions_length'] ? (float)$row['dimensions_length'] : null,
                'width' => $row['dimensions_width'] ? (float)$row['dimensions_width'] : null,
                'height' => $row['dimensions_height'] ? (float)$row['dimensions_height'] : null
            ],
            'max_stack_height' => (int)$row['max_stack_height'],
            'max_items_per_parcel' => (int)$row['max_items_per_parcel'],
            'requires_separate_parcel' => (bool)$row['requires_separate_parcel'],
            'fragile' => (bool)$row['fragile'],
            'hazardous' => (bool)$row['hazardous'],
            'temperature_controlled' => (bool)$row['temperature_controlled'],
            'packaging_cost' => (float)$row['packaging_cost'],
            'active' => (bool)$row['active'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }, $results);
    
    echo json_encode($id ? ($formattedResults[0] ?? null) : $formattedResults);
}

/**
 * Handle POST requests - create new product unit
 */
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }

    if (isset($input['bulk_action'])) {
        handleBulkAction($db, $input);
        return;
    }

    // Validate required fields
    $required = ['product_id', 'unit_type_id', 'weight_per_unit'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }
    
    // Check if combination already exists
    $checkQuery = "SELECT id FROM product_units WHERE product_id = ? AND unit_type_id = ? AND active = 1";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$input['product_id'], $input['unit_type_id']]);
    
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Product unit combination already exists']);
        return;
    }
    
    // Insert new product unit
    $query = "
        INSERT INTO product_units (
            product_id, unit_type_id, weight_per_unit, volume_per_unit,
            dimensions_length, dimensions_width, dimensions_height,
            max_stack_height, fragile, hazardous, temperature_controlled,
            packaging_cost, active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    
    $stmt = $db->prepare($query);
    $result = $stmt->execute([
        $input['product_id'],
        $input['unit_type_id'],
        $input['weight_per_unit'],
        $input['volume_per_unit'] ?? null,
        $input['dimensions_length'] ?? null,
        $input['dimensions_width'] ?? null,
        $input['dimensions_height'] ?? null,
        $input['max_stack_height'] ?? 1,
        !empty($input['fragile']) ? 1 : 0,
        !empty($input['hazardous']) ? 1 : 0,
        !empty($input['temperature_controlled']) ? 1 : 0,
        $input['packaging_cost'] ?? 0.00,
        isset($input['active']) ? (!empty($input['active']) ? 1 : 0) : 1
    ]);
    
    if ($result) {
        $id = $db->lastInsertId();
        echo json_encode([
            'success' => true,
            'id' => $id,
            'message' => 'Product unit created successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create product unit']);
    }
}

/**
 * Handle bulk update/delete actions
 */
function handleBulkAction($db, array $input) {
    $action = $input['bulk_action'] ?? '';
    $ids = $input['ids'] ?? [];

    if (!is_array($ids) || empty($ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing configuration IDs for bulk action']);
        return;
    }

    $ids = array_values(array_filter(array_map('intval', $ids), function ($value) {
        return $value > 0;
    }));

    if (empty($ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid configuration IDs provided']);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    switch ($action) {
        case 'update':
            $updates = $input['updates'] ?? [];
            if (!is_array($updates) || empty($updates)) {
                http_response_code(400);
                echo json_encode(['error' => 'No updates provided for bulk action']);
                return;
            }

            $allowedFields = ['active', 'fragile', 'hazardous', 'temperature_controlled', 'max_items_per_parcel', 'weight_per_unit'];
            $setClauses = [];
            $params = [];

            foreach ($updates as $field => $value) {
                if (!in_array($field, $allowedFields, true)) {
                    continue;
                }

                switch ($field) {
                    case 'active':
                    case 'fragile':
                    case 'hazardous':
                    case 'temperature_controlled':
                        $setClauses[] = "$field = ?";
                        $params[] = $value ? 1 : 0;
                        break;
                    case 'max_items_per_parcel':
                        $setClauses[] = "$field = ?";
                        $params[] = $value !== null ? (int)$value : null;
                        break;
                    case 'weight_per_unit':
                        $setClauses[] = "$field = ?";
                        $params[] = $value !== null ? (float)$value : null;
                        break;
                }
            }

            if (empty($setClauses)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields provided for update']);
                return;
            }

            $setClauses[] = 'updated_at = NOW()';
            $sql = 'UPDATE product_units SET ' . implode(', ', $setClauses) . " WHERE id IN ($placeholders)";
            $params = array_merge($params, $ids);

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            echo json_encode([
                'success' => true,
                'affected' => $stmt->rowCount(),
                'message' => 'Configurările selectate au fost actualizate.'
            ]);
            return;

        case 'delete':
            $sql = "DELETE FROM product_units WHERE id IN ($placeholders)";
            $stmt = $db->prepare($sql);
            $stmt->execute($ids);

            echo json_encode([
                'success' => true,
                'affected' => $stmt->rowCount(),
                'message' => 'Configurările selectate au fost șterse.'
            ]);
            return;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown bulk action']);
            return;
    }
}

/**
 * Handle PUT requests - update existing product unit
 */
function handlePut($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing product unit ID']);
        return;
    }
    
    // Build dynamic update query
    $updateFields = [];
    $updateValues = [];
    
    $allowedFields = [
        'weight_per_unit', 'volume_per_unit', 'dimensions_length',
        'dimensions_width', 'dimensions_height', 'max_stack_height',
        'fragile', 'hazardous', 'temperature_controlled', 'packaging_cost',
        'active'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateFields[] = "$field = ?";
            
            if (in_array($field, ['fragile', 'hazardous', 'temperature_controlled', 'active'])) {
                $updateValues[] = !empty($input[$field]) ? 1 : 0;
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
    
    $query = "UPDATE product_units SET " . implode(', ', $updateFields) . " WHERE id = ?";
    
    $stmt = $db->prepare($query);
    $result = $stmt->execute($updateValues);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Product unit updated successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update product unit']);
    }
}

/**
 * Handle DELETE requests - soft delete product unit
 */
function handleDelete($db) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing product unit ID']);
        return;
    }
    
    $query = "UPDATE product_units SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $db->prepare($query);
    $result = $stmt->execute([$id]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Product unit deleted successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete product unit']);
    }
}
?>