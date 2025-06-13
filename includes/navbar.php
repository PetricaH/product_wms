<?php
// includes/navbar.php - Fixed version without function conflicts

/**
 * Return the BEM active class modifier if the current script matches $page.
 */
function getActiveClass(string $page): string {
    // Compare the base filename of the current script with the target page filename
    $currentPage = basename($_SERVER['SCRIPT_NAME']);
    $targetPage = basename($page);
    
    return $currentPage === $targetPage
        ? 'sidebar__link--active' // Use the BEM modifier class
        : '';
}
?>
<aside class="sidebar">
    <div class="sidebar__logo">
        <?php if (function_exists('asset')): ?>
            <img src="<?= asset('assets/logo.png') ?>" alt="WMS Logo">
        <?php else: ?>
            <img src="<?= getNavUrl('assets/logo.png') ?>" alt="WMS Logo" style="max-width: 80%; height: auto;">
        <?php endif; ?>
    </div>

    <ul class="sidebar__nav">
        <!-- Dashboard -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('index.php') ?>"
               class="sidebar__link <?= getActiveClass('index.php') ?>">
                <span class="material-symbols-outlined">dashboard</span>
                <span>Tablou de Bord</span>
            </a>
        </li>
        
        <!-- User Management -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('users.php') ?>"
               class="sidebar__link <?= getActiveClass('users.php') ?>">
                <span class="material-symbols-outlined">group</span>
                <span>Utilizatori</span>
            </a>
        </li>
        
        <!-- Products -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('products.php') ?>"
               class="sidebar__link <?= getActiveClass('products.php') ?>">
                <span class="material-symbols-outlined">category</span>
                <span>Produse</span>
            </a>
        </li>
        
        <!-- Inventory -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('inventory.php') ?>"
               class="sidebar__link <?= getActiveClass('inventory.php') ?>">
                <span class="material-symbols-outlined">inventory</span>
                <span>Stocuri</span>
            </a>
        </li>
        
        <!-- Locations -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('locations.php') ?>"
               class="sidebar__link <?= getActiveClass('locations.php') ?>">
                <span class="material-symbols-outlined">pin_drop</span>
                <span>Depozite</span>
            </a>
        </li>
        
        <!-- Orders -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('orders.php') ?>"
               class="sidebar__link <?= getActiveClass('orders.php') ?>">
                <span class="material-symbols-outlined">shopping_cart</span>
                <span>Comenzi</span>
            </a>
        </li>
        
        <!-- Transactions -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('transactions.php') ?>"
               class="sidebar__link <?= getActiveClass('transactions.php') ?>">
                <span class="material-symbols-outlined">receipt_long</span>
                <span>Tranzacții</span>
            </a>
        </li>
        
        <!-- Activity Log -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('activities.php') ?>"
               class="sidebar__link <?= getActiveClass('activities.php') ?>">
                <span class="material-symbols-outlined">history</span>
                <span>Istoric Acțiuni</span>
            </a>
        </li>
    </ul>

    <div class="sidebar__profile">
        <span class="material-symbols-outlined">account_circle</span>
        <div class="profile-info">
            <span class="profile-name">
                <?= isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Admin User' ?>
            </span>
            <span class="profile-role">
                <?= isset($_SESSION['role']) ? ucfirst(htmlspecialchars($_SESSION['role'])) : 'Administrator' ?>
            </span>
        </div>
        <a href="<?= getNavUrl('logout.php') ?>" title="Logout" class="logout-link">
            <span class="material-symbols-outlined logout-icon">logout</span>
        </a>
    </div>
</aside>