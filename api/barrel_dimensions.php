<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
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

function handleGet($db) {
    $stmt = $db->query("SELECT id, label, length_cm, width_cm, height_cm FROM barrel_dimensions ORDER BY id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['data' => $rows]);
}

function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['label'], $input['length_cm'], $input['width_cm'], $input['height_cm'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        return;
    }
    $stmt = $db->prepare("INSERT INTO barrel_dimensions (label, length_cm, width_cm, height_cm) VALUES (?, ?, ?, ?)");
    $ok = $stmt->execute([
        $input['label'],
        $input['length_cm'],
        $input['width_cm'],
        $input['height_cm']
    ]);
    if ($ok) {
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to add dimension']);
    }
}

function handleDelete($db) {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id']);
        return;
    }
    $stmt = $db->prepare("DELETE FROM barrel_dimensions WHERE id = ?");
    $ok = $stmt->execute([$id]);
    echo json_encode(['success' => (bool)$ok]);
}
