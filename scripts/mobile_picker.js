// File: mobile_picker.js
// Final, Refactored Version

document.addEventListener('DOMContentLoaded', () => {
    console.log("DOM Loaded. Initializing Mobile Picker script.");

    // --- Configuration & Constants ---
    const GET_TASK_API_URL = '/api/picking/get_next_task.php';
    const CONFIRM_PICK_API_URL = '/api/picking/confirm_pick.php';
    const UPDATE_STATUS_API_URL = '/api/warehouse/update_order_status.php';

    // --- DOM Elements ---
    const elements = {
        // Overlays & Messages
        loadingOverlay: document.getElementById('loading-overlay'),
        messageArea: document.getElementById('message-area'),
        allDoneMessage: document.getElementById('all-done-message'),

        // Order Input
        scanOrderSection: document.getElementById('scan-order-section'),
        manualOrderSection: document.getElementById('manual-order-section'),
        scanOrderBtn: document.getElementById('scan-order-btn'),
        orderIdInput: document.getElementById('order-id-input'),
        loadManualOrderBtn: document.getElementById('load-manual-order-btn'),
        toggleManualInputBtn: document.getElementById('toggle-manual-input-btn'),
        toggleScanInputBtn: document.getElementById('toggle-scan-input-btn'),
        scannedOrderIdEl: document.getElementById('scanned-order-id'),

        // Scanner
        scannerContainer: document.getElementById('scanner-container'),
        readerDiv: document.getElementById('reader'),
        stopScanBtn: document.getElementById('stop-scan-btn'),

        // Location Verification
        locationScanPrompt: document.getElementById('location-scan-prompt'),
        targetLocationCodeEl: document.getElementById('target-location-code'),
        scanLocationBtn: document.getElementById('scan-location-btn'),
        scanLocationSection: document.getElementById('scan-location-section'),
        manualLocationSection: document.getElementById('manual-location-section'),
        locationCodeInput: document.getElementById('location-code-input'),
        verifyManualLocationBtn: document.getElementById('verify-manual-location-btn'),
        toggleManualLocationBtn: document.getElementById('toggle-manual-location-btn'),
        toggleScanLocationBtn: document.getElementById('toggle-scan-location-btn'),

        // Product Verification
        productScanPrompt: document.getElementById('product-scan-prompt'),
        targetProductSkuEl: document.getElementById('target-product-sku'),
        targetProductNameEl: document.getElementById('target-product-name'),
        scanProductBtn: document.getElementById('scan-product-btn'),
        scanProductSection: document.getElementById('scan-product-section'),
        manualProductSection: document.getElementById('manual-product-section'),
        productSkuInput: document.getElementById('product-sku-input'),
        verifyManualProductBtn: document.getElementById('verify-manual-product-btn'),
        toggleManualProductBtn: document.getElementById('toggle-manual-product-btn'),
        toggleScanProductBtn: document.getElementById('toggle-scan-product-btn'),

        // Task Display & Confirmation
        taskDisplay: document.getElementById('task-display'),
        confirmationArea: document.getElementById('confirmation-area'),
        quantityPickedInput: document.getElementById('quantity-picked-input'),
        confirmPickBtn: document.getElementById('confirm-pick-btn'),

        // Task Data Fields
        productNameEl: document.getElementById('product-name'),
        productSkuEl: document.getElementById('product-sku'),
        locationCodeEl: document.getElementById('location-code'),
        batchNumberEl: document.getElementById('batch-number'),
        inventoryIdEl: document.getElementById('inventory-id'),
        quantityToPickEl: document.getElementById('quantity-to-pick'),
        totalNeededEl: document.getElementById('total-needed'),
        availableInLocationEl: document.getElementById('available-in-location'),
    };

    // --- State Variables ---
    let currentTask = null;
    let html5QrCode = null;
    let currentScanMode = null; // 'order', 'location', 'product'

    // --- Initialization ---
    function initialize() {
        const urlParams = new URLSearchParams(window.location.search);
        const orderParam = urlParams.get('order');

        setupEventListeners();
        resetUI();

        if (orderParam) {
            autoLoadOrder(orderParam);
        }
    }

    // --- UI Helper Functions ---

    const showLoading = () => elements.loadingOverlay?.classList.remove('hidden');
    const hideLoading = () => elements.loadingOverlay?.classList.add('hidden');

    function showMessage(message, isError = false) {
        if (!elements.messageArea) return;
        elements.messageArea.textContent = message;
        elements.messageArea.className = `message-area ${isError ? 'message-area--error' : 'message-area--success'}`;
        if (!isError) {
            setTimeout(() => {
                if (elements.messageArea.textContent === message) {
                    elements.messageArea.textContent = '';
                    elements.messageArea.className = 'message-area';
                }
            }, 3000);
        }
    }

    function resetUI() {
        console.log("Resetting UI to initial state.");
        [
            elements.taskDisplay, elements.confirmationArea, elements.allDoneMessage,
            elements.locationScanPrompt, elements.productScanPrompt, elements.manualOrderSection,
            elements.manualLocationSection, elements.manualProductSection
        ].forEach(el => el?.classList.add('hidden'));

        elements.scanOrderSection?.classList.remove('hidden');
        if (elements.scannedOrderIdEl) elements.scannedOrderIdEl.textContent = '';
        if (elements.orderIdInput) elements.orderIdInput.value = '';
        if (elements.messageArea) elements.messageArea.textContent = '';
        
        currentTask = null;
        currentScanMode = null;
        stopScanner();
    }

    // --- Core Workflow Functions ---

    function autoLoadOrder(orderNumber) {
        console.log(`Auto-loading order: ${orderNumber}`);
        if (elements.scannedOrderIdEl) elements.scannedOrderIdEl.textContent = `Se încarcă: ${orderNumber}`;
        elements.manualOrderSection?.classList.add('hidden');
        elements.scanOrderSection?.classList.remove('hidden');
        
        // Temporarily disable scan button and show loading message
        if (elements.scanOrderBtn) elements.scanOrderBtn.style.display = 'none';
        
        setTimeout(() => {
            fetchNextTask(orderNumber);
        }, 500);
    }

    async function fetchNextTask(orderId) {
        const trimmedOrderId = String(orderId || '').trim();
        if (!trimmedOrderId) {
            showMessage('ID comandă invalid.', true);
            return;
        }
        console.log(`Fetching next task for Order ID: ${trimmedOrderId}`);

        // Reset relevant parts of UI for new task fetch
        [elements.locationScanPrompt, elements.productScanPrompt, elements.taskDisplay, elements.confirmationArea, elements.allDoneMessage].forEach(el => el?.classList.add('hidden'));
        showMessage('');
        currentTask = null;
        showLoading();

        try {
            const response = await fetch(`${GET_TASK_API_URL}?order_id=${encodeURIComponent(trimmedOrderId)}`);
            const result = await response.json();
            console.log("API Response for get_next_task:", result);

            if (!response.ok) throw new Error(result.message || `HTTP Error ${response.status}`);

            if (result.status === 'success') {
                updateOrderStatus(trimmedOrderId, 'picking');
                showLocationScanPrompt(result.data);
            } else if (result.status === 'complete') {
                updateOrderStatus(trimmedOrderId, 'completed');
                showCompletionScreen(result.message);
            } else {
                throw new Error(result.message || 'API returned an unknown error.');
            }
        } catch (error) {
            console.error('Fetch Task Error:', error);
            showMessage(`Eroare: ${error.message}`, true);
        } finally {
            hideLoading();
        }
    }
    
    function showLocationScanPrompt(taskData) {
        console.log("Prompting for location:", taskData.location_code);
    
        document.getElementById('loading-state')?.classList.add('hidden'); 
    
        currentTask = taskData;
        currentScanMode = 'location';
    
        if (elements.targetLocationCodeEl) elements.targetLocationCodeEl.textContent = taskData.location_code;
        if (elements.locationCodeInput) elements.locationCodeInput.value = '';
    
        [elements.productScanPrompt, elements.taskDisplay, elements.confirmationArea, elements.allDoneMessage, elements.manualLocationSection].forEach(el => el?.classList.add('hidden'));
        elements.scanLocationSection?.classList.remove('hidden');
        elements.locationScanPrompt?.classList.remove('hidden');
        showMessage("Scanați sau introduceți codul locației.", false);
    }

    function showProductScanPrompt(taskData) {
        console.log("Prompting for product:", taskData.product_sku);
        currentTask = taskData;
        currentScanMode = 'product';

        if (elements.targetProductSkuEl) elements.targetProductSkuEl.textContent = taskData.product_sku;
        if (elements.targetProductNameEl) elements.targetProductNameEl.textContent = `(${taskData.product_name})`;
        if (elements.productSkuInput) elements.productSkuInput.value = '';

        [elements.locationScanPrompt, elements.taskDisplay, elements.confirmationArea, elements.manualProductSection].forEach(el => el?.classList.add('hidden'));
        elements.scanProductSection?.classList.remove('hidden');
        elements.productScanPrompt?.classList.remove('hidden');
        showMessage("Scanați sau introduceți SKU-ul produsului.", false);
    }

    function enablePickingControls(taskData) {
        console.log("Enabling final picking controls for task:", taskData);
        currentTask = taskData;
        
        // Populate data fields
        if (elements.productNameEl) elements.productNameEl.textContent = taskData.product_name;
        if (elements.productSkuEl) elements.productSkuEl.textContent = taskData.product_sku;
        if (elements.locationCodeEl) elements.locationCodeEl.textContent = taskData.location_code;
        if (elements.quantityToPickEl) elements.quantityToPickEl.textContent = taskData.quantity_to_pick;
        if (elements.availableInLocationEl) elements.availableInLocationEl.textContent = taskData.available_in_location;
        if (elements.quantityPickedInput) {
            elements.quantityPickedInput.value = taskData.quantity_to_pick;
            elements.quantityPickedInput.max = taskData.quantity_to_pick;
        }

        [elements.locationScanPrompt, elements.productScanPrompt].forEach(el => el?.classList.add('hidden'));
        elements.taskDisplay?.classList.remove('hidden');
        elements.confirmationArea?.classList.remove('hidden');
        elements.quantityPickedInput?.focus();
    }

    async function confirmPick() {
        if (!currentTask) { showMessage('Nicio sarcină activă.', true); return; }
        const quantity = parseInt(elements.quantityPickedInput.value, 10);

        if (isNaN(quantity) || quantity <= 0) { showMessage('Cantitate invalidă.', true); elements.quantityPickedInput?.focus(); return; }
        if (quantity > currentTask.quantity_to_pick) { showMessage(`Nu se poate colecta mai mult de ${currentTask.quantity_to_pick}.`, true); elements.quantityPickedInput?.focus(); return; }

        showLoading();
        const payload = { order_item_id: currentTask.order_item_id, inventory_id: currentTask.inventory_id, quantity_picked: quantity };
        console.log("Confirming pick with payload:", payload);

        try {
            const response = await fetch(CONFIRM_PICK_API_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || `HTTP Error ${response.status}`);
            
            showMessage(result.message || 'Colectare confirmată!', false);
            fetchNextTask(currentTask.order_id); // Fetch the next task for the same order
        } catch (error) {
            console.error('Confirm Pick Error:', error);
            showMessage(`Eroare la confirmare: ${error.message}`, true);
        } finally {
            hideLoading();
        }
    }

    function showCompletionScreen(message) {

        document.getElementById('loading-state')?.classList.add('hidden');

        if (elements.allDoneMessage) {
            elements.allDoneMessage.classList.remove('hidden');
            elements.allDoneMessage.innerHTML = `
                <div style="text-align: center;">
                    <span class="material-symbols-outlined" style="font-size: 4rem; color: #4CAF50;">task_alt</span>
                    <h2>Comandă Finalizată</h2>
                    <p>${message}</p>
                    <div style="margin-top: 2rem; display: flex; gap: 1rem; justify-content: center;">
                        <button onclick="window.location.href='warehouse_orders.php'" class="btn btn-secondary">Înapoi la Comenzi</button>
                        <button onclick="window.location.href='mobile_picker.php'" class="btn btn-primary">Comandă Nouă</button>
                    </div>
                </div>
            `;
        }
    }

    // --- Scanner Functions ---

    function onScanSuccess(decodedText) {
        console.log(`Scan Success! Mode: ${currentScanMode}, Decoded: ${decodedText}`);
        showMessage(`Scanned: ${decodedText}`, false);
        stopScanner();

        const scannedValue = decodedText.trim().toUpperCase();

        switch (currentScanMode) {
            case 'order':
                fetchNextTask(scannedValue);
                break;
            case 'location':
                const expectedLocation = (currentTask?.location_code || '').trim().toUpperCase();
                if (scannedValue === expectedLocation) {
                    showMessage("Locație verificată!", false);
                    showProductScanPrompt(currentTask);
                } else {
                    showMessage(`Locație greșită! Scanat: ${decodedText}, Așteptat: ${currentTask.location_code}`, true);
                }
                break;
            case 'product':
                const expectedSku = (currentTask?.product_sku || '').trim().toUpperCase();
                if (scannedValue === expectedSku) {
                    showMessage("Produs verificat!", false);
                    enablePickingControls(currentTask);
                } else {
                    showMessage(`Produs greșit! Scanat: ${decodedText}, Așteptat: ${currentTask.product_sku}`, true);
                }
                break;
            default:
                console.warn("Scan occurred in unknown mode:", currentScanMode);
        }
    }

    function startScanner() {
        if (typeof Html5Qrcode === 'undefined') { showMessage("Librăria de scanare nu a putut fi încărcată.", true); return; }
        if (html5QrCode?.isScanning) { return; }
        if (!html5QrCode) { html5QrCode = new Html5Qrcode("reader"); }
        
        const config = { fps: 10, qrbox: { width: 250, height: 250 } };
        elements.scannerContainer?.classList.remove('hidden');
        showMessage(`Îndreptați camera către codul de bare (${currentScanMode})...`, false);

        html5QrCode.start({ facingMode: "environment" }, config, onScanSuccess, (error) => {})
            .catch(err => {
                console.error("Scanner start failed:", err);
                showMessage("Nu s-a putut porni camera.", true);
                elements.scannerContainer?.classList.add('hidden');
            });
    }

    function stopScanner() {
        if (html5QrCode?.isScanning) {
            html5QrCode.stop().catch(err => console.error("Scanner stop failed:", err));
        }
        elements.scannerContainer?.classList.add('hidden');
    }

    // --- API Functions ---
    async function updateOrderStatus(orderNumber, newStatus) {
        try {
            await fetch(UPDATE_STATUS_API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_number: orderNumber, status: newStatus })
            });
        } catch (error) {
            console.warn('Could not update order status:', error);
        }
    }

    // --- Event Listeners Setup ---
    function setupEventListeners() {
        // Order Input
        elements.toggleManualInputBtn?.addEventListener('click', () => { elements.scanOrderSection.classList.add('hidden'); elements.manualOrderSection.classList.remove('hidden'); elements.orderIdInput.focus(); });
        elements.toggleScanInputBtn?.addEventListener('click', () => { elements.manualOrderSection.classList.add('hidden'); elements.scanOrderSection.classList.remove('hidden'); });
        elements.loadManualOrderBtn?.addEventListener('click', () => fetchNextTask(elements.orderIdInput.value));
        elements.orderIdInput?.addEventListener('keypress', (e) => { if (e.key === 'Enter') fetchNextTask(elements.orderIdInput.value); });
        elements.scanOrderBtn?.addEventListener('click', () => { resetUI(); currentScanMode = 'order'; startScanner(); });

        // Location Verification
        elements.toggleManualLocationBtn?.addEventListener('click', () => { stopScanner(); elements.scanLocationSection.classList.add('hidden'); elements.manualLocationSection.classList.remove('hidden'); elements.locationCodeInput.focus(); });
        elements.toggleScanLocationBtn?.addEventListener('click', () => { elements.manualLocationSection.classList.add('hidden'); elements.scanLocationSection.classList.remove('hidden'); });
        const verifyLocation = () => {
            const entered = (elements.locationCodeInput.value || '').trim().toUpperCase();
            const expected = (currentTask?.location_code || '').trim().toUpperCase();
            if (entered === expected) { showMessage("Locație verificată!", false); showProductScanPrompt(currentTask); }
            else { showMessage(`Locație greșită! Așteptat: ${expected}`, true); elements.locationCodeInput.focus(); }
        };
        elements.verifyManualLocationBtn?.addEventListener('click', verifyLocation);
        elements.locationCodeInput?.addEventListener('keypress', (e) => { if (e.key === 'Enter') verifyLocation(); });
        elements.scanLocationBtn?.addEventListener('click', () => { currentScanMode = 'location'; startScanner(); });

        // Product Verification
        elements.toggleManualProductBtn?.addEventListener('click', () => { stopScanner(); elements.scanProductSection.classList.add('hidden'); elements.manualProductSection.classList.remove('hidden'); elements.productSkuInput.focus(); });
        elements.toggleScanProductBtn?.addEventListener('click', () => { elements.manualProductSection.classList.add('hidden'); elements.scanProductSection.classList.remove('hidden'); });
        const verifyProduct = () => {
            const entered = (elements.productSkuInput.value || '').trim().toUpperCase();
            const expected = (currentTask?.product_sku || '').trim().toUpperCase();
            if (entered === expected) { showMessage("Produs verificat!", false); enablePickingControls(currentTask); }
            else { showMessage(`Produs greșit! Așteptat: ${expected}`, true); elements.productSkuInput.focus(); }
        };
        elements.verifyManualProductBtn?.addEventListener('click', verifyProduct);
        elements.productSkuInput?.addEventListener('keypress', (e) => { if (e.key === 'Enter') verifyProduct(); });
        elements.scanProductBtn?.addEventListener('click', () => { currentScanMode = 'product'; startScanner(); });

        // General Actions
        elements.stopScanBtn?.addEventListener('click', () => stopScanner());
        elements.confirmPickBtn?.addEventListener('click', confirmPick);
        elements.quantityPickedInput?.addEventListener('keypress', (e) => { if (e.key === 'Enter') confirmPick(); });
    }

    // --- Run Initialization ---
    initialize();
});
