/**
 * Warehouse Settings JavaScript
 * Handles tab switching, form interactions, and auto-save functionality
 */

document.addEventListener('DOMContentLoaded', function() {
    initializeWarehouseSettings();
});

/**
 * Initialize warehouse settings functionality
 */
function initializeWarehouseSettings() {
    // Initialize tab functionality
    initializeTabs();
    
    // Initialize form auto-save
    initializeAutoSave();
    
    // Initialize form validation
    initializeFormValidation();
    
    // Initialize confirmation dialogs
    initializeConfirmations();
    
    // Load saved tab preference
    loadSavedTab();
}

/**
 * Tab switching functionality
 */
function initializeTabs() {
    const tabButtons = document.querySelectorAll('.tab-button');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const tabName = this.onclick ? null : this.getAttribute('data-tab');
            if (!tabName && this.onclick) {
                // Handle existing onclick events
                return;
            }
            if (tabName) {
                switchTab(tabName);
            }
        });
    });
}

/**
 * Switch between tabs
 * @param {string} tabName - Name of the tab to switch to
 */
function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab content
    const targetTab = document.getElementById(tabName + '-tab');
    if (targetTab) {
        targetTab.classList.add('active');
    }
    
    // Add active class to clicked button
    const activeButton = event ? event.target.closest('.tab-button') : 
                         document.querySelector(`[onclick*="${tabName}"]`);
    if (activeButton) {
        activeButton.classList.add('active');
    }
    
    // Save tab preference
    localStorage.setItem('warehouse_settings_active_tab', tabName);
    
    // Trigger tab change event
    dispatchTabChangeEvent(tabName);
}

/**
 * Load saved tab preference
 */
function loadSavedTab() {
    const savedTab = localStorage.getItem('warehouse_settings_active_tab');
    if (savedTab && document.getElementById(savedTab + '-tab')) {
        switchTab(savedTab);
    }
}

/**
 * Dispatch custom tab change event
 * @param {string} tabName - Name of the active tab
 */
function dispatchTabChangeEvent(tabName) {
    const event = new CustomEvent('warehouseTabChange', {
        detail: { activeTab: tabName }
    });
    document.dispatchEvent(event);
}

/**
 * Initialize auto-save functionality
 */
function initializeAutoSave() {
    const forms = document.querySelectorAll('form');
    
    forms.forEach(form => {
        const inputs = form.querySelectorAll('input[type="number"], input[type="checkbox"], select');
        
        inputs.forEach(input => {
            // Load saved value
            loadSavedValue(input);
            
            // Save on change
            input.addEventListener('change', function() {
                saveInputValue(input);
            });
            
            // Auto-save for number inputs on blur
            if (input.type === 'number') {
                input.addEventListener('blur', function() {
                    saveInputValue(input);
                });
            }
        });
        
        // Clear saved data on form submit
        form.addEventListener('submit', function() {
            clearSavedFormData(form);
        });
    });
}

/**
 * Load saved value for an input
 * @param {HTMLElement} input - Input element
 */
function loadSavedValue(input) {
    const savedValue = localStorage.getItem('warehouse_settings_' + input.name);
    if (savedValue !== null) {
        if (input.type === 'checkbox') {
            input.checked = savedValue === 'true';
        } else {
            input.value = savedValue;
        }
    }
}

/**
 * Save input value to localStorage
 * @param {HTMLElement} input - Input element
 */
function saveInputValue(input) {
    if (input.type === 'checkbox') {
        localStorage.setItem('warehouse_settings_' + input.name, input.checked);
    } else {
        localStorage.setItem('warehouse_settings_' + input.name, input.value);
    }
}

/**
 * Clear saved form data
 * @param {HTMLElement} form - Form element
 */
function clearSavedFormData(form) {
    const inputs = form.querySelectorAll('input[type="number"], input[type="checkbox"], select');
    inputs.forEach(input => {
        localStorage.removeItem('warehouse_settings_' + input.name);
    });
}

/**
 * Initialize form validation
 */
function initializeFormValidation() {
    const forms = document.querySelectorAll('form');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(form)) {
                e.preventDefault();
                showValidationMessage('Vă rugăm să corectați erorile din formular.', 'error');
            }
        });
    });
    
    // Real-time validation for specific inputs
    initializeRealTimeValidation();
}

/**
 * Validate a form
 * @param {HTMLElement} form - Form to validate
 * @returns {boolean} - Whether form is valid
 */
function validateForm(form) {
    let isValid = true;
    const errors = [];
    
    // Check required fields
    const requiredInputs = form.querySelectorAll('input[required]');
    requiredInputs.forEach(input => {
        if (!input.value.trim()) {
            isValid = false;
            errors.push(`Câmpul "${input.labels[0]?.textContent || input.name}" este obligatoriu.`);
            highlightError(input);
        } else {
            clearError(input);
        }
    });
    
    // Check number constraints
    const numberInputs = form.querySelectorAll('input[type="number"]');
    numberInputs.forEach(input => {
        const value = parseFloat(input.value);
        const min = parseFloat(input.min);
        const max = parseFloat(input.max);
        
        if (input.value && isNaN(value)) {
            isValid = false;
            errors.push(`Câmpul "${input.labels[0]?.textContent || input.name}" trebuie să fie un număr valid.`);
            highlightError(input);
        } else if (!isNaN(min) && value < min) {
            isValid = false;
            errors.push(`Câmpul "${input.labels[0]?.textContent || input.name}" trebuie să fie cel puțin ${min}.`);
            highlightError(input);
        } else if (!isNaN(max) && value > max) {
            isValid = false;
            errors.push(`Câmpul "${input.labels[0]?.textContent || input.name}" nu poate fi mai mare de ${max}.`);
            highlightError(input);
        } else {
            clearError(input);
        }
    });
    
    // Show errors if any
    if (errors.length > 0) {
        showValidationMessage(errors[0], 'error');
    }
    
    return isValid;
}

/**
 * Initialize real-time validation
 */
function initializeRealTimeValidation() {
    const numberInputs = document.querySelectorAll('input[type="number"]');
    
    numberInputs.forEach(input => {
        input.addEventListener('input', function() {
            validateNumberInput(input);
        });
        
        input.addEventListener('blur', function() {
            validateNumberInput(input);
        });
    });
}

/**
 * Validate a number input
 * @param {HTMLElement} input - Number input to validate
 */
function validateNumberInput(input) {
    const value = parseFloat(input.value);
    const min = parseFloat(input.min);
    const max = parseFloat(input.max);
    
    if (input.value && isNaN(value)) {
        highlightError(input);
        showInputError(input, 'Valoare numerică invalidă');
    } else if (!isNaN(min) && value < min) {
        highlightError(input);
        showInputError(input, `Minimum: ${min}`);
    } else if (!isNaN(max) && value > max) {
        highlightError(input);
        showInputError(input, `Maximum: ${max}`);
    } else {
        clearError(input);
    }
}

/**
 * Highlight input error
 * @param {HTMLElement} input - Input element
 */
function highlightError(input) {
    input.style.borderColor = '#dc3545';
    input.style.boxShadow = '0 0 0 3px rgba(220, 53, 69, 0.1)';
}

/**
 * Clear input error highlighting
 * @param {HTMLElement} input - Input element
 */
function clearError(input) {
    input.style.borderColor = '';
    input.style.boxShadow = '';
    hideInputError(input);
}

/**
 * Show input-specific error message
 * @param {HTMLElement} input - Input element
 * @param {string} message - Error message
 */
function showInputError(input, message) {
    hideInputError(input); // Remove any existing error
    
    const errorDiv = document.createElement('div');
    errorDiv.className = 'input-error';
    errorDiv.style.cssText = `
        color: #dc3545;
        font-size: 0.8rem;
        margin-top: 0.25rem;
        display: block;
    `;
    errorDiv.textContent = message;
    
    input.parentNode.appendChild(errorDiv);
}

/**
 * Hide input-specific error message
 * @param {HTMLElement} input - Input element
 */
function hideInputError(input) {
    const existingError = input.parentNode.querySelector('.input-error');
    if (existingError) {
        existingError.remove();
    }
}

/**
 * Initialize confirmation dialogs
 */
function initializeConfirmations() {
    const dangerousButtons = document.querySelectorAll('button[type="submit"]');
    
    dangerousButtons.forEach(button => {
        const form = button.closest('form');
        const action = form?.querySelector('input[name="action"]')?.value;
        
        if (action === 'execute_repartition') {
            // Already has onsubmit confirmation in HTML
            return;
        }
        
        // Add loading state on submit
        button.addEventListener('click', function() {
            if (validateForm(form)) {
                addLoadingState(button);
            }
        });
    });
}

/**
 * Add loading state to button
 * @param {HTMLElement} button - Button element
 */
function addLoadingState(button) {
    button.classList.add('loading');
    button.disabled = true;
    
    // Remove loading state after 5 seconds (fallback)
    setTimeout(() => {
        removeLoadingState(button);
    }, 5000);
}

/**
 * Remove loading state from button
 * @param {HTMLElement} button - Button element
 */
function removeLoadingState(button) {
    button.classList.remove('loading');
    button.disabled = false;
}

/**
 * Show validation message
 * @param {string} message - Message to show
 * @param {string} type - Message type (success, error, warning, info)
 */
function showValidationMessage(message, type = 'info') {
    // Remove existing messages
    const existingAlerts = document.querySelectorAll('.validation-alert');
    existingAlerts.forEach(alert => alert.remove());
    
    // Create new alert
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} validation-alert`;
    alert.innerHTML = `
        <span class="material-symbols-outlined">
            ${type === 'success' ? 'check_circle' : (type === 'error' ? 'error' : 'info')}
        </span>
        ${message}
    `;
    
    // Insert after page header
    const pageHeader = document.querySelector('.page-header');
    if (pageHeader) {
        pageHeader.insertAdjacentElement('afterend', alert);
    } else {
        document.querySelector('.page-container').prepend(alert);
    }
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (alert.parentNode) {
            alert.remove();
        }
    }, 5000);
    
    // Scroll to message
    alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/**
 * Handle keyboard navigation
 */
document.addEventListener('keydown', function(e) {
    // Tab navigation with Ctrl+Tab
    if (e.ctrlKey && e.key === 'Tab') {
        e.preventDefault();
        
        const tabs = ['basic', 'dimensions', 'repartition'];
        const currentTab = localStorage.getItem('warehouse_settings_active_tab') || 'basic';
        const currentIndex = tabs.indexOf(currentTab);
        const nextIndex = e.shiftKey ? 
            (currentIndex - 1 + tabs.length) % tabs.length : 
            (currentIndex + 1) % tabs.length;
        
        switchTab(tabs[nextIndex]);
    }
});

/**
 * Export functions for global access
 */
window.warehouseSettings = {
    switchTab,
    showValidationMessage,
    addLoadingState,
    removeLoadingState
};