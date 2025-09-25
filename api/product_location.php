<?php
header('Content-Type: application/json');
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$sku = isset($_GET['sku']) ? trim((string)$_GET['sku']) : '';

if ($productId <= 0 && $sku !== '') {
    $stmt = $db->prepare("SELECT product_id FROM products WHERE sku = :sku LIMIT 1");
    $stmt->execute([':sku' => $sku]);
    $foundId = $stmt->fetchColumn();
    if ($foundId) {
        $productId = (int)$foundId;
    }
}

if ($productId <= 0) {
    echo json_encode([]);
    exit;
}

try {
    // First try to find a dedicated subdivision
    $stmt = $db->prepare(
        "SELECT ls.location_id, ls.level_number, ls.subdivision_number, l.location_code
         FROM location_subdivisions ls
         JOIN locations l ON ls.location_id = l.id
         WHERE ls.dedicated_product_id = ?
         LIMIT 1"
    );
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Fall back to level assignments that allow this product
        $stmt = $db->prepare(
            "SELECT
                lls.location_id,
                lls.level_number,
                NULL as subdivision_number,
                l.location_code
             FROM location_level_settings lls
             JOIN locations l ON lls.location_id = l.id
             WHERE lls.dedicated_product_id = :product_id
                OR (
                    lls.allowed_product_types IS NOT NULL
                    AND (
                        JSON_CONTAINS(lls.allowed_product_types, :product_json_numeric, '$')
                        OR JSON_CONTAINS(lls.allowed_product_types, :product_json_string, '$')
                    )
                )
             ORDER BY lls.dedicated_product_id IS NULL, lls.priority_order ASC, lls.level_number ASC
             LIMIT 1"
        );

        $stmt->execute([
            ':product_id' => $productId,
            ':product_json_numeric' => json_encode((int)$productId),
            ':product_json_string' => json_encode((string)$productId)
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($row) {
        echo json_encode([
            'location_id' => (int)$row['location_id'],
            'location_code' => $row['location_code'],
            'level_number' => isset($row['level_number']) ? (int)$row['level_number'] : null,
            'subdivision_number' => isset($row['subdivision_number']) ? (int)$row['subdivision_number'] : null
        ]);
    } else {
        echo json_encode([]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
