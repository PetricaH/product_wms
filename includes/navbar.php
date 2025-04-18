<require_once 'header.php'; ?>
<div class="sidebar">
    <div class="logo-container">
        <img src="assets/logo.png" alt="Logo" class="logo">
        <h1 class="logo-text">Wartung WMS</h1>
    </div>
    <ul class="nav-items">
        <a href="index.php" class="<?php echo getActiveClass('index.php'); ?>">
            <li>
                <span class="material-symbols-outlined">dashboard</span>
                Dashboard
            </li>
        </a>
        <a href="users.php" class="<?php echo getActiveClass('users.php'); ?>">
            <li>
                <span class="material-symbols-outlined">group</span>
                Users
            </li>
        </a>
        <a href="batches.php" class="<?php echo getActiveClass('batches.php'); ?>">
            <li>
                <span class="material-symbols-outlined">inventory</span>
                Batches
            </li>
        </a>
        <a href="transactions.php" class="<?php echo getActiveClass('transactions.php'); ?>">
            <li>
                <span class="material-symbols-outlined">receipt</span>
                Transactions
            </li>
        </a>
        <a href="items.php" class="<?php echo getActiveClass('items.php'); ?>">
            <li>
                <span class="material-symbols-outlined">category</span>
                Items
            </li>
        </a>
        <a href="activities.php" class="<?php echo getActiveClass('activities.php'); ?>">
            <li>
                <span class="material-symbols-outlined">history</span>
                Activities
            </li>
        </a>
    </ul>
    <div class="admin-profile-container">
        <div class="admin-profile">
            <span class="material-symbols-outlined">person</span>Admin<span class="material-symbols-outlined">
            arrow_drop_down
            </span>
        </div>
        <div class="logout-option">
            <span class="material-symbols-outlined">logout</span>Logout
        </div>
    </div>
</div>