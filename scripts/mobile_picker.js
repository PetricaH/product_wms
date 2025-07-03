// File: src/js/pages/mobile_picker.js
// Added Manual Input Fallback for Location Verification

document.addEventListener('DOMContentLoaded', () => {
    console.log("DOM Loaded. Initializing script.");

    // --- Configuration ---
    const GET_TASK_API_URL = '/api/picking/get_next_task.php';
    const CONFIRM_PICK_API_URL = '/api/picking/confirm_pick.php';

    // --- DOM Elements ---
    // Order Input
    const scanOrderBtn = document.getElementById('scan-order-btn');
    const scanOrderSection = document.getElementById('scan-order-section');
    const manualOrderSection = document.getElementById('manual-order-section');
    const orderIdInput = document.getElementById('order-id-input');
    const loadManualOrderBtn = document.getElementById('load-manual-order-btn');
    const toggleManualInputBtn = document.getElementById('toggle-manual-input-btn');
    const toggleScanInputBtn = document.getElementById('toggle-scan-input-btn');
    const scannedOrderIdEl = document.getElementById('scanned-order-id');

    // Scanner
    const scannerContainer = document.getElementById('scanner-container');
    const readerDiv = document.getElementById('reader');
    const stopScanBtn = document.getElementById('stop-scan-btn');

    // Location Scan/Input
    const locationScanPrompt = document.getElementById('location-scan-prompt');
    const targetLocationCodeEl = document.getElementById('target-location-code');
    const scanLocationBtn = document.getElementById('scan-location-btn');
    const scanLocationSection = document.getElementById('scan-location-section'); // NEW
    const manualLocationSection = document.getElementById('manual-location-section'); // NEW
    const locationCodeInput = document.getElementById('location-code-input'); // NEW
    const verifyManualLocationBtn = document.getElementById('verify-manual-location-btn'); // NEW
    const toggleManualLocationBtn = document.getElementById('toggle-manual-location-btn'); // NEW
    const toggleScanLocationBtn = document.getElementById('toggle-scan-location-btn'); // NEW

    // Product Scan/Input
    const productScanPrompt = document.getElementById('product-scan-prompt');
    const targetProductSkuEl = document.getElementById('target-product-sku');
    const targetProductNameEl = document.getElementById('target-product-name');
    const scanProductBtn = document.getElementById('scan-product-btn');
    const scanProductSection = document.getElementById('scan-product-section');
    const manualProductSection = document.getElementById('manual-product-section');
    const productSkuInput = document.getElementById('product-sku-input');
    const verifyManualProductBtn = document.getElementById('verify-manual-product-btn');
    const toggleManualProductBtn = document.getElementById('toggle-manual-product-btn');
    const toggleScanProductBtn = document.getElementById('toggle-scan-product-btn');

    // Task Display & Confirmation
    const taskDisplay = document.getElementById('task-display');
    const confirmationArea = document.getElementById('confirmation-area');
    const messageArea = document.getElementById('message-area');
    const allDoneMessage = document.getElementById('all-done-message');
    const quantityPickedInput = document.getElementById('quantity-picked-input');
    const confirmPickBtn = document.getElementById('confirm-pick-btn');
    const loadingOverlay = document.getElementById('loading-overlay');

    // Task Data Elements (in task display)
    const productNameEl = document.getElementById('product-name');
    const productSkuEl = document.getElementById('product-sku');
    const locationCodeEl = document.getElementById('location-code');
    const batchNumberEl = document.getElementById('batch-number');
    const inventoryIdEl = document.getElementById('inventory-id');
    const quantityToPickEl = document.getElementById('quantity-to-pick');
    const totalNeededEl = document.getElementById('total-needed');
    const availableInLocationEl = document.getElementById('available-in-location');

    const urlParams = new URLSearchParams(window.location.search);
    const orderParam = urlParams.get('order');

    // Check elements exist (add new checks)
    // ... (previous checks) ...
    if (!locationCodeInput) console.error("Error: Manual Location input not found!");
    if (!verifyManualLocationBtn) console.error("Error: Verify Manual Location button not found!");
    if (!productScanPrompt) console.error("Error: Product Scan Prompt section not found!");
    if (!targetProductSkuEl) console.error("Error: Target Product SKU element not found!");
    if (!targetProductNameEl) console.error("Error: Target Product Name element not found!");
    if (!scanProductBtn) console.error("Error: Scan Product button not found!");
    if (!scanProductSection) console.error("Error: Scan Product Section not found!");
    if (!manualProductSection) console.error("Error: Manual Product Section not found!");
    if (!productSkuInput) console.error("Error: Product SKU input not found!");
    if (!verifyManualProductBtn) console.error("Error: Verify Manual Product button not found!");
    if (!toggleManualProductBtn) console.error("Error: Toggle Manual Product button not found!");
    if (!toggleScanProductBtn) console.error("Error: Toggle Scan Product button not found!");
    // ... etc. ...

    // --- State ---
    let currentTask = null;
    let html5QrCode = null;
    let currentScanMode = null; // 'order', 'location', 'product'

    if (orderParam) {
        // Auto-load the order from dashboard
        autoLoadOrder(orderParam);
    }

    // --- Core Functions ---

    /** Shows the loading overlay */
    function showLoading() { if (loadingOverlay) loadingOverlay.classList.remove('hidden'); }
    /** Hides the loading overlay */
    function hideLoading() { if (loadingOverlay) loadingOverlay.classList.add('hidden'); }

    /** Displays a message to the user */
    function showMessage(message, isError = false) {
        if (!messageArea) return;
        messageArea.textContent = message;
        messageArea.className = `message-area ${isError ? 'message-area--error' : 'message-area--success'}`;
        if (!isError) { setTimeout(() => { if (messageArea.textContent === message) { messageArea.textContent = ''; messageArea.className = 'message-area'; } }, 3000); }
    }

    /** Clears all dynamic sections of the UI and resets the state */
    function resetUI() {
        console.log("Resetting UI");
        // Hide all dynamic sections
        [taskDisplay, confirmationArea, allDoneMessage, locationScanPrompt, productScanPrompt,
         manualOrderSection, manualLocationSection, manualProductSection].forEach(el => {
            if (el) el.classList.add('hidden');
        });
        // Show default order input mode
        if (scanOrderSection) scanOrderSection.classList.remove('hidden');

        // Clear text/values
        if (scannedOrderIdEl) scannedOrderIdEl.textContent = '';
        if (targetLocationCodeEl) targetLocationCodeEl.textContent = '';
        if (targetProductSkuEl) targetProductSkuEl.textContent = '';
        if (targetProductNameEl) targetProductNameEl.textContent = '';
        if (messageArea) messageArea.textContent = '';
        if (orderIdInput) orderIdInput.value = '';
        if (locationCodeInput) locationCodeInput.value = ''; // Clear location input
        if (productSkuInput) productSkuInput.value = '';

        const elementsToClear = [productNameEl, productSkuEl, locationCodeEl, batchNumberEl, inventoryIdEl, quantityToPickEl, totalNeededEl, availableInLocationEl];
        elementsToClear.forEach(el => { if (el) el.textContent = ''; });

        if (inventoryIdEl) inventoryIdEl.textContent = '0';
        if (quantityToPickEl) quantityToPickEl.textContent = '0';
        if (totalNeededEl) totalNeededEl.textContent = '0';
        if (availableInLocationEl) availableInLocationEl.textContent = '0';
        if (quantityPickedInput) quantityPickedInput.value = '';

        currentTask = null;
        currentScanMode = null;
        stopScanner();
    }

    /** Displays the final picking details and enables confirmation */
    function enablePickingControls(taskData) {
        console.log("Enabling picking controls for task:", taskData);
        currentTask = taskData;

        // Populate the final display area
        if (productNameEl) productNameEl.textContent = taskData.product_name || 'N/A';
        if (productSkuEl) productSkuEl.textContent = taskData.product_sku || 'N/A';
        if (locationCodeEl) locationCodeEl.textContent = taskData.location_code || 'N/A';
        if (batchNumberEl) batchNumberEl.textContent = taskData.batch_number || 'None';
        if (inventoryIdEl) inventoryIdEl.textContent = taskData.inventory_id || '0';
        if (quantityToPickEl) quantityToPickEl.textContent = taskData.quantity_to_pick || '0';
        if (totalNeededEl) totalNeededEl.textContent = taskData.total_needed_for_item || '0';
        if (availableInLocationEl) availableInLocationEl.textContent = taskData.available_in_location || '0';

        // Pre-fill quantity and set max
        if (quantityPickedInput && quantityPickedInput instanceof HTMLInputElement) {
            try {
                quantityPickedInput.value = taskData.quantity_to_pick || '';
                const maxVal = parseInt(taskData.quantity_to_pick, 10);
                quantityPickedInput.max = !isNaN(maxVal) ? maxVal.toString() : '';
            } catch (e) { console.error("Error setting input props:", e); }
        }

        // Hide prompts, show final task display and confirmation area
        if (locationScanPrompt) locationScanPrompt.classList.add('hidden');
        if (productScanPrompt) productScanPrompt.classList.add('hidden');
        if (taskDisplay) taskDisplay.classList.remove('hidden');
        if (confirmationArea) confirmationArea.classList.remove('hidden');
        if (allDoneMessage) allDoneMessage.classList.add('hidden');

        if(quantityPickedInput) quantityPickedInput.focus(); // Focus quantity input
    }

     /** Shows the prompt to scan/enter the target product */
    function showProductScanPrompt(taskData) {
        console.log("Showing product scan prompt for SKU:", taskData.product_sku);
        currentTask = taskData;
        currentScanMode = 'product';

        if (targetProductSkuEl) targetProductSkuEl.textContent = taskData.product_sku || 'N/A';
        if (targetProductNameEl) targetProductNameEl.textContent = `(${taskData.product_name || 'Unknown Name'})`;
        if (productSkuInput) productSkuInput.value = ''; // Clear manual input

        // Hide other sections, show product prompt (default to scan view)
        if (locationScanPrompt) locationScanPrompt.classList.add('hidden');
        if (taskDisplay) taskDisplay.classList.add('hidden');
        if (confirmationArea) confirmationArea.classList.add('hidden');
        if (manualProductSection) manualProductSection.classList.add('hidden');
        if (scanProductSection) scanProductSection.classList.remove('hidden');
        if (productScanPrompt) productScanPrompt.classList.remove('hidden');

        showMessage("Please scan or enter the product SKU.", false);
    }


    /** Shows the prompt to scan/enter the target location */
    function showLocationScanPrompt(taskData) {
        console.log("Showing location scan prompt for location:", taskData.location_code);
        currentTask = taskData;
        currentScanMode = 'location';

        if (targetLocationCodeEl) targetLocationCodeEl.textContent = taskData.location_code || 'N/A';
        if (locationCodeInput) locationCodeInput.value = ''; // Clear manual input

        // Hide other sections, show location prompt (default to scan view)
        if (productScanPrompt) productScanPrompt.classList.add('hidden');
        if (taskDisplay) taskDisplay.classList.add('hidden');
        if (confirmationArea) confirmationArea.classList.add('hidden');
        if (allDoneMessage) allDoneMessage.classList.add('hidden');
        if (manualLocationSection) manualLocationSection.classList.add('hidden'); // Hide manual input first
        if (scanLocationSection) scanLocationSection.classList.remove('hidden'); // Show scan button
        if (locationScanPrompt) locationScanPrompt.classList.remove('hidden'); // Show the whole prompt section

        showMessage("Please scan or enter the location code.", false);
    }

    /** Fetches the next picking task from the API */
    async function fetchNextTask(orderId) {
        if (!orderId || String(orderId).trim() === '') { showMessage('Invalid Order ID.', true); stopScanner(); return; }
        const trimmedOrderId = String(orderId).trim();
        console.log(`fetchNextTask called with Order ID: ${trimmedOrderId}`);

        if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Loading Order: ${trimmedOrderId}`;
        // Reset UI except for the order input section
        [locationScanPrompt, productScanPrompt, taskDisplay, confirmationArea, allDoneMessage].forEach(el => el?.classList.add('hidden'));
        if (messageArea) messageArea.textContent = '';
        currentTask = null;
        showLoading();

        try {
            const response = await fetch(`${GET_TASK_API_URL}?order_id=${encodeURIComponent(trimmedOrderId)}`);
            if (!response.ok) { let e = `HTTP error ${response.status}`; try { e = (await response.json()).message || e; } catch(err){} throw new Error(e); }
            const result = await response.json();
            console.log("Fetch result:", result);

            if (result.status === 'success') {
                showLocationScanPrompt(result.data); // Start workflow with location prompt
                if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Loaded Order: ${trimmedOrderId}`;
            } else if (result.status === 'complete') {
                if (allDoneMessage) allDoneMessage.classList.remove('hidden');
                showMessage('Order picking complete!', false);
                if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Order ${trimmedOrderId} is complete.`;
            } else {
                showMessage(result.message || `API Error: ${result.status}`, true);
                if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Error loading Order: ${trimmedOrderId}`;
            }
        } catch (error) {
            console.error('Fetch Task Error:', error);
            showMessage(`Error: ${error.message || 'Network error.'}`, true);
            if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Error loading Order: ${trimmedOrderId}`;
        } finally {
            hideLoading();
        }
    }

    /** Confirms the pick quantity via API */
    async function confirmPick() {
        if (!currentTask) { showMessage('No active task.', true); return; }
        const quantity = parseInt(quantityPickedInput.value, 10);
        // Validation...
        if (isNaN(quantity) || quantity <= 0) { showMessage('Invalid quantity.', true); if(quantityPickedInput) quantityPickedInput.focus(); return; }
        if (quantity > currentTask.quantity_to_pick) { showMessage(`Cannot pick > ${currentTask.quantity_to_pick}.`, true); if(quantityPickedInput) quantityPickedInput.focus(); return; }
        if (quantity > currentTask.available_in_location) { showMessage(`Only ${currentTask.available_in_location} available.`, true); if(quantityPickedInput) quantityPickedInput.focus(); return; }

        showLoading();
        if (messageArea) messageArea.textContent = '';
        const payload = { order_item_id: currentTask.order_item_id, inventory_id: currentTask.inventory_id, quantity_picked: quantity };
        console.log("Confirm pick payload:", payload);

        try {
            const response = await fetch(CONFIRM_PICK_API_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload) });
            if (!response.ok) { let e = `HTTP error ${response.status}`; try { e = (await response.json()).message || e; } catch(err){} throw new Error(e); }
            const result = await response.json();
            console.log("Confirm result:", result);

            if (result.status === 'success') {
                showMessage(result.message || 'Pick confirmed!', false);
                if (currentTask.order_id) { fetchNextTask(currentTask.order_id); } // Fetch next task
                else { console.error("Order ID missing."); showMessage("Confirmed, cannot fetch next.", true); resetUI(); }
            } else { showMessage(result.message || `API Error: ${result.status}`, true); }
        } catch (error) { console.error('Confirm Pick Error:', error); showMessage(`Error: ${error.message || 'Network error.'}`, true); }
        finally { hideLoading(); }
    }

    // --- Scanner Functions ---

    /** Callback for successful scan */
    function onScanSuccess(decodedText, decodedResult) {
        console.log(`Scan Success! Mode: ${currentScanMode}, Decoded: ${decodedText}`);
        showMessage(`Scanned: ${decodedText}`, false);
        stopScanner(false); // Stop scanner, don't reset UI yet

        const scannedValue = decodedText.trim().toUpperCase();

        if (currentScanMode === 'order') {
            fetchNextTask(scannedValue);
        }
        else if (currentScanMode === 'location') {
            if (!currentTask) { showMessage("Error: No task loaded for location scan.", true); return;}
            const expectedLocation = (currentTask.location_code || '' ).trim().toUpperCase();
            if (scannedValue === expectedLocation) {
                showMessage("Location verified!", false);
                showProductScanPrompt(currentTask);
            } else {
                showMessage(`Wrong Location! Scanned: ${decodedText}, Expected: ${currentTask.location_code}`, true);
                if (scanLocationBtn && !scanLocationSection.classList.contains('hidden')) {

                } else if (locationCodeInput && !manualLocationSection.classList.contains('hidden')) {
                    locationCodeInput.focus();
                }
            }
        }
        else if (currentScanMode === 'product') {
             if (!currentTask) { showMessage("Error: No task loaded.", true); return; }
             const expectedSku = currentTask.product_sku.trim().toUpperCase();
             if (scannedValue === expectedSku) {
                 showMessage("Product verified!", false);
                 enablePickingControls(currentTask); // Enable final controls
             } else {
                 showMessage(`Wrong Product! Scanned: ${decodedText}, Expected: ${currentTask.product_sku}`, true);
                 if (scanProductBtn && !scanProductSection.classList.contains('hidden')) {

                 } else if (productSkuInput && !manualProductSection.classList.contains('hidden')) {
                    productSkuInput.focus();
                 }
             }
        }
        else { console.warn("Scan occurred in unknown mode:", currentScanMode); }
    }

    /** Callback for scan failure */
    function onScanFailure(error) { /* Usually ignore */ }

    /** Initializes and starts the barcode scanner */
    function startScanner() {
        if (typeof Html5Qrcode === 'undefined') { showMessage("Scanner library error.", true); return; }
        if (html5QrCode?.isScanning) { console.log("Scanner already running."); return; }
        if (!html5QrCode) { try { html5QrCode = new Html5Qrcode("reader"); } catch (e) { console.error("Scanner init failed:", e); showMessage("Scanner init failed.", true); return; } }

        const config = { fps: 10, qrbox: (w, h) => { let s=Math.min(w,h)*0.8; return {width:Math.max(s,200),height:Math.max(s,200)}; }, aspectRatio: 1.0, rememberLastUsedCamera: true, supportedScanTypes: [Html5QrcodeScanType.SCAN_TYPE_CAMERA] };

        console.log("Starting scanner for mode:", currentScanMode);
        if (scannerContainer) scannerContainer.classList.remove('hidden');
        if (stopScanBtn) stopScanBtn.classList.remove('hidden');
        if (messageArea) messageArea.textContent = `Point camera at ${currentScanMode || 'barcode'}...`;

        html5QrCode.start( { facingMode: "environment" }, config, onScanSuccess, onScanFailure)
        .catch((err) => {
            console.error(`Scanner start failed (${currentScanMode}):`, err);
            let userMsg = `Error starting scanner: ${err}`;
            if (String(err).includes("Permission")||String(err).includes("NotAllowed")) { userMsg = "Camera permission denied."; }
            else if (String(err).includes("NotFoundError")||String(err).includes("Requested camera")) { userMsg = "Camera not found/available."; }
            showMessage(userMsg, true);
            if (scannerContainer) scannerContainer.classList.add('hidden');
            if (stopScanBtn) stopScanBtn.classList.add('hidden');
        });
    }

    /** Stops scanner, optionally resets UI */
    function stopScanner(shouldResetUI = false) {
        if (html5QrCode?.isScanning) {
            console.log("Attempting to stop scanner...");
            html5QrCode.stop().then(() => { console.log("Scanner stopped."); }).catch((err) => { console.error("Scanner stop error:", err); });
        }
        if (scannerContainer) scannerContainer.classList.add('hidden');
        if (stopScanBtn) stopScanBtn.classList.add('hidden');
        if (shouldResetUI) resetUI();
    }

    /**
     * Auto-load order when coming from warehouse dashboard
     */
    function autoLoadOrder(orderNumber) {
        console.log(`Auto-loading order: ${orderNumber}`);
        
        // Update UI to show we're loading a specific order
        if (scannedOrderIdEl) {
            scannedOrderIdEl.textContent = `Se încarcă: ${orderNumber}`;
        }
        
        // Hide manual input section and show that order is pre-selected
        if (manualOrderSection) {
            manualOrderSection.style.display = 'none';
        }
        
        if (scanOrderSection) {
            const autoLoadMessage = document.createElement('div');
            autoLoadMessage.className = 'auto-load-message';
            autoLoadMessage.innerHTML = `
                <div style="background: #e6fffa; border: 2px solid #319795; border-radius: 8px; padding: 15px; margin: 10px 0; text-align: center;">
                    <span style="color: #2c7a7b; font-weight: 600;">📦 Comandă selectată din tablou de bord</span>
                    <div style="color: #285e61; margin-top: 5px;">Comanda ${orderNumber} se încarcă automat...</div>
                </div>
            `;
            scanOrderSection.appendChild(autoLoadMessage);
            
            // Hide the scan button since order is pre-selected
            if (scanOrderBtn) {
                scanOrderBtn.style.display = 'none';
            }
        }
        
        // Update status and start task fetching
        updateOrderStatus(orderNumber, 'assigned');
        
        // Fetch the first task for this order
        setTimeout(() => {
            fetchNextTask(orderNumber);
        }, 500); // Small delay to show the loading message
    }

    /**
     * Update order status on the server
     */
    async function updateOrderStatus(orderNumber, newStatus) {
        try {
            const response = await fetch('/api/warehouse/update_order_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    order_number: orderNumber, 
                    status: newStatus 
                })
            });
            
            const result = await response.json();
            if (result.status !== 'success') {
                console.warn('Could not update order status:', result.message);
            }
        } catch (error) {
            console.warn('Error updating order status:', error);
            // Continue anyway - status update is not critical for picking
        }
    }

    /**
     * Enhanced fetchNextTask function with better status updates
     */
    async function fetchNextTaskEnhanced(orderId) {
        if (!orderId || String(orderId).trim() === '') { 
            showMessage('ID comandă invalid.', true); 
            stopScanner(); 
            return; 
        }
        
        const trimmedOrderId = String(orderId).trim();
        console.log(`fetchNextTask called with Order ID: ${trimmedOrderId}`);

        if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Se încarcă comanda: ${trimmedOrderId}`;
        
        // Reset UI except for the order input section
        [locationScanPrompt, productScanPrompt, taskDisplay, confirmationArea, allDoneMessage].forEach(el => el?.classList.add('hidden'));
        if (messageArea) messageArea.textContent = '';
        currentTask = null;
        showLoading();

        try {
            const response = await fetch(`${GET_TASK_API_URL}?order_id=${encodeURIComponent(trimmedOrderId)}`);
            if (!response.ok) { 
                let e = `HTTP error ${response.status}`; 
                try { e = (await response.json()).message || e; } catch(err){} 
                throw new Error(e); 
            }
            
            const result = await response.json();
            console.log("Fetch result:", result);

            if (result.status === 'success') {
                showLocationScanPrompt(result.data); // Start workflow with location prompt
                if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Comandă încărcată: ${trimmedOrderId}`;
                
                // Update order status to 'picking' when first task is loaded
                updateOrderStatus(trimmedOrderId, 'picking');
                
            } else if (result.status === 'complete') {
                if (allDoneMessage) allDoneMessage.classList.remove('hidden');
                showMessage('Colectarea comenzii este finalizată!', false);
                if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Comanda ${trimmedOrderId} este completă.`;
                
                // Update order status to completed
                updateOrderStatus(trimmedOrderId, 'completed');
                
                // Show option to return to dashboard
                showCompletionOptions();
                
            } else {
                showMessage(result.message || `Eroare API: ${result.status}`, true);
                if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Eroare încărcare comandă: ${trimmedOrderId}`;
            }
        } catch (error) {
            console.error('Fetch Task Error:', error);
            showMessage(`Eroare: ${error.message || 'Eroare rețea.'}`, true);
            if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Eroare încărcare comandă: ${trimmedOrderId}`;
        } finally {
            hideLoading();
        }
    }

    /**
     * Show completion options when order is finished
     */
    function showCompletionOptions() {
        const completionSection = document.createElement('div');
        completionSection.className = 'completion-options';
        completionSection.innerHTML = `
            <div style="background: #f0fff4; border: 2px solid #38a169; border-radius: 12px; padding: 20px; margin: 20px 0; text-align: center;">
                <div style="color: #2f855a; font-size: 1.2rem; font-weight: 600; margin-bottom: 15px;">
                    ✅ Comandă finalizată cu succes!
                </div>
                <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                    <button onclick="returnToDashboard()" style="background: #667eea; color: white; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: 600;">
                        🏠 Înapoi la Tablou
                    </button>
                    <button onclick="startNewOrder()" style="background: #48bb78; color: white; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: 600;">
                        📦 Comandă Nouă
                    </button>
                </div>
            </div>
        `;
        
        // Insert after the all done message
        if (allDoneMessage && allDoneMessage.parentNode) {
            allDoneMessage.parentNode.insertBefore(completionSection, allDoneMessage.nextSibling);
        }
    }

    /**
     * Return to warehouse dashboard
     */
    function returnToDashboard() {
        window.location.href = 'warehouse_orders.php';
    }

    /**
     * Start a new order (reload picker)
     */
    function startNewOrder() {
        window.location.href = 'mobile_picker.html';
    }

    // Replace the original fetchNextTask with the enhanced version
    if (typeof fetchNextTask !== 'undefined') {
        // Save original function as fallback
        window.originalFetchNextTask = fetchNextTask;
        // Replace with enhanced version
        window.fetchNextTask = fetchNextTaskEnhanced;
    }

    // Add CSS for enhanced UI elements
    const enhancedStyles = `
    <style>
    .auto-load-message {
        animation: slideIn 0.5s ease-out;
    }

    .completion-options {
        animation: fadeIn 0.5s ease-out;
    }

    @keyframes slideIn {
        from { transform: translateY(-20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .completion-options button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        transition: all 0.2s ease;
    }
    </style>
    `;

    // Add enhanced styles to head
    document.head.insertAdjacentHTML('beforeend', enhancedStyles);

    // --- Event Listeners ---

    // Order Input Mode Toggles & Load
    if (toggleManualInputBtn) { toggleManualInputBtn.addEventListener('click', () => { stopScanner(false); scanOrderSection?.classList.add('hidden'); manualOrderSection?.classList.remove('hidden'); orderIdInput?.focus(); scannedOrderIdEl && (scannedOrderIdEl.textContent = ''); }); }
    if (toggleScanInputBtn) { toggleScanInputBtn.addEventListener('click', () => { manualOrderSection?.classList.add('hidden'); scanOrderSection?.classList.remove('hidden'); scannedOrderIdEl && (scannedOrderIdEl.textContent = ''); }); }
    if (loadManualOrderBtn) { loadManualOrderBtn.addEventListener('click', () => { fetchNextTask(orderIdInput.value); }); }
    if (orderIdInput) { orderIdInput.addEventListener('keypress', (e) => { if (e.key === 'Enter') { e.preventDefault(); fetchNextTask(orderIdInput.value); } }); }

    // Location Input Mode Toggles & Verify
    if (toggleManualLocationBtn) { toggleManualLocationBtn.addEventListener('click', () => { stopScanner(false); scanLocationSection?.classList.add('hidden'); manualLocationSection?.classList.remove('hidden'); locationCodeInput?.focus(); }); }
    if (toggleScanLocationBtn) { toggleScanLocationBtn.addEventListener('click', () => { manualLocationSection?.classList.add('hidden'); scanLocationSection?.classList.remove('hidden'); }); }
    if (verifyManualLocationBtn) {
        verifyManualLocationBtn.addEventListener('click', () => {
            if (!currentTask) { showMessage("Error: No task loaded for manual location verification.", true); return; }
            const enteredCode = (locationCodeInput.value || '').trim().toUpperCase();
            const expectedCode = (currentTask.location_code || '').trim().toUpperCase();
            if (enteredCode === expectedCode) {
                showMessage("Location verified!", false);
                showProductScanPrompt(currentTask);
            } else {
                showMessage(`Wrong Location! Entered: ${locationCodeInput.value}, Expected: ${currentTask.location_code}`, true);
                locationCodeInput?.focus();
            }
        });
    }
     if (locationCodeInput) { locationCodeInput.addEventListener('keypress', (e) => { if (e.key === 'Enter') { e.preventDefault(); verifyManualLocationBtn.click(); } }); }


    // Product Input Mode Toggles & Verify
    if (toggleManualProductBtn) { 
            toggleManualProductBtn.addEventListener('click', () => { 
            stopScanner(false);
            scanProductSection?.classList.add('hidden'); 
            manualProductSection?.classList.remove('hidden'); 
            productSkuInput?.focus(); 
        });
    }

    if (toggleScanProductBtn) { 
            toggleScanProductBtn.addEventListener('click', () => { 
            manualProductSection?.classList.add('hidden'); 
            scanProductSection?.classList.remove('hidden'); 
        }); 
    }

    if (verifyManualProductBtn) {
        verifyManualProductBtn.addEventListener('click', () => {
             if (!currentTask) { showMessage("Error: No task loaded.", true); return; }
             const enteredSku = productSkuInput.value.trim().toUpperCase();
             const expectedSku = currentTask.product_sku.trim().toUpperCase();
             if (enteredSku === expectedSku) { showMessage("Product verified!", false); enablePickingControls(currentTask); } // Enable final controls
             else { showMessage(`Wrong Product! Entered: ${productSkuInput.value}, Expected: ${currentTask.product_sku}`, true); productSkuInput?.focus(); }
        });
    }

    if (productSkuInput) { 
        productSkuInput.addEventListener('keypress', (e) => { 
            if (e.key === 'Enter') { 
                e.preventDefault();
                verifyManualProductBtn.click(); 
            } 
        }); 
    }


    // Scan Buttons
    if (scanOrderBtn) { 
        scanOrderBtn.addEventListener('click', () => {
            resetUI(); 
            currentScanMode = 'order'; 
            startScanner(); 
        }); 
    }

    if (scanLocationBtn) { 
        scanLocationBtn.addEventListener('click', () => { 
            currentScanMode = 'location'; 
            startScanner(); 
        }); 
    }

    if (scanProductBtn) { 
        scanProductBtn.addEventListener('click', () => { 
            currentScanMode = 'product'; 
            startScanner(); 
        }); 
    }

    // Stop Scan Button
    if (stopScanBtn) { 
        stopScanBtn.addEventListener('click', () => { 
            stopScanner(false); 
            showMessage("Scanning stopped.", false); 
        }); 
    }

    // Confirmation Button & Input
    if (confirmPickBtn) { 
        confirmPickBtn.addEventListener('click', confirmPick); 
    }

    if (quantityPickedInput) { 
        quantityPickedInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault(); 
                confirmPick(); 
            } 
        }); 
    }
    
    // --- Initial State ---
    resetUI(); // Start with a clean UI

}); // End DOMContentLoaded

//# sourceMappingURL=mobile_picker.js.map
