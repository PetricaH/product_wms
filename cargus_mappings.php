<?php
/**
 * Admin interface for managing Cargus locality mappings.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ' . getNavUrl('index.php'));
    exit;
}

$pageTitle = 'Mapări Localități Cargus';
$currentPage = 'cargus_mappings';
?>
<!DOCTYPE html>
<html lang="ro" data-theme="dark">
<head>
    <?php require_once BASE_PATH . '/includes/header.php'; ?>
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="app">
    <?php require_once BASE_PATH . '/includes/navbar.php'; ?>

    <main class="main-content">
        <div class="page-container">
            <div class="page-header">
                <div class="page-header-content">
                    <h1 class="page-title">
                        <span class="material-symbols-outlined">pin_drop</span>
                        Mapări Localități Cargus
                    </h1>
                    <p class="page-subtitle">
                        Gestionează corelarea județelor și localităților din baza ta de date cu codurile oficiale Cargus.
                    </p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-secondary" type="button" id="refreshMappings">
                        <span class="material-symbols-outlined">refresh</span>
                        Actualizează lista
                    </button>
                </div>
            </div>

            <section class="mappings-stats" id="mappingsStats">
                <article class="stat-card" aria-live="polite">
                    <div class="stat-card__label">Total mapări</div>
                    <div class="stat-card__value" id="totalMappings">-</div>
                </article>
                <article class="stat-card stat-card--warning" aria-live="polite">
                    <div class="stat-card__label">Mapări de completat</div>
                    <div class="stat-card__value" id="missingMappings">-</div>
                </article>
                <article class="stat-card stat-card--success" aria-live="polite">
                    <div class="stat-card__label">Mapări verificate</div>
                    <div class="stat-card__value" id="verifiedMappings">-</div>
                </article>
                <article class="stat-card" aria-live="polite">
                    <div class="stat-card__label">Utilizări recente</div>
                    <div class="stat-card__value" id="recentUsage">-</div>
                </article>
            </section>

            <section class="mappings-toolbar" aria-label="Filtre mapări">
                <div class="toolbar-group">
                    <label class="search-input">
                        <span class="material-symbols-outlined">search</span>
                        <input type="search"
                               id="searchMappings"
                               placeholder="Caută județ sau localitate"
                               autocomplete="off"
                               aria-label="Caută mapări">
                    </label>
                    <label class="select-input">
                        <span class="material-symbols-outlined">list_alt</span>
                        <select id="perPageSelect" aria-label="Rezultate pe pagină">
                            <option value="10">10 / pagină</option>
                            <option value="20" selected>20 / pagină</option>
                            <option value="50">50 / pagină</option>
                        </select>
                    </label>
                </div>
                <div class="toolbar-actions">
                    <label class="toggle-filter">
                        <input type="checkbox" id="onlyMissingToggle">
                        <span>Doar mapări incomplete</span>
                    </label>
                    <button class="btn btn-tertiary" type="button" id="resetFilters">Resetează filtrele</button>
                </div>
            </section>

            <section class="table-card" aria-live="polite">
                <table class="mappings-table">
                    <thead>
                        <tr>
                            <th>Județ (WMS)</th>
                            <th>Localitate (WMS)</th>
                            <th>Județ Cargus</th>
                            <th>Localitate Cargus</th>
                            <th>Cod Poștal</th>
                            <th>Încredere</th>
                            <th>Acțiuni</th>
                        </tr>
                    </thead>
                    <tbody id="mappingsTableBody">
                        <tr>
                            <td colspan="7" class="table-placeholder">Se încarcă datele...</td>
                        </tr>
                    </tbody>
                </table>
                <div class="table-footer">
                    <div class="results-summary" id="resultsSummary"></div>
                    <nav class="pagination" id="mappingsPagination" aria-label="Paginare mapări"></nav>
                </div>
            </section>

            <div class="feedback-banner" id="feedbackBanner" role="alert" hidden></div>
        </div>
    </main>
</div>

<div class="page-loading" id="pageLoadingOverlay" hidden>
    <div class="page-loading__content">
        <span class="material-symbols-outlined spinning">progress_activity</span>
        <span>Se încarcă...</span>
    </div>
</div>

<div class="mapping-modal" id="mappingModal" aria-hidden="true">
    <div class="mapping-modal__backdrop" data-modal-close></div>
    <div class="mapping-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="mappingModalTitle">
        <header class="mapping-modal__header">
            <div>
                <h2 class="mapping-modal__title" id="mappingModalTitle">Actualizează maparea Cargus</h2>
                <p class="mapping-modal__subtitle" id="mappingModalSubtitle"></p>
            </div>
            <button class="modal-close" type="button" data-modal-close aria-label="Închide">
                <span class="material-symbols-outlined">close</span>
            </button>
        </header>
        <section class="mapping-modal__body">
            <div class="mapping-modal__summary">
                <div>
                    <span class="summary-label">Județ WMS</span>
                    <strong class="summary-value" id="modalCounty"></strong>
                </div>
                <div>
                    <span class="summary-label">Localitate WMS</span>
                    <strong class="summary-value" id="modalLocality"></strong>
                </div>
                <div class="summary-meta">
                    <span id="modalMeta"></span>
                </div>
            </div>

            <div class="mapping-modal__alert" id="modalAlert" hidden></div>

            <div class="mapping-modal__loading" id="modalLoading" hidden>
                <span class="material-symbols-outlined spinning">progress_activity</span>
                <span>Căutăm localități în Cargus...</span>
            </div>

            <div class="mapping-modal__matches" id="mappingMatches" hidden>
                <div class="matches-header">
                    <h3>Rezultate Cargus propuse</h3>
                    <button class="btn btn-link" type="button" id="retrySearch">
                        <span class="material-symbols-outlined">refresh</span>
                        Reîncearcă căutarea
                    </button>
                </div>
                <div class="matches-table" role="group" aria-labelledby="mappingModalTitle">
                    <table>
                        <thead>
                            <tr>
                                <th></th>
                                <th>Județ</th>
                                <th>Localitate</th>
                                <th>Cod Poștal</th>
                                <th>Potrivire</th>
                            </tr>
                        </thead>
                        <tbody id="matchesTableBody"></tbody>
                    </table>
                </div>
            </div>
        </section>
        <footer class="mapping-modal__footer">
            <label class="orders-toggle">
                <input type="checkbox" id="applyToOrders" checked>
                <span>Actualizează și comenzile în curs cu această mapare</span>
            </label>
            <div class="footer-actions">
                <button class="btn btn-secondary" type="button" data-modal-close>Anulează</button>
                <button class="btn btn-primary" type="button" id="confirmMapping" disabled>
                    <span class="material-symbols-outlined">save</span>
                    Salvează maparea
                </button>
            </div>
        </footer>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
