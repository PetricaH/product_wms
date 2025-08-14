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

// ===== ACTIVITY INFORMATION (NEW SECTION) =====
$activity = [
    'last_movement' => null,
    'last_movement_type' => null,
    'last_movement_quantity' => 0,
    'recent_changes' => 0,
    'recent_quantity_moved' => 0,
    'activity_score' => 0,
    'predicted_refill' => null,
    'peak_activity_hour' => null,
    'transaction_breakdown' => []
];

try {
    // Check if the new transaction system is available
    $hasTransactionTable = false;
    $checkTableQuery = "SHOW TABLES LIKE 'inventory_transactions'";
    $result = $db->query($checkTableQuery);
    $hasTransactionTable = $result && $result->rowCount() > 0;
    
    if ($hasTransactionTable) {
        // Use the enhanced transaction service for comprehensive data
        if (file_exists(BASE_PATH . '/services/InventoryTransactionService.php')) {
            require_once BASE_PATH . '/services/InventoryTransactionService.php';
            $transactionService = new InventoryTransactionService($db);
            $enhancedActivity = $transactionService->getLocationActivity($locationId);
            $activity = array_merge($activity, $enhancedActivity);
            
            // Get additional insights
            $stmt = $db->prepare("
                SELECT 
                    HOUR(created_at) as hour,
                    COUNT(*) as transaction_count
                FROM inventory_transactions
                WHERE (location_id = :id OR source_location_id = :id)
                AND created_at >= (NOW() - INTERVAL 30 DAY)
                GROUP BY HOUR(created_at)
                ORDER BY transaction_count DESC
                LIMIT 1
            ");
            $stmt->bindValue(':id', $locationId, PDO::PARAM_INT);
            $stmt->execute();
            $peakHour = $stmt->fetch(PDO::FETCH_ASSOC);
            $activity['peak_activity_hour'] = $peakHour ? $peakHour['hour'] . ':00' : null;
            
            // Predict refill based on consumption patterns
            $stmt = $db->prepare("
                SELECT 
                    AVG(ABS(quantity_change)) as avg_daily_movement
                FROM inventory_transactions
                WHERE location_id = :id 
                AND transaction_type IN ('pick', 'move')
                AND created_at >= (NOW() - INTERVAL 14 DAY)
                AND quantity_change < 0
            ");
            $stmt->bindValue(':id', $locationId, PDO::PARAM_INT);
            $stmt->execute();
            $avgMovement = $stmt->fetchColumn();
            
            if ($avgMovement > 0 && $currentStock > 0) {
                $daysUntilEmpty = ceil($currentStock / $avgMovement);
                if ($daysUntilEmpty <= 30) {
                    $activity['predicted_refill'] = date('Y-m-d', strtotime("+{$daysUntilEmpty} days"));
                }
            }
        }
    } else {
        // Fallback to inventory table for basic activity tracking
        $stmt = $db->prepare("
            SELECT 
                MAX(updated_at) as last_activity,
                COUNT(*) as record_count
            FROM inventory 
            WHERE location_id = :id
        ");
        $stmt->bindValue(':id', $locationId, PDO::PARAM_INT);
        $stmt->execute();
        $inventoryActivity = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $activity['last_movement'] = $inventoryActivity['last_activity'] ?? null;
        $activity['last_movement_type'] = 'inventory_update';
        
        // Count recent updates in inventory (last 24 hours)
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM inventory 
            WHERE location_id = :id 
            AND (updated_at >= (NOW() - INTERVAL 1 DAY) OR received_at >= (NOW() - INTERVAL 1 DAY))
        ");
        $stmt->bindValue(':id', $locationId, PDO::PARAM_INT);
        $stmt->execute();
        $activity['recent_changes'] = (int)$stmt->fetchColumn();
        
        // Basic activity score based on inventory records
        $activity['activity_score'] = min(100, $activity['recent_changes'] * 15);
        
        // Get most recent inventory change with product info
        $stmt = $db->prepare("
            SELECT 
                i.updated_at,
                i.quantity,
                p.name as product_name
            FROM inventory i
            LEFT JOIN products p ON i.product_id = p.product_id
            WHERE i.location_id = :id
            ORDER BY i.updated_at DESC
            LIMIT 1
        ");
        $stmt->bindValue(':id', $locationId, PDO::PARAM_INT);
        $stmt->execute();
        $lastInventoryChange = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($lastInventoryChange) {
            $activity['last_movement_product'] = $lastInventoryChange['product_name'];
            $activity['last_movement_quantity'] = (int)$lastInventoryChange['quantity'];
        }
    }
    
} catch (Exception $e) {
    error_log("Error fetching location activity: " . $e->getMessage());
}

// ===== ENHANCED ALERTS WITH ACTIVITY-BASED INSIGHTS =====
$alerts = [];

// Capacity alerts
if ($utilPercent >= 95) {
    $alerts[] = ['type' => 'critical', 'message' => 'Capacitate critică atinsă'];
} elseif ($utilPercent >= 90) {
    $alerts[] = ['type' => 'warning', 'message' => 'Capacitate ridicată'];
}

// Activity-based alerts
if ($activity['activity_score'] > 80) {
    $alerts[] = ['type' => 'info', 'message' => 'Locație foarte activă'];
} elseif ($activity['activity_score'] < 10 && $utilPercent > 20) {
    $alerts[] = ['type' => 'warning', 'message' => 'Activitate redusă - verificați stocul'];
}

// Predicted refill alert
if (!empty($activity['predicted_refill'])) {
    $refillDate = new DateTime($activity['predicted_refill']);
    $daysDiff = (new DateTime())->diff($refillDate)->days;
    if ($daysDiff <= 7) {
        $alerts[] = ['type' => 'warning', 'message' => "Necesită realimentare în ~{$daysDiff} zile"];
    }
}

// Product-specific alerts
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

// ===== ENHANCED RESPONSE WITH ACTIVITY INTELLIGENCE =====
echo json_encode([
    'levels' => $levels,
    'capacity_details' => $capacityDetails,
    'products' => $products,
    'dedicated_product' => $dedicatedProduct,
    'activity' => $activity,
    'alerts' => $alerts,
    'environmental' => $environmental,
    'intelligence' => [
        'has_transaction_tracking' => $hasTransactionTable ?? false,
        'activity_level' => $activity['activity_score'] > 60 ? 'high' : 
                           ($activity['activity_score'] > 30 ? 'medium' : 'low'),
        'recommendation' => generateLocationRecommendation($activity, $utilPercent, $alerts)
    ]
]);

/**
 * Generate intelligent recommendations based on location data
 */
function generateLocationRecommendation($activity, $utilPercent, $alerts): string {
    if ($utilPercent >= 95) {
        return "Urgent: Eliberați spațiu sau extindeți capacitatea";
    }
    
    if ($activity['activity_score'] > 80 && $utilPercent < 50) {
        return "Locație optimă pentru stocuri cu rotație mare";
    }
    
    if ($activity['activity_score'] < 20 && $utilPercent > 60) {
        return "Considerați mutarea stocurilor cu rotație lentă";
    }
    
    if (!empty($activity['predicted_refill'])) {
        return "Planificați realimentarea pentru " . date('d.m.Y', strtotime($activity['predicted_refill']));
    }
    
    if (count($alerts) === 0 && $utilPercent > 20 && $utilPercent < 80) {
        return "Locație în parametri optimi";
    }
    
    return "Monitorizați alertele active";
}
?>