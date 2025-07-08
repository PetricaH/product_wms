<?php
require_once __DIR__ . '/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <?php require_once __DIR__ . '/includes/warehouse_header.php'; ?>
</head>
<body>
    <div class="mobile-receiving-container">
        <!-- Mobile Header -->
        <div class="mobile-header">
            <div class="header-content">
                <button class="back-btn" id="back-btn">
                    <span class="material-symbols-outlined">arrow_back</span>
                </button>
                <div class="header-title">
                    <span class="material-symbols-outlined">local_shipping</span>
                    Recepție Mobilă
                </div>
                <div class="header-actions">
                    <button class="header-btn" id="help-btn">
                        <span class="material-symbols-outlined">help</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="content-container">
                
                <!-- Workflow Progress -->
                <div class="workflow-progress">
                    <div class="progress-steps">
                        <div class="progress-step">
                            <div class="step-circle active">1</div>
                            <div class="step-label active">Scan PO</div>
                        </div>
                        <div class="progress-step">
                            <div class="step-circle">2</div>
                            <div class="step-label">Receive Items</div>
                        </div>
                        <div class="progress-step">
                            <div class="step-circle">3</div>
                            <div class="step-label">Confirm</div>
                        </div>
                    </div>
                </div>

                <!-- Step 1: Purchase Order -->
                <div id="step-1" class="workflow-section scanner-section">
                    <div class="scanner-header">
                        <h2 class="scanner-title">Scan Purchase Order</h2>
                        <p class="scanner-subtitle">Scan the PO barcode or enter manually</p>
                    </div>
                    <div class="scanner-body">
                        <!-- Camera Container -->
                        <div class="camera-container">
                            <div id="scanner-video" class="camera-video"></div>
                            <div class="camera-placeholder">
                                <span class="material-symbols-outlined camera-icon">qr_code_scanner</span>
                                <p>Camera will appear here when scanning</p>
                            </div>
                            <div class="camera-overlay"></div>
                        </div>
                        
                        <!-- Scanner Controls -->
                        <div class="scanner-controls">
                            <button id="start-scan" class="scanner-btn">
                                <span class="material-symbols-outlined">qr_code_scanner</span>
                                Start Scanner
                            </button>
                            <button id="stop-scan" class="scanner-btn secondary" style="display: none;">
                                <span class="material-symbols-outlined">stop</span>
                                Stop
                            </button>
                            <button id="switch-camera" class="scanner-btn secondary" style="display: none;">
                                <span class="material-symbols-outlined">flip_camera_android</span>
                                Switch
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Manual PO Form -->
                <div class="manual-input-form">
                    <h3 class="form-title">
                        <span class="material-symbols-outlined">edit</span>
                        Manual Entry
                    </h3>
                    <form id="po-form">
                        <div class="form-group">
                            <label for="po-number" class="form-label">PO Number *</label>
                            <input type="text" id="po-number" name="po_number" class="form-input" 
                                   placeholder="Enter PO number" required>
                        </div>
                        <div class="form-group">
                            <label for="supplier-name" class="form-label">Supplier</label>
                            <input type="text" id="supplier-name" name="supplier_name" class="form-input" 
                                   placeholder="Supplier name">
                        </div>
                        <div class="form-group">
                            <label for="expected-date" class="form-label">Expected Date</label>
                            <input type="date" id="expected-date" name="expected_date" class="form-input">
                        </div>
                        <button type="submit" class="scanner-btn">
                            <span class="material-symbols-outlined">check</span>
                            Validate PO
                        </button>
                    </form>
                </div>

                <!-- Step 2: Receive Items -->
                <div id="step-2" class="workflow-section scanner-section" style="display: none;">
                    <div class="scanner-header">
                        <h2 class="scanner-title">Receive Items</h2>
                        <p class="scanner-subtitle">Scan items or enter manually</p>
                    </div>
                    <div class="scanner-body">
                        <!-- Camera Container (reused) -->
                        <div class="camera-container">
                            <div class="camera-placeholder">
                                <span class="material-symbols-outlined camera-icon">inventory_2</span>
                                <p>Scan item barcodes</p>
                            </div>
                            <div class="camera-overlay"></div>
                        </div>
                    </div>
                </div>

                <!-- Item Receiving Form -->
                <div class="manual-input-form">
                    <h3 class="form-title">
                        <span class="material-symbols-outlined">add_box</span>
                        Add Received Item
                    </h3>
                    <form id="item-form">
                        <div class="form-group">
                            <label for="item-barcode" class="form-label">Barcode</label>
                            <input type="text" id="item-barcode" name="barcode" class="form-input" 
                                   placeholder="Scan or enter barcode">
                        </div>
                        <div class="form-group">
                            <label for="product-name" class="form-label">Product Name *</label>
                            <input type="text" id="product-name" name="product_name" class="form-input" 
                                   placeholder="Product name" required>
                        </div>
                        <div class="form-group">
                            <label for="expected-qty" class="form-label">Expected Qty</label>
                            <input type="number" id="expected-qty" name="expected_qty" class="form-input" 
                                   placeholder="0" min="0" readonly>
                        </div>
                        <div class="form-group">
                            <label for="received-qty" class="form-label">Received Qty *</label>
                            <input type="number" id="received-qty" name="received_qty" class="form-input" 
                                   placeholder="Enter quantity" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="location" class="form-label">Location *</label>
                            <select id="location" name="location" class="form-input form-select" required>
                                <option value="">Select location</option>
                                <option value="A1-01">A1-01 - Shelf A1 Level 1</option>
                                <option value="A1-02">A1-02 - Shelf A1 Level 2</option>
                                <option value="B2-01">B2-01 - Shelf B2 Level 1</option>
                                <option value="C3-01">C3-01 - Shelf C3 Level 1</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="condition" class="form-label">Condition</label>
                            <select id="condition" name="condition" class="form-input form-select">
                                <option value="good">Good</option>
                                <option value="damaged">Damaged</option>
                                <option value="defective">Defective</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea id="notes" name="notes" class="form-input" 
                                      placeholder="Optional notes" rows="2"></textarea>
                        </div>
                        <button type="submit" class="scanner-btn">
                            <span class="material-symbols-outlined">add</span>
                            Add Item
                        </button>
                    </form>
                </div>

                <!-- Received Items List -->
                <div class="received-items">
                    <div class="items-header">
                        <h3 class="items-title">Received Items</h3>
                        <div class="items-count" id="items-count">0</div>
                    </div>
                    <div class="items-list" id="received-items-list">
                        <div class="empty-state">
                            <span class="material-symbols-outlined">inventory_2</span>
                            <p>No items received yet</p>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Confirmation -->
                <div id="step-3" class="workflow-section" style="display: none;">
                    <div class="manual-input-form">
                        <h3 class="form-title">
                            <span class="material-symbols-outlined">check_circle</span>
                            Confirm Receipt
                        </h3>
                        <p>Review all received items and confirm the receipt.</p>
                        
                        <div class="summary-info">
                            <div class="summary-item">
                                <span class="summary-label">PO Number:</span>
                                <span class="summary-value" id="summary-po">-</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Total Items:</span>
                                <span class="summary-value" id="summary-items">0</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Total Quantity:</span>
                                <span class="summary-value" id="summary-qty">0</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Bottom Actions -->
        <div class="bottom-actions">
            <div class="actions-container">
                <button id="prev-step" class="action-btn secondary" style="display: none;">
                    <span class="material-symbols-outlined">arrow_back</span>
                    Previous
                </button>
                <button id="next-step" class="action-btn primary">
                    <span class="material-symbols-outlined">arrow_forward</span>
                    Next
                </button>
                <button id="save-receiving" class="action-btn secondary">
                    <span class="material-symbols-outlined">save</span>
                    Save
                </button>
                <button id="complete-receiving" class="action-btn primary" style="display: none;">
                    <span class="material-symbols-outlined">check</span>
                    Complete
                </button>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="loading-overlay" style="display: none;">
        <div class="loading-spinner"></div>
    </div>

    <!-- JavaScript Files -->
    <script src="scripts/mobile_receiving.js"></script>
    <script>
        // Pass configuration to JavaScript
        window.WMS_CONFIG = {
            baseUrl: '<?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>',
            apiBase: '<?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>/api'
        };
    </script>
<!-- Include warehouse footer (loads page-specific JS automatically) -->
    <?php require_once __DIR__ . '/includes/warehouse_footer.php'; ?>