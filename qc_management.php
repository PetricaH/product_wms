<?php
/**
 * Quality Control Management - Supervisor Interface
 * File: qc_management.php
 * 
 * Interfața de supervizor pentru managementul controlului calității
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__); 
}

require_once BASE_PATH . '/bootstrap.php';

// Session and authentication check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'supervisor'])) {
    header('Location: login.php');
    exit;
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = 'Control Calitate';
$currentPage = 'qc_management';

// Include header
require_once __DIR__ . '/includes/header.php';
?>

<div class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-container">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <h1 class="page-title">
                        <span class="material-symbols-outlined">verified</span>
                        Control Calitate
                    </h1>
                    <div class="header-actions">
                        <button class="btn btn-secondary" onclick="QCManager.refreshData()">
                            <span class="material-symbols-outlined">refresh</span>
                            Reîmprospătează
                        </button>
                    </div>
                </div>
            </div>

            <!-- Statistics Overview -->
            <div class="stats-grid" id="stats-grid">
                <!-- Stats will be loaded here -->
            </div>

            <!-- Messages -->
            <div id="alert-container"></div>

            <!-- Tab Navigation -->
            <div class="tab-container">
                <nav class="tab-nav">
                    <button class="tab-btn active" data-tab="pending">
                        <span class="material-symbols-outlined">hourglass_empty</span>
                        Articole în Așteptare
                        <span class="badge" id="pending-count">0</span>
                    </button>
                    <button class="tab-btn" data-tab="approved">
                        <span class="material-symbols-outlined">check_circle</span>
                        Aprobate
                    </button>
                    <button class="tab-btn" data-tab="rejected">
                        <span class="material-symbols-outlined">cancel</span>
                        Respinse
                    </button>
                    <button class="tab-btn" data-tab="history">
                        <span class="material-symbols-outlined">history</span>
                        Istoric Decizii
                    </button>
                </nav>
            </div>

            <!-- Pending Items Tab -->
            <div id="pending-tab" class="tab-content active">
                <!-- Bulk Actions -->
                <div class="bulk-actions" id="bulk-actions" style="display: none;">
                    <div class="bulk-info">
                        <span id="selected-count">0</span> articole selectate
                    </div>
                    <div class="bulk-buttons">
                        <button type="button" class="btn btn-success" onclick="QCManager.showBulkApproveModal()">
                            <span class="material-symbols-outlined">check_circle</span>
                            Aprobă Selectate
                        </button>
                        <button type="button" class="btn btn-danger" onclick="QCManager.showBulkRejectModal()">
                            <span class="material-symbols-outlined">cancel</span>
                            Respinge Selectate
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="QCManager.clearSelection()">
                            Anulează Selecția
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-section">
                    <div class="form-group">
                        <label for="location-filter">Tipul Locației</label>
                        <select id="location-filter" class="form-control" onchange="QCManager.applyFilters()">
                            <option value="">Toate Locațiile</option>
                            <option value="qc_hold">În Așteptare QC</option>
                            <option value="quarantine">Carantină</option>
                            <option value="pending_approval">Pending Aprobare</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="date-from">De la Data</label>
                        <input type="date" id="date-from" class="form-control" onchange="QCManager.applyFilters()">
                    </div>
                    <div class="form-group">
                        <label for="date-to">Până la Data</label>
                        <input type="date" id="date-to" class="form-control" onchange="QCManager.applyFilters()">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" class="btn btn-outline-primary" onclick="QCManager.clearFilters()">
                            <span class="material-symbols-outlined">clear</span>
                            Șterge Filtrele
                        </button>
                    </div>
                </div>

                <!-- Items List -->
                <div id="pending-items-container">
                    <!-- Items will be loaded here -->
                </div>

                <!-- Pagination -->
                <div id="pagination-container" class="pagination-wrapper">
                    <!-- Pagination will be loaded here -->
                </div>
            </div>

            <!-- Approved Items Tab -->
            <div id="approved-tab" class="tab-content">
                <div id="approved-items-container">
                    <!-- Approved items will be loaded here -->
                </div>
            </div>

            <!-- Rejected Items Tab -->
            <div id="rejected-tab" class="tab-content">
                <div id="rejected-items-container">
                    <!-- Rejected items will be loaded here -->
                </div>
            </div>

            <!-- History Tab -->
            <div id="history-tab" class="tab-content">
                <div id="history-container">
                    <!-- Decision history will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Approve Modal -->
    <div id="bulk-approve-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Aprobare în Bloc</h3>
                <button type="button" class="close-btn" onclick="QCManager.closeBulkApproveModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="bulk-approve-form">
                    <div class="form-group">
                        <label for="approve-notes">Note Supervizor</label>
                        <textarea id="approve-notes" class="form-control" rows="3" 
                                placeholder="Note opționale pentru aprobare..."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="move-to-location">Mută în Locația (opțional)</label>
                        <select id="move-to-location" class="form-control">
                            <option value="">Păstrează locația curentă</option>
                            <!-- Options will be loaded dynamically -->
                        </select>
                    </div>
                    <div class="approval-summary">
                        <p>Sunteți sigur că doriți să aprobați <span id="approve-count">0</span> articole?</p>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="QCManager.closeBulkApproveModal()">
                    Anulează
                </button>
                <button type="button" class="btn btn-success" onclick="QCManager.confirmBulkApprove()">
                    <span class="material-symbols-outlined">check_circle</span>
                    Aprobă Articolele
                </button>
            </div>
        </div>
    </div>

    <!-- Bulk Reject Modal -->
    <div id="bulk-reject-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Respingere în Bloc</h3>
                <button type="button" class="close-btn" onclick="QCManager.closeBulkRejectModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="bulk-reject-form">
                    <div class="form-group">
                        <label for="reject-reason">Motivul Respingerii *</label>
                        <select id="reject-reason" class="form-control" required>
                            <option value="">Selectați motivul...</option>
                            <option value="quality_defect">Defect de calitate</option>
                            <option value="wrong_product">Produs greșit</option>
                            <option value="damaged_packaging">Ambalaj deteriorat</option>
                            <option value="expired">Expirat</option>
                            <option value="quantity_mismatch">Nepotrivire cantitate</option>
                            <option value="other">Altul</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="reject-notes">Detalii Suplimentare</label>
                        <textarea id="reject-notes" class="form-control" rows="3" 
                                placeholder="Detalii suplimentare despre respingere..."></textarea>
                    </div>
                    <div class="rejection-summary">
                        <p class="warning">Sunteți sigur că doriți să respingeți <span id="reject-count">0</span> articole?</p>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="QCManager.closeBulkRejectModal()">
                    Anulează
                </button>
                <button type="button" class="btn btn-danger" onclick="QCManager.confirmBulkReject()">
                    <span class="material-symbols-outlined">cancel</span>
                    Respinge Articolele
                </button>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="loading-overlay" style="display: none;">
        <div class="loading-content">
            <span class="material-symbols-outlined spinning">refresh</span>
            <p>Se procesează...</p>
        </div>
    </div>
</div>

<script>
    // Pass CSRF token to JavaScript
    window.CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
    window.USER_ID = <?= $_SESSION['user_id'] ?>;
    window.USER_ROLE = '<?= $_SESSION['role'] ?>';
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>