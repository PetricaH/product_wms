<?php
/**
 * API: Receive Item
 * File: api/receiving/receive_item.php
 * 
 * Records the receipt of an individual item in a receiving session
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/includes/qc_helpers.php';
require_once BASE_PATH . '/models/ShelfLevelResolver.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Database connection
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();
require_once BASE_PATH . '/models/Product.php';
require_once BASE_PATH . '/models/PurchasableProduct.php';
require_once BASE_PATH . '/models/BarcodeCaptureTask.php';

function getDefaultLocationId(PDO $db, string $type): ?int {
    $stmt = $db->prepare("SELECT id FROM locations WHERE type = :type LIMIT 1");
    $stmt->execute([':type' => $type]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function updateReceivingSessionProgress(PDO $db, int $sessionId): void {
    $stmt = $db->prepare("
        UPDATE receiving_sessions rs
        SET total_items_received = (
            SELECT COUNT(DISTINCT filtered.purchase_order_item_id)
            FROM (
                SELECT
                    ri.purchase_order_item_id,
                    ri.tracking_method,
                    bct.status AS barcode_status,
                    bct.scanned_quantity,
                    bct.expected_quantity
                FROM receiving_items ri
                LEFT JOIN barcode_capture_tasks bct ON ri.barcode_task_id = bct.task_id
                WHERE ri.receiving_session_id = :session_id
            ) AS filtered
            WHERE filtered.purchase_order_item_id IS NOT NULL
              AND (
                filtered.tracking_method = 'bulk'
                OR (
                    filtered.tracking_method = 'individual'
                    AND (
                        filtered.barcode_status = 'completed'
                        OR filtered.scanned_quantity >= filtered.expected_quantity
                    )
                )
            )
        ),
        updated_at = NOW()
        WHERE rs.id = :session_id
    ");
    $stmt->execute([':session_id' => $sessionId]);
}

function getFirstActiveLocation(PDO $db): ?array {
    $stmt = $db->query("SELECT id, location_code FROM locations WHERE status = 'active' ORDER BY id LIMIT 1");
    $loc = $stmt->fetch(PDO::FETCH_ASSOC);
    return $loc ?: null;
}

function fetchLocationByCode(PDO $db, string $locationCode): ?array {
    $stmt = $db->prepare("SELECT id, location_code FROM locations WHERE location_code = :code AND status = 'active' LIMIT 1");
    $stmt->execute([':code' => $locationCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'location_code' => $row['location_code']
    ];
}

function getLocationById(PDO $db, int $locationId): ?array {
    $stmt = $db->prepare("SELECT id, location_code FROM locations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $locationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'location_code' => $row['location_code']
    ];
}

function findDedicatedSubdivision(PDO $db, int $productId, ?int $locationId = null): ?array {
    $params = [':product_id' => $productId];
    $query = "
        SELECT
            ls.location_id,
            l.location_code,
            ls.subdivision_number,
            COALESCE(lls.level_name, CONCAT('Nivel ', ls.level_number)) AS shelf_level,
            l.status
        FROM location_subdivisions ls
        JOIN locations l ON ls.location_id = l.id
        LEFT JOIN location_level_settings lls
            ON lls.location_id = ls.location_id AND lls.level_number = ls.level_number
        WHERE ls.dedicated_product_id = :product_id";

    if ($locationId !== null) {
        $query .= " AND ls.location_id = :location_id";
        $params[':location_id'] = $locationId;
    }

    $query .= " ORDER BY (l.status = 'active') DESC, ls.updated_at DESC, ls.subdivision_number ASC LIMIT 1";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'id' => (int)$row['location_id'],
        'location_code' => $row['location_code'],
        'shelf_level' => $row['shelf_level'] ?: null,
        'subdivision_number' => $row['subdivision_number'] !== null ? (int)$row['subdivision_number'] : null
    ];
}

function findDedicatedLevel(PDO $db, int $productId, ?int $locationId = null): ?array {
    $params = [':product_id' => $productId];
    $query = "
        SELECT
            lls.location_id,
            l.location_code,
            COALESCE(lls.level_name, CONCAT('Nivel ', lls.level_number)) AS shelf_level,
            l.status
        FROM location_level_settings lls
        JOIN locations l ON lls.location_id = l.id
        WHERE lls.dedicated_product_id = :product_id";

    if ($locationId !== null) {
        $query .= " AND lls.location_id = :location_id";
        $params[':location_id'] = $locationId;
    }

    $query .= " ORDER BY (l.status = 'active') DESC, lls.updated_at DESC, lls.level_number ASC LIMIT 1";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'id' => (int)$row['location_id'],
        'location_code' => $row['location_code'],
        'shelf_level' => $row['shelf_level'] ?: null,
        'subdivision_number' => null
    ];
}

function findInventoryLocation(PDO $db, int $productId, ?int $locationId = null): ?array {
    $params = [':product_id' => $productId];
    $query = "
        SELECT
            i.location_id,
            l.location_code,
            i.shelf_level,
            i.subdivision_number,
            i.quantity,
            i.updated_at,
            i.received_at
        FROM inventory i
        JOIN locations l ON i.location_id = l.id
        WHERE i.product_id = :product_id";

    if ($locationId !== null) {
        $query .= " AND i.location_id = :location_id";
        $params[':location_id'] = $locationId;
    }

    $query .= " ORDER BY (CASE WHEN i.quantity > 0 THEN 1 ELSE 0 END) DESC, i.updated_at DESC, i.received_at DESC LIMIT 1";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'id' => (int)$row['location_id'],
        'location_code' => $row['location_code'],
        'shelf_level' => $row['shelf_level'] ?: null,
        'subdivision_number' => $row['subdivision_number'] !== null ? (int)$row['subdivision_number'] : null
    ];
}

function resolveProductLocation(PDO $db, int $productId, ?array $requestedLocation = null): array {
    if ($requestedLocation) {
        $candidate = findDedicatedSubdivision($db, $productId, (int)$requestedLocation['id'])
            ?? findDedicatedLevel($db, $productId, (int)$requestedLocation['id'])
            ?? findInventoryLocation($db, $productId, (int)$requestedLocation['id']);
        if ($candidate) {
            return $candidate;
        }
    }

    $candidate = findDedicatedSubdivision($db, $productId)
        ?? findDedicatedLevel($db, $productId)
        ?? findInventoryLocation($db, $productId);

    if ($candidate) {
        return $candidate;
    }

    if ($requestedLocation) {
        return [
            'id' => (int)$requestedLocation['id'],
            'location_code' => $requestedLocation['location_code'],
            'shelf_level' => null,
            'subdivision_number' => null
        ];
    }

    return [
        'id' => null,
        'location_code' => null,
        'shelf_level' => null,
        'subdivision_number' => null
    ];
}

try {
    $productModel = new Product($db);
    $purchasableModel = new PurchasableProduct($db);
    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    $sessionId = (int)($input['session_id'] ?? 0);
    $itemId = (int)($input['item_id'] ?? 0);
    $receivedQuantity = (float)($input['received_quantity'] ?? 0);
    $locationCode = trim($input['location_id'] ?? ''); // This is actually location_code from frontend
    $conditionStatus = $input['condition_status'] ?? 'good';
    $batchNumber = trim($input['batch_number'] ?? '');
    $expiryDate = trim($input['expiry_date'] ?? '');
    $notes = trim($input['notes'] ?? '');
    $trackingMethod = $input['tracking_method'] ?? 'bulk';

    if (!in_array($trackingMethod, ['bulk', 'individual'], true)) {
        $trackingMethod = 'bulk';
    }
    
    if (!$sessionId || !$itemId || $receivedQuantity <= 0) {
        throw new Exception('Session ID, item ID and received quantity (>0) are required');
    }
    
    // Validate session exists and is active
    $stmt = $db->prepare("
        SELECT rs.*, po.id as purchase_order_id
        FROM receiving_sessions rs
        JOIN purchase_orders po ON rs.purchase_order_id = po.id
        WHERE rs.id = :session_id 
        AND rs.status = 'in_progress'
        AND rs.received_by = :user_id
    ");
    $stmt->execute([
        ':session_id' => $sessionId,
        ':user_id' => $_SESSION['user_id']
    ]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        throw new Exception('Receiving session not found or not accessible');
    }
    
    // Validate purchase order item exists and get product mapping
    $stmt = $db->prepare("
        SELECT poi.*, pp.supplier_product_name as product_name, pp.supplier_product_code as sku,
               COALESCE(pp.internal_product_id, p.product_id) as main_product_id
        FROM purchase_order_items poi
        JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
        LEFT JOIN products p ON (pp.internal_product_id = p.product_id OR pp.supplier_product_code = p.sku)
        WHERE poi.id = :item_id 
        AND poi.purchase_order_id = :purchase_order_id
    ");
    $stmt->execute([
        ':item_id' => $itemId,
        ':purchase_order_id' => $session['purchase_order_id']
    ]);
    $orderItem = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$orderItem) {
        throw new Exception('Purchase order item not found');
    }
    
    if (!$orderItem['main_product_id']) {
        // Auto-create main product if not found
        $newSku = $orderItem['sku'] ?: $productModel->generateSku('PRD');
        if ($productModel->skuExists($newSku)) {
            $newSku = $productModel->generateSku('PRD');
        }
        $newProductId = $productModel->createProduct([
            'sku' => $newSku,
            'name' => $orderItem['product_name'],
            'description' => $orderItem['product_name'],
            'category' => '',
            'quantity' => 0,
            'price' => $orderItem['unit_price'] ?? 0
        ]);
        if (!$newProductId) {
            throw new Exception('Failed to auto-create main product');
        }
        // Update purchasable product mapping
        $purchasableModel->updateProduct((int)$orderItem['purchasable_product_id'], ['internal_product_id' => $newProductId]);
        $orderItem['main_product_id'] = $newProductId;
    }
    
    $requestedLocation = null;
    if ($locationCode !== '') {
        $requestedLocation = fetchLocationByCode($db, $locationCode);
        if (!$requestedLocation) {
            throw new Exception('Location not found or inactive');
        }
    }

    $resolvedLocation = resolveProductLocation($db, (int)$orderItem['main_product_id'], $requestedLocation);

    if (!$resolvedLocation['id']) {
        if ($requestedLocation) {
            $resolvedLocation = [
                'id' => (int)$requestedLocation['id'],
                'location_code' => $requestedLocation['location_code'],
                'shelf_level' => null,
                'subdivision_number' => null
            ];
        } else {
            $fallback = getFirstActiveLocation($db);
            if (!$fallback) {
                throw new Exception('Location is required');
            }
            $resolvedLocation = [
                'id' => (int)$fallback['id'],
                'location_code' => $fallback['location_code'],
                'shelf_level' => null,
                'subdivision_number' => null
            ];
        }
    }

    if (empty($resolvedLocation['location_code'])) {
        $locInfo = getLocationById($db, (int)$resolvedLocation['id']);
        if ($locInfo) {
            $resolvedLocation['location_code'] = $locInfo['location_code'];
        }
    }

    if ($resolvedLocation['shelf_level'] === null) {
        $existingPlacement = findInventoryLocation($db, (int)$orderItem['main_product_id'], (int)$resolvedLocation['id']);
        if ($existingPlacement) {
            $resolvedLocation['shelf_level'] = $existingPlacement['shelf_level'];
            if ($existingPlacement['subdivision_number'] !== null) {
                $resolvedLocation['subdivision_number'] = $existingPlacement['subdivision_number'];
            }
        }
    }

    $location = [
        'id' => (int)$resolvedLocation['id'],
        'location_code' => $resolvedLocation['location_code'],
        'shelf_level' => $resolvedLocation['shelf_level'],
        'subdivision_number' => $resolvedLocation['subdivision_number'] ?? null
    ];
    $locationCode = $location['location_code'];

    $expectedQuantity = (float)$orderItem['quantity'];
    $approvalStatus = 'approved';
    if ($conditionStatus !== 'good') {
        $approvalStatus = 'pending';
        $quarantineId = getDefaultLocationId($db, 'quarantine');
        if ($quarantineId && $quarantineId != $location['id']) {
            $quarantineInfo = getLocationById($db, (int)$quarantineId);
            $location['id'] = (int)$quarantineId;
            if ($quarantineInfo) {
                $location['location_code'] = $quarantineInfo['location_code'];
            }
            $location['shelf_level'] = null;
            $location['subdivision_number'] = null;
        }
    } elseif ($receivedQuantity != $expectedQuantity) {
        $approvalStatus = 'pending';
        $qcHoldId = getDefaultLocationId($db, 'qc_hold');
        if ($qcHoldId && $qcHoldId != $location['id']) {
            $qcInfo = getLocationById($db, (int)$qcHoldId);
            $location['id'] = (int)$qcHoldId;
            if ($qcInfo) {
                $location['location_code'] = $qcInfo['location_code'];
            }
            $location['shelf_level'] = null;
            $location['subdivision_number'] = null;
        }
    }
    $locationCode = $location['location_code'];

    // Start transaction
    $db->beginTransaction();
    
    // Check if item already received in this session
    $stmt = $db->prepare("
        SELECT id, received_quantity, tracking_method, barcode_task_id
        FROM receiving_items
        WHERE receiving_session_id = :session_id
        AND purchase_order_item_id = :item_id
    ");
    $stmt->execute([
        ':session_id' => $sessionId,
        ':item_id' => $itemId
    ]);
    $existingReceiving = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $barcodeTaskId = null;
    $barcodeTask = null;

    if ($trackingMethod === 'individual') {
        $intQuantity = (int)round($receivedQuantity);
        if ($intQuantity <= 0) {
            throw new Exception('Cantitatea pentru scanare trebuie să fie un număr întreg pozitiv');
        }

        $barcodeTaskModel = new BarcodeCaptureTask($db);

        if ($existingReceiving && !empty($existingReceiving['barcode_task_id'])) {
            $barcodeTaskId = (int)$existingReceiving['barcode_task_id'];
            $stmt = $db->prepare("UPDATE barcode_capture_tasks SET expected_quantity = :expected WHERE task_id = :task_id");
            $stmt->execute([
                ':expected' => $intQuantity,
                ':task_id' => $barcodeTaskId
            ]);
        } else {
            $barcodeTaskId = $barcodeTaskModel->createTask(
                (int)$orderItem['main_product_id'],
                (int)$location['id'],
                $intQuantity,
                (int)($_SESSION['user_id'] ?? 0)
            );

            if (!$barcodeTaskId) {
                throw new Exception('Nu am putut crea sarcina de scanare pentru codurile de bare');
            }
        }

        $stmt = $db->prepare('SELECT expected_quantity, scanned_quantity, status FROM barcode_capture_tasks WHERE task_id = :task_id');
        $stmt->execute([':task_id' => $barcodeTaskId]);
        $barcodeTask = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($existingReceiving) {
        // Update existing receiving record
        $stmt = $db->prepare("
            UPDATE receiving_items SET
                received_quantity = :received_quantity,
                condition_status = :condition_status,
                batch_number = :batch_number,
                expiry_date = :expiry_date,
                location_id = :location_id,
                approval_status = :approval_status,
                notes = :notes,
                tracking_method = :tracking_method,
                barcode_task_id = :barcode_task_id,
                updated_at = NOW()
            WHERE id = :receiving_item_id
        ");
        $stmt->execute([
            ':received_quantity' => $receivedQuantity,
            ':condition_status' => $conditionStatus,
            ':batch_number' => $batchNumber ?: null,
            ':expiry_date' => $expiryDate ?: null,
            ':location_id' => $location['id'],
            ':approval_status' => $approvalStatus,
            ':notes' => $notes,
            ':tracking_method' => $trackingMethod,
            ':barcode_task_id' => $barcodeTaskId,
            ':receiving_item_id' => $existingReceiving['id']
        ]);
        $receivingItemId = $existingReceiving['id'];
    } else {
        // Create new receiving record - FIXED: Removed purchasable_product_id reference
        $stmt = $db->prepare("
            INSERT INTO receiving_items (
                receiving_session_id, product_id, purchase_order_item_id,
                expected_quantity, received_quantity, unit_price,
                condition_status, batch_number, expiry_date, location_id,
                approval_status, notes, tracking_method, barcode_task_id
            ) VALUES (
                :session_id, :product_id, :item_id, :expected_quantity,
                :received_quantity, :unit_price, :condition_status,
                :batch_number, :expiry_date, :location_id,
                :approval_status, :notes, :tracking_method, :barcode_task_id
            )
        ");
        $stmt->execute([
            ':session_id' => $sessionId,
            ':product_id' => $orderItem['main_product_id'],
            ':item_id' => $itemId,
            ':expected_quantity' => $orderItem['quantity'],
            ':received_quantity' => $receivedQuantity,
            ':unit_price' => $orderItem['unit_price'],
            ':condition_status' => $conditionStatus,
            ':batch_number' => $batchNumber ?: null,
            ':expiry_date' => $expiryDate ?: null,
            ':location_id' => $location['id'],
            ':approval_status' => $approvalStatus,
            ':notes' => $notes,
            ':tracking_method' => $trackingMethod,
            ':barcode_task_id' => $barcodeTaskId
        ]);
        $receivingItemId = $db->lastInsertId();
    }

    // Update inventory only for approved good items
    if ($trackingMethod === 'bulk' && $approvalStatus === 'approved' && $conditionStatus === 'good') {
        $subdivisionNumber = $location['subdivision_number'] ?? null;
        $inventoryRecord = null;

        if ($subdivisionNumber !== null) {
            $stmt = $db->prepare("
                SELECT id, quantity, shelf_level, subdivision_number
                FROM inventory
                WHERE product_id = :product_id
                  AND location_id = :location_id
                  AND subdivision_number = :subdivision
                LIMIT 1
            ");
            $stmt->execute([
                ':product_id' => $orderItem['main_product_id'],
                ':location_id' => $location['id'],
                ':subdivision' => $subdivisionNumber
            ]);
            $inventoryRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$inventoryRecord) {
            $stmt = $db->prepare("
                SELECT id, quantity, shelf_level, subdivision_number
                FROM inventory
                WHERE product_id = :product_id
                  AND location_id = :location_id
                ORDER BY quantity DESC, id ASC
                LIMIT 1
            ");
            $stmt->execute([
                ':product_id' => $orderItem['main_product_id'],
                ':location_id' => $location['id']
            ]);
            $inventoryRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $resolvedShelfLevel = $location['shelf_level'] ?? ($inventoryRecord['shelf_level'] ?? null);
        $resolvedSubdivision = $location['subdivision_number'] ?? ($inventoryRecord['subdivision_number'] ?? null);

        if ($resolvedShelfLevel === null && $resolvedSubdivision !== null) {
            $levelName = ShelfLevelResolver::getCorrectShelfLevel(
                $db,
                (int)$location['id'],
                (int)$orderItem['main_product_id'],
                (int)$resolvedSubdivision
            );
            if ($levelName !== null) {
                $resolvedShelfLevel = $levelName;
            }
        }

        if ($resolvedShelfLevel === null) {
            $resolvedShelfLevel = 'middle';
        }

        if ($inventoryRecord) {
            $stmt = $db->prepare("
                UPDATE inventory SET
                    quantity = quantity + :received_quantity,
                    shelf_level = :shelf_level,
                    subdivision_number = :subdivision_number,
                    received_at = NOW(),
                    updated_at = NOW()
                WHERE id = :inventory_id
            ");
            $stmt->execute([
                ':received_quantity' => $receivedQuantity,
                ':shelf_level' => $resolvedShelfLevel,
                ':subdivision_number' => $resolvedSubdivision,
                ':inventory_id' => $inventoryRecord['id']
            ]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO inventory (
                    product_id, location_id, shelf_level, subdivision_number, quantity, batch_number,
                    expiry_date, received_at
                ) VALUES (
                    :product_id, :location_id, :shelf_level, :subdivision_number, :quantity, :batch_number,
                    :expiry_date, NOW()
                )
            ");
            $stmt->execute([
                ':product_id' => $orderItem['main_product_id'],
                ':location_id' => $location['id'],
                ':shelf_level' => $resolvedShelfLevel,
                ':subdivision_number' => $resolvedSubdivision,
                ':quantity' => $receivedQuantity,
                ':batch_number' => $batchNumber ?: null,
                ':expiry_date' => $expiryDate ?: null
            ]);
        }
    }

    if ($trackingMethod === 'individual' && !$barcodeTask) {
        $stmt = $db->prepare('SELECT expected_quantity, scanned_quantity, status FROM barcode_capture_tasks WHERE task_id = :task_id');
        $stmt->execute([':task_id' => $barcodeTaskId]);
        $barcodeTask = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Check for discrepancies
    if ($receivedQuantity != $expectedQuantity) {
        // Create discrepancy record
        $discrepancyType = $receivedQuantity < $expectedQuantity ? 'quantity_short' : 'quantity_over';
        $discrepancyQuantity = abs($receivedQuantity - $expectedQuantity);

        // Create description for the discrepancy
        $description = $notes ?: "Quantity discrepancy: Expected {$expectedQuantity}, received {$receivedQuantity}";

        // Check if discrepancy already exists for this session and product
        $stmt = $db->prepare("
            SELECT id FROM receiving_discrepancies
            WHERE receiving_session_id = :session_id
            AND product_id = :product_id
        ");
        $stmt->execute([
            ':session_id' => $sessionId,
            ':product_id' => $orderItem['main_product_id']
        ]);
        $existingDiscrepancy = $stmt->fetchColumn();

        if ($existingDiscrepancy) {
            // Update existing discrepancy
            $stmt = $db->prepare("
                UPDATE receiving_discrepancies SET
                    discrepancy_type = :discrepancy_type,
                    expected_quantity = :expected_quantity,
                    actual_quantity = :actual_quantity,
                    description = :description,
                    resolution_notes = :resolution_notes
                WHERE id = :discrepancy_id
            ");
            $stmt->execute([
                ':discrepancy_type' => $discrepancyType,
                ':expected_quantity' => $expectedQuantity,
                ':actual_quantity' => $receivedQuantity,
                ':description' => $description,
                ':resolution_notes' => $notes,
                ':discrepancy_id' => $existingDiscrepancy
            ]);
        } else {
            // Create new discrepancy record
            $stmt = $db->prepare("
                INSERT INTO receiving_discrepancies (
                    receiving_session_id, product_id,
                    discrepancy_type, expected_quantity, actual_quantity,
                    description, resolution_notes
                ) VALUES (
                    :session_id, :product_id, :discrepancy_type,
                    :expected_quantity, :actual_quantity, :description, :resolution_notes
                )
            ");
            $stmt->execute([
                ':session_id' => $sessionId,
                ':product_id' => $orderItem['main_product_id'],
                ':discrepancy_type' => $discrepancyType,
                ':expected_quantity' => $expectedQuantity,
                ':actual_quantity' => $receivedQuantity,
                ':description' => $description,
                ':resolution_notes' => $notes
            ]);
        }
    }
    
    // Update session progress
    updateReceivingSessionProgress($db, $sessionId);

    // Commit transaction
    $db->commit();

    $status = 'pending';
    if ($receivedQuantity >= $expectedQuantity) {
        $status = 'received';
    } elseif ($receivedQuantity > 0 && $receivedQuantity < $expectedQuantity) {
        $status = 'partial';
    }

    $barcodeExpected = $barcodeTask['expected_quantity'] ?? null;
    $barcodeScanned = $barcodeTask['scanned_quantity'] ?? null;
    $barcodeStatus = $barcodeTask['status'] ?? null;

    if ($trackingMethod === 'individual') {
        $status = ($barcodeStatus === 'completed' || ($barcodeExpected !== null && $barcodeScanned !== null && $barcodeScanned >= $barcodeExpected))
            ? 'received'
            : 'pending_scan';
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Item received successfully',
        'receiving_item_id' => $receivingItemId,
        'status' => $status,
        'tracking_method' => $trackingMethod,
        'barcode_task_id' => $barcodeTaskId ? (int)$barcodeTaskId : null,
        'barcode_expected' => $barcodeExpected !== null ? (int)$barcodeExpected : null,
        'barcode_scanned' => $barcodeScanned !== null ? (int)$barcodeScanned : null,
        'barcode_status' => $barcodeStatus,
        'item_details' => [
            'id' => $itemId,
            'product_name' => $orderItem['product_name'],
            'sku' => $orderItem['sku'],
            'expected_quantity' => $expectedQuantity,
            'received_quantity' => $receivedQuantity,
            'location_code' => $locationCode,
            'condition_status' => $conditionStatus,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'discrepancy' => $receivedQuantity != $expectedQuantity,
            'approval_status' => $approvalStatus
        ]
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("Receive item error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}