<?php
// warehouse_inventory.php
// Inventory Search Interface with PHP Bootstrap

require_once __DIR__ . '/bootstrap.php';

// Get the base URL from bootstrap
$baseUrl = rtrim(BASE_URL, '/');
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Căutare Stoc - WMS</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    
    <!-- QR Code Scanner Library -->
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="styles/global.css">
    <link rel="stylesheet" href="styles/warehouse_inventory.css">
    
    <!-- Pass configuration to JavaScript -->
    <script>
        window.WMS_CONFIG = {
            baseUrl: '<?= htmlspecialchars($baseUrl) ?>',
            apiBase: '<?= htmlspecialchars($baseUrl) ?>/api'
        };
    </script>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <button class="back-btn" id="back-btn" onclick="window.location.href='warehouse_hub.php'">
                <span class="material-symbols-outlined">arrow_back</span>
            </button>
            <div class="header-title">
                <span class="material-symbols-outlined">inventory</span>
                Căutare Stoc
            </div>
            <div class="search-counter">
                <span id="search-counter">0</span> căutări
            </div>
        </div>
    </div>

    <!-- Main Container -->
    <div class="container">
        <div class="content">
            <!-- Search Section -->
            <div class="search-section">
                <h1 class="search-title">Căutare în Inventar</h1>
                
                <!-- Search Tabs -->
                <div class="search-tabs">
                    <button class="search-tab active" data-tab="sku">SKU</button>
                    <button class="search-tab" data-tab="name">Nume</button>
                    <button class="search-tab" data-tab="location">Locație</button>
                </div>

                <!-- SKU Search Tab -->
                <div class="tab-content" id="sku-tab">
                    <div class="form-group">
                        <label class="form-label" for="sku-input">Cod SKU:</label>
                        <input type="text" class="form-input" id="sku-input" placeholder="ex: PROD-001-XL">
                    </div>
                    
                    <button class="btn btn-primary btn-full" id="search-sku-btn">
                        <span class="material-symbols-outlined">search</span>
                        Caută după SKU
                    </button>
                </div>

                <!-- Name Search Tab -->
                <div class="tab-content hidden" id="name-tab">
                    <div class="form-group">
                        <label class="form-label" for="name-input">Numele Produsului:</label>
                        <input type="text" class="form-input" id="name-input" placeholder="ex: Tricou albastru">
                    </div>
                    
                    <button class="btn btn-primary btn-full" id="search-name-btn">
                        <span class="material-symbols-outlined">search</span>
                        Caută după Nume
                    </button>
                </div>

                <!-- Location Search Tab -->
                <div class="tab-content hidden" id="location-tab">
                    <div class="form-group">
                        <label class="form-label" for="location-input">Cod Locație:</label>
                        <input type="text" class="form-input" id="location-input" placeholder="ex: A01-B02-C03">
                    </div>
                    
                    <button class="btn btn-primary btn-full" id="search-location-btn">
                        <span class="material-symbols-outlined">search</span>
                        Caută după Locație
                    </button>
                </div>

                <!-- Scanner Section -->
                <div class="scanner-container" id="scanner-container">
                    <div id="reader"></div>
                    <div class="scanner-controls">
                        <button class="btn btn-secondary btn-full" id="stop-scan-btn" style="display: none;">
                            <span class="material-symbols-outlined">stop</span>
                            Oprește Scanner
                        </button>
                    </div>
                </div>

                <button class="btn btn-secondary btn-full" id="start-scan-btn">
                    <span class="material-symbols-outlined">qr_code_scanner</span>
                    Pornește Scanner QR/Barcode
                </button>
            </div>

            <!-- Status Messages -->
            <div id="status-message-container"></div>

            <!-- Results Section -->
            <div class="results-section hidden" id="results-section">
                <div class="results-header">
                    <h2 class="results-title">Rezultate Căutare</h2>
                    <span class="results-count" id="results-count">0 rezultate</span>
                </div>
                
                <div id="results-container">
                    <!-- Results will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Files -->
    <script src="scripts/warehouse_inventory.js"></script>
</body>
</html>