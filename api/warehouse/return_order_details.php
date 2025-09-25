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

try {
    $config = require BASE_PATH . '/config/config.php';
    $dbFactory = $config['connection_factory'] ?? null;
    if (!$dbFactory || !is_callable($dbFactory)) {
        throw new RuntimeException('Database connection not available');
    }

    $db = $dbFactory();

    $orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Order ID invalid']);
        exit;
    }

    $orderStmt = $db->prepare(
        'SELECT id, order_number, customer_name, status, order_date, updated_at, total_value
         FROM orders
         WHERE id = :id'
    );
    $orderStmt->execute([':id' => $orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Comanda nu a fost găsită.']);
        exit;
    }

    $status = strtolower((string)($order['status'] ?? ''));
    $allowedStatuses = ['picked', 'ready_to_ship', 'processing'];
    if ($status && !in_array($status, $allowedStatuses, true)) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Comanda nu este în stadiu de retur. Status curent: ' . ($order['status'] ?? 'necunoscut')
        ]);
        exit;
    }

    $itemsStmt = $db->prepare(
        'SELECT
            oi.id AS order_item_id,
            oi.product_id,
            oi.quantity AS quantity_ordered,
            COALESCE(oi.picked_quantity, oi.quantity) AS picked_quantity,
            p.name AS product_name,
            p.sku,
            p.barcode
         FROM order_items oi
         JOIN products p ON oi.product_id = p.product_id
         WHERE oi.order_id = :order_id
         ORDER BY oi.id'
    );
    $itemsStmt->execute([':order_id' => $orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $responseItems = [];
    $missingLocations = [];
    $totalRestockQty = 0;

    foreach ($items as $item) {
        $productId = (int)$item['product_id'];
        $pickedQty = (int)($item['picked_quantity'] ?? 0);
        $orderedQty = (int)($item['quantity_ordered'] ?? 0);
        $restockQty = $pickedQty > 0 ? $pickedQty : $orderedQty;
        if ($restockQty < 0) {
            $restockQty = 0;
        }

        $locationInfo = findProductReturnLocation($db, $productId);
        if (!$locationInfo) {
            $missingLocations[] = [
                'product_id' => $productId,
                'product_name' => $item['product_name'],
                'sku' => $item['sku']
            ];
        }

        $responseItems[] = [
            'order_item_id' => (int)$item['order_item_id'],
            'product_id' => $productId,
            'product_name' => $item['product_name'],
            'sku' => $item['sku'],
            'quantity_ordered' => $orderedQty,
            'picked_quantity' => $pickedQty,
            'restock_quantity' => $restockQty,
            'location_id' => $locationInfo['location_id'] ?? null,
            'location_code' => $locationInfo['location_code'] ?? null,
            'inventory_id' => $locationInfo['inventory_id'] ?? null,
            'shelf_level' => $locationInfo['shelf_level'] ?? null,
            'subdivision_number' => $locationInfo['subdivision_number'] ?? null
        ];

        $totalRestockQty += $restockQty;
    }

    $latestActivity = $order['updated_at'] ?: $order['order_date'];

    echo json_encode([
        'success' => true,
        'order' => [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'customer_name' => $order['customer_name'],
            'status' => $order['status'],
            'status_label' => translateOrderStatus($order['status']),
            'latest_activity' => $latestActivity,
            'total_items' => count($responseItems),
            'total_value' => isset($order['total_value']) ? (float)$order['total_value'] : null
        ],
        'items' => $responseItems,
        'missing_locations' => $missingLocations,
        'totals' => [
            'items' => count($responseItems),
            'restock_quantity' => $totalRestockQty,
            'missing_locations' => count($missingLocations)
        ]
    ]);
} catch (Throwable $e) {
    error_log('return_order_details error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A apărut o eroare internă.']);
}

function findProductReturnLocation(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare(
        "SELECT i.id AS inventory_id,
                i.location_id,
                l.location_code,
                i.shelf_level,
                i.subdivision_number,
                i.quantity,
                i.received_at
         FROM inventory i
         JOIN locations l ON i.location_id = l.id
         WHERE i.product_id = :product_id AND i.quantity >= 0
         ORDER BY i.quantity DESC, i.received_at ASC
         LIMIT 1"
    );
    $stmt->execute([':product_id' => $productId]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$location) {
        return null;
    }

    return [
        'inventory_id' => (int)$location['inventory_id'],
        'location_id' => (int)$location['location_id'],
        'location_code' => $location['location_code'],
        'shelf_level' => $location['shelf_level'],
        'subdivision_number' => $location['subdivision_number'] !== null ? (int)$location['subdivision_number'] : null
    ];
}

function translateOrderStatus(?string $status): string
{
    if ($status === null) {
        return 'Necunoscut';
    }

    $map = [
        'picked' => 'Pregătită',
        'ready_to_ship' => 'Gata de expediere',
        'completed' => 'Finalizată',
        'processing' => 'În procesare',
        'pending' => 'În așteptare',
        'assigned' => 'Alocată'
    ];

    $normalized = strtolower($status);
    return $map[$normalized] ?? $status;
}
