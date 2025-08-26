<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$pathInfo = trim($_SERVER['PATH_INFO'] ?? '', '/');
$segments = $pathInfo === '' ? [] : explode('/', $pathInfo);

try {
    if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'lookup' && isset($segments[1])) {
        lookupOrder($db, $segments[1]);
    } elseif ($method === 'POST' && isset($segments[0]) && $segments[0] === 'start') {
        startReturn($db);
    } elseif (!empty($segments) && is_numeric($segments[0])) {
        $returnId = (int)$segments[0];
        if ($method === 'POST' && isset($segments[1]) && $segments[1] === 'verify-item') {
            verifyItem($db, $returnId);
        } elseif ($method === 'POST' && isset($segments[1]) && $segments[1] === 'add-discrepancy') {
            addDiscrepancy($db, $returnId);
        } elseif ($method === 'POST' && isset($segments[1]) && $segments[1] === 'complete') {
            completeReturn($db, $returnId);
        } elseif ($method === 'GET' && isset($segments[1]) && $segments[1] === 'summary') {
            summaryReturn($db, $returnId);
        } else {
            throw new Exception('Endpoint not found', 404);
        }
    } else {
        throw new Exception('Endpoint not found', 404);
    }
} catch (Exception $e) {
    $code = $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    http_response_code($code);
    echo json_encode(['error' => $e->getMessage()]);
}

function getJsonInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Invalid JSON', 400);
    }
    return $input;
}

function lookupOrder(PDO $db, string $orderNumber) {
    $stmt = $db->prepare("SELECT id, order_number, customer_name, status, shipped_date, order_date
                          FROM orders WHERE order_number = :num");
    $stmt->execute([':num' => $orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new Exception('Order not found', 404);
    }

    // Ensure order was shipped and within return window (30 days)
    if ($order['status'] !== 'shipped') {
        throw new Exception('Order not shipped', 409);
    }
    $shipDate = $order['shipped_date'] ?: $order['order_date'];
    if (!$shipDate || strtotime($shipDate) < strtotime('-30 days')) {
        throw new Exception('Return period expired', 409);
    }

    // Check for existing return sessions
    $existingStmt = $db->prepare("SELECT id, status FROM returns WHERE order_id = :oid AND status IN ('in_progress','pending')");
    $existingStmt->execute([':oid' => $order['id']]);
    if ($existingStmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('Return already in progress for this order', 409);
    }

    // Fetch items along with quantities already returned in completed returns
    $itemsStmt = $db->prepare("SELECT oi.id AS order_item_id, p.product_id, p.sku, p.name, oi.quantity,
                                      COALESCE(SUM(CASE WHEN r.status = 'completed' THEN ri.quantity_returned ELSE 0 END), 0) AS returned_qty
                               FROM order_items oi
                               JOIN products p ON oi.product_id = p.product_id
                               LEFT JOIN return_items ri ON ri.order_item_id = oi.id
                               LEFT JOIN returns r ON ri.return_id = r.id
                               WHERE oi.order_id = :oid
                               GROUP BY oi.id, p.product_id, p.sku, p.name, oi.quantity");
    $itemsStmt->execute([':oid' => $order['id']]);
    $rows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $allReturned = true;
    $items = array_map(function($r) use (&$allReturned) {
        $ordered = (int)$r['quantity'];
        $returned = (int)$r['returned_qty'];
        if ($returned < $ordered) {
            $allReturned = false;
        }
        return [
            'order_item_id' => (int)$r['order_item_id'],
            'product_id' => (int)$r['product_id'],
            'sku' => $r['sku'],
            'product_name' => $r['name'],
            'ordered_quantity' => $ordered,
            'returned_quantity' => $returned,
            'expected_quantity' => max($ordered - $returned, 0)
        ];
    }, $rows);

    if ($allReturned) {
        throw new Exception('Order already fully returned', 409);
    }

    echo json_encode([
        'success' => true,
        'order' => [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'customer_name' => $order['customer_name']
        ],
        'items' => $items
    ]);
}

function startReturn(PDO $db) {
    $input = getJsonInput();
    $orderNumber = trim($input['order_number'] ?? '');
    $processedBy = isset($input['processed_by']) ? (int)$input['processed_by'] : 0;
    if ($orderNumber === '' || $processedBy <= 0) {
        throw new Exception('order_number and processed_by required', 400);
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("SELECT id, status, shipped_date, order_date FROM orders WHERE order_number = :num FOR UPDATE");
        $stmt->execute([':num' => $orderNumber]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new Exception('Order not found', 404);
        }

        if ($order['status'] !== 'shipped') {
            throw new Exception('Order not shipped', 409);
        }
        $shipDate = $order['shipped_date'] ?: $order['order_date'];
        if (!$shipDate || strtotime($shipDate) < strtotime('-30 days')) {
            throw new Exception('Return period expired', 409);
        }

        $existingStmt = $db->prepare("SELECT id FROM returns WHERE order_id = :oid AND status IN ('in_progress','pending') FOR UPDATE");
        $existingStmt->execute([':oid' => $order['id']]);
        if ($existingStmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('Return already in progress for this order', 409);
        }

        // Fetch items and returned quantities to ensure order isn't fully returned
        $itemsStmt = $db->prepare("SELECT oi.id AS order_item_id, p.product_id, p.sku, p.name, oi.quantity,
                                          COALESCE(SUM(CASE WHEN r.status = 'completed' THEN ri.quantity_returned ELSE 0 END), 0) AS returned_qty
                                   FROM order_items oi
                                   JOIN products p ON oi.product_id = p.product_id
                                   LEFT JOIN return_items ri ON ri.order_item_id = oi.id
                                   LEFT JOIN returns r ON ri.return_id = r.id
                                   WHERE oi.order_id = :oid
                                   GROUP BY oi.id, p.product_id, p.sku, p.name, oi.quantity");
        $itemsStmt->execute([':oid' => $order['id']]);
        $rows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $allReturned = true;
        $items = array_map(function($r) use (&$allReturned) {
            $ordered = (int)$r['quantity'];
            $returned = (int)$r['returned_qty'];
            if ($returned < $ordered) {
                $allReturned = false;
            }
            return [
                'order_item_id' => (int)$r['order_item_id'],
                'product_id' => (int)$r['product_id'],
                'sku' => $r['sku'],
                'product_name' => $r['name'],
                'ordered_quantity' => $ordered,
                'returned_quantity' => $returned,
                'expected_quantity' => max($ordered - $returned, 0)
            ];
        }, $rows);

        if ($allReturned) {
            throw new Exception('Order already fully returned', 409);
        }

        $insert = $db->prepare("INSERT INTO returns (order_id, processed_by, status) VALUES (:oid, :pid, 'in_progress')");
        $insert->execute([':oid' => $order['id'], ':pid' => $processedBy]);
        $returnId = (int)$db->lastInsertId();

        $db->commit();

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'return_id' => $returnId,
            'order' => [
                'id' => (int)$order['id'],
                'order_number' => $orderNumber
            ],
            'items' => $items
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function verifyItem(PDO $db, int $returnId) {
    $input = getJsonInput();
    $productId = isset($input['product_id']) ? (int)$input['product_id'] : 0;
    $qty = isset($input['quantity']) ? (int)$input['quantity'] : 0;
    $condition = $input['item_condition'] ?? 'good';
    $isExtra = !empty($input['is_extra']) ? 1 : 0;
    $notes = $input['notes'] ?? '';

    if ($productId <= 0 || $qty <= 0) {
        throw new Exception('product_id and quantity are required', 400);
    }
    if (!in_array($condition, ['good','damaged','defective','opened'])) {
        throw new Exception('Invalid item_condition', 400);
    }

    $stmt = $db->prepare("SELECT order_id, status FROM returns WHERE id = :id");
    $stmt->execute([':id' => $returnId]);
    $ret = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ret) {
        throw new Exception('Return not found', 404);
    }
    if (!in_array($ret['status'], ['in_progress','pending'])) {
        throw new Exception('Return is not in progress', 409);
    }

    $pstmt = $db->prepare("SELECT product_id FROM products WHERE product_id = :pid");
    $pstmt->execute([':pid' => $productId]);
    if (!$pstmt->fetchColumn()) {
        throw new Exception('Product not found', 404);
    }

    // Match product to order item
    $oStmt = $db->prepare("SELECT id, quantity FROM order_items WHERE order_id = :oid AND product_id = :pid");
    $oStmt->execute([':oid' => $ret['order_id'], ':pid' => $productId]);
    $orderItem = $oStmt->fetch(PDO::FETCH_ASSOC);
    $orderItemId = $orderItem['id'] ?? null;

    if ($orderItemId) {
        // Determine how many of this item were already returned
        $qtyStmt = $db->prepare("SELECT 
                COALESCE(SUM(CASE WHEN r.status = 'completed' THEN ri.quantity_returned ELSE 0 END),0) AS returned_completed,
                COALESCE(SUM(CASE WHEN r.id = :rid THEN ri.quantity_returned ELSE 0 END),0) AS returned_current
            FROM return_items ri
            JOIN returns r ON r.id = ri.return_id
            WHERE ri.order_item_id = :oiid");
        $qtyStmt->execute([':oiid' => $orderItemId, ':rid' => $returnId]);
        $qtyRow = $qtyStmt->fetch(PDO::FETCH_ASSOC);
        $orderedQty = (int)$orderItem['quantity'];
        $returnedCompleted = (int)$qtyRow['returned_completed'];
        $returnedCurrent = (int)$qtyRow['returned_current'];
        $remaining = $orderedQty - $returnedCompleted - $returnedCurrent;
        if ($qty > $remaining) {
            throw new Exception('Returned quantity exceeds remaining quantity: ' . $remaining, 409);
        }
    } else {
        // Item not part of original order
        $isExtra = 1;
    }

    // Record the returned item
    $insert = $db->prepare("INSERT INTO return_items (return_id, order_item_id, product_id, quantity_returned, item_condition, is_extra, notes)
                             VALUES (:rid, :oiid, :pid, :qty, :cond, :extra, :notes)");
    $insert->bindValue(':rid', $returnId, PDO::PARAM_INT);
    if ($orderItemId !== null) {
        $insert->bindValue(':oiid', $orderItemId, PDO::PARAM_INT);
    } else {
        $insert->bindValue(':oiid', null, PDO::PARAM_NULL);
    }
    $insert->bindValue(':pid', $productId, PDO::PARAM_INT);
    $insert->bindValue(':qty', $qty, PDO::PARAM_INT);
    $insert->bindValue(':cond', $condition, PDO::PARAM_STR);
    $insert->bindValue(':extra', $isExtra, PDO::PARAM_INT);
    $insert->bindValue(':notes', $notes, PDO::PARAM_STR);
    $insert->execute();
    $itemId = (int)$db->lastInsertId();

    // Calculate progress (excluding extras)
    $progressStmt = $db->prepare("SELECT 
            SUM(oi.quantity) AS ordered_total,
            COALESCE(SUM(CASE WHEN r.status = 'completed' THEN ri.quantity_returned ELSE 0 END),0) AS returned_completed,
            COALESCE(SUM(CASE WHEN r.id = :rid AND ri.is_extra = 0 THEN ri.quantity_returned ELSE 0 END),0) AS returned_current
        FROM order_items oi
        LEFT JOIN return_items ri ON ri.order_item_id = oi.id
        LEFT JOIN returns r ON r.id = ri.return_id
        WHERE oi.order_id = :oid");
    $progressStmt->execute([':oid' => $ret['order_id'], ':rid' => $returnId]);
    $prog = $progressStmt->fetch(PDO::FETCH_ASSOC);
    $orderedTotal = (int)$prog['ordered_total'];
    $returnedCompleted = (int)$prog['returned_completed'];
    $returnedCurrent = (int)$prog['returned_current'];
    $expectedTotal = max($orderedTotal - $returnedCompleted, 0);
    $progress = [
        'verified' => min($returnedCurrent, $expectedTotal),
        'expected' => $expectedTotal
    ];

    http_response_code(201);
    $response = ['success' => true, 'item_id' => $itemId, 'is_extra' => (bool)$isExtra, 'progress' => $progress];
    if ($isExtra) {
        $response['message'] = 'Item not in original order; recorded as extra';
    }
    echo json_encode($response);
}

function addDiscrepancy(PDO $db, int $returnId) {
    $input = getJsonInput();
    $productId = isset($input['product_id']) ? (int)$input['product_id'] : 0;
    $type = $input['discrepancy_type'] ?? '';
    $expected = isset($input['expected_quantity']) ? (int)$input['expected_quantity'] : 0;
    $actual = isset($input['actual_quantity']) ? (int)$input['actual_quantity'] : 0;
    $condition = $input['item_condition'] ?? 'good';
    $notes = $input['notes'] ?? '';

    if ($productId <= 0 || !in_array($type, ['missing','extra','damaged'])) {
        throw new Exception('Invalid input', 400);
    }
    if (!in_array($condition, ['good','damaged','defective','opened'])) {
        throw new Exception('Invalid item_condition', 400);
    }

    $stmt = $db->prepare("SELECT order_id FROM returns WHERE id = :id");
    $stmt->execute([':id' => $returnId]);
    $ret = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ret) {
        throw new Exception('Return not found', 404);
    }

    $pstmt = $db->prepare("SELECT product_id FROM products WHERE product_id = :pid");
    $pstmt->execute([':pid' => $productId]);
    if (!$pstmt->fetchColumn()) {
        throw new Exception('Product not found', 404);
    }

    $oStmt = $db->prepare("SELECT id FROM order_items WHERE order_id = :oid AND product_id = :pid");
    $oStmt->execute([':oid' => $ret['order_id'], ':pid' => $productId]);
    $orderItemId = $oStmt->fetchColumn();
    if (!$orderItemId) {
        $orderItemId = null;
    }

    $insert = $db->prepare("INSERT INTO return_discrepancies (return_id, order_item_id, product_id, discrepancy_type, expected_quantity, actual_quantity, item_condition, notes)
                             VALUES (:rid, :oiid, :pid, :dtype, :exp, :act, :cond, :notes)");
    $insert->bindValue(':rid', $returnId, PDO::PARAM_INT);
    if ($orderItemId !== null) {
        $insert->bindValue(':oiid', $orderItemId, PDO::PARAM_INT);
    } else {
        $insert->bindValue(':oiid', null, PDO::PARAM_NULL);
    }
    $insert->bindValue(':pid', $productId, PDO::PARAM_INT);
    $insert->bindValue(':dtype', $type, PDO::PARAM_STR);
    $insert->bindValue(':exp', $expected, PDO::PARAM_INT);
    $insert->bindValue(':act', $actual, PDO::PARAM_INT);
    $insert->bindValue(':cond', $condition, PDO::PARAM_STR);
    $insert->bindValue(':notes', $notes, PDO::PARAM_STR);
    $insert->execute();
    $discId = (int)$db->lastInsertId();

    http_response_code(201);
    echo json_encode(['success' => true, 'discrepancy_id' => $discId]);
}

function completeReturn(PDO $db, int $returnId) {
    $input = getJsonInput();
    $verifiedBy = isset($input['verified_by']) ? (int)$input['verified_by'] : 0;
    $notes = $input['notes'] ?? '';

    $stmt = $db->prepare("SELECT status FROM returns WHERE id = :id");
    $stmt->execute([':id' => $returnId]);
    $ret = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ret) {
        throw new Exception('Return not found', 404);
    }
    if ($ret['status'] === 'completed') {
        throw new Exception('Return already completed', 409);
    }

    try {
        $db->beginTransaction();

        $itemsStmt = $db->prepare("SELECT product_id, quantity_returned FROM return_items WHERE return_id = :rid");
        $itemsStmt->execute([':rid' => $returnId]);
        while ($row = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
            $upd = $db->prepare("UPDATE products SET quantity = quantity + :qty WHERE product_id = :pid");
            $upd->execute([':qty' => $row['quantity_returned'], ':pid' => $row['product_id']]);
        }

        $update = $db->prepare("UPDATE returns SET status = 'completed', verified_by = :vby, verified_at = NOW(), notes = :notes WHERE id = :id");
        if ($verifiedBy > 0) {
            $update->bindValue(':vby', $verifiedBy, PDO::PARAM_INT);
        } else {
            $update->bindValue(':vby', null, PDO::PARAM_NULL);
        }
        $update->bindValue(':notes', $notes, PDO::PARAM_STR);
        $update->bindValue(':id', $returnId, PDO::PARAM_INT);
        $update->execute();

        $db->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function summaryReturn(PDO $db, int $returnId) {
    $stmt = $db->prepare("SELECT r.id, r.status, r.order_id, r.processed_by, r.verified_by, r.notes, r.created_at, r.updated_at, r.verified_at,
                                 o.order_number, o.customer_name
                          FROM returns r
                          JOIN orders o ON r.order_id = o.id
                          WHERE r.id = :id");
    $stmt->execute([':id' => $returnId]);
    $ret = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ret) {
        throw new Exception('Return not found', 404);
    }

    $itemsStmt = $db->prepare("SELECT ri.id, ri.product_id, p.sku, p.name, ri.quantity_returned, ri.item_condition, ri.is_extra, ri.notes
                               FROM return_items ri
                               JOIN products p ON ri.product_id = p.product_id
                               WHERE ri.return_id = :rid");
    $itemsStmt->execute([':rid' => $returnId]);
    $itemRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    $items = array_map(function($r) {
        return [
            'id' => (int)$r['id'],
            'product_id' => (int)$r['product_id'],
            'sku' => $r['sku'],
            'product_name' => $r['name'],
            'quantity_returned' => (int)$r['quantity_returned'],
            'item_condition' => $r['item_condition'],
            'is_extra' => (bool)$r['is_extra'],
            'notes' => $r['notes']
        ];
    }, $itemRows);

    $discStmt = $db->prepare("SELECT rd.id, rd.product_id, p.sku, p.name, rd.discrepancy_type, rd.expected_quantity, rd.actual_quantity, rd.item_condition, rd.notes
                               FROM return_discrepancies rd
                               JOIN products p ON rd.product_id = p.product_id
                               WHERE rd.return_id = :rid");
    $discStmt->execute([':rid' => $returnId]);
    $discRows = $discStmt->fetchAll(PDO::FETCH_ASSOC);
    $discrepancies = array_map(function($r) {
        return [
            'id' => (int)$r['id'],
            'product_id' => (int)$r['product_id'],
            'sku' => $r['sku'],
            'product_name' => $r['name'],
            'discrepancy_type' => $r['discrepancy_type'],
            'expected_quantity' => (int)$r['expected_quantity'],
            'actual_quantity' => (int)$r['actual_quantity'],
            'item_condition' => $r['item_condition'],
            'notes' => $r['notes']
        ];
    }, $discRows);

    echo json_encode([
        'success' => true,
        'return' => [
            'id' => (int)$ret['id'],
            'status' => $ret['status'],
            'order_id' => (int)$ret['order_id'],
            'order_number' => $ret['order_number'],
            'customer_name' => $ret['customer_name'],
            'processed_by' => (int)$ret['processed_by'],
            'verified_by' => $ret['verified_by'] !== null ? (int)$ret['verified_by'] : null,
            'notes' => $ret['notes'],
            'created_at' => $ret['created_at'],
            'updated_at' => $ret['updated_at'],
            'verified_at' => $ret['verified_at']
        ],
        'items' => $items,
        'discrepancies' => $discrepancies
    ]);
}