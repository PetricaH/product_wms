<?php
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$apiKey = $_GET['api_key'] ?? '';

// Dual authentication: API key OR session
if (!empty($apiKey)) {
    // Option 1: API key authentication (for external systems)
    $configuredApiKey = $config['api']['key'] ?? '';
    if (empty($configuredApiKey) || $apiKey !== $configuredApiKey) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API key']);
        exit;
    }
} elseif (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    // Option 2: Session authentication (for logged-in users)
    // User is logged in, proceed
} else {
    // No valid authentication found
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

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

// Map dedicated product IDs to names
$productMap = [];
$ids = array_unique(array_filter(array_map(function($l){ 
    return $l['dedicated_product_id'] ?? null; 
}, $details['level_settings'])));

if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT product_id, name FROM products WHERE product_id IN ($placeholders)");
    $stmt->execute(array_values($ids));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productMap[(int)$row['product_id']] = $row['name'];
    }
}

$levels = [];
foreach ($details['level_settings'] as $level) {
    $occ = $level['current_occupancy'] ?? ['items'=>0,'capacity'=>0,'occupancy_percent'=>0];
    $pid = !empty($level['dedicated_product_id']) ? (int)$level['dedicated_product_id'] : null;
    
    $levels[] = [
        'number' => (int)$level['level_number'],
        'name' => $level['level_name'] ?: ('Nivel ' . $level['level_number']),
        'subdivision_count' => (int)($level['subdivision_count'] ?? 0),
        'subdivisions_enabled' => !empty($level['subdivisions_enabled']),
        'capacity' => $occ['capacity'] ?: null,
        'current_stock' => (int)$occ['items'],
        'occupancy_percentage' => $occ['capacity'] ? $occ['occupancy_percent'] : null,
        'product_name' => $pid && isset($productMap[$pid]) ? $productMap[$pid] : null,
        'dedicated_product_id' => $pid
    ];
}

echo json_encode(['levels' => $levels]);
?>