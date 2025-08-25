/**
 * Warehouse Receiving - Production JavaScript with TimingManager Integration
 * File: scripts/warehouse-js/warehouse_receiving.js
 * 
 * Handles complete receiving workflow with supplier document matching
 * Following WMS patterns and real-world receiving process
 * Enhanced with silent timing tracking for performance analytics
 */

class WarehouseReceiving {
    constructor() {
        this.config = window.WMS_CONFIG || { baseUrl: '', apiBase: '/api' };
        this.defaultLocation = this.config.defaultLocation || (this.config.locations && this.config.locations.length ? this.config.locations[0].location_code : '');
        this.currentStep = 1;
        this.currentReceivingSession = null;
        this.selectedPurchaseOrder = null;
        this.receivingItems = [];
        this.selectedProductId = null;
        this.scanner = null;
        this.scannerActive = false;
        this.productionMode = false;
        this.labelsPrinted = false;
        this.lastPrintData = null;

        // TIMING INTEGRATION - Silent timing for performance tracking
        this.timingManager = null;
        this.activeTimingTasks = new Map(); // receiving_item_id -> task_id
        this.isTimingEnabled = window.TIMING_ENABLED || false;
        
        this.init();
    }

    init() {
        this.bindEvents();
        this.initializeCurrentStep();
        this.loadRecentSessions();
        this.initializeTiming();
        
        console.log('🚛 Warehouse Receiving initialized');
    }

    /**
     * Initialize TimingManager for silent timing
     */
    initializeTiming() {
        if (this.isTimingEnabled && window.TimingManager) {
            this.timingManager = new TimingManager('receiving');
            console.log('⏱️ Silent timing enabled for receiving operations');
        } else {
            console.log('⏱️ Timing disabled or TimingManager not available');
        }
    }

    /**
     * Start timing for a receiving item silently
     */
    async startItemTiming(receivingItem) {
        if (!this.timingManager || !this.isTimingEnabled) return;
        
        try {
            const timingData = {
                receiving_session_id: this.currentReceivingSession?.id,
                receiving_item_id: receivingItem.id,
                product_id: receivingItem.product_id,
                quantity_to_receive: receivingItem.expected_quantity,
                location_id: receivingItem.location_id
            };

            const result = await this.timingManager.startTiming(timingData);
            
            if (result.success) {
                this.activeTimingTasks.set(receivingItem.id, result.task_id);
                console.log(`⏱️ Silent timing started for item ${receivingItem.id}`);
            }
        } catch (error) {
            console.error('Error starting item timing:', error);
        }
    }

    /**
     * Complete timing for a receiving item silently
     */
    async completeItemTiming(receivingItemId, quantityReceived, notes = '') {
        if (!this.timingManager || !this.isTimingEnabled) return;
        
        const taskId = this.activeTimingTasks.get(receivingItemId);
        if (!taskId) return;
        
        try {
            const completionData = {
                quantity_received: quantityReceived,
                quality_check_notes: notes,
                discrepancy_notes: notes
            };

            const result = await this.timingManager.completeTiming(taskId, completionData);
            
            if (result.success) {
                this.activeTimingTasks.delete(receivingItemId);
                console.log(`⏱️ Silent timing completed for item ${receivingItemId}`);
            }
        } catch (error) {
            console.error('Error completing item timing:', error);
        }
    }

    bindEvents() {
        // Search purchase order by number
        const poSearchInput = document.getElementById('po-search-input');
        if (poSearchInput) {
            poSearchInput.addEventListener('input', (e) => this.quickSearchPO(e.target.value));
        }

        // Complete receiving
        const completeBtn = document.getElementById('complete-receiving-btn');
        if (completeBtn) {
            completeBtn.addEventListener('click', () => this.completeReceiving());
        }

        // Scanner controls
        const scanBarcodeBtn = document.getElementById('scan-barcode-btn');
        const startScannerBtn = document.getElementById('start-scanner');
        const stopScannerBtn = document.getElementById('stop-scanner');

        if (scanBarcodeBtn) {
            scanBarcodeBtn.addEventListener('click', () => this.openScannerModal());
        }
        if (startScannerBtn) {
            startScannerBtn.addEventListener('click', () => this.startScanner());
        }
        if (stopScannerBtn) {
            stopScannerBtn.addEventListener('click', () => this.stopScanner());
        }

        // Close modal on outside click
        window.addEventListener('click', (e) => {
            const scannerModal = document.getElementById('scanner-modal');
            if (e.target === scannerModal) {
                this.closeScannerModal();
            }
        });

        const toggleBtn = document.getElementById('toggle-production');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => this.toggleProductionMode());
        }

        const prodSearch = document.getElementById('prod-search-input');
        if (prodSearch) {
            prodSearch.addEventListener('input', (e) => this.searchProducts(e.target.value));
        }

        const printBtn = document.getElementById('print-labels-btn');
        if (printBtn) {
            printBtn.addEventListener('click', () => this.printProductionLabels());
        }

        const addStockBtn = document.getElementById('add-stock-btn');
        if (addStockBtn) {
            addStockBtn.addEventListener('click', () => this.addProductionStock());
        }
    }

    initializeCurrentStep() {
        this.showStep(this.currentStep);
    }

    showStep(stepNumber) {
        // Hide all steps
        document.querySelectorAll('.step-section').forEach(step => {
            step.classList.remove('active');
        });
        
        // Show current step
        const currentStepElement = document.getElementById(`step-${stepNumber}`);
        if (currentStepElement) {
            currentStepElement.classList.add('active');
            this.currentStep = stepNumber;
        }
    }

    toggleProductionMode() {
        this.productionMode = !this.productionMode;
        const prodSection = document.getElementById('production-section');
        const step1 = document.getElementById('step-1');
        const step3 = document.getElementById('step-3');
        const step4 = document.getElementById('step-4');
        const toggleBtn = document.getElementById('toggle-production');

        if (this.productionMode) {
            if (prodSection) prodSection.style.display = 'block';
            if (step1) step1.style.display = 'none';
            if (step3) step3.style.display = 'none';
            if (step4) step4.style.display = 'none';
            if (toggleBtn) toggleBtn.textContent = 'Recepție Comandă';
            this.initProductionDefaults();
        } else {
            if (prodSection) prodSection.style.display = 'none';
            if (step1) step1.style.display = '';
            if (step3) step3.style.display = '';
            if (step4) step4.style.display = '';
            if (toggleBtn) toggleBtn.textContent = 'Recepție Producție';
        }
    }

    initProductionDefaults() {
        const batchInput = document.getElementById('prod-batch-number');
        const dateInput = document.getElementById('prod-date');
        if (batchInput) {
            batchInput.value = `PRD-${Date.now()}`;
        }
        if (dateInput) {
            const now = new Date();
            dateInput.value = now.toISOString().slice(0,16);
        }
        this.selectedProductId = null;
        const searchRes = document.getElementById('prod-search-results');
        if (searchRes) searchRes.innerHTML = '';
        const searchInput = document.getElementById('prod-search-input');
        if (searchInput) searchInput.value = '';
    }

    async quickSearchPO(query) {
        if (!query) {
            const container = document.getElementById('po-search-results');
            if (container) container.innerHTML = '';
            return;
        }

        try {
            const response = await fetch(`${this.config.apiBase}/receiving/quick_search_purchase_orders.php?q=${encodeURIComponent(query)}`);
            const result = await response.json();

            if (response.ok && result.orders) {
                this.displayPurchaseOrders(result.orders, 'po-search-results');
            }
        } catch (error) {
            console.error('Quick search error:', error);
        }
    }

    displayPurchaseOrders(purchaseOrders, containerId = 'purchase-orders-list') {
        const container = document.getElementById(containerId);
        if (!container) return;

        container.innerHTML = purchaseOrders.map(po => `
            <div class="purchase-order-item" data-po-id="${po.id}" onclick="receivingSystem.startReceivingFromSearch(${po.id})">
                <div class="po-header">
                    <div class="po-number">${this.escapeHtml(po.order_number)}</div>
                    <div class="po-status status-${po.status}">
                        ${this.getStatusText(po.status)}
                    </div>
                </div>
                <div class="po-details">
                    <div class="po-detail">
                        <div class="po-detail-label">Furnizor</div>
                        <div class="po-detail-value">${this.escapeHtml(po.supplier_name)}</div>
                    </div>
                    <div class="po-detail">
                        <div class="po-detail-label">Valoare Totală</div>
                        <div class="po-detail-value">${po.total_amount} ${po.currency}</div>
                    </div>
                    <div class="po-detail">
                        <div class="po-detail-label">Data Livrării</div>
                        <div class="po-detail-value">${po.expected_delivery_date || 'N/A'}</div>
                    </div>
                    <div class="po-detail">
                        <div class="po-detail-label">Produse</div>
                        <div class="po-detail-value">${po.items_count} articole</div>
                    </div>
                </div>
            </div>
        `).join('');
    }

    async searchProducts(query) {
        if (!query) {
            const c = document.getElementById('prod-search-results');
            if (c) c.innerHTML = '';
            return;
        }
        try {
            const resp = await fetch(`${this.config.apiBase}/products.php?search=${encodeURIComponent(query)}`);
            const data = await resp.json();
            if (resp.ok) {
                const products = Array.isArray(data) ? data : data.data;
                if (Array.isArray(products)) {
                    this.displayProducts(products);
                }
            }
        } catch (err) {
            console.error('Product search error:', err);
        }
    }

    displayProducts(products) {
        const container = document.getElementById('prod-search-results');
        if (!container) return;
        
        container.innerHTML = products.map(p => `
            <div class="purchase-order-item" data-product-id="${p.id}" data-product-name="${this.escapeHtml(p.name)}">
                <div class="po-header">
                    <div class="po-number">${this.escapeHtml(p.sku || p.code)}</div>
                </div>
                <div class="po-details">${this.escapeHtml(p.name)}</div>
            </div>
        `).join('');
        
        // Add event listener for product selection
        container.querySelectorAll('.purchase-order-item').forEach(item => {
            item.addEventListener('click', () => {
                const productId = item.getAttribute('data-product-id');
                const productName = item.getAttribute('data-product-name');
                this.selectProduct(productId, productName);
            });
        });
    }

    selectProduct(id, name) {
        this.selectedProductId = id;
        const searchInput = document.getElementById('prod-search-input');
        if (searchInput) searchInput.value = name;
        const container = document.getElementById('prod-search-results');
        if (container) container.innerHTML = '';
    }

    async printProductionLabels() {
        if (!this.productionMode) return;
        
        const qty = parseInt(document.getElementById('prod-qty').value) || 0;
        const batch = document.getElementById('prod-batch-number').value;
        const date = document.getElementById('prod-date').value;
        
        if (!this.selectedProductId || qty <= 0) {
            this.showError('Selectează produsul și cantitatea');
            return;
        }
        

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content') || this.config.csrfToken;

            // Generate label preview first
            const previewData = new FormData();
            previewData.append('product_id', this.selectedProductId);
            previewData.append('quantity', qty);
            previewData.append('batch_number', batch);
            previewData.append('produced_at', date);
            previewData.append('source', 'factory');
            previewData.append('action', 'preview');

            const previewResp = await fetch(`${this.config.apiBase}/receiving/record_production_receipt.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': csrfToken },
                body: previewData
            });

            const previewResult = await previewResp.json();
            if (!previewResp.ok || !previewResult.success || !previewResult.label_url) {
                throw new Error(previewResult.message || 'Eroare la generarea etichetei');
            }

            window.open(previewResult.label_url, '_blank');
            if (!confirm('Trimitem eticheta la imprimantă?')) return;

            const printerName = await chooseLabelPrinter();
            if (!printerName) return;

            for (let i = 0; i < qty; i++) {
                const formData = new FormData();
                formData.append('product_id', this.selectedProductId);
                formData.append('quantity', qty);
                formData.append('batch_number', batch);
                formData.append('produced_at', date);
                formData.append('printer', printerName);
                formData.append('source', 'factory');
                formData.append('action', 'print');

                const desc = document.getElementById('prod-photo-description');
                if (desc && desc.value.trim()) {
                    formData.append('photo_description', desc.value.trim());
                }

                const photos = document.getElementById('prod-photos');
                if (photos && photos.files.length) {
                    Array.from(photos.files).forEach(f => formData.append('photos[]', f));
                }

                console.log('Sending production receipt data');

                const response = await fetch(`${this.config.apiBase}/receiving/record_production_receipt.php`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData
                });

                const result = await response.json();
                console.log('API Response:', result); // Debug logging

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Eroare la imprimare');
                }
            }

            this.showSuccess('Etichetele au fost trimise la imprimantă');
            this.labelsPrinted = true;
            this.lastPrintData = { product_id: this.selectedProductId, quantity: qty, batch_number: batch, produced_at: date };
            const addBtn = document.getElementById('add-stock-btn');
            if (addBtn) {
                addBtn.style.display = 'inline-block';
                addBtn.disabled = false;
            }
        } catch (err) {
            console.error('Print error:', err);
            this.showError('Eroare: ' + err.message);
        }
    }

    async addProductionStock() {
        if (!this.labelsPrinted || !this.lastPrintData) {
            return;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content') || this.config.csrfToken;

        try {
            const formData = new FormData();
            Object.entries(this.lastPrintData).forEach(([k, v]) => formData.append(k, v));
            formData.append('action', 'add_stock');

            const response = await fetch(`${this.config.apiBase}/receiving/record_production_receipt.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                body: formData
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Eroare la adăugarea în stoc');
            }

            this.showSuccess(result.message || 'Produs adăugat în stoc');
            this.initProductionDefaults();
            const addBtn = document.getElementById('add-stock-btn');
            if (addBtn) {
                addBtn.disabled = true;
                addBtn.style.display = 'none';
            }
            this.labelsPrinted = false;
            this.lastPrintData = null;
        } catch (err) {
            console.error('Add stock error:', err);
            this.showError('Eroare: ' + err.message);
        }
    }
    
    async startReceivingFromSearch(poId) {
        this.showLoading(true);

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || this.config.csrfToken;
            const response = await fetch(`${this.config.apiBase}/receiving/start_session.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    purchase_order_id: poId
                })
            });

            const result = await response.json();

            if (!response.ok) {
                if (result.error_type === 'existing_session') {
                    this.handleExistingSession(result.existing_session);
                    return;
                }
                throw new Error(result.message || 'Eroare la începerea recepției');
            }

            this.currentReceivingSession = result.session;
            this.loadReceivingItems();
            this.updateReceivingSummary();
            this.showStep(3);

        } catch (error) {
            console.error('Error starting receiving session:', error);
            this.showError('Eroare la începerea recepției: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }

    handleExistingSession(existingSession) {
        const canResume = existingSession.can_resume;
        const sessionInfo = `
            <div style="margin-bottom: 1rem;">
                <strong>Sesiune Existentă:</strong> ${existingSession.session_number}<br>
                <strong>Document:</strong> ${existingSession.supplier_document_number}<br>
                <strong>Creat la:</strong> ${new Date(existingSession.created_at).toLocaleString('ro-RO')}<br>
                <strong>Creat de:</strong> ${existingSession.received_by_name}
            </div>
        `;

        if (canResume) {
            this.showConfirmDialog(
                'Sesiune Activă Găsită',
                sessionInfo + 'Există deja o sesiune de recepție activă pentru această comandă. Dorești să continui sesiunea existentă?',
                [
                    {
                        text: 'Continuă Sesiunea',
                        action: () => this.resumeSession(existingSession.id),
                        primary: true
                    },
                    {
                        text: 'Anulează',
                        action: () => {},
                        primary: false
                    }
                ]
            );
        } else {
            this.showError(
                'Există o sesiune activă pentru această comandă creată de ' + existingSession.received_by_name + 
                '. Contactează un manager pentru a prelua sesiunea.'
            );
        }
    }

    async resumeSession(sessionId) {
        this.showLoading(true);
        
        try {
            // Load the existing session
            const response = await fetch(`${this.config.apiBase}/receiving/get_expected_items.php?session_id=${sessionId}`);
            const result = await response.json();
            
            if (!response.ok) {
                throw new Error(result.message || 'Eroare la încărcarea sesiunii');
            }

            this.currentReceivingSession = result.session;
            this.receivingItems = result.items || [];
            this.displayReceivingItems();
            this.updateReceivingSummary();
            this.showStep(3);
            this.showSuccess('Sesiune reluată cu succes');
            
        } catch (error) {
            console.error('Error resuming session:', error);
            this.showError('Eroare la reluarea sesiunii: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }

    async loadReceivingItems() {
        if (!this.currentReceivingSession) return;
        
        try {
            const response = await fetch(`${this.config.apiBase}/receiving/get_expected_items.php?session_id=${this.currentReceivingSession.id}`);
            const result = await response.json();
            
            if (response.ok) {
                this.receivingItems = result.items || [];
                this.displayReceivingItems();
            }
            
        } catch (error) {
            console.error('Error loading receiving items:', error);
        }
    }

    displayReceivingItems() {
        const container = document.getElementById('expected-items-list');
        if (!container) return;

        container.innerHTML = this.receivingItems.map(item => {
            const selectedLoc = item.location_code || this.defaultLocation;
            return `
            <div class="expected-item" data-item-id="${item.id}">
                <div class="item-header">
                    <div>
                        <div class="item-name">${this.escapeHtml(item.product_name)}</div>
                        <div class="item-sku">SKU: ${this.escapeHtml(item.sku)}</div>
                    </div>
                    <div class="item-status status-${item.status || 'pending'}">
                        ${this.getItemStatusText(item.status || 'pending')}
                    </div>
                </div>
                <div class="item-receiving-form">
                    <div class="form-group">
                        <label class="form-label">Cantitate Așteptată</label>
                        <input type="number" class="quantity-input" value="${item.expected_quantity}" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cantitate Primită</label>
                        <input type="number" class="quantity-input" id="received-qty-${item.id}" 
                               value="${item.received_quantity || 0}" min="0" step="0.001">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Locație</label>
                        <select class="location-select" id="location-${item.id}">
                            <option value="">Selectează locația</option>
                            ${this.getLocationOptions(selectedLoc)}
                        </select>
                    </div>
                    <button type="button" class="receive-item-btn" onclick="receivingSystem.receiveItem(${item.id})">
                        <span class="material-symbols-outlined">check</span>
                        Primește
                    </button>
                </div>
            </div>
        `}).join('');
    }

    getLocationOptions(selectedCode = '') {
        const locations = this.config.locations || [];
        return locations.map(loc =>
            `<option value="${loc.location_code}" ${loc.location_code === selectedCode ? 'selected' : ''}>${loc.location_code} (${loc.zone})</option>`
        ).join('');
    }

    async receiveItem(itemId) {
        const receivedQtyInput = document.getElementById(`received-qty-${itemId}`);
        const locationSelect = document.getElementById(`location-${itemId}`);
        
        if (!receivedQtyInput || !locationSelect) return;
        
        const receivedQty = parseFloat(receivedQtyInput.value) || 0;
        const locationId = locationSelect.value;
        
        if (receivedQty <= 0) {
            this.showError('Cantitatea primită trebuie să fie mai mare decât 0');
            return;
        }
        
        if (!locationId) {
            this.showError('Selectează o locație pentru produs');
            return;
        }

        // Find the item to start timing
        const item = this.receivingItems.find(i => i.id == itemId);
        if (item) {
            await this.startItemTiming(item);
        }

        this.showLoading(true);
        
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || this.config.csrfToken;
            const response = await fetch(`${this.config.apiBase}/receiving/receive_item.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    session_id: this.currentReceivingSession.id,
                    item_id: itemId,
                    received_quantity: receivedQty,
                    location_id: locationId
                })
            });

            const result = await response.json();
            
            if (!response.ok) {
                throw new Error(result.message || 'Eroare la primirea produsului');
            }

            // Complete timing silently
            await this.completeItemTiming(itemId, receivedQty);

            // Update item status
            this.updateItemStatus(itemId, result.status);
            this.updateReceivingSummary();
            this.showSuccess('Produs primit cu succes');
            
        } catch (error) {
            console.error('Error receiving item:', error);
            this.showError('Eroare la primirea produsului: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }

    updateItemStatus(itemId, status) {
        const itemElement = document.querySelector(`[data-item-id="${itemId}"]`);
        if (!itemElement) return;
        
        const statusElement = itemElement.querySelector('.item-status');
        if (statusElement) {
            statusElement.className = `item-status status-${status}`;
            statusElement.textContent = this.getItemStatusText(status);
        }
        
        // Update the item in our array
        const item = this.receivingItems.find(i => i.id == itemId);
        if (item) {
            item.status = status;
        }
    }

    updateReceivingSummary() {
        if (!this.currentReceivingSession) return;
        
        const poNumberEl = document.getElementById('current-po-number');
        const supplierEl = document.getElementById('current-supplier');
        const itemsReceivedEl = document.getElementById('items-received');
        const itemsExpectedEl = document.getElementById('items-expected');
        
        if (poNumberEl) poNumberEl.textContent = this.currentReceivingSession.po_number || '-';
        if (supplierEl) supplierEl.textContent = this.currentReceivingSession.supplier_name || '-';
        
        const receivedCount = this.receivingItems.filter(item => item.status === 'received').length;
        const expectedCount = this.receivingItems.length;
        
        if (itemsReceivedEl) itemsReceivedEl.textContent = receivedCount;
        if (itemsExpectedEl) itemsExpectedEl.textContent = expectedCount;
    }

    async completeReceiving() {
        if (!this.currentReceivingSession) {
            this.showError('Nicio sesiune de recepție activă');
            return;
        }

        const receivedCount = this.receivingItems.filter(item => item.status === 'received').length;
        const expectedCount = this.receivingItems.length;
        
        if (receivedCount === 0) {
            this.showError('Trebuie să primești cel puțin un produs');
            return;
        }

        if (receivedCount < expectedCount) {
            const confirmed = confirm(`Ai primit doar ${receivedCount} din ${expectedCount} produse. Vrei să finalizezi recepția?`);
            if (!confirmed) return;
        }

        this.showLoading(true);
        
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || this.config.csrfToken;

            const formData = new FormData();
            formData.append('session_id', this.currentReceivingSession.id);
            formData.append('source', 'sellers');

            const desc = document.getElementById('receiving-photo-description');

            const photosInput = document.getElementById('receiving-photos');
            if (photosInput && photosInput.files.length) {
                Array.from(photosInput.files).forEach(file => formData.append('photos[]', file));
            if (desc && desc.value.trim()) {
                    formData.append('photo_description', desc.value.trim());
                }
            }

            const response = await fetch(`${this.config.apiBase}/receiving/complete_session.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                body: formData
            });

            const result = await response.json();
            
            if (!response.ok) {
                throw new Error(result.message || 'Eroare la finalizarea recepției');
            }

            this.displayCompletionSummary(result.summary);
            this.showStep(4);
            this.showSuccess('Recepția a fost finalizată cu succes');
            
            // Trigger dashboard refresh if timing is enabled
            if (this.timingManager) {
                this.timingManager.triggerDashboardRefresh();
            }
            
        } catch (error) {
            console.error('Error completing receiving:', error);
            this.showError('Eroare la finalizarea recepției: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }

    displayCompletionSummary(summary) {
        const container = document.getElementById('completion-details');
        if (!container || !summary) return;

        container.innerHTML = `
            <div class="summary-item">
                <span class="summary-label">Număr Sesiune:</span>
                <span class="summary-value">${this.escapeHtml(summary.session_number)}</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Produse Primite:</span>
                <span class="summary-value">${summary.items_received} / ${summary.items_expected}</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Discrepanțe:</span>
                <span class="summary-value">${summary.discrepancies || 0}</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Finalizat la:</span>
                <span class="summary-value">${new Date(summary.completed_at).toLocaleString('ro-RO')}</span>
            </div>
        `;
    }

    async loadRecentSessions() {
        try {
            const response = await fetch(`${this.config.apiBase}/receiving/recent_sessions.php`);
            const result = await response.json();
            
            if (response.ok && result.sessions) {
                this.updateRecentSessions(result.sessions);
            }
            
        } catch (error) {
            console.error('Error loading recent sessions:', error);
        }
    }

    updateRecentSessions(sessions) {
        const container = document.querySelector('.recent-sessions');
        if (!container) return;

        if (sessions.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <span class="material-symbols-outlined">inbox</span>
                    <p>Nicio recepție înregistrată</p>
                </div>
            `;
            return;
        }

        container.innerHTML = sessions.map(session => `
            <div class="session-item" onclick="receivingSystem.viewSession('${session.id}')">
                <div class="session-header">
                    <span class="session-number">${this.escapeHtml(session.session_number)}</span>
                    <span class="session-status status-${session.status}">
                        ${this.getStatusText(session.status)}
                    </span>
                </div>
                <div class="session-details">
                    <div class="session-supplier">${this.escapeHtml(session.supplier_name || 'N/A')}</div>
                    <div class="session-date">${new Date(session.created_at).toLocaleString('ro-RO')}</div>
                </div>
            </div>
        `).join('');
    }

    viewSession(sessionId) {
        this.resumeSession(sessionId);
    }

    // Scanner functionality
    openScannerModal() {
        const modal = document.getElementById('scanner-modal');
        if (modal) {
            modal.style.display = 'block';
        }
    }

    closeScannerModal() {
        const modal = document.getElementById('scanner-modal');
        if (modal) {
            modal.style.display = 'none';
        }
        this.stopScanner();
    }

    async startScanner() {
        if (this.scannerActive) return;
        
        try {
            // Initialize scanner (would use QuaggaJS or similar in production)
            const scannerContainer = document.getElementById('scanner-container');
            if (scannerContainer) {
                scannerContainer.innerHTML = `
                    <div style="text-align: center; padding: 2rem; color: var(--light-gray);">
                        <div style="font-size: 2rem; margin-bottom: 1rem;">📷</div>
                        <p>Scanner simulat - introdu barcode manual:</p>
                        <input type="text" id="manual-barcode" placeholder="Scanează sau introdu barcode" 
                               style="padding: 0.5rem; margin: 1rem; width: 200px; text-align: center;">
                        <button onclick="receivingSystem.processBarcode(document.getElementById('manual-barcode').value)" 
                                style="padding: 0.5rem 1rem; background: var(--success); color: white; border: none; border-radius: 4px;">
                            Procesează
                        </button>
                    </div>
                `;
            }
            
            this.scannerActive = true;
            
        } catch (error) {
            console.error('Error starting scanner:', error);
            this.showError('Eroare la pornirea scannerului');
        }
    }

    stopScanner() {
        this.scannerActive = false;
        const scannerContainer = document.getElementById('scanner-container');
        if (scannerContainer) {
            scannerContainer.innerHTML = `
                <div id="scanner-placeholder" class="scanner-placeholder">
                    <span class="material-symbols-outlined">qr_code_scanner</span>
                    <p>Apasă "Pornește Scanner" pentru a scana</p>
                </div>
            `;
        }
    }

    processBarcode(barcode) {
        if (!barcode) return;
        
        // Find product by barcode and auto-fill receiving form
        const item = this.receivingItems.find(item => item.barcode === barcode);
        if (item) {
            const receivedQtyInput = document.getElementById(`received-qty-${item.id}`);
            if (receivedQtyInput) {
                receivedQtyInput.focus();
                receivedQtyInput.select();
            }
            this.closeScannerModal();
            this.showSuccess(`Produs găsit: ${item.product_name}`);
        } else {
            this.showError('Produsul nu a fost găsit în lista de așteptare');
        }
    }

    // Navigation functions
    goToPreviousStep() {
        if (this.currentStep > 1) {
            this.showStep(this.currentStep - 1);
        }
    }

    startNewReceiving() {
        this.currentReceivingSession = null;
        this.selectedPurchaseOrder = null;
        this.receivingItems = [];
        
        const searchInput = document.getElementById('po-search-input');
        if (searchInput) {
            searchInput.value = '';
        }
        const results = document.getElementById('po-search-results');
        if (results) {
            results.innerHTML = '';
        }
        
        this.showStep(1);
    }

    viewReceivingHistory() {
        window.location.href = `${this.config.baseUrl}/receiving_history.php`;
    }

    // Utility functions
    showLoading(show) {
        const overlay = document.getElementById('loading-overlay');
        if (overlay) {
            overlay.style.display = show ? 'flex' : 'none';
        }
    }

    showSuccess(message) {
        this.showMessage(message, 'success');
    }

    showError(message) {
        this.showMessage(message, 'error');
    }

    showMessage(message, type) {
        // Create alert element
        const alertEl = document.createElement('div');
        alertEl.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-floating`;
        alertEl.innerHTML = `
            <span class="material-symbols-outlined">
                ${type === 'success' ? 'check_circle' : 'error'}
            </span>
            ${this.escapeHtml(message)}
        `;

        // Display at top of screen
        document.body.appendChild(alertEl);

        // Auto-hide after 3 seconds
        setTimeout(() => {
            if (alertEl.parentNode) {
                alertEl.parentNode.removeChild(alertEl);
            }
        }, 3000);
    }

    getStatusText(status) {
        const statusMap = {
            'draft': 'Schiță',
            'sent': 'Trimis',
            'confirmed': 'Confirmat',
            'partial_delivery': 'Livrare Parțială',
            'delivered': 'Livrat',
            'in_progress': 'În Progres',
            'completed': 'Finalizat',
            'cancelled': 'Anulat'
        };
        return statusMap[status] || status;
    }

    getItemStatusText(status) {
        const statusMap = {
            'pending': 'În Așteptare',
            'received': 'Primit',
            'partial': 'Parțial'
        };
        return statusMap[status] || status;
    }

    showConfirmDialog(title, message, buttons) {
        // Create modal HTML
        const modalHtml = `
            <div class="modal" id="confirm-modal" style="display: flex;">
                <div class="modal-content" style="max-width: 500px;">
                    <div class="modal-header">
                        <h3>${this.escapeHtml(title)}</h3>
                    </div>
                    <div class="modal-body">
                        <div style="color: var(--white); line-height: 1.6;">
                            ${message}
                        </div>
                        <div style="display: flex; gap: 1rem; margin-top: 2rem; justify-content: flex-end;">
                            ${buttons.map((btn, index) => `
                                <button type="button" 
                                        class="btn ${btn.primary ? 'btn-primary' : 'btn-secondary'}" 
                                        onclick="receivingSystem.handleConfirmAction(${index})">
                                    ${this.escapeHtml(btn.text)}
                                </button>
                            `).join('')}
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remove existing modal if any
        const existingModal = document.getElementById('confirm-modal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Add modal to document
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Store button actions for later use
        this.confirmDialogActions = buttons.map(btn => btn.action);
    }

    handleConfirmAction(buttonIndex) {
        const modal = document.getElementById('confirm-modal');
        if (modal) {
            modal.remove();
        }
        
        if (this.confirmDialogActions && this.confirmDialogActions[buttonIndex]) {
            this.confirmDialogActions[buttonIndex]();
        }
        
        this.confirmDialogActions = null;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.receivingSystem = new WarehouseReceiving();

    // Auto-hide any pre-rendered alerts
    const existingAlert = document.querySelector('.alert-floating');
    if (existingAlert) {
        setTimeout(() => {
            if (existingAlert.parentNode) {
                existingAlert.parentNode.removeChild(existingAlert);
            }
        }, 3000);
    }
});

// Global functions for onclick handlers
function goToPreviousStep() {
    if (window.receivingSystem) {
        window.receivingSystem.goToPreviousStep();
    }
}

function startNewReceiving() {
    if (window.receivingSystem) {
        window.receivingSystem.startNewReceiving();
    }
}

function viewReceivingHistory() {
    if (window.receivingSystem) {
        window.receivingSystem.viewReceivingHistory();
    }
}

function closeScannerModal() {
    if (window.receivingSystem) {
        window.receivingSystem.closeScannerModal();
    }
}

async function chooseLabelPrinter() {
    try {
        const resp = await fetch('api/printer_management.php?path=printers');
        const printers = await resp.json();
        const labels = printers.filter(p => p.printer_type === 'label' && p.is_active);
        if (labels.length === 0) {
            alert('Nicio imprimantă de etichete disponibilă');
            return null;
        }
        if (labels.length === 1) {
            return labels[0].network_identifier;
        }
        const choice = prompt('Selectează imprimanta:\n' + labels.map((p,i) => `${i+1}: ${p.name}`).join('\n'), '1');
        if (choice === null) return null;
        const index = parseInt(choice, 10) - 1;
        if (isNaN(index) || index < 0 || index >= labels.length) return null;
        return labels[index].network_identifier;
    } catch (err) {
        console.error('Printer fetch error', err);
        alert('Eroare la încărcarea imprimantelor');
        return null;
    }
}