/**
 * Complete Products Page JavaScript
 * Modal management, bulk operations, Excel import, and basic interactions
 */

// DOM Elements
const createModal = document.getElementById('createProductModal');
const editModal = document.getElementById('editProductModal');
const deleteModal = document.getElementById('deleteProductModal');
const assignLocationModal = document.getElementById('assignLocationModal');

// Global variables
let selectedFile = null;
let importInProgress = false;
const API_KEY = window.APP_CONFIG && window.APP_CONFIG.apiKey ? window.APP_CONFIG.apiKey : '';

/**
 * Initialize page functionality
 */
document.addEventListener('DOMContentLoaded', function() {
    initializeModals();
    initializeSearch();
    autoHideAlerts();
    initializeBulkOperations();
    initializeExcelImport();
    initializeProductModals();
});

/**
 * Modal Management
 */
function initializeModals() {
    // Close modals when clicking outside
    [createModal, editModal, deleteModal, assignLocationModal].forEach(modal => {
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
    [createModal, editModal, deleteModal, assignLocationModal].forEach(modal => {
        if (modal) {
            hideModal(modal);
        }
    });
    
    // Also close import modal if open
    const importModal = document.getElementById('importProductModal');
    if (importModal) {
        closeImportModal();
    }
}

function openAssignLocationModal(productId) {
    const productInput = document.getElementById('assign-product-id');
    const locSelect = document.getElementById('assign-location');
    const levelSelect = document.getElementById('assign-shelf-level');
    const subContainer = document.getElementById('assign-subdivision-container');
    const subSelect = document.getElementById('assign-subdivision-number');

    if (productInput) productInput.value = productId;
    if (locSelect) locSelect.value = '';
    if (levelSelect) levelSelect.innerHTML = '<option value="">--</option>';
    if (subContainer) subContainer.style.display = 'none';
    if (subSelect) subSelect.innerHTML = '<option value="">--</option>';

    showModal(assignLocationModal);
}

function closeAssignLocationModal() {
    hideModal(assignLocationModal);
}

async function loadAssignLocationLevels(locationId) {
    const levelSelect = document.getElementById('assign-shelf-level');
    const subContainer = document.getElementById('assign-subdivision-container');
    const subSelect = document.getElementById('assign-subdivision-number');

    if (levelSelect) {
        levelSelect.innerHTML = '<option value="">--</option>';
    }
    if (subContainer) subContainer.style.display = 'none';
    if (subSelect) subSelect.innerHTML = '<option value="">--</option>';

    if (!locationId) return;

    try {
        const params = new URLSearchParams({ id: locationId });
        if (API_KEY) params.append('api_key', API_KEY);
        const resp = await fetch(`api/location_info.php?${params.toString()}`, { credentials: 'same-origin' });
        const data = await resp.json();
        if (resp.ok && data.levels) {
            data.levels.forEach(l => {
                const opt = document.createElement('option');
                opt.value = l.number;
                let label = l.name;
                if (!(l.subdivision_count && l.subdivision_count > 0)) {
                    if ((l.current_stock > 0) || l.dedicated_product_id) {
                        const info = l.capacity ? `${l.current_stock}/${l.capacity} articole - ${l.occupancy_percentage}%` : `${l.current_stock} articole`;
                        const name = l.product_name ? l.product_name + ' - ' : '';
                        label += ` (${name}${info})`;
                    }
                }
                opt.textContent = label;
                opt.dataset.subdivisionCount = l.subdivision_count;
                levelSelect.appendChild(opt);
            });
        }
    } catch (e) { console.error(e); }

}

async function updateAssignSubdivisionOptions() {
    const levelSelect = document.getElementById('assign-shelf-level');
    const locId = document.getElementById('assign-location')?.value;
    const productId = document.getElementById('assign-product-id')?.value;
    const subContainer = document.getElementById('assign-subdivision-container');
    const subSelect = document.getElementById('assign-subdivision-number');
    const levelNumber = levelSelect ? levelSelect.value : '';

    if (subSelect) subSelect.innerHTML = '<option value="">--</option>';

    if (!locId || !levelNumber) { if (subContainer) subContainer.style.display = 'none'; return; }

    try {
        const params = new URLSearchParams({ location_id: locId, level: levelNumber, product_id: productId });
        if (API_KEY) params.append('api_key', API_KEY);
        const resp = await fetch(`api/subdivision_info.php?${params.toString()}`, { credentials: 'same-origin' });
        const data = await resp.json();
        if (resp.ok && Array.isArray(data.subdivisions) && data.subdivisions.length) {
            data.subdivisions.forEach(sd => {
                const opt = document.createElement('option');
                const info = sd.capacity ? `${sd.current_stock}/${sd.capacity} articole - ${sd.occupancy_percentage}%` : `${sd.current_stock} articole`;
                const name = sd.product_name ? sd.product_name + ' - ' : '';
                const prefix = sd.recommended ? '⭐ ' : (sd.compatible ? '' : '❌ ');
                opt.value = sd.subdivision_number;
                opt.textContent = `${prefix}Subdiviziunea ${sd.subdivision_number} (${name}${info})`;
                if (!sd.compatible) opt.disabled = true;
                subSelect.appendChild(opt);
            });
            if (subContainer) subContainer.style.display = 'block';
        } else {
            if (subContainer) subContainer.style.display = 'none';
        }
    } catch (e) {
        console.error(e);
        if (subContainer) subContainer.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const locSelect = document.getElementById('assign-location');
    const levelSelect = document.getElementById('assign-shelf-level');

    if (locSelect) {
        locSelect.addEventListener('change', () => loadAssignLocationLevels(locSelect.value));
    }
    if (levelSelect) {
        levelSelect.addEventListener('change', updateAssignSubdivisionOptions);
    }
});

function assignLocationForProduct(productId) {
    openAssignLocationModal(productId);
}

window.assignLocationForProduct = assignLocationForProduct;
window.closeAssignLocationModal = closeAssignLocationModal;
window.updateAssignSubdivisionOptions = updateAssignSubdivisionOptions;
window.loadAssignLocationLevels = loadAssignLocationLevels;

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
        document.getElementById('edit-unit').value = product.unit_of_measure || 'pcs';
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
 * Show category dropdown for bulk category change
 */
function showCategoryBulk() {
    const select = document.getElementById('bulkCategorySelect');
    const applyBtn = document.getElementById('applyCategoryBtn');
    if (select.style.display === 'none') {
        select.style.display = 'inline-block';
        applyBtn.style.display = 'inline-block';
    } else {
        select.style.display = 'none';
        applyBtn.style.display = 'none';
    }
}

/**
 * Apply bulk category change to selected products
 */
function applyBulkCategory() {
    const selectedCheckboxes = document.querySelectorAll('.product-checkbox:checked');
    const selectedCount = selectedCheckboxes.length;
    if (selectedCount === 0) {
        showNotification('Niciun produs selectat.', 'error');
        return;
    }
    const categorySelect = document.getElementById('bulkCategorySelect');
    const newCategory = categorySelect.value;
    if (!newCategory) {
        showNotification('Selectează o categorie.', 'error');
        return;
    }
    if (confirm(`Ești sigur că vrei să schimbi categoria pentru ${selectedCount} produs${selectedCount > 1 ? 'e' : ''}?`)) {
        document.getElementById('bulkActionInput').value = 'change_category';
        document.getElementById('bulkCategoryInput').value = newCategory;
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
 * ENHANCED EXCEL IMPORT FUNCTIONALITY
 */

/**
 * Initialize Excel Import Functionality
 */
function initializeExcelImport() {
    const fileInput = document.getElementById('excelFile');
    const uploadArea = document.getElementById('uploadArea');
    
    if (!fileInput || !uploadArea) {
        console.warn('Excel import elements not found');
        return;
    }
    
    // File input change handler
    fileInput.addEventListener('change', handleFileSelect);
    
    // Drag and drop functionality
    uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (!uploadArea.contains(e.relatedTarget)) {
            uploadArea.classList.remove('dragover');
        }
    });
    
    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            handleDroppedFile(files[0]);
        }
    });
    
    // Click to upload
    uploadArea.addEventListener('click', function(e) {
        if (e.target === uploadArea || e.target.closest('.upload-content')) {
            fileInput.click();
        }
    });
}

/**
 * Initialize product modals (existing functionality)
 */
function initializeProductModals() {
    // Product modal functionality
    const addProductBtn = document.getElementById('addProductBtn');
    const productModal = document.getElementById('productModal');
    const closeModalBtns = document.querySelectorAll('.modal-close');
    
    if (addProductBtn) {
        addProductBtn.addEventListener('click', () => openProductModal());
    }
    
    closeModalBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
            const modal = e.target.closest('.modal');
            if (modal) modal.style.display = 'none';
        });
    });
    
    // Close modal when clicking outside
    window.addEventListener('click', (e) => {
        if (e.target.classList.contains('modal')) {
            e.target.style.display = 'none';
        }
    });
}

/**
 * Handle file selection from input
 */
function handleFileSelect() {
    const fileInput = document.getElementById('excelFile');
    const file = fileInput.files[0];
    
    if (!file) {
        hideFileInfo();
        return;
    }
    
    validateAndShowFile(file);
}

/**
 * Handle dropped file
 */
function handleDroppedFile(file) {
    const fileInput = document.getElementById('excelFile');
    
    // Create a new FileList-like object
    const dt = new DataTransfer();
    dt.items.add(file);
    fileInput.files = dt.files;
    
    validateAndShowFile(file);
}

/**
 * Validate and display file information
 */
function validateAndShowFile(file) {
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
    
    // Validate file size (max 10MB)
    const maxSize = 10 * 1024 * 1024; // 10MB
    if (file.size > maxSize) {
        showNotification('Fișierul este prea mare. Mărimea maximă permisă este 10MB.', 'error');
        return;
    }
    
    selectedFile = file;
    showFileInfo(file);
    
    // Enable the process button
    const processBtn = document.getElementById('processBtn');
    if (processBtn) {
        processBtn.disabled = false;
        processBtn.textContent = 'Procesează Fișierul';
    }
}

/**
 * Show file information
 */
function showFileInfo(file) {
    const fileInfo = document.getElementById('fileInfo');
    const fileName = document.getElementById('fileName');
    const fileSize = document.getElementById('fileSize');
    
    if (fileName) fileName.textContent = file.name;
    if (fileSize) fileSize.textContent = formatFileSize(file.size);
    if (fileInfo) fileInfo.style.display = 'flex';
}

/**
 * Hide file information
 */
function hideFileInfo() {
    const fileInfo = document.getElementById('fileInfo');
    if (fileInfo) fileInfo.style.display = 'none';
    
    const processBtn = document.getElementById('processBtn');
    if (processBtn) {
        processBtn.disabled = true;
        processBtn.textContent = 'Selectează un fișier';
    }
    
    selectedFile = null;
}

/**
 * Format file size for display
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

/**
 * Start processing the Excel file
 */
async function startProcessing() {
    if (!selectedFile) {
        showNotification('Vă rog să selectați un fișier Excel.', 'error');
        return;
    }
    
    if (importInProgress) {
        showNotification('Un import este deja în curs. Vă rog să așteptați.', 'warning');
        return;
    }
    
    importInProgress = true;
    
    try {
        // Show processing state
        showProcessingState();
        
        // Prepare form data
        const formData = new FormData();
        formData.append('excel_file', selectedFile);
        formData.append('sync_smartbill', document.getElementById('syncSmartBill')?.checked || false);
        formData.append('overwrite_existing', document.getElementById('overwriteExisting')?.checked || false);
        
        // Upload and process
        const response = await fetch('api/excel_import.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showImportResults(result);
        } else {
            showImportError(result);
        }
        
    } catch (error) {
        console.error('Import error:', error);
        showNotification('Eroare la procesarea fișierului: ' + error.message, 'error');
        hideProcessingState();
    } finally {
        importInProgress = false;
    }
}

/**
 * Show processing state
 */
function showProcessingState() {
    const processBtn = document.getElementById('processBtn');
    const progressBar = document.getElementById('progressBar');
    const progressContainer = document.getElementById('progressContainer');
    
    if (processBtn) {
        processBtn.disabled = true;
        processBtn.innerHTML = '<span class="material-symbols-outlined spinning">refresh</span> Procesez...';
    }
    
    if (progressContainer) {
        progressContainer.style.display = 'block';
    }
    
    if (progressBar) {
        progressBar.style.width = '50%';
    }
}

/**
 * Hide processing state
 */
function hideProcessingState() {
    const processBtn = document.getElementById('processBtn');
    const progressContainer = document.getElementById('progressContainer');
    
    if (processBtn) {
        processBtn.disabled = false;
        processBtn.innerHTML = 'Procesează Fișierul';
    }
    
    if (progressContainer) {
        progressContainer.style.display = 'none';
    }
}

/**
 * Show import results
 */
function showImportResults(result) {
    const resultsDiv = document.getElementById('importResults');
    const progressBar = document.getElementById('progressBar');
    
    if (progressBar) {
        progressBar.style.width = '100%';
    }
    
    let html = `
        <div class="import-summary ${result.success ? 'success' : 'error'}">
            <h4><span class="material-symbols-outlined">${result.success ? 'check_circle' : 'error'}</span> 
                ${result.success ? 'Import Finalizat' : 'Import Eșuat'}</h4>
            <p>${result.message}</p>
            
            <div class="import-stats">
                <div class="stat-item">
                    <span class="stat-number">${result.processed || 0}</span>
                    <span class="stat-label">Procesate</span>
                </div>
                <div class="stat-item success">
                    <span class="stat-number">${result.imported || 0}</span>
                    <span class="stat-label">Importate</span>
                </div>
                <div class="stat-item warning">
                    <span class="stat-number">${result.updated || 0}</span>
                    <span class="stat-label">Actualizate</span>
                </div>
                <div class="stat-item info">
                    <span class="stat-number">${result.skipped || 0}</span>
                    <span class="stat-label">Omise</span>
                </div>
                ${result.smartbill_synced ? `
                <div class="stat-item smartbill">
                    <span class="stat-number">${result.smartbill_synced}</span>
                    <span class="stat-label">SmartBill Sync</span>
                </div>
                ` : ''}
            </div>
        </div>
    `;
    
    // Show errors if any
    if (result.errors && result.errors.length > 0) {
        html += `
            <div class="import-errors">
                <h5><span class="material-symbols-outlined">warning</span> Erori (${result.errors.length})</h5>
                <ul>
                    ${result.errors.slice(0, 10).map(error => `<li>${error}</li>`).join('')}
                    ${result.errors.length > 10 ? `<li>... și încă ${result.errors.length - 10} erori</li>` : ''}
                </ul>
            </div>
        `;
    }
    
    // Show warnings if any
    if (result.warnings && result.warnings.length > 0) {
        html += `
            <div class="import-warnings">
                <h5><span class="material-symbols-outlined">info</span> Avertismente (${result.warnings.length})</h5>
                <ul>
                    ${result.warnings.slice(0, 5).map(warning => `<li>${warning}</li>`).join('')}
                </ul>
            </div>
        `;
    }
    
    if (resultsDiv) {
        resultsDiv.innerHTML = html;
        resultsDiv.style.display = 'block';
    }
    
    hideProcessingState();
    showNotification(result.message, result.success ? 'success' : 'error');
}

/**
 * Show import error
 */
function showImportError(result) {
    const message = result.message || 'Eroare necunoscută la procesarea fișierului';
    showNotification(message, 'error');
    
    const resultsDiv = document.getElementById('importResults');
    if (resultsDiv) {
        resultsDiv.innerHTML = `
            <div class="import-summary error">
                <h4><span class="material-symbols-outlined">error</span> Import Eșuat</h4>
                <p>${message}</p>
                ${result.errors && result.errors.length > 0 ? `
                    <div class="import-errors">
                        <h5>Detalii erori:</h5>
                        <ul>
                            ${result.errors.map(error => `<li>${error}</li>`).join('')}
                        </ul>
                    </div>
                ` : ''}
            </div>
        `;
        resultsDiv.style.display = 'block';
    }
    
    hideProcessingState();
}

/**
 * Reset import form
 */
function resetImportForm() {
    selectedFile = null;
    const fileInput = document.getElementById('excelFile');
    if (fileInput) fileInput.value = '';
    
    hideFileInfo();
    
    const resultsDiv = document.getElementById('importResults');
    if (resultsDiv) {
        resultsDiv.style.display = 'none';
    }
    
    const progressContainer = document.getElementById('progressContainer');
    if (progressContainer) {
        progressContainer.style.display = 'none';
    }
}

/**
 * Open import modal
 */
function openImportModal() {
    const modal = document.getElementById('importProductModal');
    if (modal) {
        modal.style.display = 'flex';
        resetImportForm();
    }
}

/**
 * Close import modal
 */
function closeImportModal() {
    const modal = document.getElementById('importProductModal');
    if (modal) {
        modal.style.display = 'none';
        resetImportForm();
        if (typeof refreshProductsTable === 'function') {
            refreshProductsTable();
        }
    }
}

/**
 * Refresh products table (if function exists)
 */
function refreshProductsTable() {
    // Reload the page to show updated products
    setTimeout(() => {
        window.location.reload();
    }, 2000);
}

/**
 * Download sample Excel template
 */
function downloadSampleTemplate() {
    // Create sample data
    const sampleData = [
        ['SKU', 'Nume Produs', 'Descriere', 'Categorie', 'Cantitate', 'Pret', 'Stoc Minim', 'Unitate Masura', 'Furnizor'],
        ['PROD001', 'Produs Exemplu 1', 'Descriere produs 1', 'Categoria A', '100', '25.50', '10', 'bucata', 'Furnizor ABC'],
        ['PROD002', 'Produs Exemplu 2', 'Descriere produs 2', 'Categoria B', '50', '15.00', '5', 'kg', 'Furnizor XYZ'],
        ['PROD003', 'Produs Exemplu 3', 'Descriere produs 3', 'Categoria A', '200', '8.75', '20', 'litru', 'Furnizor DEF']
    ];
    
    // Convert to CSV format
    const csvContent = sampleData.map(row => 
        row.map(cell => `"${cell}"`).join(',')
    ).join('\n');
    
    // Create and download file
    const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'template_produse.csv';
    link.click();
}

/**
 * Utility Functions
 */
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
        <span class="material-symbols-outlined">
            ${type === 'success' ? 'check_circle' : 
              type === 'error' ? 'error' : 
              type === 'warning' ? 'warning' : 'info'}
        </span>
        <span>${message}</span>
        <button onclick="this.parentElement.remove()">
            <span class="material-symbols-outlined">close</span>
        </button>
    `;
    
    // Add notification styles
    notification.style.cssText = `
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        margin: 8px 0;
        border-radius: 6px;
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
        min-width: 300px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        animation: slideIn 0.3s ease-out;
        font-size: 14px;
    `;
    
    // Apply type-specific styles
    if (type === 'success') {
        notification.style.background = '#d4edda';
        notification.style.color = '#155724';
        notification.style.border = '1px solid #c3e6cb';
    } else if (type === 'error') {
        notification.style.background = '#f8d7da';
        notification.style.color = '#721c24';
        notification.style.border = '1px solid #f5c6cb';
    } else if (type === 'warning') {
        notification.style.background = '#fff3cd';
        notification.style.color = '#856404';
        notification.style.border = '1px solid #ffeaa7';
    } else {
        notification.style.background = '#d1ecf1';
        notification.style.color = '#0c5460';
        notification.style.border = '1px solid #bee5eb';
    }
    
    // Style the close button
    const closeButton = notification.querySelector('button');
    if (closeButton) {
        closeButton.style.cssText = `
            background: none;
            border: none;
            cursor: pointer;
            padding: 2px;
            margin-left: auto;
            color: inherit;
        `;
    }
    
    // Add to page
    const container = document.getElementById('notifications') || document.body;
    container.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.style.animation = 'slideOut 0.3s ease-in';
            setTimeout(() => notification.remove(), 300);
        }
    }, 5000);
}

/**
 * LABEL PRINTING FUNCTIONALITY
 */

/**
 * Send request to generate and print labels for a product
 */
async function chooseLabelPrinter() {
    try {
        const resp = await fetch('api/printer_management.php?path=printers');
        const printers = await resp.json();
        const labels = printers.filter(p => p.printer_type === 'label' && p.is_active);
        if (labels.length === 0) {
            showNotification('Nicio imprimantă de etichete disponibilă', 'error');
            return null;
        }
        if (labels.length === 1) {
            return labels[0].network_identifier;
        }
        const choice = prompt('Selectează imprimanta:\n' + labels.map((p,i) => `${i+1}: ${p.name}`).join('\n'), '1');
        if (choice === null) return null;
        const index = parseInt(choice, 10) - 1;
        if (isNaN(index) || index < 0 || index >= labels.length) return null;
        return labels[index].network_identifier;
    } catch (err) {
        console.error('Printer fetch error', err);
        showNotification('Eroare la încărcarea imprimantelor', 'error');
        return null;
    }
}

async function printLabels(productId, productName) {
    const qtyInput = prompt(`Introduceți numărul de etichete pentru ${productName}:`, '1');
    if (qtyInput === null) {
        return;
    }
    const quantity = parseInt(qtyInput, 10);
    if (isNaN(quantity) || quantity <= 0) {
        showNotification('Cantitate invalidă', 'error');
        return;
    }

    if (!confirm(`Printează ${quantity} etichete pentru ${productName}?`)) {
        return;
    }

    const button = event.target.closest('button');
    const original = button ? button.innerHTML : null;
    if (button) {
        button.disabled = true;
        button.innerHTML = '<span class="material-symbols-outlined spinning">hourglass_empty</span>';
    }

    const printerName = await chooseLabelPrinter();
    if (!printerName) {
        if (button) {
            button.disabled = false;
            button.innerHTML = original;
        }
        return;
    }

    const formData = new FormData();
    formData.append('product_id', productId);
    formData.append('quantity', quantity);
    formData.append('printer', printerName);

    fetch('api/labels/print.php', {
        method: 'POST',
        body: formData
    })
        .then(resp => resp.json())
        .then(data => {
            if (data.status === 'success') {
                showNotification('Etichetele au fost trimise la imprimantă', 'success');
            } else {
                showNotification('Eroare: ' + data.message, 'error');
            }
        })
        .catch(err => {
            showNotification('Eroare la conectarea serverului: ' + err.message, 'error');
        })
        .finally(() => {
            if (button) {
                button.disabled = false;
                button.innerHTML = original;
            }
        });
}

/**
 * SMARTBILL SYNC FUNCTIONALITY
 */

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

/**
 * Seller Search Functionality for Products
 * Handles real-time search and selection of sellers
 */

// Global variables for seller search
let sellerSearchTimeouts = {};
let currentSellerRequests = {};
let selectedSellers = {};

/**
 * Initialize seller search functionality
 */
function initializeSellerSearch() {
    // Initialize for both create and edit modals
    initializeSellerSearchForModal('create');
    initializeSellerSearchForModal('edit');
    
    // Close search results when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.seller-search-container')) {
            hideAllSellerResults();
        }
    });
}

/**
 * Initialize seller search for a specific modal
 * @param {string} modalType - 'create' or 'edit'
 */
function initializeSellerSearchForModal(modalType) {
    const searchInput = document.getElementById(`${modalType}-seller-search`);
    const resultsContainer = document.getElementById(`${modalType}-seller-results`);
    
    if (!searchInput || !resultsContainer) {
        return;
    }
    
    // Input event listener with debouncing
    searchInput.addEventListener('input', function(e) {
        const query = e.target.value.trim();
        
        // Clear previous timeout
        if (sellerSearchTimeouts[modalType]) {
            clearTimeout(sellerSearchTimeouts[modalType]);
        }
        
        // Hide results if query is too short
        if (query.length < 2) {
            hideSellerResults(modalType);
            return;
        }
        
        // Debounce the search
        sellerSearchTimeouts[modalType] = setTimeout(() => {
            searchSellers(query, modalType);
        }, 300);
    });
    
    // Focus event - show results if we have a query
    searchInput.addEventListener('focus', function(e) {
        const query = e.target.value.trim();
        if (query.length >= 2) {
            searchSellers(query, modalType);
        }
    });
    
    // Keyboard navigation
    searchInput.addEventListener('keydown', function(e) {
        handleSellerSearchKeydown(e, modalType);
    });
}

/**
 * Search for sellers via API
 * @param {string} query - Search query
 * @param {string} modalType - Modal type (create/edit)
 */
async function searchSellers(query, modalType) {
    const resultsContainer = document.getElementById(`${modalType}-seller-results`);
    
    if (!resultsContainer) {
        return;
    }
    
    // Cancel previous request if exists
    if (currentSellerRequests[modalType]) {
        currentSellerRequests[modalType].abort();
    }
    
    // Show loading state
    showSellerResults(modalType);
    resultsContainer.innerHTML = '<div class="seller-search-loading">Se caută...</div>';
    
    try {
        // Create new AbortController for this request
        const controller = new AbortController();
        currentSellerRequests[modalType] = controller;
        
        const response = await fetch(`api/seller_search.php?q=${encodeURIComponent(query)}&limit=10`, {
            signal: controller.signal
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        // Clear the request reference
        delete currentSellerRequests[modalType];
        
        if (data.success) {
            displaySellerResults(data.sellers, modalType);
        } else {
            throw new Error(data.error || 'Search failed');
        }
        
    } catch (error) {
        // Don't show error if request was aborted (user typed something else)
        if (error.name !== 'AbortError') {
            console.error('Seller search error:', error);
            resultsContainer.innerHTML = '<div class="no-results">Eroare la căutare. Încercați din nou.</div>';
        }
        
        // Clear the request reference
        delete currentSellerRequests[modalType];
    }
}

/**
 * Display seller search results
 * @param {Array} sellers - Array of seller objects
 * @param {string} modalType - Modal type
 */
function displaySellerResults(sellers, modalType) {
    const resultsContainer = document.getElementById(`${modalType}-seller-results`);
    
    if (!resultsContainer) {
        return;
    }
    
    if (sellers.length === 0) {
        resultsContainer.innerHTML = '<div class="no-results">Nu s-au găsit furnizori</div>';
        return;
    }
    
    let html = '';
    sellers.forEach((seller, index) => {
        html += `
            <div class="seller-search-item" 
                 data-seller-id="${seller.id}" 
                 data-index="${index}"
                 onclick="selectSeller('${modalType}', ${seller.id}, '${escapeHtml(seller.name)}', '${escapeHtml(seller.contact_person || '')}', '${escapeHtml(seller.email || '')}', '${escapeHtml(seller.phone || '')}')">
                <span class="seller-item-name">${escapeHtml(seller.name)}</span>
                <div class="seller-item-details">
                    ${seller.contact_person ? `<span class="seller-item-contact">${escapeHtml(seller.contact_person)}</span>` : ''}
                    ${seller.city ? `<span class="seller-item-city">${escapeHtml(seller.city)}</span>` : ''}
                    ${seller.phone ? `<span class="seller-item-phone">${escapeHtml(seller.phone)}</span>` : ''}
                </div>
            </div>
        `;
    });
    
    resultsContainer.innerHTML = html;
}

/**
 * Select a seller from search results
 * @param {string} modalType - Modal type
 * @param {number} sellerId - Seller ID
 * @param {string} sellerName - Seller name
 * @param {string} contactPerson - Contact person
 * @param {string} email - Email
 * @param {string} phone - Phone
 */
function selectSeller(modalType, sellerId, sellerName, contactPerson, email, phone) {
    // Set hidden field value
    const sellerIdInput = document.getElementById(`${modalType}-seller-id`);
    const searchInput = document.getElementById(`${modalType}-seller-search`);
    const selectedContainer = document.getElementById(`${modalType}-selected-seller`);
    const selectedNameElement = selectedContainer?.querySelector('.selected-seller-name');
    const selectedContactElement = selectedContainer?.querySelector('.selected-seller-contact');
    
    if (sellerIdInput) {
        sellerIdInput.value = sellerId;
    }
    
    // Hide search input and show selected seller
    if (searchInput) {
        searchInput.style.display = 'none';
        searchInput.value = sellerName;
    }
    
    if (selectedContainer) {
        selectedContainer.style.display = 'flex';
    }
    
    if (selectedNameElement) {
        selectedNameElement.textContent = sellerName;
    }
    
    if (selectedContactElement) {
        const contactText = contactPerson || email || phone || '';
        selectedContactElement.textContent = contactText;
        selectedContactElement.style.display = contactText ? 'block' : 'none';
    }
    
    // Store selected seller data
    selectedSellers[modalType] = {
        id: sellerId,
        name: sellerName,
        contact_person: contactPerson,
        email: email,
        phone: phone
    };
    
    // Hide results
    hideSellerResults(modalType);
}

/**
 * Clear selected seller
 * @param {string} modalType - Modal type
 */
function clearSelectedSeller(modalType) {
    const sellerIdInput = document.getElementById(`${modalType}-seller-id`);
    const searchInput = document.getElementById(`${modalType}-seller-search`);
    const selectedContainer = document.getElementById(`${modalType}-selected-seller`);
    
    if (sellerIdInput) {
        sellerIdInput.value = '';
    }
    
    if (searchInput) {
        searchInput.style.display = 'block';
        searchInput.value = '';
        searchInput.focus();
    }
    
    if (selectedContainer) {
        selectedContainer.style.display = 'none';
    }
    
    // Clear stored data
    delete selectedSellers[modalType];
    
    // Hide results
    hideSellerResults(modalType);
}

/**
 * Handle keyboard navigation in seller search
 * @param {KeyboardEvent} e - Keyboard event
 * @param {string} modalType - Modal type
 */
function handleSellerSearchKeydown(e, modalType) {
    const resultsContainer = document.getElementById(`${modalType}-seller-results`);
    
    if (!resultsContainer || !resultsContainer.classList.contains('show')) {
        return;
    }
    
    const items = resultsContainer.querySelectorAll('.seller-search-item');
    const highlighted = resultsContainer.querySelector('.seller-search-item.highlighted');
    let currentIndex = highlighted ? parseInt(highlighted.dataset.index) : -1;
    
    switch (e.key) {
        case 'ArrowDown':
            e.preventDefault();
            currentIndex = Math.min(currentIndex + 1, items.length - 1);
            highlightSellerItem(modalType, currentIndex);
            break;
            
        case 'ArrowUp':
            e.preventDefault();
            currentIndex = Math.max(currentIndex - 1, 0);
            highlightSellerItem(modalType, currentIndex);
            break;
            
        case 'Enter':
            e.preventDefault();
            if (highlighted) {
                highlighted.click();
            }
            break;
            
        case 'Escape':
            e.preventDefault();
            hideSellerResults(modalType);
            break;
    }
}

/**
 * Highlight a seller search item
 * @param {string} modalType - Modal type
 * @param {number} index - Item index to highlight
 */
function highlightSellerItem(modalType, index) {
    const resultsContainer = document.getElementById(`${modalType}-seller-results`);
    const items = resultsContainer?.querySelectorAll('.seller-search-item');
    
    if (!items) return;
    
    // Remove existing highlights
    items.forEach(item => item.classList.remove('highlighted'));
    
    // Add highlight to specified item
    if (items[index]) {
        items[index].classList.add('highlighted');
        items[index].scrollIntoView({ block: 'nearest' });
    }
}

/**
 * Show seller search results
 * @param {string} modalType - Modal type
 */
function showSellerResults(modalType) {
    const resultsContainer = document.getElementById(`${modalType}-seller-results`);
    if (resultsContainer) {
        resultsContainer.classList.add('show');
    }
}

/**
 * Hide seller search results
 * @param {string} modalType - Modal type
 */
function hideSellerResults(modalType) {
    const resultsContainer = document.getElementById(`${modalType}-seller-results`);
    if (resultsContainer) {
        resultsContainer.classList.remove('show');
    }
}

/**
 * Hide all seller search results
 */
function hideAllSellerResults() {
    hideSellerResults('create');
    hideSellerResults('edit');
}

/**
 * Reset seller search for modal
 * @param {string} modalType - Modal type
 */
function resetSellerSearch(modalType) {
    // Clear inputs
    const sellerIdInput = document.getElementById(`${modalType}-seller-id`);
    const searchInput = document.getElementById(`${modalType}-seller-search`);
    const selectedContainer = document.getElementById(`${modalType}-selected-seller`);
    
    if (sellerIdInput) {
        sellerIdInput.value = '';
    }
    
    if (searchInput) {
        searchInput.style.display = 'block';
        searchInput.value = '';
    }
    
    if (selectedContainer) {
        selectedContainer.style.display = 'none';
    }
    
    // Clear stored data
    delete selectedSellers[modalType];
    
    // Hide results
    hideSellerResults(modalType);
    
    // Clear timeouts
    if (sellerSearchTimeouts[modalType]) {
        clearTimeout(sellerSearchTimeouts[modalType]);
        delete sellerSearchTimeouts[modalType];
    }
    
    // Cancel pending requests
    if (currentSellerRequests[modalType]) {
        currentSellerRequests[modalType].abort();
        delete currentSellerRequests[modalType];
    }
}

/**
 * Suggest seller for product (SmartBill integration)
 * @param {number} productId - Product ID
 */
async function suggestSeller(productId) {
    try {
        showNotification('Se caută furnizori sugerați...', 'info');
        
        const response = await fetch(`api/suggest_seller.php?product_id=${productId}`);
        const data = await response.json();
        
        if (data.success && data.suggestions.length > 0) {
            const suggestion = data.suggestions[0];
            const confirmMessage = `Am găsit un furnizor potrivit pentru acest produs:\n\n` +
                                 `Furnizor: ${suggestion.name}\n` +
                                 `Contact: ${suggestion.contact_person || 'N/A'}\n\n` +
                                 `Doriți să atribuiți acest furnizor produsului?`;
            
            if (confirm(confirmMessage)) {
                // Update product with suggested seller
                const updateResponse = await fetch('api/update_product_seller.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        product_id: productId,
                        seller_id: suggestion.id
                    })
                });
                
                const updateData = await updateResponse.json();
                
                if (updateData.success) {
                    showNotification('Furnizor atribuit cu succes!', 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showNotification('Eroare la atribuirea furnizorului', 'error');
                }
            }
        } else {
            showNotification('Nu s-au găsit furnizori sugerați pentru acest produs', 'warning');
        }
        
    } catch (error) {
        console.error('Suggest seller error:', error);
        showNotification('Eroare la căutarea furnizorilor sugerați', 'error');
    }
}

/**
 * Escape HTML to prevent XSS
 * @param {string} text - Text to escape
 * @return {string} Escaped text
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Enhanced populateEditForm function with seller support
 * This replaces the original function completely to avoid recursion
 * @param {object} productData - Product data
 */
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
        document.getElementById('edit-unit').value = product.unit_of_measure || 'pcs';
        document.getElementById('edit-status').checked = (product.status == undefined ? 1 : product.status) == 1;
        
        // Handle seller data
        const sellerId = product.seller_id;
        const sellerName = product.seller_name;
        const sellerContact = product.seller_contact;
        
        if (sellerId && sellerName) {
            // Select the seller
            selectSeller('edit', sellerId, sellerName, sellerContact || '', '', '');
        } else {
            // Clear seller selection
            clearSelectedSeller('edit');
        }
        
    } catch (error) {
        console.error('Error populating edit form:', error);
        showNotification('Eroare la încărcarea datelor produsului.', 'error');
        closeEditModal();
    }
}

// Enhanced modal functions with seller search reset
function resetCreateForm() {
    const form = document.getElementById('createProductModal')?.querySelector('form');
    if (form) {
        form.reset();
        // Set defaults
        document.getElementById('create-unit').value = 'pcs';
        document.getElementById('create-status').checked = true;
    }
    
    // Reset seller search
    resetSellerSearch('create');
}

function resetEditForm() {
    const form = document.getElementById('editProductModal')?.querySelector('form');
    if (form) {
        form.reset();
    }
    
    // Reset seller search
    resetSellerSearch('edit');
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initializeSellerSearch();
});

// CSS animations for notifications
const notificationStyles = document.createElement('style');
notificationStyles.textContent = `
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

@keyframes slideOut {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(100%); opacity: 0; }
}

.spinning {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
`;
document.head.appendChild(notificationStyles);