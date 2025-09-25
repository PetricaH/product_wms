<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'admin') {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'] ?? null;

if (!$dbFactory || !is_callable($dbFactory)) {
    die('Conexiunea la baza de date nu este configurată corect.');
}

$db = $dbFactory();

require_once BASE_PATH . '/models/Incident.php';

$incidentModel = new Incident($db);

$statusMap = [
    'reported' => 'Raportat',
    'under_review' => 'În Revizuire',
    'investigating' => 'În Investigare',
    'resolved' => 'Rezolvat',
    'rejected' => 'Respins',
];

$typeMap = [
    'product_loss' => 'Pierdere Produs',
    'equipment_loss' => 'Pierdere Echipament',
    'equipment_damage' => 'Deteriorare Echipament',
    'safety_issue' => 'Problemă Siguranță',
    'quality_issue' => 'Problemă Calitate',
    'process_violation' => 'Încălcare Procedură',
    'other' => 'Altele',
];

$severityMap = [
    'low' => 'Scăzută',
    'medium' => 'Medie',
    'high' => 'Ridicată',
    'critical' => 'Critică',
];

$filters = [
    'status' => isset($_GET['status']) && array_key_exists($_GET['status'], $statusMap) ? $_GET['status'] : '',
    'incident_type' => isset($_GET['incident_type']) && array_key_exists($_GET['incident_type'], $typeMap) ? $_GET['incident_type'] : '',
    'severity' => isset($_GET['severity']) && array_key_exists($_GET['severity'], $severityMap) ? $_GET['severity'] : '',
    'search' => trim($_GET['search'] ?? ''),
];

$incidents = $incidentModel->getAllIncidents($filters);
$incidentIds = array_column($incidents, 'id');
$photosByIncident = [];

if ($incidentIds) {
    $placeholders = implode(',', array_fill(0, count($incidentIds), '?'));
    $photoStmt = $db->prepare("SELECT * FROM incident_photos WHERE incident_id IN ($placeholders) ORDER BY uploaded_at ASC");
    $photoStmt->execute($incidentIds);
    while ($photo = $photoStmt->fetch(PDO::FETCH_ASSOC)) {
        $photosByIncident[$photo['incident_id']][] = $photo;
    }
}

$incidentsForView = [];
foreach ($incidents as $incident) {
    $incidentId = (int)$incident['id'];
    $incident['status_label'] = $statusMap[$incident['status']] ?? $incident['status'];
    $incident['type_label'] = $typeMap[$incident['incident_type']] ?? $incident['incident_type'];
    $incident['severity_label'] = $severityMap[$incident['severity']] ?? $incident['severity'];
    $incident['photos'] = $photosByIncident[$incidentId] ?? [];
    $incident['reported_at_display'] = date('d.m.Y H:i', strtotime($incident['reported_at']));
    $incident['occurred_at_display'] = date('d.m.Y H:i', strtotime($incident['occurred_at']));
    $incident['estimated_cost_display'] = $incident['estimated_cost'] !== null
        ? number_format((float)$incident['estimated_cost'], 2, ',', ' ')
        : 'N/A';
    $incidentsForView[] = $incident;
}

$unresolvedCounts = $incidentModel->getUnresolvedCounts();
$unresolvedTotal = $incidentModel->getUnresolvedTotal();

$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
$cssPath = BASE_PATH . '/styles/incidents-admin.css';
$cssUrl = rtrim(BASE_URL, '/') . '/styles/incidents-admin.css' . (file_exists($cssPath) ? '?v=' . filemtime($cssPath) : '');
$scriptPath = BASE_PATH . '/scripts/incidents-admin.js';
$scriptUrl = file_exists($scriptPath) ? rtrim(BASE_URL, '/') . '/scripts/incidents-admin.js?v=' . filemtime($scriptPath) : '';


?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($cssUrl) ?>">
    <title>Administrare Incidente</title>
</head>
<body>
    <div class="app">
        <?php require_once __DIR__ . '/includes/navbar.php'; ?>
        <div class="main-content">
            <div class="page-container incidents-admin">
                <header class="page-header">
                    <div class="page-header-content">
                        <h1 class="page-title">
                            <span class="material-symbols-outlined">emergency</span>
                            Administrare Incidente
                        </h1>
                    </div>
                </header>

                <section class="incident-badges">
                    <div class="badge-card total">
                        <span class="badge-label">Incidente nerezolvate</span>
                        <strong class="badge-value"><?= (int)$unresolvedTotal ?></strong>
                    </div>
                    <div class="badge-grid">
                        <?php foreach ($severityMap as $key => $label): ?>
                            <div class="badge-card severity-<?= htmlspecialchars($key) ?>">
                                <span class="badge-label"><?= htmlspecialchars($label) ?></span>
                                <strong class="badge-value"><?= (int)($unresolvedCounts[$key] ?? 0) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="filters-card">
                    <form class="filters-form" method="GET">
                        <div class="filter-group">
                            <label for="filter-status">Status</label>
                            <select name="status" id="filter-status">
                                <option value="">Toate Statusurile</option>
                                <?php foreach ($statusMap as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="filter-type">Tip</label>
                            <select name="incident_type" id="filter-type">
                                <option value="">Toate Tipurile</option>
                                <?php foreach ($typeMap as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value) ?>" <?= $filters['incident_type'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="filter-severity">Severitate</label>
                            <select name="severity" id="filter-severity">
                                <option value="">Toate Severitățile</option>
                                <?php foreach ($severityMap as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value) ?>" <?= $filters['severity'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group search">
                            <label for="filter-search">Căutare</label>
                            <input type="search" id="filter-search" name="search" value="<?= htmlspecialchars($filters['search']) ?>" placeholder="Număr, titlu sau raportant">
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn-primary">
                                <span class="material-symbols-outlined">filter_alt</span>
                                Filtrează
                            </button>
                            <a class="btn-secondary" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
                                <span class="material-symbols-outlined">refresh</span>
                                Resetează
                            </a>
                        </div>
                    </form>
                </section>

                <section class="incidents-table-card">
                    <div class="card-header">
                        <h2>Incidente Raportate</h2>
                        <span class="count-pill"><?= count($incidentsForView) ?> rezultate</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table incidents-table">
                            <thead>
                                <tr>
                                    <th>Număr Incident</th>
                                    <th>Tip</th>
                                    <th>Titlu</th>
                                    <th>Raportant</th>
                                    <th>Severitate</th>
                                    <th>Status</th>
                                    <th>Data Raportării</th>
                                    <th>Acțiuni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($incidentsForView)): ?>
                                    <tr>
                                        <td colspan="8" class="empty-state">Nu există incidente pentru filtrele selectate.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($incidentsForView as $incident):
                                        $incidentJson = htmlspecialchars(json_encode($incident, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
                                    ?>
                                        <tr data-incident-id="<?= (int)$incident['id'] ?>">
                                            <td><span class="mono"><?= htmlspecialchars($incident['incident_number']) ?></span></td>
                                            <td><?= htmlspecialchars($incident['type_label']) ?></td>
                                            <td><?= htmlspecialchars($incident['title']) ?></td>
                                            <td><?= htmlspecialchars($incident['reporter_name']) ?></td>
                                            <td>
                                                <span class="badge severity <?= htmlspecialchars($incident['severity']) ?>">
                                                    <?= htmlspecialchars($incident['severity_label']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge status <?= htmlspecialchars($incident['status']) ?>">
                                                    <?= htmlspecialchars($incident['status_label']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($incident['reported_at_display']) ?></td>
                                            <td>
                                                <div class="action-group">
                                                    <button type="button" class="btn-icon view-incident" data-incident='<?= $incidentJson ?>'>
                                                        <span class="material-symbols-outlined">visibility</span>
                                                        Vezi Detalii
                                                    </button>
                                                    <button type="button" class="btn-icon update-incident" data-incident='<?= $incidentJson ?>'>
                                                        <span class="material-symbols-outlined">playlist_add_check</span>
                                                        Actualizează Status
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <div class="modal incident-modal" id="incidentDetailModal" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <header class="modal-header">
                    <h2 class="modal-title">Detalii Incident</h2>
                    <button type="button" class="modal-close" data-modal-close>
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </header>
                <div class="modal-body" id="incident-detail-body"></div>
                <footer class="modal-footer">
                    <button type="button" class="btn-secondary" data-modal-close>Închide</button>
                </footer>
            </div>
        </div>
    </div>

    <div class="modal incident-modal" id="incidentStatusModal" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <header class="modal-header">
                    <h2 class="modal-title">Actualizează Status Incident</h2>
                    <button type="button" class="modal-close" data-modal-close>
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </header>
                <form class="modal-body" id="incident-status-form">
                    <input type="hidden" name="incident_id" id="status-incident-id">
                    <div class="form-group">
                        <label for="status-select">Status</label>
                        <select name="status" id="status-select" required>
                            <?php foreach ($statusMap as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="status-admin-notes">Note Administrator</label>
                        <textarea id="status-admin-notes" name="admin_notes" rows="3" placeholder="Observații interne"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="status-resolution-notes">Note Rezolvare</label>
                        <textarea id="status-resolution-notes" name="resolution_notes" rows="3" placeholder="Rezumatul rezolvării"></textarea>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" id="status-follow-up" name="follow_up_required" value="1">
                        <label for="status-follow-up">Necesită acțiuni suplimentare</label>
                    </div>
                </form>
                <footer class="modal-footer">
                    <button type="button" class="btn-secondary" data-modal-close>Anulează</button>
                    <button type="button" class="btn-primary" id="status-save-btn">
                        <span class="material-symbols-outlined">save</span>
                        Salvează
                    </button>
                </footer>
            </div>
        </div>
    </div>

    <div id="incident-data" data-update-endpoint="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/api/incidents/update-status.php') ?>" data-csrf="<?= htmlspecialchars(getCsrfToken()) ?>"></div>

    <?php if ($scriptUrl): ?>
        <script src="<?= htmlspecialchars($scriptUrl) ?>" defer></script>
    <?php endif; ?>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
