<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metodă neacceptată.',
    ]);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Acces interzis.',
    ]);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
if (empty($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Configurare bază de date invalidă.',
    ]);
    exit;
}

$db = $config['connection_factory']();

$payload = file_get_contents('php://input');
$data = $payload ? json_decode($payload, true) : null;
if (!is_array($data)) {
    $data = $_POST;
}

try {
    $itemId = (int)($data['receiving_item_id'] ?? 0);
    if ($itemId <= 0) {
        throw new RuntimeException('ID-ul recepției este invalid.');
    }

    $checkStmt = $db->prepare('SELECT id FROM receiving_items WHERE id = :id LIMIT 1');
    $checkStmt->execute([':id' => $itemId]);
    if (!$checkStmt->fetchColumn()) {
        throw new RuntimeException('Recepția specificată nu a fost găsită.');
    }

    $rawNotes = isset($data['notes']) ? (string)$data['notes'] : '';
    $normalizedNotes = str_replace("\0", '', $rawNotes);
    $normalizedNotes = preg_replace("/\r\n?/", "\n", $normalizedNotes ?? '') ?? '';
    $normalizedNotes = trim($normalizedNotes);

    if (mb_strlen($normalizedNotes) > 2000) {
        $normalizedNotes = mb_substr($normalizedNotes, 0, 2000);
    }

    $stmt = $db->prepare('UPDATE receiving_items SET notes = :notes WHERE id = :id');
    $stmt->execute([
        ':notes' => $normalizedNotes === '' ? null : $normalizedNotes,
        ':id' => $itemId,
    ]);

    echo json_encode([
        'success' => true,
        'message' => $normalizedNotes === '' ? 'Observațiile au fost eliminate.' : 'Observațiile au fost actualizate.',
        'notes' => $normalizedNotes,
    ]);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}
