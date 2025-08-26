<?php
// Mobile Returns Processing Interface
// Follows mobile picker workflow structure
$currentPage = 'mobile_returns';
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/warehouse_header.php'; ?>
</head>
<body>
    <div class="returns-header">
        <h1>
            <span class="material-symbols-outlined">assignment_return</span>
            Procesare Returnări
        </h1>
        <div id="progress-container" class="progress-container">
            <div id="progress-bar" class="progress-bar"></div>
        </div>
    </div>

    <div id="offline-banner" class="offline-banner hidden">
        Offline - datele vor fi sincronizate când conexiunea revine.
    </div>

    <!-- Step 1: Return Lookup -->
    <section id="lookup-step" class="step">
        <div class="card">
            <h2>Căutare Returnare</h2>
            <div class="input-group">
                <input type="text" id="order-input" placeholder="Număr comandă" />
                <button id="order-scan-btn" class="btn btn-primary">
                    <span class="material-symbols-outlined">qr_code_scanner</span>
                    Scanare
                </button>
            </div>
            <button id="lookup-btn" class="btn btn-success">Încarcă Comanda</button>
            <div id="lookup-error" class="error-message hidden"></div>
        </div>
    </section>

    <!-- Step 2: Original Order Summary -->
    <section id="summary-step" class="step hidden">
        <div class="card">
            <h2>Comanda Originală</h2>
            <div id="order-summary" class="order-summary"></div>
            <button id="start-verification-btn" class="btn btn-primary">Începe Verificarea</button>
        </div>
    </section>

    <!-- Step 3: Item Verification -->
    <section id="verification-step" class="step hidden">
        <div class="card">
            <h2>Verificare Produse</h2>
            <div id="item-info" class="item-info"></div>
            <div class="scan-options">
                <button id="item-scan-btn" class="btn btn-primary btn-large">
                    <span class="material-symbols-outlined">qr_code_scanner</span>
                    Scanează Produsul
                </button>
                <button id="item-manual-btn" class="btn btn-secondary">Introdu Manual</button>
            </div>
            <div id="condition-select" class="hidden">
                <label for="item-condition">Condiție:</label>
                <select id="item-condition" class="form-control">
                    <option value="good">Bun</option>
                    <option value="damaged">Deteriorat</option>
                </select>
                <button id="confirm-item-btn" class="btn btn-success">Confirmă Produs</button>
            </div>
            <div id="verification-error" class="error-message hidden"></div>
        </div>
    </section>

    <!-- Step 4: Discrepancy Resolution -->
    <section id="discrepancy-step" class="step hidden">
        <div class="card">
            <h2>Rezolvare Discrepanțe</h2>
            <div id="discrepancy-list" class="discrepancy-list"></div>
            <button id="complete-return-btn" class="btn btn-primary">Finalizează Returnarea</button>
        </div>
    </section>

    <!-- Step 5: Completion Summary -->
    <section id="completion-step" class="step hidden">
        <div class="card">
            <h2>Sumar Returnare</h2>
            <div id="completion-details" class="completion-details"></div>
            <button id="new-return-btn" class="btn btn-secondary">Procesează Altă Returnare</button>
        </div>
    </section>

    <!-- Scanner Modal -->
    <div id="scanner-modal" class="modal hidden">
        <div class="modal-content">
            <div id="scanner-container"></div>
            <button id="close-scanner-btn" class="btn btn-secondary">Închide</button>
        </div>
    </div>

    <script src="scripts/mobile_returns.js"></script>
</body>
</html>
