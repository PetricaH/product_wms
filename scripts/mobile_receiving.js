/**
 * Mobile Receiving JavaScript
 * Handles mobile receiving workflow, barcode scanning, and receiving process management
 * Optimized for mobile devices following WMS monochrome design language
 */

class MobileReceiving {
    constructor() {
        this.config = window.WMS_CONFIG || { baseUrl: '', apiBase: '/api' };
        this.scanner = null;
        this.currentReceiving = {
            id: null,
            po_number: '',
            supplier: '',
            items: [],
            status: 'pending'
        };
        this.scannerActive = false;
        this.currentCamera = 'environment';
        this.workflowStep = 1; // 1: Scan PO, 2: Receive Items, 3: Confirm Receipt
        
        this.init();
    }

    init() {
        this.bindEvents();
        this.setupScanner();
        this.initializeWorkflow();
        this.handleOrientationChange();
    }

    bindEvents() {
        // Navigation
        const backBtn = document.getElementById('back-btn');
        if (backBtn) backBtn.addEventListener('click', () => this.navigateBack());

        // Scanner controls
        const startScanBtn = document.getElementById('start-scan');
        const stopScanBtn = document.getElementById('stop-scan');
        const toggleFlashBtn = document.getElementById('toggle-flash');
        const switchCameraBtn = document.getElementById('switch-camera');
        
        if (startScanBtn) startScanBtn.addEventListener('click', () => this.startScanner());
        if (stopScanBtn) stopScanBtn.addEventListener('click', () => this.stopScanner());
        if (toggleFlashBtn) toggleFlashBtn.addEventListener('click', () => this.toggleFlash());
        if (switchCameraBtn) switchCameraBtn.addEventListener('click', () => this.switchCamera());

        // Manual input forms
        const poForm = document.getElementById('po-form');
        const itemForm = document.getElementById('item-form');
        
        if (poForm) poForm.addEventListener('submit', (e) => this.handlePOInput(e));
        if (itemForm) itemForm.addEventListener('submit', (e) => this.handleItemInput(e));

        // Workflow actions
        const nextStepBtn = document.getElementById('next-step');
        const prevStepBtn = document.getElementById('prev-step');
        const completeReceivingBtn = document.getElementById('complete-receiving');
        const saveReceivingBtn = document.getElementById('save-receiving');
        
        if (nextStepBtn) nextStepBtn.addEventListener('click', () => this.nextStep());
        if (prevStepBtn) prevStepBtn.addEventListener('click', () => this.prevStep());
        if (completeReceivingBtn) completeReceivingBtn.addEventListener('click', () => this.completeReceiving());
        if (saveReceivingBtn) saveReceivingBtn.addEventListener('click', () => this.saveReceiving());

        // Item actions
        document.addEventListener('click', (e) => {
            if (e.target.matches('.edit-item-btn')) {
                const index = parseInt(e.target.dataset.index);
                this.editItem(index);
            }
            if (e.target.matches('.remove-item-btn')) {
                const index = parseInt(e.target.dataset.index);
                this.removeItem(index);
            }
        });

        // Touch and swipe gestures
        this.initializeTouchGestures();
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleKeyboardShortcuts(e));
    }

    initializeWorkflow() {
        this.updateWorkflowProgress();
        this.showWorkflowStep(this.workflowStep);
    }

    setupScanner() {
        const videoElement = document.getElementById('scanner-video');
        if (!videoElement) return;

        // Initialize Html5Qrcode scanner for mobile
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
            // Mobile-optimized scanner config
            const config = {
                fps: 10,
                qrbox: function(viewfinderWidth, viewfinderHeight) {
                    const minEdgePercentage = 0.7;
                    const minEdgeSize = Math.min(viewfinderWidth, viewfinderHeight);
                    const qrboxSize = Math.floor(minEdgeSize * minEdgePercentage);
                    return {
                        width: qrboxSize,
                        height: qrboxSize
                    };
                },
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
            this.updateScannerStatus('Scanning...');
            this.vibrate(50); // Haptic feedback

        } catch (error) {
            console.error('Scanner start error:', error);
            this.showError('Failed to start camera. Please check permissions.');
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
        this.showInfo(`Switched to ${this.currentCamera === 'environment' ? 'back' : 'front'} camera`);
    }

    onScanSuccess(decodedText, decodedResult) {
        console.log('Barcode scanned:', decodedText);
        this.updateScannerStatus(`Scanned: ${decodedText}`);
        this.vibrate(100); // Success haptic feedback
        
        // Process based on current workflow step
        switch (this.workflowStep) {
            case 1:
                this.processPOBarcode(decodedText);
                break;
            case 2:
                this.processItemBarcode(decodedText);
                break;
            default:
                this.showInfo('Barcode scanned but not processed in current step');
        }
        
        // Auto-stop scanner after successful scan
        setTimeout(() => this.stopScanner(), 1000);
    }

    onScanError(errorMessage) {
        // Ignore frequent scanning errors to avoid spam
        if (errorMessage.includes('NotFoundException')) return;
        if (errorMessage.includes('ChecksumException')) return;
        console.log('Scan error:', errorMessage);
    }

    async processPOBarcode(barcode) {
        try {
            this.showLoading(true);
            
            // Look up purchase order by barcode/number
            const response = await fetch(`${this.config.apiBase}/purchase-orders/lookup/${encodeURIComponent(barcode)}`);
            if (!response.ok) throw new Error('Purchase order not found');
            
            const po = await response.json();
            
            // Set up receiving for this PO
            this.currentReceiving.po_number = po.po_number;
            this.currentReceiving.supplier = po.supplier;
            this.currentReceiving.expected_items = po.items || [];
            
            // Fill PO form
            this.fillPOForm(po);
            
            this.showSuccess(`PO ${po.po_number} loaded successfully`);
            
        } catch (error) {
            console.error('PO processing error:', error);
            this.showError('Purchase order not found. Please enter manually.');
            
            // Fill PO number field for manual entry
            const poField = document.getElementById('po-number');
            if (poField) poField.value = barcode;
        } finally {
            this.showLoading(false);
        }
    }

    async processItemBarcode(barcode) {
        try {
            this.showLoading(true);
            
            // Look up product by barcode
            const response = await fetch(`${this.config.apiBase}/products/lookup/${encodeURIComponent(barcode)}`);
            if (!response.ok) throw new Error('Product not found');
            
            const product = await response.json();
            
            // Check if this item is expected in the PO
            const expectedItem = this.currentReceiving.expected_items?.find(
                item => item.product_id === product.id || item.barcode === barcode
            );
            
            // Auto-fill the item form
            this.fillItemForm(product, expectedItem);
            
            this.showSuccess(`Product found: ${product.name}`);
            
        } catch (error) {
            console.error('Item processing error:', error);
            this.showError('Product not found. Please enter manually.');
            
            // Fill barcode field for manual entry
            const barcodeField = document.getElementById('item-barcode');
            if (barcodeField) barcodeField.value = barcode;
        } finally {
            this.showLoading(false);
        }
    }

    fillPOForm(po) {
        const fields = {
            'po-number': po.po_number,
            'supplier-name': po.supplier_name,
            'expected-date': po.expected_date
        };

        Object.entries(fields).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) element.value = value || '';
        });
    }

    fillItemForm(product, expectedItem) {
        const fields = {
            'item-barcode': product.barcode,
            'product-name': product.name,
            'product-id': product.id,
            'expected-qty': expectedItem?.quantity || 0,
            'location': expectedItem?.location || ''
        };

        Object.entries(fields).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) element.value = value;
        });

        // Focus on received quantity field
        const receivedQtyField = document.getElementById('received-qty');
        if (receivedQtyField) {
            receivedQtyField.focus();
            receivedQtyField.select();
        }
    }

    async handlePOInput(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        const poData = {
            po_number: formData.get('po-number'),
            supplier_name: formData.get('supplier-name'),
            expected_date: formData.get('expected-date')
        };

        if (!poData.po_number) {
            this.showError('Please enter a PO number');
            return;
        }

        try {
            this.showLoading(true);
            
            // Validate PO exists
            const response = await fetch(`${this.config.apiBase}/purchase-orders/${poData.po_number}`);
            if (!response.ok) throw new Error('PO not found');
            
            const po = await response.json();
            this.currentReceiving.po_number = po.po_number;
            this.currentReceiving.supplier = po.supplier;
            this.currentReceiving.expected_items = po.items || [];
            
            this.nextStep();
            this.showSuccess('PO validated. Ready to receive items.');
            
        } catch (error) {
            console.error('PO validation error:', error);
            this.showError('Invalid PO number');
        } finally {
            this.showLoading(false);
        }
    }

    async handleItemInput(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        const itemData = {
            product_id: formData.get('product-id'),
            product_name: formData.get('product-name'),
            barcode: formData.get('item-barcode'),
            expected_qty: parseInt(formData.get('expected-qty')) || 0,
            received_qty: parseInt(formData.get('received-qty')) || 0,
            location: formData.get('location'),
            condition: formData.get('condition') || 'good',
            notes: formData.get('notes') || ''
        };

        // Validate required fields
        if (!itemData.product_name || !itemData.received_qty || !itemData.location) {
            this.showError('Please fill in all required fields');
            return;
        }

        // Add to received items
        this.addItemToReceiving(itemData);
        
        // Clear form for next item
        event.target.reset();
        document.getElementById('item-barcode').focus();
    }

    addItemToReceiving(itemData) {
        // Check if item already exists
        const existingIndex = this.currentReceiving.items.findIndex(
            item => item.product_id === itemData.product_id && item.location === itemData.location
        );

        if (existingIndex >= 0) {
            // Update existing item
            this.currentReceiving.items[existingIndex] = itemData;
            this.showInfo('Item updated');
        } else {
            // Add new item
            itemData.id = Date.now(); // Temporary ID
            this.currentReceiving.items.push(itemData);
            this.showSuccess('Item added to receiving');
        }

        this.updateItemsDisplay();
        this.updateWorkflowProgress();
    }

    updateItemsDisplay() {
        const container = document.getElementById('received-items-list');
        if (!container) return;

        if (this.currentReceiving.items.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <span class="material-symbols-outlined">inventory_2</span>
                    <p>No items received yet</p>
                </div>
            `;
            return;
        }

        container.innerHTML = this.currentReceiving.items.map((item, index) => {
            const isDiscrepancy = item.received_qty !== item.expected_qty;
            const statusClass = item.condition === 'damaged' ? 'status-partial' : 'status-complete';
            
            return `
                <div class="item-card">
                    <div class="item-info">
                        <div class="item-details">
                            <div class="item-name">${item.product_name}</div>
                            <div class="item-sku">SKU: ${item.barcode || 'N/A'}</div>
                            <div class="item-location">Location: ${item.location}</div>
                        </div>
                        <div class="item-quantity">
                            <div class="quantity-value ${isDiscrepancy ? 'discrepancy' : ''}">${item.received_qty}</div>
                            <div class="quantity-label">Received</div>
                        </div>
                    </div>
                    <div class="item-meta">
                        <div class="status-badge ${statusClass}">${item.condition}</div>
                        ${item.notes ? `<div class="item-notes">${item.notes}</div>` : ''}
                    </div>
                    <div class="item-actions">
                        <button class="item-btn edit-item-btn" data-index="${index}">
                            <span class="material-symbols-outlined">edit</span>
                        </button>
                        <button class="item-btn danger remove-item-btn" data-index="${index}">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    }

    editItem(index) {
        const item = this.currentReceiving.items[index];
        if (!item) return;

        // Fill form with item data
        this.fillItemForm(item);
        
        // Remove item so it can be re-added
        this.removeItem(index);
        
        this.showInfo('Item loaded for editing');
    }

    removeItem(index) {
        if (index >= 0 && index < this.currentReceiving.items.length) {
            this.currentReceiving.items.splice(index, 1);
            this.updateItemsDisplay();
            this.updateWorkflowProgress();
            this.showInfo('Item removed');
            this.vibrate(50);
        }
    }

    nextStep() {
        if (this.workflowStep < 3) {
            this.workflowStep++;
            this.updateWorkflowProgress();
            this.showWorkflowStep(this.workflowStep);
            this.vibrate(50);
        }
    }

    prevStep() {
        if (this.workflowStep > 1) {
            this.workflowStep--;
            this.updateWorkflowProgress();
            this.showWorkflowStep(this.workflowStep);
            this.vibrate(50);
        }
    }

    updateWorkflowProgress() {
        const steps = document.querySelectorAll('.step-circle');
        const labels = document.querySelectorAll('.step-label');
        
        steps.forEach((step, index) => {
            const stepNumber = index + 1;
            step.classList.remove('active', 'completed');
            
            if (stepNumber < this.workflowStep) {
                step.classList.add('completed');
                step.innerHTML = '<span class="material-symbols-outlined">check</span>';
            } else if (stepNumber === this.workflowStep) {
                step.classList.add('active');
                step.textContent = stepNumber;
            } else {
                step.textContent = stepNumber;
            }
        });

        labels.forEach((label, index) => {
            label.classList.toggle('active', index + 1 === this.workflowStep);
        });

        // Update items count badge
        const itemsCount = document.getElementById('items-count');
        if (itemsCount) {
            itemsCount.textContent = this.currentReceiving.items.length;
        }
    }

    showWorkflowStep(step) {
        // Hide all workflow sections
        document.querySelectorAll('.workflow-section').forEach(section => {
            section.style.display = 'none';
        });

        // Show current step section
        const currentSection = document.getElementById(`step-${step}`);
        if (currentSection) {
            currentSection.style.display = 'block';
        }

        // Update navigation buttons
        const prevBtn = document.getElementById('prev-step');
        const nextBtn = document.getElementById('next-step');
        const completeBtn = document.getElementById('complete-receiving');

        if (prevBtn) prevBtn.style.display = step > 1 ? 'flex' : 'none';
        if (nextBtn) nextBtn.style.display = step < 3 ? 'flex' : 'none';
        if (completeBtn) completeBtn.style.display = step === 3 ? 'flex' : 'none';
    }

    async saveReceiving() {
        if (this.currentReceiving.items.length === 0) {
            this.showError('No items to save');
            return;
        }

        try {
            this.showLoading(true);
            
            const response = await fetch(`${this.config.apiBase}/receiving/save`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    po_number: this.currentReceiving.po_number,
                    supplier: this.currentReceiving.supplier,
                    items: this.currentReceiving.items,
                    status: 'draft'
                })
            });

            if (!response.ok) throw new Error('Failed to save receiving');
            
            const result = await response.json();
            this.currentReceiving.id = result.id;
            
            this.showSuccess('Receiving saved as draft');
            this.vibrate(100);
            
        } catch (error) {
            console.error('Save error:', error);
            this.showError('Failed to save receiving');
        } finally {
            this.showLoading(false);
        }
    }

    async completeReceiving() {
        if (this.currentReceiving.items.length === 0) {
            this.showError('No items to receive');
            return;
        }

        if (!confirm('Complete this receiving? This will update inventory levels.')) {
            return;
        }

        try {
            this.showLoading(true);
            
            const response = await fetch(`${this.config.apiBase}/receiving/complete`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    po_number: this.currentReceiving.po_number,
                    supplier: this.currentReceiving.supplier,
                    items: this.currentReceiving.items,
                    status: 'completed'
                })
            });

            if (!response.ok) throw new Error('Failed to complete receiving');
            
            const result = await response.json();
            
            this.showSuccess('Receiving completed successfully!');
            this.vibrate(200); // Success haptic
            
            // Reset for new receiving
            setTimeout(() => {
                this.resetReceiving();
            }, 2000);
            
        } catch (error) {
            console.error('Complete error:', error);
            this.showError('Failed to complete receiving');
        } finally {
            this.showLoading(false);
        }
    }

    resetReceiving() {
        this.currentReceiving = {
            id: null,
            po_number: '',
            supplier: '',
            items: [],
            status: 'pending'
        };
        
        this.workflowStep = 1;
        
        // Clear all forms
        document.querySelectorAll('form').forEach(form => form.reset());
        
        this.updateWorkflowProgress();
        this.showWorkflowStep(1);
        this.updateItemsDisplay();
        
        this.showInfo('Ready for new receiving');
    }

    initializeTouchGestures() {
        let startX, startY;
        
        document.addEventListener('touchstart', (e) => {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        });
        
        document.addEventListener('touchmove', (e) => {
            if (!startX || !startY) return;
            
            const diffX = startX - e.touches[0].clientX;
            const diffY = startY - e.touches[0].clientY;
            
            // Swipe detection
            if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 50) {
                if (diffX > 0) {
                    // Swipe left - next step
                    this.nextStep();
                } else {
                    // Swipe right - previous step
                    this.prevStep();
                }
                
                startX = null;
                startY = null;
            }
        });
    }

    handleOrientationChange() {
        window.addEventListener('orientationchange', () => {
            // Restart scanner on orientation change
            if (this.scannerActive) {
                setTimeout(() => {
                    this.stopScanner();
                    setTimeout(() => this.startScanner(), 500);
                }, 500);
            }
        });
    }

    handleKeyboardShortcuts(event) {
        // Ctrl/Cmd + S to save
        if ((event.ctrlKey || event.metaKey) && event.key === 's') {
            event.preventDefault();
            this.saveReceiving();
        }
        
        // Ctrl/Cmd + Enter to complete
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
            event.preventDefault();
            if (this.workflowStep === 3) {
                this.completeReceiving();
            }
        }
        
        // Escape to stop scanner
        if (event.key === 'Escape' && this.scannerActive) {
            this.stopScanner();
        }
        
        // Arrow keys for navigation
        if (event.key === 'ArrowRight') {
            this.nextStep();
        }
        if (event.key === 'ArrowLeft') {
            this.prevStep();
        }
    }

    navigateBack() {
        if (this.workflowStep > 1) {
            this.prevStep();
        } else {
            // Navigate to previous page or show confirmation
            if (confirm('Are you sure you want to leave? Unsaved changes will be lost.')) {
                window.history.back();
            }
        }
    }

    updateScannerUI(isActive) {
        const startBtn = document.getElementById('start-scan');
        const stopBtn = document.getElementById('stop-scan');
        const flashBtn = document.getElementById('toggle-flash');
        const switchBtn = document.getElementById('switch-camera');
        
        if (startBtn) startBtn.style.display = isActive ? 'none' : 'flex';
        if (stopBtn) stopBtn.style.display = isActive ? 'flex' : 'none';
        if (flashBtn) flashBtn.style.display = isActive ? 'flex' : 'none';
        if (switchBtn) switchBtn.style.display = isActive ? 'flex' : 'none';
    }

    updateScannerStatus(message) {
        const statusElement = document.querySelector('.scanner-status');
        if (statusElement) {
            statusElement.textContent = message;
        }
    }

    // Haptic feedback for mobile devices
    vibrate(duration = 50) {
        if ('vibrate' in navigator) {
            navigator.vibrate(duration);
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
}

// Initialize the mobile receiving system when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.mobileReceiving = new MobileReceiving();
});

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = MobileReceiving;
}