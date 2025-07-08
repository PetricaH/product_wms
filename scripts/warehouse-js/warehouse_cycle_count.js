/**
 * Warehouse Cycle Count JavaScript
 * Handles cycle counting workflow, barcode scanning, and count management
 * Following the WMS monochrome design language patterns
 */

class WarehouseCycleCount {
    constructor() {
        this.config = window.WMS_CONFIG || { baseUrl: '', apiBase: '/api' };
        this.scanner = null;
        this.currentCount = {
            id: null,
            location: '',
            items: [],
            status: 'pending'
        };
        this.scannerActive = false;
        this.currentCamera = 'environment';
        
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadDashboardData();
        this.setupScanner();
    }

    bindEvents() {
        // Scanner controls
        const startScanBtn = document.getElementById('start-scan');
        const stopScanBtn = document.getElementById('stop-scan');
        const switchCameraBtn = document.getElementById('switch-camera');
        
        if (startScanBtn) startScanBtn.addEventListener('click', () => this.startScanner());
        if (stopScanBtn) stopScanBtn.addEventListener('click', () => this.stopScanner());
        if (switchCameraBtn) switchCameraBtn.addEventListener('click', () => this.switchCamera());

        // Manual input
        const manualForm = document.getElementById('manual-count-form');
        if (manualForm) {
            manualForm.addEventListener('submit', (e) => this.handleManualInput(e));
        }

        // Count actions
        const submitCountBtn = document.getElementById('submit-count');
        const saveCountBtn = document.getElementById('save-count');
        const resetCountBtn = document.getElementById('reset-count');
        
        if (submitCountBtn) submitCountBtn.addEventListener('click', () => this.submitCount());
        if (saveCountBtn) saveCountBtn.addEventListener('click', () => this.saveCount());
        if (resetCountBtn) resetCountBtn.addEventListener('click', () => this.resetCount());

        // Location selector
        const locationSelect = document.getElementById('location-select');
        if (locationSelect) {
            locationSelect.addEventListener('change', (e) => this.selectLocation(e.target.value));
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleKeyboardShortcuts(e));
    }

    async loadDashboardData() {
        try {
            this.showLoading(true);
            
            const response = await fetch(`${this.config.apiBase}/cycle-count/dashboard`);
            if (!response.ok) throw new Error('Failed to load dashboard data');
            
            const data = await response.json();
            this.updateDashboard(data);
            
        } catch (error) {
            console.error('Dashboard load error:', error);
            this.showError('Failed to load dashboard data');
        } finally {
            this.showLoading(false);
        }
    }

    updateDashboard(data) {
        // Update dashboard cards
        const cards = {
            'pending-counts': data.pendingCounts || 0,
            'completed-today': data.completedToday || 0,
            'discrepancies': data.discrepancies || 0,
            'total-items': data.totalItems || 0
        };

        Object.entries(cards).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = value.toLocaleString();
            }
        });

        // Update recent counts list
        if (data.recentCounts) {
            this.updateRecentCountsList(data.recentCounts);
        }
    }

    updateRecentCountsList(counts) {
        const container = document.getElementById('recent-counts-list');
        if (!container) return;

        container.innerHTML = counts.map(count => `
            <div class="result-item" data-count-id="${count.id}">
                <div class="result-info">
                    <div class="result-product">${count.product_name}</div>
                    <div class="result-location">Location: ${count.location}</div>
                </div>
                <div class="result-counts">
                    <div class="count-display">
                        <div class="count-label">System</div>
                        <div class="count-value">${count.system_qty}</div>
                    </div>
                    <div class="count-display">
                        <div class="count-label">Counted</div>
                        <div class="count-value ${count.discrepancy ? 'discrepancy' : ''}">${count.counted_qty}</div>
                    </div>
                    <div class="status-indicator status-${count.status}">
                        ${count.status}
                    </div>
                </div>
            </div>
        `).join('');

        // Add click handlers for count items
        container.querySelectorAll('.result-item').forEach(item => {
            item.addEventListener('click', () => {
                const countId = item.dataset.countId;
                this.viewCountDetails(countId);
            });
        });
    }

    setupScanner() {
        const videoElement = document.getElementById('scanner-video');
        if (!videoElement) return;

        // Initialize Html5Qrcode scanner
        if (typeof Html5Qrcode !== 'undefined') {
            this.scanner = new Html5Qrcode("scanner-video");
        } else {
            console.warn('Html5Qrcode library not loaded');
            this.showError('Barcode scanner not available');
        }
    }

    async startScanner() {
        if (!this.scanner || this.scannerActive) return;

        try {
            const config = {
                fps: 10,
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0,
                supportedScanTypes: [Html5QrcodeScanType.SCAN_TYPE_CAMERA]
            };

            await this.scanner.start(
                { facingMode: this.currentCamera },
                config,
                (decodedText, decodedResult) => this.onScanSuccess(decodedText, decodedResult),
                (errorMessage) => this.onScanError(errorMessage)
            );

            this.scannerActive = true;
            this.updateScannerUI(true);
            this.updateScannerStatus('Scanning for barcodes...');

        } catch (error) {
            console.error('Scanner start error:', error);
            this.showError('Failed to start camera scanner');
        }
    }

    async stopScanner() {
        if (!this.scanner || !this.scannerActive) return;

        try {
            await this.scanner.stop();
            this.scannerActive = false;
            this.updateScannerUI(false);
            this.updateScannerStatus('Scanner stopped');
        } catch (error) {
            console.error('Scanner stop error:', error);
        }
    }

    async switchCamera() {
        if (!this.scannerActive) return;

        this.currentCamera = this.currentCamera === 'environment' ? 'user' : 'environment';
        
        await this.stopScanner();
        setTimeout(() => this.startScanner(), 500);
    }

    onScanSuccess(decodedText, decodedResult) {
        console.log('Barcode scanned:', decodedText);
        this.updateScannerStatus(`Scanned: ${decodedText}`);
        
        // Process the scanned barcode
        this.processScannedBarcode(decodedText);
        
        // Auto-stop scanner after successful scan
        setTimeout(() => this.stopScanner(), 1000);
    }

    onScanError(errorMessage) {
        // Ignore frequent scanning errors
        if (errorMessage.includes('NotFoundException')) return;
        console.log('Scan error:', errorMessage);
    }

    async processScannedBarcode(barcode) {
        try {
            this.showLoading(true);
            
            // Look up product by barcode
            const response = await fetch(`${this.config.apiBase}/products/lookup/${encodeURIComponent(barcode)}`);
            if (!response.ok) throw new Error('Product not found');
            
            const product = await response.json();
            
            // Auto-fill the manual form
            this.fillManualForm(product);
            
            this.showSuccess(`Product found: ${product.name}`);
            
        } catch (error) {
            console.error('Barcode processing error:', error);
            this.showError('Product not found. Please enter manually.');
            
            // Fill barcode field for manual entry
            const barcodeField = document.getElementById('barcode');
            if (barcodeField) barcodeField.value = barcode;
        } finally {
            this.showLoading(false);
        }
    }

    fillManualForm(product) {
        const fields = {
            'product-id': product.id,
            'product-name': product.name,
            'barcode': product.barcode,
            'expected-qty': product.current_stock || 0
        };

        Object.entries(fields).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) element.value = value;
        });

        // Focus on counted quantity field
        const countedQtyField = document.getElementById('counted-qty');
        if (countedQtyField) {
            countedQtyField.focus();
            countedQtyField.select();
        }
    }

    async handleManualInput(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        const countData = {
            product_id: formData.get('product-id'),
            product_name: formData.get('product-name'),
            barcode: formData.get('barcode'),
            location: formData.get('location'),
            expected_qty: parseInt(formData.get('expected-qty')) || 0,
            counted_qty: parseInt(formData.get('counted-qty')) || 0
        };

        // Validate required fields
        if (!countData.product_name || !countData.location || countData.counted_qty === '') {
            this.showError('Please fill in all required fields');
            return;
        }

        // Add to current count
        this.addItemToCount(countData);
        
        // Clear form for next item
        event.target.reset();
        document.getElementById('barcode').focus();
    }

    addItemToCount(itemData) {
        // Check if item already exists in current count
        const existingIndex = this.currentCount.items.findIndex(
            item => item.product_id === itemData.product_id && item.location === itemData.location
        );

        if (existingIndex >= 0) {
            // Update existing item
            this.currentCount.items[existingIndex] = itemData;
            this.showInfo('Item count updated');
        } else {
            // Add new item
            this.currentCount.items.push(itemData);
            this.showSuccess('Item added to count');
        }

        this.updateCountDisplay();
    }

    updateCountDisplay() {
        const container = document.getElementById('current-count-items');
        if (!container) return;

        if (this.currentCount.items.length === 0) {
            container.innerHTML = '<div class="empty-state">No items counted yet</div>';
            return;
        }

        container.innerHTML = this.currentCount.items.map((item, index) => {
            const discrepancy = item.counted_qty !== item.expected_qty;
            return `
                <div class="result-item" data-index="${index}">
                    <div class="result-info">
                        <div class="result-product">${item.product_name}</div>
                        <div class="result-location">Location: ${item.location}</div>
                    </div>
                    <div class="result-counts">
                        <div class="count-display">
                            <div class="count-label">Expected</div>
                            <div class="count-value">${item.expected_qty}</div>
                        </div>
                        <div class="count-display">
                            <div class="count-label">Counted</div>
                            <div class="count-value ${discrepancy ? 'discrepancy' : ''}">${item.counted_qty}</div>
                        </div>
                        <button class="scanner-btn secondary" onclick="cycleCount.removeItem(${index})">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    }

    removeItem(index) {
        if (index >= 0 && index < this.currentCount.items.length) {
            this.currentCount.items.splice(index, 1);
            this.updateCountDisplay();
            this.showInfo('Item removed from count');
        }
    }

    async selectLocation(locationCode) {
        if (!locationCode) return;
        
        this.currentCount.location = locationCode;
        
        // Load expected items for this location
        try {
            const response = await fetch(`${this.config.apiBase}/locations/${locationCode}/inventory`);
            if (response.ok) {
                const inventory = await response.json();
                this.updateLocationInfo(inventory);
            }
        } catch (error) {
            console.error('Location load error:', error);
        }
    }

    updateLocationInfo(inventory) {
        const infoContainer = document.getElementById('location-info');
        if (!infoContainer) return;

        const totalItems = inventory.length;
        const totalValue = inventory.reduce((sum, item) => sum + (item.quantity * item.unit_cost), 0);

        infoContainer.innerHTML = `
            <div class="location-summary">
                <h4>Location Summary</h4>
                <p>Total Items: ${totalItems}</p>
                <p>Total Value: $${totalValue.toFixed(2)}</p>
            </div>
        `;
    }

    async saveCount() {
        if (this.currentCount.items.length === 0) {
            this.showError('No items to save');
            return;
        }

        try {
            this.showLoading(true);
            
            const response = await fetch(`${this.config.apiBase}/cycle-count/save`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    location: this.currentCount.location,
                    items: this.currentCount.items,
                    status: 'draft'
                })
            });

            if (!response.ok) throw new Error('Failed to save count');
            
            const result = await response.json();
            this.currentCount.id = result.id;
            
            this.showSuccess('Count saved as draft');
            
        } catch (error) {
            console.error('Save error:', error);
            this.showError('Failed to save count');
        } finally {
            this.showLoading(false);
        }
    }

    async submitCount() {
        if (this.currentCount.items.length === 0) {
            this.showError('No items to submit');
            return;
        }

        if (!confirm('Are you sure you want to submit this count? This action cannot be undone.')) {
            return;
        }

        try {
            this.showLoading(true);
            
            const response = await fetch(`${this.config.apiBase}/cycle-count/submit`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    location: this.currentCount.location,
                    items: this.currentCount.items,
                    status: 'completed'
                })
            });

            if (!response.ok) throw new Error('Failed to submit count');
            
            const result = await response.json();
            
            this.showSuccess('Count submitted successfully!');
            this.resetCount();
            this.loadDashboardData(); // Refresh dashboard
            
        } catch (error) {
            console.error('Submit error:', error);
            this.showError('Failed to submit count');
        } finally {
            this.showLoading(false);
        }
    }

    resetCount() {
        this.currentCount = {
            id: null,
            location: '',
            items: [],
            status: 'pending'
        };
        
        // Clear forms
        const manualForm = document.getElementById('manual-count-form');
        if (manualForm) manualForm.reset();
        
        const locationSelect = document.getElementById('location-select');
        if (locationSelect) locationSelect.value = '';
        
        this.updateCountDisplay();
        this.showInfo('Count reset');
    }

    handleKeyboardShortcuts(event) {
        // Ctrl/Cmd + S to save
        if ((event.ctrlKey || event.metaKey) && event.key === 's') {
            event.preventDefault();
            this.saveCount();
        }
        
        // Ctrl/Cmd + Enter to submit
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
            event.preventDefault();
            this.submitCount();
        }
        
        // Escape to stop scanner
        if (event.key === 'Escape' && this.scannerActive) {
            this.stopScanner();
        }
    }

    updateScannerUI(isActive) {
        const startBtn = document.getElementById('start-scan');
        const stopBtn = document.getElementById('stop-scan');
        const switchBtn = document.getElementById('switch-camera');
        
        if (startBtn) startBtn.style.display = isActive ? 'none' : 'flex';
        if (stopBtn) stopBtn.style.display = isActive ? 'flex' : 'none';
        if (switchBtn) switchBtn.style.display = isActive ? 'flex' : 'none';
    }

    updateScannerStatus(message) {
        const statusElement = document.querySelector('.scanner-status');
        if (statusElement) {
            statusElement.textContent = message;
        }
    }

    // Utility methods for UI feedback
    showLoading(show) {
        const loader = document.getElementById('loading-overlay');
        if (loader) {
            loader.style.display = show ? 'flex' : 'none';
        }
    }

    showError(message) {
        this.showToast(message, 'error');
    }

    showSuccess(message) {
        this.showToast(message, 'success');
    }

    showInfo(message) {
        this.showToast(message, 'info');
    }

    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 3000);
    }

    // Public method to view count details (for dashboard clicks)
    async viewCountDetails(countId) {
        try {
            const response = await fetch(`${this.config.apiBase}/cycle-count/${countId}`);
            if (!response.ok) throw new Error('Count not found');
            
            const count = await response.json();
            this.displayCountDetails(count);
            
        } catch (error) {
            console.error('Count details error:', error);
            this.showError('Failed to load count details');
        }
    }

    displayCountDetails(count) {
        // This could open a modal or navigate to a details page
        console.log('Count details:', count);
        // Implementation depends on your UI framework/routing
    }
}

// Initialize the cycle count system when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.cycleCount = new WarehouseCycleCount();
});

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = WarehouseCycleCount;
}