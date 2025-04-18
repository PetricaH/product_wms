<?php
// includes/navbar.php

// Make sure helpers (asset(), in_prod()) are loaded
require_once __DIR__ . '/helpers.php';

/**
 * Return the URL for a given page.
 * (Adjust this if your app lives in a sub‑folder.)
 */
function getNavUrl(string $page): string {
    return $page;
}

/**
 * Return 'active' if the current script matches $page.
 */
function getActiveClass(string $page): string {
    return basename($_SERVER['SCRIPT_NAME']) === $page
        ? 'active'
        : '';
}
?>
<nav class="sidebar">
  <div class="logo-container">
    <img src="<?= asset('assets/logo.png') ?>" alt="Logo" class="logo">
    <h1 class="logo-text">YOUR WMS</h1>
  </div>

  <ul class="nav-items">
    <li>
      <a href="<?= getNavUrl('index.php') ?>"
         class="<?= getActiveClass('index.php') ?>">
        <span class="material-symbols-outlined">dashboard</span>
        Dashboard
      </a>
    </li>
    <li>
      <a href="<?= getNavUrl('users.php') ?>"
         class="<?= getActiveClass('users.php') ?>">
        <span class="material-symbols-outlined">group</span>
        Users
      </a>
    </li>
    <li>
      <a href="<?= getNavUrl('batches.php') ?>"
         class="<?= getActiveClass('batches.php') ?>">
        <span class="material-symbols-outlined">inventory</span>
        Batches
      </a>
    </li>
    <li>
      <a href="<?= getNavUrl('transactions.php') ?>"
         class="<?= getActiveClass('transactions.php') ?>">
        <span class="material-symbols-outlined">receipt</span>
        Transactions
      </a>
    </li>
    <li>
      <a href="<?= getNavUrl('views/inventory/items.php') ?>"
         class="<?= getActiveClass('items.php') ?>">
        <span class="material-symbols-outlined">category</span>
        Items
      </a>
    </li>
    <li>
      <a href="<?= getNavUrl('activities.php') ?>"
         class="<?= getActiveClass('activities.php') ?>">
        <span class="material-symbols-outlined">history</span>
        Activities
      </a>
    </li>
  </ul>

  <div class="admin-profile-container">
    <div class="admin-profile">
      <span class="material-symbols-outlined">person</span>
      Admin
      <span class="material-symbols-outlined">arrow_drop_down</span>
    </div>
    <div class="logout-option">
      <span class="material-symbols-outlined">logout</span>
      Logout
    </div>
  </div>
</nav>
