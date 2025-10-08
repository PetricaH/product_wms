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
$addressText = trim((string)($input['address_text'] ?? ''));
$recipientCountyName = trim((string)($input['recipient_county_name'] ?? ''));
$recipientCountyId = $input['recipient_county_id'] ?? null;
$recipientLocalityName = trim((string)($input['recipient_locality_name'] ?? ''));
$recipientLocalityId = $input['recipient_locality_id'] ?? null;

$recipientCountyId = filter_var($recipientCountyId, FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
if ($recipientCountyId !== null && $recipientCountyId <= 0) {
    $recipientCountyId = null;
}

$recipientLocalityId = filter_var($recipientLocalityId, FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
if ($recipientLocalityId !== null && $recipientLocalityId <= 0) {
    $recipientLocalityId = null;
}

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

    if ($addressText === '') {
        $updateParts[] = 'address_text = NULL';
    } else {
        $updateParts[] = 'address_text = :address_text';
        $params[':address_text'] = $addressText;
    }

    if ($recipientCountyId === null) {
        $updateParts[] = 'recipient_county_id = NULL';
    } else {
        $updateParts[] = 'recipient_county_id = :recipient_county_id';
        $params[':recipient_county_id'] = $recipientCountyId;
    }

    if ($recipientCountyName === '') {
        $updateParts[] = 'recipient_county_name = NULL';
    } else {
        $updateParts[] = 'recipient_county_name = :recipient_county_name';
        $params[':recipient_county_name'] = $recipientCountyName;
    }

    if ($recipientLocalityId === null) {
        $updateParts[] = 'recipient_locality_id = NULL';
    } else {
        $updateParts[] = 'recipient_locality_id = :recipient_locality_id';
        $params[':recipient_locality_id'] = $recipientLocalityId;
    }

    if ($recipientLocalityName === '') {
        $updateParts[] = 'recipient_locality_name = NULL';
    } else {
        $updateParts[] = 'recipient_locality_name = :recipient_locality_name';
        $params[':recipient_locality_name'] = $recipientLocalityName;
    }

    $updateParts[] = 'updated_at = NOW()';

    $query = 'UPDATE orders SET ' . implode(', ', $updateParts) . ' WHERE id = :id LIMIT 1';

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
        } elseif (is_int($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
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
            'shipping_address' => $existingOrder['shipping_address'] ?? null,
            'address_text' => $existingOrder['address_text'] ?? null,
            'recipient_county_id' => $existingOrder['recipient_county_id'] ?? null,
            'recipient_county_name' => $existingOrder['recipient_county_name'] ?? null,
            'recipient_locality_id' => $existingOrder['recipient_locality_id'] ?? null,
            'recipient_locality_name' => $existingOrder['recipient_locality_name'] ?? null
        ],
        [
            'customer_email' => $customerEmail !== '' ? $customerEmail : null,
            'shipping_address' => $shippingAddress !== '' ? $shippingAddress : null,
            'address_text' => $addressText !== '' ? $addressText : null,
            'recipient_county_id' => $recipientCountyId,
            'recipient_county_name' => $recipientCountyName !== '' ? $recipientCountyName : null,
            'recipient_locality_id' => $recipientLocalityId,
            'recipient_locality_name' => $recipientLocalityName !== '' ? $recipientLocalityName : null
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
