<?php
// File: api/awb/track_awb.php
// Endpoint to track AWB status with caching

ob_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/config/config.php';

function respond($payload, int $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

$allowedRoles = ['admin', 'warehouse', 'warehouse_worker'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    respond(['success' => false, 'error' => 'Access denied'], 403);
}

$awb = trim($_REQUEST['awb'] ?? '');
if (!preg_match('/^\d+$/', $awb)) {
    respond(['success' => false, 'error' => 'Invalid AWB'], 400);
}

$refresh = isset($_REQUEST['refresh']);

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

require_once BASE_PATH . '/models/CargusService.php';
$cargusService = new CargusService($db);

$cacheDir = BASE_PATH . '/storage/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}
$cacheFile = $cacheDir . '/awb_status_' . $awb . '.json';

if (!$refresh && file_exists($cacheFile)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if ($cached && isset($cached['timestamp'], $cached['data']) && (time() - $cached['timestamp'] < 300)) {
        error_log("AWB tracking cache hit for {$awb} by user {$_SESSION['user_id']}");
        respond(['success' => true, 'data' => $cached['data']]);
    }
}

error_log("AWB tracking request for {$awb} by user {$_SESSION['user_id']}");
$result = $cargusService->trackAWB($awb);

if ($result['success']) {
    $data = $result['data'];
    file_put_contents($cacheFile, json_encode(['timestamp' => time(), 'data' => $data]));
    respond(['success' => true, 'data' => $data]);
}

error_log("AWB tracking failed for {$awb}: {$result['error']}" . (isset($result['raw']) ? ' Raw: ' . substr($result['raw'],0,500) : ''));
respond(['success' => false, 'error' => 'Status unavailable', 'data' => ['awb' => $awb]], 502);
