<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!defined('BASE_PATH')) {
    $currentDir = __DIR__;
    $possiblePaths = [
        dirname($currentDir, 2),
        dirname($currentDir, 3),
        $_SERVER['DOCUMENT_ROOT'] ?? null,
        isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/product_wms' : null,
    ];

    foreach ($possiblePaths as $path) {
        if ($path && file_exists($path . '/config/config.php')) {
            define('BASE_PATH', $path);
            break;
        }
    }

    if (!defined('BASE_PATH')) {
        $scriptDir = dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
        $currentPath = $scriptDir;
        for ($i = 0; $i < 6; $i++) {
            if (file_exists($currentPath . '/config/config.php')) {
                define('BASE_PATH', $currentPath);
                break;
            }
            $currentPath = dirname($currentPath);
        }
    }

    if (!defined('BASE_PATH')) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Nu a putut fi determinată calea de bază a aplicației.'
        ]);
        exit;
    }
}

require_once BASE_PATH . '/bootstrap.php';

try {
    $config = require BASE_PATH . '/config/config.php';

    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        throw new RuntimeException('Configurația bazei de date este invalidă.');
    }

    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    require_once BASE_PATH . '/models/Order.php';

    $orderModel = new Order($db);

    $sinceRaw = trim($_GET['since'] ?? '');
    $statusFilter = strtolower(trim($_GET['status'] ?? ''));
    $priorityFilter = trim($_GET['priority'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 25)));

    $since = null;
    if ($sinceRaw !== '') {
        try {
            $sinceDate = new DateTimeImmutable($sinceRaw);
            $since = $sinceDate->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Parametrul "since" nu are un format valid ISO8601.'
            ]);
            exit;
        }
    }

    // Fetch more records than a single page to account for multiple updates.
    $updates = $orderModel->getOrdersUpdatedSince($since, $statusFilter, $priorityFilter, $search, $pageSize * 2);

    $statusLabels = $orderModel->getStatuses();
    $priorityLabels = [
        'normal' => 'Normal',
        'high' => 'Înaltă',
        'urgent' => 'Urgentă'
    ];

    $latestTimestamp = $since ? new DateTimeImmutable($since) : null;
    $responseOrders = [];

    foreach ($updates as $order) {
        $orderUpdatedAt = $order['updated_at'] ?? $order['created_at'] ?? $order['order_date'];
        $updatedAtIso = null;
        if (!empty($orderUpdatedAt)) {
            $updatedAtDate = new DateTimeImmutable($orderUpdatedAt);
            $updatedAtIso = $updatedAtDate->format(DateTimeInterface::ATOM);
            if ($latestTimestamp === null || $updatedAtDate > $latestTimestamp) {
                $latestTimestamp = $updatedAtDate;
            }
        }

        $orderDateIso = null;
        if (!empty($order['order_date'])) {
            $orderDateIso = (new DateTimeImmutable($order['order_date']))->format(DateTimeInterface::ATOM);
        }

        $awbCreatedAtIso = null;
        if (!empty($order['awb_created_at'])) {
            $awbCreatedAtIso = (new DateTimeImmutable($order['awb_created_at']))->format(DateTimeInterface::ATOM);
        }

        $items = $orderModel->getOrderItems((int)$order['id']);
        $weightParts = [];
        $calculatedWeight = 0.0;
        foreach ($items as $item) {
            $itemWeightPerUnit = (float)($item['weight_per_unit'] ?? 0);
            $itemWeight = $itemWeightPerUnit * (int)($item['quantity'] ?? 0);
            $calculatedWeight += $itemWeight;
            if ($itemWeightPerUnit > 0) {
                $weightParts[] = sprintf('%s (%d×%skg)', $item['product_name'] ?? 'Produs', (int)($item['quantity'] ?? 0), number_format($itemWeightPerUnit, 3, '.', ''));
            }
        }

        $displayWeight = (float)($order['total_weight'] ?? 0);
        if ($displayWeight <= 0 && $calculatedWeight > 0) {
            $displayWeight = $calculatedWeight;
        }

        $awbBarcode = trim((string)($order['awb_barcode'] ?? ''));

        $responseOrders[] = [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'customer_name' => $order['customer_name'],
            'customer_email' => $order['customer_email'],
            'status' => strtolower((string)$order['status']),
            'status_raw' => $order['status'],
            'status_label' => $statusLabels[strtolower((string)$order['status'])] ?? ucfirst((string)$order['status']),
            'priority' => strtolower((string)($order['priority'] ?? 'normal')),
            'priority_label' => $priorityLabels[strtolower((string)($order['priority'] ?? 'normal'))] ?? ucfirst((string)($order['priority'] ?? 'Normal')),
            'total_value' => (float)($order['total_value'] ?? 0),
            'total_items' => (int)($order['total_items'] ?? 0),
            'order_date' => $orderDateIso,
            'order_date_display' => !empty($order['order_date']) ? date('d.m.Y H:i', strtotime((string)$order['order_date'])) : null,
            'updated_at' => $updatedAtIso,
            'awb_barcode' => $awbBarcode,
            'awb_created_at' => $awbCreatedAtIso,
            'awb_generation_attempts' => (int)($order['awb_generation_attempts'] ?? 0),
            'total_weight' => $displayWeight,
            'weight_display' => $displayWeight > 0 ? number_format($displayWeight, 3, '.', '') : null,
            'weight_breakdown' => !empty($weightParts) ? implode(' + ', $weightParts) : '',
            'notes' => $order['notes'] ?? '',
            'cancellation_reason' => isset($order['cancellation_reason']) ? trim((string)$order['cancellation_reason']) : '',
        ];
    }

    $latestIso = $latestTimestamp ? $latestTimestamp->format(DateTimeInterface::ATOM) : ($since ? (new DateTimeImmutable($since))->format(DateTimeInterface::ATOM) : null);

    echo json_encode([
        'status' => 'success',
        'data' => [
            'orders' => $responseOrders,
            'latestTimestamp' => $latestIso,
            'page' => $page,
            'pageSize' => $pageSize,
        ]
    ]);
} catch (Throwable $e) {
    error_log('Order updates error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'A apărut o eroare la încărcarea actualizărilor de comenzi.'
    ]);
}
