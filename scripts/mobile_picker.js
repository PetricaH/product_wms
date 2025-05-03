// File: src/js/pages/mobile_picker.js
// Added Manual Input Fallback for Order ID and Location Scan Step

document.addEventListener('DOMContentLoaded', () => {
    console.log("DOM Loaded. Initializing script.");

    // --- Configuration ---
    const GET_TASK_API_URL = '/api/picking/get_next_task.php';
    const CONFIRM_PICK_API_URL = '/api/picking/confirm_pick.php';

    // --- DOM Elements ---
    const scanOrderBtn = document.getElementById('scan-order-btn');
    const scannerContainer = document.getElementById('scanner-container');
    const readerDiv = document.getElementById('reader');
    const stopScanBtn = document.getElementById('stop-scan-btn');
    const scannedOrderIdEl = document.getElementById('scanned-order-id');

    // Manual Input Elements
    const scanOrderSection = document.getElementById('scan-order-section');
    const manualOrderSection = document.getElementById('manual-order-section');
    const orderIdInput = document.getElementById('order-id-input'); // Manual input field
    const loadManualOrderBtn = document.getElementById('load-manual-order-btn'); // Manual load button
    const toggleManualInputBtn = document.getElementById('toggle-manual-input-btn'); // Button to show manual input
    const toggleScanInputBtn = document.getElementById('toggle-scan-input-btn'); // Button to show scanner input

    const locationScanPrompt = document.getElementById('location-scan-prompt');
    const targetLocationCodeEl = document.getElementById('target-location-code');
    const scanLocationBtn = document.getElementById('scan-location-btn');

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
    const locationCodeEl = document.getElementById('location-code'); // In task display
    const batchNumberEl = document.getElementById('batch-number');
    const inventoryIdEl = document.getElementById('inventory-id');
    const quantityToPickEl = document.getElementById('quantity-to-pick');
    const totalNeededEl = document.getElementById('total-needed');
    const availableInLocationEl = document.getElementById('available-in-location');

    // Check if essential elements were found
    if (!scanOrderBtn) console.error("Error: Scan Order button not found!");
    if (!manualOrderSection) console.error("Error: Manual order section not found!");
    if (!scanOrderSection) console.error("Error: Scan order section not found!");
    if (!orderIdInput) console.error("Error: Manual Order ID input not found!");
    if (!loadManualOrderBtn) console.error("Error: Manual Load Order button not found!");
    if (!toggleManualInputBtn) console.error("Error: Toggle Manual Input button not found!");
    if (!toggleScanInputBtn) console.error("Error: Toggle Scan Input button not found!");
    if (!scanLocationBtn) console.error("Error: Scan Location button not found!");
    if (!readerDiv) console.error("Error: Scanner reader div not found!");
    if (!scannerContainer) console.error("Error: Scanner container div not found!");
    if (!confirmPickBtn) console.error("Error: Confirm Pick button not found!");
    if (!loadingOverlay) console.error("Error: Loading overlay not found!");
    if (!quantityPickedInput) console.error("Error: Quantity picked input not found!");
    if (!stopScanBtn) console.error("Error: Stop Scan button not found!");

    // --- State ---
    let currentTask = null; // Holds the full task details from API
    let html5QrCode = null; // Scanner instance
    let currentScanMode = null; // What are we expecting to scan? 'order', 'location', 'product' etc.

    // --- Functions ---

    /**
     * Shows the loading overlay.
     */
    function showLoading() {
        if (loadingOverlay) {
            loadingOverlay.classList.remove('hidden');
        } else {
            console.error("Cannot show loading: overlay element missing.");
        }
    }

    /**
     * Hides the loading overlay.
     */
    function hideLoading() {
        if (loadingOverlay) {
            loadingOverlay.classList.add('hidden');
        } else {
             console.error("Cannot hide loading: overlay element missing.");
        }
    }

    /**
     * Displays a message to the user.
     * @param {string} message - The message text.
     * @param {boolean} [isError=false] - True if it's an error message.
     */
    function showMessage(message, isError = false) {
        if (!messageArea) return;
        messageArea.textContent = message;
        // Use modifier classes for styling defined in SCSS
        messageArea.className = `message-area ${isError ? 'message-area--error' : 'message-area--success'}`;
        // Auto-clear non-error messages only
        if (!isError) {
            setTimeout(() => {
                if (messageArea.textContent === message) {
                   messageArea.textContent = '';
                   messageArea.className = 'message-area'; // Reset class
                }
            }, 3000); // Shorter timeout for success messages
        }
    }

    /**
     * Clears all dynamic sections of the UI and resets the state.
     */
    function resetUI() {
        console.log("Resetting UI");
        if (taskDisplay) taskDisplay.classList.add('hidden');
        if (confirmationArea) confirmationArea.classList.add('hidden');
        if (allDoneMessage) allDoneMessage.classList.add('hidden');
        if (locationScanPrompt) locationScanPrompt.classList.add('hidden');
        if (scannedOrderIdEl) scannedOrderIdEl.textContent = '';
        if (targetLocationCodeEl) targetLocationCodeEl.textContent = '';
        if (messageArea) messageArea.textContent = ''; // Clear messages
        if (orderIdInput) orderIdInput.value = ''; // Clear manual input

        // Hide manual input, show scan button by default
        if (manualOrderSection) manualOrderSection.classList.add('hidden');
        if (scanOrderSection) scanOrderSection.classList.remove('hidden');

        // Clear specific task details
        const elementsToClear = [productNameEl, productSkuEl, locationCodeEl, batchNumberEl, inventoryIdEl, quantityToPickEl, totalNeededEl, availableInLocationEl];
        elementsToClear.forEach(el => { if (el) el.textContent = ''; });

        // Reset default values for numeric displays if needed
        if (inventoryIdEl) inventoryIdEl.textContent = '0';
        if (quantityToPickEl) quantityToPickEl.textContent = '0';
        if (totalNeededEl) totalNeededEl.textContent = '0';
        if (availableInLocationEl) availableInLocationEl.textContent = '0';
        if (quantityPickedInput) quantityPickedInput.value = '';

        currentTask = null;
        currentScanMode = null; // Reset scan mode
        stopScanner(); // Ensure scanner is stopped
    }

    /**
     * Displays the product picking details after location is verified.
     * @param {object} taskData - The task data object from the API.
     */
    function displayTaskDetails(taskData) {
        console.log("Displaying task details:", taskData);
        currentTask = taskData; // Ensure current task is set

        if (productNameEl) productNameEl.textContent = taskData.product_name || 'N/A';
        if (productSkuEl) productSkuEl.textContent = taskData.product_sku || 'N/A';
        if (locationCodeEl) locationCodeEl.textContent = taskData.location_code || 'N/A'; // Show location here too
        if (batchNumberEl) batchNumberEl.textContent = taskData.batch_number || 'None';
        if (inventoryIdEl) inventoryIdEl.textContent = taskData.inventory_id || '0';
        if (quantityToPickEl) quantityToPickEl.textContent = taskData.quantity_to_pick || '0';
        if (totalNeededEl) totalNeededEl.textContent = taskData.total_needed_for_item || '0';
        if (availableInLocationEl) availableInLocationEl.textContent = taskData.available_in_location || '0';

        // Set quantity input value and max attribute
        if (quantityPickedInput && quantityPickedInput instanceof HTMLInputElement) {
            try {
                quantityPickedInput.value = taskData.quantity_to_pick || '';
                const maxVal = parseInt(taskData.quantity_to_pick, 10);
                quantityPickedInput.max = !isNaN(maxVal) ? maxVal.toString() : '';
            } catch (e) { console.error("Error setting input props:", e); }
        } else {
            console.warn("quantityPickedInput not found or is not an input element.");
        }

        // Hide location prompt, show task details and confirmation
        if (locationScanPrompt) locationScanPrompt.classList.add('hidden');
        if (taskDisplay) taskDisplay.classList.remove('hidden');
        if (confirmationArea) confirmationArea.classList.remove('hidden');
        if (allDoneMessage) allDoneMessage.classList.add('hidden');
    }

    /**
     * Shows the prompt to scan the target location.
     * @param {object} taskData - The task data object from the API.
     */
    function showLocationScanPrompt(taskData) {
        console.log("Showing location scan prompt for location:", taskData.location_code);
        currentTask = taskData; // Store the task data
        currentScanMode = 'location'; // Set the expected scan type

        if (targetLocationCodeEl) targetLocationCodeEl.textContent = taskData.location_code || 'N/A';

        // Hide other sections, show the prompt
        if (taskDisplay) taskDisplay.classList.add('hidden');
        if (confirmationArea) confirmationArea.classList.add('hidden');
        if (allDoneMessage) allDoneMessage.classList.add('hidden');
        if (locationScanPrompt) locationScanPrompt.classList.remove('hidden');

        showMessage("Please scan the location barcode.", false);
    }


    /**
     * Fetches the next picking task from the API for the given order ID.
     * @param {string|number} orderId - The Order ID (scanned or manually entered).
     */
    async function fetchNextTask(orderId) {
        // Validate Order ID (allow non-numeric strings)
        if (!orderId || String(orderId).trim() === '') {
             showMessage('Please enter or scan a valid Order ID.', true);
             stopScanner(); // Stop scanner if active
             return;
        }
        const trimmedOrderId = String(orderId).trim();
        console.log(`fetchNextTask called with Order ID: ${trimmedOrderId}`);

        if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Loading Order: ${trimmedOrderId}`;
        resetUI(); // Reset previous state but keep the input mode (scan/manual) visible
        showLoading();

        try {
            // Use the same API endpoint regardless of input method
            const response = await fetch(`${GET_TASK_API_URL}?order_id=${encodeURIComponent(trimmedOrderId)}`);
             if (!response.ok) {
                let errorMsg = `HTTP error! Status: ${response.status}`;
                try { errorMsg = (await response.json()).message || errorMsg; } catch(e){}
                throw new Error(errorMsg);
            }
            const result = await response.json();
            console.log("Fetch result:", result);

            if (result.status === 'success') {
                showLocationScanPrompt(result.data); // Go to location scan prompt
                if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Loaded Order: ${trimmedOrderId}`;
            } else if (result.status === 'complete') {
                if (allDoneMessage) allDoneMessage.classList.remove('hidden');
                showMessage('Order picking complete!', false);
                if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Order ${trimmedOrderId} is complete.`;
            } else {
                // Handle API errors like "order not found" or "no stock"
                const errorMessage = result.message || `API Error: ${result.status}`;
                showMessage(errorMessage, true);
                if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Error loading Order: ${trimmedOrderId}`;
            }
        } catch (error) {
            console.error('Fetch Task Error Caught:', error);
            showMessage(`Error: ${error.message || 'Network error or invalid response.'}`, true);
            if (scannedOrderIdEl) scannedOrderIdEl.textContent = `Error loading Order: ${trimmedOrderId}`;
        } finally {
            hideLoading();
        }
    }

    /**
     * Confirms the pick quantity for the current task via API.
     */
    async function confirmPick() {
        if (!currentTask) {
            showMessage('No active task loaded to confirm.', true);
            return;
        }
        const quantity = parseInt(quantityPickedInput.value, 10);

        // Input validation
         if (isNaN(quantity) || quantity <= 0) {
            showMessage('Please enter a valid positive quantity picked.', true);
            if (quantityPickedInput) quantityPickedInput.focus();
            return;
        }
        // Check against suggested quantity for this step
        if (quantity > currentTask.quantity_to_pick) {
            showMessage(`Cannot pick more than ${currentTask.quantity_to_pick} from this specific location/batch for this step.`, true);
            if (quantityPickedInput) quantityPickedInput.focus();
            return;
        }
        // Check against total available in this specific inventory record
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
        console.log("Confirm pick payload:", payload);

        try {
            const response = await fetch(CONFIRM_PICK_API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload)
            });

             if (!response.ok) {
                let errorMsg = `HTTP error! Status: ${response.status}`;
                 try { errorMsg = (await response.json()).message || errorMsg; } catch(e){}
                throw new Error(errorMsg);
            }
            const result = await response.json();
            console.log("Confirm result:", result);

            if (result.status === 'success') {
                showMessage(result.message || 'Pick confirmed successfully!', false);
                // Fetch next task using order_id stored in currentTask
                // Important: Ensure order_id is returned by get_next_task API
                if (currentTask.order_id) {
                     fetchNextTask(currentTask.order_id);
                } else {
                    console.error("Cannot fetch next task: order_id missing from current task data.");
                    showMessage("Pick confirmed, but couldn't fetch next task. Please scan order again.", true);
                    resetUI(); // Reset UI completely
                }
            } else {
                // Handle API errors like "failed to update"
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
     * Callback function executed by the scanner library on successful scan.
     * @param {string} decodedText - The text decoded from the barcode.
     * @param {object} decodedResult - Detailed result object from the library.
     */
    function onScanSuccess(decodedText, decodedResult) {
        console.log(`Scan Success! Mode: ${currentScanMode}, Decoded: ${decodedText}`);
        showMessage(`Scanned: ${decodedText}`, false);
        stopScanner(false); // Stop scanner after successful scan, don't reset UI yet

        // Process based on the current scanning mode
        if (currentScanMode === 'order') {
            fetchNextTask(decodedText); // Process the scanned order ID
        } else if (currentScanMode === 'location') {
            if (!currentTask) {
                showMessage("Error: No task loaded to verify location against.", true);
                return; // Should not happen if UI flow is correct
            }
            // Compare scanned location with target location (case-insensitive comparison is safer)
            if (decodedText.trim().toUpperCase() === currentTask.location_code.trim().toUpperCase()) {
                showMessage("Location verified!", false);
                displayTaskDetails(currentTask); // Show product details now
            } else {
                showMessage(`Wrong Location! Scanned: ${decodedText}, Expected: ${currentTask.location_code}`, true);
                // User needs to press "Scan Location" button again
            }
        }
        // Add other scan modes ('product', 'batch') here later
        else {
            console.warn("Scan occurred in unknown mode:", currentScanMode);
        }
    }

    /**
     * Callback function executed by the scanner library on scan failure.
     * @param {string} error - Error message from the library.
     */
    function onScanFailure(error) {
        // Usually too noisy to log every time, uncomment if needed for debugging
        // console.warn(`Code scan error = ${error}`);
    }

    /**
     * Initializes and starts the barcode scanner.
     */
    function startScanner() {
        // Check if library is loaded
        if (typeof Html5Qrcode === 'undefined') {
             showMessage("Error: Scanner library not loaded.", true); return;
        }
        // Prevent starting multiple instances
        if (html5QrCode && html5QrCode.isScanning) {
            console.log("Scanner already running."); return;
        }
        // Create instance if needed
        if (!html5QrCode) {
            try {
                 html5QrCode = new Html5Qrcode("reader"); // "reader" is the ID of the div
            } catch (e) {
                 console.error("Failed to initialize Html5Qrcode:", e);
                 showMessage("Failed to initialize scanner.", true);
                 return;
            }
        }

        // Scanner configuration
        const config = {
            fps: 10, // Scan frequency
            qrbox: (viewfinderWidth, viewfinderHeight) => {
                 // Responsive scan box size
                 let edgePercentage = 0.80;
                 let minEdgeSize = 200;
                 let edgeSize = Math.min(viewfinderWidth, viewfinderHeight);
                 let qrboxSize = Math.floor(edgeSize * edgePercentage);
                 return { width: Math.max(qrboxSize, minEdgeSize), height: Math.max(qrboxSize, minEdgeSize) };
            },
            aspectRatio: 1.0, // Preferred aspect ratio
            rememberLastUsedCamera: true, // Remember user's camera choice
            supportedScanTypes: [Html5QrcodeScanType.SCAN_TYPE_CAMERA] // Only use camera
        };

        console.log("Starting scanner for mode:", currentScanMode);
        if (scannerContainer) scannerContainer.classList.remove('hidden'); // Show scanner view
        if (stopScanBtn) stopScanBtn.classList.remove('hidden'); // Show stop button
        if (messageArea) messageArea.textContent = `Point camera at ${currentScanMode || 'barcode'}...`; // Prompt

        // Start scanning, preferring the back camera
        html5QrCode.start( { facingMode: "environment" }, config, onScanSuccess, onScanFailure)
        .catch((err) => {
            console.error("Failed to start scanner (environment):", err);
            // Try front camera as fallback (optional)
            if (String(err).includes("NotFoundError") || String(err).includes("Requested camera not available")) {
                console.log("Trying front camera...");
                html5QrCode.start({ facingMode: "user" }, config, onScanSuccess, onScanFailure)
                 .catch(err2 => {
                    console.error("Failed to start scanner (user):", err2);
                    let userMessage = `Error starting camera: ${err2}`;
                    if (String(err2).includes("Permission denied") || String(err2).includes("NotAllowedError")) {
                        userMessage = "Camera permission denied. Please allow camera access.";
                    }
                      showMessage(userMessage, true);
                      if (scannerContainer) scannerContainer.classList.add('hidden');
                      if (stopScanBtn) stopScanBtn.classList.add('hidden');
                 });
            } else {
                 // Handle other errors like permission denied directly
                 let userMessage = `Error starting scanner: ${err}`;
                 if (String(err).includes("Permission denied") || String(err).includes("NotAllowedError")) {
                     userMessage = "Camera permission denied. Please allow camera access.";
                 }
                 showMessage(userMessage, true);
                 if (scannerContainer) scannerContainer.classList.add('hidden');
                 if (stopScanBtn) stopScanBtn.classList.add('hidden');
            }
        });
    }

    /**
     * Stops the barcode scanner if it's running.
     * @param {boolean} [shouldResetUI=false] - Whether to reset the entire UI after stopping.
     */
    function stopScanner(shouldResetUI = false) {
        // Check if the library and scanning state exist before trying to stop
        // Use optional chaining ?. for safer access
        if (html5QrCode?.isScanning) {
            console.log("Attempting to stop scanner...");
            html5QrCode.stop().then(() => {
                console.log("Scanner stopped successfully via stop().");
                if (scannerContainer) scannerContainer.classList.add('hidden');
                if (stopScanBtn) stopScanBtn.classList.add('hidden');
                if (shouldResetUI) resetUI();
            }).catch((err) => {
                console.error("Error stopping scanner:", err);
                // Force hide UI elements even if stop() failed
                if (scannerContainer) scannerContainer.classList.add('hidden');
                if (stopScanBtn) stopScanBtn.classList.add('hidden');
                if (shouldResetUI) resetUI();
            });
        } else {
             // Ensure UI is hidden if scanner wasn't active or instance doesn't exist
             if (scannerContainer) scannerContainer.classList.add('hidden');
             if (stopScanBtn) stopScanBtn.classList.add('hidden');
             if (shouldResetUI) resetUI();
        }
    }

    // --- Event Listeners ---

    // Scan Order Button
    if (scanOrderBtn) {
        scanOrderBtn.addEventListener('click', () => {
            console.log("Scan Order button clicked.");
            resetUI(); // Reset previous state
            currentScanMode = 'order'; // Set mode for order scanning
            startScanner();
        });
    } else { console.error("Scan Order button not found."); }

    // Scan Location Button
    if (scanLocationBtn) {
         scanLocationBtn.addEventListener('click', () => {
            console.log("Scan Location button clicked.");
            // Mode should be 'location' if this button is visible, but set just in case
            currentScanMode = 'location';
            startScanner(); // Start scanner specifically for location
        });
    } else { console.error("Scan Location button not found."); }

    // Stop Scan Button
    if (stopScanBtn) {
        stopScanBtn.addEventListener('click', () => {
            console.log("Stop Scan button clicked.");
            stopScanner(false); // Stop scanner, don't reset task UI
            showMessage("Scanning stopped.", false);
        });
    } else { console.error("Stop Scan button not found."); }

    // Confirm Pick Button
    if (confirmPickBtn) {
        confirmPickBtn.addEventListener('click', confirmPick);
    } else { console.error("Confirm Pick button not found."); }

    // Quantity Input Enter Key
    if (quantityPickedInput) {
        quantityPickedInput.addEventListener('keypress', (event) => {
            if (event.key === 'Enter') { event.preventDefault(); confirmPick(); }
        });
    } else { console.error("Quantity picked input not found."); }

    // Toggle Manual Input Button
    if (toggleManualInputBtn) {
        toggleManualInputBtn.addEventListener('click', () => {
            console.log("Toggle Manual Input button clicked.");
            stopScanner(false); // Stop scanner if running
            if (scanOrderSection) scanOrderSection.classList.add('hidden');
            if (manualOrderSection) manualOrderSection.classList.remove('hidden');
            if (orderIdInput) orderIdInput.focus(); // Focus the input field
             if (scannedOrderIdEl) scannedOrderIdEl.textContent = ''; // Clear scanned info
        });
    } else { console.error("Toggle Manual Input button not found."); }

    // Toggle Scanner Button (from Manual section)
    if (toggleScanInputBtn) {
        toggleScanInputBtn.addEventListener('click', () => {
             console.log("Toggle Scan Input button clicked.");
             if (manualOrderSection) manualOrderSection.classList.add('hidden');
             if (scanOrderSection) scanOrderSection.classList.remove('hidden');
              if (scannedOrderIdEl) scannedOrderIdEl.textContent = ''; // Clear scanned info
        });
    } else { console.error("Toggle Scan Input button not found."); }

    // Manual Load Button
    if (loadManualOrderBtn) {
        loadManualOrderBtn.addEventListener('click', () => {
            console.log("Load Manual Order button clicked.");
            const orderId = orderIdInput.value;
            fetchNextTask(orderId); // Call fetch with manually entered ID
        });
         // Allow submitting manual input with Enter key
        if(orderIdInput) {
            orderIdInput.addEventListener('keypress', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault(); // Prevent form submission
                    console.log("Enter key pressed in manual order input.");
                    fetchNextTask(orderIdInput.value);
                }
            });
        }
    } else { console.error("Load Manual Order button not found."); }


    // --- Initial State ---
    resetUI(); // Ensure UI starts clean

}); // End DOMContentLoaded

//# sourceMappingURL=mobile_picker.js.map
