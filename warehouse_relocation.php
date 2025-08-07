<?php
/**
 * Warehouse Relocation Management
 * File: warehouse_relocation.php
 * Follows WMS design language and standards
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentication check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['warehouse', 'admin', 'manager'])) {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

// Database connection
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include required models
require_once BASE_PATH . '/models/Location.php';
require_once BASE_PATH . '/models/Product.php';
require_once BASE_PATH . '/models/Inventory.php';

// Include relocation model if exists
if (file_exists(BASE_PATH . '/models/RelocationTask.php')) {
    require_once BASE_PATH . '/models/RelocationTask.php';
    $relocationModel = new RelocationTask($db);
}

$locationModel = new Location($db);
$productModel = new Product($db);
$inventoryModel = new Inventory($db);

// Handle form submissions and operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'Sesiune expirată. Vă rugăm să reîncărcați pagina.';
        $messageType = 'error';
    } else {
        try {
            switch ($action) {
                case 'create_relocation':
                    $productId = intval($_POST['product_id'] ?? 0);
                    $fromLocationId = intval($_POST['from_location_id'] ?? 0);
                    $toLocationId = intval($_POST['to_location_id'] ?? 0);
                    $quantity = intval($_POST['quantity'] ?? 0);
                    
                    if ($productId <= 0 || $fromLocationId <= 0 || $toLocationId <= 0 || $quantity <= 0) {
                        throw new Exception('Date invalide pentru crearea relocării.');
                    }
                    
                    if ($fromLocationId === $toLocationId) {
                        throw new Exception('Locațiile de origine și destinație nu pot fi identice.');
                    }
                    
                    // Verify sufficient stock at source location - FIXED: Use direct query
                    $stmt = $db->prepare("
                        SELECT COALESCE(SUM(quantity), 0) as available_stock 
                        FROM inventory 
                        WHERE product_id = :product_id AND location_id = :location_id AND quantity > 0
                    ");
                    $stmt->execute([
                        ':product_id' => $productId,
                        ':location_id' => $fromLocationId
                    ]);
                    $availableStock = (int)$stmt->fetchColumn();
                    
                    if ($availableStock < $quantity) {
                        throw new Exception('Stoc insuficient în locația de origine. Disponibil: ' . $availableStock);
                    }
                    
                    // Create relocation task
                    if (isset($relocationModel) && $relocationModel->createTask($productId, $fromLocationId, $toLocationId, $quantity)) {
                        $message = 'Task de relocare creat cu succes.';
                        $messageType = 'success';
                    } else {
                        throw new Exception('Eroare la crearea task-ului de relocare.');
                    }
                    break;
                    
                case 'complete_relocation':
                    $taskId = intval($_POST['task_id'] ?? 0);
                    
                    if ($taskId <= 0) {
                        throw new Exception('ID task invalid.');
                    }
                    
                    // Get task details
                    $stmt = $db->prepare("SELECT * FROM relocation_tasks WHERE id = :task_id AND status IN ('pending', 'ready')");
                    $stmt->execute([':task_id' => $taskId]);
                    $task = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$task) {
                        throw new Exception('Task-ul nu a fost găsit sau este deja completat.');
                    }
                    
                    // Start transaction for relocation
                    $db->beginTransaction();
                    
                    try {
                        // Move inventory from source to destination location
                        // This is a simplified version - you may need more complex logic based on your inventory structure
                        $stmt = $db->prepare("
                            UPDATE inventory 
                            SET location_id = :to_location 
                            WHERE product_id = :product_id 
                            AND location_id = :from_location 
                            AND quantity >= :quantity
                            LIMIT 1
                        ");
                        
                        $moveResult = $stmt->execute([
                            ':to_location' => $task['to_location_id'],
                            ':product_id' => $task['product_id'],
                            ':from_location' => $task['from_location_id'],
                            ':quantity' => $task['quantity']
                        ]);
                        
                        if (!$moveResult || $stmt->rowCount() === 0) {
                            throw new Exception('Nu s-a putut muta stocul. Verificați disponibilitatea.');
                        }
                        
                        // Update task status to completed
                        $stmt = $db->prepare("UPDATE relocation_tasks SET status = 'completed', updated_at = NOW() WHERE id = :task_id");
                        $stmt->execute([':task_id' => $taskId]);
                        
                        $db->commit();
                        $message = 'Relocarea a fost finalizată cu succes.';
                        $messageType = 'success';
                        
                    } catch (Exception $e) {
                        $db->rollBack();
                        throw $e;
                    }
                    break;
                    
                default:
                    throw new Exception('Acțiune nerecunoscută.');
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get data for display
try {
    // Get active locations - FIXED: Use correct method name
    $activeLocations = $locationModel->getActiveLocations();
    
    // Get products for dropdown
    $allProducts = $productModel->getAllProductsForDropdown();
    
    // Get pending relocation tasks - FIXED: Use direct query since method doesn't exist
    $pendingTasks = [];
    if (isset($relocationModel)) {
        $stmt = $db->prepare("
            SELECT rt.*, 
                   p.name as product_name, p.sku as product_sku,
                   l1.location_code as from_location, l2.location_code as to_location
            FROM relocation_tasks rt
            LEFT JOIN products p ON rt.product_id = p.product_id  
            LEFT JOIN locations l1 ON rt.from_location_id = l1.id
            LEFT JOIN locations l2 ON rt.to_location_id = l2.id
            WHERE rt.status IN ('pending', 'ready')
            ORDER BY rt.created_at ASC
        ");
        $stmt->execute();
        $pendingTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get relocation statistics - FIXED: Use direct queries
    $pendingTasksCount = count($pendingTasks);
    $completedTodayCount = 0;
    $totalThisMonthCount = 0;
    
    if (isset($relocationModel)) {
        // Count completed today
        $stmt = $db->prepare("SELECT COUNT(*) FROM relocation_tasks WHERE status = 'completed' AND DATE(updated_at) = CURDATE()");
        $stmt->execute();
        $completedTodayCount = (int)$stmt->fetchColumn();
        
        // Count total this month
        $stmt = $db->prepare("SELECT COUNT(*) FROM relocation_tasks WHERE status = 'completed' AND YEAR(updated_at) = YEAR(NOW()) AND MONTH(updated_at) = MONTH(NOW())");
        $stmt->execute();
        $totalThisMonthCount = (int)$stmt->fetchColumn();
    }
    
    $relocationStats = [
        'pending_tasks' => $pendingTasksCount,
        'completed_today' => $completedTodayCount,
        'total_this_month' => $totalThisMonthCount
    ];
    
} catch (Exception $e) {
    error_log("Error fetching relocation data: " . $e->getMessage());
    $activeLocations = [];
    $allProducts = [];
    $pendingTasks = [];
    $relocationStats = ['pending_tasks' => 0, 'completed_today' => 0, 'total_this_month' => 0];
}

// Define current page for navigation
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/warehouse_header.php'; ?>
    <title>Relocări Depozit - WMS</title>
</head>
<body>
    <?php require_once __DIR__ . '/includes/warehouse_navbar.php'; ?>
    
    <!-- Main Container (following WMS design language) -->
    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <span class="material-symbols-outlined">move_location</span>
                Relocări Depozit
            </h1>
            <p class="page-subtitle">Gestionează relocările produselor între locații</p>
        </div>

        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>" role="alert">
                <span class="material-symbols-outlined">
                    <?= $messageType === 'success' ? 'check_circle' : 'error' ?>
                </span>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Stats Section (following warehouse design pattern) -->
        <div class="stats-section">
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="material-symbols-outlined stat-icon">pending_actions</span>
                    <div class="stat-number"><?= $relocationStats['pending_tasks'] ?></div>
                    <div class="stat-label">În așteptare</div>
                </div>
                
                <div class="stat-card">
                    <span class="material-symbols-outlined stat-icon">task_alt</span>
                    <div class="stat-number"><?= $relocationStats['completed_today'] ?></div>
                    <div class="stat-label">Finalizate azi</div>
                </div>
                
                <div class="stat-card">
                    <span class="material-symbols-outlined stat-icon">trending_up</span>
                    <div class="stat-number"><?= $relocationStats['total_this_month'] ?></div>
                    <div class="stat-label">Total luna curentă</div>
                </div>
            </div>
        </div>

        <!-- Action Section -->
        <div class="action-section">
            <div class="section-header">
                <h2 class="section-title">
                    <span class="material-symbols-outlined">add_task</span>
                    Creare Task Relocare
                </h2>
            </div>
            
            <div class="card">
                <form method="POST" id="relocation-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="create_relocation">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="product_id" class="form-label">Produs</label>
                            <select name="product_id" id="product_id" class="form-select" required>
                                <option value="">Selectează produsul</option>
                                <?php foreach ($allProducts as $product): ?>
                                    <option value="<?= $product['product_id'] ?>">
                                        <?= htmlspecialchars($product['name']) ?> (<?= htmlspecialchars($product['sku']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="from_location_id" class="form-label">Locația de origine</label>
                            <select name="from_location_id" id="from_location_id" class="form-select" required>
                                <option value="">Selectează locația de origine</option>
                                <?php foreach ($activeLocations as $location): ?>
                                    <option value="<?= $location['id'] ?>">
                                        <?= htmlspecialchars($location['location_code']) ?> - <?= htmlspecialchars($location['zone']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="to_location_id" class="form-label">Locația de destinație</label>
                            <select name="to_location_id" id="to_location_id" class="form-select" required>
                                <option value="">Selectează locația de destinație</option>
                                <?php foreach ($activeLocations as $location): ?>
                                    <option value="<?= $location['id'] ?>">
                                        <?= htmlspecialchars($location['location_code']) ?> - <?= htmlspecialchars($location['zone']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="quantity" class="form-label">Cantitate</label>
                            <input type="number" name="quantity" id="quantity" class="form-input" min="1" required>
                            <small class="form-hint">Cantitatea de relocat</small>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <span class="material-symbols-outlined">add</span>
                            Creează Task Relocare
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Pending Tasks Section -->
        <div class="tasks-section">
            <div class="section-header">
                <h2 class="section-title">
                    <span class="material-symbols-outlined">format_list_bulleted</span>
                    Task-uri În Așteptare
                </h2>
            </div>
            
            <div class="card">
                <?php if (empty($pendingTasks)): ?>
                    <div class="empty-state">
                        <span class="material-symbols-outlined empty-icon">inbox</span>
                        <p class="empty-text">Nu există task-uri de relocare în așteptare.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Produs</th>
                                    <th>Din</th>
                                    <th>În</th>
                                    <th>Cantitate</th>
                                    <th>Status</th>
                                    <th>Creat</th>
                                    <th>Acțiuni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingTasks as $task): ?>
                                    <tr>
                                        <td>
                                            <div class="product-info">
                                                <strong><?= htmlspecialchars($task['product_name'] ?? '') ?></strong>
                                                <small><?= htmlspecialchars($task['product_sku'] ?? '') ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="location-badge">
                                                <?= htmlspecialchars($task['from_location'] ?? '') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="location-badge">
                                                <?= htmlspecialchars($task['to_location'] ?? '') ?>
                                            </span>
                                        </td>
                                        <td><?= intval($task['quantity'] ?? 0) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= htmlspecialchars($task['status'] ?? '') ?>">
                                                <?= ucfirst($task['status'] ?? '') ?>
                                            </span>
                                        </td>
                                        <td><?= date('d.m.Y H:i', strtotime($task['created_at'] ?? '')) ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="action" value="complete_relocation">
                                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success" 
                                                        onclick="return confirm('Finalizezi această relocare?')">
                                                    <span class="material-symbols-outlined">check</span>
                                                    Finalizează
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/warehouse_footer.php'; ?>

    <!-- JavaScript for enhanced functionality -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Form validation and UX enhancements
            const form = document.getElementById('relocation-form');
            const fromLocationSelect = document.getElementById('from_location_id');
            const toLocationSelect = document.getElementById('to_location_id');
            
            // Prevent selecting same location for origin and destination
            function updateLocationOptions() {
                const fromValue = fromLocationSelect.value;
                const toOptions = toLocationSelect.querySelectorAll('option');
                
                toOptions.forEach(option => {
                    if (option.value === fromValue && fromValue !== '') {
                        option.disabled = true;
                    } else {
                        option.disabled = false;
                    }
                });
            }
            
            fromLocationSelect.addEventListener('change', updateLocationOptions);
            toLocationSelect.addEventListener('change', function() {
                const toValue = this.value;
                const fromOptions = fromLocationSelect.querySelectorAll('option');
                
                fromOptions.forEach(option => {
                    if (option.value === toValue && toValue !== '') {
                        option.disabled = true;
                    } else {
                        option.disabled = false;
                    }
                });
            });
            
            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        });
    </script>