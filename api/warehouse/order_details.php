<?php
// File: /api/warehouse/order_details.php - Mobile-friendly order details
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Define Base Path
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

if (!file_exists(BASE_PATH . '/config/config.php')) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Config file missing.']);
    exit;
}

try {
    $config = require BASE_PATH . '/config/config.php';
    
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database config error.']);
        exit;
    }

    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    // Get order ID from request
    $orderId = $_GET['id'] ?? $_GET['order_id'] ?? '';

    if (empty($orderId)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Order ID is required.']);
        exit;
    }

    // Get order details (support both ID and order number)
    $orderQuery = "
        SELECT 
            o.*,
            COALESCE(o.awb_barcode, '') as tracking_number
        FROM orders o 
        WHERE o.id = :order_id OR o.order_number = :order_id
    ";

    $orderStmt = $db->prepare($orderQuery);
    $orderStmt->execute([':order_id' => $orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Order not found.']);
        exit;
    }

    // Get order items
    $itemsQuery = "
        SELECT
            oi.id as order_item_id,
            oi.product_id,
            oi.quantity as quantity_ordered,
            oi.picked_quantity,
            oi.unit_price,
            (oi.quantity * oi.unit_price) AS line_total,
            (oi.quantity - COALESCE(oi.picked_quantity, 0)) as remaining_to_pick,
            p.name AS product_name,
            p.sku,
            p.barcode as product_barcode,
            -- Use GROUP_CONCAT to list all locations without creating duplicate rows
            GROUP_CONCAT(DISTINCT l.location_code ORDER BY l.location_code SEPARATOR ', ') AS location_code
        FROM order_items oi
        JOIN products p ON oi.product_id = p.product_id
        LEFT JOIN inventory i ON oi.product_id = i.product_id AND i.quantity > 0
        LEFT JOIN locations l ON i.location_id = l.id
        WHERE oi.order_id = :order_id
        -- Add GROUP BY to ensure one row per order item
        GROUP BY oi.id
        ORDER BY oi.id
    ";

    $itemsStmt = $db->prepare($itemsQuery);
    $itemsStmt->execute([':order_id' => $order['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals
    $totalItems = count($items);
    $totalQuantityOrdered = array_sum(array_column($items, 'quantity_ordered'));
    $totalQuantityPicked = array_sum(array_column($items, 'picked_quantity'));
    $totalRemaining = array_sum(array_column($items, 'remaining_to_pick'));
    $progressPercent = $totalQuantityOrdered > 0 ? round(($totalQuantityPicked / $totalQuantityOrdered) * 100, 1) : 0;

    // Status translation
    $statusTranslations = [
        'Pending' => 'În Așteptare',
        'Processing' => 'În Procesare',
        'Completed' => 'Finalizat',
        'Shipped' => 'Expediat',
        'Cancelled' => 'Anulat'
    ];

    // Prepare response
    $response = [
        'status' => 'success',
        'data' => [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'customer_name' => $order['customer_name'],
            'customer_email' => $order['customer_email'],
            'shipping_address' => $order['shipping_address'],
            'address_text' => $order['address_text'],
            'order_date' => $order['order_date'],
            'status' => $order['status'],
            'status_label' => $statusTranslations[$order['status']] ?? $order['status'],
            'tracking_number' => $order['tracking_number'],
            'total_value' => number_format((float)$order['total_value'], 2),
            'notes' => $order['notes'],
            'recipient_county_id' => $order['recipient_county_id'],
            'recipient_county_name' => $order['recipient_county_name'],
            'recipient_locality_id' => $order['recipient_locality_id'],
            'recipient_locality_name' => $order['recipient_locality_name'],
            
            // Progress information
            'progress' => [
                'total_items' => $totalItems,
                'total_quantity_ordered' => $totalQuantityOrdered,
                'total_quantity_picked' => $totalQuantityPicked,
                'total_remaining' => $totalRemaining,
                'progress_percent' => $progressPercent,
                'is_complete' => ($totalRemaining === 0)
            ],
            
            // Order items
            'items' => array_map(function($item) {
                return [
                    'order_item_id'   => (int)$item['order_item_id'],
                    'product_id'      => (int)$item['product_id'],
                    'product_name'    => $item['product_name'],
                    'sku'             => $item['sku'],
                    'product_barcode' => $item['product_barcode'],
                    'quantity_ordered' => (int)$item['quantity_ordered'],
                    'picked_quantity'   => (int)$item['picked_quantity'],
                    'remaining_to_pick' => (int)$item['remaining_to_pick'],
                    'unit_price'        => (float)$item['unit_price'],
                    'line_total'        => (float)$item['line_total'],
                    'location_code'     => $item['location_code'],
                    'is_complete'       => ((int)$item['remaining_to_pick'] === 0)
                ];
            }, $items)
        ]
    ];

    // Include tracking information when an AWB exists
    if (!empty($order['tracking_number'])) {
        require_once BASE_PATH . '/models/CargusService.php';
        $cargus = new CargusService($db);
        $track = $cargus->trackAWB($order['tracking_number']);

        if ($track['success']) {
            $events = $track['data']['history'] ?? [];
            $pudoPoints = $cargus->getPudoPoints();

            // Map PUDO points by lowercase name for faster lookup
            $pudoMap = [];
            foreach ($pudoPoints as $p) {
                $name = $p['Name'] ?? $p['name'] ?? null;
                if ($name) {
                    $pudoMap[strtolower($name)] = [
                        'lat' => $p['Latitude'] ?? $p['latitude'] ?? null,
                        'lng' => $p['Longitude'] ?? $p['longitude'] ?? null
                    ];
                }
            }

            $eventsWithCoords = [];
            foreach ($events as $ev) {
                $loc = $ev['location'] ?? '';
                $coords = $pudoMap[strtolower($loc)] ?? ['lat' => null, 'lng' => null];
                $eventsWithCoords[] = [
                    'time' => $ev['time'],
                    'status' => $ev['event'],
                    'location' => $loc,
                    'lat' => $coords['lat'],
                    'lng' => $coords['lng']
                ];
            }

            $currentLocation = end($eventsWithCoords) ?: null;
            $progress = 0;
            if (!empty($track['data']['status'])) {
                $progress = stripos($track['data']['status'], 'delivered') !== false ? 100 : min(99, count($eventsWithCoords) * 20);
            }

            $response['data']['tracking'] = [
                'current_status' => $track['data']['status'] ?? null,
                'current_location' => $currentLocation,
                'events' => $eventsWithCoords,
                'progress_percent' => $progress
            ];
        }
    }

    echo json_encode($response);

} catch (PDOException $e) {
    error_log("Database error in order_details.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error.',
        'error_code' => 'DB_ERROR'
    ]);
} catch (Exception $e) {
    error_log("General error in order_details.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error.',
        'error_code' => 'SERVER_ERROR'
    ]);
}
?>