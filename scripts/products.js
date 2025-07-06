/**
 * Simple Products Page JavaScript
 * Modal management and basic interactions
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
    // The table row animation that was causing issues has been removed.
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
