<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hub Operații Depozit - WMS</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="styles/global.css">
    <link rel="stylesheet" href="styles/warehouse-css/warehouse_hub.css">
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <div class="header-title">
                <span class="material-symbols-outlined">warehouse</span>
                WMS - Hub Operații
            </div>
            <div class="user-info">
                <div id="current-time"></div>
                <div class="user-badge">
                    <span class="material-symbols-outlined">person</span>
                    <span id="worker-name">Lucrător</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Container -->
    <div class="main-container">
        <div class="page-header">
            <h1 class="page-title">Hub Operații Depozit</h1>
            <p class="page-subtitle">Selectează operația dorită pentru a începe lucrul</p>
        </div>

        <div class="operations-grid">
            <!-- Picking -->
            <div class="operation-card picking-card" data-operation="picking">
                <div class="status-indicator" id="picking-status"></div>
                <span class="material-symbols-outlined operation-icon">shopping_cart</span>
                <h2 class="operation-title">Picking Comenzi</h2>
                <p class="operation-description">
                    Scanează comenzile, localizează produsele și finalizează task-urile de picking cu ghidare pas cu pas.
                </p>
                <div class="operation-stats">
                    <div class="stat-item">
                        <span class="stat-number" id="pending-picks">-</span>
                        <span class="stat-label">În așteptare</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number" id="picks-today">-</span>
                        <span class="stat-label">Astăzi</span>
                    </div>
                </div>
            </div>

            <!-- Receiving -->
            <div class="operation-card receiving-card" data-operation="receiving">
                <div class="status-indicator" id="receiving-status"></div>
                <span class="material-symbols-outlined operation-icon">inventory_2</span>
                <h2 class="operation-title">Recepție Marfă</h2>
                <p class="operation-description">
                    Procesează livrările primite, verifică produsele și actualizează stocurile cu localizare precisă.
                </p>
                <div class="operation-stats">
                    <div class="stat-item">
                        <span class="stat-number" id="pending-receipts">-</span>
                        <span class="stat-label">De procesat</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number" id="received-today">-</span>
                        <span class="stat-label">Astăzi</span>
                    </div>
                </div>
            </div>

            <!-- Inventory -->
            <div class="operation-card inventory-card" data-operation="inventory">
                <div class="status-indicator" id="inventory-status"></div>
                <span class="material-symbols-outlined operation-icon">inventory</span>
                <h2 class="operation-title">Căutare Stoc</h2>
                <p class="operation-description">
                    Căutări rapide în inventar, verificări de stoc și localizări pentru managementul eficient al depozitului.
                </p>
                <div class="operation-stats">
                    <div class="stat-item">
                        <span class="stat-number" id="total-products">-</span>
                        <span class="stat-label">Produse</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number" id="low-stock-items">-</span>
                        <span class="stat-label">Stoc scăzut</span>
                    </div>
                </div>
            </div>

            <!-- Cycle Count -->
            <div class="operation-card cycle-count-card" data-operation="cycle-count">
                <div class="status-indicator" id="cycle-count-status"></div>
                <span class="material-symbols-outlined operation-icon">fact_check</span>
                <h2 class="operation-title">Inventariere Ciclică</h2>
                <p class="operation-description">
                    Efectuează inventarieri sistematice pentru menținerea preciziei stocurilor și identificarea discrepanțelor.
                </p>
                <div class="operation-stats">
                    <div class="stat-item">
                        <span class="stat-number" id="scheduled-counts">-</span>
                        <span class="stat-label">Programate</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number" id="variance-items">-</span>
                        <span class="stat-label">Diferențe</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        WMS © 2025 - Sistem de Management Depozit
    </div>

    <!-- JavaScript Files -->
    <script src="scripts/warehouse-js/warehouse_hub.js?v=<?= filemtime('scripts/warehouse-js/warehouse_hub.js') ?>"></script>
</body>
</html>