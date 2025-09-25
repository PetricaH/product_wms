<?php
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (function_exists('apache_request_headers')) {
    $headers = array_change_key_case(apache_request_headers(), CASE_UPPER);
    $csrfToken = $csrfToken ?: ($headers['X-CSRF-TOKEN'] ?? '');
}

if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $config = require BASE_PATH . '/config/config.php';
    $dbFactory = $config['connection_factory'] ?? null;
    if (!$dbFactory || !is_callable($dbFactory)) {
        throw new RuntimeException('Database connection not available');
    }
    $db = $dbFactory();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON payload');
    }

    $orderId = isset($input['order_id']) ? (int)$input['order_id'] : 0;
    $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : [];

    if ($orderId <= 0 || empty($items)) {
        throw new InvalidArgumentException('Order ID și lista de produse sunt obligatorii.');
    }

    require_once BASE_PATH . '/models/Inventory.php';

    $orderStmt = $db->prepare('SELECT id, order_number, customer_name, status FROM orders WHERE id = :id');
    $orderStmt->execute([':id' => $orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Comanda nu a fost găsită.']);
        exit;
    }

    $inventoryModel = new Inventory($db);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $orderNumber = $order['order_number'] ?? ('#' . $orderId);

    $processedCount = 0;
    $totalQuantity = 0;

    foreach ($items as $item) {
        $productId = isset($item['product_id']) ? (int)$item['product_id'] : 0;
        $restockQty = isset($item['restock_quantity']) ? (int)$item['restock_quantity'] : 0;
        $locationId = isset($item['location_id']) ? (int)$item['location_id'] : 0;
        $inventoryId = isset($item['inventory_id']) ? (int)$item['inventory_id'] : 0;
        $shelfLevel = $item['shelf_level'] ?? null;
        $subdivisionNumber = isset($item['subdivision_number']) ? (int)$item['subdivision_number'] : null;

        if ($productId <= 0 || $restockQty <= 0 || $locationId <= 0) {
            throw new InvalidArgumentException('Informațiile despre produs și locație sunt incomplete.');
        }

        $metadata = [
            'transaction_type' => 'return',
            'reason' => 'Restocare retur',
            'reference_type' => 'return',
            'reference_id' => $orderId,
            'notes' => 'Return din comanda ' . $orderNumber,
            'user_id' => $userId,
            'shelf_level' => $shelfLevel,
            'subdivision_number' => $subdivisionNumber
        ];

        $success = false;
        if ($inventoryId > 0) {
            $success = $inventoryModel->increaseInventoryQuantity($inventoryId, $restockQty, $metadata);
        } else {
            $addData = [
                'product_id' => $productId,
                'location_id' => $locationId,
                'quantity' => $restockQty,
                'received_at' => date('Y-m-d H:i:s'),
                'transaction_type' => 'return',
                'reason' => 'Restocare retur',
                'reference_type' => 'return',
                'reference_id' => $orderId,
                'notes' => 'Return din comanda ' . $orderNumber,
                'user_id' => $userId
            ];

            if ($shelfLevel !== null) {
                $addData['shelf_level'] = $shelfLevel;
            }
            if ($subdivisionNumber !== null) {
                $addData['subdivision_number'] = $subdivisionNumber;
            }
            if (!empty($item['batch_number'])) {
                $addData['batch_number'] = $item['batch_number'];
            }
            if (!empty($item['lot_number'])) {
                $addData['lot_number'] = $item['lot_number'];
            }

            $success = (bool)$inventoryModel->addStock($addData);
        }

        if (!$success) {
            throw new RuntimeException('Nu am putut readăuga produsul ' . $productId . ' în stoc.');
        }

        $processedCount++;
        $totalQuantity += $restockQty;
    }

    if ($processedCount === 0) {
        throw new RuntimeException('Niciun produs nu a fost readăugat în stoc.');
    }

    echo json_encode([
        'success' => true,
        'message' => sprintf('Au fost readăugate %d produse în stoc (%d bucăți).', $processedCount, $totalQuantity)
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('process_return_restock error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A apărut o eroare la procesarea returului.']);
}
