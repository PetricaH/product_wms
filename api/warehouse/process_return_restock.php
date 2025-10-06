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

    $returnId = isset($input['return_id']) ? (int)$input['return_id'] : 0;
    $orderId = isset($input['order_id']) ? (int)$input['order_id'] : 0;

    if ($returnId <= 0) {
        throw new InvalidArgumentException('ID-ul returului este obligatoriu.');
    }

    require_once BASE_PATH . '/models/Inventory.php';

    $returnStmt = $db->prepare(
        'SELECT r.id, r.order_id, r.status, o.order_number, o.customer_name
         FROM returns r
         JOIN orders o ON o.id = r.order_id
         WHERE r.id = :return_id
         LIMIT 1'
    );
    $returnStmt->execute([':return_id' => $returnId]);
    $returnRow = $returnStmt->fetch(PDO::FETCH_ASSOC);

    if (!$returnRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Returul nu a fost găsit.']);
        exit;
    }

    $orderIdFromReturn = (int)$returnRow['order_id'];
    if ($orderId > 0 && $orderId !== $orderIdFromReturn) {
        throw new InvalidArgumentException('Returul nu aparține comenzii selectate.');
    }

    $orderId = $orderIdFromReturn;
    $orderNumber = $returnRow['order_number'] ?? ('#' . $orderId);

    $inventoryModel = new Inventory($db);
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $itemsStmt = $db->prepare(
        'SELECT
            ri.id,
            ri.product_id,
            ri.quantity_returned,
            ri.location_id,
            ri.item_condition,
            ri.notes,
            p.name AS product_name,
            l.location_code,
            oi.id AS order_item_id
         FROM return_items ri
         JOIN order_items oi ON oi.id = ri.order_item_id
         JOIN products p ON p.product_id = ri.product_id
         LEFT JOIN locations l ON l.id = ri.location_id
         WHERE ri.return_id = :return_id'
    );
    $itemsStmt->execute([':return_id' => $returnId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        throw new InvalidArgumentException('Nu există produse înregistrate pentru acest retur.');
    }

    $locationCheckStmt = $db->prepare('SELECT status FROM locations WHERE id = :id LIMIT 1');
    $inventoryLookupStmt = $db->prepare(
        'SELECT id, shelf_level, subdivision_number
         FROM inventory
         WHERE product_id = :product_id AND location_id = :location_id
         LIMIT 1'
    );

    $processedCount = 0;
    $totalQuantity = 0;

    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $locationId = (int)($item['location_id'] ?? 0);
        $restockQty = (int)($item['quantity_returned'] ?? 0);

        if ($productId <= 0 || $locationId <= 0 || $restockQty <= 0) {
            continue; // Skip items without quantity or location
        }

        $locationCheckStmt->execute([':id' => $locationId]);
        $locationStatus = $locationCheckStmt->fetchColumn();
        if ($locationStatus !== 'active') {
            throw new InvalidArgumentException('Locația selectată pentru produsul ' . $productId . ' nu este activă.');
        }

        $inventoryLookupStmt->execute([
            ':product_id' => $productId,
            ':location_id' => $locationId
        ]);
        $inventoryRow = $inventoryLookupStmt->fetch(PDO::FETCH_ASSOC);

        $inventoryId = $inventoryRow ? (int)$inventoryRow['id'] : 0;
        $shelfLevel = $inventoryRow['shelf_level'] ?? null;
        $subdivisionNumber = isset($inventoryRow['subdivision_number']) ? (int)$inventoryRow['subdivision_number'] : null;

        $metadata = [
            'transaction_type' => 'return',
            'reason' => 'Restocare retur',
            'reference_type' => 'return',
            'reference_id' => $returnId,
            'notes' => trim(($item['notes'] ?? '') !== '' ? $item['notes'] : 'Return din comanda ' . $orderNumber),
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
                'reference_id' => $returnId,
                'notes' => $metadata['notes'],
                'user_id' => $userId
            ];

            if ($shelfLevel !== null) {
                $addData['shelf_level'] = $shelfLevel;
            }
            if ($subdivisionNumber !== null) {
                $addData['subdivision_number'] = $subdivisionNumber;
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
        throw new RuntimeException('Niciun produs valid nu a fost găsit pentru readăugare.');
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
