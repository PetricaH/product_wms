<?php
/**
 * Receiving History - View Past Receiving Sessions
 * File: receiving_history.php
 * 
 * Displays history of receiving sessions with search and filter capabilities
 */

// Security and bootstrap
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';

// Session and authentication check
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Database connection
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include models
require_once BASE_PATH . '/models/ReceivingSession.php';
require_once BASE_PATH . '/models/Seller.php';
require_once BASE_PATH . '/models/User.php';

$receivingSessionModel = new ReceivingSession($db);
$sellerModel = new Seller($db);
$userModel = new Users($db);

// Get current user info
$currentUser = $userModel->findById($_SESSION['user_id']);
$userName = $currentUser['username'] ?? 'Worker';

// Get filter parameters
$filters = [
    'status' => $_GET['status'] ?? '',
    'supplier_id' => intval($_GET['supplier_id'] ?? 0),
    'received_by' => $_GET['received_by'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'search' => trim($_GET['search'] ?? ''),
    'limit' => 50
];

// Remove empty filters
$filters = array_filter($filters, function($value) {
    return $value !== '' && $value !== 0;
});

// Get receiving sessions
$sessions = $receivingSessionModel->getAllSessions($filters);

// Get suppliers for filter dropdown
$suppliers = $sellerModel->getAllSellers();

// Get dashboard statistics
$stats = $receivingSessionModel->getDashboardStats($_SESSION['user_id']);

// Define current page for footer
$currentPage = 'receiving_history';
?>
<!DOCTYPE html>
<html lang="ro" data-theme="dark">
<head>
    <?php require_once BASE_PATH . '/includes/header.php'; ?>
    <title>Istoric Recepții - WMS</title>
    <link rel="stylesheet" href="styles/warehouse-css/warehouse_receiving.css">
    <style>
        .history-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .filters-section {
            background: linear-gradient(145deg, var(--darker-gray) 0%, var(--dark-gray) 100%);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: linear-gradient(145deg, var(--darker-gray) 0%, var(--dark-gray) 100%);
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--white);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: var(--light-gray);
            font-size: 0.9rem;
        }
        
        .sessions-table {
            background: linear-gradient(145deg, var(--darker-gray) 0%, var(--dark-gray) 100%);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .table-header {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sessions-grid {
            display: grid;
            gap: 1px;
            background: rgba(255, 255, 255, 0.05);
        }
        
        .session-row {
            display: grid;
            grid-template-columns: 150px 1fr 150px 120px 100px 80px auto;
            gap: 1rem;
            padding: 1rem;
            background: var(--dark-gray);
            align-items: center;
            transition: background-color 0.3s ease;
        }
        
        .session-row:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .session-header {
            display: grid;
            grid-template-columns: 150px 1fr 150px 120px 100px 80px auto;
            gap: 1rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.1);
            font-weight: 600;
            color: var(--white);
        }
        
        .session-cell {
            color: var(--white);
            font-size: 0.9rem;
        }
        
        .session-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .action-btn-small {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-view {
            background: var(--info);
            color: white;
        }
        
        .btn-view:hover {
            background: #138496;
        }
        
        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 0.25rem;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--success);
            transition: width 0.3s ease;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--light-gray);
        }
        
        @media (max-width: 1200px) {
            .session-header, .session-row {
                grid-template-columns: 1fr;
                gap: 0.5rem;
                text-align: left;
            }
            
            .session-row {
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }
        }
    </style>
</head>
<body>
    <div class="app">
        <?php require_once BASE_PATH . '/includes/warehouse_navbar.php'; ?>
        
        <div class="main-content">
            <div class="history-container">
                <div class="page-header">
                    <h1 class="page-title">
                        <span class="material-symbols-outlined">history</span>
                        Istoric Recepții
                    </h1>
                    <div class="header-actions">
                        <a href="warehouse_receiving.php" class="btn btn-primary">
                            <span class="material-symbols-outlined">add</span>
                            Recepție Nouă
                        </a>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?= $stats['total_sessions'] ?? 0 ?></div>
                        <div class="stat-label">Total Recepții (30 zile)</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $stats['completed_sessions'] ?? 0 ?></div>
                        <div class="stat-label">Recepții Finalizate</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $stats['active_sessions'] ?? 0 ?></div>
                        <div class="stat-label">Recepții Active</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($stats['total_items_received'] ?? 0) ?></div>
                        <div class="stat-label">Produse Primite</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= round($stats['avg_completion_rate'] ?? 0, 1) ?>%</div>
                        <div class="stat-label">Rata Finalizare</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-section">
                    <h3 style="color: var(--white); margin-bottom: 1rem;">Filtrează Recepțiile</h3>
                    <form method="GET" action="">
                        <div class="filters-grid">
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">Toate</option>
                                    <option value="in_progress" <?= ($_GET['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>
                                        În Progres
                                    </option>
                                    <option value="completed" <?= ($_GET['status'] ?? '') === 'completed' ? 'selected' : '' ?>>
                                        Finalizat
                                    </option>
                                    <option value="cancelled" <?= ($_GET['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>
                                        Anulat
                                    </option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Furnizor</label>
                                <select name="supplier_id" class="form-select">
                                    <option value="">Toți furnizorii</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?= $supplier['id'] ?>" 
                                                <?= (intval($_GET['supplier_id'] ?? 0) === intval($supplier['id'])) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($supplier['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Data De La</label>
                                <input type="date" name="date_from" class="form-input" 
                                       value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Data Până La</label>
                                <input type="date" name="date_to" class="form-input" 
                                       value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Căutare</label>
                                <input type="text" name="search" class="form-input" 
                                       placeholder="Număr sesiune, document..."
                                       value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <span class="material-symbols-outlined">search</span>
                                Filtrează
                            </button>
                            <a href="receiving_history.php" class="btn btn-secondary">
                                <span class="material-symbols-outlined">clear</span>
                                Resetează
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Sessions Table -->
                <div class="sessions-table">
                    <div class="table-header">
                        <h3 style="margin: 0; color: var(--white);">
                            Recepții (<?= count($sessions) ?> rezultate)
                        </h3>
                    </div>
                    
                    <?php if (empty($sessions)): ?>
                        <div class="empty-state">
                            <span class="material-symbols-outlined" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;">
                                inbox
                            </span>
                            <p>Nu s-au găsit recepții cu criteriile selectate</p>
                        </div>
                    <?php else: ?>
                        <div class="sessions-grid">
                            <div class="session-header">
                                <div>Număr Sesiune</div>
                                <div>Document / Furnizor</div>
                                <div>Comandă</div>
                                <div>Data</div>
                                <div>Progres</div>
                                <div>Status</div>
                                <div>Acțiuni</div>
                            </div>
                            
                            <?php foreach ($sessions as $session): ?>
                                <div class="session-row">
                                    <div class="session-cell">
                                        <strong><?= htmlspecialchars($session['session_number']) ?></strong>
                                    </div>
                                    
                                    <div class="session-cell">
                                        <div style="font-weight: 500;">
                                            <?= htmlspecialchars($session['supplier_document_number']) ?>
                                        </div>
                                        <div style="color: var(--light-gray); font-size: 0.8rem;">
                                            <?= htmlspecialchars($session['supplier_name'] ?? 'N/A') ?>
                                        </div>
                                    </div>
                                    
                                    <div class="session-cell">
                                        <?= htmlspecialchars($session['po_number'] ?? 'N/A') ?>
                                    </div>
                                    
                                    <div class="session-cell">
                                        <?= date('d.m.Y', strtotime($session['created_at'])) ?>
                                        <div style="color: var(--light-gray); font-size: 0.8rem;">
                                            <?= date('H:i', strtotime($session['created_at'])) ?>
                                        </div>
                                    </div>
                                    
                                    <div class="session-cell">
                                        <?php 
                                        $progress = 0;
                                        if ($session['total_items_expected'] > 0) {
                                            $progress = ($session['items_received_count'] / $session['total_items_expected']) * 100;
                                        }
                                        ?>
                                        <div><?= $session['items_received_count'] ?> / <?= $session['total_items_expected'] ?></div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?= $progress ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="session-cell">
                                        <span class="session-status status-<?= $session['status'] ?>">
                                            <?php
                                            $statusLabels = [
                                                'in_progress' => 'În Progres',
                                                'completed' => 'Finalizat',
                                                'cancelled' => 'Anulat'
                                            ];
                                            echo $statusLabels[$session['status']] ?? $session['status'];
                                            ?>
                                        </span>
                                    </div>
                                    
                                    <div class="session-cell">
                                        <div class="session-actions">
                                            <button type="button" class="action-btn-small btn-view" 
                                                    onclick="viewSession(<?= $session['id'] ?>)">
                                                <span class="material-symbols-outlined">visibility</span>
                                                Vezi
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function viewSession(sessionId) {
            // Open session details modal or navigate to details page
            window.open(`receiving_session_details.php?id=${sessionId}`, '_blank');
        }
    </script>

    <?php require_once BASE_PATH . '/includes/warehouse_footer.php'; ?>
</body>
</html>