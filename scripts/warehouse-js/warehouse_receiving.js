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
        if (!Array.isArray(this.config.locationLevels)) {
            this.config.locationLevels = [];
        }
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
        this.productionLocationLookupId = 0;
        this.productionLocationSelect = null;
        this.selectedProductionLocation = null;

        this.activeTab = 'standard';
        this.returnSearchAbortController = null;
        this.returnSearchResults = new Map();
        this.selectedReturnOrderId = null;
        this.selectedReturnOrderDetails = null;
        this.lastReturnSearchTerm = '';
        this.returnRestockButton = null;
        this.returnRestockButtonDefaultHtml = '';

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
        this.productionLocationSelect = document.getElementById('prod-location-select');
        if (this.productionLocationSelect) {
            this.populateProductionLocationOptions();
            this.productionLocationSelect.addEventListener('change', () => this.updateProductionLocationSelection(true));
        }

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
            prodSearch.addEventListener('input', (e) => {
                const value = e.target.value || '';
                this.productionLocationLookupId += 1;
                this.selectedProductId = null;
                this.updateProductionLocationMessage('idle');
                this.searchProducts(value);
            });
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

        this.returnRestockButton = document.getElementById('return-restock-btn');
        if (this.returnRestockButton) {
            this.returnRestockButtonDefaultHtml = this.returnRestockButton.innerHTML;
            this.returnRestockButton.addEventListener('click', () => this.processReturnRestock());
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
        this.productionLocationLookupId += 1;
        if (this.productionLocationSelect) {
            this.populateProductionLocationOptions('');
        }
        this.selectedProductionLocation = null;
        this.updateProductionLocationMessage('idle');
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
            this.lastReturnSearchTerm = '';
            this.returnSearchResults.clear();
            this.clearReturnSelection();
            container.innerHTML = `
                <div class="empty-state">
                    <span class="material-symbols-outlined">travel_explore</span>
                    <p>Introduceți numele unei companii pentru a vedea retururile active.</p>
                </div>
            `;
            return;
        }

        if (trimmedQuery.length < 2) {
            this.lastReturnSearchTerm = trimmedQuery;
            this.returnSearchResults.clear();
            this.clearReturnSelection();
            container.innerHTML = `
                <div class="empty-state">
                    <span class="material-symbols-outlined">info</span>
                    <p>Introduceți cel puțin 2 caractere pentru a căuta o companie.</p>
                </div>
            `;
            return;
        }

        if (trimmedQuery !== this.lastReturnSearchTerm) {
            this.clearReturnSelection();
        }
        this.lastReturnSearchTerm = trimmedQuery;

        this.returnSearchResults.clear();
        this.showReturnSearchLoading();

        if (this.returnSearchAbortController) {
            this.returnSearchAbortController.abort();
        }

        this.returnSearchAbortController = new AbortController();

        try {
            const response = await fetch(`${this.config.apiBase}/warehouse/search_pending_returns.php?company=${encodeURIComponent(trimmedQuery)}`, {
                signal: this.returnSearchAbortController.signal
            });
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || 'Nu am putut căuta retururile active.');
            }

            const returns = Array.isArray(result.returns) ? result.returns : [];
            this.renderReturnOrders(returns, trimmedQuery);
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            console.error('Return search error:', error);
            container.innerHTML = `
                <div class="return-orders-error">
                    ${this.escapeHtml(error.message || 'A apărut o eroare la încărcarea retururilor active.')}
                </div>
            `;
            this.toggleReturnRestockHint();
            this.disableReturnRestockButton();
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
                <span>Căutăm retururile active...</span>
            </div>
        `;

        this.toggleReturnRestockHint();
        this.disableReturnRestockButton();
    }

    renderReturnOrders(orders, query) {
        const container = document.getElementById('return-orders-results');
        if (!container) {
            return;
        }

        this.returnSearchResults = new Map();

        if (!orders.length) {
            container.innerHTML = `
                <div class="empty-state">
                    <span class="material-symbols-outlined">inventory_2</span>
                    <p>Nu am găsit retururi active pentru compania <strong>${this.escapeHtml(query)}</strong>.</p>
                </div>
            `;
            this.clearReturnSelection();
            this.toggleReturnRestockHint();
            this.disableReturnRestockButton();
            return;
        }

        orders.forEach(order => {
            if (order && typeof order.id !== 'undefined') {
                this.returnSearchResults.set(Number(order.id), order);
            }
        });

        container.innerHTML = orders
            .map((order) => this.renderReturnOrderCard(order))
            .join('');

        this.attachReturnOrderEvents();
        this.highlightSelectedReturnOrder();
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
            <div class="return-order-card" data-order-id="${order.id}" data-order-number="${this.escapeHtml(orderNumber)}">
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

    attachReturnOrderEvents() {
        const container = document.getElementById('return-orders-results');
        if (!container) {
            return;
        }

        container.querySelectorAll('.return-order-card').forEach((card) => {
            card.addEventListener('click', () => {
                const orderId = Number(card.getAttribute('data-order-id'));
                if (!Number.isFinite(orderId)) {
                    return;
                }
                this.handleReturnOrderSelection(orderId);
            });
        });
    }

    highlightSelectedReturnOrder() {
        const container = document.getElementById('return-orders-results');
        if (!container) {
            return;
        }

        let found = false;
        container.querySelectorAll('.return-order-card').forEach((card) => {
            const cardId = Number(card.getAttribute('data-order-id'));
            const isSelected = this.selectedReturnOrderId !== null && cardId === this.selectedReturnOrderId;
            card.classList.toggle('selected', isSelected);
            if (isSelected) {
                found = true;
            }
        });

        if (!found && this.selectedReturnOrderId !== null) {
            this.selectedReturnOrderId = null;
            this.resetReturnDetails();
        }
    }

    handleReturnOrderSelection(orderId) {
        if (!Number.isFinite(orderId)) {
            return;
        }

        this.selectedReturnOrderId = orderId;
        this.highlightSelectedReturnOrder();
        this.loadReturnOrderDetails(orderId);
    }

    async loadReturnOrderDetails(orderId) {
        if (!Number.isFinite(orderId)) {
            return;
        }

        this.showReturnDetailsLoading();

        try {
            const response = await fetch(`${this.config.apiBase}/warehouse/return_order_details.php?order_id=${encodeURIComponent(orderId)}`);
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Nu am putut încărca detaliile comenzii.');
            }

            this.renderReturnOrderDetails(result);
        } catch (error) {
            console.error('Return order details error:', error);
            const details = document.getElementById('return-order-details');
            if (details) {
                details.innerHTML = `
                    <div class="return-orders-error">
                        ${this.escapeHtml(error.message || 'A apărut o eroare la încărcarea produselor din retur.')}
                    </div>
                `;
            }
            this.selectedReturnOrderDetails = null;
            this.toggleReturnRestockHint('warning', error.message || 'Nu am putut încărca produsele din retur.');
            this.disableReturnRestockButton();
        }
    }

    showReturnDetailsLoading() {
        const details = document.getElementById('return-order-details');
        if (!details) {
            return;
        }

        details.innerHTML = `
            <div class="returns-loading">
                <span class="material-symbols-outlined">autorenew</span>
                <span>Încărcăm detaliile comenzii...</span>
            </div>
        `;

        this.toggleReturnRestockHint();
        this.disableReturnRestockButton();
    }

    renderReturnOrderDetails(data) {
        const details = document.getElementById('return-order-details');
        if (!details) {
            return;
        }

        const order = data.order || {};
        const items = Array.isArray(data.items) ? data.items : [];

        const normalizedItems = items.map((item) => {
            const restockQuantity = Number(item.restock_quantity ?? item.picked_quantity ?? item.quantity_ordered ?? 0) || 0;
            return {
                ...item,
                restock_quantity: restockQuantity,
                location_id: item.location_id ? Number(item.location_id) : null,
                inventory_id: item.inventory_id ? Number(item.inventory_id) : null,
                shelf_level: item.shelf_level ?? null,
                subdivision_number: typeof item.subdivision_number !== 'undefined' && item.subdivision_number !== null
                    ? Number(item.subdivision_number)
                    : null,
                location_missing: !(item.location_id && item.location_code)
            };
        });

        this.selectedReturnOrderDetails = {
            order,
            items: normalizedItems
        };

        const totalUnits = normalizedItems.reduce((sum, item) => sum + (item.restock_quantity || 0), 0);
        const missingCount = normalizedItems.filter((item) => item.location_missing).length;

        const summaryHtml = `
            <div class="return-order-summary">
                <div class="return-order-summary-item">
                    <span class="return-order-summary-label">Comandă</span>
                    <span class="return-order-summary-value">${this.escapeHtml(order.order_number || '-')}</span>
                </div>
                <div class="return-order-summary-item">
                    <span class="return-order-summary-label">Client</span>
                    <span class="return-order-summary-value">${this.escapeHtml(order.customer_name || 'N/A')}</span>
                </div>
                <div class="return-order-summary-item">
                    <span class="return-order-summary-label">Status</span>
                    <span class="return-order-summary-value">${this.escapeHtml(order.status_label || this.formatOrderStatus(order.status || 'picked'))}</span>
                </div>
                <div class="return-order-summary-item">
                    <span class="return-order-summary-label">Articole</span>
                    <span class="return-order-summary-value">${normalizedItems.length}</span>
                </div>
                <div class="return-order-summary-item">
                    <span class="return-order-summary-label">Bucăți</span>
                    <span class="return-order-summary-value">${totalUnits}</span>
                </div>
                <div class="return-order-summary-item">
                    <span class="return-order-summary-label">Ultima actualizare</span>
                    <span class="return-order-summary-value">${this.escapeHtml(this.formatDateTime(order.latest_activity))}</span>
                </div>
            </div>
        `;

        let itemsMarkup = '';
        if (!normalizedItems.length) {
            itemsMarkup = `
                <div class="empty-state">
                    <span class="material-symbols-outlined">inventory</span>
                    <p>Nu există produse de readăugat în stoc pentru această comandă.</p>
                </div>
            `;
        } else {
            itemsMarkup = normalizedItems.map((item) => {
                const hasLocation = Boolean(item.location_id && item.location_code);
                const locationText = hasLocation
                    ? `${this.escapeHtml(item.location_code)}${item.shelf_level ? ' · ' + this.escapeHtml(item.shelf_level) : ''}`
                    : 'Necesită locație';
                const locationIcon = hasLocation ? 'location_on' : 'warning';
                const locationClass = hasLocation ? '' : 'return-item-location-missing';

                return `
                    <div class="return-item">
                        <div class="return-item-header">
                            <span class="return-item-name">${this.escapeHtml(item.product_name || 'Produs')}</span>
                            <span class="return-item-sku">${this.escapeHtml(item.sku || '')}</span>
                        </div>
                        <div class="return-item-meta">
                            <span>
                                <span class="material-symbols-outlined">inventory_2</span>
                                ${item.restock_quantity} buc
                            </span>
                            <span>
                                <span class="material-symbols-outlined">shopping_cart</span>
                                Ridicate: ${item.picked_quantity ?? item.restock_quantity}
                            </span>
                            <span class="${locationClass}">
                                <span class="material-symbols-outlined">${locationIcon}</span>
                                ${this.escapeHtml(locationText)}
                            </span>
                        </div>
                    </div>
                `;
            }).join('');
        }

        details.innerHTML = `
            ${summaryHtml}
            <div class="return-items-list">
                ${itemsMarkup}
            </div>
        `;

        if (missingCount > 0) {
            const message = missingCount === 1
                ? 'Un produs nu are o locație activă. Actualizați locația înainte de a readăuga în stoc.'
                : `${missingCount} produse nu au o locație activă. Actualizați locațiile înainte de a continua.`;
            this.toggleReturnRestockHint('warning', message);
        } else if (!normalizedItems.length) {
            this.toggleReturnRestockHint('warning', 'Nu există produse de readăugat în stoc pentru această comandă.');
        } else if (totalUnits === 0) {
            this.toggleReturnRestockHint('warning', 'Cantitățile de returnat sunt zero pentru această comandă.');
        } else {
            this.toggleReturnRestockHint('success', 'Toate produsele vor fi readăugate în stoc în locațiile propuse.');
        }

        if (this.returnRestockButton) {
            if (this.returnRestockButtonDefaultHtml) {
                this.returnRestockButton.innerHTML = this.returnRestockButtonDefaultHtml;
            }
            this.returnRestockButton.disabled = missingCount > 0 || !normalizedItems.length || totalUnits === 0;
        }
    }

    getDefaultReturnDetailsMarkup() {
        return `
            <div class="empty-state">
                <span class="material-symbols-outlined">playlist_add_check</span>
                <p>Selectați o comandă pentru a vedea produsele returnate și locațiile propuse.</p>
            </div>
        `;
    }

    resetReturnDetails() {
        const details = document.getElementById('return-order-details');
        if (details) {
            details.innerHTML = this.getDefaultReturnDetailsMarkup();
        }

        this.selectedReturnOrderDetails = null;
        this.toggleReturnRestockHint();
        this.disableReturnRestockButton();
    }

    clearReturnSelection(resetDetails = true) {
        this.selectedReturnOrderId = null;
        const container = document.getElementById('return-orders-results');
        if (container) {
            container.querySelectorAll('.return-order-card.selected').forEach((card) => {
                card.classList.remove('selected');
            });
        }

        if (resetDetails) {
            this.resetReturnDetails();
        }
    }

    toggleReturnRestockHint(type = '', message = '') {
        const hint = document.getElementById('return-restock-hint');
        if (!hint) {
            return;
        }

        hint.style.display = 'none';
        hint.classList.remove('warning', 'success');
        hint.innerHTML = '';

        if (!type || !message) {
            return;
        }

        const icon = type === 'success' ? 'check_circle' : 'warning';
        hint.style.display = 'flex';
        hint.classList.add(type);
        hint.innerHTML = `
            <span class="material-symbols-outlined">${icon}</span>
            <span>${this.escapeHtml(message)}</span>
        `;
    }

    disableReturnRestockButton() {
        if (!this.returnRestockButton) {
            this.returnRestockButton = document.getElementById('return-restock-btn');
        }

        if (!this.returnRestockButton) {
            return;
        }

        if (this.returnRestockButtonDefaultHtml) {
            this.returnRestockButton.innerHTML = this.returnRestockButtonDefaultHtml;
        }

        this.returnRestockButton.disabled = true;
    }

    async processReturnRestock() {
        if (!this.selectedReturnOrderDetails || !this.selectedReturnOrderDetails.order) {
            this.showError('Selectați o comandă de retur înainte de a readăuga produsele în stoc.');
            return;
        }

        const { order, items } = this.selectedReturnOrderDetails;
        const restockItems = items.filter((item) => item.location_id && item.restock_quantity > 0);

        if (!restockItems.length) {
            this.showError('Nu există produse eligibile pentru readăugare în stoc.');
            return;
        }

        if (!this.returnRestockButton) {
            this.returnRestockButton = document.getElementById('return-restock-btn');
        }

        if (this.returnRestockButton) {
            this.returnRestockButton.disabled = true;
            this.returnRestockButton.innerHTML = `
                <span class="material-symbols-outlined spin">autorenew</span>
                Se procesează...
            `;
        }

        try {
            const payload = {
                order_id: order.id,
                order_number: order.order_number,
                items: restockItems.map((item) => ({
                    order_item_id: item.order_item_id,
                    product_id: item.product_id,
                    restock_quantity: item.restock_quantity,
                    location_id: item.location_id,
                    inventory_id: item.inventory_id,
                    shelf_level: item.shelf_level,
                    subdivision_number: item.subdivision_number
                }))
            };

            const response = await fetch(`${this.config.apiBase}/warehouse/process_return_restock.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.config.csrfToken
                },
                body: JSON.stringify(payload)
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Nu am putut readăuga produsele în stoc.');
            }

            this.showSuccess(result.message || 'Produsele au fost readăugate în stoc din retur.');
            this.selectedReturnOrderDetails = null;
            this.clearReturnSelection();

            const searchInput = document.getElementById('return-company-search');
            if (searchInput && searchInput.value.trim().length >= 2) {
                this.searchReturnOrders(searchInput.value);
            } else {
                this.returnSearchResults.clear();
            }
        } catch (error) {
            console.error('Return restock error:', error);
            this.showError(error.message || 'A apărut o eroare la readăugarea produselor în stoc.');
            if (this.returnRestockButton && this.returnRestockButtonDefaultHtml) {
                this.returnRestockButton.innerHTML = this.returnRestockButtonDefaultHtml;
                this.returnRestockButton.disabled = false;
            }
        }
    }

    async searchProducts(query) {
        const searchTerm = (query || '').trim();
        if (!searchTerm) {
            const c = document.getElementById('prod-search-results');
            if (c) c.innerHTML = '';
            return;
        }
        try {
            const resp = await fetch(`${this.config.apiBase}/products.php?search=${encodeURIComponent(searchTerm)}`);
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
        
        container.innerHTML = products.map((p) => {
            const productName = this.escapeHtml(p.name);
            const productSku = this.escapeHtml(p.sku || p.code || '');
            return `
            <div class="purchase-order-item" data-product-id="${p.id}" data-product-name="${productName}" data-product-sku="${productSku}">
                <div class="po-header">
                    <div class="po-number">${productSku || '—'}</div>
                </div>
                <div class="po-details">${productName}</div>
            </div>`;
        }).join('');

        // Add event listener for product selection
        container.querySelectorAll('.purchase-order-item').forEach(item => {
            item.addEventListener('click', () => {
                const productId = item.getAttribute('data-product-id');
                const productName = item.getAttribute('data-product-name');
                const productSku = item.getAttribute('data-product-sku') || '';
                this.selectProduct(productId, productName, productSku);
            });
        });
    }

    async selectProduct(id, name, sku = '') {
        const numericId = parseInt(id, 10);
        this.selectedProductId = Number.isNaN(numericId) ? null : numericId;
        const searchInput = document.getElementById('prod-search-input');
        if (searchInput) searchInput.value = name;
        const container = document.getElementById('prod-search-results');
        if (container) container.innerHTML = '';
        if (!this.selectedProductId) {
            this.updateProductionLocationMessage('error');
            return;
        }
        if (this.productionLocationSelect) {
            this.productionLocationSelect.value = '';
        }
        this.selectedProductionLocation = null;
        this.updateProductionLocationSelection(false);
        await this.showProductionLocationForProduct({ product_id: this.selectedProductId, sku });
    }

    async showProductionLocationForProduct(productMeta) {
        const lookupToken = ++this.productionLocationLookupId;
        this.updateProductionLocationMessage('loading');

        const effectiveMeta = {
            product_id: productMeta?.product_id || productMeta?.id || null,
            sku: productMeta?.sku || productMeta?.code || ''
        };

        if (!effectiveMeta.product_id) {
            this.updateProductionLocationMessage('error');
            return;
        }

        try {
            const suggestion = await this.fetchSuggestedLocation(effectiveMeta);
            if (lookupToken !== this.productionLocationLookupId) {
                return;
            }

            if (suggestion && suggestion.location_code) {
                this.ensureLocationInConfig(suggestion);
                this.ensureLocationLevelInConfig(suggestion);
                this.applySuggestedProductionLocation(suggestion);
                this.updateProductionLocationMessage('found', suggestion);
                return;
            }

            const fallbackLocation = (this.defaultLocation || '').trim();
            if (fallbackLocation) {
                this.updateProductionLocationMessage('fallback', {
                    location_code: fallbackLocation,
                    reason: 'missing'
                });
                this.applyFallbackProductionLocation(fallbackLocation);
            } else {
                this.updateProductionLocationMessage('missing');
            }
        } catch (error) {
            console.error('Production location lookup failed:', error);
            if (lookupToken !== this.productionLocationLookupId) {
                return;
            }

            const fallbackLocation = (this.defaultLocation || '').trim();
            if (fallbackLocation) {
                this.updateProductionLocationMessage('fallback', {
                    location_code: fallbackLocation,
                    reason: 'error'
                });
                this.applyFallbackProductionLocation(fallbackLocation);
            } else {
                this.updateProductionLocationMessage('error');
            }
        }
    }

    updateProductionLocationMessage(state, details = {}) {
        const infoElement = document.getElementById('prod-location-info');
        if (!infoElement) {
            return;
        }

        const validStates = ['idle', 'loading', 'found', 'fallback', 'missing', 'error'];
        const normalizedState = validStates.includes(state) ? state : 'idle';
        infoElement.dataset.state = normalizedState;

        const fallbackLocation = (details.location_code || '').toString().trim();
        const defaultMessage = 'Selectează un produs pentru a vedea locația sugerată de stocare.';
        let message = defaultMessage;

        switch (normalizedState) {
            case 'loading':
                message = 'Se verifică locația sugerată pentru produs...';
                break;
            case 'found': {
                const extras = [];
                const zone = details.zone ? String(details.zone).trim() : '';
                const levelName = details.level_name ? String(details.level_name).trim() : '';
                const level = details.level_number ?? details.level;

                if (zone) {
                    extras.push(`Zona ${this.escapeHtml(zone)}`);
                }
                if (levelName) {
                    extras.push(this.escapeHtml(levelName));
                } else if (level !== null && level !== undefined && level !== '') {
                    extras.push(`Nivel ${this.escapeHtml(String(level))}`);
                }

                const extraText = extras.length ? ` <span class="location-extra">(${extras.join(' · ')})</span>` : '';
                message = `Locație selectată: <code>${this.escapeHtml(details.location_code || '')}</code>${extraText}. Poți modifica locația folosind selectorul de mai sus.`;
                break;
            }
            case 'fallback': {
                const reasonText = details.reason === 'error'
                    ? 'Nu am putut confirma locația dedicată.'
                    : 'Produsul nu are o locație dedicată configurată.';
                if (fallbackLocation) {
                    message = `${reasonText} Va fi folosită locația implicită <code>${this.escapeHtml(fallbackLocation)}</code>. Poți alege altă locație din selectorul de mai sus.`;
                } else {
                    message = `${reasonText} Te rugăm să aloci o locație înainte de a continua folosind selectorul de mai sus.`;
                }
                break;
            }
            case 'missing':
                message = 'Produsul nu are o locație configurată și nu există o locație implicită disponibilă. Selectează manual locația potrivită înainte de a continua.';
                break;
            case 'error':
                message = 'Nu am putut determina locația produsului. Încearcă din nou sau contactează un supervizor.';
                break;
            case 'idle':
            default:
                message = defaultMessage;
                break;
        }

        infoElement.innerHTML = message;
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
            const formData = new FormData();
            formData.append('product_id', this.selectedProductId);
            formData.append('quantity', qty);
            formData.append('print_copies', qty);
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

        const locationChoice = this.getSelectedProductionLocation();
        if (!locationChoice || !locationChoice.location_id) {
            this.showError('Selectează o locație înainte de a adăuga produsul în stoc.');
            return;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content') || this.config.csrfToken;

        try {
            const formData = new FormData();
            Object.entries(this.lastPrintData).forEach(([k, v]) => formData.append(k, v));
            formData.append('action', 'add_stock');
            formData.append('location_id', locationChoice.location_id);
            if (locationChoice.level_number !== null && locationChoice.level_number !== undefined && locationChoice.level_number !== '') {
                formData.append('level_number', locationChoice.level_number);
            }
            if (locationChoice.level_name) {
                formData.append('shelf_level', locationChoice.level_name);
            }

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
                    zone: suggestion.zone ?? suggestion.zone_name ?? null,
                    level_number: suggestion.level_number ?? suggestion.level ?? null,
                    subdivision_number: suggestion.subdivision_number ?? suggestion.subdivision ?? null
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

    buildLocationLevelDisplay(locationCode, levelName, levelNumber) {
        const code = (locationCode || '').toString().trim();
        let levelText = '';
        if (levelName && levelName.toString().trim()) {
            levelText = levelName.toString().trim();
        } else if (levelNumber !== null && levelNumber !== undefined && levelNumber !== '') {
            levelText = `Nivel ${levelNumber}`;
        }
        return levelText ? `${code} · ${levelText}` : code;
    }

    createLocationLevelValue(locationId, levelNumber) {
        const idPart = Number.isFinite(Number(locationId)) ? String(Number(locationId)) : String(locationId ?? '');
        const levelPart = levelNumber === null || levelNumber === undefined || levelNumber === ''
            ? ''
            : String(levelNumber);
        return `${idPart}|${levelPart}`;
    }

    findLocationLevelByValue(value) {
        if (!value) {
            return null;
        }
        const [locationPart, levelPart = ''] = value.split('|');
        const locationId = parseInt(locationPart, 10);
        if (Number.isNaN(locationId)) {
            return null;
        }
        const hasLevel = levelPart !== '';
        const targetLevel = hasLevel ? parseInt(levelPart, 10) : null;
        if (hasLevel && Number.isNaN(targetLevel)) {
            return null;
        }

        if (!Array.isArray(this.config.locationLevels)) {
            return null;
        }

        return this.config.locationLevels.find(level => {
            if (parseInt(level.location_id, 10) !== locationId) {
                return false;
            }
            const storedLevel = level.level_number;
            const normalizedStored = storedLevel === null || storedLevel === undefined
                ? null
                : parseInt(storedLevel, 10);
            return normalizedStored === (hasLevel ? targetLevel : null);
        }) || null;
    }

    sortLocationLevels() {
        if (!Array.isArray(this.config.locationLevels)) {
            this.config.locationLevels = [];
            return;
        }

        this.config.locationLevels.sort((a, b) => {
            const codeA = (a.location_code || '').toString();
            const codeB = (b.location_code || '').toString();
            const codeCompare = codeA.localeCompare(codeB, undefined, { sensitivity: 'base', numeric: true });
            if (codeCompare !== 0) {
                return codeCompare;
            }

            const aLevel = a.level_number;
            const bLevel = b.level_number;

            if (aLevel === bLevel) {
                return 0;
            }

            if (aLevel === null || aLevel === undefined) {
                return 1;
            }

            if (bLevel === null || bLevel === undefined) {
                return -1;
            }

            return aLevel - bLevel;
        });
    }

    populateProductionLocationOptions(selectedValue = '') {
        if (!this.productionLocationSelect) {
            return;
        }

        const select = this.productionLocationSelect;
        const placeholder = select.dataset.placeholder || 'Selectează nivelul de depozitare';
        const previousValue = select.value;

        select.innerHTML = '';
        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = placeholder;
        select.appendChild(placeholderOption);

        if (!Array.isArray(this.config.locationLevels)) {
            this.config.locationLevels = [];
        } else {
            this.sortLocationLevels();
            this.config.locationLevels.forEach(level => {
                const option = document.createElement('option');
                const value = this.createLocationLevelValue(level.location_id, level.level_number);
                option.value = value;
                option.textContent = level.display_code || this.buildLocationLevelDisplay(level.location_code, level.level_name, level.level_number);
                option.dataset.locationId = level.location_id;
                if (level.level_number !== null && level.level_number !== undefined) {
                    option.dataset.levelNumber = level.level_number;
                }
                if (level.level_name) {
                    option.dataset.levelName = level.level_name;
                }
                select.appendChild(option);
            });
        }

        const valueToApply = selectedValue !== undefined && selectedValue !== null && selectedValue !== ''
            ? selectedValue
            : (previousValue || '');
        select.value = valueToApply;
        this.updateProductionLocationSelection(false);
    }

    updateProductionLocationSelection(shouldUpdateMessage = false) {
        if (!this.productionLocationSelect) {
            this.selectedProductionLocation = null;
            return;
        }

        const selectedValue = this.productionLocationSelect.value;
        this.selectedProductionLocation = this.findLocationLevelByValue(selectedValue);

        if (shouldUpdateMessage) {
            if (this.selectedProductionLocation) {
                const details = {
                    location_code: this.selectedProductionLocation.location_code,
                    zone: this.selectedProductionLocation.zone,
                    level_number: this.selectedProductionLocation.level_number,
                    level_name: this.selectedProductionLocation.level_name
                };
                this.updateProductionLocationMessage('found', details);
            } else if (!selectedValue) {
                this.updateProductionLocationMessage('idle');
            }
        }
    }

    getSelectedProductionLocation() {
        return this.selectedProductionLocation;
    }

    ensureLocationLevelInConfig(details) {
        if (!details || details.location_id === undefined || details.location_id === null) {
            return;
        }

        const locationId = parseInt(details.location_id, 10);
        if (Number.isNaN(locationId)) {
            return;
        }

        const rawLevel = details.level_number ?? details.level ?? null;
        const levelNumber = rawLevel === null || rawLevel === undefined || rawLevel === ''
            ? null
            : parseInt(rawLevel, 10);

        if (!Array.isArray(this.config.locationLevels)) {
            this.config.locationLevels = [];
        }

        const index = this.config.locationLevels.findIndex(level => {
            if (parseInt(level.location_id, 10) !== locationId) {
                return false;
            }
            const storedLevel = level.level_number;
            const normalizedStored = storedLevel === null || storedLevel === undefined ? null : parseInt(storedLevel, 10);
            return normalizedStored === levelNumber;
        });

        const levelName = details.level_name || (levelNumber !== null ? `Nivel ${levelNumber}` : null);

        if (index >= 0) {
            const existing = this.config.locationLevels[index];
            const updated = { ...existing };
            if ((!updated.level_name || !updated.level_name.toString().trim()) && levelName) {
                updated.level_name = levelName;
            }
            if ((!updated.location_code || !updated.location_code.toString().trim()) && details.location_code) {
                updated.location_code = details.location_code;
            }
            if ((!updated.zone || !updated.zone.toString().trim()) && details.zone) {
                updated.zone = details.zone;
            }
            if ((!updated.type || !updated.type.toString().trim()) && details.type) {
                updated.type = details.type;
            }
            updated.level_number = levelNumber;
            updated.display_code = this.buildLocationLevelDisplay(updated.location_code || '', updated.level_name, levelNumber);
            this.config.locationLevels[index] = updated;
            return;
        }

        const entry = {
            location_id: locationId,
            location_code: details.location_code || '',
            zone: details.zone || null,
            type: details.type || null,
            level_number: levelNumber,
            level_name: levelName,
            display_code: this.buildLocationLevelDisplay(details.location_code || '', levelName, levelNumber)
        };
        this.config.locationLevels.push(entry);
        this.sortLocationLevels();
    }

    applySuggestedProductionLocation(details) {
        if (!details || !this.productionLocationSelect) {
            return;
        }

        this.ensureLocationLevelInConfig(details);
        const value = this.createLocationLevelValue(details.location_id, details.level_number ?? details.level ?? null);
        this.populateProductionLocationOptions(value);
        this.updateProductionLocationSelection(false);
    }

    applyFallbackProductionLocation(locationCode) {
        if (!this.productionLocationSelect) {
            return;
        }

        this.populateProductionLocationOptions(this.productionLocationSelect.value || '');

        if (!locationCode) {
            this.updateProductionLocationSelection(false);
            return;
        }

        const normalized = locationCode.toString().trim().toLowerCase();
        let match = (this.config.locationLevels || []).find(level => (level.location_code || '').toString().trim().toLowerCase() === normalized);

        if (!match && Array.isArray(this.config.locations)) {
            const fallbackBase = this.config.locations.find(loc => (loc.location_code || '').toString().trim().toLowerCase() === normalized);
            if (fallbackBase && fallbackBase.id) {
                this.ensureLocationLevelInConfig({
                    location_id: fallbackBase.id,
                    location_code: fallbackBase.location_code,
                    zone: fallbackBase.zone,
                    type: fallbackBase.type,
                    level_number: null
                });
                match = (this.config.locationLevels || []).find(level => (level.location_code || '').toString().trim().toLowerCase() === normalized);
            }
        }

        if (match) {
            const value = this.createLocationLevelValue(match.location_id, match.level_number);
            this.productionLocationSelect.value = value;
            this.updateProductionLocationSelection(false);
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

