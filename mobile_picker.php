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
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <style>
        .hidden {
            display: none !important;
        }
    </style>
</head>
<body>
    <div class="picker-container">
        <!-- Loading Overlay -->
        <div id="loading-overlay" class="hidden">
            <div class="spinner"></div>
        </div>

        <!-- Message Area -->
        <div id="message-area" class="message-area"></div>

        <header class="picker-header">
            <h1>Colectare Comandă</h1>
            <div id="order-number" class="order-number-display">#<?= $orderNumber ?></div>
        </header>

        <main id="task-container" class="task-container">
            <!-- Order Input Section -->
            <div id="scan-order-section" class="state-card hidden">
                <h2>Scanați Codul Comenzii</h2>
                <button id="scan-order-btn" class="btn btn-primary">
                    <span class="material-symbols-outlined">qr_code_scanner</span>
                    Scanează Comandă
                </button>
                <button id="toggle-manual-input-btn" class="btn btn-secondary">
                    Manual Input
                </button>
                <div id="scanned-order-id"></div>
            </div>

            <div id="manual-order-section" class="state-card hidden">
                <h2>Introduceți Numărul Comenzii</h2>
                <input type="text" id="order-id-input" placeholder="ORD-XXXXXX-XX" value="<?= $orderNumber ?>">
                <button id="load-manual-order-btn" class="btn btn-primary">Încărcare</button>
                <button id="toggle-scan-input-btn" class="btn btn-secondary">Înapoi la Scanare</button>
            </div>

            <!-- Scanner Container -->
            <div id="scanner-container" class="scanner-container hidden">
                <div id="reader"></div>
                <button id="stop-scan-btn" class="btn btn-danger">Oprește Scanarea</button>
            </div>

            <!-- Location Verification -->
            <div id="location-scan-prompt" class="state-card hidden">
                <h2>Verificare Locație</h2>
                <p>Locația țintă: <strong id="target-location-code">A1-01-01</strong></p>
                
                <div id="scan-location-section">
                    <button id="scan-location-btn" class="btn btn-primary">
                        <span class="material-symbols-outlined">qr_code_scanner</span>
                        Scanează Locația
                    </button>
                    <button id="toggle-manual-location-btn" class="btn btn-secondary">Manual</button>
                </div>

                <div id="manual-location-section" class="hidden">
                    <input type="text" id="location-code-input" placeholder="Introduceți codul locației">
                    <button id="verify-manual-location-btn" class="btn btn-primary">Verifică</button>
                    <button id="toggle-scan-location-btn" class="btn btn-secondary">Înapoi la Scanare</button>
                </div>
            </div>

            <!-- Product Verification -->
            <div id="product-scan-prompt" class="state-card hidden">
                <h2>Verificare Produs</h2>
                <p>Produs țintă: <strong id="target-product-sku">SKU-123</strong></p>
                <p id="target-product-name">(Nume Produs)</p>
                
                <div id="scan-product-section">
                    <button id="scan-product-btn" class="btn btn-primary">
                        <span class="material-symbols-outlined">qr_code_scanner</span>
                        Scanează Produsul
                    </button>
                    <button id="toggle-manual-product-btn" class="btn btn-secondary">Manual</button>
                </div>

                <div id="manual-product-section" class="hidden">
                    <input type="text" id="product-sku-input" placeholder="Introduceți SKU produsului">
                    <button id="verify-manual-product-btn" class="btn btn-primary">Verifică</button>
                    <button id="toggle-scan-product-btn" class="btn btn-secondary">Înapoi la Scanare</button>
                </div>
            </div>

            <!-- Loading state - THIS IS THE ONLY ONE VISIBLE BY DEFAULT -->
            <div id="loading-state" class="state-card">
                <div class="spinner"></div>
                <p>Se încarcă următoarea sarcină...</p>
            </div>

            <!-- Task display state -->
            <div id="task-state" class="state-card hidden">
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
                    <p class="details-text">Disponibil în locație: <span id="available-in-location">0</span></p>
                </div>
                <div class="actions">
                    <input type="number" id="picked-quantity-input" class="quantity-input" placeholder="Introduceți cantitatea">
                    <button id="confirm-pick-btn" class="btn btn-primary">
                        <span class="material-symbols-outlined">check_circle</span>
                        Confirmă Colectarea
                    </button>
                </div>
            </div>

            <!-- Task Display (alternative structure that JS expects) -->
            <div id="task-display" class="state-card hidden">
                <div class="task-details">
                    <div class="icon-text">
                        <span class="material-symbols-outlined">inventory_2</span>
                        <strong id="product-name-display"></strong>
                    </div>
                    <div class="icon-text">
                        <span class="material-symbols-outlined">tag</span>
                        <strong>SKU: <span id="product-sku-display"></span></strong>
                    </div>
                    <div class="icon-text">
                        <span class="material-symbols-outlined">pin_drop</span>
                        <strong>Locație: <span id="location-code-display"></span></strong>
                    </div>
                </div>
            </div>

            <!-- Confirmation Area -->
            <div id="confirmation-area" class="state-card hidden">
                <h3>Confirmați Colectarea</h3>
                <div class="picking-input">
                    <label for="quantity-picked-input">Cantitate colectată:</label>
                    <input type="number" id="quantity-picked-input" class="quantity-input" 
                           placeholder="Introduceți cantitatea" min="1">
                </div>
                <button id="confirm-pick-btn" class="btn btn-primary">
                    <span class="material-symbols-outlined">check_circle</span>
                    Confirmă Colectarea
                </button>
            </div>

            <!-- Completion state -->
            <div id="completion-state" class="state-card hidden">
                <span class="material-symbols-outlined icon-large">task_alt</span>
                <h2 id="completion-message"></h2>
                <a href="/warehouse_orders.php" class="btn btn-secondary">Înapoi la Comenzi</a>
            </div>

            <!-- All Done Message (alternative structure that JS expects) -->
            <div id="all-done-message" class="state-card hidden">
                <span class="material-symbols-outlined icon-large">task_alt</span>
                <h2>Comandă Finalizată!</h2>
                <p>Toate articolele au fost colectate cu succes.</p>
                <div class="actions">
                    <a href="warehouse_orders.php" class="btn btn-secondary">Înapoi la Comenzi</a>
                    <a href="mobile_picker.php" class="btn btn-primary">Comandă Nouă</a>
                </div>
            </div>

            <!-- Error state -->
            <div id="error-state" class="state-card hidden">
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