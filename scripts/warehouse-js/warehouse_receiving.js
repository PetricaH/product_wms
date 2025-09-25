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
        this.productionMode = false;
        this.labelsPrinted = false;
        this.lastPrintData = null;
        this.barcodeDecisionModal = null;
        this.barcodeDecisionResolve = null;
        this.barcodeDecisionKeyHandler = null;
        this.activeBarcodeTask = null;
        this.barcodeCaptureSession = null;
        this.locationSuggestionCache = new Map();

        this.activeTab = 'standard';
        this.returnSearchAbortController = null;

        // TIMING INTEGRATION - Silent timing for performance tracking
        this.timingManager = null;
        this.activeTimingTasks = new Map(); // receiving_item_id -> task_id
        this.isTimingEnabled = window.TIMING_ENABLED || false;
        
        this.init();
    }

    init() {
        this.bindEvents();
        this.initializeTabs();
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

        if (scanBarcodeBtn) {
            scanBarcodeBtn.addEventListener('click', () => {
                const targetUrl = `${this.config.baseUrl}/warehouse_barcode_tasks.php`;
                window.open(targetUrl, '_blank');
            });
        }

        // Close modal on outside click
        window.addEventListener('click', (e) => {
            const scannerModal = document.getElementById('scanner-modal');
            if (e.target === scannerModal) {
                this.closeScannerModal();
            }
            const decisionModal = document.getElementById('barcode-decision-modal');
            if (e.target === decisionModal) {
                this.cancelBarcodeDecision();
            }
        });

        this.barcodeDecisionModal = document.getElementById('barcode-decision-modal');
        if (this.barcodeDecisionModal) {
            this.barcodeDecisionModal.querySelectorAll('[data-barcode-choice]').forEach(btn => {
                btn.addEventListener('click', (event) => {
                    const choice = event.currentTarget.getAttribute('data-barcode-choice');
                    this.handleBarcodeDecisionChoice(choice);
                });
            });
        }

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

        const previewBtn = document.getElementById('preview-label-btn');
        if (previewBtn) {
            previewBtn.addEventListener('click', () => this.previewProductionLabel());
        }

        const addStockBtn = document.getElementById('add-stock-btn');
        if (addStockBtn) {
            addStockBtn.addEventListener('click', () => this.addProductionStock());
        }

        const returnSearchInput = document.getElementById('return-company-search');
        if (returnSearchInput) {
            const handleSearch = this.debounce((value) => this.searchReturnOrders(value), 350);
            returnSearchInput.addEventListener('input', (event) => handleSearch(event.target.value));
            returnSearchInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    this.searchReturnOrders(event.target.value);
                }
            });
        }
    }

    initializeTabs() {
        const tabButtons = document.querySelectorAll('[data-receiving-tab]');
        const panels = document.querySelectorAll('.receiving-tab-panel');

        if (!tabButtons.length || !panels.length) {
            return;
        }

        tabButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const target = button.getAttribute('data-receiving-tab');
                if (!target || target === this.activeTab) {
                    return;
                }

                this.activeTab = target;

                tabButtons.forEach((btn) => {
                    btn.classList.toggle('active', btn === button);
                });

                panels.forEach((panel) => {
                    const panelKey = panel.getAttribute('data-receiving-panel');
                    panel.classList.toggle('active', panelKey === target);
                });

                if (target === 'returns') {
                    const input = document.getElementById('return-company-search');
                    if (input) {
                        input.focus();
                        if (input.value.trim().length > 0) {
                            this.searchReturnOrders(input.value);
                        }
                    }
                }
            });
        });
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

    async searchReturnOrders(query) {
        const container = document.getElementById('return-orders-results');
        if (!container) {
            return;
        }

        const trimmedQuery = (query || '').trim();

        if (!trimmedQuery) {
            container.innerHTML = `
                <div class="empty-state">
                    <span class="material-symbols-outlined">travel_explore</span>
                    <p>Introduceți numele unei companii pentru a vedea comenzile pregătite pentru retur.</p>
                </div>
            `;
            return;
        }

        if (trimmedQuery.length < 2) {
            container.innerHTML = `
                <div class="empty-state">
                    <span class="material-symbols-outlined">info</span>
                    <p>Introduceți cel puțin 2 caractere pentru a căuta o companie.</p>
                </div>
            `;
            return;
        }

        this.showReturnSearchLoading();

        if (this.returnSearchAbortController) {
            this.returnSearchAbortController.abort();
        }

        this.returnSearchAbortController = new AbortController();

        try {
            const response = await fetch(`${this.config.apiBase}/warehouse/search_picked_orders.php?company=${encodeURIComponent(trimmedQuery)}`, {
                signal: this.returnSearchAbortController.signal
            });
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || 'Nu am putut căuta comenzile pregătite.');
            }

            const orders = Array.isArray(result.orders) ? result.orders : [];
            this.renderReturnOrders(orders, trimmedQuery);
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            console.error('Return search error:', error);
            container.innerHTML = `
                <div class="return-orders-error">
                    ${this.escapeHtml(error.message || 'A apărut o eroare la încărcarea comenzilor de retur.')}
                </div>
            `;
        } finally {
            this.returnSearchAbortController = null;
        }
    }

    showReturnSearchLoading() {
        const container = document.getElementById('return-orders-results');
        if (!container) {
            return;
        }

        container.innerHTML = `
            <div class="returns-loading">
                <span class="material-symbols-outlined">autorenew</span>
                <span>Căutăm comenzile pregătite...</span>
            </div>
        `;
    }

    renderReturnOrders(orders, query) {
        const container = document.getElementById('return-orders-results');
        if (!container) {
            return;
        }

        if (!orders.length) {
            container.innerHTML = `
                <div class="empty-state">
                    <span class="material-symbols-outlined">inventory_2</span>
                    <p>Nu am găsit comenzi pregătite pentru compania <strong>${this.escapeHtml(query)}</strong>.</p>
                </div>
            `;
            return;
        }

        container.innerHTML = orders
            .map((order) => this.renderReturnOrderCard(order))
            .join('');
    }

    renderReturnOrderCard(order) {
        const orderNumber = order.order_number || 'Comandă';
        const company = order.customer_name || 'Companie necunoscută';
        const latest = order.latest_activity || order.updated_at || order.order_date;
        const formattedDate = this.formatDateTime(latest);
        const totalItems = Number(order.total_items ?? order.items_count ?? 0) || 0;
        const totalValue = this.formatCurrency(order.total_value, order.currency || 'RON');
        const statusLabel = order.status_label || this.formatOrderStatus(order.status);

        return `
            <div class="return-order-card">
                <div class="return-order-card-header">
                    <span class="return-order-number">${this.escapeHtml(orderNumber)}</span>
                    <span class="return-order-date">${this.escapeHtml(formattedDate)}</span>
                </div>
                <div class="return-order-company">${this.escapeHtml(company)}</div>
                <div class="return-order-meta">
                    <span class="return-order-status">
                        <span class="material-symbols-outlined">local_shipping</span>
                        ${this.escapeHtml(statusLabel)}
                    </span>
                    <span>
                        <span class="material-symbols-outlined">inventory_2</span>
                        ${totalItems} articole
                    </span>
                    <span>
                        <span class="material-symbols-outlined">payments</span>
                        ${this.escapeHtml(totalValue)}
                    </span>
                </div>
            </div>
        `;
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

            const printerName = 'Godex EZ6250i';

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

    async previewProductionLabel() {
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

            const formData = new FormData();
            formData.append('product_id', this.selectedProductId);
            formData.append('quantity', qty);
            formData.append('batch_number', batch);
            formData.append('produced_at', date);
            formData.append('source', 'factory');
            formData.append('action', 'preview');

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
                throw new Error(result.message || 'Eroare la generarea etichetei');
            }

            if (result.label_url) {
                window.open(result.label_url, '_blank');
            }
        } catch (err) {
            console.error('Preview error:', err);
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
                await this.autoAssignSuggestedLocations();
                this.displayReceivingItems();
            }
            
        } catch (error) {
            console.error('Error loading receiving items:', error);
        }
    }

    async autoAssignSuggestedLocations() {
        if (!Array.isArray(this.receivingItems) || this.receivingItems.length === 0) {
            return;
        }

        const assignmentTasks = this.receivingItems.map(async (item) => {
            if (!item || item.location_code) {
                return;
            }

            try {
                const suggestion = await this.fetchSuggestedLocation(item);
                if (suggestion && suggestion.location_code) {
                    this.ensureLocationInConfig(suggestion);
                    item.location_code = suggestion.location_code;
                    if (!item.location_id && suggestion.location_id) {
                        item.location_id = suggestion.location_id;
                    }
                }
            } catch (error) {
                console.warn('Could not auto-assign location for item', item?.sku || item?.id, error);
            }
        });

        await Promise.allSettled(assignmentTasks);
    }

    async fetchSuggestedLocation(item) {
        const cacheKey = item?.product_id || item?.sku;
        if (!cacheKey) {
            return null;
        }

        if (this.locationSuggestionCache.has(cacheKey)) {
            return this.locationSuggestionCache.get(cacheKey);
        }

        let query = '';
        if (item.product_id) {
            query = `product_id=${encodeURIComponent(item.product_id)}`;
        } else if (item.sku) {
            query = `sku=${encodeURIComponent(item.sku)}`;
        } else {
            return null;
        }

        const url = `${this.config.apiBase}/product_location.php?${query}`;

        try {
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            const suggestion = Array.isArray(data) ? (data[0] || null) : data;

            if (suggestion && suggestion.location_code) {
                const normalized = {
                    location_id: suggestion.location_id ?? suggestion.id ?? null,
                    location_code: suggestion.location_code,
                    zone: suggestion.zone ?? suggestion.zone_name ?? null
                };
                this.locationSuggestionCache.set(cacheKey, normalized);
                return normalized;
            }

            this.locationSuggestionCache.set(cacheKey, null);
            return null;
        } catch (error) {
            this.locationSuggestionCache.set(cacheKey, null);
            throw error;
        }
    }

    ensureLocationInConfig(location) {
        if (!location || !location.location_code) {
            return;
        }

        if (!Array.isArray(this.config.locations)) {
            this.config.locations = [];
        }

        const exists = this.config.locations.some(loc => loc.location_code === location.location_code);
        if (!exists) {
            this.config.locations.push({
                id: location.location_id || null,
                location_code: location.location_code,
                zone: location.zone || '---',
                type: 'warehouse'
            });
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
                    ${item.tracking_method === 'individual' ? `
                        <div class="barcode-task-banner">
                            <div class="barcode-task-info">
                                <span class="material-symbols-outlined">qr_code_scanner</span>
                                <div>
                                    <div class="barcode-task-title">Scanare coduri de bare</div>
                                    <div class="barcode-task-progress">${item.barcode_scanned || 0} / ${item.barcode_expected || item.received_quantity || item.expected_quantity || 0} unități scanate</div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="receivingSystem.openBarcodeScannerByItem(${item.id})">
                                <span class="material-symbols-outlined">play_arrow</span>
                                Continuă scanarea
                            </button>
                        </div>
                    ` : ''}
                    <button type="button" class="receive-item-btn" onclick="receivingSystem.receiveItem(${item.id})">
                        <span class="material-symbols-outlined">check</span>
                        Primește
                    </button>
                </div>
            </div>
        `}).join('');
    }

    getLocationOptions(selectedCode = '') {
        const locations = Array.isArray(this.config.locations) ? [...this.config.locations] : [];

        if (selectedCode && !locations.some(loc => loc.location_code === selectedCode)) {
            locations.unshift({
                id: null,
                location_code: selectedCode,
                zone: '---'
            });
        }

        return locations.map(loc =>
            `<option value="${loc.location_code}" ${loc.location_code === selectedCode ? 'selected' : ''}>${loc.location_code} (${loc.zone || '---'})</option>`
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

        const item = this.receivingItems.find(i => i.id == itemId) || null;

        const trackingChoice = await this.promptBarcodeDecision(item, receivedQty);
        if (!trackingChoice) {
            return;
        }

        if (trackingChoice === 'individual' && !Number.isInteger(receivedQty)) {
            this.showError('Pentru scanarea individuală, cantitatea trebuie să fie un număr întreg.');
            return;
        }

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
                    location_id: locationId,
                    tracking_method: trackingChoice
                })
            });

            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || 'Eroare la primirea produsului');
            }

            // Complete timing silently
            await this.completeItemTiming(itemId, receivedQty);

            if (item) {
                item.status = result.status;
                item.received_quantity = result.item_details?.received_quantity ?? receivedQty;
                item.location_code = locationId;
                item.tracking_method = result.tracking_method || 'bulk';
                item.barcode_task_id = result.barcode_task_id || null;
                if (item.tracking_method === 'individual') {
                    item.barcode_expected = result.barcode_expected || item.barcode_expected || item.received_quantity;
                    item.barcode_scanned = typeof result.barcode_scanned === 'number' ? result.barcode_scanned : item.barcode_scanned || 0;
                    item.barcode_status = result.barcode_status || item.barcode_status || null;
                } else {
                    item.barcode_expected = null;
                    item.barcode_scanned = null;
                    item.barcode_status = null;
                }
            }

            this.updateItemStatus(itemId, result.status);
            this.displayReceivingItems();
            this.updateReceivingSummary();

            if (trackingChoice === 'individual') {
                this.showSuccess('Sarcina de scanare a fost creată. Scanează fiecare unitate.');
                if (result.barcode_task_id) {
                    const productName = item?.product_name || result.item_details?.product_name || '';
                    this.openBarcodeScanner({
                        taskId: result.barcode_task_id,
                        expected: result.barcode_expected || Math.round(receivedQty),
                        scanned: result.barcode_scanned || 0,
                        status: result.barcode_status || 'pending',
                        productName,
                        sku: item?.sku || result.item_details?.sku || '',
                        locationCode: locationId
                    }, itemId);
                }
            } else {
                this.showSuccess('Produs primit cu succes');
            }

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

    // Barcode scanning workflow
    async promptBarcodeDecision(item, quantity) {
        if (!this.barcodeDecisionModal) {
            return 'bulk';
        }

        return new Promise((resolve) => {
            this.barcodeDecisionResolve = resolve;

            const questionEl = this.barcodeDecisionModal.querySelector('.modal-question');
            if (questionEl) {
                const qtyText = quantity ? `${quantity} ${quantity === 1 ? 'bucată' : 'bucăți'}` : 'aceste bucăți';
                questionEl.textContent = `Există coduri de bare de scanat pentru ${qtyText}?`;
            }

            this.barcodeDecisionModal.style.display = 'flex';

            this.barcodeDecisionKeyHandler = (event) => {
                if (event.key === 'F1') {
                    event.preventDefault();
                    this.handleBarcodeDecisionChoice('individual');
                } else if (event.key === 'F2') {
                    event.preventDefault();
                    this.handleBarcodeDecisionChoice('bulk');
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    this.cancelBarcodeDecision();
                }
            };

            document.addEventListener('keydown', this.barcodeDecisionKeyHandler);
        });
    }

    handleBarcodeDecisionChoice(choice) {
        if (!this.barcodeDecisionResolve) {
            return;
        }
        const resolver = this.barcodeDecisionResolve;
        this.cleanupBarcodeDecision();
        resolver(choice);
    }

    cancelBarcodeDecision() {
        if (!this.barcodeDecisionResolve) {
            this.cleanupBarcodeDecision();
            return;
        }
        const resolver = this.barcodeDecisionResolve;
        this.cleanupBarcodeDecision();
        resolver(null);
    }

    cleanupBarcodeDecision() {
        if (this.barcodeDecisionModal) {
            this.barcodeDecisionModal.style.display = 'none';
        }
        if (this.barcodeDecisionKeyHandler) {
            document.removeEventListener('keydown', this.barcodeDecisionKeyHandler);
            this.barcodeDecisionKeyHandler = null;
        }
        this.barcodeDecisionResolve = null;
    }

    openBarcodeScanner(taskInfo, itemId) {
        const modal = document.getElementById('scanner-modal');
        if (!modal) return;

        if (this.barcodeCaptureSession) {
            this.barcodeCaptureSession.destroy();
        }

        const titleEl = document.getElementById('barcode-modal-title');
        const subtitleEl = document.getElementById('barcode-modal-subtitle');
        const progressTextEl = document.getElementById('barcode-progress-text');
        const progressFillEl = document.getElementById('barcode-progress-fill');
        const inputEl = document.getElementById('barcode-scan-input');
        const listEl = document.getElementById('barcode-scanned-list');
        const alertEl = document.getElementById('barcode-scan-alert');

        if (listEl) {
            listEl.innerHTML = '';
        }
        if (alertEl) {
            alertEl.style.display = 'none';
            alertEl.textContent = '';
        }

        if (titleEl) {
            titleEl.textContent = 'Scanare coduri de bare';
        }
        if (subtitleEl) {
            const parts = [];
            if (taskInfo.productName) {
                parts.push(taskInfo.productName);
            }
            if (taskInfo.sku) {
                parts.push(`SKU ${taskInfo.sku}`);
            }
            if (taskInfo.locationCode) {
                parts.push(`Locație ${taskInfo.locationCode}`);
            }
            subtitleEl.textContent = parts.join(' • ');
        }

        modal.style.display = 'flex';
        this.activeBarcodeTask = { itemId, taskId: taskInfo.taskId };

        this.barcodeCaptureSession = new BarcodeCaptureSession({
            taskId: taskInfo.taskId,
            apiBase: this.config.apiBase,
            expected: taskInfo.expected,
            scanned: taskInfo.scanned,
            elements: {
                progressText: progressTextEl,
                progressFill: progressFillEl,
                input: inputEl,
                list: listEl,
                alert: alertEl
            },
            onProgress: (data) => this.updateItemBarcodeProgress(itemId, data),
            onComplete: (data) => this.onBarcodeTaskComplete(itemId, data)
        });
    }

    openBarcodeScannerByItem(itemId) {
        const item = this.receivingItems.find(i => i.id == itemId);
        if (!item || !item.barcode_task_id) {
            this.showError('Nu există o sarcină de scanare pentru acest produs.');
            return;
        }

        this.openBarcodeScanner({
            taskId: item.barcode_task_id,
            expected: item.barcode_expected || item.received_quantity || item.expected_quantity || 0,
            scanned: item.barcode_scanned || 0,
            status: item.barcode_status || 'pending',
            productName: item.product_name,
            sku: item.sku,
            locationCode: item.location_code
        }, itemId);
    }

    closeScannerModal() {
        const modal = document.getElementById('scanner-modal');
        if (modal) {
            modal.style.display = 'none';
        }
        if (this.barcodeCaptureSession) {
            this.barcodeCaptureSession.destroy();
            this.barcodeCaptureSession = null;
        }
        this.activeBarcodeTask = null;
    }

    updateItemBarcodeProgress(itemId, progress) {
        const item = this.receivingItems.find(i => i.id == itemId);
        if (!item) return;

        if (typeof progress.expected === 'number') {
            item.barcode_expected = progress.expected;
        }
        if (typeof progress.scanned === 'number') {
            item.barcode_scanned = progress.scanned;
        }
        if (progress.status) {
            item.barcode_status = progress.status;
        }

        if (item.tracking_method === 'individual') {
            if (item.barcode_status === 'completed' || (item.barcode_expected && item.barcode_scanned >= item.barcode_expected)) {
                item.status = 'received';
            } else {
                item.status = 'pending_scan';
            }
        }

        this.displayReceivingItems();
        this.updateReceivingSummary();
    }

    onBarcodeTaskComplete(itemId, progress) {
        const item = this.receivingItems.find(i => i.id == itemId);
        if (item) {
            item.barcode_expected = progress.expected;
            item.barcode_scanned = progress.scanned;
            item.barcode_status = progress.status;
            item.status = 'received';
        }
        this.showSuccess('Scanarea codurilor de bare a fost finalizată.');
        this.closeScannerModal();
        this.displayReceivingItems();
        this.updateReceivingSummary();
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

    formatDateTime(value) {
        if (!value) {
            return '-';
        }

        let date = new Date(value);
        if (Number.isNaN(date.getTime()) && typeof value === 'string') {
            date = new Date(value.replace(' ', 'T'));
        }

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString('ro-RO');
    }

    formatCurrency(amount, currency = 'RON') {
        const numericValue = Number(amount);

        if (!Number.isFinite(numericValue)) {
            const fallback = typeof amount !== 'undefined' ? amount : 0;
            return `${fallback} ${currency}`;
        }

        try {
            return new Intl.NumberFormat('ro-RO', {
                style: 'currency',
                currency
            }).format(numericValue);
        } catch (error) {
            return `${numericValue.toFixed(2)} ${currency}`;
        }
    }

    formatOrderStatus(status) {
        if (!status) {
            return 'Necunoscut';
        }

        const normalized = String(status).toLowerCase();
        const statusMap = {
            'picked': 'Pregătită',
            'ready_to_ship': 'Gata de expediere',
            'completed': 'Finalizată',
            'processing': 'În procesare',
            'pending': 'În așteptare',
            'assigned': 'Alocată'
        };

        return statusMap[normalized] || status;
    }

    debounce(fn, delay = 300) {
        let timeout;
        return (...args) => {
            clearTimeout(timeout);
            timeout = setTimeout(() => fn.apply(this, args), delay);
        };
    }

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
            'partial': 'Parțial',
            'pending_scan': 'Scanare în curs'
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

class BarcodeCaptureSession {
    constructor(config) {
        this.taskId = config.taskId;
        this.apiBase = config.apiBase;
        this.expected = config.expected || 0;
        this.scanned = config.scanned || 0;
        this.elements = config.elements || {};
        this.onProgress = config.onProgress;
        this.onComplete = config.onComplete;
        this.storageKey = `barcode_scans_${this.taskId}`;
        this.editingCard = null;
        this.inputDebounceTimer = null;

        this.submitHandler = this.handleInputChange.bind(this);
        this.keypressHandler = this.handleKeypress.bind(this);
        this.inputHandler = this.handleInputEvent.bind(this);
        this.listClickHandler = this.handleListClick.bind(this);

        this.init();
    }

    init() {
        this.updateProgressUI();
        this.loadScans();

        if (this.elements.input) {
            this.elements.input.value = '';
            this.elements.input.focus();
            this.elements.input.addEventListener('change', this.submitHandler);
            this.elements.input.addEventListener('keypress', this.keypressHandler);
            this.elements.input.addEventListener('input', this.inputHandler);
        }
        if (this.elements.list) {
            this.elements.list.addEventListener('click', this.listClickHandler);
        }
    }

    emitProgress(statusOverride = null) {
        if (typeof this.onProgress === 'function') {
            const status = statusOverride || (this.expected > 0 && this.scanned >= this.expected ? 'completed' : 'in_progress');
            this.onProgress({
                expected: this.expected,
                scanned: this.scanned,
                status
            });
        }
    }

    updateProgressUI() {
        if (this.elements.progressText) {
            this.elements.progressText.textContent = `Scanate ${this.scanned}/${this.expected} unități`;
        }
        if (this.elements.progressFill) {
            const percent = this.expected > 0 ? Math.min(100, Math.round((this.scanned / this.expected) * 100)) : 0;
            this.elements.progressFill.style.width = `${percent}%`;
        }
        this.emitProgress();
    }

    async loadScans() {
        const stored = JSON.parse(localStorage.getItem(this.storageKey) || '[]');
        let remote = [];

        try {
            const res = await fetch(`${this.apiBase}/barcode_capture/list.php?task_id=${this.taskId}`, {
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data.status === 'success') {
                if (typeof data.expected === 'number') {
                    this.expected = data.expected;
                }
                if (typeof data.scanned === 'number') {
                    this.scanned = data.scanned;
                }
                remote = data.scans || [];
            }
        } catch (error) {
            console.error('Failed to load barcode scans:', error);
        }

        const mergedMap = new Map();
        [...stored, ...remote].forEach(scan => {
            if (scan && scan.barcode) {
                mergedMap.set(scan.barcode, scan);
            }
        });

        const merged = Array.from(mergedMap.values());
        this.renderScannedList(merged);
        localStorage.setItem(this.storageKey, JSON.stringify(merged));
        this.updateProgressUI();
    }

    renderScannedList(scans) {
        if (!this.elements.list) return;
        this.elements.list.innerHTML = '';
        scans.forEach(scan => {
            this.addCard(scan.barcode, scan.inventory_id, false);
        });
    }

    addCard(barcode, inventoryId, prepend = true) {
        if (!this.elements.list || !barcode) return;
        const card = document.createElement('div');
        card.className = 'barcode-card';
        card.dataset.barcode = barcode;
        card.dataset.inventoryId = inventoryId || '';
        card.textContent = barcode;

        if (prepend && this.elements.list.firstChild) {
            this.elements.list.insertBefore(card, this.elements.list.firstChild);
        } else {
            this.elements.list.appendChild(card);
        }
    }

    handleListClick(event) {
        const card = event.target.closest('.barcode-card');
        if (!card || !card.dataset.barcode) return;
        if (!confirm('Ștergi acest cod de bare?')) return;
        this.deleteScan(card);
    }

    async deleteScan(card) {
        const inventoryId = card.dataset.inventoryId || null;
        const barcode = card.dataset.barcode;
        if (!barcode) return;

        try {
            const res = await fetch(`${this.apiBase}/barcode_capture/delete_scan.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    task_id: this.taskId,
                    inventory_id: inventoryId,
                    barcode
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                this.expected = typeof data.expected === 'number' ? data.expected : this.expected;
                this.scanned = typeof data.scanned === 'number' ? data.scanned : this.scanned;
                card.remove();
                this.removeFromStorage(barcode, inventoryId);
                this.clearAlert();
                this.updateProgressUI();
            } else {
                this.showAlert(data.message || 'Eroare la ștergerea codului');
            }
        } catch (error) {
            console.error('Delete scan error:', error);
            this.showAlert('Eroare de rețea la ștergerea codului');
        }
    }

    removeFromStorage(barcode, inventoryId) {
        const stored = JSON.parse(localStorage.getItem(this.storageKey) || '[]');
        const filtered = stored.filter(scan => !(scan.barcode === barcode && String(scan.inventory_id || '') === String(inventoryId || '')));
        localStorage.setItem(this.storageKey, JSON.stringify(filtered));
    }

    handleInputChange() {
        this.submit();
    }

    handleKeypress(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            this.submit();
        }
    }

    handleInputEvent() {
        if (!this.elements.input) return;
        const value = this.elements.input.value.trim();
        clearTimeout(this.inputDebounceTimer);
        if (!value) {
            this.inputDebounceTimer = null;
            return;
        }
        this.inputDebounceTimer = setTimeout(() => {
            this.submit();
        }, 150);
    }

    async submit() {
        if (!this.elements.input) return;
        if (this.inputDebounceTimer) {
            clearTimeout(this.inputDebounceTimer);
            this.inputDebounceTimer = null;
        }
        const code = this.elements.input.value.trim();
        if (!code) return;

        const existing = Array.from(this.elements.list?.querySelectorAll('.barcode-card') || [])
            .some(card => card.dataset.barcode === code);
        if (existing) {
            this.showAlert('Codul de bare a fost deja scanat');
            this.elements.input.value = '';
            this.elements.input.focus();
            return;
        }

        try {
            const res = await fetch(`${this.apiBase}/barcode_capture/scan.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    task_id: this.taskId,
                    barcode: code
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                this.clearAlert();
                this.expected = typeof data.expected === 'number' ? data.expected : this.expected;
                this.scanned = typeof data.scanned === 'number' ? data.scanned : this.scanned;
                this.addCard(code, data.inventory_id);
                this.addToStorage(code, data.inventory_id);
                this.updateProgressUI();
                this.elements.input.value = '';
                this.elements.input.focus();
                if (data.completed) {
                    this.emitProgress('completed');
                    if (typeof this.onComplete === 'function') {
                        this.onComplete({
                            expected: this.expected,
                            scanned: this.scanned,
                            status: 'completed'
                        });
                    }
                }
            } else {
                this.showAlert(data.message || 'Eroare la scanare');
            }
        } catch (error) {
            console.error('Scan submit error:', error);
            this.showAlert('Eroare de rețea la scanare');
        }
    }

    addToStorage(barcode, inventoryId) {
        const stored = JSON.parse(localStorage.getItem(this.storageKey) || '[]');
        stored.unshift({ barcode, inventory_id: inventoryId });
        localStorage.setItem(this.storageKey, JSON.stringify(stored));
    }

    showAlert(message) {
        if (!this.elements.alert) return;
        this.elements.alert.textContent = message;
        this.elements.alert.style.display = 'block';
        this.elements.alert.classList.add('error');
    }

    clearAlert() {
        if (!this.elements.alert) return;
        this.elements.alert.textContent = '';
        this.elements.alert.style.display = 'none';
        this.elements.alert.classList.remove('error');
    }

    destroy() {
        this.clearAlert();
        if (this.elements.input) {
            this.elements.input.removeEventListener('change', this.submitHandler);
            this.elements.input.removeEventListener('keypress', this.keypressHandler);
            this.elements.input.removeEventListener('input', this.inputHandler);
        }
        if (this.elements.list) {
            this.elements.list.removeEventListener('click', this.listClickHandler);
        }
        if (this.inputDebounceTimer) {
            clearTimeout(this.inputDebounceTimer);
            this.inputDebounceTimer = null;
        }
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

