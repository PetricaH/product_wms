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
    $assignedLocation = findAssignedLocationForProduct($db, $productId);

    if ($assignedLocation) {
        $inventoryRecord = findInventoryRecordForLocation($db, $productId, (int)$assignedLocation['location_id']);

        if ($inventoryRecord) {
            return [
                'inventory_id' => (int)$inventoryRecord['inventory_id'],
                'location_id' => (int)$assignedLocation['location_id'],
                'location_code' => $assignedLocation['location_code'],
                'shelf_level' => $inventoryRecord['shelf_level'] ?? ($assignedLocation['shelf_level'] ?? null),
                'subdivision_number' => $inventoryRecord['subdivision_number'] !== null
                    ? (int)$inventoryRecord['subdivision_number']
                    : ($assignedLocation['subdivision_number'] ?? null)
            ];
        }

        return [
            'inventory_id' => null,
            'location_id' => (int)$assignedLocation['location_id'],
            'location_code' => $assignedLocation['location_code'],
            'shelf_level' => $assignedLocation['shelf_level'] ?? null,
            'subdivision_number' => isset($assignedLocation['subdivision_number']) && $assignedLocation['subdivision_number'] !== null
                ? (int)$assignedLocation['subdivision_number']
                : null
        ];
    }

    $inventoryLocation = findPrimaryInventoryPlacement($db, $productId);

    if ($inventoryLocation) {
        return [
            'inventory_id' => (int)$inventoryLocation['inventory_id'],
            'location_id' => (int)$inventoryLocation['location_id'],
            'location_code' => $inventoryLocation['location_code'],
            'shelf_level' => $inventoryLocation['shelf_level'],
            'subdivision_number' => $inventoryLocation['subdivision_number'] !== null
                ? (int)$inventoryLocation['subdivision_number']
                : null
        ];
    }

    return null;
}

function findAssignedLocationForProduct(PDO $db, int $productId): ?array
{
    $dedicatedSubdivision = findDedicatedSubdivisionForProduct($db, $productId);
    if ($dedicatedSubdivision) {
        return $dedicatedSubdivision;
    }

    $dedicatedLevel = findDedicatedLevelForProduct($db, $productId);
    if ($dedicatedLevel) {
        return $dedicatedLevel;
    }

    $allowedLevel = findAllowedLevelForProduct($db, $productId);
    if ($allowedLevel) {
        return $allowedLevel;
    }

    return null;
}

function findDedicatedSubdivisionForProduct(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare(
        "SELECT
            ls.location_id,
            l.location_code,
            ls.subdivision_number,
            COALESCE(lls.level_name, CONCAT('Nivel ', ls.level_number)) AS shelf_level
         FROM location_subdivisions ls
         JOIN locations l ON ls.location_id = l.id
         LEFT JOIN location_level_settings lls
            ON lls.location_id = ls.location_id AND lls.level_number = ls.level_number
         WHERE ls.dedicated_product_id = :product_id
         ORDER BY (l.status = 'active') DESC, ls.updated_at DESC, ls.subdivision_number ASC
         LIMIT 1"
    );
    $stmt->execute([':product_id' => $productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'location_id' => (int)$row['location_id'],
        'location_code' => $row['location_code'],
        'shelf_level' => $row['shelf_level'] ?: null,
        'subdivision_number' => $row['subdivision_number'] !== null ? (int)$row['subdivision_number'] : null
    ];
}

function findDedicatedLevelForProduct(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare(
        "SELECT
            lls.location_id,
            l.location_code,
            COALESCE(lls.level_name, CONCAT('Nivel ', lls.level_number)) AS shelf_level
         FROM location_level_settings lls
         JOIN locations l ON lls.location_id = l.id
         WHERE lls.dedicated_product_id = :product_id
         ORDER BY (l.status = 'active') DESC, lls.updated_at DESC, lls.level_number ASC
         LIMIT 1"
    );
    $stmt->execute([':product_id' => $productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'location_id' => (int)$row['location_id'],
        'location_code' => $row['location_code'],
        'shelf_level' => $row['shelf_level'] ?: null,
        'subdivision_number' => null
    ];
}

function findAllowedLevelForProduct(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare(
        "SELECT
            lls.location_id,
            l.location_code,
            COALESCE(lls.level_name, CONCAT('Nivel ', lls.level_number)) AS shelf_level
         FROM location_level_settings lls
         JOIN locations l ON lls.location_id = l.id
         WHERE lls.allowed_product_types IS NOT NULL
           AND (
                JSON_CONTAINS(lls.allowed_product_types, :product_numeric, '$')
                OR JSON_CONTAINS(lls.allowed_product_types, :product_string, '$')
           )
         ORDER BY (l.status = 'active') DESC, lls.priority_order ASC, lls.level_number ASC
         LIMIT 1"
    );

    $stmt->execute([
        ':product_numeric' => json_encode((int)$productId),
        ':product_string' => json_encode((string)$productId)
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'location_id' => (int)$row['location_id'],
        'location_code' => $row['location_code'],
        'shelf_level' => $row['shelf_level'] ?: null,
        'subdivision_number' => null
    ];
}

function findInventoryRecordForLocation(PDO $db, int $productId, int $locationId): ?array
{
    $stmt = $db->prepare(
        "SELECT
            i.id AS inventory_id,
            i.shelf_level,
            i.subdivision_number
         FROM inventory i
         WHERE i.product_id = :product_id
           AND i.location_id = :location_id
         ORDER BY i.quantity DESC, i.received_at ASC
         LIMIT 1"
    );
    $stmt->execute([
        ':product_id' => $productId,
        ':location_id' => $locationId
    ]);

    $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inventory) {
        return null;
    }

    return [
        'inventory_id' => (int)$inventory['inventory_id'],
        'shelf_level' => $inventory['shelf_level'] ?? null,
        'subdivision_number' => $inventory['subdivision_number'] !== null ? (int)$inventory['subdivision_number'] : null
    ];
}

function findPrimaryInventoryPlacement(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare(
        "SELECT
            i.id AS inventory_id,
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
