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
        this.currentStep = 1;
        this.currentReceivingSession = null;
        this.selectedPurchaseOrder = null;
        this.receivingItems = [];
        this.scanner = null;
        this.scannerActive = false;
        
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

        // Manual input handlers
        const manualInputBtn = document.getElementById('manual-input-btn');
        if (manualInputBtn) {
            manualInputBtn.addEventListener('click', () => this.showManualInput());
        }

        // Step navigation
        const prevBtn = document.getElementById('prev-btn');
        const nextBtn = document.getElementById('next-btn');
        
        if (prevBtn) {
            prevBtn.addEventListener('click', () => this.previousStep());
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', () => this.nextStep());
        }
    }

    initializeCurrentStep() {
        const urlParams = new URLSearchParams(window.location.search);
        const step = urlParams.get('step');
        
        if (step) {
            this.currentStep = parseInt(step);
            this.showStep(this.currentStep);
        } else {
            this.showStep(1);
        }
    }

    showStep(step) {
        // Hide all steps
        document.querySelectorAll('.step-content').forEach(el => {
            el.style.display = 'none';
        });
        
        // Show current step
        const currentStepElement = document.getElementById(`step-${step}`);
        if (currentStepElement) {
            currentStepElement.style.display = 'block';
        }
        
        // Update step indicator
        this.updateStepIndicator(step);
        
        // Update navigation buttons
        this.updateNavigationButtons(step);
        
        this.currentStep = step;
    }

    updateStepIndicator(step) {
        document.querySelectorAll('.step-indicator .step').forEach((el, index) => {
            el.classList.remove('active', 'completed');
            if (index + 1 === step) {
                el.classList.add('active');
            } else if (index + 1 < step) {
                el.classList.add('completed');
            }
        });
    }

    updateNavigationButtons(step) {
        const prevBtn = document.getElementById('prev-btn');
        const nextBtn = document.getElementById('next-btn');
        const completeBtn = document.getElementById('complete-receiving-btn');
        
        if (prevBtn) prevBtn.style.display = step > 1 ? 'flex' : 'none';
        if (nextBtn) nextBtn.style.display = step < 3 ? 'flex' : 'none';
        if (completeBtn) completeBtn.style.display = step === 3 ? 'flex' : 'none';
    }

    async quickSearchPO(searchTerm) {
        if (!searchTerm || searchTerm.length < 2) {
            this.clearPOResults();
            return;
        }

        try {
            const response = await fetch(`${this.config.apiBase}/receiving/search_po.php?q=${encodeURIComponent(searchTerm)}`);
            const result = await response.json();
            
            if (response.ok) {
                this.displayPOResults(result.purchase_orders || []);
            } else {
                this.showError(result.message || 'Eroare la căutarea PO');
            }
        } catch (error) {
            console.error('Error searching PO:', error);
            this.showError('Eroare la căutarea PO');
        }
    }

    displayPOResults(purchaseOrders) {
        const resultsContainer = document.getElementById('po-search-results');
        if (!resultsContainer) return;

        if (purchaseOrders.length === 0) {
            resultsContainer.innerHTML = '<p class="no-results">Niciun PO găsit</p>';
            return;
        }

        resultsContainer.innerHTML = purchaseOrders.map(po => `
            <div class="po-result" data-po-id="${po.id}">
                <div class="po-header">
                    <div class="po-number">${po.po_number}</div>
                    <div class="po-date">${this.formatDate(po.created_at)}</div>
                </div>
                <div class="po-supplier">${po.supplier_name}</div>
                <div class="po-items">${po.items_count} produse</div>
                <button class="btn btn-primary" onclick="warehouseReceiving.selectPO('${po.id}')">
                    Selectează
                </button>
            </div>
        `).join('');
    }

    clearPOResults() {
        const resultsContainer = document.getElementById('po-search-results');
        if (resultsContainer) {
            resultsContainer.innerHTML = '';
        }
    }

    async selectPO(poId) {
        try {
            this.showLoading(true);
            
            const response = await fetch(`${this.config.apiBase}/receiving/select_po.php?po_id=${poId}`);
            const result = await response.json();
            
            if (response.ok) {
                this.selectedPurchaseOrder = result.purchase_order;
                this.displaySelectedPO();
                this.showSuccess('PO selectat cu succes');
            } else {
                this.showError(result.message || 'Eroare la selectarea PO');
            }
        } catch (error) {
            console.error('Error selecting PO:', error);
            this.showError('Eroare la selectarea PO');
        } finally {
            this.showLoading(false);
        }
    }

    displaySelectedPO() {
        const container = document.getElementById('selected-po-details');
        if (!container || !this.selectedPurchaseOrder) return;

        container.innerHTML = `
            <div class="selected-po-card">
                <h3>PO Selectat: ${this.selectedPurchaseOrder.po_number}</h3>
                <div class="po-details">
                    <div class="detail-item">
                        <span class="label">Furnizor:</span>
                        <span class="value">${this.selectedPurchaseOrder.supplier_name}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Data:</span>
                        <span class="value">${this.formatDate(this.selectedPurchaseOrder.created_at)}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Produse:</span>
                        <span class="value">${this.selectedPurchaseOrder.items_count}</span>
                    </div>
                </div>
                <button class="btn btn-success" onclick="warehouseReceiving.createReceivingSession()">
                    Începe Recepția
                </button>
            </div>
        `;
    }

    async createReceivingSession() {
        if (!this.selectedPurchaseOrder) {
            this.showError('Niciun PO selectat');
            return;
        }

        try {
            this.showLoading(true);
            
            const response = await fetch(`${this.config.apiBase}/receiving/create_session.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    purchase_order_id: this.selectedPurchaseOrder.id,
                    operator_id: window.USER_ID || 1
                })
            });
            
            const result = await response.json();
            
            if (response.ok) {
                this.currentReceivingSession = result.session;
                this.receivingItems = result.items || [];
                this.displayReceivingItems();
                this.showStep(3);
                this.showSuccess('Sesiune de recepție creată cu succes');
            } else {
                this.showError(result.message || 'Eroare la crearea sesiunii');
            }
        } catch (error) {
            console.error('Error creating session:', error);
            this.showError('Eroare la crearea sesiunii');
        } finally {
            this.showLoading(false);
        }
    }

    displayReceivingItems() {
        const container = document.getElementById('expected-items-list');
        if (!container) return;

        container.innerHTML = this.receivingItems.map(item => `
            <div class="expected-item" data-item-id="${item.id}">
                <div class="item-header">
                    <div>
                        <div class="item-name">${item.product_name}</div>
                        <div class="item-sku">${item.sku}</div>
                    </div>
                    <div class="item-status status-${item.status || 'pending'}">
                        ${this.getItemStatusText(item.status || 'pending')}
                    </div>
                </div>
                <div class="item-details">
                    <div class="detail-item">
                        <span class="label">Cantitate așteptată:</span>
                        <span class="value">${item.expected_quantity}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Cantitate primită:</span>
                        <span class="value">${item.received_quantity || 0}</span>
                    </div>
                    <div class="detail-item">
                        <span class="label">Locație:</span>
                        <span class="value">${item.location_code || 'Neasignată'}</span>
                    </div>
                </div>
                <div class="item-actions">
                    <button class="btn btn-sm btn-primary" onclick="warehouseReceiving.startReceivingItem(${item.id})">
                        Începe Recepția
                    </button>
                    <button class="btn btn-sm btn-success" onclick="warehouseReceiving.completeReceivingItem(${item.id})" 
                            ${item.status !== 'in_progress' ? 'disabled' : ''}>
                        Finalizează
                    </button>
                </div>
            </div>
        `).join('');
    }

    async startReceivingItem(itemId) {
        const item = this.receivingItems.find(i => i.id == itemId);
        if (!item) return;

        try {
            // Start timing silently
            await this.startItemTiming(item);
            
            // Update item status
            const response = await fetch(`${this.config.apiBase}/receiving/start_item.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    receiving_item_id: itemId,
                    operator_id: window.USER_ID || 1
                })
            });
            
            const result = await response.json();
            
            if (response.ok) {
                this.updateItemStatus(itemId, 'in_progress');
                this.showSuccess(`Recepția pentru ${item.product_name} a început`);
                this.displayReceivingItems(); // Refresh display
            } else {
                this.showError(result.message || 'Eroare la începerea recepției');
            }
        } catch (error) {
            console.error('Error starting item receiving:', error);
            this.showError('Eroare la începerea recepției');
        }
    }

    async completeReceivingItem(itemId) {
        const item = this.receivingItems.find(i => i.id == itemId);
        if (!item) return;

        // Show quantity input modal
        const quantityReceived = await this.showQuantityModal(item);
        if (quantityReceived === null) return;

        try {
            // Complete timing silently
            await this.completeItemTiming(itemId, quantityReceived);
            
            // Update item status
            const response = await fetch(`${this.config.apiBase}/receiving/complete_item.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    receiving_item_id: itemId,
                    quantity_received: quantityReceived,
                    operator_id: window.USER_ID || 1
                })
            });
            
            const result = await response.json();
            
            if (response.ok) {
                this.updateItemStatus(itemId, 'received');
                this.showSuccess(`Recepția pentru ${item.product_name} finalizată`);
                this.displayReceivingItems(); // Refresh display
                this.updateReceivingSummary();
            } else {
                this.showError(result.message || 'Eroare la finalizarea recepției');
            }
        } catch (error) {
            console.error('Error completing item receiving:', error);
            this.showError('Eroare la finalizarea recepției');
        }
    }

    showQuantityModal(item) {
        return new Promise((resolve) => {
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.innerHTML = `
                <div class="modal-content">
                    <h3>Cantitate Primită</h3>
                    <p>Produs: ${item.product_name}</p>
                    <p>Cantitate așteptată: ${item.expected_quantity}</p>
                    <div class="form-group">
                        <label>Cantitate primită:</label>
                        <input type="number" id="quantity-input" value="${item.expected_quantity}" min="0" step="1">
                    </div>
                    <div class="modal-actions">
                        <button class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove(); resolve(null);">
                            Anulează
                        </button>
                        <button class="btn btn-primary" onclick="
                            const qty = parseInt(document.getElementById('quantity-input').value);
                            this.closest('.modal-overlay').remove();
                            resolve(qty);
                        ">
                            Confirmă
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Focus on input
            setTimeout(() => {
                document.getElementById('quantity-input').focus();
            }, 100);
        });
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

    getItemStatusText(status) {
        const statusMap = {
            'pending': 'În așteptare',
            'in_progress': 'În curs',
            'received': 'Primit',
            'discrepancy': 'Discrepanță',
            'quality_hold': 'Reținut QC'
        };
        return statusMap[status] || status;
    }

    updateReceivingSummary() {
        const receivedCount = this.receivingItems.filter(item => item.status === 'received').length;
        const totalCount = this.receivingItems.length;
        const percentage = totalCount > 0 ? Math.round((receivedCount / totalCount) * 100) : 0;
        
        // Update summary display
        const summaryElement = document.getElementById('receiving-summary');
        if (summaryElement) {
            summaryElement.innerHTML = `
                <div class="summary-item">
                    <span class="label">Progres:</span>
                    <span class="value">${receivedCount}/${totalCount} (${percentage}%)</span>
                </div>
            `;
        }
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
            const confirmed = confirm(`Ai primit doar ${receivedCount} din ${expectedCount} produse. Continui?`);
            if (!confirmed) return;
        }

        try {
            this.showLoading(true);
            
            const response = await fetch(`${this.config.apiBase}/receiving/complete_session.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    session_id: this.currentReceivingSession.id,
                    operator_id: window.USER_ID || 1
                })
            });
            
            const result = await response.json();
            
            if (response.ok) {
                this.showSuccess('Recepția finalizată cu succes!');
                
                // Trigger dashboard refresh
                if (this.timingManager) {
                    this.timingManager.triggerDashboardRefresh();
                }
                
                // Redirect or reset
                setTimeout(() => {
                    window.location.href = 'warehouse_hub.php';
                }, 2000);
            } else {
                this.showError(result.message || 'Eroare la finalizarea recepției');
            }
        } catch (error) {
            console.error('Error completing receiving:', error);
            this.showError('Eroare la finalizarea recepției');
        } finally {
            this.showLoading(false);
        }
    }

    async loadRecentSessions() {
        try {
            const response = await fetch(`${this.config.apiBase}/receiving/recent_sessions.php`);
            const result = await response.json();
            
            if (response.ok) {
                this.displayRecentSessions(result.sessions || []);
            }
        } catch (error) {
            console.error('Error loading recent sessions:', error);
        }
    }

    displayRecentSessions(sessions) {
        const container = document.getElementById('recent-sessions');
        if (!container) return;

        if (sessions.length === 0) {
            container.innerHTML = '<p class="no-sessions">Nicio sesiune recentă</p>';
            return;
        }

        container.innerHTML = sessions.map(session => `
            <div class="session-item">
                <div class="session-header">
                    <span class="session-po">${session.po_number}</span>
                    <span class="session-date">${this.formatDate(session.created_at)}</span>
                </div>
                <div class="session-details">
                    <span class="session-supplier">${session.supplier_name}</span>
                    <span class="session-status status-${session.status}">${session.status}</span>
                </div>
                ${session.status === 'in_progress' ? `
                    <button class="btn btn-sm btn-primary" onclick="warehouseReceiving.resumeSession('${session.id}')">
                        Reia
                    </button>
                ` : ''}
            </div>
        `).join('');
    }

    async resumeSession(sessionId) {
        this.showLoading(true);
        
        try {
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

    // Scanner functions
    openScannerModal() {
        const modal = document.getElementById('scanner-modal');
        if (modal) {
            modal.style.display = 'block';
        }
    }

    async startScanner() {
        const cameraElement = document.getElementById('camera-preview');
        if (!cameraElement) return;

        try {
            this.scanner = new Html5QrCode("camera-preview");
            
            const qrCodeSuccessCallback = (decodedText, decodedResult) => {
                this.handleScanResult(decodedText);
            };
            
            const config = { fps: 10, qrbox: { width: 250, height: 250 } };
            
            await this.scanner.start(
                { facingMode: "environment" },
                config,
                qrCodeSuccessCallback
            );
            
            this.scannerActive = true;
            this.showSuccess('Scanner activat');
        } catch (error) {
            console.error('Error starting scanner:', error);
            this.showError('Eroare la pornirea scannerului');
        }
    }

    async stopScanner() {
        if (this.scanner && this.scannerActive) {
            try {
                await this.scanner.stop();
                this.scannerActive = false;
                this.showSuccess('Scanner oprit');
            } catch (error) {
                console.error('Error stopping scanner:', error);
            }
        }
    }

    handleScanResult(scannedCode) {
        console.log('Scanned code:', scannedCode);
        
        // Process the scanned code based on current context
        if (this.currentStep === 2) {
            // Scanning for PO
            this.quickSearchPO(scannedCode);
        } else if (this.currentStep === 3) {
            // Scanning for product
            this.handleProductScan(scannedCode);
        }
        
        // Close scanner modal
        this.closeScannerModal();
    }

    async handleProductScan(scannedCode) {
        // Try to find the product in current receiving items
        const item = this.receivingItems.find(i => 
            i.sku === scannedCode || 
            i.barcode === scannedCode || 
            i.product_code === scannedCode
        );
        
        if (item) {
            if (item.status === 'pending') {
                await this.startReceivingItem(item.id);
            } else if (item.status === 'in_progress') {
                await this.completeReceivingItem(item.id);
            }
        } else {
            this.showError('Produs negăsit în lista de recepție');
        }
    }

    closeScannerModal() {
        const modal = document.getElementById('scanner-modal');
        if (modal) {
            modal.style.display = 'none';
        }
        this.stopScanner();
    }

    // Utility functions
    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('ro-RO', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    showLoading(show) {
        const overlay = document.getElementById('loading-overlay');
        if (overlay) {
            overlay.style.display = show ? 'flex' : 'none';
        }
    }

    showSuccess(message) {
        this.showToast(message, 'success');
    }

    showError(message) {
        this.showToast(message, 'error');
    }

    showToast(message, type) {
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        
        // Add to page
        document.body.appendChild(toast);
        
        // Show toast
        setTimeout(() => toast.classList.add('show'), 100);
        
        // Remove toast
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => document.body.removeChild(toast), 300);
        }, 3000);
    }

    previousStep() {
        if (this.currentStep > 1) {
            this.showStep(this.currentStep - 1);
        }
    }

    nextStep() {
        if (this.currentStep < 3) {
            this.showStep(this.currentStep + 1);
        }
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.warehouseReceiving = new WarehouseReceiving();
});

// Export for use in other modules
window.WarehouseReceiving = WarehouseReceiving;