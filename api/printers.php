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

function handleGet(PDO $db) {
    $stmt = $db->query("SELECT id, name, network_address FROM printers ORDER BY name ASC");
    $printers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $formatted = array_map(function ($row) {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'network_address' => $row['network_address'],
        ];
    }, $printers);

    echo json_encode($formatted);
}

function handlePost(PDO $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['name']) || empty($input['network_address'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        return;
    }

    $stmt = $db->prepare('INSERT INTO printers (name, network_address) VALUES (?, ?)');
    $result = $stmt->execute([$input['name'], $input['network_address']]);

    if ($result) {
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create printer']);
    }
}

function handlePut(PDO $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing printer ID']);
        return;
    }

    $fields = [];
    $values = [];

    if (isset($input['name'])) {
        $fields[] = 'name = ?';
        $values[] = $input['name'];
    }
    if (isset($input['network_address'])) {
        $fields[] = 'network_address = ?';
        $values[] = $input['network_address'];
    }

    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['error' => 'No data to update']);
        return;
    }

    $values[] = $input['id'];
    $stmt = $db->prepare('UPDATE printers SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $result = $stmt->execute($values);

    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update printer']);
    }
}

function handleDelete(PDO $db) {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing printer ID']);
        return;
    }

    $stmt = $db->prepare('DELETE FROM printers WHERE id = ?');
    $result = $stmt->execute([$id]);

    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete printer']);
    }
}
?>
