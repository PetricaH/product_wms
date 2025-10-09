<?php
if (!defined('BASE_PATH')) define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','manager'], true)) {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

$currentPage = 'facturi_somatii';
?>
<!DOCTYPE html>
<html lang="ro" data-theme="dark">
<head>
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Facturi &amp; Somații - WMS</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    
</head>
<body>
    <div class="app">
        <?php require_once __DIR__ . '/includes/navbar.php'; ?>
        <div class="main-content">
            <div class="page-container">
                <header class="page-header">
                    <div class="page-header-content">
                        <h1 class="page-title">
                            <span class="material-symbols-outlined">receipt_long</span>
                            Facturi &amp; Somații
                        </h1>
                        <p class="page-subtitle">Procesează rapid facturile noi și gestionează istoricul complet.</p>
                    </div>
                </header>

                <div class="invoice-page">
                    <section class="quick-upload-card" id="invoice-processing">
                        <div class="upload-stack">
                            <div class="upload-area" id="upload-area">
                                <input type="file" id="invoice-file" accept="image/*,application/pdf" hidden>
                                <input type="file" id="invoice-camera" accept="image/*" capture="environment" hidden>
                                <div class="upload-illustration">
                                    <span class="material-symbols-outlined">cloud_upload</span>
                                </div>
                                <p class="upload-title">Încarcă sau fotografiază factura</p>
                                <p class="upload-description">Trage fișierul aici sau folosește opțiunile rapide de mai jos.</p>
                                <div class="upload-actions">
                                    <button type="button" class="btn btn-primary" id="select-file-btn">
                                        <span class="material-symbols-outlined">upload_file</span>
                                        Încarcă fișier
                                    </button>
                                    <button type="button" class="btn btn-secondary camera-only" id="camera-btn">
                                        <span class="material-symbols-outlined">photo_camera</span>
                                        Deschide cameră
                                    </button>
                                </div>
                            </div>

                        </div>

                        <div class="preview-processing">
                            <div class="preview-container" id="preview-container" hidden>
                                <h3>Previzualizare</h3>
                                <img id="preview-image" alt="Previzualizare factură">
                                <div class="preview-actions">
                                    <button type="button" class="btn btn-secondary" id="reset-upload-btn">
                                        <span class="material-symbols-outlined">refresh</span>
                                        Procesează altă factură
                                    </button>
                                    <button type="button" class="btn btn-primary" id="process-btn">
                                        <span class="material-symbols-outlined">rocket_launch</span>
                                        Procesează factura
                                    </button>
                                </div>
                            </div>

                            <div class="processing-indicator" id="processing-indicator" hidden>
                                <div class="spinner"></div>
                                <p>Factura este procesată, te rugăm să aștepți...</p>
                            </div>
                        </div>
                    </section>

                    <section class="results-section">
                        <div class="results-card" id="results-display" data-n8n-webhook-url="<?= htmlspecialchars(getenv('N8N_WEBHOOK_URL') ?: '') ?>">
                            <div class="placeholder">
                                <span class="material-symbols-outlined">assignment_add</span>
                                <p>Rezultatele procesării vor apărea aici.</p>
                            </div>
                        </div>
                    </section>

                    <section class="stats-grid">
                        <div class="stat-card">
                            <p class="stat-label">Total facturi</p>
                            <p class="stat-number" id="stat-total">0</p>
                        </div>
                        <div class="stat-card">
                            <p class="stat-label">Facturi neplătite</p>
                            <p class="stat-number warning" id="stat-neplatite">0</p>
                        </div>
                        <div class="stat-card">
                            <p class="stat-label">Facturi plătite</p>
                            <p class="stat-number success" id="stat-platite">0</p>
                        </div>
                        <div class="stat-card">
                            <p class="stat-label">Sumă totală</p>
                            <p class="stat-number highlight" id="stat-suma">0,00 RON</p>
                        </div>
                    </section>

                    <section class="filters-area">
                        <details class="filters-card" open>
                            <summary>
                                <span>Filtre facturi</span>
                                <span class="material-symbols-outlined summary-icon">expand_more</span>
                            </summary>
                            <div class="filters-content">
                                <div class="filters-bar">
                                    <div class="filter-group">
                                        <label for="filter-date-from">De la</label>
                                        <input type="date" id="filter-date-from">
                                    </div>
                                    <div class="filter-group">
                                        <label for="filter-date-to">Până la</label>
                                        <input type="date" id="filter-date-to">
                                    </div>
                                    <div class="filter-group">
                                        <label for="filter-status">Status</label>
                                        <select id="filter-status">
                                            <option value="">Toate</option>
                                            <option value="neplatita">Neplătită</option>
                                            <option value="platita">Plătită</option>
                                        </select>
                                    </div>
                                    <div class="filter-group">
                                        <label for="filter-search">Caută</label>
                                        <input type="text" id="filter-search" placeholder="Nr. factură, firmă, CIF">
                                    </div>
                                    <div class="filter-actions">
                                        <button type="button" class="btn btn-primary" id="apply-filters-btn">
                                            <span class="material-symbols-outlined">filter_alt</span>
                                            Aplică filtre
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="reset-filters-btn">
                                            <span class="material-symbols-outlined">restart_alt</span>
                                            Resetează
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </details>
                    </section>

                    <section class="table-card">
                        <table id="facturi_somatii" class="display nowrap" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Nr. Factură</th>
                                    <th>Nume firmă</th>
                                    <th>CIF</th>
                                    <th>Data emitere</th>
                                    <th>Termen plată</th>
                                    <th>Sumă</th>
                                    <th>Status</th>
                                    <th>Acțiuni</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </section>
                </div>
            </div>
        </div>

        <div class="modal" id="invoice-modal" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title">Detalii factură</h3>
                        <button class="modal-close" id="close-modal-btn" type="button">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                    <div class="modal-body" id="modal-body"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const fsWebhookUrl = document.getElementById('results-display')?.dataset?.n8nWebhookUrl || '';
        window.WMS_CONFIG = {
            apiBase: '<?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>/api',
            baseUrl: '<?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>',
            csrfToken: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>',
            n8nWebhookUrl: fsWebhookUrl
        };
    </script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>
