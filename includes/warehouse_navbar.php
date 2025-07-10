<?php
/**
 * Simple Warehouse Header Navigation - Respects existing design language
 */

// Get user info
$userName = $_SESSION['username'] ?? 'Worker';
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');

// Get page title based on current page
$pageTitles = [
    'warehouse_orders' => 'Comenzi Depozit',
    'warehouse_hub' => 'Hub Operații',
    'warehouse_picking' => 'Picking',
    'warehouse_receiving' => 'Recepție',
    'warehouse_inventory' => 'Căutare Stoc'
];

$pageTitle = $pageTitles[$currentPage] ?? 'WMS Warehouse';
?>

<!-- Header (matching existing warehouse_hub.php style) -->
<div class="header">
    <div class="header-content">
        <div class="header-title">
            <?php if ($currentPage !== 'warehouse_hub'): ?>
                <a href="<?= getNavUrl('warehouse_hub.php') ?>" class="back-btn" style="color: var(--white); text-decoration: none; margin-right: 1rem;">
                    <span class="material-symbols-outlined">arrow_back</span>
                </a>
            <?php endif; ?>
            <span class="material-symbols-outlined">warehouse</span>
            <?= htmlspecialchars($pageTitle) ?>
        </div>
        <button class="mobile-menu-toggle" id="mobile-menu-toggle" aria-label="Meniu">
            <span class="material-symbols-outlined">menu</span>
        </button>
        <div class="user-info">
            <div id="current-time"></div>
            <div class="user-badge">
                <span class="material-symbols-outlined">person</span>
                <span id="worker-name"><?= htmlspecialchars($userName) ?></span>
            </div>
        </div>
        <ul class="nav-menu" id="nav-menu">
            <li class="nav-item"><a href="warehouse_orders.php" class="nav-link">Comenzi</a></li>
            <li class="nav-item"><a href="warehouse_inventory.php" class="nav-link">Stoc</a></li>
            <li class="nav-item"><a href="warehouse_hub.php" class="nav-link">Hub</a></li>
        </ul>
    </div>
</div>

<style>
/* Simple header styles matching existing design language */
.header {
    background-color: var(--dark-gray);
    color: var(--white);
    padding: 1rem;
    position: sticky;
    top: 0;
    z-index: 1000;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.header-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
    max-width: 1200px;
    margin: 0 auto;
}

.header-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--white);
}

.back-btn {
    background: none;
    border: none;
    color: var(--white);
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 50%;
    transition: background-color 0.3s ease;
    display: flex;
    align-items: center;
}

.back-btn:hover {
    background-color: rgba(255, 255, 255, 0.1);
}

.user-info {
    display: flex;
    align-items: center;
    gap: 1rem;
    font-size: 0.9rem;
}

.user-badge {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background-color: rgba(255, 255, 255, 0.1);
    padding: 0.5rem 1rem;
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

#current-time {
    color: var(--light-gray);
    font-size: 0.9rem;
}

/* Mobile responsiveness */
@media (max-width: 768px) {
    .header-content {
        padding: 0 1rem;
    }
    
    .user-info {
        flex-direction: column;
        gap: 0.5rem;
        font-size: 0.8rem;
    }
    
    #current-time {
        display: none;
    }
}
</style>

<script>
// Update current time
function updateTime() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('ro-RO', { 
        hour: '2-digit', 
        minute: '2-digit' 
    });
    const timeElement = document.getElementById('current-time');
    if (timeElement) {
        timeElement.textContent = timeString;
    }
}

// Update time immediately and then every minute
updateTime();
setInterval(updateTime, 60000);
</script>