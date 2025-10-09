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
    <style>
        .invoice-page {
            display: flex;
            flex-direction: column;
            gap: 1.75rem;
        }

        .quick-upload-card,
        .results-card,
        .table-card,
        .filters-card,
        .stats-grid .stat-card {
            background: rgba(22, 24, 28, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            box-shadow: 0 18px 45px -35px rgba(0, 0, 0, 0.9);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .quick-upload-card:hover,
        .results-card:not([hidden]):hover,
        .table-card:hover,
        .filters-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 60px -40px rgba(0, 0, 0, 0.9);
        }

        .quick-upload-card {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.75rem;
            padding: 1.75rem;
            align-items: start;
        }

        .upload-stack {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .upload-area {
            background: rgba(13, 15, 20, 0.6);
            border: 2px dashed rgba(96, 134, 255, 0.35);
            border-radius: 14px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .upload-area .upload-illustration {
            font-size: 2rem;
            margin-bottom: 0.75rem;
            color: rgba(96, 134, 255, 0.9);
        }

        .upload-area .upload-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.35rem;
        }

        .upload-area .upload-description {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 1rem;
        }

        .upload-area.drag-over {
            border-color: rgba(130, 173, 255, 0.8);
            background: rgba(27, 32, 44, 0.85);
        }

        .upload-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: center;
        }

        .camera-fullscreen {
            position: fixed;
            inset: 0;
            background: radial-gradient(circle at center, rgba(12, 16, 24, 0.96), rgba(5, 7, 12, 0.98));
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            z-index: 9999;
        }

        .camera-fullscreen video {
            width: 100%;
            height: 100%;
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 20px;
            box-shadow: 0 25px 80px -40px rgba(0, 0, 0, 0.9);
            background: rgba(0, 0, 0, 0.65);
        }

        .camera-fullscreen .camera-overlay-controls {
            position: fixed;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            justify-content: center;
            background: rgba(5, 7, 12, 0.75);
            padding: 0.75rem 1.25rem;
            border-radius: 999px;
            box-shadow: 0 12px 30px -20px rgba(0, 0, 0, 0.9);
        }

        body.camera-open {
            overflow: hidden;
        }

        .preview-processing {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .preview-container {
            background: rgba(13, 15, 20, 0.6);
            border-radius: 14px;
            padding: 1.25rem;
            text-align: center;
            animation: fadeIn 0.2s ease;
        }

        .preview-container img {
            max-width: 150px;
            max-height: 150px;
            object-fit: contain;
            border-radius: 10px;
            margin: 0.75rem auto 1rem;
            display: block;
        }

        .preview-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .processing-indicator {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            background: rgba(13, 15, 20, 0.65);
            border-radius: 14px;
            border: 1px solid rgba(96, 134, 255, 0.2);
        }

        .processing-indicator .spinner {
            width: 32px;
            height: 32px;
            border: 3px solid rgba(96, 134, 255, 0.3);
            border-top-color: rgba(96, 134, 255, 0.9);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .results-section {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .results-card {
            padding: 1.5rem;
        }

        .results-card .placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            min-height: 120px;
            color: rgba(255, 255, 255, 0.6);
        }

        .results-card .placeholder .material-symbols-outlined {
            font-size: 2rem;
            opacity: 0.65;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

        .stat-card {
            padding: 1.25rem;
        }

        .stat-card .stat-label {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 0.35rem;
        }

        .stat-card .stat-number {
            font-size: 1.4rem;
            font-weight: 600;
        }

        .filters-area {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .filters-card {
            padding: 0;
            overflow: hidden;
        }

        .filters-card summary {
            cursor: pointer;
            list-style: none;
            padding: 1rem 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .filters-card summary::-webkit-details-marker {
            display: none;
        }

        .filters-card summary .summary-icon {
            font-size: 1.4rem;
            transition: transform 0.2s ease;
        }

        .filters-card[open] summary .summary-icon {
            transform: rotate(180deg);
        }

        .filters-content {
            padding: 0 1.25rem 1.25rem;
        }

        .filters-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }

        .filter-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .table-card {
            padding: 1.5rem;
        }

        .table-card table {
            width: 100% !important;
        }

        .modal .modal-dialog {
            max-width: 720px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .quick-upload-card {
                padding: 1.25rem;
            }

            .preview-actions {
                flex-direction: column;
            }

            .filters-card summary {
                padding: 0.85rem 1.1rem;
            }

            .filters-content {
                padding: 0 1.1rem 1.1rem;
            }
        }

        @media (max-width: 600px) {
            .camera-fullscreen video {
                border-radius: 12px;
            }

            .camera-fullscreen .camera-overlay-controls {
                width: calc(100% - 2rem);
                border-radius: 20px;
                bottom: 1.25rem;
            }
        }
    </style>
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

                    <div class="camera-fullscreen" id="camera-fullscreen" hidden>
                        <video id="camera-stream" autoplay playsinline></video>
                        <div class="camera-overlay-controls">
                            <button type="button" class="btn btn-primary" id="capture-btn">
                                <span class="material-symbols-outlined">camera</span>
                                Fotografiază
                            </button>
                            <button type="button" class="btn btn-secondary" id="close-camera-btn">
                                <span class="material-symbols-outlined">close</span>
                                Închide cameră
                            </button>
                        </div>
                    </div>

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
