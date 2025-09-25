<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$allowedRoles = ['admin', 'warehouse', 'worker'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles, true)) {
    return;
}

if (defined('INCIDENT_REPORT_WIDGET_RENDERED')) {
    return;
}

define('INCIDENT_REPORT_WIDGET_RENDERED', true);

$config = $config ?? require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'] ?? null;
$locations = [];

if ($dbFactory && is_callable($dbFactory)) {
    try {
        $db = $dbFactory();
        $stmt = $db->prepare("SELECT id, location_code FROM locations ORDER BY location_code ASC LIMIT 300");
        $stmt->execute();
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Incident widget could not load locations: ' . $e->getMessage());
    }
}

$assetBase = rtrim(BASE_URL, '/') . '/';
$cssPath = BASE_PATH . '/styles/incident-report.css';
$cssHref = $assetBase . 'styles/incident-report.css';
$jsPath = BASE_PATH . '/scripts/incident-report-worker.js';
$jsSrc = $assetBase . 'scripts/incident-report-worker.js';

if (file_exists($cssPath)) {
    $cssHref .= '?v=' . filemtime($cssPath);
}

if (file_exists($jsPath)) {
    $jsSrc .= '?v=' . filemtime($jsPath);
}

$createEndpoint = $assetBase . 'api/incidents/create.php';
$csrfToken = getCsrfToken();
?>
<link rel="stylesheet" href="<?= htmlspecialchars($cssHref) ?>">
<div id="incident-report-widget"
     class="incident-widget"
     data-create-endpoint="<?= htmlspecialchars($createEndpoint) ?>"
     data-csrf="<?= htmlspecialchars($csrfToken) ?>"
     data-max-photos="5">
    <button type="button" class="incident-fab" id="incident-fab">
        <span class="material-symbols-outlined">report</span>
        <span class="fab-label">Raportează Incident</span>
    </button>

    <div class="incident-modal" id="incident-modal" aria-hidden="true">
        <div class="incident-modal-overlay" data-close="modal"></div>
        <div class="incident-modal-content" role="dialog" aria-modal="true" aria-labelledby="incident-modal-title">
            <header class="incident-modal-header">
                <h2 id="incident-modal-title">Raportare Incident Operațional</h2>
                <button type="button" class="incident-modal-close" data-close="modal" aria-label="Închide">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </header>

            <form id="incident-form" class="incident-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="incident-form-grid">
                    <div class="incident-field">
                        <label for="incident_type">Tip Incident</label>
                        <select id="incident_type" name="incident_type" required>
                            <option value="">Selectează...</option>
                            <option value="product_loss">Pierdere Produs</option>
                            <option value="equipment_loss">Pierdere Echipament</option>
                            <option value="equipment_damage">Deteriorare Echipament</option>
                            <option value="safety_issue">Problemă Siguranță</option>
                            <option value="quality_issue">Problemă Calitate</option>
                            <option value="process_violation">Încălcare Procedură</option>
                            <option value="other">Altele</option>
                        </select>
                    </div>

                    <div class="incident-field">
                        <label for="incident_title">Titlu Scurt</label>
                        <input type="text" id="incident_title" name="title" maxlength="255" required placeholder="Descriere rapidă">
                    </div>

                    <div class="incident-field incident-field-span">
                        <label for="incident_description">Descriere Detaliată</label>
                        <textarea id="incident_description" name="description" rows="4" required placeholder="Explică pe scurt ce s-a întâmplat, cauze, impact"></textarea>
                    </div>

                    <div class="incident-field">
                        <label for="incident_location">Locația</label>
                        <select id="incident_location" name="location_id">
                            <option value="">Alege locația</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= (int)$location['id'] ?>"><?= htmlspecialchars($location['location_code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="incident-field">
                        <label for="location_description">Descriere locație suplimentară</label>
                        <input type="text" id="location_description" name="location_description" maxlength="255" placeholder="Zona, echipament sau context">
                    </div>

                    <div class="incident-field">
                        <label for="incident_severity">Severitate</label>
                        <select id="incident_severity" name="severity" required>
                            <option value="low">Scăzută</option>
                            <option value="medium" selected>Medie</option>
                            <option value="high">Ridicată</option>
                            <option value="critical">Critică</option>
                        </select>
                    </div>

                    <div class="incident-field">
                        <label for="occurred_at">Când s-a întâmplat?</label>
                        <input type="datetime-local" id="occurred_at" name="occurred_at" required>
                    </div>

                    <div class="incident-field">
                        <label for="estimated_cost">Cost Estimativ (RON)</label>
                        <input type="number" id="estimated_cost" name="estimated_cost" step="0.01" min="0" placeholder="0.00">
                    </div>

                    <div class="incident-field incident-field-span">
                        <label for="incident_photos">Fotografii (max. 5MB/buc)</label>
                        <input type="file" id="incident_photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
                        <small class="incident-help">Poți atașa mai multe poze pentru documentare.</small>
                        <div class="incident-photo-preview" id="incident-photo-preview"></div>
                    </div>
                </div>

                <footer class="incident-modal-footer">
                    <button type="button" class="btn-secondary" data-close="modal">Renunță</button>
                    <button type="submit" class="btn-primary" id="incident-submit">
                        <span class="material-symbols-outlined">send</span>
                        Trimite Raportul
                    </button>
                </footer>
            </form>
        </div>
    </div>

    <div class="incident-toast-container" id="incident-toast"></div>
</div>
<script src="<?= htmlspecialchars($jsSrc) ?>" defer></script>
