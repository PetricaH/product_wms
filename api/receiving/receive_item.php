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

// Session check
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF check
$headers = apache_request_headers();
$csrfToken = $headers['X-CSRF-Token'] ?? '';
if (!$csrfToken || $csrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
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

try {
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
    
    if (!$sessionId || !$itemId || $receivedQuantity <= 0) {
        throw new Exception('Session ID, item ID and received quantity (>0) are required');
    }
    
    if (!$locationCode) {
        throw new Exception('Location is required');
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
        throw new Exception('No matching main product found for this purchasable product');
    }
    
    // Validate location exists
    $stmt = $db->prepare("SELECT id, location_code FROM locations WHERE location_code = :location_code AND status = 'active'");
    $stmt->execute([':location_code' => $locationCode]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$location) {
        throw new Exception('Location not found or inactive');
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Check if item already received in this session
    $stmt = $db->prepare("
        SELECT id, received_quantity 
        FROM receiving_items 
        WHERE receiving_session_id = :session_id 
        AND purchase_order_item_id = :item_id
    ");
    $stmt->execute([
        ':session_id' => $sessionId,
        ':item_id' => $itemId
    ]);
    $existingReceiving = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingReceiving) {
        // Update existing receiving record
        $stmt = $db->prepare("
            UPDATE receiving_items SET
                received_quantity = :received_quantity,
                condition_status = :condition_status,
                batch_number = :batch_number,
                expiry_date = :expiry_date,
                location_id = :location_id,
                notes = :notes,
                updated_at = NOW()
            WHERE id = :receiving_item_id
        ");
        $stmt->execute([
            ':received_quantity' => $receivedQuantity,
            ':condition_status' => $conditionStatus,
            ':batch_number' => $batchNumber ?: null,
            ':expiry_date' => $expiryDate ?: null,
            ':location_id' => $location['id'],
            ':notes' => $notes,
            ':receiving_item_id' => $existingReceiving['id']
        ]);
        $receivingItemId = $existingReceiving['id'];
    } else {
        // Create new receiving record
        $stmt = $db->prepare("
            INSERT INTO receiving_items (
                receiving_session_id, product_id, purchase_order_item_id,
                expected_quantity, received_quantity, unit_price,
                condition_status, batch_number, expiry_date, location_id, notes
            ) VALUES (
                :session_id, :product_id, :item_id, :expected_quantity,
                :received_quantity, :unit_price, :condition_status,
                :batch_number, :expiry_date, :location_id, :notes
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
            ':notes' => $notes
        ]);
        $receivingItemId = $db->lastInsertId();
    }
    
    // Update inventory if condition is good
    if ($conditionStatus === 'good') {
        // Check if inventory record exists for this product/location
        $stmt = $db->prepare("
            SELECT id, quantity 
            FROM inventory 
            WHERE product_id = :product_id AND location_id = :location_id
        ");
        $stmt->execute([
            ':product_id' => $orderItem['main_product_id'],
            ':location_id' => $location['id']
        ]);
        $inventoryRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($inventoryRecord) {
            // Update existing inventory
            $stmt = $db->prepare("
                UPDATE inventory SET 
                    quantity = quantity + :received_quantity,
                    received_at = NOW(),
                    updated_at = NOW()
                WHERE id = :inventory_id
            ");
            $stmt->execute([
                ':received_quantity' => $receivedQuantity,
                ':inventory_id' => $inventoryRecord['id']
            ]);
        } else {
            // Create new inventory record
            $stmt = $db->prepare("
                INSERT INTO inventory (
                    product_id, location_id, quantity, batch_number, 
                    expiry_date, received_at
                ) VALUES (
                    :product_id, :location_id, :quantity, :batch_number,
                    :expiry_date, NOW()
                )
            ");
            $stmt->execute([
                ':product_id' => $orderItem['main_product_id'],
                ':location_id' => $location['id'],
                ':quantity' => $receivedQuantity,
                ':batch_number' => $batchNumber ?: null,
                ':expiry_date' => $expiryDate ?: null
            ]);
        }
    }
    
    // Check for discrepancies
    $expectedQuantity = (float)$orderItem['quantity'];
    if ($receivedQuantity != $expectedQuantity) {
        // Create discrepancy record
        $discrepancyType = $receivedQuantity < $expectedQuantity ? 'quantity_short' : 'quantity_over';
        $description = sprintf(
            'Expected %s, received %s for %s (SKU: %s)',
            $expectedQuantity,
            $receivedQuantity,
            $orderItem['product_name'],
            $orderItem['sku']
        );
        
        $stmt = $db->prepare("
            INSERT INTO receiving_discrepancies (
                receiving_session_id, purchasable_product_id, discrepancy_type,
                expected_quantity, actual_quantity, description
            ) VALUES (
                :session_id, :purchasable_product_id, :discrepancy_type,
                :expected_quantity, :actual_quantity, :description
            )
        ");
        $stmt->execute([
            ':session_id' => $sessionId,
            ':purchasable_product_id' => $orderItem['purchasable_product_id'],
            ':discrepancy_type' => $discrepancyType,
            ':expected_quantity' => $expectedQuantity,
            ':actual_quantity' => $receivedQuantity,
            ':description' => $description
        ]);
    }
    
    // Update session item counts
    $stmt = $db->prepare("
        UPDATE receiving_sessions SET 
            total_items_received = (
                SELECT COUNT(DISTINCT purchase_order_item_id) 
                FROM receiving_items 
                WHERE receiving_session_id = :session_id
            ),
            updated_at = NOW()
        WHERE id = :session_id
    ");
    $stmt->execute([':session_id' => $sessionId]);
    
    // Commit transaction
    $db->commit();
    
    // Determine item status
    $status = 'received';
    if ($receivedQuantity < $expectedQuantity) {
        $status = 'partial';
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Item received successfully',
        'receiving_item_id' => $receivingItemId,
        'status' => $status,
        'received_quantity' => $receivedQuantity,
        'expected_quantity' => $expectedQuantity,
        'location' => $location['location_code'],
        'has_discrepancy' => $receivedQuantity != $expectedQuantity
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