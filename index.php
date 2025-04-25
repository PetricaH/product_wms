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
require_once __DIR__ . '/models/Location.php';

$product     = new Product($db);
$users       = new Users($db);
$location   = new Location($db);

$totalProducts = $product->countAll();
$totalUsers    = $users->countAllUsers();

$warehouseOccupationPercent = $location->calculateOccupationPercentage();
$totalLocations = $location->countTotalLocations();
$occupiedLocations = $location->countOccupiedLocations();

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
        <div class="summar-card warehouse-occupation">
            <div class="card-content-top">
                <span class="material-symbols-outlined summar-card-icon">warehouse</span>
                <h3>Ocupare Depozit</h3>
                    <div class="progress-circle-container">
                        <div class="progress-circle"
                            role="progressbar"
                            aria-valuenow="<?= $warehouseOccupationPercent ?>"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            style="--progress-percent: <?= $warehouseOccupationPercent ?>%"
                        >
                            <div class="progress-circle-inner">
                                <span class="percentage-text"><? $warehouseOccupationPercent ?>%</span>
                            </div>
                        </div>
                    </div>
            </div>
            <div class="card-details">
                <?= number_format($occupiedLocations) ?> / <?= number_format($totalLocations) ?> Locatii Ocupate
            </div>
        </div>
    </main>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
