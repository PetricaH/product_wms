// File: scripts/mobile_receiving.js
// Mobile Receiving Interface for WMS
// Production-ready with real API integration

document.addEventListener('DOMContentLoaded', () => {
    console.log("Mobile Receiving Interface Loaded");

    // --- Configuration ---
    const GET_DOCUMENT_API_URL = '/api/receiving/get_document.php';
    const VERIFY_PRODUCT_API_URL = '/api/receiving/verify_product.php';
    const ADD_ITEM_API_URL = '/api/receiving/add_item.php';
    const COMPLETE_RECEIPT_API_URL = '/api/receiving/complete_receipt.php';
    const GET_LOCATIONS_API_URL = '/api/receiving/get_locations.php';

    // --- DOM Elements ---
    // Document Input
    const scanDocumentBtn = document.getElementById('scan-document-btn');
    const toggleManualDocumentBtn = document.getElementById('toggle-manual-document-btn');
    const toggleScanDocumentBtn = document.getElementById('toggle-scan-document-btn');
    const manualDocumentSection = document.getElementById('manual-document-section');
    const documentNumberInput = document.getElementById('document-number-input');
    const loadDocumentBtn = document.getElementById('load-document-btn');
    const scannedDocumentInfo = document.getElementById('scanned-document-info');

    // Scanner
    const scannerContainer = document.getElementById('scanner-container');
    const readerDiv = document.getElementById('reader');
    const stopScanBtn = document.getElementById('stop-scan-btn');

    // Product Scanning
    const scanProductBtn = document.getElementById('scan-product-btn');
    const toggleManualProductBtn = document.getElementById('toggle-manual-product-btn');
    const toggleScanProductBtn = document.getElementById('toggle-scan-product-btn');
    const manualProductSection = document.getElementById('manual-product-section');
    const productSkuInput = document.getElementById('product-sku-input');
    const verifyProductBtn = document.getElementById('verify-product-btn');

    // Steps
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const step3 = document.getElementById('step3');
    const step4 = document.getElementById('step4');

    // Document Details
    const docNumberEl = document.getElementById('doc-number');
    const supplierNameEl = document.getElementById('supplier-name');
    const documentDateEl = document.getElementById('document-date');
    const documentStatusEl = document.getElementById('document-status');
    const startReceivingBtn = document.getElementById('start-receiving-btn');

    // Progress
    const totalItemsCountEl = document.getElementById('total-items-count');
    const receivedItemsCountEl = document.getElementById('received-items-count');
    const totalItemsDisplayEl = document.getElementById('total-items-display');
    const progressFillEl = document.getElementById('progress-fill');

    // Current Item
    const currentItemNameEl = document.getElementById('current-item-name');
    const currentItemSkuEl = document.getElementById('current-item-sku');
    const currentItemExpectedEl = document.getElementById('current-item-expected');

    // Receiving Details
    const receivingDetailsSection = document.getElementById('receiving-details-section');
    const receivedQuantityInput = document.getElementById('received-quantity');
    const storageLocationSelect = document.getElementById('storage-location');
    const batchNumberInput = document.getElementById('batch-number');
    const expiryDateInput = document.getElementById('expiry-date');
    const confirmReceiveBtn = document.getElementById('confirm-receive-btn');

    // Completion
    const finalDocumentNumberEl = document.getElementById('final-document-number');
    const finalItemsReceivedEl = document.getElementById('final-items-received');
    const completionDateEl = document.getElementById('completion-date');
    const generateNirBtn = document.getElementById('generate-nir-btn');
    const newReceivingBtn = document.getElementById('new-receiving-btn');

    // Messages
    const messageArea = document.getElementById('message-area');
    const allDoneMessage = document.getElementById('all-done-message');
    const loadingOverlay = document.getElementById('loading-overlay');

    // --- State Variables ---
    let html5QrCode;
    let currentScanMode = 'document'; // 'document' or 'product'
    let currentDocument = null;
    let currentItemIndex = 0;
    let receivedItems = [];

    // --- Utility Functions ---
    function showLoading() {
        if (loadingOverlay) loadingOverlay.classList.remove('hidden');
    }

    function hideLoading() {
        if (loadingOverlay) loadingOverlay.classList.add('hidden');
    }

    function showMessage(message, type = 'info') {
        if (!messageArea) return;
        
        const messageEl = document.createElement('div');
        messageEl.className = `message ${type}`;
        messageEl.innerHTML = `
            <span class="material-symbols-outlined">
                ${type === 'success' ? 'check_circle' : 
                  type === 'error' ? 'error' : 
                  type === 'warning' ? 'warning' : 'info'}
            </span>
            ${message}
        `;
        
        messageArea.appendChild(messageEl);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (messageEl.parentNode) {
                messageEl.parentNode.removeChild(messageEl);
            }
        }, 5000);
    }

    function showStep(stepNumber) {
        [step1, step2, step3, step4].forEach(step => {
            if (step) step.classList.add('hidden');
        });
        
        const targetStep = document.getElementById(`step${stepNumber}`);
        if (targetStep) targetStep.classList.remove('hidden');
    }

    function resetUI() {
        currentDocument = null;
        currentItemIndex = 0;
        receivedItems = [];
        
        // Reset form fields
        if (documentNumberInput) documentNumberInput.value = '';
        if (productSkuInput) productSkuInput.value = '';
        if (receivedQuantityInput) receivedQuantityInput.value = '';
        if (batchNumberInput) batchNumberInput.value = '';
        if (expiryDateInput) expiryDateInput.value = '';
        if (storageLocationSelect) storageLocationSelect.value = '';
        
        // Hide sections
        if (manualDocumentSection) manualDocumentSection.classList.add('hidden');
        if (manualProductSection) manualProductSection.classList.add('hidden');
        if (receivingDetailsSection) receivingDetailsSection.classList.add('hidden');
        if (allDoneMessage) allDoneMessage.classList.add('hidden');
        
        // Clear messages
        if (messageArea) messageArea.innerHTML = '';
        if (scannedDocumentInfo) scannedDocumentInfo.textContent = '';
        
        showStep(1);
    }

    // --- API Functions ---
    async function fetchDocumentDetails(documentNumber) {
        if (!documentNumber || String(documentNumber).trim() === '') {
            showMessage('Număr document invalid.', 'error');
            return;
        }

        const trimmedDocNumber = String(documentNumber).trim();
        console.log(`Fetching document details for: ${trimmedDocNumber}`);

        if (scannedDocumentInfo) {
            scannedDocumentInfo.textContent = `Se încarcă documentul: ${trimmedDocNumber}`;
        }

        showLoading();

        try {
            const response = await fetch(`${GET_DOCUMENT_API_URL}?document_number=${encodeURIComponent(trimmedDocNumber)}`);
            
            if (!response.ok) {
                let errorMessage = `HTTP error ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMessage = errorData.message || errorMessage;
                } catch (err) {
                    // Use default error message
                }
                throw new Error(errorMessage);
            }

            const result = await response.json();
            console.log("Document fetch result:", result);

            if (result.status === 'success' && result.data) {
                currentDocument = result.data;
                displayDocumentDetails();
                loadStorageLocations();
                showStep(2);
                showMessage('Document încărcat cu succes!', 'success');
                
                if (scannedDocumentInfo) {
                    scannedDocumentInfo.textContent = `Document încărcat: ${trimmedDocNumber}`;
                }
            } else {
                const errorMsg = result.message || `Document '${trimmedDocNumber}' nu a fost găsit.`;
                showMessage(errorMsg, 'error');
                
                if (scannedDocumentInfo) {
                    scannedDocumentInfo.textContent = `Eroare încărcare document: ${trimmedDocNumber}`;
                }
            }
        } catch (error) {
            console.error('Document fetch error:', error);
            showMessage(`Eroare: ${error.message || 'Eroare de rețea.'}`, 'error');
            
            if (scannedDocumentInfo) {
                scannedDocumentInfo.textContent = `Eroare încărcare document: ${trimmedDocNumber}`;
            }
        } finally {
            hideLoading();
        }
    }

    async function loadStorageLocations() {
        try {
            const response = await fetch(GET_LOCATIONS_API_URL);
            
            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const result = await response.json();
            
            if (result.status === 'success' && result.data) {
                populateLocationSelect(result.data);
            } else {
                console.warn('No locations data received');
                // Add default option if no locations available
                if (storageLocationSelect) {
                    storageLocationSelect.innerHTML = '<option value="">Nu sunt locații disponibile</option>';
                }
            }
        } catch (error) {
            console.error('Error loading locations:', error);
            showMessage('Eroare la încărcarea locațiilor de depozitare.', 'warning');
        }
    }

    function populateLocationSelect(locations) {
        if (!storageLocationSelect) return;
        
        storageLocationSelect.innerHTML = '<option value="">Selectează locația...</option>';
        
        locations.forEach(location => {
            const option = document.createElement('option');
            option.value = location.id;
            option.textContent = `${location.location_code} - ${location.description || location.zone}`;
            storageLocationSelect.appendChild(option);
        });
    }

    async function verifyProduct() {
        const enteredSku = productSkuInput ? productSkuInput.value.trim().toUpperCase() : '';
        
        if (!enteredSku) {
            showMessage('Introduceți SKU-ul produsului!', 'error');
            return;
        }

        if (!currentDocument || currentItemIndex >= currentDocument.items.length) {
            showMessage('Eroare: Nu există articole de procesat.', 'error');
            return;
        }

        const currentItem = currentDocument.items[currentItemIndex];
        
        showLoading();

        try {
            const response = await fetch(VERIFY_PRODUCT_API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    sku: enteredSku,
                    expected_sku: currentItem.sku,
                    document_id: currentDocument.id,
                    item_id: currentItem.id
                })
            });

            if (!response.ok) {
                let errorMessage = `HTTP error ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMessage = errorData.message || errorMessage;
                } catch (err) {
                    // Use default error message
                }
                throw new Error(errorMessage);
            }

            const result = await response.json();
            console.log("Product verification result:", result);

            if (result.status === 'success') {
                showMessage('Produs verificat cu succes!', 'success');
                
                // Set default quantity
                if (receivedQuantityInput) {
                    receivedQuantityInput.value = currentItem.expected_quantity || 1;
                    receivedQuantityInput.max = currentItem.expected_quantity || 999;
                }
                
                // Show receiving details section
                if (receivingDetailsSection) {
                    receivingDetailsSection.classList.remove('hidden');
                }
                
                // Focus on quantity input
                if (receivedQuantityInput) {
                    receivedQuantityInput.focus();
                }
            } else {
                const errorMsg = result.message || `SKU greșit! Se așteaptă: ${currentItem.sku}`;
                showMessage(errorMsg, 'error');
            }
        } catch (error) {
            console.error('Product verification error:', error);
            showMessage(`Eroare: ${error.message || 'Eroare de verificare produs.'}`, 'error');
        } finally {
            hideLoading();
        }
    }

    async function confirmReceiveItem() {
        const quantity = parseInt(receivedQuantityInput.value);
        const locationId = parseInt(storageLocationSelect.value);
        const batchNumber = batchNumberInput.value.trim();
        const expiryDate = expiryDateInput.value;

        // Validation
        if (!quantity || quantity <= 0) {
            showMessage('Introduceți o cantitate validă!', 'error');
            if (receivedQuantityInput) receivedQuantityInput.focus();
            return;
        }

        if (!locationId) {
            showMessage('Selectați locația de depozitare!', 'error');
            if (storageLocationSelect) storageLocationSelect.focus();
            return;
        }

        const currentItem = currentDocument.items[currentItemIndex];

        if (quantity > currentItem.expected_quantity) {
            showMessage(`Cantitatea nu poate fi mai mare decât ${currentItem.expected_quantity}!`, 'error');
            if (receivedQuantityInput) receivedQuantityInput.focus();
            return;
        }

        showLoading();

        const payload = {
            document_id: currentDocument.id,
            item_id: currentItem.id,
            product_id: currentItem.product_id,
            sku: currentItem.sku,
            received_quantity: quantity,
            location_id: locationId,
            batch_number: batchNumber || null,
            expiry_date: expiryDate || null
        };

        console.log("Confirm receive payload:", payload);

        try {
            const response = await fetch(ADD_ITEM_API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                let errorMessage = `HTTP error ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMessage = errorData.message || errorMessage;
                } catch (err) {
                    // Use default error message
                }
                throw new Error(errorMessage);
            }

            const result = await response.json();
            console.log("Add item result:", result);

            if (result.status === 'success') {
                // Record received item
                receivedItems.push({
                    ...currentItem,
                    received_quantity: quantity,
                    location_id: locationId,
                    batch_number: batchNumber,
                    expiry_date: expiryDate,
                    received_at: new Date().toISOString()
                });

                showMessage(result.message || 'Articol primit cu succes!', 'success');

                // Move to next item
                currentItemIndex++;
                updateProgress();

                // Reset form
                resetReceivingForm();

                if (currentItemIndex >= currentDocument.items.length) {
                    // All items received
                    completeReceiving();
                } else {
                    // Show next item
                    showCurrentItem();
                }
            } else {
                const errorMsg = result.message || 'Eroare la primirea articolului.';
                showMessage(errorMsg, 'error');
            }
        } catch (error) {
            console.error('Add item error:', error);
            showMessage(`Eroare: ${error.message || 'Eroare de rețea.'}`, 'error');
        } finally {
            hideLoading();
        }
    }

    async function completeReceiving() {
        showLoading();

        try {
            const response = await fetch(COMPLETE_RECEIPT_API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    document_id: currentDocument.id,
                    received_items: receivedItems
                })
            });

            if (!response.ok) {
                let errorMessage = `HTTP error ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMessage = errorData.message || errorMessage;
                } catch (err) {
                    // Use default error message
                }
                throw new Error(errorMessage);
            }

            const result = await response.json();
            console.log("Complete receiving result:", result);

            if (result.status === 'success') {
                // Update completion display
                if (finalDocumentNumberEl) {
                    finalDocumentNumberEl.textContent = currentDocument.document_number;
                }
                if (finalItemsReceivedEl) {
                    finalItemsReceivedEl.textContent = receivedItems.length;
                }
                if (completionDateEl) {
                    completionDateEl.textContent = new Date().toLocaleString('ro-RO');
                }

                showStep(4);
                showMessage('Recepție finalizată cu succes!', 'success');
            } else {
                const errorMsg = result.message || 'Eroare la finalizarea recepției.';
                showMessage(errorMsg, 'error');
            }
        } catch (error) {
            console.error('Complete receiving error:', error);
            showMessage(`Eroare: ${error.message || 'Eroare de finalizare.'}`, 'error');
        } finally {
            hideLoading();
        }
    }

    // --- Display Functions ---
    function displayDocumentDetails() {
        if (!currentDocument) return;

        if (docNumberEl) docNumberEl.textContent = currentDocument.document_number;
        if (supplierNameEl) supplierNameEl.textContent = currentDocument.supplier_name;
        if (documentDateEl) documentDateEl.textContent = currentDocument.document_date;
        if (documentStatusEl) documentStatusEl.textContent = currentDocument.status;

        if (totalItemsCountEl) totalItemsCountEl.textContent = currentDocument.items.length;
        if (totalItemsDisplayEl) totalItemsDisplayEl.textContent = currentDocument.items.length;

        updateProgress();
    }

    function updateProgress() {
        const received = receivedItems.length;
        const total = currentDocument ? currentDocument.items.length : 0;
        const percentage = total > 0 ? (received / total) * 100 : 0;

        if (progressFillEl) progressFillEl.style.width = `${percentage}%`;
        if (receivedItemsCountEl) receivedItemsCountEl.textContent = received;
    }

    function showCurrentItem() {
        if (!currentDocument || currentItemIndex >= currentDocument.items.length) return;

        const item = currentDocument.items[currentItemIndex];
        
        if (currentItemNameEl) currentItemNameEl.textContent = item.product_name;
        if (currentItemSkuEl) currentItemSkuEl.textContent = item.sku;
        if (currentItemExpectedEl) currentItemExpectedEl.textContent = item.expected_quantity;
    }

    function resetReceivingForm() {
        if (productSkuInput) productSkuInput.value = '';
        if (receivedQuantityInput) receivedQuantityInput.value = '';
        if (batchNumberInput) batchNumberInput.value = '';
        if (expiryDateInput) expiryDateInput.value = '';
        if (storageLocationSelect) storageLocationSelect.value = '';
        
        if (manualProductSection) manualProductSection.classList.add('hidden');
        if (receivingDetailsSection) receivingDetailsSection.classList.add('hidden');
    }

    // --- Scanner Functions ---
    function onScanSuccess(decodedText, decodedResult) {
        console.log(`Scan Success! Mode: ${currentScanMode}, Decoded: ${decodedText}`);
        showMessage(`Scanat: ${decodedText}`, 'success');
        stopScanner();

        const scannedValue = decodedText.trim();

        if (currentScanMode === 'document') {
            fetchDocumentDetails(scannedValue);
        } else if (currentScanMode === 'product') {
            if (productSkuInput) {
                productSkuInput.value = scannedValue;
                verifyProduct();
            }
        }
    }

    function onScanFailure(error) {
        // Usually ignore scanning failures
    }

    function startScanner() {
        if (typeof Html5Qrcode === 'undefined') {
            showMessage("Librăria scanner-ului nu este disponibilă.", 'error');
            return;
        }

        if (html5QrCode?.isScanning) {
            console.log("Scanner-ul rulează deja.");
            return;
        }

        if (!html5QrCode) {
            try {
                html5QrCode = new Html5Qrcode("reader");
            } catch (e) {
                console.error("Eroare inițializare scanner:", e);
                showMessage("Eroare inițializare scanner.", 'error');
                return;
            }
        }

        const config = {
            fps: 10,
            qrbox: (w, h) => {
                let s = Math.min(w, h) * 0.8;
                return { width: Math.max(s, 200), height: Math.max(s, 200) };
            },
            aspectRatio: 1.0,
            rememberLastUsedCamera: true,
            supportedScanTypes: [Html5QrcodeScanType.SCAN_TYPE_CAMERA]
        };

        console.log("Starting scanner for mode:", currentScanMode);
        if (scannerContainer) scannerContainer.classList.remove('hidden');
        if (stopScanBtn) stopScanBtn.classList.remove('hidden');

        const modeText = currentScanMode === 'document' ? 'document' : 'produs';
        showMessage(`Îndreptați camera către ${modeText}...`, 'info');

        html5QrCode.start(
            { facingMode: "environment" },
            config,
            onScanSuccess,
            onScanFailure
        ).catch((err) => {
            console.error(`Scanner start failed (${currentScanMode}):`, err);
            
            let userMsg = `Eroare pornire scanner: ${err}`;
            if (String(err).includes("Permission") || String(err).includes("NotAllowed")) {
                userMsg = "Acces la cameră refuzat.";
            } else if (String(err).includes("NotFoundError") || String(err).includes("Requested camera")) {
                userMsg = "Camera nu a fost găsită.";
            }
            
            showMessage(userMsg, 'error');
            if (scannerContainer) scannerContainer.classList.add('hidden');
            if (stopScanBtn) stopScanBtn.classList.add('hidden');
        });
    }

    function stopScanner() {
        if (html5QrCode?.isScanning) {
            console.log("Se oprește scanner-ul...");
            html5QrCode.stop().then(() => {
                console.log("Scanner oprit.");
            }).catch((err) => {
                console.error("Eroare oprire scanner:", err);
            });
        }
        
        if (scannerContainer) scannerContainer.classList.add('hidden');
        if (stopScanBtn) stopScanBtn.classList.add('hidden');
    }

    // --- Event Listeners ---

    // Document Input
    if (toggleManualDocumentBtn) {
        toggleManualDocumentBtn.addEventListener('click', () => {
            stopScanner();
            if (manualDocumentSection) manualDocumentSection.classList.remove('hidden');
            if (documentNumberInput) documentNumberInput.focus();
            if (scannedDocumentInfo) scannedDocumentInfo.textContent = '';
        });
    }

    if (toggleScanDocumentBtn) {
        toggleScanDocumentBtn.addEventListener('click', () => {
            if (manualDocumentSection) manualDocumentSection.classList.add('hidden');
            if (scannedDocumentInfo) scannedDocumentInfo.textContent = '';
        });
    }

    if (loadDocumentBtn) {
        loadDocumentBtn.addEventListener('click', () => {
            if (documentNumberInput) {
                fetchDocumentDetails(documentNumberInput.value);
            }
        });
    }

    if (documentNumberInput) {
        documentNumberInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                fetchDocumentDetails(documentNumberInput.value);
            }
        });
    }

    // Scanner Controls
    if (scanDocumentBtn) {
        scanDocumentBtn.addEventListener('click', () => {
            currentScanMode = 'document';
            startScanner();
        });
    }

    if (scanProductBtn) {
        scanProductBtn.addEventListener('click', () => {
            currentScanMode = 'product';
            startScanner();
        });
    }

    if (stopScanBtn) {
        stopScanBtn.addEventListener('click', () => {
            stopScanner();
            showMessage("Scanare oprită.", 'info');
        });
    }

    // Product Input
    if (toggleManualProductBtn) {
        toggleManualProductBtn.addEventListener('click', () => {
            stopScanner();
            if (manualProductSection) manualProductSection.classList.remove('hidden');
            if (productSkuInput) productSkuInput.focus();
        });
    }

    if (toggleScanProductBtn) {
        toggleScanProductBtn.addEventListener('click', () => {
            if (manualProductSection) manualProductSection.classList.add('hidden');
        });
    }

    if (verifyProductBtn) {
        verifyProductBtn.addEventListener('click', verifyProduct);
    }

    if (productSkuInput) {
        productSkuInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                verifyProduct();
            }
        });
    }

    // Receiving
    if (startReceivingBtn) {
        startReceivingBtn.addEventListener('click', () => {
            currentItemIndex = 0;
            showCurrentItem();
            showStep(3);
        });
    }

    if (confirmReceiveBtn) {
        confirmReceiveBtn.addEventListener('click', confirmReceiveItem);
    }

    if (receivedQuantityInput) {
        receivedQuantityInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                confirmReceiveItem();
            }
        });
    }

    // Completion
    if (generateNirBtn) {
        generateNirBtn.addEventListener('click', () => {
            // Generate NIR report - implement based on your needs
            showMessage('Funcția de generare NIR va fi implementată.', 'info');
        });
    }

    if (newReceivingBtn) {
        newReceivingBtn.addEventListener('click', resetUI);
    }

    // --- Initialize ---
    resetUI();
    console.log("Mobile Receiving Interface Ready");
});
//# sourceMappingURL=mobile_receiving.js.map
