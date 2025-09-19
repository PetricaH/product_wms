<?php
/**
 * Interfață relocare depozit optimizată pentru dispozitive mobile cu scanner
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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['warehouse', 'admin', 'manager', 'warehouse_worker'], true)) {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

$dbFactory = $config['connection_factory'];
$db = $dbFactory();

/**
 * Returnează lista de sarcini deschise (pending sau ready)
 */
function fetchOpenRelocationTasks(PDO $db): array
{
    $stmt = $db->prepare(
        "SELECT rt.*, 
                p.name AS product_name, p.sku AS product_sku,
                l1.location_code AS from_location, l2.location_code AS to_location
         FROM relocation_tasks rt
         LEFT JOIN products p ON rt.product_id = p.product_id
         LEFT JOIN locations l1 ON rt.from_location_id = l1.id
         LEFT JOIN locations l2 ON rt.to_location_id = l2.id
         WHERE rt.status IN ('pending', 'ready')
         ORDER BY rt.created_at ASC"
    );
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Formatează datele pentru răspuns JSON către interfața mobilă
 */
function formatTaskPayload(array $tasks, int $index): array
{
    $task = $tasks[$index];

    return [
        'task' => [
            'id' => (int)($task['id'] ?? 0),
            'product_name' => $task['product_name'] ?? '',
            'product_sku' => $task['product_sku'] ?? '',
            'quantity' => (int)($task['quantity'] ?? 0),
            'from_location' => $task['from_location'] ?? '',
            'to_location' => $task['to_location'] ?? '',
            'status' => $task['status'] ?? 'pending',
            'created_at' => $task['created_at'] ?? null,
        ],
        'position' => $index + 1,
        'total' => count($tasks),
    ];
}

/**
 * Găsește indexul unei sarcini în lista ordonată
 */
function resolveTaskIndex(array $tasks, ?int $taskId): int
{
    if ($taskId !== null) {
        foreach ($tasks as $index => $task) {
            if ((int)($task['id'] ?? 0) === $taskId) {
                return $index;
            }
        }
    }

    return 0;
}

$isAjax = isset($_GET['ajax']) || isset($_POST['ajax']);

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $method === 'GET' ? ($_GET['action'] ?? '') : ($_POST['action'] ?? '');

        if ($method === 'GET') {
            if ($action === 'fetch_task') {
                $tasks = fetchOpenRelocationTasks($db);

                if (empty($tasks)) {
                    echo json_encode([
                        'success' => true,
                        'data' => null,
                        'message' => 'Nu există sarcini disponibile.',
                    ]);
                    exit;
                }

                $taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : null;
                $direction = $_GET['direction'] ?? 'current';

                $index = resolveTaskIndex($tasks, $taskId);

                if ($direction === 'next') {
                    $index = min($index + 1, count($tasks) - 1);
                } elseif ($direction === 'previous') {
                    $index = max($index - 1, 0);
                }

                echo json_encode([
                    'success' => true,
                    'data' => formatTaskPayload($tasks, $index),
                ]);
                exit;
            }

            throw new Exception('Acțiune GET invalidă.');
        }

        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Sesiune expirată. Reîncărcați pagina.');
        }

        if ($action === 'update_status') {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $newStatus = $_POST['status'] ?? '';

            if ($taskId <= 0) {
                throw new Exception('ID sarcină invalid.');
            }

            if (!in_array($newStatus, ['pending', 'ready', 'completed'], true)) {
                throw new Exception('Status invalid.');
            }

            $stmt = $db->prepare('UPDATE relocation_tasks SET status = :status, updated_at = NOW() WHERE id = :task_id');
            $stmt->execute([
                ':status' => $newStatus,
                ':task_id' => $taskId,
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Statusul a fost actualizat.',
            ]);
            exit;
        }

        if ($action === 'complete_with_move') {
            $taskId = (int)($_POST['task_id'] ?? 0);

            if ($taskId <= 0) {
                throw new Exception('ID sarcină invalid.');
            }

            $db->beginTransaction();

            try {
                $stmt = $db->prepare('SELECT * FROM relocation_tasks WHERE id = :task_id FOR UPDATE');
                $stmt->execute([':task_id' => $taskId]);
                $task = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$task) {
                    throw new Exception('Sarcina nu a fost găsită.');
                }

                if (!in_array($task['status'], ['pending', 'ready'], true)) {
                    throw new Exception('Sarcina nu este disponibilă pentru actualizare.');
                }

                $stmt = $db->prepare(
                    'UPDATE inventory SET location_id = :to_location
                     WHERE product_id = :product_id
                       AND location_id = :from_location
                       AND quantity >= :quantity
                     LIMIT 1'
                );
                $moved = $stmt->execute([
                    ':to_location' => $task['to_location_id'],
                    ':product_id' => $task['product_id'],
                    ':from_location' => $task['from_location_id'],
                    ':quantity' => $task['quantity'],
                ]);

                if (!$moved || $stmt->rowCount() === 0) {
                    throw new Exception('Nu s-a putut muta stocul. Verificați disponibilitatea.');
                }

                $stmt = $db->prepare("UPDATE relocation_tasks SET status = 'completed', updated_at = NOW() WHERE id = :task_id");
                $stmt->execute([':task_id' => $taskId]);

                $db->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Relocarea a fost înregistrată.',
                ]);
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }

        throw new Exception('Acțiune POST invalidă.');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }

    exit;
}

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
                throw new Exception('ID sarcină invalid.');
            }

                $stmt = $db->prepare("SELECT * FROM relocation_tasks WHERE id = :task_id AND status IN ('pending', 'ready')");
                $stmt->execute([':task_id' => $taskId]);
                $task = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$task) {
                    throw new Exception('Sarcina nu a fost găsită sau este deja finalizată.');
                }

                $db->beginTransaction();
                try {
                    $stmt = $db->prepare(
                        "UPDATE inventory
                         SET location_id = :to_location
                         WHERE product_id = :product_id
                           AND location_id = :from_location
                           AND quantity >= :quantity
                         LIMIT 1"
                    );
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
    $pendingTasks = fetchOpenRelocationTasks($db);
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
    error_log('Error fetching relocation data: ' . $e->getMessage());
    $pendingTasks = [];
    $pendingCount = 0;
    $readyCount = 0;
    $completedToday = 0;
}

$initialTaskPayload = !empty($pendingTasks) ? formatTaskPayload($pendingTasks, 0) : null;

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$viewParam = $_GET['view'] ?? null;
$forceDesktop = $viewParam === 'desktop';
$forceMobile = $viewParam === 'mobile';
$isLikelyMobileAgent = (bool)preg_match('/(Android|iPhone|iPad|Mobile|Windows CE|Pocket|Handheld|Opera Mini)/i', $userAgent);
$isMobileView = $forceDesktop ? false : ($forceMobile ? true : $isLikelyMobileAgent);

$desktopCssPath = __DIR__ . '/styles/warehouse-css/warehouse_relocation.css';
$mobileCssPath = __DIR__ . '/styles/warehouse-css/warehouse_relocation_mobile.css';
$desktopCssVersion = file_exists($desktopCssPath) ? filemtime($desktopCssPath) : time();
$mobileCssVersion = file_exists($mobileCssPath) ? filemtime($mobileCssPath) : time();

$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/warehouse_header.php'; ?>
    <title>Relocări Depozit - WMS</title>
    <link rel="stylesheet" href="styles/warehouse-css/warehouse_relocation.css?v=<?= htmlspecialchars($desktopCssVersion) ?>">
    <?php if ($isMobileView): ?>
        <link rel="stylesheet" href="styles/warehouse-css/warehouse_relocation_mobile.css?v=<?= htmlspecialchars($mobileCssVersion) ?>">
    <?php endif; ?>
</head>
<body>
    <?php require_once __DIR__ . '/includes/warehouse_navbar.php'; ?>

    <div class="main-container<?= $isMobileView ? ' mobile-mode' : '' ?>">
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

        <?php if ($isMobileView): ?>
            <div class="view-switcher-inline">
                <a class="btn btn-sm btn-secondary" href="?view=desktop">Versiune tabelară</a>
            </div>
            <div id="mobileRelocationApp" class="mobile-relocation-app" data-total="<?= count($pendingTasks) ?>">
                <div class="mobile-task-card" data-step="select">
                    <header class="mobile-task-header">
                        <div class="task-progress" id="mobileTaskProgress">Sarcina 0 din <?= count($pendingTasks) ?></div>
                        <div class="task-back">
                            <span class="function-chip">F5</span>
                            <span class="function-text">Înapoi</span>
                        </div>
                    </header>

                    <section class="task-summary" id="taskSummary">
                        <div class="task-type">📦 Relocare</div>
                        <h1 class="task-product" id="taskProduct">Selectați o sarcină</h1>
                        <p class="task-sku" id="taskSku"></p>
                        <p class="task-qty" id="taskQty"></p>
                        <div class="task-locations">
                            <div class="location from" id="taskFrom">---</div>
                            <span class="location-arrow">→</span>
                            <div class="location to" id="taskTo">---</div>
                        </div>
                    </section>

                    <section class="task-step" id="taskStep">
                        <header class="step-header">
                            <span class="step-title" id="stepTitle">Pasul 0 - Selectare sarcină</span>
                            <span class="step-status" id="stepStatus">Folosiți F1 pentru a începe</span>
                        </header>
                        <div class="step-body">
                            <div class="expected-label">Se așteaptă:</div>
                            <div class="expected-value" id="expectedValue">--</div>
                            <div class="scanner-state" id="scannerState">Scanner inactiv</div>
                            <div class="manual-entry" id="manualEntry" hidden>
                                <label for="manualInput" id="manualLabel">Introduceți codul:</label>

                                <input type="text" id="manualInput" inputmode="text" autocomplete="off">
                                <div class="manual-buttons">
                                    <button type="button" id="manualConfirm">Confirmă</button>
                                    <button type="button" id="manualCancel">Renunță</button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <footer class="function-bar">
                        <div class="function-key" data-key="F1">
                            <span class="key-label">F1</span>
                            <span class="key-action" id="f1Action">Pornește</span>
                        </div>
                        <div class="function-key" data-key="F2">
                            <span class="key-label">F2</span>
                            <span class="key-action" id="f2Action">Următor</span>
                        </div>
                        <div class="function-key" data-key="F3">
                            <span class="key-label">F3</span>
                            <span class="key-action" id="f3Action">Scanare</span>
                        </div>
                        <div class="function-key" data-key="F4">
                            <span class="key-label">F4</span>
                            <span class="key-action" id="f4Action">Manual</span>
                        </div>
                        <div class="function-key" data-key="F5">
                            <span class="key-label">F5</span>
                            <span class="key-action" id="f5Action">Înapoi</span>
                        </div>
                    </footer>
                </div>
            </div>
        <?php else: ?>
            <div class="content-section">
                <div class="section-header">
                    <div class="section-actions">
                        <h2>Sarcini în așteptare</h2>
                        <div class="view-switcher">
                            <a class="btn btn-sm btn-primary" href="?view=mobile">Interfață mobilă</a>
                        </div>
                    </div>
                </div>

                <?php if (empty($pendingTasks)): ?>
                    <div class="empty-state">
                        <span class="material-symbols-outlined empty-icon">inbox</span>
                        <p class="empty-text">Nu există sarcini de relocare în așteptare.</p>
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
        <?php endif; ?>
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

    <script>
        window.WAREHOUSE_RELOCATION_CONFIG = {
            csrfToken: '<?= $_SESSION['csrf_token'] ?>',
            fetchUrl: '<?= getNavUrl('warehouse_relocation.php') ?>',
            initialTask: <?= json_encode($initialTaskPayload ?? null, JSON_UNESCAPED_UNICODE) ?>,
            totals: {
                pending: <?= (int)$pendingCount ?>,
                ready: <?= (int)$readyCount ?>,
            },
            isMobile: <?= $isMobileView ? 'true' : 'false' ?>,
        };
    </script>
    <script src="scripts/warehouse-js/warehouse_relocation.js?v=<?= filemtime(__DIR__ . '/scripts/warehouse-js/warehouse_relocation.js') ?>" defer></script>
    <?php require_once __DIR__ . '/includes/warehouse_footer.php'; ?>
</body>
</html>
