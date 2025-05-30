<?php
// includes/navbar.php

// Ensure helpers are loaded (assuming asset() function exists)
// require_once __DIR__ . '/helpers.php'; // Already required in index.php usually

/**
 * Return the URL for a given page.
 * Adjust base path if your app lives in a sub-folder.
 */
function getNavUrl(string $page): string {
    // If pages are directly in root or handled by routing, this is fine.
    // If they are in subdirectories, adjust path accordingly.
    // Example for items page: return '/views/inventory/' . $page;
    return '/' . ltrim($page, '/'); // Ensure leading slash
}

/**
 * Return the BEM active class modifier if the current script matches $page.
 */
function getActiveClass(string $page): string {
    // Compare the base filename of the current script with the target page filename
    $currentPage = basename($_SERVER['SCRIPT_NAME']);
    $targetPage = basename($page);
    // Add specific check for index.php potentially being just '/'
    // This might need refinement depending on your routing/server setup
    // if (($currentPage === 'index.php' || $currentPage === '') && ($targetPage === 'index.php' || $targetPage === '')) {
    //     return 'sidebar__link--active';
    // }
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
            <img src="/assets/logo.png" alt="WMS Logo" style="max-width: 80%; height: auto;">
            <?php endif; ?>
        </div>

    <ul class="sidebar__nav">
        <li class="sidebar__item">
            <a href="<?= getNavUrl('index.php') ?>"
               class="sidebar__link <?= getActiveClass('index.php') ?>">
                <span class="material-symbols-outlined">dashboard</span>
                <span>Tablou de Bord</span>
            </a>
        </li>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('users.php') ?>"
               class="sidebar__link <?= getActiveClass('users.php') ?>">
                <span class="material-symbols-outlined">group</span>
                <span>Utilizatori</span>
            </a>
        </li>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('batches.php') ?>"
               class="sidebar__link <?= getActiveClass('batches.php') ?>">
                <span class="material-symbols-outlined">inventory</span>
                <span>Batches</span>
            </a>
        </li>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('transactions.php') ?>"
               class="sidebar__link <?= getActiveClass('transactions.php') ?>">
                <span class="material-symbols-outlined">receipt_long</span> <span>Transactions</span>
            </a>
        </li>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('views/inventory/items.php') ?>"
               class="sidebar__link <?= getActiveClass('items.php') ?>">
                <span class="material-symbols-outlined">category</span>
                <span>Produse</span>
            </a>
        </li>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('activities.php') ?>"
               class="sidebar__link <?= getActiveClass('activities.php') ?>">
                <span class="material-symbols-outlined">history</span>
                <span>Istoric Actiuni</span>
            </a>
        </li>
        <li class="sidebar__item">
             <a href="<?= getNavUrl('locations.php') ?>"
                class="sidebar__link <?= getActiveClass('locations.php') ?>">
                 <span class="material-symbols-outlined">pin_drop</span>
                 <span>Depozite</span>
             </a>
        </li>
         <li class="sidebar__item">
             <a href="<?= getNavUrl('orders.php') ?>"
                class="sidebar__link <?= getActiveClass('orders.php') ?>">
                 <span class="material-symbols-outlined">shopping_cart</span>
                 <span>Comenzi</span>
             </a>
        </li>
    </ul>

    <div class="sidebar__profile">
        <span class="material-symbols-outlined">account_circle</span>
        <div class="profile-info">
            <span class="profile-name">Admin User</span> <span class="profile-role">Administrator</span> </div>
        <a href="/logout.php" title="Logout" class="logout-link">
             <span class="material-symbols-outlined logout-icon">logout</span>
        </a>
    </div>
</aside>
