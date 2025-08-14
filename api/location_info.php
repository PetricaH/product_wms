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
$inventoryIncluded = @include_once BASE_PATH . '/models/Inventory.php';
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
    $levelOccupancy = $locModel->getLevelOccupancyData($locationId, $level['level_number']);
    $pid = !empty($level['dedicated_product_id']) ? (int)$level['dedicated_product_id'] : null;

    $levels[] = [
        'number' => (int)$level['level_number'],
        'name' => $level['level_name'] ?: ('Nivel ' . $level['level_number']),
        'subdivision_count' => (int)($level['subdivision_count'] ?? 0),
        'subdivisions_enabled' => !empty($level['subdivisions_enabled']),
        'capacity' => $levelOccupancy['capacity'] ?: null,
        'current_stock' => (int)$levelOccupancy['items'],
        'occupancy_percentage' => $levelOccupancy['capacity'] ? $levelOccupancy['occupancy_percent'] : null,
        'product_name' => $pid && isset($productMap[$pid]) ? $productMap[$pid] : null,
        'dedicated_product_id' => $pid
    ];
}

// ===== CAPACITY DETAILS =====
$occupancy = $locModel->getLocationOccupancy($locationId);
$totalCapacity = (int)($occupancy['capacity'] ?? 0);
$currentStock = (int)($occupancy['current_items'] ?? 0);
$availableSpace = $totalCapacity > $currentStock ? $totalCapacity - $currentStock : 0;
$utilPercent = $totalCapacity > 0 ? round(($currentStock / $totalCapacity) * 100, 1) : 0;

if ($utilPercent >= 90) {
    $efficiency = $utilPercent >= 95 ? 'Excellent' : 'Good';
} elseif ($utilPercent >= 70) {
    $efficiency = 'Fair';
} else {
    $efficiency = 'Poor';
}

$capacityDetails = [
    'total_capacity' => $totalCapacity,
    'current_stock' => $currentStock,
    'available_space' => $availableSpace,
    'utilization_percentage' => $utilPercent,
    'efficiency_rating' => $efficiency
];

// ===== PRODUCT INFORMATION =====
$products = [];
if ($inventoryIncluded) {
    try {
        $invModel = new Inventory($db);
        $records = $invModel->getLocationInventory($locationId);
        usort($records, function($a, $b) {
            return ($b['quantity'] ?? 0) <=> ($a['quantity'] ?? 0);
        });
        foreach (array_slice($records, 0, 5) as $rec) {
            $products[] = [
                'id' => (int)$rec['product_id'],
                'name' => $rec['product_name'] ?? '',
                'quantity' => (int)$rec['quantity'],
                'last_moved' => $rec['updated_at'] ?? $rec['received_at'] ?? null,
                'min_stock_level' => $rec['min_stock_level'] ?? null
            ];
        }
    } catch (Exception $e) {
        // Ignore inventory errors
    }
}

// Determine dedicated product if present in any level
$dedicatedProduct = null;
foreach ($levels as $lvl) {
    if (!empty($lvl['dedicated_product_id'])) {
        $dedicatedProduct = [
            'id' => $lvl['dedicated_product_id'],
            'name' => $lvl['product_name']
        ];
        break;
    }
}

// ===== ALERTS =====
$alerts = [];
if ($utilPercent >= 95) {
    $alerts[] = ['type' => 'critical', 'message' => 'Aproape de capacitate maximă'];
} elseif ($utilPercent >= 90) {
    $alerts[] = ['type' => 'warning', 'message' => 'Aproape de capacitate maximă'];
}
foreach ($products as $prod) {
    if ($prod['min_stock_level'] !== null && $prod['quantity'] <= $prod['min_stock_level']) {
        $alerts[] = ['type' => 'warning', 'message' => 'Stoc redus pentru ' . $prod['name']];
    }
}

// ===== ENVIRONMENTAL DATA =====
$environmental = null;
try {
    $stmt = $db->prepare("SELECT temperature, humidity FROM location_environment WHERE location_id = :id ORDER BY recorded_at DESC LIMIT 1");
    $stmt->bindValue(':id', $locationId, PDO::PARAM_INT);
    $stmt->execute();
    $environmental = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    // Table might not exist
}

echo json_encode([
    'levels' => $levels,
    'capacity_details' => $capacityDetails,
    'products' => $products,
    'dedicated_product' => $dedicatedProduct,
    'alerts' => $alerts,
    'environmental' => $environmental
]);
?>
