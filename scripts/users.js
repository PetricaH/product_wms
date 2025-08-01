/**
 * Users Page JavaScript
 * Handles modal interactions, form management, and search functionality
 */

// DOM Elements
const createModal = document.getElementById('createUserModal');
const editModal = document.getElementById('editUserModal');
const deleteModal = document.getElementById('deleteUserModal');

// Search functionality
const searchInput = document.getElementById('search-users');
let searchTimeout;

/**
 * Initialize page functionality
 */
document.addEventListener('DOMContentLoaded', function() {
    initializeSearch();
    initializeModalEvents();
    initializeTableInteractions();
});

/**
 * Search functionality
 */
function initializeSearch() {
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                filterUsers(this.value.toLowerCase());
            }, 300);
        });
    }
}

function filterUsers(searchTerm) {
    const tableRows = document.querySelectorAll('.table tbody tr');
    
    tableRows.forEach(row => {
        const username = row.cells[1]?.textContent.toLowerCase() || '';
        const email = row.cells[2]?.textContent.toLowerCase() || '';
        const role = row.cells[3]?.textContent.toLowerCase() || '';
        
        const isVisible = username.includes(searchTerm) || 
                         email.includes(searchTerm) || 
                         role.includes(searchTerm);
        
        row.style.display = isVisible ? '' : 'none';
    });
    
    // Show/hide empty state
    updateEmptyState(searchTerm);
}

function updateEmptyState(searchTerm) {
    const visibleRows = document.querySelectorAll('.table tbody tr[style=""]').length;
    const tableContainer = document.querySelector('.table-responsive');
    
    if (visibleRows === 0 && searchTerm) {
        if (!document.getElementById('search-empty-state')) {
            const emptyState = document.createElement('div');
            emptyState.id = 'search-empty-state';
            emptyState.className = 'empty-state';
            emptyState.innerHTML = `
                <span class="material-symbols-outlined">search_off</span>
                <h3>Nu s-au găsit rezultate</h3>
                <p>Încercați să modificați termenii de căutare.</p>
            `;
            tableContainer.appendChild(emptyState);
        }
        document.getElementById('search-empty-state').style.display = 'block';
    } else {
        const searchEmptyState = document.getElementById('search-empty-state');
        if (searchEmptyState) {
            searchEmptyState.style.display = 'none';
        }
    }
}

/**
 * Modal Management
 */
function initializeModalEvents() {
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
        document.getElementById('create-username')?.focus();
    }, 100);
}

function closeCreateModal() {
    hideModal(createModal);
    resetCreateForm();
}

function openEditModal(userData) {
    populateEditForm(userData);
    showModal(editModal);
    
    // Focus first input
    setTimeout(() => {
        document.getElementById('edit-username')?.focus();
    }, 100);
}

function closeEditModal() {
    hideModal(editModal);
    resetEditForm();
}

function confirmDelete(userId, username) {
    document.getElementById('delete-user-id').value = userId;
    document.getElementById('delete-username').textContent = username;
    showModal(deleteModal);
}

function closeDeleteModal() {
    hideModal(deleteModal);
}

function showModal(modal) {
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function hideModal(modal) {
    if (modal) {
        modal.classList.remove('show');
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
        // Set default values
        document.getElementById('create-role').value = 'user';
        document.getElementById('create-status').checked = true;
    }
}

function resetEditForm() {
    const form = editModal?.querySelector('form');
    if (form) {
        form.reset();
    }
}

function populateEditForm(userData) {
    try {
        // Handle both JSON string and object
        const user = typeof userData === 'string' ? JSON.parse(userData) : userData;
        
        document.getElementById('edit-user-id').value = user.id || '';
        document.getElementById('edit-username').value = user.username || '';
        document.getElementById('edit-email').value = user.email || '';
        document.getElementById('edit-role').value = user.role || 'user';
        document.getElementById('edit-status').checked = user.status == 1;
        
        // Clear password field
        document.getElementById('edit-password').value = '';
        
    } catch (error) {
        console.error('Error populating edit form:', error);
        alert('Eroare la încărcarea datelor utilizatorului.');
        closeEditModal();
    }
}

/**
 * Table Interactions
 */
function initializeTableInteractions() {
    // Add hover effects and animations
    const tableRows = document.querySelectorAll('.table tbody tr');
    
    tableRows.forEach(row => {
        // Add subtle animation on load
        row.style.opacity = '0';
        row.style.transform = 'translateY(10px)';
        
        setTimeout(() => {
            row.style.transition = 'all 0.3s ease';
            row.style.opacity = '1';
            row.style.transform = 'translateY(0)';
        }, Math.random() * 300);
    });
}

/**
 * Form Validation
 */
function validateCreateForm() {
    const form = createModal?.querySelector('form');
    if (!form) return false;
    
    const username = document.getElementById('create-username').value.trim();
    const email = document.getElementById('create-email').value.trim();
    const password = document.getElementById('create-password').value;
    
    if (!username || !email || !password) {
        alert('Toate câmpurile marcate cu * sunt obligatorii.');
        return false;
    }
    
    if (password.length < 6) {
        alert('Parola trebuie să aibă cel puțin 6 caractere.');
        return false;
    }
    
    return true;
}

function validateEditForm() {
    const form = editModal?.querySelector('form');
    if (!form) return false;
    
    const username = document.getElementById('edit-username').value.trim();
    const email = document.getElementById('edit-email').value.trim();
    const password = document.getElementById('edit-password').value;
    
    if (!username || !email) {
        alert('Numele de utilizator și email-ul sunt obligatorii.');
        return false;
    }
    
    if (password && password.length < 6) {
        alert('Parola nouă trebuie să aibă cel puțin 6 caractere.');
        return false;
    }
    
    return true;
}

/**
 * Form Submission Enhancement
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
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        // Auto-hide success alerts after 5 seconds
        if (alert.classList.contains('alert-success')) {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    alert.remove();
                }, 300);
            }, 5000);
        }
    });
});

/**
 * Keyboard Shortcuts
 */
document.addEventListener('keydown', function(e) {
    // Ctrl+N or Cmd+N to open create modal
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        openCreateModal();
    }
    
    // Ctrl+F or Cmd+F to focus search
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        searchInput?.focus();
    }
});

/**
 * Utility Functions
 */
function showNotification(message, type = 'info') {
    // Create and show a temporary notification
    const notification = document.createElement('div');
    notification.className = `alert alert-${type}`;
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '9999';
    notification.style.maxWidth = '400px';
    notification.innerHTML = `
        <span class="material-symbols-outlined">
            ${type === 'success' ? 'check_circle' : type === 'error' ? 'error' : 'info'}
        </span>
        ${message}
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove after 3 seconds
    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 3000);
}