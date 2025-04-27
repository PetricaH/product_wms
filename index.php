<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Load configuration
$config = require __DIR__ . '/config/config.php';
define('BASE_PATH', __DIR__);

// Include helpers
require_once __DIR__ . '/includes/helpers.php';

// Get database connection using the factory from config
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
     die("Database connection factory not configured correctly in config.php");
}
$dbFactory = $config['connection_factory'];
$db        = $dbFactory(); // Execute the factory to get the PDO object

// Include Models
require_once __DIR__ . '/models/Product.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Location.php';
require_once __DIR__ . '/models/Inventory.php';

// Instantiate Models
$product       = new Product($db);
$users         = new Users($db);
$location      = new Location($db);
$inventory     = new Inventory($db);

// --- Get Data for Dashboard Cards ---
$totalProducts = $product->countAll(); // Counts distinct product SKUs
$totalUsers    = $users->countAllUsers();
$warehouseOccupationPercent = $location->calculateOccupationPercentage();
$totalLocations = $location->countTotalLocations();
$occupiedLocations = $location->countOccupiedLocations();
$totalItemsInStock = $inventory->getTotalItemCount();

// Placeholder data (replace with actual logic when available)
$totalIncasari = 0;
$totalIesiri = 0;
$comenziActive = 0;

?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/header.php'; ?>

    <link rel="stylesheet" href="/path/to/your/compiled/main.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />

    <title>WMS Dashboard</title> </head>
<body class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <main class="main-content">
        <header class="main-header">
            <h1>Dashboard Sumar</h1>
            </header>

        <section class="summary-grid">

            <article class="summary-card summary-card--products">
                <div class="summary-card__icon-wrapper">
                    <span class="material-symbols-outlined">inventory_2</span>
                </div>
                <div class="summary-card__content">
                    <h3 class="summary-card__title">Total Produse</h3>
                    <p class="summary-card__value"><?= number_format($totalProducts) ?></p>
                    <p class="summary-card__details">Tipuri distincte (SKU)</p>
                </div>
            </article>

            <article class="summary-card summary-card--users">
                <div class="summary-card__icon-wrapper">
                     <span class="material-symbols-outlined">group</span>
                </div>
                 <div class="summary-card__content">
                    <h3 class="summary-card__title">Total Utilizatori</h3>
                    <p class="summary-card__value"><?= number_format($totalUsers) ?></p>
                    <p class="summary-card__details">Utilizatori înregistrați</p>
                 </div>
            </article>

            <article class="summary-card summary-card--occupation">
                 <div class="summary-card__icon-wrapper">
                    <span class="material-symbols-outlined">warehouse</span>
                 </div>
                 <div class="summary-card__content">
                    <h3 class="summary-card__title">Ocupare Depozit</h3>
                    <div class="progress-circle-container" title="<?= number_format($occupiedLocations) ?> / <?= number_format($totalLocations) ?> Locații Ocupate">
                        <div
                            class="progress-circle"
                            role="progressbar"
                            aria-valuenow="<?= $warehouseOccupationPercent ?>"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            style="--progress-percent: <?= $warehouseOccupationPercent ?>%"
                        >
                            <div class="progress-circle-inner">
                                <span class="percentage-text"><?= $warehouseOccupationPercent ?>%</span>
                            </div>
                        </div>
                    </div>
                    <p class="summary-card__details">Bazat pe locații</p>
                 </div>
            </article>

            <article class="summary-card summary-card--items">
                 <div class="summary-card__icon-wrapper">
                    <span class="material-symbols-outlined">widgets</span>
                 </div>
                 <div class="summary-card__content">
                    <h3 class="summary-card__title">Total Articole Stoc</h3>
                    <p class="summary-card__value"><?= number_format($totalItemsInStock) ?></p>
                    <p class="summary-card__details">Unități individuale</p>
                 </div>
            </article>

            <article class="summary-card summary-card--revenue">
                <div class="summary-card__icon-wrapper">
                   <span class="material-symbols-outlined">payments</span>
                </div>
                <div class="summary-card__content">
                   <h3 class="summary-card__title">Total Încasări</h3>
                   <p class="summary-card__value"><?= number_format($totalIncasari, 2) ?> Lei</p>
                   <p class="summary-card__details">Perioada selectată</p>
                </div>
            </article>

            <article class="summary-card summary-card--shipped">
                <div class="summary-card__icon-wrapper">
                    <span class="material-symbols-outlined">call_made</span>
                </div>
                <div class="summary-card__content">
                    <h3 class="summary-card__title">Total Ieșiri</h3>
                    <p class="summary-card__value"><?= number_format($totalIesiri) ?></p>
                    <p class="summary-card__details">Articole expediate</p>
                </div>
            </article>

            <article class="summary-card summary-card--orders">
                <div class="summary-card__icon-wrapper">
                    <span class="material-symbols-outlined">pending_actions</span>
                </div>
                <div class="summary-card__content">
                    <h3 class="summary-card__title">Comenzi Active</h3>
                    <p class="summary-card__value"><?= number_format($comenziActive) ?></p>
                    <p class="summary-card__details">Comenzi în așteptare</p>
                </div>
            </article>

            </section> </main>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>