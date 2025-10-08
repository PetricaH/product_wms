<?php
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Neautorizat.']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (function_exists('apache_request_headers')) {
    $headers = array_change_key_case(apache_request_headers(), CASE_UPPER);
    $csrfToken = $csrfToken ?: ($headers['X-CSRF-TOKEN'] ?? '');
}

if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF invalid.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodă nepermisă.']);
    exit;
}

try {
    $config = require BASE_PATH . '/config/config.php';
    $dbFactory = $config['connection_factory'] ?? null;
    if (!$dbFactory || !is_callable($dbFactory)) {
        throw new RuntimeException('Conexiunea la baza de date nu este disponibilă.');
    }

    $db = $dbFactory();

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Date JSON invalide.');
    }

    $returnId = isset($payload['return_id']) ? (int)$payload['return_id'] : 0;
    $orderItemId = isset($payload['order_item_id']) ? (int)$payload['order_item_id'] : 0;
    $productId = isset($payload['product_id']) ? (int)$payload['product_id'] : 0;
    $quantityReceived = isset($payload['quantity_received']) ? (int)$payload['quantity_received'] : 0;
    $condition = isset($payload['condition']) ? strtolower(trim((string)$payload['condition'])) : '';
    $locationRaw = $payload['location_id'] ?? null;
    $locationId = null;
    if ($locationRaw !== null && $locationRaw !== '') {
        if (is_numeric($locationRaw)) {
            $locationId = (int)$locationRaw;
        } else {
            throw new InvalidArgumentException('Locația selectată nu este validă.');
        }
    }
    $notes = isset($payload['notes']) ? trim((string)$payload['notes']) : '';

    if ($returnId <= 0) {
        throw new InvalidArgumentException('Returul selectat nu este valid.');
    }
    if ($orderItemId <= 0) {
        throw new InvalidArgumentException('Articolul din comandă este obligatoriu.');
    }
    if ($productId <= 0) {
        throw new InvalidArgumentException('Produsul selectat nu este valid.');
    }
    if ($quantityReceived < 0) {
        throw new InvalidArgumentException('Cantitatea primită trebuie să fie un număr pozitiv.');
    }
    $requiresLocation = $quantityReceived > 0;
    if ($requiresLocation && (!$locationId || $locationId <= 0)) {
        throw new InvalidArgumentException('Selectați o locație de depozitare pentru produs.');
    }

    $allowedConditions = ['good', 'damaged', 'defective', 'opened'];
    if (!in_array($condition, $allowedConditions, true)) {
        throw new InvalidArgumentException('Selectați o stare validă pentru produs.');
    }

    $db->beginTransaction();

    // Validate return record
    $returnStmt = $db->prepare('SELECT id, order_id FROM returns WHERE id = :id LIMIT 1');
    $returnStmt->execute([':id' => $returnId]);
    $returnRow = $returnStmt->fetch(PDO::FETCH_ASSOC);

    if (!$returnRow) {
        throw new InvalidArgumentException('Returul selectat nu a fost găsit.');
    }

    $orderId = (int)($returnRow['order_id'] ?? 0);
    if ($orderId <= 0) {
        throw new InvalidArgumentException('Returul nu are o comandă asociată.');
    }

    // Validate order item and expected quantity
    $itemStmt = $db->prepare(
        'SELECT oi.id, oi.order_id, oi.product_id, oi.quantity, COALESCE(oi.picked_quantity, oi.quantity) AS expected_quantity
         FROM order_items oi
         WHERE oi.id = :id
         LIMIT 1'
    );
    $itemStmt->execute([':id' => $orderItemId]);
    $orderItem = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$orderItem || (int)$orderItem['order_id'] !== $orderId) {
        throw new InvalidArgumentException('Articolul nu aparține comenzii acestui retur.');
    }

    if ((int)$orderItem['product_id'] !== $productId) {
        throw new InvalidArgumentException('Produsul selectat nu se potrivește cu articolul din comandă.');
    }

    // Validate location exists and is active
    $location = null;
    if ($locationId && $locationId > 0) {
        $locStmt = $db->prepare("SELECT id, location_code, status FROM locations WHERE id = :id LIMIT 1");
        $locStmt->execute([':id' => $locationId]);
        $location = $locStmt->fetch(PDO::FETCH_ASSOC);
        if (!$location || ($location['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('Locația selectată nu este activă.');
        }
    } else {
        $locationId = null;
    }

    $expectedQuantity = (int)($orderItem['expected_quantity'] ?? 0);

    // Insert or update return item
    $existingStmt = $db->prepare(
        'SELECT id FROM return_items WHERE return_id = :return_id AND order_item_id = :order_item_id LIMIT 1'
    );
    $existingStmt->execute([
        ':return_id' => $returnId,
        ':order_item_id' => $orderItemId
    ]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $updateStmt = $db->prepare(
            'UPDATE return_items
             SET quantity_returned = :quantity,
                 item_condition = :condition,
                 location_id = :location_id,
                 notes = :notes,
                 is_extra = 0,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':quantity' => $quantityReceived,
            ':condition' => $condition,
            ':location_id' => $locationId,
            ':notes' => $notes !== '' ? $notes : null,
            ':id' => (int)$existing['id']
        ]);
        $returnItemId = (int)$existing['id'];
    } else {
        $insertStmt = $db->prepare(
            'INSERT INTO return_items
                 (return_id, order_item_id, product_id, quantity_returned, item_condition, is_extra, notes, location_id, created_at, updated_at)
             VALUES
                 (:return_id, :order_item_id, :product_id, :quantity, :condition, 0, :notes, :location_id, NOW(), NOW())'
        );
        $insertStmt->execute([
            ':return_id' => $returnId,
            ':order_item_id' => $orderItemId,
            ':product_id' => $productId,
            ':quantity' => $quantityReceived,
            ':condition' => $condition,
            ':notes' => $notes !== '' ? $notes : null,
            ':location_id' => $locationId
        ]);
        $returnItemId = (int)$db->lastInsertId();
    }

    // Handle discrepancies
    $notesForDiscrepancy = $notes !== '' ? $notes : null;

    $discrepancyStmt = $db->prepare(
        'INSERT INTO return_discrepancies
            (return_id, order_item_id, product_id, discrepancy_type, expected_quantity, actual_quantity, item_condition, notes, updated_at)
         VALUES
            (:return_id, :order_item_id, :product_id, :type, :expected, :actual, :condition, :notes, NOW())
         ON DUPLICATE KEY UPDATE
            expected_quantity = VALUES(expected_quantity),
            actual_quantity = VALUES(actual_quantity),
            item_condition = VALUES(item_condition),
            notes = VALUES(notes),
            updated_at = VALUES(updated_at)'
    );

    $deleteDiscrepancyStmt = $db->prepare(
        'DELETE FROM return_discrepancies WHERE return_id = :return_id AND order_item_id = :order_item_id AND product_id = :product_id AND discrepancy_type = :type'
    );

    // Quantity short
    if ($quantityReceived < $expectedQuantity) {
        $discrepancyStmt->execute([
            ':return_id' => $returnId,
            ':order_item_id' => $orderItemId,
            ':product_id' => $productId,
            ':type' => 'quantity_short',
            ':expected' => $expectedQuantity,
            ':actual' => $quantityReceived,
            ':condition' => $condition,
            ':notes' => $notesForDiscrepancy
        ]);
    } else {
        $deleteDiscrepancyStmt->execute([
            ':return_id' => $returnId,
            ':order_item_id' => $orderItemId,
            ':product_id' => $productId,
            ':type' => 'quantity_short'
        ]);
    }

    // Quantity over
    if ($quantityReceived > $expectedQuantity) {
        $discrepancyStmt->execute([
            ':return_id' => $returnId,
            ':order_item_id' => $orderItemId,
            ':product_id' => $productId,
            ':type' => 'quantity_over',
            ':expected' => $expectedQuantity,
            ':actual' => $quantityReceived,
            ':condition' => $condition,
            ':notes' => $notesForDiscrepancy
        ]);
    } else {
        $deleteDiscrepancyStmt->execute([
            ':return_id' => $returnId,
            ':order_item_id' => $orderItemId,
            ':product_id' => $productId,
            ':type' => 'quantity_over'
        ]);
    }

    // Condition issue
    if ($condition !== 'good') {
        $discrepancyStmt->execute([
            ':return_id' => $returnId,
            ':order_item_id' => $orderItemId,
            ':product_id' => $productId,
            ':type' => 'condition_issue',
            ':expected' => $expectedQuantity,
            ':actual' => $quantityReceived,
            ':condition' => $condition,
            ':notes' => $notesForDiscrepancy
        ]);
    } else {
        $deleteDiscrepancyStmt->execute([
            ':return_id' => $returnId,
            ':order_item_id' => $orderItemId,
            ':product_id' => $productId,
            ':type' => 'condition_issue'
        ]);
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Produsul a fost înregistrat pentru retur.',
        'return_item_id' => $returnItemId,
        'quantity_received' => $quantityReceived,
        'condition' => $condition,
        'location_id' => $locationId
    ]);
} catch (InvalidArgumentException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('add_return_item error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A apărut o eroare la înregistrarea produsului.']);
}
