<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// // Session check
// if (session_status() === PHP_SESSION_NONE) {
//     session_start();
// }

// // Authentication check
// if (!isset($_SESSION['user_id'])) {
//     header('Location: login.php');
//     exit;
// }

// Load configuration
require_once __DIR__ . '/bootstrap.php';
$config = require __DIR__ . '/config/config.php';
// define('BASE_PATH', __DIR__);

// Include helpers
require_once __DIR__ . '/includes/helpers.php';

// Get database connection using the factory from config
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
     die("Database connection factory not configured correctly in config.php");
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include Models
require_once __DIR__ . '/models/Product.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Location.php';
require_once __DIR__ . '/models/Inventory.php';
require_once __DIR__ . '/models/Order.php';
require_once __DIR__ . '/models/ReceivingSession.php';

$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');

// Instantiate Models
$product = new Product($db);
$users = new Users($db);
$location = new Location($db);
$inventory = new Inventory($db);
$orders = new Order($db);
$receivingSession = new ReceivingSession($db);

// Get Dashboard Data
try {
    $totalProducts = $product->countAll();
    $totalUsers = $users->countAllUsers();
    $totalLocations = $location->countTotalLocations();
    $occupiedLocations = $location->countOccupiedLocations();
    $totalItemsInStock = $inventory->getTotalItemCount();
    $lowStockProducts = $inventory->getLowStockCount();
    $activeOrders = $orders->countActiveOrders();
    $pendingOrders = $orders->countPendingOrders();
    $completedOrdersToday = $orders->countCompletedToday();
    $pendingQualityItems = $receivingSession->countPendingQualityItems();
    
    // Calculate warehouse occupation percentage
    $warehouseOccupationPercent = $totalLocations > 0 ? round(($occupiedLocations / $totalLocations) * 100, 1) : 0;
    
    // Recent activity
    $recentOrders = $orders->getRecentOrders(5);
    $criticalStockAlerts = $inventory->getCriticalStockAlerts(5);

    // Performance metrics
    $todayStats = [
        'orders_created' => $orders->countOrdersCreatedToday(),
        'orders_completed' => $completedOrdersToday,
        'items_moved' => $inventory->getItemsMovedToday(),
    ];

    // Duration statistics - Updated to use timing tables
    $pickingByOperator = [];
    $pickingByCategory = [];
    $receivingByOperator = [];
    $receivingByCategory = [];
    
    try {
        // Get picking statistics by operator from timing tables
        $pickingByOperatorStmt = $db->prepare("
            SELECT 
                u.username as operator,
                AVG(pt.duration_seconds) / 60 as avg_minutes,
                COUNT(*) as total_tasks
            FROM picking_tasks pt
            JOIN users u ON pt.operator_id = u.id
            WHERE pt.status = 'completed'
            AND pt.end_time IS NOT NULL
            AND pt.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY u.username
            ORDER BY avg_minutes ASC
            LIMIT 10
        ");
        $pickingByOperatorStmt->execute();
        $pickingByOperator = $pickingByOperatorStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get picking statistics by category from timing tables
        $pickingByCategoryStmt = $db->prepare("
            SELECT 
                p.category,
                AVG(pt.duration_seconds) / 60 as avg_minutes,
                COUNT(*) as total_tasks
            FROM picking_tasks pt
            JOIN products p ON pt.product_id = p.product_id
            WHERE pt.status = 'completed'
            AND pt.end_time IS NOT NULL
            AND pt.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY p.category
            ORDER BY avg_minutes ASC
            LIMIT 10
        ");
        $pickingByCategoryStmt->execute();
        $pickingByCategory = $pickingByCategoryStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get receiving statistics by operator from timing tables
        $receivingByOperatorStmt = $db->prepare("
            SELECT 
                u.username as operator,
                AVG(rt.duration_seconds) / 60 as avg_minutes,
                COUNT(*) as total_tasks
            FROM receiving_tasks rt
            JOIN users u ON rt.operator_id = u.id
            WHERE rt.status = 'completed'
            AND rt.end_time IS NOT NULL
            AND rt.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY u.username
            ORDER BY avg_minutes ASC
            LIMIT 10
        ");
        $receivingByOperatorStmt->execute();
        $receivingByOperator = $receivingByOperatorStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get receiving statistics by category from timing tables
        $receivingByCategoryStmt = $db->prepare("
            SELECT 
                p.category,
                AVG(rt.duration_seconds) / 60 as avg_minutes,
                COUNT(*) as total_tasks
            FROM receiving_tasks rt
            JOIN products p ON rt.product_id = p.product_id
            WHERE rt.status = 'completed'
            AND rt.end_time IS NOT NULL
            AND rt.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY p.category
            ORDER BY avg_minutes ASC
            LIMIT 10
        ");
        $receivingByCategoryStmt->execute();
        $receivingByCategory = $receivingByCategoryStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get today's task counts for the performance section
        $todayTasksStmt = $db->prepare("
            SELECT 
                (SELECT COUNT(*) FROM picking_tasks WHERE DATE(start_time) = CURDATE() AND status = 'completed') as picking_tasks_today,
                (SELECT COUNT(*) FROM receiving_tasks WHERE DATE(start_time) = CURDATE() AND status = 'completed') as receiving_tasks_today,
                (SELECT COUNT(*) FROM picking_tasks WHERE status = 'active') as active_picking_tasks,
                (SELECT COUNT(*) FROM receiving_tasks WHERE status = 'active') as active_receiving_tasks
        ");
        $todayTasksStmt->execute();
        $todayTaskCounts = $todayTasksStmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        // If timing tables don't exist yet, fall back to old methods
        error_log("Timing tables not available, using fallback: " . $e->getMessage());
        $pickingByOperator = $orders->getAverageProcessingTimeByOperator();
        $pickingByCategory = $orders->getAverageProcessingTimeByCategory();
        $receivingByOperator = $receivingSession->getAverageDurationByOperator();
        $receivingByCategory = $receivingSession->getAverageDurationByCategory();
        $todayTaskCounts = ['picking_tasks_today' => 0, 'receiving_tasks_today' => 0, 'active_picking_tasks' => 0, 'active_receiving_tasks' => 0];
    }
    
} catch (Exception $e) {
    error_log("Dashboard data error: " . $e->getMessage());
    // Set default values on error
    $totalProducts = $totalUsers = $totalLocations = $occupiedLocations = 0;
    $totalItemsInStock = $lowStockProducts = $activeOrders = $pendingOrders = 0;
    $warehouseOccupationPercent = 0;
    $recentOrders = $criticalStockAlerts = [];
    $todayStats = ['orders_created' => 0, 'orders_completed' => 0, 'items_moved' => 0];
    $pendingQualityItems = 0;
    $pickingByOperator = $pickingByCategory = [];
    $receivingByOperator = $receivingByCategory = [];
    $todayTaskCounts = ['picking_tasks_today' => 0, 'receiving_tasks_today' => 0, 'active_picking_tasks' => 0, 'active_receiving_tasks' => 0];
}
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>WMS Dashboard</title>
</head>
<body class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <div class="main-content">
        <div class="dashboard-container">
            <div class="page-container">
                <!-- Dashboard Header -->
                <div class="dashboard-header">
                    <div class="header-content">
                        <div class="header-info">
                            <h1 class="dashboard-title">
                                <span class="material-symbols-outlined">dashboard</span>
                                Dashboard WMS
                            </h1>
                            <p class="dashboard-subtitle">Sumar activitate depozit - <?= date('d.m.Y H:i') ?></p>
                        </div>
                        <div class="header-actions">
                            <div class="status-indicator">
                                <span class="status-dot status-active"></span>
                                <span class="status-text">Sistem Operațional</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Key Metrics Grid -->
                <div class="metrics-grid">
                    <!-- Products Overview -->
                    <div class="metric-card primary">
                        <div class="metric-header">
                            <span class="material-symbols-outlined">inventory_2</span>
                            <div class="metric-values">
                                <div class="metric-primary"><?= number_format($totalProducts) ?></div>
                                <div class="metric-label">Produse Totale</div>
                            </div>
                        </div>
                        <div class="metric-details">
                            <div class="detail-item">
                                <span class="detail-label">Stoc critic:</span>
                                <span class="detail-value text-warning"><?= number_format($lowStockProducts) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Warehouse Capacity -->
                    <div class="metric-card secondary">
                        <div class="metric-header">
                            <span class="material-symbols-outlined">warehouse</span>
                            <div class="metric-values">
                                <div class="metric-primary"><?= $warehouseOccupationPercent ?>%</div>
                                <div class="metric-label">Ocupare Depozit</div>
                            </div>
                        </div>
                        <div class="metric-details">
                            <div class="detail-item">
                                <span class="detail-label">Locații ocupate:</span>
                                <span class="detail-value"><?= number_format($occupiedLocations) ?>/<?= number_format($totalLocations) ?></span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= $warehouseOccupationPercent ?>%"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Inventory Overview -->
                    <div class="metric-card info">
                        <div class="metric-header">
                            <span class="material-symbols-outlined">widgets</span>
                            <div class="metric-values">
                                <div class="metric-primary"><?= number_format($totalItemsInStock) ?></div>
                                <div class="metric-label">Articole în Stoc</div>
                            </div>
                        </div>
                        <div class="metric-details">
                            <div class="detail-item">
                                <span class="detail-label">Mișcări azi:</span>
                                <span class="detail-value"><?= number_format($todayStats['items_moved']) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Orders Overview -->
                    <div class="metric-card success">
                        <div class="metric-header">
                            <span class="material-symbols-outlined">shopping_cart</span>
                            <div class="metric-values">
                                <div class="metric-primary"><?= number_format($activeOrders) ?></div>
                                <div class="metric-label">Comenzi Active</div>
                            </div>
                        </div>
                        <div class="metric-details">
                            <div class="detail-item">
                                <span class="detail-label">Finalizate azi:</span>
                                <span class="detail-value text-success"><?= number_format($todayStats['orders_completed']) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- QC Pending -->
                    <div class="metric-card warning">
                        <div class="metric-header">
                            <span class="material-symbols-outlined">fact_check</span>
                            <div class="metric-values">
                                <div class="metric-primary"><?= number_format($pendingQualityItems) ?></div>
                                <div class="metric-label">QC în Așteptare</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activity Overview -->
                <div class="activity-grid">
                    <!-- Today's Performance -->
                    <div class="activity-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span class="material-symbols-outlined">trending_up</span>
                                Performanță Astăzi
                            </h3>
                        </div>
                        <div class="card-content">
                            <div class="performance-stats">
                                <div class="perf-stat">
                                    <div class="perf-value"><?= number_format($todayStats['orders_created']) ?></div>
                                    <div class="perf-label">Comenzi Noi</div>
                                </div>
                                <div class="perf-stat">
                                    <div class="perf-value"><?= number_format($todayStats['orders_completed']) ?></div>
                                    <div class="perf-label">Finalizate</div>
                                </div>
                                <div class="perf-stat">
                                    <div class="perf-value"><?= number_format($todayStats['items_moved']) ?></div>
                                    <div class="perf-label">Articole Procesate</div>
                                </div>
                            </div>
                            <!-- Added tracking data -->
                            <div class="performance-stats" style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                                <div class="perf-stat">
                                    <div class="perf-value"><?= number_format($todayTaskCounts['picking_tasks_today']) ?></div>
                                    <div class="perf-label">Picking Tasks</div>
                                </div>
                                <div class="perf-stat">
                                    <div class="perf-value"><?= number_format($todayTaskCounts['receiving_tasks_today']) ?></div>
                                    <div class="perf-label">Receiving Tasks</div>
                                </div>
                                <div class="perf-stat">
                                    <div class="perf-value"><?= number_format($todayTaskCounts['active_picking_tasks'] + $todayTaskCounts['active_receiving_tasks']) ?></div>
                                    <div class="perf-label">Active Tasks</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Orders -->
                    <div class="activity-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span class="material-symbols-outlined">receipt_long</span>
                                Comenzi Recente
                            </h3>
                            <a href="<?= getNavUrl('orders.php') ?>" class="card-action">Vezi toate</a>
                        </div>
                        <div class="card-content">
                            <?php if (empty($recentOrders)): ?>
                                <div class="empty-state">
                                    <span class="material-symbols-outlined">inbox</span>
                                    <p>Nu există comenzi recente</p>
                                </div>
                            <?php else: ?>
                                <div class="order-list">
                                    <?php foreach ($recentOrders as $order): ?>
                                        <div class="order-item">
                                            <div class="order-info">
                                                <div class="order-id">#<?= htmlspecialchars($order['id']) ?></div>
                                                <div class="order-customer"><?= htmlspecialchars($order['customer_name'] ?? 'N/A') ?></div>
                                            </div>
                                            <div class="order-status">
                                                <span class="status-badge status-<?= strtolower($order['status']) ?>">
                                                    <?= ucfirst($order['status']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Stock Alerts -->
                    <div class="activity-card alert-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span class="material-symbols-outlined">warning</span>
                                Alerte Stoc
                            </h3>
                            <a href="<?= getNavUrl('inventory.php?filter=low_stock') ?>" class="card-action">Vezi toate</a>
                        </div>
                        <div class="card-content">
                            <?php if (empty($criticalStockAlerts)): ?>
                                <div class="empty-state">
                                    <span class="material-symbols-outlined">check_circle</span>
                                    <p>Nu există alerte de stoc</p>
                                </div>
                            <?php else: ?>
                                <div class="alert-list">
                                    <?php foreach ($criticalStockAlerts as $alert): ?>
                                        <div class="alert-item">
                                            <div class="alert-info">
                                                <div class="alert-product"><?= htmlspecialchars($alert['name']) ?></div>
                                                <div class="alert-sku"><?= htmlspecialchars($alert['sku']) ?></div>
                                            </div>
                                            <div class="alert-stock">
                                                <span class="stock-current"><?= number_format($alert['quantity']) ?></span>
                                                <span class="stock-divider">/</span>
                                                <span class="stock-min"><?= number_format($alert['min_stock_level']) ?></span>
                                            </div>
                                        </div>
                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Duration Statistics -->
                <div class="duration-stats">
                    <h3 class="section-title">
                        <span class="material-symbols-outlined">schedule</span>
                        Timp Mediu Procesare
                    </h3>
                    <div class="duration-grid">
                        <div class="duration-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <span class="material-symbols-outlined">person</span>
                                    Picking / Operator
                                </h3>
                            </div>
                            <div class="card-content">
                                <?php if (empty($pickingByOperator)): ?>
                                    <div class="empty-state">
                                        <span class="material-symbols-outlined">info</span>
                                        <p>Fără date</p>
                                    </div>
                                <?php else: ?>
                                    <div class="duration-list">
                                        <?php foreach ($pickingByOperator as $row): ?>
                                            <div class="duration-item">
                                                <span><?= htmlspecialchars($row['operator']) ?></span>
                                                <span class="duration-value"><?= number_format($row['avg_minutes'], 1) ?> min</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="duration-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <span class="material-symbols-outlined">category</span>
                                    Picking / Categorie
                                </h3>
                            </div>
                            <div class="card-content">
                                <?php if (empty($pickingByCategory)): ?>
                                    <div class="empty-state">
                                        <span class="material-symbols-outlined">info</span>
                                        <p>Fără date</p>
                                    </div>
                                <?php else: ?>
                                    <div class="duration-list">
                                        <?php foreach ($pickingByCategory as $row): ?>
                                            <div class="duration-item">
                                                <span><?= htmlspecialchars($row['category'] ?? 'N/A') ?></span>
                                                <span class="duration-value"><?= number_format($row['avg_minutes'], 1) ?> min</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="duration-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <span class="material-symbols-outlined">person</span>
                                    Recepție / Operator
                                </h3>
                            </div>
                            <div class="card-content">
                                <?php if (empty($receivingByOperator)): ?>
                                    <div class="empty-state">
                                        <span class="material-symbols-outlined">info</span>
                                        <p>Fără date</p>
                                    </div>
                                <?php else: ?>
                                    <div class="duration-list">
                                        <?php foreach ($receivingByOperator as $row): ?>
                                            <div class="duration-item">
                                                <span><?= htmlspecialchars($row['operator']) ?></span>
                                                <span class="duration-value"><?= number_format($row['avg_minutes'], 1) ?> min</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="duration-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <span class="material-symbols-outlined">category</span>
                                    Recepție / Categorie
                                </h3>
                            </div>
                            <div class="card-content">
                                <?php if (empty($receivingByCategory)): ?>
                                    <div class="empty-state">
                                        <span class="material-symbols-outlined">info</span>
                                        <p>Fără date</p>
                                    </div>
                                <?php else: ?>
                                    <div class="duration-list">
                                        <?php foreach ($receivingByCategory as $row): ?>
                                            <div class="duration-item">
                                                <span><?= htmlspecialchars($row['category'] ?? 'N/A') ?></span>
                                                <span class="duration-value"><?= number_format($row['avg_minutes'], 1) ?> min</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h3 class="section-title">Acțiuni Rapide</h3>
                    <div class="actions-grid">
                        <a href="<?= getNavUrl('products.php') ?>" class="action-card">
                            <span class="material-symbols-outlined">add_box</span>
                            <span class="action-text">Adaugă Produs</span>
                        </a>
                        <a href="<?= getNavUrl('inventory.php') ?>" class="action-card">
                            <span class="material-symbols-outlined">inventory</span>
                            <span class="action-text">Verifică Inventar</span>
                        </a>
                        <a href="<?= getNavUrl('orders.php') ?>" class="action-card">
                            <span class="material-symbols-outlined">add_shopping_cart</span>
                            <span class="action-text">Comandă Nouă</span>
                        </a>
                        <a href="<?= getNavUrl('locations.php') ?>" class="action-card">
                            <span class="material-symbols-outlined">place</span>
                            <span class="action-text">Gestionează Locații</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
    <script>
        // Auto-refresh dashboard data every 30 seconds
        setInterval(() => {
            // Update timestamp
            const subtitle = document.querySelector('.dashboard-subtitle');
            if (subtitle) {
                const now = new Date();
                const timeStr = now.toLocaleDateString('ro-RO') + ' ' + 
                               now.toLocaleTimeString('ro-RO', {hour: '2-digit', minute: '2-digit'});
                subtitle.textContent = `Sumar activitate depozit - ${timeStr}`;
            }
        }, 30000);

        // Pass tracking data to JavaScript for updates
        window.TRACKING_DATA = {
            todayTaskCounts: <?= json_encode($todayTaskCounts) ?>
        };
    </script>
</body>
</html>