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

if (empty($_SESSION['user_id'])) {
    throw new RuntimeException('Utilizatorul autentificat este necesar pentru actualizarea observațiilor.');
}

$adminId = (int)$_SESSION['user_id'];

if ($normalizedNotes === '') {
    $stmt = $db->prepare('UPDATE receiving_items SET admin_notes = NULL, admin_notes_updated_by = NULL, admin_notes_updated_at = NULL WHERE id = :id');
    $stmt->execute([':id' => $itemId]);
} else {
    $stmt = $db->prepare('UPDATE receiving_items SET admin_notes = :notes, admin_notes_updated_by = :admin_id, admin_notes_updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        ':notes' => $normalizedNotes,
        ':admin_id' => $adminId,
        ':id' => $itemId,
    ]);
}

$metaStmt = $db->prepare('
    SELECT ri.admin_notes, ri.admin_notes_updated_at, u.username AS admin_notes_updated_by_name
    FROM receiving_items ri
    LEFT JOIN users u ON ri.admin_notes_updated_by = u.id
    WHERE ri.id = :id
    LIMIT 1
');
$metaStmt->execute([':id' => $itemId]);
$meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$updatedAt = null;
if (!empty($meta['admin_notes_updated_at'])) {
    $updatedAt = date('d.m.Y H:i', strtotime($meta['admin_notes_updated_at']));
}

echo json_encode([
    'success' => true,
    'message' => $normalizedNotes === '' ? 'Observațiile au fost eliminate.' : 'Observațiile au fost actualizate.',
    'notes' => $meta['admin_notes'] ?? '',
    'updated_at' => $updatedAt,
    'updated_by' => $meta['admin_notes_updated_by_name'] ?? null,
]);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}
