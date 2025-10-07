<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$orderId  = (int)($_GET['order_id']  ?? 0);
$returnId = (int)($_GET['return_id'] ?? 0);
if ($orderId <= 0 || $returnId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid order_id/return_id']);
    exit;
}

set_exception_handler(function (Throwable $e): void {
    error_log('[ReturnDetails] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'internal']);
    exit;
});

$lastSql = '';

try {
    $sqlHeader = '
        SELECT
            r.id,
            r.order_id,
            r.processed_by,
            r.verified_by,
            r.status,
            r.return_awb,
            r.auto_created,
            r.return_date,
            r.notes        AS return_notes,
            r.verified_at,
            r.created_at   AS return_created_at,
            r.updated_at   AS return_updated_at
        FROM returns r
        WHERE r.id = :return_id AND r.order_id = :order_id
        LIMIT 1
    ';
    $lastSql = $sqlHeader;
    $stmt = $pdo->prepare($sqlHeader);
    $stmt->execute([':return_id' => $returnId, ':order_id' => $orderId]);
    $header = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($header === false) {
        http_response_code(404);
        echo json_encode(['error' => 'Return not found for this order']);
        exit;
    }

    $sqlItems = '
        SELECT
            ri.id              AS return_item_id,
            ri.order_item_id,
            ri.product_id,
            ri.quantity_returned,
            ri.item_condition,
            ri.is_extra,
            ri.notes           AS item_notes,
            ri.created_at      AS item_created_at,
            ri.updated_at      AS item_updated_at
        FROM return_items ri
        WHERE ri.return_id = :return_id
        ORDER BY ri.id ASC
    ';
    $lastSql = $sqlItems;
    $stmt = $pdo->prepare($sqlItems);
    $stmt->execute([':return_id' => $returnId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'header' => $header,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('[ReturnDetails][SQL] ' . $e->getMessage() . ' | SQL: ' . $lastSql);
    http_response_code(500);
    echo json_encode(['error' => 'internal']);
}
