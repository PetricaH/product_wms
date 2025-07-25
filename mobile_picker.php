<?php
// File: mobile_picker.php - Improved Picking Interface
$currentPage = 'mobile_picker';

// Get order from URL parameter if provided
$orderNumber = htmlspecialchars($_GET['order'] ?? '');
$hasOrderFromUrl = !empty($orderNumber);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/warehouse_header.php'; ?>
    <link rel="stylesheet" href="styles/awb_generation.css">
</head>
<body>
    <!-- Simple Header (matching existing warehouse_orders.php style) -->
    <div class="picker-header">
        <div class="header-content">
            <h1>
                <span class="material-symbols-outlined">inventory</span>
                Mobile Picker
            </h1>
            <div id="order-info" class="order-info <?= !$hasOrderFromUrl ? 'hidden' : '' ?>">
                <div class="order-number">#<span id="current-order-number"><?= $orderNumber ?></span></div>
                <div class="customer-name" id="customer-name">Loading...</div>
            </div>
            <button id="print-invoice-btn" class="btn btn-secondary btn-sm <?= !$hasOrderFromUrl ? 'hidden' : '' ?>" title="Printează Factura">
                <span class="material-symbols-outlined">print</span>
            </button>
        </div>
    </div>

        <!-- Order Input Section (shown when no order in URL) -->
        <div id="order-input-section" class="content-section <?= $hasOrderFromUrl ? 'hidden' : '' ?>">
            <div class="input-card">
                <h2>Introduceți Comanda</h2>
                <div class="input-group">
                    <label for="order-input">Numărul comenzii:</label>
                    <input type="text" id="order-input" placeholder="ORD-2025-000001" class="form-control">
                    <button id="load-order-btn" class="btn btn-primary">
                        <span class="material-symbols-outlined">search</span>
                        Încarcă Comanda
                    </button>
                </div>
            </div>
        </div>

        <!-- Progress Section -->
        <div id="progress-section" class="progress-section <?= !$hasOrderFromUrl ? 'hidden' : '' ?>">
            <div class="progress-stats">
                <span>Progres: <strong id="items-completed">0</strong> din <strong id="items-total">0</strong></span>
                <span id="progress-percentage">0%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" id="progress-fill"></div>
            </div>
        </div>

        <!-- Picking List Section -->
        <div id="picking-list-section" class="content-section <?= !$hasOrderFromUrl ? 'hidden' : '' ?>">
            <div class="section-header">
                <h2>Lista de Colectare</h2>
                <button id="refresh-items-btn" class="btn btn-secondary btn-sm">
                    <span class="material-symbols-outlined">refresh</span>
                </button>
            </div>
            
            <div id="items-container" class="items-container">
                <!-- Items will be loaded here -->
            </div>
        </div>

        <?php if (!empty($order['awb_barcode'])): ?>
            <div class="awb-status">
                <span class="material-symbols-outlined">local_shipping</span>
                AWB: <?= htmlspecialchars($order['awb_barcode']) ?>
            </div>
        <?php else: ?>
            <button type="button" class="generate-awb-btn" 
                    data-order-id="<?= $order['id'] ?>">
                <span class="material-symbols-outlined">local_shipping</span>
                Generează AWB
            </button>
        <?php endif; ?>

        <!-- Picking Workflow Sections -->
        
        <!-- Step 1: Location Verification -->
        <div id="location-step" class="workflow-step hidden">
            <div class="step-card">
                <div class="step-header">
                    <h3>Pas 1: Verificare Locație</h3>
                    <div class="step-info">
                        <span class="material-symbols-outlined">pin_drop</span>
                        <span>Locația necesară: <strong id="target-location">A1-01</strong></span>
                    </div>
                </div>
                
                <div class="scan-section" id="location-scan-section">
                    <button id="scan-location-btn" class="btn btn-primary btn-large">
                        <span class="material-symbols-outlined">qr_code_scanner</span>
                        Scanează Locația
                    </button>
                    <button id="manual-location-btn" class="btn btn-secondary">
                        <span class="material-symbols-outlined">keyboard</span>
                        Introdu Manual
                    </button>
                </div>
                
                <div class="manual-section hidden" id="location-manual-section">
                    <label for="location-input">Codul locației:</label>
                    <input type="text" id="location-input" class="form-control" placeholder="A1-01">
                    <button id="verify-location-btn" class="btn btn-primary">
                        <span class="material-symbols-outlined">check</span>
                        Verifică Locația
                    </button>
                    <button id="back-to-scan-location" class="btn btn-secondary">
                        <span class="material-symbols-outlined">qr_code_scanner</span>
                        Înapoi la Scanare
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Step 2: Product Verification -->
        <div id="product-step" class="workflow-step hidden">
            <div class="step-card">
                <div class="step-header">
                    <h3>Pas 2: Verificare Produs</h3>
                    <div class="step-info">
                        <span class="material-symbols-outlined">inventory_2</span>
                        <div>
                            <div><strong id="target-product-name">Product Name</strong></div>
                            <div>SKU: <span id="target-product-sku">SKU123</span></div>
                        </div>
                    </div>
                </div>
                
                <div class="scan-section" id="product-scan-section">
                    <button id="scan-product-btn" class="btn btn-primary btn-large">
                        <span class="material-symbols-outlined">qr_code_scanner</span>
                        Scanează Produsul
                    </button>
                    <button id="manual-product-btn" class="btn btn-secondary">
                        <span class="material-symbols-outlined">keyboard</span>
                        Introdu Manual
                    </button>
                </div>
                
                <div class="manual-section hidden" id="product-manual-section">
                    <label for="product-input">Codul produsului:</label>
                    <input type="text" id="product-input" class="form-control" placeholder="SKU sau Barcode">
                    <button id="verify-product-btn" class="btn btn-primary">
                        <span class="material-symbols-outlined">check</span>
                        Verifică Produsul
                    </button>
                    <button id="back-to-scan-product" class="btn btn-secondary">
                        <span class="material-symbols-outlined">qr_code_scanner</span>
                        Înapoi la Scanare
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Step 3: Quantity Input -->
        <div id="quantity-step" class="workflow-step hidden">
            <div class="step-card">
                <div class="step-header">
                    <h3>Pas 3: Cantitate Colectată</h3>
                    <div class="step-info">
                        <span class="material-symbols-outlined">scale</span>
                        <span>Necesar: <strong id="required-quantity">0</strong></span>
                    </div>
                </div>
                
                <div class="quantity-section">
                    <label for="picked-quantity-input">Cantitate colectată:</label>
                    <div class="quantity-controls">
                        <button class="qty-btn" id="qty-decrease">-</button>
                        <input type="number" id="picked-quantity-input" min="0" value="0" class="qty-input">
                        <button class="qty-btn" id="qty-increase">+</button>
                    </div>
                    <div class="quantity-actions">
                        <button id="confirm-quantity-btn" class="btn btn-success btn-large">
                            <span class="material-symbols-outlined">check_circle</span>
                            Confirmă Colectarea
                        </button>
                        <button id="back-to-product" class="btn btn-secondary">
                            <span class="material-symbols-outlined">arrow_back</span>
                            Înapoi
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scanner Container -->
        <div id="scanner-container" class="scanner-container hidden">
            <div class="scanner-header">
                <h3 id="scanner-title">Scanare</h3>
                <button id="close-scanner" class="btn btn-secondary">
                    <span class="material-symbols-outlined">close</span>
                    Închide
                </button>
            </div>
            <div id="scanner-reader"></div>
            <div class="scanner-manual">
                <button id="scanner-manual-input" class="btn btn-secondary">
                    <span class="material-symbols-outlined">keyboard</span>
                    Introdu Manual
                </button>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div id="loading-overlay" class="loading-overlay hidden">
            <div class="spinner"></div>
            <p>Se încarcă...</p>
        </div>

        <!-- Messages -->
        <div id="message-container" class="message-container"></div>

        <!-- Completion Section -->
        <div id="completion-section" class="content-section hidden">
            <div class="completion-card">
                <span class="material-symbols-outlined icon-large success">task_alt</span>
                <h2>Comandă Finalizată!</h2>
                <p>Toate articolele au fost colectate cu succes.</p>
                <div class="completion-actions">
                    <a href="warehouse_orders.php" class="btn btn-secondary">
                        <span class="material-symbols-outlined">arrow_back</span>
                        Înapoi la Comenzi
                    </a>
                    <a href="mobile_picker.php" class="btn btn-primary">
                        <span class="material-symbols-outlined">add</span>
                        Comandă Nouă
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Pass data to JavaScript -->
    <script>
        window.PICKER_CONFIG = {
            orderFromUrl: <?= json_encode($hasOrderFromUrl ? $orderNumber : null) ?>,
            hasOrderFromUrl: <?= json_encode($hasOrderFromUrl) ?>
        };
    </script>
    <script src="scripts/orders_awb.js"></script>
    <?php require_once __DIR__ . '/includes/warehouse_footer.php'; ?>
</body>
</html>