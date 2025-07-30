<?php
header('Content-Type: application/json');
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$locationId = intval($_GET['id'] ?? 0);
if ($locationId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid location id']);
    exit;
}

require_once BASE_PATH . '/models/Location.php';
$locModel = new Location($db);
$details = $locModel->getLocationDetails($locationId, true);

if (!$details || empty($details['level_settings'])) {
    echo json_encode(['levels' => []]);
    exit;
}

$levels = [];
foreach ($details['level_settings'] as $level) {
    $levels[] = [
        'number' => (int)$level['level_number'],
        'name' => $level['level_name'] ?: ('Nivel ' . $level['level_number']),
        'subdivision_count' => (int)($level['subdivision_count'] ?? 0),
        'subdivisions_enabled' => !empty($level['subdivisions_enabled'])
    ];
}

echo json_encode(['levels' => $levels]);
