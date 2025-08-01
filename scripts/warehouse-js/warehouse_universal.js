// File: scripts/warehouse_universal.js
// Universal functionality for warehouse pages

// Global warehouse configuration
window.WMS_WAREHOUSE = {
    initialized: false,
    currentUser: null,
    apiBase: '/api'
};

// Initialize on DOM load
document.addEventListener('DOMContentLoaded', function() {
    initializeWarehouse();
});

/**
 * Initialize warehouse universal functionality
 */
function initializeWarehouse() {
    console.log('🏭 Warehouse Universal JS loaded');
    
    // Set configuration from PHP
    if (window.WMS_CONFIG) {
        window.WMS_WAREHOUSE.currentUser = window.WMS_CONFIG.currentUser;
        window.WMS_WAREHOUSE.apiBase = window.WMS_CONFIG.apiBase;
    }
    
    // Initialize mobile menu
    initializeMobileMenu();
    
    // Initialize global keyboard shortcuts
    initializeKeyboardShortcuts();
    
    // Initialize auto-refresh functionality
    initializeAutoRefresh();
    
    // Mark as initialized
    window.WMS_WAREHOUSE.initialized = true;
    
    console.log('✅ Warehouse initialized successfully');
}

/**
 * Mobile menu functionality
 */
function initializeMobileMenu() {
    const mobileToggle = document.getElementById('mobile-menu-toggle');
    const navMenu = document.querySelector('.nav-menu');
    
    if (mobileToggle && navMenu) {
        mobileToggle.addEventListener('click', function() {
            navMenu.classList.toggle('show');
        });
        
        // Close menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!mobileToggle.contains(e.target) && !navMenu.contains(e.target)) {
                navMenu.classList.remove('show');
            }
        });
    }
}

/**
 * Global keyboard shortcuts for warehouse operations
 */
function initializeKeyboardShortcuts() {
    document.addEventListener('keydown', function(e) {
        // Don't trigger shortcuts when typing in inputs
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
            return;
        }
        
        // Alt + R = Refresh current page data
        if (e.altKey && e.key === 'r') {
            e.preventDefault();
            if (window.refreshOrders) {
                refreshOrders();
            } else if (window.refreshData) {
                refreshData();
            }
            showToast('🔄 Actualizând datele...', 'info');
        }
        
        // Alt + H = Go to warehouse hub
        if (e.altKey && e.key === 'h') {
            e.preventDefault();
            window.location.href = 'warehouse_hub.php';
        }
        
        // Alt + O = Go to orders
        if (e.altKey && e.key === 'o') {
            e.preventDefault();
            window.location.href = 'warehouse_orders.php';
        }
        
        // Alt + P = Go to picking
        if (e.altKey && e.key === 'p') {
            e.preventDefault();
            window.location.href = 'warehouse_picking.php';
        }
        
        // Alt + I = Go to inventory search
        if (e.altKey && e.key === 'i') {
            e.preventDefault();
            window.location.href = 'warehouse_inventory.php';
        }
        
        // Escape = Close any open modals
        if (e.key === 'Escape') {
            closeAllModals();
        }
    });
}

/**
 * Auto-refresh functionality for real-time updates
 */
function initializeAutoRefresh() {
    // Auto-refresh every 30 seconds if refresh function exists
    if (window.refreshOrders || window.refreshData) {
        setInterval(function() {
            if (document.visibilityState === 'visible') { // Only refresh if tab is visible
                if (window.refreshOrders) {
                    refreshOrders();
                } else if (window.refreshData) {
                    refreshData();
                }
            }
        }, 30000); // 30 seconds
    }
}

/**
 * Universal toast notification system
 */
function showToast(message, type = 'info', duration = 3000) {
    // Remove existing toasts
    const existingToasts = document.querySelectorAll('.warehouse-toast');
    existingToasts.forEach(toast => toast.remove());
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `warehouse-toast warehouse-toast-${type}`;
    toast.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        background: ${getToastColor(type)};
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 3000;
        font-size: 0.875rem;
        font-weight: 500;
        max-width: 300px;
        opacity: 0;
        transform: translateX(100%);
        transition: all 0.3s ease;
    `;
    
    toast.textContent = message;
    document.body.appendChild(toast);
    
    // Animate in
    setTimeout(() => {
        toast.style.opacity = '1';
        toast.style.transform = 'translateX(0)';
    }, 10);
    
    // Auto-remove
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

/**
 * Get color for toast type
 */
function getToastColor(type) {
    const colors = {
        success: '#34a853',
        error: '#ea4335',
        warning: '#fbbc04',
        info: '#1a73e8'
    };
    return colors[type] || colors.info;
}

/**
 * Close all open modals
 */
function closeAllModals() {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.style.display = 'none';
        modal.classList.remove('show');
    });
}

/**
 * Universal API request handler
 */
async function warehouseRequest(endpoint, options = {}) {
    const defaultOptions = {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        }
    };
    
    const config = { ...defaultOptions, ...options };
    const url = endpoint.startsWith('http') ? endpoint : `${window.WMS_WAREHOUSE.apiBase}/${endpoint}`;
    
    try {
        const response = await fetch(url, config);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const data = await response.json();
        return { success: true, data };
        
    } catch (error) {
        console.error('Warehouse API Error:', error);
        return { success: false, error: error.message };
    }
}

/**
 * Format date for warehouse display
 */
function formatWarehouseDate(dateString) {
    if (!dateString) return '-';
    
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);
    
    if (diffMins < 60) {
        return `${diffMins}min`;
    } else if (diffHours < 24) {
        return `${diffHours}h`;
    } else if (diffDays < 7) {
        return `${diffDays}d`;
    } else {
        return date.toLocaleDateString('ro-RO');
    }
}

/**
 * Format currency for warehouse display
 */
function formatWarehouseCurrency(amount) {
    if (!amount && amount !== 0) return '-';
    return new Intl.NumberFormat('ro-RO', {
        style: 'currency',
        currency: 'RON',
        minimumFractionDigits: 0,
        maximumFractionDigits: 2
    }).format(amount);
}

/**
 * Get priority badge HTML
 */
function getPriorityBadge(priority) {
    const priorities = {
        urgent: { text: 'URGENT', color: '#ea4335' },
        high: { text: 'RIDICATĂ', color: '#fbbc04' },
        normal: { text: 'NORMALĂ', color: '#9aa0a6' }
    };
    
    const p = priorities[priority] || priorities.normal;
    
    return `<span style="
        display: inline-flex;
        align-items: center;
        padding: 0.125rem 0.5rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 500;
        background: ${p.color}20;
        color: ${p.color};
        border: 1px solid ${p.color}40;
    ">${p.text}</span>`;
}

/**
 * Get status badge HTML
 */
function getStatusBadge(status) {
    const statuses = {
        pending: { text: 'ÎN AȘTEPTARE', color: '#fbbc04' },
        processing: { text: 'ÎN PROCESARE', color: '#1a73e8' },
        ready: { text: 'GATA', color: '#34a853' },
        completed: { text: 'FINALIZAT', color: '#34a853' },
        cancelled: { text: 'ANULAT', color: '#ea4335' }
    };
    
    const s = statuses[status] || statuses.pending;
    
    return `<span style="
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        background: ${s.color}20;
        color: ${s.color};
        border: 1px solid ${s.color}40;
    ">${s.text}</span>`;
}

/**
 * Show loading state on element
 */
function showLoading(element, show = true) {
    if (typeof element === 'string') {
        element = document.getElementById(element);
    }
    
    if (!element) return;
    
    if (show) {
        element.style.display = 'flex';
        element.style.flexDirection = 'column';
        element.style.alignItems = 'center';
        element.style.justifyContent = 'center';
        element.style.padding = '2rem';
    } else {
        element.style.display = 'none';
    }
}

// Make functions globally available
window.showToast = showToast;
window.warehouseRequest = warehouseRequest;
window.formatWarehouseDate = formatWarehouseDate;
window.formatWarehouseCurrency = formatWarehouseCurrency;
window.getPriorityBadge = getPriorityBadge;
window.getStatusBadge = getStatusBadge;
window.showLoading = showLoading;
window.closeAllModals = closeAllModals;