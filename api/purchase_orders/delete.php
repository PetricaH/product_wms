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

require_once BASE_PATH . '/models/PurchaseOrder.php';

try {
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('ID-ul comenzii este invalid.');
    }

    $purchaseOrderModel = new PurchaseOrder($db);

    if (!$purchaseOrderModel->deletePurchaseOrder($orderId)) {
        $error = $purchaseOrderModel->getLastError() ?? 'Nu s-a putut șterge comanda de achiziție.';
        throw new RuntimeException($error);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Comanda de achiziție a fost ștearsă cu succes.'
    ]);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage()
    ]);
}
