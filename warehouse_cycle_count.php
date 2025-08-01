<?php
require_once __DIR__ . '/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WMS - Inventariere Ciclică</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="styles/global.css">
    <link rel="stylesheet" href="styles/warehouse_cycle_count.css">
    
    <style>
        /* Immediate dark theme application */
        body {
            background: linear-gradient(135deg, #0F1013 0%, #16161A 100%) !important;
            color: #FEFFFF !important;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <button class="back-btn" onclick="window.history.back()">
                <span class="material-symbols-outlined">arrow_back</span>
            </button>
            <div class="header-title">
                <span class="material-symbols-outlined">inventory</span>
                Inventariere Ciclică
            </div>
            <div class="header-actions">
                <button class="header-btn" id="settings-btn">
                    <span class="material-symbols-outlined">settings</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Dashboard Section -->
        <section class="cycle-count-dashboard">
            <div class="container">
                <div class="page-header">
                    <h1 class="page-title">📊 Cycle Count Dashboard</h1>
                    <p class="page-subtitle">Inventory verification and count management system</p>
                </div>

                <div class="dashboard-grid">
                    <div class="dashboard-card">
                        <span class="card-icon material-symbols-outlined">pending</span>
                        <span class="card-value" id="pending-counts">0</span>
                        <span class="card-label">Pending Counts</span>
                    </div>
                    <div class="dashboard-card">
                        <span class="card-icon material-symbols-outlined">check_circle</span>
                        <span class="card-value" id="completed-today">0</span>
                        <span class="card-label">Completed Today</span>
                    </div>
                    <div class="dashboard-card">
                        <span class="card-icon material-symbols-outlined">warning</span>
                        <span class="card-value" id="discrepancies">0</span>
                        <span class="card-label">Discrepancies</span>
                    </div>
                    <div class="dashboard-card">
                        <span class="card-icon material-symbols-outlined">inventory</span>
                        <span class="card-value" id="total-items">0</span>
                        <span class="card-label">Total Items</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Active Count Section -->
        <section class="active-count-section">
            <div class="container">
                <h2 class="section-title">
                    <span class="material-symbols-outlined">inventory</span>
                    New Cycle Count
                </h2>

                <!-- Scanner Interface -->
                <div class="scanner-interface">
                    <div class="scanner-controls">
                        <button id="start-scan" class="scanner-btn">
                            <span class="material-symbols-outlined">qr_code_scanner</span>
                            Start Scanner
                        </button>
                        <button id="stop-scan" class="scanner-btn secondary" style="display: none;">
                            <span class="material-symbols-outlined">stop</span>
                            Stop Scanner
                        </button>
                        <button id="switch-camera" class="scanner-btn secondary" style="display: none;">
                            <span class="material-symbols-outlined">flip_camera_android</span>
                            Switch Camera
                        </button>
                    </div>

                    <!-- Camera Preview -->
                    <div class="camera-preview">
                        <div id="scanner-video"></div>
                        <div class="camera-overlay"></div>
                        <div class="scanner-status">Ready to scan barcodes</div>
                    </div>
                </div>

                <!-- Manual Input Section -->
                <div class="manual-input-section">
                    <form id="manual-count-form">
                        <div class="input-group">
                            <label for="location-select" class="input-label">Location *</label>
                            <select id="location-select" name="location" class="input-field" required>
                                <option value="">Select Location</option>
                                <option value="A1-01">A1-01 - Shelf A1 Level 1</option>
                                <option value="A1-02">A1-02 - Shelf A1 Level 2</option>
                                <option value="B2-01">B2-01 - Shelf B2 Level 1</option>
                                <option value="C3-01">C3-01 - Shelf C3 Level 1</option>
                            </select>
                        </div>

                        <div class="input-group">
                            <label for="barcode" class="input-label">Barcode</label>
                            <input type="text" id="barcode" name="barcode" class="input-field" 
                                   placeholder="Scan or enter barcode">
                        </div>

                        <div class="input-group">
                            <label for="product-id" class="input-label">Product ID</label>
                            <input type="text" id="product-id" name="product_id" class="input-field" 
                                   placeholder="Product ID" readonly>
                        </div>

                        <div class="input-group">
                            <label for="product-name" class="input-label">Product Name *</label>
                            <input type="text" id="product-name" name="product_name" class="input-field" 
                                   placeholder="Product name" required>
                        </div>

                        <div class="input-group">
                            <label for="expected-qty" class="input-label">Expected Quantity</label>
                            <input type="number" id="expected-qty" name="expected_qty" class="input-field" 
                                   placeholder="0" min="0">
                        </div>

                        <div class="input-group">
                            <label for="counted-qty" class="input-label">Counted Quantity *</label>
                            <input type="number" id="counted-qty" name="counted_qty" class="input-field" 
                                   placeholder="Enter count" min="0" required>
                        </div>

                        <div class="input-group">
                            <label for="notes" class="input-label">Notes</label>
                            <textarea id="notes" name="notes" class="input-field" 
                                      placeholder="Optional notes" rows="2"></textarea>
                        </div>

                        <button type="submit" class="scanner-btn">
                            <span class="material-symbols-outlined">add</span>
                            Add Item to Count
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <!-- Count Results -->
        <section class="count-results">
            <div class="container">
                <div class="results-header">
                    <h3 class="results-title">Current Count Items</h3>
                </div>
                <div class="results-list" id="current-count-items">
                    <div class="empty-state">No items counted yet</div>
                </div>
            </div>
        </section>

        <!-- Count Actions -->
        <section class="count-actions" style="margin-top: 2rem;">
            <div class="container">
                <div class="scanner-controls">
                    <button id="save-count" class="scanner-btn secondary">
                        <span class="material-symbols-outlined">save</span>
                        Save as Draft
                    </button>
                    <button id="submit-count" class="scanner-btn">
                        <span class="material-symbols-outlined">check</span>
                        Submit Count
                    </button>
                    <button id="reset-count" class="scanner-btn secondary">
                        <span class="material-symbols-outlined">refresh</span>
                        Reset Count
                    </button>
                </div>
            </div>
        </section>
    </div>

    <!-- Floating Action Button -->
    <button class="fab" id="fab-scan">
        <span class="material-symbols-outlined">qr_code_scanner</span>
    </button>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="loading-overlay" style="display: none;">
        <div class="loading-spinner"></div>
    </div>

    <!-- JavaScript Files -->
    <script src="scripts/warehouse_cycle_count.js"></script>
    <script>
        // Pass configuration to JavaScript
        window.WMS_CONFIG = {
            baseUrl: '<?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>',
            apiBase: '<?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>/api'
        };
    </script>
</body>
</html>