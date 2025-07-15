// File: scripts/warehouse-js/mobile_picker.js - Improved Mobile Picker

console.log("✅ Improved Mobile Picker Script Loaded!");

document.addEventListener('DOMContentLoaded', () => {
    console.log("DOM Loaded. Initializing improved mobile picker script.");

    // Configuration
    const API_BASE = window.WMS_CONFIG?.apiBase || '/api';
    const GET_ORDER_DETAILS_URL = `${API_BASE}/warehouse/order_details.php`;
    const UPDATE_PICK_URL = `${API_BASE}/picking/update_pick.php`;
    
    // Global State
    let currentOrder = null;
    let orderItems = [];
    let currentItem = null;
    let currentStep = 'list'; // 'list', 'location', 'product', 'quantity'
    let scannedLocation = null;
    let scannedProduct = null;
    let html5QrCode = null;

    // DOM Elements
    const elements = {
        // Header & Info
        orderInfo: document.getElementById('order-info'),
        currentOrderNumber: document.getElementById('current-order-number'),
        customerName: document.getElementById('customer-name'),
        printInvoiceBtn: document.getElementById('print-invoice-btn'),
        
        // Progress
        progressSection: document.getElementById('progress-section'),
        itemsCompleted: document.getElementById('items-completed'),
        itemsTotal: document.getElementById('items-total'),
        progressPercentage: document.getElementById('progress-percentage'),
        progressFill: document.getElementById('progress-fill'),
        
        // Order Input
        orderInputSection: document.getElementById('order-input-section'),
        orderInput: document.getElementById('order-input'),
        loadOrderBtn: document.getElementById('load-order-btn'),
        
        // Picking List
        pickingListSection: document.getElementById('picking-list-section'),
        itemsContainer: document.getElementById('items-container'),
        refreshItemsBtn: document.getElementById('refresh-items-btn'),
        
        // Workflow Steps
        locationStep: document.getElementById('location-step'),
        productStep: document.getElementById('product-step'),
        quantityStep: document.getElementById('quantity-step'),
        
        // Location Step
        targetLocation: document.getElementById('target-location'),
        locationScanSection: document.getElementById('location-scan-section'),
        locationManualSection: document.getElementById('location-manual-section'),
        scanLocationBtn: document.getElementById('scan-location-btn'),
        manualLocationBtn: document.getElementById('manual-location-btn'),
        locationInput: document.getElementById('location-input'),
        verifyLocationBtn: document.getElementById('verify-location-btn'),
        backToScanLocation: document.getElementById('back-to-scan-location'),
        
        // Product Step
        targetProductName: document.getElementById('target-product-name'),
        targetProductSku: document.getElementById('target-product-sku'),
        productScanSection: document.getElementById('product-scan-section'),
        productManualSection: document.getElementById('product-manual-section'),
        scanProductBtn: document.getElementById('scan-product-btn'),
        manualProductBtn: document.getElementById('manual-product-btn'),
        productInput: document.getElementById('product-input'),
        verifyProductBtn: document.getElementById('verify-product-btn'),
        backToScanProduct: document.getElementById('back-to-scan-product'),
        
        // Quantity Step
        requiredQuantity: document.getElementById('required-quantity'),
        pickedQuantityInput: document.getElementById('picked-quantity-input'),
        qtyDecrease: document.getElementById('qty-decrease'),
        qtyIncrease: document.getElementById('qty-increase'),
        confirmQuantityBtn: document.getElementById('confirm-quantity-btn'),
        backToProduct: document.getElementById('back-to-product'),
        
        // Scanner
        scannerContainer: document.getElementById('scanner-container'),
        scannerTitle: document.getElementById('scanner-title'),
        scannerReader: document.getElementById('scanner-reader'),
        closeScanner: document.getElementById('close-scanner'),
        scannerManualInput: document.getElementById('scanner-manual-input'),
        
        // Loading & Messages
        loadingOverlay: document.getElementById('loading-overlay'),
        messageContainer: document.getElementById('message-container'),
        
        // Completion
        completionSection: document.getElementById('completion-section')
    };

    // Initialize
    init();

    function init() {
        setupEventListeners();
        
        // Auto-load order if provided in URL
        if (window.PICKER_CONFIG?.hasOrderFromUrl && window.PICKER_CONFIG?.orderFromUrl) {
            loadOrder(window.PICKER_CONFIG.orderFromUrl);
        }
    }

    function setupEventListeners() {
        // Order input
        elements.loadOrderBtn?.addEventListener('click', handleLoadOrder);
        elements.orderInput?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') handleLoadOrder();
        });
        
        // Refresh
        elements.refreshItemsBtn?.addEventListener('click', () => {
            if (currentOrder) loadOrderItems(currentOrder.id);
        });
        
        // Location Step
        elements.scanLocationBtn?.addEventListener('click', () => startScanner('location'));
        elements.manualLocationBtn?.addEventListener('click', showManualLocationInput);
        elements.verifyLocationBtn?.addEventListener('click', verifyLocation);
        elements.backToScanLocation?.addEventListener('click', showLocationScanOptions);
        
        // Product Step  
        elements.scanProductBtn?.addEventListener('click', () => startScanner('product'));
        elements.manualProductBtn?.addEventListener('click', showManualProductInput);
        elements.verifyProductBtn?.addEventListener('click', verifyProduct);
        elements.backToScanProduct?.addEventListener('click', showProductScanOptions);
        
        // Quantity Step
        elements.qtyDecrease?.addEventListener('click', () => adjustQuantity(-1));
        elements.qtyIncrease?.addEventListener('click', () => adjustQuantity(1));
        elements.confirmQuantityBtn?.addEventListener('click', confirmPick);
        elements.backToProduct?.addEventListener('click', () => showStep('product'));

        elements.printInvoiceBtn?.addEventListener('click', printInvoice);
        
        // Scanner
        elements.closeScanner?.addEventListener('click', closeScanner);
        elements.scannerManualInput?.addEventListener('click', handleScannerManualInput);
        
        // Quantity input validation
        elements.pickedQuantityInput?.addEventListener('input', validateQuantityInput);
    }

    async function handleLoadOrder() {
        const orderNumber = elements.orderInput?.value?.trim();
        if (!orderNumber) {
            showMessage('Vă rugăm să introduceți numărul comenzii.', 'error');
            return;
        }
        
        await loadOrder(orderNumber);
    }

    async function loadOrder(orderNumber) {
        try {
            showLoading(true);
            console.log('Loading order:', orderNumber);
            
            const response = await fetch(`${GET_ORDER_DETAILS_URL}?order_id=${encodeURIComponent(orderNumber)}`);
            const responseText = await response.text();
            
            console.log('Raw API response:', responseText);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${responseText}`);
            }
            
            const data = JSON.parse(responseText);
            console.log('Parsed API data:', data);
            
            if (data.status === 'error') {
                throw new Error(data.message);
            }
            
            // Fix: Handle the correct API response structure
            currentOrder = data.data || data.order;
            orderItems = currentOrder.items || data.items || [];
            
            console.log('Current order:', currentOrder);
            console.log('Order items:', orderItems);
            
            if (!orderItems || orderItems.length === 0) {
                console.warn('No items found in order!');
                showMessage('Această comandă nu conține produse pentru colectare.', 'info');
                return;
            }
            
            updateOrderDisplay();
            updateProgressDisplay();
            displayOrderItems();
            
            // Switch to picking list view
            elements.orderInputSection?.classList.add('hidden');
            elements.pickingListSection?.classList.remove('hidden');
            elements.orderInfo?.classList.remove('hidden');
            elements.progressSection?.classList.remove('hidden');
            
            showMessage('Comandă încărcată cu succes!', 'success');
            
        } catch (error) {
            console.error('Error loading order:', error);
            showMessage(`Eroare la încărcarea comenzii: ${error.message}`, 'error');
        } finally {
            showLoading(false);
        }
    }

    function updateOrderDisplay() {
        if (!currentOrder) return;

        if (elements.currentOrderNumber) {
            elements.currentOrderNumber.textContent = currentOrder.order_number;
        }

        if (elements.customerName) {
            elements.customerName.textContent = currentOrder.customer_name || 'Client necunoscut';
        }

        if (elements.printInvoiceBtn) {
            elements.printInvoiceBtn.classList.remove('hidden');
        }
    }

    function updateProgressDisplay() {
        if (!orderItems || orderItems.length === 0) return;
        
        const totalItems = orderItems.length;
        const completedItems = orderItems.filter(item => 
            (item.picked_quantity || 0) >= (item.quantity_ordered || 0)
        ).length;
        
        const progressPercent = totalItems > 0 ? Math.round((completedItems / totalItems) * 100) : 0;
        
        if (elements.itemsCompleted) elements.itemsCompleted.textContent = completedItems;
        if (elements.itemsTotal) elements.itemsTotal.textContent = totalItems;
        if (elements.progressPercentage) elements.progressPercentage.textContent = `${progressPercent}%`;
        if (elements.progressFill) elements.progressFill.style.width = `${progressPercent}%`;
        
        // Check if order is complete
        if (completedItems === totalItems && totalItems > 0) {
            setTimeout(() => showCompletion(), 1000);
        }
    }

    function displayOrderItems() {
        if (!elements.itemsContainer || !orderItems) return;
        
        elements.itemsContainer.innerHTML = '';
        
        orderItems.forEach((item, index) => {
            const itemCard = createItemCard(item, index);
            elements.itemsContainer.appendChild(itemCard);
        });
    }

    function createItemCard(item, index) {
        const quantityOrdered = parseInt(item.quantity_ordered) || 0;
        const quantityPicked = parseInt(item.picked_quantity) || 0;
        const remaining = Math.max(0, quantityOrdered - quantityPicked);
        
        let status = 'pending';
        let statusText = 'Pending';
        
        if (quantityPicked > 0 && quantityPicked < quantityOrdered) {
            status = 'progress';
            statusText = 'În progres';
        } else if (quantityPicked >= quantityOrdered) {
            status = 'completed';
            statusText = 'Completat';
        }
        
        const card = document.createElement('div');
        card.className = `item-card ${status}`;
        card.onclick = () => startPickingWorkflow(item, index);
        
        card.innerHTML = `
            <div class="item-header">
                <div class="item-info">
                    <div class="product-name">${escapeHtml(item.product_name || 'Produs necunoscut')}</div>
                    <div class="product-sku">${escapeHtml(item.sku || 'N/A')}</div>
                </div>
                <div class="status-badge status-${status}">${statusText}</div>
            </div>
            
            <div class="item-details">
                <div class="item-detail">
                    <span class="material-symbols-outlined">pin_drop</span>
                    <span>${escapeHtml(item.location_code || 'N/A')}</span>
                </div>
                <div class="item-detail">
                    <span class="material-symbols-outlined">inventory</span>
                    <span>${quantityPicked}/${quantityOrdered}</span>
                </div>
                ${remaining > 0 ? `
                    <div class="item-detail">
                        <span class="material-symbols-outlined">schedule</span>
                        <span>Rămâne: ${remaining}</span>
                    </div>
                ` : ''}
            </div>
        `;
        
        return card;
    }

    // Workflow Management Functions
    function startPickingWorkflow(item, index) {
        currentItem = { ...item, index };
        scannedLocation = null;
        scannedProduct = null;

        // Show location step only if a location code exists
        if (item.location_code) {
            const expectedLocation = item.location_code;
            if (elements.targetLocation) elements.targetLocation.textContent = expectedLocation;
            showStep('location');
        } else {
            // No location specified, skip directly to product verification
            scannedLocation = '';
            showStep('product');
        }
    }

    function showStep(stepName) {
        currentStep = stepName;
        
        // Hide all workflow steps
        const allSteps = ['location', 'product', 'quantity'];
        allSteps.forEach(step => {
            const stepElement = elements[`${step}Step`];
            if (stepElement) stepElement.classList.add('hidden');
        });
        
        // Hide other sections
        elements.pickingListSection?.classList.add('hidden');
        elements.orderInputSection?.classList.add('hidden');
        
        // Show target step
        const targetStep = elements[`${stepName}Step`];
        if (targetStep) {
            targetStep.classList.remove('hidden');
            
            // Setup step-specific data
            if (stepName === 'product' && currentItem) {
                if (elements.targetProductName) elements.targetProductName.textContent = currentItem.product_name || 'Produs necunoscut';
                if (elements.targetProductSku) elements.targetProductSku.textContent = currentItem.sku || 'N/A';
            } else if (stepName === 'quantity' && currentItem) {
                const quantityOrdered = parseInt(currentItem.quantity_ordered) || 0;
                const quantityPicked = parseInt(currentItem.picked_quantity) || 0;
                const remaining = Math.max(0, quantityOrdered - quantityPicked);
                
                if (elements.requiredQuantity) elements.requiredQuantity.textContent = remaining;
                if (elements.pickedQuantityInput) {
                    elements.pickedQuantityInput.value = remaining;
                    elements.pickedQuantityInput.max = remaining;
                }
            }
        }
    }

    function showLocationScanOptions() {
        if (elements.locationScanSection) elements.locationScanSection.classList.remove('hidden');
        if (elements.locationManualSection) elements.locationManualSection.classList.add('hidden');
    }

    function showManualLocationInput() {
        if (elements.locationScanSection) elements.locationScanSection.classList.add('hidden');
        if (elements.locationManualSection) elements.locationManualSection.classList.remove('hidden');
        if (elements.locationInput) elements.locationInput.focus();
    }

    function verifyLocation() {
        // If no location code is provided for the item, skip verification
        if (!currentItem.location_code) {
            scannedLocation = '';
            showStep('product');
            return;
        }

        const inputLocation = elements.locationInput?.value?.trim().toUpperCase();
        const expectedLocation = currentItem.location_code.toUpperCase();

        if (!inputLocation) {
            showMessage('Vă rugăm să introduceți codul locației.', 'error');
            return;
        }

        if (inputLocation === expectedLocation) {
            scannedLocation = inputLocation;
            showMessage('Locație verificată cu succes!', 'success');
            setTimeout(() => showStep('product'), 1000);
        } else {
            showMessage(`Locație incorectă! Așteptat: ${expectedLocation}, Introdus: ${inputLocation}`, 'error');
        }
    }

    function showProductScanOptions() {
        if (elements.productScanSection) elements.productScanSection.classList.remove('hidden');
        if (elements.productManualSection) elements.productManualSection.classList.add('hidden');
    }

    function showManualProductInput() {
        if (elements.productScanSection) elements.productScanSection.classList.add('hidden');
        if (elements.productManualSection) elements.productManualSection.classList.remove('hidden');
        if (elements.productInput) elements.productInput.focus();
    }

    function verifyProduct() {
        const inputProduct = elements.productInput?.value?.trim().toUpperCase();
        const expectedSku = (currentItem.sku || '').toUpperCase();
        const expectedBarcode = (currentItem.product_barcode || '').toUpperCase();
        
        if (!inputProduct) {
            showMessage('Vă rugăm să introduceți codul produsului.', 'error');
            return;
        }
        
        if (inputProduct === expectedSku || inputProduct === expectedBarcode) {
            scannedProduct = inputProduct;
            showMessage('Produs verificat cu succes!', 'success');
            setTimeout(() => showStep('quantity'), 1000);
        } else {
            showMessage(`Produs incorect! Așteptat: ${expectedSku}, Introdus: ${inputProduct}`, 'error');
        }
    }

    // Scanner Functions
    function startScanner(type) {
        if (elements.scannerContainer) {
            elements.scannerContainer.classList.remove('hidden');
            if (elements.scannerTitle) {
                elements.scannerTitle.textContent = type === 'location' ? 'Scanare Locație' : 'Scanare Produs';
            }
            
            // Initialize HTML5 QR Code scanner
            try {
                if (!html5QrCode) {
                    html5QrCode = new Html5Qrcode("scanner-reader");
                }
                
                const config = { fps: 10, qrbox: { width: 250, height: 250 } };
                
                html5QrCode.start(
                    { facingMode: "environment" },
                    config,
                    (decodedText) => {
                        handleScanSuccess(decodedText, type);
                    },
                    (errorMessage) => {
                        // Ignore scan errors - they're normal
                    }
                ).catch(err => {
                    console.error('Camera access failed:', err);
                    showMessage('Nu se poate accesa camera. Folosiți introducerea manuală.', 'error');
                    closeScanner();
                });
                
            } catch (error) {
                console.error('Scanner initialization failed:', error);
                showMessage('Scanner indisponibil. Folosiți introducerea manuală.', 'error');
                closeScanner();
            }
        }
    }

    function handleScanSuccess(decodedText, type) {
        console.log('Scanned:', decodedText, 'Type:', type);
        
        closeScanner();
        
        if (type === 'location') {
            if (elements.locationInput) elements.locationInput.value = decodedText;
            verifyLocation();
        } else if (type === 'product') {
            if (elements.productInput) elements.productInput.value = decodedText;
            verifyProduct();
        }
    }

    function closeScanner() {
        if (html5QrCode) {
            html5QrCode.stop().then(() => {
                if (elements.scannerContainer) elements.scannerContainer.classList.add('hidden');
            }).catch(err => {
                console.error('Error stopping scanner:', err);
                if (elements.scannerContainer) elements.scannerContainer.classList.add('hidden');
            });
        } else {
            if (elements.scannerContainer) elements.scannerContainer.classList.add('hidden');
        }
    }

    function handleScannerManualInput() {
        closeScanner();
        if (currentStep === 'location') {
            showManualLocationInput();
        } else if (currentStep === 'product') {
            showManualProductInput();
        }
    }

    function returnToList() {
        currentStep = 'list';
        currentItem = null;
        scannedLocation = null;
        scannedProduct = null;
        
        // Hide all workflow steps
        const allSteps = ['location', 'product', 'quantity'];
        allSteps.forEach(step => {
            const stepElement = elements[`${step}Step`];
            if (stepElement) stepElement.classList.add('hidden');
        });
        
        // Show picking list
        elements.pickingListSection?.classList.remove('hidden');
    }

    function adjustQuantity(delta) {
        if (!elements.pickedQuantityInput || !currentItem) return;
        
        const current = parseInt(elements.pickedQuantityInput.value) || 0;
        const newValue = Math.max(0, current + delta);
        const maxAllowed = parseInt(currentItem.quantity_ordered) || 0;
        
        elements.pickedQuantityInput.value = Math.min(newValue, maxAllowed);
        validateQuantityInput();
    }

    function validateQuantityInput() {
        if (!elements.pickedQuantityInput || !currentItem) return;
        
        const value = parseInt(elements.pickedQuantityInput.value) || 0;
        const maxAllowed = parseInt(currentItem.quantity_ordered) || 0;
        
        // Enable/disable confirm button
        if (elements.confirmQuantityBtn) {
            elements.confirmQuantityBtn.disabled = value <= 0 || value > maxAllowed;
        }
    }

    async function confirmPick() {
        if (!currentItem || !elements.pickedQuantityInput) return;
        
        const quantityToPick = parseInt(elements.pickedQuantityInput.value) || 0;
        
        if (quantityToPick <= 0) {
            showMessage('Cantitatea trebuie să fie mai mare de 0.', 'error');
            return;
        }
        
        const locationRequired = !!currentItem.location_code;
        if (!scannedProduct || (locationRequired && !scannedLocation)) {
            showMessage('Eroare: Produsul și, dacă este cazul, locația trebuie verificate înainte de confirmare.', 'error');
            return;
        }
        
        try {
            showLoading(true);
            
            const formData = new FormData();
            formData.append('order_item_id', currentItem.order_item_id);
            formData.append('quantity_picked', quantityToPick);
            formData.append('order_id', currentOrder.id);
            formData.append('verified_location', scannedLocation);
            formData.append('verified_product', scannedProduct);
            
            const response = await fetch(UPDATE_PICK_URL, {
                method: 'POST',
                body: formData
            });
            
            const responseText = await response.text();
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${responseText}`);
            }
            
            const data = JSON.parse(responseText);
            
            if (data.status === 'error') {
                throw new Error(data.message);
            }
            
            // Update local data
            const currentPicked = parseInt(orderItems[currentItem.index].picked_quantity) || 0;
            orderItems[currentItem.index].picked_quantity = currentPicked + quantityToPick;
            
            // Show success and return to list
            showMessage(`Colectat cu succes: ${quantityToPick} x ${currentItem.product_name}`, 'success');
            
            // Update progress and display
            updateProgressDisplay();
            displayOrderItems();
            
            // Return to list after delay
            setTimeout(() => {
                returnToList();
            }, 1500);
            
        } catch (error) {
            console.error('Error confirming pick:', error);
            showMessage(`Eroare la confirmarea colectării: ${error.message}`, 'error');
        } finally {
            showLoading(false);
        }
    }

    function showCompletion() {
        elements.pickingListSection?.classList.add('hidden');
        elements.progressSection?.classList.add('hidden');
        elements.completionSection?.classList.remove('hidden');
    }

    async function printInvoice() {
        if (!currentOrder) return;
        try {
            showLoading(true);
            const formData = new FormData();
            formData.append('order_id', currentOrder.id);
            const response = await fetch(`${API_BASE}/invoices/print_invoice.php`, {
                method: 'POST',
                body: formData
            });
            const text = await response.text();
            const data = JSON.parse(text);
            if (data.status === 'success') {
                showMessage('Factura a fost trimisă la imprimantă.', 'success');
            } else {
                throw new Error(data.message || 'Eroare la imprimare');
            }
        } catch (err) {
            console.error('Print invoice error:', err);
            showMessage(`Eroare la imprimare: ${err.message}`, 'error');
        } finally {
            showLoading(false);
        }
    }

    // Utility Functions
    function showLoading(show = true) {
        if (elements.loadingOverlay) {
            elements.loadingOverlay.classList.toggle('hidden', !show);
        }
    }

    function showMessage(message, type = 'info') {
        if (!elements.messageContainer) return;
        
        // Remove existing messages
        const existingMessages = elements.messageContainer.querySelectorAll('.message');
        existingMessages.forEach(msg => msg.remove());
        
        const messageEl = document.createElement('div');
        messageEl.className = `message ${type}`;
        messageEl.textContent = message;
        
        elements.messageContainer.appendChild(messageEl);
        
        // Auto-remove after 4 seconds
        setTimeout(() => {
            messageEl.style.animation = 'slideUp 0.3s ease reverse';
            setTimeout(() => messageEl.remove(), 300);
        }, 4000);
        
        console.log(`${type.toUpperCase()}: ${message}`);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Export for debugging
    window.mobilePicker = {
        loadOrder,
        currentOrder: () => currentOrder,
        orderItems: () => orderItems,
        refreshItems: () => {
            if (currentOrder) loadOrderItems(currentOrder.id);
        },
        printInvoice
    };
});