<?php
/**
 * Warehouse Relocation Management
 * Mobile-friendly interface for relocation tasks
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['warehouse', 'admin', 'manager', 'warehouse_worker'])) {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

$dbFactory = $config['connection_factory'];
$db = $dbFactory();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'Sesiune expirată. Vă rugăm să reîncărcați pagina.';
        $messageType = 'error';
    } else {
        try {
            if ($action === 'complete_relocation') {
                $taskId = intval($_POST['task_id'] ?? 0);

                if ($taskId <= 0) {
                    throw new Exception('ID task invalid.');
                }

                $stmt = $db->prepare("SELECT * FROM relocation_tasks WHERE id = :task_id AND status IN ('pending', 'ready')");
                $stmt->execute([':task_id' => $taskId]);
                $task = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$task) {
                    throw new Exception('Task-ul nu a fost găsit sau este deja completat.');
                }

                $db->beginTransaction();
                try {
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

                    $stmt = $db->prepare("UPDATE relocation_tasks SET status = 'completed', updated_at = NOW() WHERE id = :task_id");
                    $stmt->execute([':task_id' => $taskId]);

                    $db->commit();
                    $message = 'Relocarea a fost finalizată cu succes.';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
            } else {
                throw new Exception('Acțiune nerecunoscută.');
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

try {
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
    $pendingCount = 0;
    $readyCount = 0;
    foreach ($pendingTasks as $task) {
        if (($task['status'] ?? '') === 'pending') {
            $pendingCount++;
        } elseif (($task['status'] ?? '') === 'ready') {
            $readyCount++;
        }
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM relocation_tasks WHERE status = 'completed' AND DATE(updated_at) = CURDATE()");
    $stmt->execute();
    $completedToday = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Error fetching relocation data: " . $e->getMessage());
    $pendingTasks = [];
    $pendingCount = 0;
    $readyCount = 0;
    $completedToday = 0;
}

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

    <div class="main-container">
        <div class="stats-section">
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="material-symbols-outlined stat-icon">pending_actions</span>
                    <div class="stat-number"><?= $pendingCount ?></div>
                    <div class="stat-label">În așteptare</div>
                </div>
                <div class="stat-card">
                    <span class="material-symbols-outlined stat-icon">inventory_2</span>
                    <div class="stat-number"><?= $readyCount ?></div>
                    <div class="stat-label">Gata</div>
                </div>
                <div class="stat-card">
                    <span class="material-symbols-outlined stat-icon">task_alt</span>
                    <div class="stat-number"><?= $completedToday ?></div>
                    <div class="stat-label">Finalizate azi</div>
                </div>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>" role="alert">
                <span class="material-symbols-outlined">
                    <?= $messageType === 'success' ? 'check_circle' : 'error' ?>
                </span>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="content-section">
            <div class="section-header">
                <h2>Task-uri În Așteptare</h2>
            </div>

            <?php if (empty($pendingTasks)): ?>
                <div class="empty-state">
                    <span class="material-symbols-outlined empty-icon">inbox</span>
                    <p class="empty-text">Nu există task-uri de relocare în așteptare.</p>
                </div>
            <?php else: ?>
                <div class="card">
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
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="action" value="complete_relocation">
                                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Finalizezi această relocare?')">
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
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        });
    </script>
    <?php require_once __DIR__ . '/includes/warehouse_footer.php'; ?>
</body>
</html>

