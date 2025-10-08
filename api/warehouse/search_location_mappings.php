<?php
// File: api/warehouse/search_location_mappings.php - Lightweight lookup for recipient address mappings
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$allowedRoles = ['admin', 'warehouse', 'warehouse_worker'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Acces interzis.'
    ]);
    exit;
}

try {
    $config = require BASE_PATH . '/config/config.php';
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        throw new RuntimeException('Configurația bazei de date lipsește.');
    }
    $db = $config['connection_factory']();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Nu se poate stabili conexiunea la baza de date.'
    ]);
    exit;
}

$type = strtolower(trim((string)($_GET['type'] ?? 'locality')));
if (!in_array($type, ['county', 'locality'], true)) {
    $type = 'locality';
}

$query = trim((string)($_GET['query'] ?? $_GET['q'] ?? ''));
if ($query === '') {
    echo json_encode([
        'status' => 'success',
        'data' => []
    ]);
    exit;
}

$limit = (int)($_GET['limit'] ?? 15);
$limit = max(5, min(30, $limit));

$params = [':search' => '%' . $query . '%'];

if ($type === 'county') {
    $sql = "
        SELECT
            cargus_county_id,
            MAX(cargus_county_name) AS cargus_county_name,
            MAX(county_name) AS county_name
        FROM address_location_mappings
        WHERE (county_name LIKE :search OR cargus_county_name LIKE :search)
          AND cargus_county_id IS NOT NULL AND cargus_county_id <> 0
        GROUP BY cargus_county_id
        ORDER BY MAX(cargus_county_name) ASC
        LIMIT :limit
    ";
} else {
    $countyId = $_GET['county_id'] ?? $_GET['cargus_county_id'] ?? null;
    $countyId = filter_var($countyId, FILTER_VALIDATE_INT, ['options' => ['default' => null]]);

    $whereCounty = '';
    if ($countyId !== null && $countyId > 0) {
        $whereCounty = ' AND cargus_county_id = :county_id';
        $params[':county_id'] = $countyId;
    }

    $sql = "
        SELECT
            cargus_locality_id,
            MAX(cargus_locality_name) AS cargus_locality_name,
            MAX(locality_name) AS locality_name,
            MAX(cargus_county_id) AS cargus_county_id,
            MAX(cargus_county_name) AS cargus_county_name,
            MAX(county_name) AS county_name
        FROM address_location_mappings
        WHERE (locality_name LIKE :search OR cargus_locality_name LIKE :search)
          AND cargus_locality_id IS NOT NULL AND cargus_locality_id <> 0
          {$whereCounty}
        GROUP BY cargus_locality_id
        ORDER BY MAX(cargus_locality_name) ASC
        LIMIT :limit
    ";
}

try {
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
        } elseif (is_int($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $results = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($type === 'county') {
            $results[] = [
                'cargus_county_id' => (int)$row['cargus_county_id'],
                'cargus_county_name' => $row['cargus_county_name'],
                'county_name' => $row['county_name']
            ];
        } else {
            $results[] = [
                'cargus_locality_id' => (int)$row['cargus_locality_id'],
                'cargus_locality_name' => $row['cargus_locality_name'],
                'locality_name' => $row['locality_name'],
                'cargus_county_id' => $row['cargus_county_id'] !== null ? (int)$row['cargus_county_id'] : null,
                'cargus_county_name' => $row['cargus_county_name'],
                'county_name' => $row['county_name']
            ];
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => $results
    ]);
} catch (Throwable $e) {
    error_log('search_location_mappings error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Căutarea nu a reușit.'
    ]);
}
