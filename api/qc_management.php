<?php
/**
 * QC Management API
 * File: api/qc_management.php
 * 
 * Handles all quality control operations for supervisors
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

// Session and authentication check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'supervisor'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Supervisor role required.']);
    exit;
}

// Database connection
$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($db, $path);
            break;
        case 'POST':
            handlePost($db, $path);
            break;
        case 'PUT':
            handlePut($db, $path);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log('QC Management API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}

/**
 * Handle GET requests
 */
function handleGet($db, $path) {
    switch ($path) {
        case 'pending-items':
            getPendingItems($db);
            break;
        case 'qc-stats':
            getQcStats($db);
            break;
        case 'qc-locations':
            getQcLocations($db);
            break;
        case 'decision-history':
            getDecisionHistory($db);
            break;
        case 'get-supplier-info':
            getSupplierInfo($db);
            break;
        case 'notification-history':
            getNotificationHistoryApi($db);
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
}

/**
 * Handle POST requests
 */
function handlePost($db, $path) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($path) {
        case 'approve-items':
            approveItems($db, $input);
            break;
        case 'reject-items':
            rejectItems($db, $input);
            break;
        case 'bulk-decision':
            bulkDecision($db, $input);
            break;
        case 'notify-supplier':
            notifySupplier($db, $input);
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
}

/**
 * Get pending QC items
 */
function getPendingItems($db) {
    $filters = [
        'status' => $_GET['status'] ?? 'pending',
        'location_type' => $_GET['location_type'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'limit' => (int)($_GET['limit'] ?? 50),
        'offset' => (int)($_GET['offset'] ?? 0)
    ];

    $query = "
        SELECT 
            ri.id,
            ri.approval_status,
            ri.condition_status,
            ri.expected_quantity,
            ri.received_quantity,
            ri.notes as receiving_notes,
            ri.created_at as received_at,
            ri.batch_number,
            ri.expiry_date,
            
            -- Product information
            pp.supplier_product_name as product_name,
            pp.supplier_product_code as product_code,
            p.name as internal_product_name,
            p.sku as internal_sku,
            
            -- Purchase order information
            po.order_number,
            s.supplier_name,
            
            -- Receiving session information
            rs.session_number,
            u.username as received_by_user,
            
            -- Location information
            l.location_code,
            l.zone,
            l.type as location_type,
            
            -- Discrepancy information
            rd.discrepancy_type,
            rd.description as discrepancy_description,
            rd.resolution_status as discrepancy_status
            
        FROM receiving_items ri
        LEFT JOIN purchase_order_items poi ON ri.purchase_order_item_id = poi.id
        LEFT JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
        LEFT JOIN products p ON pp.internal_product_id = p.product_id
        LEFT JOIN receiving_sessions rs ON ri.receiving_session_id = rs.id
        LEFT JOIN purchase_orders po ON rs.purchase_order_id = po.id
        LEFT JOIN sellers s ON po.seller_id = s.id
        LEFT JOIN users u ON rs.received_by = u.id
        LEFT JOIN locations l ON ri.location_id = l.id
        LEFT JOIN receiving_discrepancies rd ON (rd.receiving_session_id = rs.id AND rd.product_id = ri.product_id)
        
        WHERE ri.approval_status = :status
    ";
    
    $params = [':status' => $filters['status']];
    
    if (!empty($filters['location_type'])) {
        $query .= " AND l.type = :location_type";
        $params[':location_type'] = $filters['location_type'];
    }
    
    if (!empty($filters['date_from'])) {
        $query .= " AND ri.created_at >= :date_from";
        $params[':date_from'] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $query .= " AND ri.created_at <= :date_to";
        $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
    }
    
    $query .= " ORDER BY ri.created_at DESC LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $filters['limit'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $filters['offset'], PDO::PARAM_INT);
    
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count for pagination
    $countQuery = str_replace(['ORDER BY ri.created_at DESC LIMIT :limit OFFSET :offset', 'SELECT ri.id,'], ['', 'SELECT COUNT(*) as total,'], $query);
    $countStmt = $db->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'data' => $items,
        'total' => (int)$total,
        'pagination' => [
            'limit' => $filters['limit'],
            'offset' => $filters['offset'],
            'has_next' => ($filters['offset'] + $filters['limit']) < $total
        ]
    ]);
}

/**
 * Get QC statistics
 */
function getQcStats($db) {
    $timeframe = $_GET['timeframe'] ?? '30'; // days
    
    $query = "
        SELECT 
            COUNT(*) as total_items,
            SUM(CASE WHEN approval_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN approval_status = 'approved' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN approval_status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
            SUM(CASE WHEN condition_status != 'good' THEN 1 ELSE 0 END) as damaged_count,
            SUM(CASE WHEN received_quantity != expected_quantity THEN 1 ELSE 0 END) as discrepancy_count
        FROM receiving_items 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL :timeframe DAY)
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':timeframe' => $timeframe]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get location-wise breakdown
    $locationQuery = "
        SELECT 
            l.type as location_type,
            l.zone,
            COUNT(*) as item_count
        FROM receiving_items ri
        JOIN locations l ON ri.location_id = l.id
        WHERE ri.created_at >= DATE_SUB(NOW(), INTERVAL :timeframe DAY)
          AND ri.approval_status = 'pending'
        GROUP BY l.type, l.zone
        ORDER BY item_count DESC
    ";
    
    $stmt = $db->prepare($locationQuery);
    $stmt->execute([':timeframe' => $timeframe]);
    $locationBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'location_breakdown' => $locationBreakdown,
        'timeframe_days' => (int)$timeframe
    ]);
}

/**
 * Approve items
 */
function approveItems($db, $input) {
    $itemIds = $input['item_ids'] ?? [];
    $supervisorNotes = $input['supervisor_notes'] ?? '';
    $moveToLocation = $input['move_to_location'] ?? null; // Optional: move to different location
    
    if (empty($itemIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'No items specified']);
        return;
    }
    
    $db->beginTransaction();
    
    try {
        $userId = $_SESSION['user_id'];
        $approvedCount = 0;
        
        foreach ($itemIds as $itemId) {
            // Get current item details
            $stmt = $db->prepare("
                SELECT ri.*, poi.purchasable_product_id, pp.internal_product_id 
                FROM receiving_items ri
                LEFT JOIN purchase_order_items poi ON ri.purchase_order_item_id = poi.id
                LEFT JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
                WHERE ri.id = :item_id AND ri.approval_status = 'pending'
            ");
            $stmt->execute([':item_id' => $itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) continue; // Skip if not found or not pending
            
            // Log the decision
            $stmt = $db->prepare("
                INSERT INTO qc_decisions (receiving_item_id, decision, decided_by, supervisor_notes, previous_status)
                VALUES (:item_id, 'approved', :user_id, :notes, :previous_status)
            ");
            $stmt->execute([
                ':item_id' => $itemId,
                ':user_id' => $userId,
                ':notes' => $supervisorNotes,
                ':previous_status' => $item['approval_status']
            ]);
            
            // Determine target location
            $targetLocationId = $moveToLocation ?: $item['location_id'];
            
            // Update receiving item
            $stmt = $db->prepare("
                UPDATE receiving_items 
                SET approval_status = 'approved',
                    approved_by = :user_id,
                    approved_at = NOW(),
                    supervisor_notes = :notes,
                    location_id = :location_id
                WHERE id = :item_id
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':notes' => $supervisorNotes,
                ':location_id' => $targetLocationId,
                ':item_id' => $itemId
            ]);
            
            // Update inventory if item is in good condition
            if ($item['condition_status'] === 'good') {
                $productId = $item['internal_product_id'] ?: $item['product_id'];
                
                // Check if inventory record exists
                $stmt = $db->prepare("
                    SELECT id, quantity FROM inventory 
                    WHERE product_id = :product_id AND location_id = :location_id
                ");
                $stmt->execute([
                    ':product_id' => $productId,
                    ':location_id' => $targetLocationId
                ]);
                $inventoryRecord = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($inventoryRecord) {
                    // Update existing inventory
                    $stmt = $db->prepare("
                        UPDATE inventory SET 
                            quantity = quantity + :quantity,
                            updated_at = NOW()
                        WHERE id = :inventory_id
                    ");
                    $stmt->execute([
                        ':quantity' => $item['received_quantity'],
                        ':inventory_id' => $inventoryRecord['id']
                    ]);
                } else {
                    // Create new inventory record
                    $stmt = $db->prepare("
                        INSERT INTO inventory (product_id, location_id, shelf_level, quantity, batch_number, expiry_date, received_at)
                        VALUES (:product_id, :location_id, :shelf_level, :quantity, :batch_number, :expiry_date, NOW())
                    ");
                    $stmt->execute([
                        ':product_id' => $productId,
                        ':location_id' => $targetLocationId,
                        ':shelf_level' => 'middle',
                        ':quantity' => $item['received_quantity'],
                        ':batch_number' => $item['batch_number'],
                        ':expiry_date' => $item['expiry_date']
                    ]);
                }
            }
            
            $approvedCount++;
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully approved {$approvedCount} items",
            'approved_count' => $approvedCount
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * Reject items
 */
function rejectItems($db, $input) {
    $itemIds = $input['item_ids'] ?? [];
    $rejectionReason = $input['rejection_reason'] ?? '';
    $supervisorNotes = $input['supervisor_notes'] ?? '';
    
    if (empty($itemIds) || empty($rejectionReason)) {
        http_response_code(400);
        echo json_encode(['error' => 'Item IDs and rejection reason are required']);
        return;
    }
    
    $db->beginTransaction();
    
    try {
        $userId = $_SESSION['user_id'];
        $rejectedCount = 0;
        
        foreach ($itemIds as $itemId) {
            // Get current item details
            $stmt = $db->prepare("
                SELECT approval_status FROM receiving_items 
                WHERE id = :item_id AND approval_status = 'pending'
            ");
            $stmt->execute([':item_id' => $itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) continue; // Skip if not found or not pending
            
            // Log the decision
            $stmt = $db->prepare("
                INSERT INTO qc_decisions (receiving_item_id, decision, decided_by, decision_reason, supervisor_notes, previous_status)
                VALUES (:item_id, 'rejected', :user_id, :reason, :notes, :previous_status)
            ");
            $stmt->execute([
                ':item_id' => $itemId,
                ':user_id' => $userId,
                ':reason' => $rejectionReason,
                ':notes' => $supervisorNotes,
                ':previous_status' => $item['approval_status']
            ]);
            
            // Update receiving item
            $stmt = $db->prepare("
                UPDATE receiving_items 
                SET approval_status = 'rejected',
                    approved_by = :user_id,
                    approved_at = NOW(),
                    rejection_reason = :reason,
                    supervisor_notes = :notes
                WHERE id = :item_id
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':reason' => $rejectionReason,
                ':notes' => $supervisorNotes,
                ':item_id' => $itemId
            ]);
            
            $rejectedCount++;
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully rejected {$rejectedCount} items",
            'rejected_count' => $rejectedCount
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * Get QC locations
 */
function getQcLocations($db) {
    $stmt = $db->prepare("
        SELECT id, location_code, zone, type, capacity, status, notes
        FROM locations 
        WHERE type IN ('qc_hold', 'quarantine', 'pending_approval')
          AND status = 'active'
        ORDER BY type, location_code
    ");
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'locations' => $locations
    ]);
}

/**
 * Get decision history
 */
function getDecisionHistory($db) {
    $itemId = $_GET['item_id'] ?? null;
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $query = "
        SELECT qd.*, u.username as decided_by_name, ri.product_id,
               pp.supplier_product_name as product_name
        FROM qc_decisions qd
        JOIN users u ON qd.decided_by = u.id
        JOIN receiving_items ri ON qd.receiving_item_id = ri.id
        LEFT JOIN purchase_order_items poi ON ri.purchase_order_item_id = poi.id
        LEFT JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
    ";
    
    $params = [];
    if ($itemId) {
        $query .= " WHERE qd.receiving_item_id = :item_id";
        $params[':item_id'] = $itemId;
    }
    
    $query .= " ORDER BY qd.created_at DESC LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'history' => $history
    ]);
}

/**
 * Get supplier information and item details
 */
function getSupplierInfo($db) {
    $itemId = intval($_GET['receiving_item_id'] ?? 0);
    if ($itemId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing receiving_item_id']);
        return;
    }

    $stmt = $db->prepare("
        SELECT
            ri.id as receiving_item_id,
            ri.expected_quantity,
            ri.received_quantity,
            ri.condition_status,
            ri.batch_number,
            ri.expiry_date,
            ri.notes as operator_notes,
            ri.supplier_notification_count,
            ri.last_notification_at,
            rs.id as session_id,
            rs.session_number,
            rs.purchase_order_id,
            rs.created_at as received_date,
            po.order_number,
            s.id as seller_id,
            s.supplier_name as name,
            s.email,
            s.contact_person,
            s.phone,
            l.location_code,
            pp.supplier_product_name as product_name
        FROM receiving_items ri
        LEFT JOIN purchase_order_items poi ON ri.purchase_order_item_id = poi.id
        LEFT JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
        LEFT JOIN receiving_sessions rs ON ri.receiving_session_id = rs.id
        LEFT JOIN purchase_orders po ON rs.purchase_order_id = po.id
        LEFT JOIN sellers s ON po.seller_id = s.id
        LEFT JOIN locations l ON ri.location_id = l.id
        WHERE ri.id = :item_id
    ");
    $stmt->execute([':item_id' => $itemId]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$info) {
        http_response_code(404);
        echo json_encode(['error' => 'Item not found']);
        return;
    }

    // Discover images from storage/receiving/session_number
    $images = [];
    if (!empty($info['session_number'])) {
        $dir = BASE_PATH . '/storage/receiving/' . $info['session_number'];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.{jpg,jpeg,png,gif}', GLOB_BRACE) as $img) {
                $images[] = str_replace(BASE_PATH, '', $img);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $info,
        'images' => $images
    ]);
}

/**
 * Get detailed notification history
 */
function getNotificationHistoryApi($db) {
    $itemId = intval($_GET['item_id'] ?? 0);
    if ($itemId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing item_id']);
        return;
    }

    $stmt = $db->prepare("SELECT * FROM supplier_notifications WHERE receiving_item_id = :id ORDER BY created_at DESC");
    $stmt->execute([':id' => $itemId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'history' => $history]);
}

/**
 * Send supplier notification email and log
 */
function notifySupplier($db, $input) {
    $itemId = intval($input['receiving_item_id'] ?? 0);
    $subject = trim($input['email_subject'] ?? '');
    $body = trim($input['email_body'] ?? '');
    $selectedInfo = $input['selected_info'] ?? [];
    $images = $input['selected_images'] ?? [];
    if ($itemId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid receiving_item_id']);
        return;
    }
    if ($subject === '' && $body === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Email subject or body required']);
        return;
    }

    // Fetch supplier and SMTP info via getSupplierInfo query
    $_GET['receiving_item_id'] = $itemId;
    ob_start();
    getSupplierInfo($db);
    $response = ob_get_clean();
    $info = json_decode($response, true);
    if (!$info || empty($info['success'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Item not found']);
        return;
    }
    $data = $info['data'];

    // Build final email body
    $infoLines = [];
    foreach ($selectedInfo as $key => $val) {
        if (isset($data[$key])) {
            $infoLines[] = ucfirst($key) . ': ' . $data[$key];
        }
    }
    $finalBody = $body . "\n\n" . implode("\n", $infoLines);

    // Load SMTP settings from config
    $smtp = [
        'smtp_host' => getenv('SMTP_HOST'),
        'smtp_port' => getenv('SMTP_PORT') ?: 587,
        'smtp_user' => getenv('SMTP_USER'),
        'smtp_pass' => getenv('SMTP_PASS'),
        'smtp_secure' => getenv('SMTP_SECURE') ?: ''
    ];

    $emailResult = sendSupplierNotificationEmail($smtp, $data['email'], $subject, $finalBody, $images);
    $status = $emailResult['success'] ? 'sent' : 'failed';

    // Log notification
    $stmt = $db->prepare("
        INSERT INTO supplier_notifications (receiving_item_id, purchase_order_id, seller_id, sent_by, email_subject, email_body, selected_info, attached_images, delivery_status)
        VALUES (:ri, :po, :seller, :user, :subj, :body, :info, :images, :status)
    ");
    $stmt->execute([
        ':ri' => $itemId,
        ':po' => $data['purchase_order_id'],
        ':seller' => $data['seller_id'],
        ':user' => $_SESSION['user_id'],
        ':subj' => $subject,
        ':body' => $finalBody,
        ':info' => json_encode($selectedInfo),
        ':images' => json_encode($images),
        ':status' => $status
    ]);

    // Update receiving item counters
    $db->exec("UPDATE receiving_items SET supplier_notified = 1, supplier_notification_count = supplier_notification_count + 1, last_notification_at = NOW() WHERE id = " . intval($itemId));

    echo json_encode($emailResult);
}

function sendSupplierNotificationEmail(array $smtp, string $to, string $subject, string $body, array $attachments = []): array {
    require_once BASE_PATH . '/lib/PHPMailer/PHPMailer.php';
    require_once BASE_PATH . '/lib/PHPMailer/SMTP.php';
    require_once BASE_PATH . '/lib/PHPMailer/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $smtp['smtp_host'];
        $mail->Port = intval($smtp['smtp_port']);
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['smtp_user'];
        $mail->Password = $smtp['smtp_pass'];
        if (!empty($smtp['smtp_secure'])) {
            $mail->SMTPSecure = $smtp['smtp_secure'];
        }
        $fromEmail = $smtp['smtp_user'];
        $mail->setFrom($fromEmail, 'WMS QC');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->isHTML(false);
        foreach ($attachments as $path) {
            $fullPath = BASE_PATH . $path;
            if (is_file($fullPath)) {
                $mail->addAttachment($fullPath);
            }
        }
        $mail->send();
        return ['success' => true, 'message' => 'Email sent'];
    } catch (Exception $e) {
        error_log('Supplier notification email error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}