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
    $returnId = isset($_GET['return_id']) ? (int)$_GET['return_id'] : 0;

    if ($returnId <= 0 && $orderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Parametri invalidi']);
        exit;
    }

    $order = null;
    $returnInfo = null;

    if ($returnId > 0) {
        $returnStmt = $db->prepare(
            'SELECT
                r.id AS return_id,
                r.status AS return_status,
                r.notes AS return_notes,
                r.return_awb,
                r.return_date,
                r.created_at AS return_created_at,
                r.updated_at AS return_updated_at,
                o.id AS order_id,
                o.order_number,
                o.customer_name,
                o.status AS order_status,
                o.order_date,
                o.updated_at AS order_updated_at,
                o.total_value
             FROM returns r
             JOIN orders o ON o.id = r.order_id
             WHERE r.id = :return_id
             LIMIT 1'
        );
        $returnStmt->execute([':return_id' => $returnId]);
        $row = $returnStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Returul nu a fost găsit.']);
            exit;
        }

        $orderId = (int)$row['order_id'];

        $order = [
            'id' => $orderId,
            'order_number' => $row['order_number'],
            'customer_name' => $row['customer_name'],
            'status' => $row['order_status'],
            'order_date' => $row['order_date'],
            'updated_at' => $row['order_updated_at'],
            'total_value' => $row['total_value']
        ];

        $returnInfo = [
            'id' => (int)$row['return_id'],
            'status' => $row['return_status'],
            'return_date' => $row['return_date'],
            'return_awb' => $row['return_awb'],
            'notes' => $row['return_notes'],
            'created_at' => $row['return_created_at'],
            'updated_at' => $row['return_updated_at']
        ];
    }

    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Order ID invalid']);
        exit;
    }

    if ($order === null) {
        $orderStmt = $db->prepare(
            'SELECT id, order_number, customer_name, status, order_date, updated_at, total_value
             FROM orders
             WHERE id = :id'
        );
        $orderStmt->execute([':id' => $orderId]);
        $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$orderRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Comanda nu a fost găsită.']);
            exit;
        }

        $order = [
            'id' => (int)$orderRow['id'],
            'order_number' => $orderRow['order_number'],
            'customer_name' => $orderRow['customer_name'],
            'status' => $orderRow['status'],
            'order_date' => $orderRow['order_date'],
            'updated_at' => $orderRow['updated_at'],
            'total_value' => $orderRow['total_value']
        ];

        if ($returnId <= 0) {
            $activeReturnStmt = $db->prepare(
                'SELECT id, status, return_date, return_awb, notes, created_at, updated_at
                 FROM returns
                 WHERE order_id = :order_id AND status IN ("pending", "in_progress")
                 ORDER BY updated_at DESC
                 LIMIT 1'
            );
            $activeReturnStmt->execute([':order_id' => $orderId]);
            $activeReturn = $activeReturnStmt->fetch(PDO::FETCH_ASSOC);
            if ($activeReturn) {
                $returnInfo = [
                    'id' => (int)$activeReturn['id'],
                    'status' => $activeReturn['status'],
                    'return_date' => $activeReturn['return_date'],
                    'return_awb' => $activeReturn['return_awb'],
                    'notes' => $activeReturn['notes'],
                    'created_at' => $activeReturn['created_at'],
                    'updated_at' => $activeReturn['updated_at']
                ];
                $returnId = (int)$activeReturn['id'];
            }
        }
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
            p.barcode,
            ri.id AS return_item_id,
            ri.quantity_returned,
            ri.item_condition,
            ri.notes AS return_notes,
            ri.location_id AS processed_location_id,
            ri.updated_at AS processed_updated_at,
            ri.created_at AS processed_created_at,
            l.location_code AS processed_location_code
         FROM order_items oi
         JOIN products p ON oi.product_id = p.product_id
         LEFT JOIN return_items ri
            ON ri.order_item_id = oi.id AND ri.return_id = :return_id
         LEFT JOIN locations l ON l.id = ri.location_id
         WHERE oi.order_id = :order_id
         ORDER BY oi.id'
    );
    $itemsStmt->execute([
        ':order_id' => $orderId,
        ':return_id' => $returnId > 0 ? $returnId : 0
    ]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $responseItems = [];
    $missingLocations = [];
    $totalExpectedQty = 0;
    $processedCount = 0;
    $processedUnits = 0;

    foreach ($items as $item) {
        $productId = (int)$item['product_id'];
        $pickedQty = (int)($item['picked_quantity'] ?? 0);
        $orderedQty = (int)($item['quantity_ordered'] ?? 0);
        $expectedQty = $pickedQty > 0 ? $pickedQty : $orderedQty;
        if ($expectedQty < 0) {
            $expectedQty = 0;
        }

        $defaultLocation = findProductReturnLocation($db, $productId);
        if (!$defaultLocation) {
            $missingLocations[] = [
                'product_id' => $productId,
                'product_name' => $item['product_name'],
                'sku' => $item['sku']
            ];
        }

        $isProcessed = !empty($item['return_item_id']);
        if ($isProcessed) {
            $processedCount++;
            $processedUnits += (int)($item['quantity_returned'] ?? 0);
        }

        $selectedLocationId = $isProcessed
            ? (int)$item['processed_location_id']
            : ($defaultLocation['location_id'] ?? null);
        $selectedLocationCode = $isProcessed
            ? $item['processed_location_code']
            : ($defaultLocation['location_code'] ?? null);

        $responseItems[] = [
            'order_item_id' => (int)$item['order_item_id'],
            'product_id' => $productId,
            'product_name' => $item['product_name'],
            'sku' => $item['sku'],
            'quantity_ordered' => $orderedQty,
            'picked_quantity' => $pickedQty,
            'expected_quantity' => $expectedQty,
            'restock_quantity' => $expectedQty,
            'default_location_id' => $defaultLocation['location_id'] ?? null,
            'default_location_code' => $defaultLocation['location_code'] ?? null,
            'inventory_id' => $defaultLocation['inventory_id'] ?? null,
            'shelf_level' => $defaultLocation['shelf_level'] ?? null,
            'subdivision_number' => $defaultLocation['subdivision_number'] ?? null,
            'location_id' => $selectedLocationId,
            'location_code' => $selectedLocationCode,
            'is_processed' => $isProcessed,
            'processed_quantity' => $isProcessed ? (int)$item['quantity_returned'] : null,
            'processed_condition' => $isProcessed ? ($item['item_condition'] ?? null) : null,
            'processed_notes' => $isProcessed ? ($item['return_notes'] ?? null) : null,
            'processed_location_id' => $isProcessed ? (int)$item['processed_location_id'] : null,
            'processed_location_code' => $isProcessed ? $item['processed_location_code'] : null,
            'processed_at' => $isProcessed ? ($item['processed_updated_at'] ?? $item['processed_created_at']) : null,
            'return_item_id' => $isProcessed ? (int)$item['return_item_id'] : null
        ];

        $totalExpectedQty += $expectedQty;
    }

    $latestActivity = $order['updated_at'] ?: $order['order_date'];
    $totalItems = count($responseItems);

    echo json_encode([
        'success' => true,
        'order' => [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'customer_name' => $order['customer_name'],
            'status' => $order['status'],
            'status_label' => translateOrderStatus($order['status']),
            'latest_activity' => $latestActivity,
            'total_items' => $totalItems,
            'total_value' => isset($order['total_value']) ? (float)$order['total_value'] : null
        ],
        'return' => $returnInfo,
        'items' => $responseItems,
        'missing_locations' => $missingLocations,
        'totals' => [
            'items' => $totalItems,
            'expected_quantity' => $totalExpectedQty,
            'processed_items' => $processedCount,
            'processed_quantity' => $processedUnits,
            'missing_locations' => count($missingLocations)
        ],
        'processing' => [
            'all_processed' => $totalItems > 0 && $processedCount === $totalItems,
            'processed_items' => $processedCount,
            'total_items' => $totalItems
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
            COALESCE(lls.level_name, CONCAT('Nivel ', lls.level_number)) AS shelf_level,
            lls.allowed_product_types
         FROM location_level_settings lls
         JOIN locations l ON lls.location_id = l.id
         WHERE lls.allowed_product_types IS NOT NULL
         ORDER BY (l.status = 'active') DESC, lls.priority_order ASC, lls.level_number ASC"
    );

    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!productAllowedForLocationLevel($row['allowed_product_types'], $productId)) {
            continue;
        }

        return [
            'location_id' => (int)$row['location_id'],
            'location_code' => $row['location_code'],
            'shelf_level' => $row['shelf_level'] ?: null,
            'subdivision_number' => null
        ];
    }

    return null;
}

function productAllowedForLocationLevel($allowedProductTypes, int $productId): bool
{
    if ($allowedProductTypes === null || $allowedProductTypes === '') {
        return false;
    }

    $decoded = json_decode($allowedProductTypes, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $values = is_array($decoded) ? $decoded : [$decoded];

        foreach ($values as $value) {
            if (is_array($value)) {
                continue;
            }

            $stringValue = (string)$value;
            if ($stringValue === (string)$productId) {
                return true;
            }

            if (is_numeric($value) && (int)$value === $productId) {
                return true;
            }
        }

        return false;
    }

    $parts = preg_split('/[\s,;|]+/', $allowedProductTypes);
    if ($parts === false) {
        return false;
    }

    foreach ($parts as $part) {
        $trimmed = trim($part, "\"' ");
        if ($trimmed === '') {
            continue;
        }

        if ($trimmed === (string)$productId) {
            return true;
        }

        if (ctype_digit($trimmed) && (int)$trimmed === $productId) {
            return true;
        }
    }

    return false;
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
