<?php
// File: api/warehouse/update_order_details.php - Update contact information for an order
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$allowedRoles = ['admin', 'warehouse', 'warehouse_worker'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Autentificare necesară.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Metodă HTTP neacceptată. Folosește POST.'
    ]);
    exit;
}

if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Configurația bazei de date lipsește.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$orderId = filter_var($input['order_id'] ?? null, FILTER_VALIDATE_INT);
if (!$orderId) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'ID comandă invalid.'
    ]);
    exit;
}

$customerEmail = trim((string)($input['customer_email'] ?? ''));
$shippingAddress = trim((string)($input['shipping_address'] ?? ''));

if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => 'Adresa de email introdusă nu este validă.'
    ]);
    exit;
}

try {
    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    require_once BASE_PATH . '/models/Order.php';
    $orderModel = new Order($db);
    $existingOrder = $orderModel->getOrderById($orderId);

    if (!$existingOrder) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Comanda nu a fost găsită.'
        ]);
        exit;
    }

    $updateParts = [];
    $params = [':id' => $orderId];

    if ($customerEmail === '') {
        $updateParts[] = 'customer_email = NULL';
    } else {
        $updateParts[] = 'customer_email = :customer_email';
        $params[':customer_email'] = $customerEmail;
    }

    if ($shippingAddress === '') {
        $updateParts[] = 'shipping_address = NULL';
    } else {
        $updateParts[] = 'shipping_address = :shipping_address';
        $params[':shipping_address'] = $shippingAddress;
    }

    $updateParts[] = 'updated_at = NOW()';

    $query = 'UPDATE orders SET ' . implode(', ', $updateParts) . ' WHERE id = :id LIMIT 1';

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    $stmt->execute();

    logActivity(
        $_SESSION['user_id'] ?? 0,
        'update',
        'order',
        $orderId,
        'Actualizare detalii client pentru comandă',
        [
            'customer_email' => $existingOrder['customer_email'] ?? null,
            'shipping_address' => $existingOrder['shipping_address'] ?? null
        ],
        [
            'customer_email' => $customerEmail !== '' ? $customerEmail : null,
            'shipping_address' => $shippingAddress !== '' ? $shippingAddress : null
        ]
    );

    echo json_encode([
        'status' => 'success',
        'message' => 'Detaliile comenzii au fost actualizate.'
    ]);
} catch (Throwable $e) {
    error_log('update_order_details error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'A apărut o eroare la actualizarea comenzii.'
    ]);
}
