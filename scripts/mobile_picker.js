// File: src/js/pages/mobile_picker.js
// Added html5-qrcode integration

document.addEventListener('DOMContentLoaded', () => {
    console.log("DOM Loaded. Initializing script.");

    // --- Configuration ---
    const GET_TASK_API_URL = '/api/picking/get_next_task.php';
    const CONFIRM_PICK_API_URL = '/api/picking/confirm_pick.php';

    // --- DOM Elements ---
    // Removed orderIdInput, loadOrderBtn
    const scanOrderBtn = document.getElementById('scan-order-btn');
    const scannerContainer = document.getElementById('scanner-container');
    const readerDiv = document.getElementById('reader'); // Div where scanner UI goes
    const scannedOrderIdEl = document.getElementById('scanned-order-id');

    const taskDisplay = document.getElementById('task-display');
    const confirmationArea = document.getElementById('confirmation-area');
    const messageArea = document.getElementById('message-area');
    const allDoneMessage = document.getElementById('all-done-message');
    const quantityPickedInput = document.getElementById('quantity-picked-input');
    const confirmPickBtn = document.getElementById('confirm-pick-btn');
    const loadingOverlay = document.getElementById('loading-overlay');

    // Task Data Elements
    const productNameEl = document.getElementById('product-name');
    const productSkuEl = document.getElementById('product-sku');
    const locationCodeEl = document.getElementById('location-code');
    const batchNumberEl = document.getElementById('batch-number');
    const inventoryIdEl = document.getElementById('inventory-id');
    const quantityToPickEl = document.getElementById('quantity-to-pick');
    const totalNeededEl = document.getElementById('total-needed');
    const availableInLocationEl = document.getElementById('available-in-location');

    // Check if essential elements were found
    if (!scanOrderBtn) console.error("Error: Scan Order button not found!");
    if (!readerDiv) console.error("Error: Scanner reader div not found!");
    if (!scannerContainer) console.error("Error: Scanner container div not found!");
    if (!confirmPickBtn) console.error("Error: Confirm Pick button not found!");
    if (!loadingOverlay) console.error("Error: Loading overlay not found!");
    if (!quantityPickedInput) console.error("Error: Quantity picked input not found!");

    // --- State ---
    let currentTask = null;
    let html5QrCode = null; // To hold the scanner instance

    // --- Functions ---

    function showLoading() {
        if (loadingOverlay) loadingOverlay.classList.remove('hidden');
    }

    function hideLoading() {
        if (loadingOverlay) loadingOverlay.classList.add('hidden');
    }

    function showMessage(message, isError = false) {
        if (!messageArea) return;
        messageArea.textContent = message;
        messageArea.className = `message-area ${isError ? 'message-area--error' : 'message-area--success'}`;
        setTimeout(() => {
            if (messageArea.textContent === message) {
               messageArea.textContent = '';
               messageArea.className = 'message-area';
            }
        }, 5000);
    }

    function clearTaskDisplay() {
        if (taskDisplay) taskDisplay.classList.add('hidden');
        if (confirmationArea) confirmationArea.classList.add('hidden');
        if (allDoneMessage) allDoneMessage.classList.add('hidden');
        if (scannedOrderIdEl) scannedOrderIdEl.textContent = ''; // Clear scanned ID display
        const elementsToClear = [productNameEl, productSkuEl, locationCodeEl, batchNumberEl, inventoryIdEl, quantityToPickEl, totalNeededEl, availableInLocationEl];
        elementsToClear.forEach(el => { if (el) el.textContent = ''; });
        if (inventoryIdEl) inventoryIdEl.textContent = '0';
        if (quantityToPickEl) quantityToPickEl.textContent = '0';
        if (totalNeededEl) totalNeededEl.textContent = '0';
        if (availableInLocationEl) availableInLocationEl.textContent = '0';
        if (quantityPickedInput) quantityPickedInput.value = '';
        currentTask = null;
    }

    function displayTask(taskData) {
        if (!taskData || typeof taskData !== 'object') {
            console.error("Invalid task data received:", taskData);
            showMessage("Received invalid task data from server.", true);
            return;
        }
        currentTask = taskData;
        if (productNameEl) productNameEl.textContent = taskData.product_name || 'N/A';
        if (productSkuEl) productSkuEl.textContent = taskData.product_sku || 'N/A';
        if (locationCodeEl) locationCodeEl.textContent = taskData.location_code || 'N/A';
        if (batchNumberEl) batchNumberEl.textContent = taskData.batch_number || 'None';
        if (inventoryIdEl) inventoryIdEl.textContent = taskData.inventory_id || '0';
        if (quantityToPickEl) quantityToPickEl.textContent = taskData.quantity_to_pick || '0';
        if (totalNeededEl) totalNeededEl.textContent = taskData.total_needed_for_item || '0';
        if (availableInLocationEl) availableInLocationEl.textContent = taskData.available_in_location || '0';

        if (quantityPickedInput && quantityPickedInput instanceof HTMLInputElement) {
            try {
                quantityPickedInput.value = taskData.quantity_to_pick || '';
                const maxVal = parseInt(taskData.quantity_to_pick, 10);
                if (!isNaN(maxVal)) {
                    quantityPickedInput.max = maxVal.toString();
                } else {
                    quantityPickedInput.removeAttribute('max');
                }
            } catch (e) {
                 console.error("Error setting properties on quantityPickedInput:", e);
                 showMessage("Internal UI error setting quantity input.", true);
            }
        } else {
            console.warn("quantityPickedInput not found or is not an input element.");
        }

        if (taskDisplay) taskDisplay.classList.remove('hidden');
        if (confirmationArea) confirmationArea.classList.remove('hidden');
        if (allDoneMessage) allDoneMessage.classList.add('hidden');
        if (messageArea) messageArea.textContent = '';
    }

    async function fetchNextTask(orderId) {
        const numericOrderId = parseInt(orderId, 10);
        // Basic validation - might need adjustment if order IDs are not purely numeric
        if (isNaN(numericOrderId) || numericOrderId <= 0) {
            showMessage(`Invalid Order ID scanned: ${orderId}`, true);
            console.log("fetchNextTask aborted: Invalid orderId.");
            stopScanner(); // Stop scanner if scan was invalid
            return;
        }

        // Display the scanned ID
        if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Loading Order: ${numericOrderId}`;

        clearTaskDisplay(); // Clear previous task first
        showLoading();
        if (messageArea) messageArea.textContent = '';

        try {
            const response = await fetch(`${GET_TASK_API_URL}?order_id=${numericOrderId}`);
             if (!response.ok) {
                let errorMsg = `HTTP error! Status: ${response.status} ${response.statusText}`;
                try {
                    const errorResult = await response.json();
                    errorMsg = errorResult.message || errorMsg;
                } catch (e) { /* Ignore */ }
                throw new Error(errorMsg);
            }
            const result = await response.json();

            if (result.status === 'success') {
                displayTask(result.data);
                 if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Loaded Order: ${numericOrderId}`;
            } else if (result.status === 'complete') {
                if (taskDisplay) taskDisplay.classList.add('hidden');
                if (confirmationArea) confirmationArea.classList.add('hidden');
                if (allDoneMessage) allDoneMessage.classList.remove('hidden');
                showMessage('Order picking complete!', false);
                 if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Order ${numericOrderId} is complete.`;
            } else {
                const errorMessage = result.message || `API Error: ${result.status}`;
                showMessage(errorMessage, true);
                if (taskDisplay) taskDisplay.classList.add('hidden');
                if (confirmationArea) confirmationArea.classList.add('hidden');
                if (allDoneMessage) allDoneMessage.classList.add('hidden');
                 if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Error loading Order: ${numericOrderId}`;
            }
        } catch (error) {
            console.error('Fetch Task Error Caught:', error);
            showMessage(`Error: ${error.message || 'Network error or invalid response.'}`, true);
            if (taskDisplay) taskDisplay.classList.add('hidden');
            if (confirmationArea) confirmationArea.classList.add('hidden');
            if (allDoneMessage) allDoneMessage.classList.add('hidden');
            if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Error loading Order: ${numericOrderId}`;
        } finally {
            hideLoading();
        }
    }

    async function confirmPick() {
        if (!currentTask) {
            showMessage('No active task loaded to confirm.', true);
            return;
        }
        const quantity = parseInt(quantityPickedInput.value, 10);
        if (isNaN(quantity) || quantity <= 0) {
            showMessage('Please enter a valid positive quantity picked.', true);
            if (quantityPickedInput) quantityPickedInput.focus();
            return;
        }
        if (quantity > currentTask.quantity_to_pick) {
            showMessage(`Cannot pick more than ${currentTask.quantity_to_pick} from this specific location/batch for this step.`, true);
            if (quantityPickedInput) quantityPickedInput.focus();
            return;
        }
         if (quantity > currentTask.available_in_location) {
             showMessage(`Error: Only ${currentTask.available_in_location} available in this location/batch.`, true);
             if (quantityPickedInput) quantityPickedInput.focus();
             return;
         }

        showLoading();
        if (messageArea) messageArea.textContent = '';

        const payload = {
            order_item_id: currentTask.order_item_id,
            inventory_id: currentTask.inventory_id,
            quantity_picked: quantity
        };

        try {
            const response = await fetch(CONFIRM_PICK_API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            });

             if (!response.ok) {
                let errorMsg = `HTTP error! Status: ${response.status} ${response.statusText}`;
                try {
                    const errorResult = await response.json();
                    errorMsg = errorResult.message || errorMsg;
                } catch (e) { /* Ignore */ }
                throw new Error(errorMsg);
            }
            const result = await response.json();

            if (result.status === 'success') {
                showMessage(result.message || 'Pick confirmed successfully!', false);
                // Re-use the order ID from the current task to fetch the next one
                if (currentTask.order_id) {
                     fetchNextTask(currentTask.order_id); // Fetch next task on success
                } else {
                    console.error("Cannot fetch next task: order_id missing from current task data.");
                    showMessage("Pick confirmed, but couldn't fetch next task (missing order ID). Please scan again.", true);
                    clearTaskDisplay(); // Clear the confirmed task
                }

            } else {
                const errorMessage = result.message || `API Error: ${result.status}`;
                showMessage(errorMessage, true);
            }
        } catch (error) {
            console.error('Confirm Pick Error Caught:', error);
            showMessage(`Error: ${error.message || 'Network error or invalid response.'}`, true);
        } finally {
            hideLoading();
        }
    }

    // --- Scanner Functions ---

    /**
     * Called when a barcode is successfully scanned.
     * @param {string} decodedText - The decoded text from the barcode.
     * @param {object} decodedResult - More detailed result object from the library.
     */
    function onScanSuccess(decodedText, decodedResult) {
        console.log(`Code matched = ${decodedText}`, decodedResult);
        showMessage(`Scanned: ${decodedText}`, false);
        stopScanner(); // Stop scanner after successful scan

        // --- !!! IMPORTANT: Add validation if needed !!! ---
        // e.g., check if decodedText looks like an order ID format
        // if (!isValidOrderIdFormat(decodedText)) {
        //     showMessage(`Invalid Order ID format scanned: ${decodedText}`, true);
        //     startScanner(); // Optionally restart scanner immediately
        //     return;
        // }

        // Fetch the task for the scanned order ID
        fetchNextTask(decodedText);
    }

    /**
     * Called when scanning fails (e.g., camera permission denied, no barcode found).
     * This is often called repeatedly by the library, so keep it minimal.
     * @param {string} error - Error message from the library.
     */
    function onScanFailure(error) {
        // Handle scan failure, usually quietly unless it's a critical error like permissions
        // console.warn(`Code scan error = ${error}`);
        // showMessage(`Scanner error: ${error}`, true); // Can be noisy
    }

    /**
     * Initializes and starts the barcode scanner.
     */
    function startScanner() {
        // Ensure html5QrCode is available (loaded from CDN)
        if (typeof Html5Qrcode === 'undefined') {
             showMessage("Error: Scanner library not loaded.", true);
             return;
        }

        // Prevent starting multiple instances
        if (html5QrCode && html5QrCode.isScanning) {
            console.log("Scanner already running.");
            return;
        }

        // Create a new instance if it doesn't exist
        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode("reader"); // "reader" is the ID of the div
        }

        // Configuration for the scanner
        const config = {
            fps: 10, // Frames per second to attempt scanning
            qrbox: { width: 250, height: 250 }, // Size of the scanning box (optional)
            aspectRatio: 1.0, // Optional aspect ratio
            rememberLastUsedCamera: true, // Try to use the same camera as last time
             supportedScanTypes: [ // Limit scan types if needed
                 Html5QrcodeScanType.SCAN_TYPE_CAMERA
             ]
        };

        console.log("Starting scanner...");
        if (scannerContainer) scannerContainer.classList.remove('hidden'); // Show the scanner view
        if (messageArea) messageArea.textContent = "Point camera at Order Barcode..."; // Prompt user

        // Start scanning
        html5QrCode.start(
            { facingMode: "environment" }, // Prefer back camera
            config,
            onScanSuccess,
            onScanFailure
        ).catch((err) => {
            console.error("Failed to start scanner:", err);
            showMessage(`Error starting scanner: ${err}`, true);
            if (scannerContainer) scannerContainer.classList.add('hidden'); // Hide on error
        });
    }

     /**
     * Stops the barcode scanner if it's running.
     */
    function stopScanner() {
        if (html5QrCode && html5QrCode.isScanning) {
            console.log("Stopping scanner...");
            html5QrCode.stop().then((ignore) => {
                console.log("Scanner stopped successfully.");
                if (scannerContainer) scannerContainer.classList.add('hidden'); // Hide scanner view
                // Optionally clear the reader div content if needed
                // if (readerDiv) readerDiv.innerHTML = '';
            }).catch((err) => {
                console.error("Failed to stop scanner:", err);
                // Don't necessarily show error to user, but hide container
                 if (scannerContainer) scannerContainer.classList.add('hidden');
            });
        } else {
             // Ensure container is hidden even if scanner wasn't technically running
             if (scannerContainer) scannerContainer.classList.add('hidden');
        }
    }


    // --- Event Listeners ---

    // Attach listener to the Scan Order button
    if (scanOrderBtn) {
        scanOrderBtn.addEventListener('click', () => {
            console.log("Scan Order button clicked.");
            clearTaskDisplay(); // Clear any previous task
            startScanner(); // Start the scanner
        });
    } else {
        console.error("Scan Order button element not found on page load.");
    }

    // Keep other listeners
    if (confirmPickBtn) {
        confirmPickBtn.addEventListener('click', confirmPick);
    } else {
        console.error("Confirm Pick button element not found on page load.");
    }

    if (quantityPickedInput) {
        quantityPickedInput.addEventListener('keypress', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                confirmPick();
            }
        });
    } else {
         console.error("Quantity picked input element not found on page load.");
    }

}); 
//# sourceMappingURL=mobile_picker.js.map
