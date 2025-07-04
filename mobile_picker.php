<?php
// File: mobile_picker.php
// Interface for mobile picking tasks

// Basic configuration, can be expanded
ini_set('display_errors', 1);
error_reporting(E_ALL);

// You can include a bootstrap or config file here if needed
// require_once 'config/bootstrap.php';

$orderNumber = htmlspecialchars($_GET['order'] ?? 'N/A');

?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>WMS Picker - Comanda <?= $orderNumber ?></title>
    <link rel="stylesheet" href="styles/mobile_picker.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
</head>
<body>
    <div class="picker-container">
        <header class="picker-header">
            <h1>Colectare Comandă</h1>
            <div id="order-number" class="order-number-display">#<?= $orderNumber ?></div>
        </header>

        <main id="task-container" class="task-container">
            <!-- Loading state -->
            <div id="loading-state" class="state-card">
                <div class="spinner"></div>
                <p>Se încarcă următoarea sarcină...</p>
            </div>

            <!-- Task display state -->
            <div id="task-state" class="state-card" style="display: none;">
                <div class="location-info">
                    <span class="material-symbols-outlined">pin_drop</span>
                    <h2 id="location-code"></h2>
                </div>
                <div class="product-info">
                    <h3 id="product-name"></h3>
                    <p>SKU: <strong id="product-sku"></strong></p>
                    <p>Barcode: <strong id="product-barcode"></strong></p>
                </div>
                <div class="quantity-info">
                    <p>Cantitate de colectat:</p>
                    <div class="quantity-display" id="quantity-to-pick"></div>
                </div>
                <div class="actions">
                    <input type="number" id="picked-quantity-input" class="quantity-input" placeholder="Introduceți cantitatea">
                    <button id="confirm-pick-btn" class="btn btn-primary">
                        <span class="material-symbols-outlined">check_circle</span>
                        Confirmă Colectarea
                    </button>
                </div>
            </div>

            <!-- Completion state -->
            <div id="completion-state" class="state-card" style="display: none;">
                <span class="material-symbols-outlined icon-large">task_alt</span>
                <h2 id="completion-message"></h2>
                <a href="/warehouse_orders.php" class="btn btn-secondary">Înapoi la Comenzi</a>
            </div>

            <!-- Error state -->
            <div id="error-state" class="state-card" style="display: none;">
                <span class="material-symbols-outlined icon-large">error</span>
                <h2>Eroare</h2>
                <p id="error-message"></p>
                <button onclick="location.reload()" class="btn btn-secondary">
                    <span class="material-symbols-outlined">refresh</span>
                    Reîncărcare
                </button>
            </div>
        </main>

        <footer class="picker-footer">
            <p>WMS Picker v1.0</p>
        </footer>
    </div>

    <script src="scripts/mobile_picker.js"></script>
</body>
</html>
