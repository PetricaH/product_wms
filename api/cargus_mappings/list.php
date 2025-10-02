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

try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Nu se poate conecta la baza de date']);
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
$perPage = max(5, min(100, $perPage));
$search = trim((string)($_GET['search'] ?? ''));
$onlyMissing = filter_var($_GET['only_missing'] ?? false, FILTER_VALIDATE_BOOLEAN) || ($_GET['only_missing'] ?? '') === '1';

$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = '(
        county_name LIKE :search
        OR locality_name LIKE :search
        OR cargus_county_name LIKE :search
        OR cargus_locality_name LIKE :search
    )';
    $params[':search'] = '%' . $search . '%';
}

if ($onlyMissing) {
    $whereClauses[] = '(cargus_locality_id IS NULL OR cargus_locality_id = 0)';
}

$whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

try {
    $countSql = "SELECT COUNT(*) FROM address_location_mappings {$whereSql}";
    $countStmt = $db->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;

    $listSql = "
        SELECT
            id,
            county_name,
            locality_name,
            cargus_county_id,
            cargus_locality_id,
            cargus_county_name,
            cargus_locality_name,
            cargus_postal_code,
            mapping_confidence,
            is_verified,
            usage_count,
            last_used_at,
            updated_at,
            created_at
        FROM address_location_mappings
        {$whereSql}
        ORDER BY
            (cargus_locality_id IS NULL OR cargus_locality_id = 0) DESC,
            updated_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $listStmt = $db->prepare($listSql);
    foreach ($params as $key => $value) {
        $listStmt->bindValue($key, $value);
    }
    $listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();

    $mappings = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $statsStmt = $db->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN cargus_locality_id IS NULL OR cargus_locality_id = 0 THEN 1 ELSE 0 END) AS missing,
        SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) AS verified,
        SUM(CASE WHEN last_used_at IS NOT NULL AND last_used_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent_usage
    FROM address_location_mappings");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'missing' => 0, 'verified' => 0, 'recent_usage' => 0];

    echo json_encode([
        'success' => true,
        'data' => $mappings,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int)ceil($total / $perPage)
        ],
        'stats' => [
            'total' => (int)($stats['total'] ?? 0),
            'missing' => (int)($stats['missing'] ?? 0),
            'verified' => (int)($stats['verified'] ?? 0),
            'recent_usage' => (int)($stats['recent_usage'] ?? 0)
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Eroare la preluarea mapărilor',
        'details' => $e->getMessage()
    ]);
}
