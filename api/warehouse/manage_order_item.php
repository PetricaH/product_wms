<?php
// File: api/warehouse/manage_order_item.php - CRUD operations for order items
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

$action = strtolower(trim((string)($input['action'] ?? '')));
$orderId = filter_var($input['order_id'] ?? null, FILTER_VALIDATE_INT);

if (!$orderId || !in_array($action, ['create', 'update', 'delete'], true)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Date invalide pentru procesare.'
    ]);
    exit;
}

try {
    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();
    $db->beginTransaction();

    require_once BASE_PATH . '/models/Order.php';
    $orderModel = new Order($db);
    $order = $orderModel->getOrderById($orderId);

    if (!$order) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Comanda nu a fost găsită.'
        ]);
        exit;
    }

    $itemId = null;
    if (isset($input['order_item_id'])) {
        $itemId = filter_var($input['order_item_id'], FILTER_VALIDATE_INT);
    }

    switch ($action) {
        case 'delete':
            if (!$itemId) {
                throw new InvalidArgumentException('ID-ul produsului este necesar pentru ștergere.');
            }

            $item = fetchOrderItem($db, $orderId, $itemId);
            if (!$item) {
                throw new RuntimeException('Produsul specificat nu a fost găsit în această comandă.');
            }

            $deleteStmt = $db->prepare('DELETE FROM order_items WHERE id = :id AND order_id = :order_id LIMIT 1');
            $deleteStmt->execute([
                ':id' => $itemId,
                ':order_id' => $orderId
            ]);

            logActivity(
                $_SESSION['user_id'] ?? 0,
                'delete',
                'order_item',
                $itemId,
                'Produs eliminat din comandă',
                [
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'] ?? null,
                    'quantity' => $item['quantity'] ?? $item['quantity_ordered'] ?? null
                ],
                null,
                'order',
                $orderId
            );
            break;

        case 'create':
            $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = (int)($input['quantity'] ?? 0);
            $unitPrice = normalizeNumericValue($input['unit_price'] ?? null);

            if (!$productId || $quantity <= 0) {
                throw new InvalidArgumentException('Produsul și cantitatea sunt obligatorii.');
            }

            $product = fetchProduct($db, $productId);
            if (!$product) {
                throw new RuntimeException('Produsul selectat nu există.');
            }

            if ($unitPrice === null || $unitPrice < 0) {
                $unitPrice = isset($product['price']) ? (float)$product['price'] : 0.0;
            }

            $unitMeasure = $product['unit_of_measure'] ?? 'buc';
            $columns = ['order_id', 'product_id'];
            $placeholders = [':order_id', ':product_id'];
            $params = [
                ':order_id' => $orderId,
                ':product_id' => $productId
            ];

            $quantityColumns = buildQuantityColumns($db, $quantity, $params, $columns, $placeholders);
            $columns = $quantityColumns['columns'];
            $placeholders = $quantityColumns['placeholders'];
            $params = $quantityColumns['params'];

            if (tableColumnExists($db, 'order_items', 'unit_price')) {
                $columns[] = 'unit_price';
                $placeholders[] = ':unit_price';
                $params[':unit_price'] = $unitPrice;
            }

            if (tableColumnExists($db, 'order_items', 'total_price')) {
                $columns[] = 'total_price';
                $placeholders[] = ':total_price';
                $params[':total_price'] = $unitPrice * $quantity;
            }

            if (tableColumnExists($db, 'order_items', 'unit_measure')) {
                $columns[] = 'unit_measure';
                $placeholders[] = ':unit_measure';
                $params[':unit_measure'] = $unitMeasure;
            }

            if (tableColumnExists($db, 'order_items', 'picked_quantity')) {
                $columns[] = 'picked_quantity';
                $placeholders[] = ':picked_quantity';
                $params[':picked_quantity'] = 0;
            }

            if (tableColumnExists($db, 'order_items', 'notes')) {
                $columns[] = 'notes';
                $placeholders[] = ':notes';
                $params[':notes'] = '';
            }

            $insertQuery = sprintf(
                'INSERT INTO order_items (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', $placeholders)
            );

            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->execute($params);
            $itemId = (int)$db->lastInsertId();

            logActivity(
                $_SESSION['user_id'] ?? 0,
                'create',
                'order_item',
                $itemId,
                'Produs adăugat în comandă',
                null,
                [
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice
                ],
                'order',
                $orderId
            );
            break;

        case 'update':
            if (!$itemId) {
                throw new InvalidArgumentException('ID-ul produsului este necesar pentru modificare.');
            }

            $item = fetchOrderItem($db, $orderId, $itemId);
            if (!$item) {
                throw new RuntimeException('Produsul specificat nu a fost găsit în această comandă.');
            }

            $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT) ?: (int)$item['product_id'];
            $quantity = (int)($input['quantity'] ?? ($item['quantity'] ?? $item['quantity_ordered'] ?? 0));
            $unitPriceInput = normalizeNumericValue($input['unit_price'] ?? null);
            $unitPrice = $unitPriceInput !== null ? $unitPriceInput : (isset($item['unit_price']) ? (float)$item['unit_price'] : 0.0);

            if ($quantity <= 0) {
                throw new InvalidArgumentException('Cantitatea trebuie să fie mai mare decât zero.');
            }

            if ($item['picked_quantity'] ?? 0 > $quantity) {
                throw new RuntimeException('Cantitatea nu poate fi mai mică decât cantitatea deja ridicată.');
            }

            $product = fetchProduct($db, $productId);
            if (!$product) {
                throw new RuntimeException('Produsul selectat nu există.');
            }

            if ($unitPrice < 0) {
                $unitPrice = isset($product['price']) ? (float)$product['price'] : 0.0;
            }

            $unitMeasure = $product['unit_of_measure'] ?? ($item['unit_measure'] ?? 'buc');

            $updateParts = ['product_id = :product_id'];
            $params = [
                ':order_id' => $orderId,
                ':id' => $itemId,
                ':product_id' => $productId
            ];

            $quantityColumns = buildQuantityColumns($db, $quantity, $params);
            $updateParts = array_merge($updateParts, $quantityColumns['setParts']);
            $params = $quantityColumns['params'];

            if (tableColumnExists($db, 'order_items', 'unit_price')) {
                $updateParts[] = 'unit_price = :unit_price';
                $params[':unit_price'] = $unitPrice;
            }

            if (tableColumnExists($db, 'order_items', 'total_price')) {
                $updateParts[] = 'total_price = :total_price';
                $params[':total_price'] = $unitPrice * $quantity;
            }

            if (tableColumnExists($db, 'order_items', 'unit_measure')) {
                $updateParts[] = 'unit_measure = :unit_measure';
                $params[':unit_measure'] = $unitMeasure;
            }

            $updateQuery = 'UPDATE order_items SET ' . implode(', ', $updateParts) . ' WHERE id = :id AND order_id = :order_id';
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute($params);

            logActivity(
                $_SESSION['user_id'] ?? 0,
                'update',
                'order_item',
                $itemId,
                'Produs actualizat în comandă',
                [
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'] ?? null,
                    'quantity' => $item['quantity'] ?? $item['quantity_ordered'] ?? null,
                    'unit_price' => $item['unit_price'] ?? null
                ],
                [
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice
                ],
                'order',
                $orderId
            );
            break;
    }

    recalculateOrderSummary($db, $orderModel, $orderId);

    $db->commit();

    echo json_encode([
        'status' => 'success',
        'message' => $action === 'delete'
            ? 'Produsul a fost eliminat din comandă.'
            : 'Comanda a fost actualizată.'
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    $message = $e->getMessage();
    $statusCode = $e instanceof InvalidArgumentException ? 422 : ($e instanceof RuntimeException ? 400 : 500);

    if ($statusCode === 500) {
        error_log('manage_order_item error: ' . $e->getMessage());
        $message = 'A apărut o eroare la actualizarea produselor comenzii.';
    }

    http_response_code($statusCode);
    echo json_encode([
        'status' => 'error',
        'message' => $message
    ]);
}

function normalizeNumericValue($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_string($value)) {
        $value = str_replace([' ', ','], ['', '.'], $value);
    }

    return is_numeric($value) ? (float)$value : null;
}

function fetchOrderItem(PDO $db, int $orderId, int $itemId): ?array
{
    $stmt = $db->prepare('SELECT * FROM order_items WHERE id = :id AND order_id = :order_id');
    $stmt->execute([
        ':id' => $itemId,
        ':order_id' => $orderId
    ]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    return $item ?: null;
}

function fetchProduct(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare('SELECT product_id, price, unit_of_measure, name FROM products WHERE product_id = :id');
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    return $product ?: null;
}

function tableColumnExists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute([
        ':table' => $table,
        ':column' => $column
    ]);

    $cache[$key] = (int)$stmt->fetchColumn() > 0;
    return $cache[$key];
}

function buildQuantityColumns(PDO $db, int $quantity, array $params, array $columns = [], array $placeholders = []): array
{
    if (empty($columns)) {
        $columns = [];
    }
    if (empty($placeholders)) {
        $placeholders = [];
    }

    if (tableColumnExists($db, 'order_items', 'quantity')) {
        $columns[] = 'quantity';
        $placeholders[] = ':quantity';
        $params[':quantity'] = $quantity;
    }

    if (tableColumnExists($db, 'order_items', 'quantity_ordered')) {
        $columns[] = 'quantity_ordered';
        $placeholders[] = ':quantity_ordered';
        $params[':quantity_ordered'] = $quantity;
    }

    return [
        'columns' => $columns,
        'placeholders' => $placeholders,
        'params' => $params,
        'setParts' => array_filter([
            tableColumnExists($db, 'order_items', 'quantity') ? 'quantity = :quantity' : null,
            tableColumnExists($db, 'order_items', 'quantity_ordered') ? 'quantity_ordered = :quantity_ordered' : null
        ])
    ];
}

function recalculateOrderSummary(PDO $db, Order $orderModel, int $orderId): void
{
    $quantityColumn = tableColumnExists($db, 'order_items', 'quantity') ? 'quantity' : (tableColumnExists($db, 'order_items', 'quantity_ordered') ? 'quantity_ordered' : null);
    $unitPriceColumnExists = tableColumnExists($db, 'order_items', 'unit_price');
    $pickedQuantityExists = tableColumnExists($db, 'order_items', 'picked_quantity');

    $totalsQueryParts = [];
    if ($quantityColumn !== null) {
        $totalsQueryParts[] = 'SUM(COALESCE(' . $quantityColumn . ', 0)) AS total_quantity';
    }
    if ($unitPriceColumnExists && $quantityColumn !== null) {
        $totalsQueryParts[] = 'SUM(COALESCE(' . $quantityColumn . ', 0) * COALESCE(unit_price, 0)) AS total_value';
    }
    if ($pickedQuantityExists) {
        $totalsQueryParts[] = 'SUM(COALESCE(picked_quantity, 0)) AS total_picked';
    }

    $totals = [
        'total_quantity' => 0,
        'total_value' => 0,
        'total_picked' => 0
    ];

    if (!empty($totalsQueryParts)) {
        $totalsQuery = 'SELECT ' . implode(', ', $totalsQueryParts) . ' FROM order_items WHERE order_id = :order_id';
        $totalsStmt = $db->prepare($totalsQuery);
        $totalsStmt->execute([':order_id' => $orderId]);
        $totals = array_merge($totals, array_filter((array)$totalsStmt->fetch(PDO::FETCH_ASSOC)));
    }

    $ordersUpdateParts = [];
    $ordersParams = [':order_id' => $orderId];

    if (tableColumnExists($db, 'orders', 'total_value') && isset($totals['total_value'])) {
        $ordersUpdateParts[] = 'total_value = :total_value';
        $ordersParams[':total_value'] = (float)$totals['total_value'];
    }

    if (tableColumnExists($db, 'orders', 'updated_at')) {
        $ordersUpdateParts[] = 'updated_at = NOW()';
    }

    if (tableColumnExists($db, 'orders', 'items_count')) {
        $countStmt = $db->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :order_id');
        $countStmt->execute([':order_id' => $orderId]);
        $ordersUpdateParts[] = 'items_count = :items_count';
        $ordersParams[':items_count'] = (int)$countStmt->fetchColumn();
    }

    if (!empty($ordersUpdateParts)) {
        $ordersUpdate = 'UPDATE orders SET ' . implode(', ', $ordersUpdateParts) . ' WHERE id = :order_id';
        $ordersStmt = $db->prepare($ordersUpdate);
        $ordersStmt->execute($ordersParams);
    }

    try {
        if (method_exists($orderModel, 'recalculateShipping')) {
            $orderModel->recalculateShipping($orderId);
        }
    } catch (Throwable $e) {
        error_log('manage_order_item recalculation warning: ' . $e->getMessage());
    }
}
