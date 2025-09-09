// File: scripts/warehouse-js/mobile_picker.js - Enhanced with Silent Timing

console.log("✅ Improved Mobile Picker Script Loaded!");

document.addEventListener('DOMContentLoaded', () => {
    console.log("DOM Loaded. Initializing improved mobile picker script.");

    // Configuration
    const API_BASE = window.WMS_CONFIG?.apiBase || '/api';
    const GET_ORDER_DETAILS_URL = `${API_BASE}/warehouse/order_details.php`;
    const UPDATE_PICK_URL = `${API_BASE}/picking/update_pick.php`;
    
    // SILENT TIMING INTEGRATION - Initialize TimingManager
    const timingManager = new TimingManager('picking');
    let currentTaskId = null; // Track current timing task silently

    let availablePrinters = [];
    
    // Global State
    let currentOrder = null;
    let orderItems = [];
    let currentItem = null;
    let currentStep = 'list'; // 'list', 'location', 'product', 'quantity'
    let scannedLocation = null;
    let scannedProduct = null;
    let html5QrCode = null;
    let currentScannerType = null; // track which scanner context is active
    let productScanTimeout = null; // timeout handler for auto product verification
    let locationScanTimeout = null; // timeout handler for auto location verification

    // DOM Elements
    const elements = {
        // Header & Info
        orderInfo: document.getElementById('order-info'),
        currentOrderNumber: document.getElementById('current-order-number'),
        customerName: document.getElementById('customer-name'),
        printInvoiceBtn: document.getElementById('print-invoice-btn'),
        generateAwbBtn: document.getElementById('generate-awb-btn'),
        printAwbBtn: document.getElementById('print-awb-btn'),
        orderAwb: document.getElementById('order-awb'),
        awbCode: document.getElementById('order-awb-code'),
        
        // Progress
        progressSection: document.getElementById('progress-section'),
        itemsCompleted: document.getElementById('items-completed'),
        itemsTotal: document.getElementById('items-total'),
        progressPercentage: document.getElementById('progress-percentage'),
        progressFill: document.getElementById('progress-fill'),
        
        // Order Input
        orderInputSection: document.getElementById('order-input-section'),
        orderInput: document.getElementById('order-input'),
        scanOrderBtn: document.getElementById('scan-order-btn'),
        cameraOrderBtn: document.getElementById('camera-order-btn'),
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
        locationManualSection: document.getElementById('location-manual-section'),
        locationInput: document.getElementById('location-input'),
        verifyLocationBtn: document.getElementById('verify-location-btn'),
        
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
        setupKeyboardShortcuts();
        updateAwbButtons();
        loadAvailablePrinters();

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
        elements.scanOrderBtn?.addEventListener('click', () => startPhysicalScanning('order'));
        elements.cameraOrderBtn?.addEventListener('click', () => startScanner('order'));
        
        // Refresh
        elements.refreshItemsBtn?.addEventListener('click', () => {
            // Check if there is an active order in the application's state
            if (currentOrder && currentOrder.order_number) {
                // Call the main loadOrder function, which handles fetching and redrawing everything.
                loadOrder(currentOrder.order_number);
            } else {
                showMessage('Nu se poate reîncărca. Nicio comandă activă.', 'error');
            }
        });

        elements.printAwbBtn?.addEventListener('click', () => {
            const awbCode = currentOrder?.awb_barcode || currentOrder?.tracking_number;
            if (awbCode) {
                printAWBDirect(currentOrder.id, awbCode, currentOrder.order_number);
            }
        });
        
        
        // Location Step
        elements.verifyLocationBtn?.addEventListener('click', verifyLocation);
        elements.locationInput?.addEventListener('input', () => {
            clearTimeout(locationScanTimeout);
            locationScanTimeout = setTimeout(verifyLocation, 300);
        });
        elements.locationInput?.addEventListener('keydown', (e) => {
            if (elements.locationInput.hasAttribute('readonly')) {
                elements.locationInput.removeAttribute('readonly');
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                verifyLocation();
            }
        });
        elements.locationInput?.addEventListener('pointerdown', () => {
            elements.locationInput.removeAttribute('readonly');
            elements.locationInput.setAttribute('inputmode', 'text');
            setTimeout(() => elements.locationInput?.focus(), 0);
        });
        elements.locationInput?.addEventListener('blur', () => {
            elements.locationInput.setAttribute('readonly', 'true');
            elements.locationInput.setAttribute('inputmode', 'none');
            clearTimeout(locationScanTimeout);
        });

        // Product Step
        elements.scanProductBtn?.addEventListener('click', () => startPhysicalScanning('product'));
        elements.manualProductBtn?.addEventListener('click', () => startPhysicalScanning('product'));
        elements.verifyProductBtn?.addEventListener('click', verifyProduct);
        elements.backToScanProduct?.addEventListener('click', () => startScanner('product'));
        // Listen for Enter key and auto-verify for scanner input
        elements.productInput?.addEventListener('keydown', (e) => {
            if (elements.productInput.hasAttribute('readonly')) {
                elements.productInput.removeAttribute('readonly');
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                verifyProduct();
            }
        });
        elements.productInput?.addEventListener('pointerdown', () => {
            elements.productInput.removeAttribute('readonly');
            elements.productInput.setAttribute('inputmode', 'text');
            setTimeout(() => elements.productInput?.focus(), 0);
        });
        elements.productInput?.addEventListener('blur', () => {
            elements.productInput.setAttribute('readonly', 'true');
            elements.productInput.setAttribute('inputmode', 'none');
            clearTimeout(productScanTimeout);
        });
        elements.productInput?.addEventListener('input', () => {
            clearTimeout(productScanTimeout);
            productScanTimeout = setTimeout(() => {
                if (currentStep === 'product' && elements.productInput.value.trim() !== '') {
                    verifyProduct();
                }
            }, 300);
        });
        
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

    function setupKeyboardShortcuts() {
        document.addEventListener('keydown', handleFunctionKeys);
    }

    function handleFunctionKeys(e) {
        switch (e.key) {
            case 'F1':
                e.preventDefault();
                if (currentOrder) {
                    printInvoice();
                } else {
                    showMessage('Nicio comandă activă pentru printarea facturii.', 'error');
                }
                break;
            case 'F2':
                e.preventDefault();
                if (!currentOrder) {
                    showMessage('Nicio comandă activă pentru AWB.', 'error');
                    return;
                }
                const awbCode = currentOrder.awb_barcode;
                if (awbCode) {
                    printAWBDirect(currentOrder.id, awbCode, currentOrder.order_number);
                } else if (!elements.generateAwbBtn.classList.contains('hidden')) {
                    generateAWB(currentOrder.id);
                } else {
                    showMessage('AWB lipsă pentru această comandă.', 'error');
                }
                break;
            case 'F3':
            case 'F4':
            case 'F5':
                e.preventDefault();
                // Reserved for future use
                break;
        }
    }

    // Add this function to load available printers
    async function loadAvailablePrinters() {
        try {
            const response = await fetch('api/printer_management.php?path=printers', {
                credentials: 'same-origin'
            });
            if (response.ok) {
                const data = await response.json();
                availablePrinters = data.filter(p => 
                    p.is_active && 
                    (p.printer_type === 'awb' || p.printer_type === 'label' || p.printer_type === 'universal')
                );
                // Also set window.availablePrinters for compatibility
                window.availablePrinters = availablePrinters;
                console.log('Loaded printers:', availablePrinters);
            }
        } catch (error) {
            console.error('Failed to load printers:', error);
            availablePrinters = [];
            window.availablePrinters = [];
        }
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

            // Normalize AWB field from API (tracking_number vs awb_barcode)
            if (currentOrder) {
                currentOrder.awb_barcode = currentOrder.awb_barcode || currentOrder.tracking_number || currentOrder.awb || null;

                // Verify that the AWB still exists in Cargus
                if (currentOrder.awb_barcode) {
                    const exists = await verifyAwbExists(currentOrder.awb_barcode);
                    if (!exists) {
                        showMessage('AWB nu mai există în Cargus. Poți regenera unul nou.', 'warning');
                        currentOrder.awb_barcode = null;
                    }
                }
            }

            console.log('Current order:', currentOrder);
            console.log('Order items:', orderItems);

            updateAwbButtons();

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

    async function verifyAwbExists(awbCode) {
        try {
            const resp = await fetch(`api/awb/track_awb.php?awb=${encodeURIComponent(awbCode)}&refresh=1`, {
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            });
            const data = await resp.json();
            return !!data.success;
        } catch (err) {
            console.error('AWB verification failed:', err);
            return false;
        }
    }

    function updateAwbButtons() {
        if (!elements.generateAwbBtn || !elements.printAwbBtn) return;

        const awbCode = currentOrder?.awb_barcode || currentOrder?.tracking_number || currentOrder?.awb;

        if (awbCode) {
            // Ensure AWB code is stored consistently
            currentOrder.awb_barcode = awbCode;

            elements.generateAwbBtn.classList.add('hidden');
            elements.printAwbBtn.classList.remove('hidden');
            elements.printAwbBtn.disabled = false;
            elements.printAwbBtn.classList.add('btn-pulse');

            if (elements.orderAwb && elements.awbCode) {
                elements.awbCode.textContent = awbCode;
                elements.orderAwb.classList.remove('hidden');
            }
            if (elements.orderInfo) {
                elements.orderInfo.classList.add('awb-present');
            }
        } else {
            elements.printAwbBtn.classList.add('hidden');
            elements.printAwbBtn.classList.remove('btn-pulse');
            elements.generateAwbBtn.classList.remove('hidden');
            elements.generateAwbBtn.setAttribute('data-order-id', currentOrder?.id || '');

            if (elements.orderAwb) {
                elements.orderAwb.classList.add('hidden');
            }
            if (elements.orderInfo) {
                elements.orderInfo.classList.remove('awb-present');
            }
        }
    }

    async function printInvoice() {
        if (!currentOrder) {
            showMessage('Nicio comandă activă pentru printarea facturii.', 'error');
            return;
        }
    
        console.log(`🖨️ Starting invoice print for order: ${currentOrder.id}`);
    
        showLoading(true);
        
        try {
            for (let i = 0; i < 2; i++) {
                // MOBILE FIX: Enhanced fetch configuration for mobile browsers
                const response = await fetch('api/invoices/print_invoice_network.php', {
                    method: 'POST',
                    credentials: 'include', // CRITICAL: Include session cookies
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Cache-Control': 'no-cache',
                        'Accept': 'application/json'
                    },
                    body: new URLSearchParams({
                        order_id: currentOrder.id,
                        printer_id: 2 // SIMPLE: Use printer ID 2 for invoices
                    })
                });

                if (!response.ok) {
                    if (response.status === 403) {
                        throw new Error('Sesiune expirată. Te rugăm să reîncarci pagina și să te autentifici din nou.');
                    }
                    throw new Error(`Eroare server: HTTP ${response.status}`);
                }

                const data = await response.json();
                console.log('📄 Invoice response data:', data);

                if (data.status !== 'success') {
                    throw new Error(data.message || 'Eroare la printarea facturii');
                }
            }

            showMessage('Factura a fost trimisă la imprimantă.', 'success');
        } catch (error) {
            console.error('❌ Invoice print failed:', error);
            showMessage(`Eroare la printarea facturii: ${error.message}`, 'error');
            
            // If session error, suggest page reload
            if (error.message.includes('sesiune') || error.message.includes('403')) {
                setTimeout(() => {
                    if (confirm('Sesiune expirată. Dorești să reîncarci pagina?')) {
                        window.location.reload();
                    }
                }, 2000);
            }
        } finally {
            showLoading(false);
        }
    }

    async function printAWBDirect(orderId, awbCode, orderNumber, format = 'label') {
        console.log(`🖨️ Starting AWB print: Order=${orderId}, AWB=${awbCode}`);
    
        const btn = elements.printAwbBtn;
        const originalHtml = btn ? btn.innerHTML : '';
        
        // Show loading state
        if (btn) {
            btn.disabled = true;
            btn.classList.remove('btn-pulse');
            btn.innerHTML = '<span class="material-symbols-outlined spinning">hourglass_empty</span> Se verifică AWB...';
        }

        try {
            // Verify AWB still exists in Cargus before sending to print
            const checkResp = await fetch(`api/awb/track_awb.php?awb=${encodeURIComponent(awbCode)}&refresh=1`, {
                credentials: 'include',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cache-Control': 'no-cache',
                    'Accept': 'application/json'
                }
            });
            const checkData = await checkResp.json();
            if (!checkData.success) {
                currentOrder.awb_barcode = null;
                updateAwbButtons();
                throw new Error('AWB inexistent în Cargus. Poți regenera AWB-ul.');
            }

            if (btn) {
                btn.innerHTML = '<span class="material-symbols-outlined spinning">hourglass_empty</span> Se generează PDF...';
            }

            // MOBILE FIX: Enhanced fetch configuration for mobile browsers
            const response = await fetch('api/awb/print_awb.php', {
                method: 'POST',
                credentials: 'include', // CRITICAL: Include session cookies
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cache-Control': 'no-cache',
                    'Accept': 'application/json'
                },
                body: new URLSearchParams({
                    order_id: orderId,
                    awb_code: awbCode,
                    printer_id: 4, // SIMPLE: Use printer ID 4 (GODEX G500)
                    format: format
                })
            });
    
            console.log(`📡 Response status: ${response.status} ${response.statusText}`);
    
            if (!response.ok) {
                if (response.status === 403) {
                    throw new Error('Sesiune expirată. Te rugăm să reîncarci pagina și să te autentifici din nou.');
                }
                throw new Error(`Eroare server: HTTP ${response.status}`);
            }
    
            const data = await response.json();
            console.log('📄 Response data:', data);
            
            if (data.success) {
                showMessage('AWB trimis la GODEX G500!', 'success');
                console.log('✅ Print job ID:', data.job_id);
            } else {
                throw new Error(data.error || 'Eroare necunoscută la printare');
            }
    
        } catch (error) {
            console.error('❌ AWB print failed:', error);
            showMessage(`Eroare la printarea AWB: ${error.message}`, 'error');
            
            // Re-add pulse effect on error
            if (btn) btn.classList.add('btn-pulse');
            
            // If session error, suggest page reload
            if (error.message.includes('sesiune') || error.message.includes('403')) {
                setTimeout(() => {
                    if (confirm('Sesiune expirată. Dorești să reîncarci pagina?')) {
                        window.location.reload();
                    }
                }, 2000);
            }
        } finally {
            // Restore button state
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    }

    document.addEventListener('awbGenerated', (e) => {
        if (currentOrder && e.detail.orderId === currentOrder.id) {
            currentOrder.awb_barcode = e.detail.awbCode;
            updateAwbButtons();
            showMessage('AWB generat. Printează AWB.', 'info');
        }
    });

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
    async function startPickingWorkflow(item, index) {
        currentItem = { ...item, index };
        scannedLocation = null;
        scannedProduct = null;

        // SILENT TIMING INTEGRATION - Start timing when workflow begins
        try {
            const timingResult = await timingManager.startTiming({
                order_id: currentOrder.id,
                order_item_id: item.order_item_id || item.id,
                product_id: item.product_id,
                quantity_to_pick: item.quantity_ordered - (item.picked_quantity || 0),
                location_id: item.location_id
            });
            
            if (timingResult.success) {
                currentTaskId = timingResult.task_id;
                console.log('⏱️ Silent timing started for item:', item.id, 'Task ID:', currentTaskId);
            } else {
                console.warn('⚠️ Failed to start timing for item:', item.id);
            }
        } catch (error) {
            console.warn('⚠️ Timing error (non-blocking):', error);
        }

        // Show location step only if a location code exists
        if (item.location_code) {
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
            if (stepName === 'location' && currentItem) {
                if (elements.targetLocation) elements.targetLocation.textContent = currentItem.location_code || 'N/A';
                startPhysicalScanning('location');
            } else if (stepName === 'product' && currentItem) {
                if (elements.targetProductName) elements.targetProductName.textContent = currentItem.product_name || 'Produs necunoscut';
                if (elements.targetProductSku) elements.targetProductSku.textContent = currentItem.sku || 'N/A';
                // Immediately prepare for physical scanning
                startPhysicalScanning('product');
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

    function startPhysicalScanning(type) {
        // Remove any leftover camera fallback buttons
        document.querySelectorAll('.camera-fallback-btn').forEach(btn => btn.remove());

        if (type === 'location') {
            if (elements.locationManualSection) elements.locationManualSection.classList.remove('hidden');
            if (elements.locationInput) {
                elements.locationInput.placeholder = 'Pull trigger to scan or type manually';
                elements.locationInput.value = '';
                elements.locationInput.setAttribute('inputmode', 'none');
                elements.locationInput.setAttribute('readonly', 'true');
                elements.locationInput.focus({ preventScroll: true });
            }
            // Camera fallback removed for location scanning

        } else if (type === 'product') {
            if (elements.productScanSection) elements.productScanSection.classList.add('hidden');
            if (elements.productManualSection) elements.productManualSection.classList.remove('hidden');
            if (elements.productInput) {
                elements.productInput.placeholder = 'Pull trigger to scan or type manually';
                elements.productInput.value = '';
                elements.productInput.focus();
            }
            // Hide camera options and adjust manual verify button text
            if (elements.backToScanProduct) elements.backToScanProduct.classList.add('hidden');
            if (elements.verifyProductBtn) elements.verifyProductBtn.textContent = 'Verifică produsul manual';

        } else if (type === 'order') {
            if (elements.orderInput) {
                elements.orderInput.placeholder = 'Pull trigger to scan or type manually';
                elements.orderInput.focus();
            }
            addCameraFallbackButton('order');
        }
    }

    function addCameraFallbackButton(type) {
        let container = null;
        if (type === 'location') {
            container = elements.locationManualSection;

        } else if (type === 'order') {
            container = elements.orderInputSection;
        }

        if (!container) return;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = 'Use Camera Instead';
        btn.className = 'camera-fallback-btn';
        btn.addEventListener('click', () => startScanner(type));
        container.appendChild(btn);
    }

    function verifyLocation() {
        clearTimeout(locationScanTimeout);
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
            if (elements.locationInput) {
                elements.locationInput.value = '';
                elements.locationInput.setAttribute('readonly', 'true');
                elements.locationInput.setAttribute('inputmode', 'none');
            }
            setTimeout(() => showStep('product'), 1000);
        } else {
            showMessage(`Locație incorectă! Așteptat: ${expectedLocation}, Introdus: ${inputLocation}`, 'error');
            if (elements.locationInput) {
                elements.locationInput.value = '';
                elements.locationInput.setAttribute('readonly', 'true');
                elements.locationInput.setAttribute('inputmode', 'none');
                elements.locationInput.focus({ preventScroll: true });
            }
        }
    }

    function verifyProduct() {
        clearTimeout(productScanTimeout);
        const inputProduct = elements.productInput?.value?.trim().toUpperCase();
        if (elements.productInput) elements.productInput.value = '';
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
            if (elements.productInput) elements.productInput.focus();
        }
    }

    // Scanner Functions
    function startScanner(type) {
        currentScannerType = type;
        if (elements.scannerContainer) {
            elements.scannerContainer.classList.remove('hidden');
            if (elements.scannerTitle) {
                elements.scannerTitle.textContent =
                    type === 'location'
                        ? 'Scanare Locație'
                        : type === 'product'
                            ? 'Scanare Produs'
                            : 'Scanare Comandă';
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
        } else if (type === 'order') {
            if (elements.orderInput) elements.orderInput.value = decodedText;
            handleLoadOrder();
        }
    }

    function closeScanner() {
        currentScannerType = null;
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
        const type = currentScannerType;
        closeScanner();
        if (type) {
            startPhysicalScanning(type);
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

            // SILENT TIMING INTEGRATION - Complete timing when pick is confirmed
            if (currentTaskId) {
                try {
                    const timingResult = await timingManager.completeTiming(currentTaskId, {
                        quantity_picked: quantityToPick,
                        notes: `Picked ${quantityToPick} items successfully`
                    });
                    
                    if (timingResult.success) {
                        console.log('⏱️ Silent timing completed for item:', currentItem.id, 
                                   'Duration:', timingResult.task.duration_formatted);
                        
                        // Trigger dashboard refresh silently
                        if (window.refreshDashboardStats) {
                            window.refreshDashboardStats();
                        }
                    } else {
                        console.warn('⚠️ Failed to complete timing for item:', currentItem.id);
                    }
                    
                    currentTaskId = null; // Clear timing task
                } catch (error) {
                    console.warn('⚠️ Timing completion error (non-blocking):', error);
                }
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

        const closeBtn = document.createElement('button');
        closeBtn.className = 'message-close';
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', () => messageEl.remove());
        messageEl.appendChild(closeBtn);

        elements.messageContainer.appendChild(messageEl);
        // Auto-remove after 3 seconds for better visibility
        setTimeout(() => {
            messageEl.style.animation = 'slideUp 0.3s ease reverse';
            setTimeout(() => messageEl.remove(), 300);
        }, 3000);
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