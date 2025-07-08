/**
 * Complete Products Page JavaScript
 * Modal management, bulk operations, and basic interactions
 */

// DOM Elements
const createModal = document.getElementById('createProductModal');
const editModal = document.getElementById('editProductModal');
const deleteModal = document.getElementById('deleteProductModal');

/**
 * Initialize page functionality
 */
document.addEventListener('DOMContentLoaded', function() {
    initializeModals();
    initializeSearch();
    autoHideAlerts();
    initializeBulkOperations();
});

/**
 * Modal Management
 */
function initializeModals() {
    // Close modals when clicking outside
    [createModal, editModal, deleteModal].forEach(modal => {
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeAllModals();
                }
            });
        }
    });
    
    // Close modals with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAllModals();
        }
    });
}

function openCreateModal() {
    resetCreateForm();
    showModal(createModal);
    
    // Focus first input
    setTimeout(() => {
        document.getElementById('create-name')?.focus();
    }, 100);
}

function closeCreateModal() {
    hideModal(createModal);
    resetCreateForm();
}

function openEditModal(productData) {
    populateEditForm(productData);
    showModal(editModal);
    
    // Focus first input
    setTimeout(() => {
        document.getElementById('edit-name')?.focus();
    }, 100);
}

function closeEditModal() {
    hideModal(editModal);
    resetEditForm();
}

function confirmDelete(productId, productName) {
    document.getElementById('delete-product-id').value = productId;
    document.getElementById('delete-product-name').textContent = productName;
    showModal(deleteModal);
}

function closeDeleteModal() {
    hideModal(deleteModal);
}

function showModal(modal) {
    if (modal) {
        modal.classList.add('show');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function hideModal(modal) {
    if (modal) {
        modal.classList.remove('show');
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

function closeAllModals() {
    [createModal, editModal, deleteModal].forEach(modal => {
        if (modal) {
            hideModal(modal);
        }
    });
}

/**
 * Form Management
 */
function resetCreateForm() {
    const form = createModal?.querySelector('form');
    if (form) {
        form.reset();
        // Set defaults
        document.getElementById('create-unit').value = 'pcs';
        document.getElementById('create-status').checked = true;
    }
}

function resetEditForm() {
    const form = editModal?.querySelector('form');
    if (form) {
        form.reset();
    }
}

function populateEditForm(productData) {
    try {
        const product = typeof productData === 'string' ? JSON.parse(productData) : productData;
        
        // Handle both 'id' and 'product_id' field names
        document.getElementById('edit-product-id').value = product.id || product.product_id || '';
        document.getElementById('edit-name').value = product.name || '';
        document.getElementById('edit-sku').value = product.sku || '';
        document.getElementById('edit-description').value = product.description || '';
        document.getElementById('edit-price').value = product.price || '';
        document.getElementById('edit-category').value = product.category || '';
        document.getElementById('edit-unit').value = product.unit || 'pcs';
        document.getElementById('edit-status').checked = (product.status == undefined ? 1 : product.status) == 1;
        
    } catch (error) {
        console.error('Error populating edit form:', error);
        showNotification('Eroare la încărcarea datelor produsului.', 'error');
        closeEditModal();
    }
}

/**
 * Search functionality
 */
function initializeSearch() {
    const searchInput = document.querySelector('input[name="search"]');
    const form = document.getElementById('filters-form');
    
    if (searchInput && form) {
        let searchTimeout;
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                // Reset to page 1 when searching
                const pageInput = form.querySelector('input[name="page"]');
                if (pageInput) {
                    pageInput.value = 1;
                }
                form.submit();
            }, 500); // Debounce search
        });
    }
}

/**
 * Form Validation
 */
function validateCreateForm() {
    const name = document.getElementById('create-name').value.trim();
    const sku = document.getElementById('create-sku').value.trim();
    
    if (!name || !sku) {
        showNotification('Numele și SKU-ul sunt obligatorii.', 'error');
        return false;
    }
    
    if (sku.length < 2) {
        showNotification('SKU-ul trebuie să aibă cel puțin 2 caractere.', 'error');
        return false;
    }
    
    return true;
}

function validateEditForm() {
    const name = document.getElementById('edit-name').value.trim();
    const sku = document.getElementById('edit-sku').value.trim();
    
    if (!name || !sku) {
        showNotification('Numele și SKU-ul sunt obligatorii.', 'error');
        return false;
    }
    
    if (sku.length < 2) {
        showNotification('SKU-ul trebuie să aibă cel puțin 2 caractere.', 'error');
        return false;
    }
    
    return true;
}

/**
 * Enhanced Form Submission
 */
document.addEventListener('DOMContentLoaded', function() {
    // Add validation to create form
    const createForm = createModal?.querySelector('form');
    if (createForm) {
        createForm.addEventListener('submit', function(e) {
            if (!validateCreateForm()) {
                e.preventDefault();
            }
        });
    }
    
    // Add validation to edit form
    const editForm = editModal?.querySelector('form');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            if (!validateEditForm()) {
                e.preventDefault();
            }
        });
    }
});

/**
 * BULK OPERATIONS FUNCTIONALITY
 */

/**
 * Initialize bulk operations functionality
 */
function initializeBulkOperations() {
    // Set up event listeners for checkboxes
    const selectAllCheckbox = document.getElementById('selectAll');
    const productCheckboxes = document.querySelectorAll('.product-checkbox');
    
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', toggleSelectAll);
    }
    
    productCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBulkActions);
    });
    
    // Initial update of bulk actions visibility
    updateBulkActions();
    
    // Add bulk operation feedback
    addBulkOperationFeedback();
}

/**
 * Toggle all product checkboxes based on select all checkbox
 */
function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const productCheckboxes = document.querySelectorAll('.product-checkbox');
    const isChecked = selectAllCheckbox.checked;
    
    productCheckboxes.forEach(checkbox => {
        checkbox.checked = isChecked;
    });
    
    updateBulkActions();
}

/**
 * Update bulk actions bar visibility and selected count
 */
function updateBulkActions() {
    const productCheckboxes = document.querySelectorAll('.product-checkbox');
    const selectAllCheckbox = document.getElementById('selectAll');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const selectedCountElement = document.getElementById('selectedCount');
    
    // Count selected checkboxes
    const selectedCheckboxes = Array.from(productCheckboxes).filter(cb => cb.checked);
    const selectedCount = selectedCheckboxes.length;
    const totalCount = productCheckboxes.length;
    
    // Update selected count display
    if (selectedCountElement) {
        selectedCountElement.textContent = selectedCount;
    }
    
    // Show/hide bulk actions bar
    if (bulkActionsBar) {
        if (selectedCount > 0) {
            bulkActionsBar.style.display = 'block';
            // Add smooth slide-down animation
            if (!bulkActionsBar.classList.contains('visible')) {
                bulkActionsBar.style.opacity = '0';
                bulkActionsBar.style.transform = 'translateY(-10px)';
                bulkActionsBar.classList.add('visible');
                
                requestAnimationFrame(() => {
                    bulkActionsBar.style.transition = 'all 0.3s ease';
                    bulkActionsBar.style.opacity = '1';
                    bulkActionsBar.style.transform = 'translateY(0)';
                });
            }
        } else {
            // Hide with animation
            bulkActionsBar.style.transition = 'all 0.3s ease';
            bulkActionsBar.style.opacity = '0';
            bulkActionsBar.style.transform = 'translateY(-10px)';
            
            setTimeout(() => {
                bulkActionsBar.style.display = 'none';
                bulkActionsBar.classList.remove('visible');
            }, 300);
        }
    }
    
    // Update select all checkbox state
    if (selectAllCheckbox) {
        if (selectedCount === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (selectedCount === totalCount) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        }
    }
}

/**
 * Perform bulk action after confirmation
 */
function performBulkAction(action) {
    const selectedCheckboxes = document.querySelectorAll('.product-checkbox:checked');
    const selectedCount = selectedCheckboxes.length;
    
    if (selectedCount === 0) {
        showNotification('Niciun produs selectat.', 'error');
        return;
    }
    
    // Get action details for confirmation
    const actionDetails = getBulkActionDetails(action, selectedCount);
    
    // Show confirmation dialog
    if (confirm(actionDetails.confirmMessage)) {
        // Set the bulk action type
        document.getElementById('bulkActionInput').value = action;
        
        // Submit the form
        document.getElementById('bulkForm').submit();
    }
}

/**
 * Get bulk action details for confirmation
 */
function getBulkActionDetails(action, count) {
    const details = {
        activate: {
            confirmMessage: `Ești sigur că vrei să activezi ${count} produs${count > 1 ? 'e' : ''}?`,
            buttonText: 'Activează',
            successMessage: `${count} produs${count > 1 ? 'e' : ''} activat${count > 1 ? 'e' : ''} cu succes.`
        },
        deactivate: {
            confirmMessage: `Ești sigur că vrei să dezactivezi ${count} produs${count > 1 ? 'e' : ''}?`,
            buttonText: 'Dezactivează',
            successMessage: `${count} produs${count > 1 ? 'e' : ''} dezactivat${count > 1 ? 'e' : ''} cu succes.`
        },
        delete: {
            confirmMessage: `Ești sigur că vrei să ștergi ${count} produs${count > 1 ? 'e' : ''}?\n\nAceastă acțiune nu poate fi anulată!`,
            buttonText: 'Șterge',
            successMessage: `${count} produs${count > 1 ? 'e' : ''} șters${count > 1 ? 'e' : ''} cu succes.`
        }
    };
    
    return details[action] || {
        confirmMessage: `Ești sigur că vrei să efectuezi această acțiune pentru ${count} produs${count > 1 ? 'e' : ''}?`,
        buttonText: 'Continuă',
        successMessage: `Acțiunea a fost efectuată pentru ${count} produs${count > 1 ? 'e' : ''}.`
    };
}

/**
 * Clear all selections
 */
function clearAllSelections() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const productCheckboxes = document.querySelectorAll('.product-checkbox');
    
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    }
    
    productCheckboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    
    updateBulkActions();
}

/**
 * Add visual feedback for bulk operations
 */
function addBulkOperationFeedback() {
    const bulkActionButtons = document.querySelectorAll('.bulk-actions .btn');
    
    bulkActionButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Add loading state
            const originalText = this.innerHTML;
            this.disabled = true;
            this.innerHTML = '<span class="material-symbols-outlined">hourglass_empty</span> Se procesează...';
            
            // Re-enable after a short delay (will be overridden by page reload)
            setTimeout(() => {
                this.disabled = false;
                this.innerHTML = originalText;
            }, 2000);
        });
    });
}

/**
 * Auto-hide alerts
 */
function autoHideAlerts() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        if (alert.classList.contains('alert-success')) {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    alert.remove();
                }, 300);
            }, 4000);
        }
    });
}

/**
 * Keyboard Shortcuts
 */
document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + N for new product
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        openCreateModal();
    }
    
    // Ctrl/Cmd + F for search
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        const searchInput = document.querySelector('input[name="search"]');
        searchInput?.focus();
    }
    
    // Ctrl/Cmd + A for select all (when not in input field)
    if ((e.ctrlKey || e.metaKey) && e.key === 'a' && !e.target.matches('input, textarea')) {
        e.preventDefault();
        const selectAllCheckbox = document.getElementById('selectAll');
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = true;
            toggleSelectAll();
        }
    }
    
    // Escape to clear selections or close modals
    if (e.key === 'Escape') {
        const bulkActionsBar = document.getElementById('bulkActionsBar');
        if (bulkActionsBar && bulkActionsBar.style.display !== 'none') {
            clearAllSelections();
        } else {
            closeAllModals();
        }
    }
    
    // Delete key for bulk delete (when products are selected)
    if (e.key === 'Delete') {
        const selectedCheckboxes = document.querySelectorAll('.product-checkbox:checked');
        if (selectedCheckboxes.length > 0) {
            e.preventDefault();
            performBulkAction('delete');
        }
    }
});

/**
 * Utility Functions
 */
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <span class="material-symbols-outlined">
            ${type === 'success' ? 'check_circle' : type === 'error' ? 'error' : 'info'}
        </span>
        ${message}
    `;
    
    // Add compact notification styles
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: var(--container-background);
        color: var(--text-primary);
        padding: 0.75rem 1rem;
        border-radius: 6px;
        box-shadow: var(--base-shadow);
        border: 1px solid var(--border-color);
        z-index: 9999;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.85rem;
        max-width: 300px;
        transition: all 0.3s ease;
        transform: translateX(100%);
    `;
    
    if (type === 'error') {
        notification.style.borderColor = 'var(--danger-color)';
        notification.style.color = 'var(--danger-color)';
    }
    
    document.body.appendChild(notification);
    
    // Animate in
    requestAnimationFrame(() => {
        notification.style.transform = 'translateX(0)';
    });
    
    // Auto-remove
    setTimeout(() => {
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 3000);
}

/**
 * Excel Import Functionality
 * Add this to your existing products.js file
 */

let selectedFile = null;
let importedData = [];

/**
 * Import Modal Management
 */
function openImportModal() {
    resetImportModal();
    showModal(document.getElementById('importProductModal'));
}

function closeImportModal() {
    hideModal(document.getElementById('importProductModal'));
    resetImportModal();
}

function resetImportModal() {
    selectedFile = null;
    importedData = [];
    
    // Reset to step 1
    document.getElementById('step-upload').style.display = 'flex';
    document.getElementById('step-processing').style.display = 'none';
    document.getElementById('step-results').style.display = 'none';
    
    // Reset file input and info
    document.getElementById('excelFile').value = '';
    document.getElementById('fileInfo').style.display = 'none';
    
    // Reset progress
    document.getElementById('progressFill').style.width = '0%';
    document.getElementById('processingLogs').innerHTML = '';
    
    // Hide results button
    document.getElementById('viewProductsBtn').style.display = 'none';
}

/**
 * File Selection and Validation
 */
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('excelFile');
    const uploadArea = document.getElementById('uploadArea');
    
    // File input change handler
    fileInput.addEventListener('change', handleFileSelect);
    
    // Drag and drop functionality
    uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', function(e) {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            handleFileSelect();
        }
    });
});

function handleFileSelect() {
    const fileInput = document.getElementById('excelFile');
    const file = fileInput.files[0];
    
    if (!file) {
        document.getElementById('fileInfo').style.display = 'none';
        return;
    }
    
    // Validate file type
    const allowedTypes = [
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];
    
    const fileExtension = file.name.toLowerCase().split('.').pop();
    if (!['xls', 'xlsx'].includes(fileExtension)) {
        showNotification('Vă rog să selectați un fișier Excel (.xls sau .xlsx)', 'error');
        return;
    }
    
    selectedFile = file;
    
    // Show file info
    document.getElementById('fileName').textContent = file.name;
    document.getElementById('fileSize').textContent = formatFileSize(file.size);
    document.getElementById('fileInfo').style.display = 'flex';
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

/**
 * Start Processing
 */
async function startProcessing() {
    if (!selectedFile) {
        showNotification('Vă rog să selectați un fișier.', 'error');
        return;
    }
    
    // Switch to processing step
    document.getElementById('step-upload').style.display = 'none';
    document.getElementById('step-processing').style.display = 'flex';
    
    updateProgress(10, 'Citire fișier Excel...');
    
    try {
        // Read and parse Excel file
        const excelData = await parseExcelFile(selectedFile);
        updateProgress(30, 'Procesare date...');
        
        // Process and clean data
        const processedData = processExcelData(excelData);
        updateProgress(50, 'Validare produse...');
        
        // Send to backend for database insertion
        await importToDatabase(processedData);
        
    } catch (error) {
        console.error('Import error:', error);
        addLog('Eroare: ' + error.message, 'error');
        showNotification('Eroare la importul produselor: ' + error.message, 'error');
    }
}

/**
 * Parse Excel File
 */
async function parseExcelFile(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                // Use SheetJS to parse Excel
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, {type: 'array'});
                
                // Get first sheet
                const sheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[sheetName];
                
                // Convert to JSON with header row at index 9 (row 10)
                const jsonData = XLSX.utils.sheet_to_json(worksheet, {header: 1});
                
                addLog(`Fișier citit: ${jsonData.length} rânduri găsite`, 'success');
                resolve(jsonData);
            } catch (error) {
                reject(new Error('Eroare la citirea fișierului Excel: ' + error.message));
            }
        };
        reader.onerror = () => reject(new Error('Eroare la citirea fișierului'));
        reader.readAsArrayBuffer(file);
    });
}

/**
 * Process Excel Data
 */
function processExcelData(jsonData) {
    const processedProducts = new Map(); // Use Map to handle duplicates
    let validRows = 0;
    let skippedRows = 0;
    
    // Headers are at row 10 (index 9): ["Gestiune","Produs","Cod","U.M.","Stoc final","Cost unitar","Sold final"]
    const headers = jsonData[9];
    addLog(`Headers găsite: ${headers.join(', ')}`, 'info');
    
    // Process data starting from row 11 (index 10)
    for (let i = 10; i < jsonData.length; i++) {
        const row = jsonData[i];
        
        // Skip empty rows
        if (!row || row.length < 3 || !row[1] || !row[2]) {
            skippedRows++;
            continue;
        }
        
        try {
            const product = {
                gestiune: row[0] || 'Marfa',
                produs: row[1],
                cod: row[2],
                um: row[3] || 'bucata',
                stoc_final: parseInt(row[4]) || 0,
                cost_unitar: parseFloat(row[5]) || 0,
                sold_final: parseFloat(row[6]) || 0
            };
            
            // Clean product data
            const cleanProduct = cleanProductData(product);
            
            // Handle duplicates by summing quantities
            if (processedProducts.has(cleanProduct.sku)) {
                const existing = processedProducts.get(cleanProduct.sku);
                existing.quantity += cleanProduct.quantity;
                addLog(`Actualizat SKU duplicat ${cleanProduct.sku}: cantitate totală ${existing.quantity}`, 'warning');
            } else {
                processedProducts.set(cleanProduct.sku, cleanProduct);
                validRows++;
            }
            
        } catch (error) {
            addLog(`Eroare la rândul ${i + 1}: ${error.message}`, 'error');
            skippedRows++;
        }
    }
    
    const finalProducts = Array.from(processedProducts.values());
    addLog(`Procesare completă: ${finalProducts.length} produse unice, ${skippedRows} rânduri omise`, 'success');
    
    return finalProducts;
}

/**
 * Clean Product Data
 */
function cleanProductData(rawProduct) {
    // Extract clean product name (remove code prefix)
    let productName = rawProduct.produs.trim();
    const match = productName.match(/^[A-Z0-9\-\.]+\s*-\s*(.+)$/);
    if (match) {
        productName = match[1].trim();
    }
    
    // Map units
    const unitMap = {
        'bucata': 'pcs',
        'bucati': 'pcs',
        'litru': 'l',
        'litri': 'l',
        'kg': 'kg',
        'set': 'set'
    };
    
    const unit = unitMap[rawProduct.um.toLowerCase()] || rawProduct.um;
    
    return {
        name: productName,
        sku: rawProduct.cod.trim(),
        description: rawProduct.produs.trim(),
        price: rawProduct.cost_unitar,
        category: rawProduct.gestiune || 'Imported',
        unit: unit,
        quantity: rawProduct.stoc_final,
        status: 'active'
    };
}

/**
 * Import to Database
 */
async function importToDatabase(products) {
    updateProgress(60, 'Trimitere către server...');
    
    try {
        const response = await fetch('import_handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'import_products',
                products: products
            })
        });
        
        const result = await response.json();
        
        if (!response.ok) {
            throw new Error(result.message || 'Eroare de server');
        }
        
        updateProgress(100, 'Import finalizat!');
        
        // Show results
        setTimeout(() => {
            showResults(result);
        }, 1000);
        
    } catch (error) {
        throw new Error('Eroare la comunicarea cu serverul: ' + error.message);
    }
}

/**
 * Show Results
 */
function showResults(result) {
    // Switch to results step
    document.getElementById('step-processing').style.display = 'none';
    document.getElementById('step-results').style.display = 'flex';
    
    // Update stats
    document.getElementById('createdCount').textContent = result.created || 0;
    document.getElementById('updatedCount').textContent = result.updated || 0;
    document.getElementById('skippedCount').textContent = result.skipped || 0;
    document.getElementById('errorCount').textContent = result.errors?.length || 0;
    
    // Show errors if any
    if (result.errors && result.errors.length > 0) {
        const errorList = document.getElementById('errorList');
        errorList.innerHTML = '';
        result.errors.forEach(error => {
            const li = document.createElement('li');
            li.textContent = error;
            errorList.appendChild(li);
        });
        document.getElementById('importErrors').style.display = 'block';
    }
    
    // Show view products button
    document.getElementById('viewProductsBtn').style.display = 'inline-block';
    
    // Show success notification
    const totalProcessed = (result.created || 0) + (result.updated || 0);
    showNotification(`Import finalizat! ${totalProcessed} produse procesate cu succes.`, 'success');
}

/**
 * Progress and Logging
 */
function updateProgress(percentage, status) {
    document.getElementById('progressFill').style.width = percentage + '%';
    document.getElementById('processingStatus').textContent = status;
}

function addLog(message, type = 'info') {
    const logsContainer = document.getElementById('processingLogs');
    const logEntry = document.createElement('div');
    logEntry.className = `log-entry log-${type}`;
    logEntry.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
    logsContainer.appendChild(logEntry);
    logsContainer.scrollTop = logsContainer.scrollHeight;
}
/**
 * Synchronize stock using SmartBill API
 */
async function syncSmartBillStock() {
    const button = event.target.closest('button');
    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="material-symbols-outlined">sync</span> Sincronizare...';

    try {
        const response = await fetch('smartbill-sync.php?action=ajax', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'ajax_action=manual_sync&sync_type=product_sync'
        });
        const result = await response.json();
        if (result.success) {
            showNotification('Stoc actualizat: ' + result.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification('Eroare SmartBill: ' + result.message, 'error');
        }
    } catch (err) {
        showNotification('Eroare conexiune: ' + err.message, 'error');
    } finally {
        button.disabled = false;
        button.innerHTML = original;
    }
}
