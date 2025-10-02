<?php
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acces interzis']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Date JSON invalide']);
    exit;
}

$mappingId = (int)($payload['mapping_id'] ?? 0);
$countyId = (int)($payload['county_id'] ?? 0);
$localityId = (int)($payload['locality_id'] ?? 0);
$countyName = trim((string)($payload['county_name'] ?? ''));
$localityName = trim((string)($payload['locality_name'] ?? ''));
$postalCode = trim((string)($payload['postal_code'] ?? ''));
$updateOrders = filter_var($payload['update_orders'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($mappingId <= 0 || $countyId <= 0 || $localityId <= 0 || $countyName === '' || $localityName === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Toate câmpurile sunt obligatorii pentru actualizare']);
    exit;
}

$postalCode = $postalCode !== '' ? $postalCode : null;

try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $fetchStmt = $db->prepare('SELECT * FROM address_location_mappings WHERE id = :id');
    $fetchStmt->execute([':id' => $mappingId]);
    $existing = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Maparea selectată nu a fost găsită']);
        exit;
    }

    $db->beginTransaction();

    $updateStmt = $db->prepare('
        UPDATE address_location_mappings
        SET
            cargus_county_id = :county_id,
            cargus_locality_id = :locality_id,
            cargus_county_name = :county_name,
            cargus_locality_name = :locality_name,
            cargus_postal_code = :postal_code,
            mapping_confidence = :confidence,
            is_verified = 1,
            last_used_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
    ');

    $updateStmt->execute([
        ':county_id' => $countyId,
        ':locality_id' => $localityId,
        ':county_name' => $countyName,
        ':locality_name' => $localityName,
        ':postal_code' => $postalCode,
        ':confidence' => 'manual',
        ':id' => $mappingId
    ]);

    $ordersUpdated = 0;

    if ($updateOrders) {
        $ordersUpdated = updatePendingOrders($db, $existing, [
            'county_id' => $countyId,
            'locality_id' => $localityId,
            'county_name' => $countyName,
            'locality_name' => $localityName,
            'postal_code' => $postalCode
        ]);
    }

    $db->commit();

    $fetchUpdated = $db->prepare('SELECT * FROM address_location_mappings WHERE id = :id');
    $fetchUpdated->execute([':id' => $mappingId]);
    $updated = $fetchUpdated->fetch(PDO::FETCH_ASSOC);

    if (function_exists('logActivity')) {
        logActivity(
            (int)$_SESSION['user_id'],
            'update',
            'cargus_mapping',
            $mappingId,
            'Actualizare manuală mapare Cargus',
            $existing,
            array_merge($updated ?: [], ['orders_updated' => $ordersUpdated])
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Maparea a fost actualizată cu succes',
        'orders_updated' => $ordersUpdated,
        'mapping' => $updated
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'A apărut o eroare la actualizare',
        'details' => $e->getMessage()
    ]);
}

function updatePendingOrders(PDO $db, array $existing, array $newValues): int
{
    $checkStmt = $db->prepare("SHOW COLUMNS FROM orders LIKE :column");

    $conditions = [];
    foreach (['shipping_county', 'recipient_county_name'] as $countyColumn) {
        $checkStmt->execute([':column' => $countyColumn]);
        $countyExists = (bool)$checkStmt->fetch(PDO::FETCH_ASSOC);

        $cityColumn = $countyColumn === 'shipping_county' ? 'shipping_city' : 'recipient_locality_name';
        $checkStmt->execute([':column' => $cityColumn]);
        $cityExists = (bool)$checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($countyExists && $cityExists) {
            $conditions[] = sprintf(
                '(LOWER(%s) = LOWER(:match_county) AND LOWER(%s) = LOWER(:match_locality))',
                $countyColumn,
                $cityColumn
            );
        }
    }

    if (empty($conditions)) {
        return 0;
    }

    $updateFields = [
        'recipient_county_id = :county_id',
        'recipient_locality_id = :locality_id',
        'recipient_county_name = :new_county_name',
        'recipient_locality_name = :new_locality_name'
    ];

    $checkStmt->execute([':column' => 'recipient_postal']);
    if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
        $updateFields[] = 'recipient_postal = :postal_code';
    }

    $checkStmt->execute([':column' => 'updated_at']);
    $hasUpdatedAt = (bool)$checkStmt->fetch(PDO::FETCH_ASSOC);

    $sql = sprintf(
        'UPDATE orders SET %s%s WHERE status IN (\'pending\', \'processing\') AND (%s)',
        implode(', ', $updateFields),
        $hasUpdatedAt ? ', updated_at = NOW()' : '',
        implode(' OR ', $conditions)
    );

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':county_id', $newValues['county_id'], PDO::PARAM_INT);
    $stmt->bindValue(':locality_id', $newValues['locality_id'], PDO::PARAM_INT);
    $stmt->bindValue(':new_county_name', $newValues['county_name']);
    $stmt->bindValue(':new_locality_name', $newValues['locality_name']);
    $stmt->bindValue(':match_county', $existing['county_name'] ?? $newValues['county_name']);
    $stmt->bindValue(':match_locality', $existing['locality_name'] ?? $newValues['locality_name']);

    if (in_array('recipient_postal = :postal_code', $updateFields, true)) {
        if ($newValues['postal_code'] !== null && $newValues['postal_code'] !== '') {
            $stmt->bindValue(':postal_code', $newValues['postal_code']);
        } else {
            $stmt->bindValue(':postal_code', null, PDO::PARAM_NULL);
        }
    }

    $stmt->execute();

    return $stmt->rowCount();
}
