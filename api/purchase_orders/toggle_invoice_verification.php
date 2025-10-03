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
    echo json_encode(['success' => false, 'message' => 'Metodă neacceptată.']);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acces interzis.']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configurare bază de date invalidă.']);
    exit;
}

$db = $config['connection_factory']();

$payload = file_get_contents('php://input');
$decoded = $payload ? json_decode($payload, true) : null;

if (!is_array($decoded)) {
    $decoded = $_POST;
}

try {
    $orderId = (int)($decoded['order_id'] ?? 0);
    $verified = !empty($decoded['verified']);
    if ($orderId <= 0) {
        throw new RuntimeException('ID-ul comenzii este invalid.');
    }

    $stmt = $db->prepare('SELECT invoice_file_path FROM purchase_orders WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('Comanda de achiziție nu a fost găsită.');
    }

    if ($verified && empty($order['invoice_file_path'])) {
        throw new RuntimeException('Nu se poate marca factura ca verificată înainte de încărcare.');
    }

    $updateSql = "
        UPDATE purchase_orders
        SET invoice_verified = :verified,
            invoice_verified_by = CASE WHEN :verified = 1 THEN :user_id ELSE NULL END,
            invoice_verified_at = CASE WHEN :verified = 1 THEN NOW() ELSE NULL END,
            updated_at = NOW()
        WHERE id = :id
    ";

    $updateStmt = $db->prepare($updateSql);
    $updateStmt->execute([
        ':verified' => $verified ? 1 : 0,
        ':user_id' => $_SESSION['user_id'],
        ':id' => $orderId,
    ]);

    $infoStmt = $db->prepare('SELECT invoice_verified, invoice_verified_at, uv.username AS verified_by FROM purchase_orders po LEFT JOIN users uv ON po.invoice_verified_by = uv.id WHERE po.id = :id');
    $infoStmt->execute([':id' => $orderId]);
    $result = $infoStmt->fetch(PDO::FETCH_ASSOC);

    $verifiedAt = null;
    $verifiedBy = null;
    if (!empty($result['invoice_verified']) && !empty($result['invoice_verified_at'])) {
        $verifiedAt = date('d.m.Y H:i', strtotime($result['invoice_verified_at']));
        $verifiedBy = $result['verified_by'] ?? null;
    }

    echo json_encode([
        'success' => true,
        'message' => $verified ? 'Factura a fost marcată ca verificată.' : 'Statusul verificării a fost resetat.',
        'verified' => $verified,
        'verified_at' => $verifiedAt,
        'verified_by' => $verifiedBy,
    ]);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}
