<?php
header('Content-Type: application/json');
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$locationId = intval($_GET['location_id'] ?? 0);
$levelNumber = intval($_GET['level'] ?? 0);
$productId  = intval($_GET['product_id'] ?? 0);
$apiKey = $_GET['api_key'] ?? '';

// optional API key check similar to location_info.php
if (!empty($config['api']['key']) && $apiKey !== ($config['api']['key'] ?? '')) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

if ($locationId <= 0 || $levelNumber <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

require_once BASE_PATH . '/models/LocationSubdivision.php';
require_once BASE_PATH . '/models/LocationLevelSettings.php';

$subModel = new LocationSubdivision($db);

// Determine shelf level name from number using dynamic settings
$lls = new LocationLevelSettings($db);
$levelName = $lls->getLevelNameByNumber($locationId, $levelNumber) ?? 'middle';

try {
    $query = "SELECT ls.*, p.name AS product_name,
                     COALESCE(SUM(i.quantity),0) AS total_qty,
                     COALESCE(SUM(CASE WHEN i.product_id = :pid THEN i.quantity ELSE 0 END),0) AS prod_qty
               FROM location_subdivisions ls
               LEFT JOIN products p ON ls.dedicated_product_id = p.product_id
               LEFT JOIN inventory i ON ls.location_id = i.location_id
                    AND i.shelf_level = :level_name
                    AND i.subdivision_number = ls.subdivision_number
               WHERE ls.location_id = :loc AND ls.level_number = :lvl
               GROUP BY ls.id
               ORDER BY ls.subdivision_number";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':pid' => $productId,
        ':level_name' => $levelName,
        ':loc' => $locationId,
        ':lvl' => $levelNumber
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $subdivisions = [];
    foreach ($rows as $row) {
        $capacity = (int)($row['items_capacity'] ?: $row['product_capacity'] ?: 0);
        $current = (int)$row['total_qty'];
        $available = $capacity ? max(0, $capacity - $current) : 0;
        $compatible = true;
        if ($row['dedicated_product_id'] && $productId && $row['dedicated_product_id'] != $productId) {
            $compatible = false;
        }
        $recommended = $compatible && ($row['dedicated_product_id'] == $productId || (int)$row['prod_qty'] > 0);
        $status = 'Empty';
        if ($capacity && $current >= $capacity) $status = 'Full';
        elseif ($current > 0) $status = 'Partially Full';

        $subdivisions[] = [
            'subdivision_number' => (int)$row['subdivision_number'],
            'capacity' => $capacity ?: null,
            'current_stock' => $current,
            'available_capacity' => $capacity ? $available : null,
            'occupancy_percentage' => $capacity ? round(($current/$capacity)*100,1) : null,
            'product_name' => $row['product_name'],
            'dedicated_product_id' => $row['dedicated_product_id'] ? (int)$row['dedicated_product_id'] : null,
            'compatible' => $compatible,
            'recommended' => $recommended,
            'status' => $status
        ];
    }

    echo json_encode(['subdivisions' => $subdivisions]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
