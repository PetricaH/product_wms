<?php
// Admin Returns Dashboard
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

// Require admin/manager role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','manager'])) {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

$currentPage = 'returns_dashboard';
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/warehouse_header.php'; ?>
    <!-- DataTables & Chart.js -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php require_once __DIR__ . '/includes/warehouse_navbar.php'; ?>
    <div class="main-container">
        <div class="stats-grid">
            <div class="stat-card">
                <div id="stat-in-progress" class="stat-number">0</div>
                <div class="stat-label">În verificare</div>
            </div>
            <div class="stat-card">
                <div id="stat-pending" class="stat-number">0</div>
                <div class="stat-label">Așteaptă inventariere</div>
            </div>
            <div class="stat-card">
                <div id="stat-completed" class="stat-number">0</div>
                <div class="stat-label">Finalizate</div>
            </div>
            <div class="stat-card">
                <div id="stat-discrepancies" class="stat-number">0</div>
                <div class="stat-label">Discrepanțe</div>
            </div>
        </div>

        <form id="filter-form" class="filters">
            <div>
                <label for="from">De la</label>
                <input type="date" id="from" name="from">
            </div>
            <div>
                <label for="to">Până la</label>
                <input type="date" id="to" name="to">
            </div>
            <div>
                <label for="search">Caută</label>
                <input type="text" id="search" name="search" placeholder="Comandă sau client">
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Toate</option>
                    <option value="in_progress">În verificare</option>
                    <option value="pending">Așteaptă inventariere</option>
                    <option value="completed">Finalizate</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filtrează</button>
            <button type="button" id="export-btn" class="btn btn-secondary">Exportă CSV</button>
        </form>

        <table id="returns-table" class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Comandă</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Procesat de</th>
                    <th>Creat</th>
                    <th>Verificat</th>
                    <th>Discrepanțe</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <div class="chart-card">
            <canvas id="returns-chart" height="120"></canvas>
        </div>
    </div>

    <!-- Detail Modal -->
    <div id="return-modal" class="modal" style="display:none;">
        <div class="modal-content">
            <button class="modal-close" onclick="closeReturnModal()">&times;</button>
            <div id="return-details"></div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/warehouse_footer.php'; ?>
</body>
</html>
