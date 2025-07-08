console.log("✅ Mobile Picker Script Loaded!");

document.addEventListener('DOMContentLoaded', () => {
    console.log("DOM Loaded. Initializing Mobile Picker script.");

    // --- Configuration & Constants ---
    const API_BASE = window.WMS_CONFIG?.apiBase || '/api';
    const GET_TASK_API_URL = `${API_BASE}/picking/get_next_task.php`;
    const CONFIRM_PICK_API_URL = `${API_BASE}/picking/confirm_pick.php`;
    
    console.log('Mobile Picker API_BASE:', API_BASE);
    console.log('GET_TASK_API_URL:', GET_TASK_API_URL);

    // --- Global State ---
    let currentTask = null;
    let currentScanMode = null;
    let html5QrCode = null;

    // --- DOM Elements ---
    const elements = {
        // Messages & Loading
        loadingOverlay: document.getElementById('loading-overlay'),
        messageArea: document.getElementById('message-area'),
        
        // Order Input
        scanOrderSection: document.getElementById('scan-order-section'),
        manualOrderSection: document.getElementById('manual-order-section'),
        scanOrderBtn: document.getElementById('scan-order-btn'),
        orderIdInput: document.getElementById('order-id-input'),
        loadManualOrderBtn: document.getElementById('load-manual-order-btn'),
        toggleManualInputBtn: document.getElementById('toggle-manual-input-btn'),
        toggleScanInputBtn: document.getElementById('toggle-scan-input-btn'),
        scannedOrderIdEl: document.getElementById('scanned-order-id'),
        orderNumberDisplay: document.getElementById('order-number'),

        // Scanner
        scannerContainer: document.getElementById('scanner-container'),
        readerDiv: document.getElementById('reader'),
        stopScanBtn: document.getElementById('stop-scan-btn'),

        // Location
        locationScanPrompt: document.getElementById('location-scan-prompt'),
        targetLocationCodeEl: document.getElementById('target-location-code'),
        scanLocationBtn: document.getElementById('scan-location-btn'),
        scanLocationSection: document.getElementById('scan-location-section'),
        manualLocationSection: document.getElementById('manual-location-section'),
        locationCodeInput: document.getElementById('location-code-input'),
        verifyManualLocationBtn: document.getElementById('verify-manual-location-btn'),
        toggleManualLocationBtn: document.getElementById('toggle-manual-location-btn'),
        toggleScanLocationBtn: document.getElementById('toggle-scan-location-btn'),

        // Product
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

        // Task & Confirmation
        taskDisplay: document.getElementById('task-display'),
        confirmationArea: document.getElementById('confirmation-area'),
        quantityPickedInput: document.getElementById('quantity-picked-input'),
        confirmPickBtn: document.getElementById('confirm-pick-btn'),

        // Task Display Elements
        productNameEl: document.getElementById('product-name'),
        productSkuEl: document.getElementById('product-sku'),
        quantityToPickEl: document.getElementById('quantity-to-pick'),
        locationCodeEl: document.getElementById('location-code'),
        availableInLocationEl: document.getElementById('available-in-location'),

        // Completion
        allDoneMessage: document.getElementById('all-done-message'),
        completionState: document.getElementById('completion-state'),
        completionMessage: document.getElementById('completion-message'),
        errorState: document.getElementById('error-state'),
        errorMessage: document.getElementById('error-message'),
        loadingState: document.getElementById('loading-state')
    };

    // --- INITIALIZATION ---
    console.log('WMS Config:', window.WMS_CONFIG);
    
    // FIXED: Auto-start picking if order provided in URL
    if (window.WMS_CONFIG && window.WMS_CONFIG.hasOrderFromUrl && window.WMS_CONFIG.orderFromUrl) {
        console.log('Order provided in URL:', window.WMS_CONFIG.orderFromUrl);
        // Auto-start the picking process after DOM is ready
        setTimeout(() => {
            fetchNextTask(window.WMS_CONFIG.orderFromUrl);
        }, 500);
    }

    // --- UTILITY FUNCTIONS ---
    function showLoading(show = true) {
        if (elements.loadingOverlay) {
            elements.loadingOverlay.classList.toggle('hidden', !show);
        }
    }

    function showMessage(message, isError = false) {
        if (elements.messageArea) {
            elements.messageArea.textContent = message;
            elements.messageArea.className = `message-area ${isError ? 'error' : 'success'}`;
            elements.messageArea.classList.remove('hidden');
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                elements.messageArea.classList.add('hidden');
            }, 5000);
        }
        console.log(isError ? `❌ ${message}` : `✅ ${message}`);
    }

    function hideAllSections() {
        const sections = [
            elements.locationScanPrompt,
            elements.productScanPrompt,
            elements.taskDisplay,
            elements.confirmationArea,
            elements.allDoneMessage,
            elements.completionState,
            elements.errorState,
            elements.loadingState,
            elements.scannerContainer
        ];
        sections.forEach(section => section?.classList.add('hidden'));
    }

    // --- CORE WORKFLOW FUNCTIONS ---
    async function fetchNextTask(orderId) {
        if (!orderId || String(orderId).trim() === '') {
            showMessage('ID comandă invalid.', true);
            return;
        }

        const trimmedOrderId = String(orderId).trim();
        console.log(`fetchNextTask called with Order ID: ${trimmedOrderId}`);

        if (elements.orderNumberDisplay) {
            elements.orderNumberDisplay.textContent = `#${trimmedOrderId}`;
        }

        hideAllSections();
        elements.loadingState?.classList.remove('hidden');
        showLoading();

        try {
            const response = await fetch(`${GET_TASK_API_URL}?order_id=${encodeURIComponent(trimmedOrderId)}`);
            
            if (!response.ok) {
                let errorMsg = `HTTP error ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMsg = errorData.message || errorMsg;
                } catch(err) {
                    // Keep the HTTP error message
                }
                throw new Error(errorMsg);
            }

            const result = await response.json();
            console.log("Fetch result:", result);

            if (result.status === 'success') {
                // FIXED: Properly validate location_code exists
                if (result.data && result.data.location_code !== undefined) {
                    showLocationScanPrompt(result.data);
                } else {
                    throw new Error('Date task invalide: lipsește location_code din răspunsul API');
                }
            } else if (result.status === 'complete') {
                hideAllSections();
                elements.allDoneMessage?.classList.remove('hidden');
                showMessage('Picking comandă completat!', false);
            } else {
                throw new Error(result.message || `Eroare API: ${result.status}`);
            }
        } catch (error) {
            console.error('Fetch Task Error:', error);
            showMessage(`Eroare: ${error.message || 'Eroare de rețea.'}`, true);
            hideAllSections();
            if (elements.errorState) {
                elements.errorState.classList.remove('hidden');
                if (elements.errorMessage) {
                    elements.errorMessage.textContent = error.message;
                }
            }
        } finally {
            showLoading(false);
            elements.loadingState?.classList.add('hidden');
        }
    }

    function showLocationScanPrompt(taskData) {
        console.log("Showing location scan prompt for location:", taskData.location_code);
        currentTask = taskData;
        currentScanMode = 'location';

        // FIXED: Handle missing or null location_code gracefully
        const locationCode = taskData.location_code || 'N/A';
        if (elements.targetLocationCodeEl) {
            elements.targetLocationCodeEl.textContent = locationCode;
        }
        if (elements.locationCodeInput) {
            elements.locationCodeInput.value = '';
        }

        hideAllSections();
        elements.manualLocationSection?.classList.add('hidden');
        elements.scanLocationSection?.classList.remove('hidden');
        elements.locationScanPrompt?.classList.remove('hidden');

        showMessage(`Vă rugăm să mergeți la locația: ${locationCode}`, false);
    }

    function showProductScanPrompt(taskData) {
        console.log("Showing product scan prompt for product:", taskData.product_sku);
        currentTask = taskData;
        currentScanMode = 'product';

        if (elements.targetProductSkuEl) {
            elements.targetProductSkuEl.textContent = taskData.product_sku || 'N/A';
        }
        if (elements.targetProductNameEl) {
            elements.targetProductNameEl.textContent = `(${taskData.product_name || 'Nume necunoscut'})`;
        }
        if (elements.productSkuInput) {
            elements.productSkuInput.value = '';
        }

        hideAllSections();
        elements.manualProductSection?.classList.add('hidden');
        elements.scanProductSection?.classList.remove('hidden');
        elements.productScanPrompt?.classList.remove('hidden');

        showMessage("Vă rugăm să scanați sau introduceți SKU-ul produsului.", false);
    }

    function enablePickingControls(taskData) {
        console.log("Enabling picking controls for task:", taskData);
        currentTask = taskData;

        // Populate task display
        if (elements.productNameEl) elements.productNameEl.textContent = taskData.product_name || 'N/A';
        if (elements.productSkuEl) elements.productSkuEl.textContent = taskData.product_sku || 'N/A';
        if (elements.quantityToPickEl) elements.quantityToPickEl.textContent = taskData.quantity_to_pick || '0';
        if (elements.locationCodeEl) elements.locationCodeEl.textContent = taskData.location_code || 'N/A';
        if (elements.availableInLocationEl) elements.availableInLocationEl.textContent = taskData.available_in_location || '0';

        // Set default quantity
        if (elements.quantityPickedInput) {
            elements.quantityPickedInput.value = taskData.quantity_to_pick || '';
        }

        hideAllSections();
        elements.taskDisplay?.classList.remove('hidden');
        elements.confirmationArea?.classList.remove('hidden');

        showMessage("Confirmați cantitatea colectată.", false);
    }

    async function confirmPick() {
        if (!currentTask) {
            showMessage('Nicio sarcină activă.', true);
            return;
        }

        const quantity = parseInt(elements.quantityPickedInput?.value, 10);
        
        if (isNaN(quantity) || quantity <= 0) {
            showMessage('Cantitate invalidă.', true);
            return;
        }

        if (quantity > currentTask.quantity_to_pick) {
            showMessage(`Nu se poate colecta mai mult decât este necesar: ${currentTask.quantity_to_pick}`, true);
            return;
        }

        showLoading();

        try {
            const response = await fetch(CONFIRM_PICK_API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    order_item_id: currentTask.order_item_id,
                    quantity_picked: quantity
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const result = await response.json();

            if (result.status === 'success') {
                showMessage('Colectare confirmată! Se încarcă următoarea sarcină...', false);
                
                const orderId = currentTask.order_id || window.WMS_CONFIG?.orderFromUrl;
                
                setTimeout(() => {
                    fetchNextTask(orderId);
                }, 1000);
            } else {
                throw new Error(result.message || 'Confirmarea colectării a eșuat');
            }
        } catch (error) {
            console.error('Confirm Pick Error:', error);
            showMessage(`Eroare: ${error.message}`, true);
        } finally {
            showLoading(false);
        }
    }

    // --- SCANNER FUNCTIONS ---
    function onScanSuccess(decodedText, decodedResult) {
        console.log(`Scan Success! Mode: ${currentScanMode}, Decoded: ${decodedText}`);
        showMessage(`Scanat: ${decodedText}`, false);
        stopScanner(false);

        const scannedValue = decodedText.trim().toUpperCase();

        if (currentScanMode === 'order') {
            fetchNextTask(scannedValue);
        }
        else if (currentScanMode === 'location') {
            if (!currentTask) {
                showMessage("Eroare: Nicio sarcină încărcată pentru scanarea locației.", true);
                return;
            }
            const expectedLocation = (currentTask.location_code || '').trim().toUpperCase();
            if (scannedValue === expectedLocation) {
                showMessage("Locație verificată!", false);
                showProductScanPrompt(currentTask);
            } else {
                showMessage(`Locație greșită! Așteptat: ${currentTask.location_code}`, true);
            }
        }
        else if (currentScanMode === 'product') {
            if (!currentTask) {
                showMessage("Eroare: Nicio sarcină încărcată.", true);
                return;
            }
            const expectedSku = (currentTask.product_sku || '').trim().toUpperCase();
            if (scannedValue === expectedSku) {
                showMessage("Produs verificat!", false);
                enablePickingControls(currentTask);
            } else {
                showMessage(`Produs greșit! Așteptat: ${currentTask.product_sku}`, true);
            }
        }
    }

    function onScanFailure(error) {
        // Silent - don't spam console
    }

    function startScanner() {
        if (!elements.readerDiv) {
            showMessage('Element scanner nu a fost găsit.', true);
            return;
        }

        elements.scannerContainer?.classList.remove('hidden');

        if (html5QrCode) {
            console.log('Scanner already initialized');
            return;
        }

        try {
            html5QrCode = new Html5Qrcode("reader");
            
            const config = {
                fps: 10,
                qrbox: { width: 250, height: 250 }
            };

            html5QrCode.start(
                { facingMode: "environment" },
                config,
                onScanSuccess,
                onScanFailure
            ).catch(err => {
                console.error('Scanner start error:', err);
                showMessage('Accesul la cameră a eșuat. Verificați permisiunile.', true);
                elements.scannerContainer?.classList.add('hidden');
            });
        } catch (error) {
            console.error('Scanner initialization error:', error);
            showMessage('Inițializarea scannerului a eșuat.', true);
        }
    }

    function stopScanner(resetUI = true) {
        if (html5QrCode) {
            html5QrCode.stop().then(() => {
                html5QrCode.clear();
                html5QrCode = null;
                if (resetUI) {
                    elements.scannerContainer?.classList.add('hidden');
                }
            }).catch(err => {
                console.error('Error stopping scanner:', err);
                html5QrCode = null;
                if (resetUI) {
                    elements.scannerContainer?.classList.add('hidden');
                }
            });
        } else if (resetUI) {
            elements.scannerContainer?.classList.add('hidden');
        }
    }

    // --- EVENT LISTENERS ---

    // Order Input Events (only if no order in URL)
    if (!window.WMS_CONFIG?.hasOrderFromUrl) {
        elements.scanOrderBtn?.addEventListener('click', () => {
            currentScanMode = 'order';
            startScanner();
        });

        elements.toggleManualInputBtn?.addEventListener('click', () => {
            elements.scanOrderSection?.classList.add('hidden');
            elements.manualOrderSection?.classList.remove('hidden');
            elements.orderIdInput?.focus();
        });

        elements.toggleScanInputBtn?.addEventListener('click', () => {
            elements.manualOrderSection?.classList.add('hidden');
            elements.scanOrderSection?.classList.remove('hidden');
        });

        elements.loadManualOrderBtn?.addEventListener('click', () => {
            if (elements.orderIdInput?.value) {
                fetchNextTask(elements.orderIdInput.value);
            }
        });

        elements.orderIdInput?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (elements.orderIdInput?.value) {
                    fetchNextTask(elements.orderIdInput.value);
                }
            }
        });
    }

    // Location Events
    elements.scanLocationBtn?.addEventListener('click', () => {
        currentScanMode = 'location';
        startScanner();
    });

    elements.toggleManualLocationBtn?.addEventListener('click', () => {
        stopScanner(false);
        elements.scanLocationSection?.classList.add('hidden');
        elements.manualLocationSection?.classList.remove('hidden');
        elements.locationCodeInput?.focus();
    });

    elements.toggleScanLocationBtn?.addEventListener('click', () => {
        elements.manualLocationSection?.classList.add('hidden');
        elements.scanLocationSection?.classList.remove('hidden');
    });

    elements.verifyManualLocationBtn?.addEventListener('click', () => {
        if (!currentTask) {
            showMessage("Eroare: Nicio sarcină încărcată pentru verificarea manuală a locației.", true);
            return;
        }
        const enteredCode = (elements.locationCodeInput?.value || '').trim().toUpperCase();
        const expectedCode = (currentTask.location_code || '').trim().toUpperCase();
        if (enteredCode === expectedCode) {
            showMessage("Locație verificată!", false);
            showProductScanPrompt(currentTask);
        } else {
            showMessage(`Locație greșită! Așteptat: ${currentTask.location_code}`, true);
            elements.locationCodeInput?.focus();
        }
    });

    // Product Events
    elements.scanProductBtn?.addEventListener('click', () => {
        currentScanMode = 'product';
        startScanner();
    });

    elements.toggleManualProductBtn?.addEventListener('click', () => {
        stopScanner(false);
        elements.scanProductSection?.classList.add('hidden');
        elements.manualProductSection?.classList.remove('hidden');
        elements.productSkuInput?.focus();
    });

    elements.toggleScanProductBtn?.addEventListener('click', () => {
        elements.manualProductSection?.classList.add('hidden');
        elements.scanProductSection?.classList.remove('hidden');
    });

    elements.verifyManualProductBtn?.addEventListener('click', () => {
        if (!currentTask) {
            showMessage("Eroare: Nicio sarcină încărcată pentru verificarea manuală a produsului.", true);
            return;
        }
        const enteredSku = (elements.productSkuInput?.value || '').trim().toUpperCase();
        const expectedSku = (currentTask.product_sku || '').trim().toUpperCase();
        if (enteredSku === expectedSku) {
            showMessage("Produs verificat!", false);
            enablePickingControls(currentTask);
        } else {
            showMessage(`Produs greșit! Așteptat: ${currentTask.product_sku}`, true);
            elements.productSkuInput?.focus();
        }
    });

    // Scanner Control Events
    elements.stopScanBtn?.addEventListener('click', () => {
        stopScanner();
    });

    // Picking Confirmation Events
    elements.confirmPickBtn?.addEventListener('click', confirmPick);

    elements.quantityPickedInput?.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmPick();
        }
    });

    console.log("Mobile Picker initialization complete.");
});