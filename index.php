<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$config = require __DIR__ . '/config/config.php';
define('BASE_PATH', __DIR__);

require_once __DIR__ . '/includes/helpers.php';

$db      = $config['connection_factory']();
require_once __DIR__ . '/models/Product.php';
require_once __DIR__ . '/models/User.php';

$product     = new Product($db);
$users       = new Users($db);
$totalProducts = $product->countAll();
$totalUsers    = $users->countAllUsers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/includes/header.php'; ?>
</head>
<body class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <main class="main-content">
        <div class="sumar">
            <div class="summar-card total-produse">
                <span class="material-symbols-outlined summar-card-icon">
                inventory_2
                </span>
                <h3>Total Produse</h3>
                <p class="count"><?= $totalProducts ?></p>
            </div>
            <div class="summar-card total-utilizatori">
                <span class="material-symbols-outlined summar-card-icon">
                group
                </span>
                <h3>Total Utilizatori</h3>
                <p class="count"><?= $totalUsers ?></p>
            </div>
            <div class="summar-card total-incasari">
                <span class="material-symbols-outlined summar-card-icon">
                payments
                </span>
                <h3>Total Încasări</h3>
                <p class="count">0</p>
            </div>
            <div class="summar-card total-iesiri">
                <span class="material-symbols-outlined summar-card-icon">
                call_made
                </span>
                <h3>Total Ieșiri</h3>
                <p class="count">0</p>
            </div>
            <div class="summar-card comenzi-active">
                <span class="material-symbols-outlined summar-card-icon">
                today
                </span>
                <h3>Comenzi Active</h3>
                <p class="count">0</p>
            </div>
            <div class="summar-card venituri-totale">
                <span class="material-symbols-outlined summar-card-icon">
                account_balance
                </span>
                <h3>Venituri Totale</h3>
                <p class="count">0</p>
            </div>
        </div>
        <div class="warehouse-space-card">
            <h3>Spatiu in Depozit</h3>
            <span></span>
        </div>
    </main>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
