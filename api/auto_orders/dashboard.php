<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'] ?? null;

if (!$dbFactory || !is_callable($dbFactory)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection factory not configured.'
    ]);
    exit;
}

try {
    $db = $dbFactory();

    require_once BASE_PATH . '/models/AutoOrderManager.php';

    $autoOrderManager = new AutoOrderManager($db);

    $statistics = $autoOrderManager->getAutoOrderStatistics();

    // Failed auto orders
    $failedAutoOrders = $autoOrderManager->getFailedAutoOrders();
    $failedCount = is_array($failedAutoOrders) ? count($failedAutoOrders) : 0;

    // Products below minimum stock with auto order enabled
    $productsQuery = $db->prepare(
        "SELECT p.product_id, p.name, p.sku, p.quantity, p.min_stock_level, p.last_auto_order_date,
                s.supplier_name
         FROM products p
         LEFT JOIN sellers s ON p.seller_id = s.id
         WHERE p.auto_order_enabled = 1
           AND p.min_stock_level IS NOT NULL
           AND p.min_stock_level > 0
           AND p.quantity <= p.min_stock_level
         ORDER BY p.quantity ASC, p.name ASC
         LIMIT 15"
    );
    $productsQuery->execute();
    $attentionProducts = $productsQuery->fetchAll(PDO::FETCH_ASSOC);

    $productsAtMinimum = count($attentionProducts);

    // Recent auto orders timeline (last 7 days)
    $recentOrdersStmt = $db->prepare(
        "SELECT po.id, po.order_number, po.status, po.total_amount, po.currency, po.created_at,
                po.email_sent_at, po.notes, s.supplier_name,
                GROUP_CONCAT(DISTINCT pr.name ORDER BY pr.name SEPARATOR ', ') AS product_names
         FROM purchase_orders po
         LEFT JOIN sellers s ON po.seller_id = s.id
         LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
         LEFT JOIN purchasable_products pp ON poi.purchasable_product_id = pp.id
         LEFT JOIN products pr ON pp.internal_product_id = pr.product_id
         WHERE (po.notes LIKE 'Autocomandă%' OR po.notes LIKE 'Auto-generată%' OR poi.notes LIKE 'Autocomandă%')
           AND po.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY po.id
         ORDER BY po.created_at DESC
         LIMIT 25"
    );
    $recentOrdersStmt->execute();
    $recentOrders = $recentOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'statistics' => [
                'totalAutoOrders' => $statistics['total_autocomenzi'] ?? 0,
                'autoOrdersLast30Days' => $statistics['autocomenzi_ultimele_30_zile'] ?? 0,
                'autoOrdersToday' => $statistics['autocomenzi_azi'] ?? 0,
                'autoOrdersPending' => $statistics['autocomenzi_nefinalizate'] ?? 0,
                'failedAutoOrders' => $failedCount,
                'productsAtMinimum' => $productsAtMinimum
            ],
            'recentOrders' => $recentOrders,
            'attentionProducts' => $attentionProducts,
            'failedAutoOrders' => $failedAutoOrders
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load auto order dashboard data.',
        'error' => $e->getMessage()
    ]);
}
