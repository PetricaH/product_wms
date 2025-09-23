// File: scripts/warehouse-js/warehouse_orders.js

// Global variables
let allOrders = [];
let filteredOrders = [];
let currentOrderId = null;
let currentOrderDetails = null;
let activeStatusFilter = '';
let modalKeyboardShortcutsInitialized = false;

// Use PHP-provided configuration
const API_BASE = window.WMS_CONFIG?.apiBase || '/api';

// Reuse picking AWB workflow helpers for modal actions
if (typeof window !== 'undefined') {
    window.IS_PICKING_INTERFACE = true;
}

console.log('Warehouse Orders JS loaded, API_BASE:', API_BASE);

// Initialize the dashboard
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing warehouse orders dashboard');
    initializeEventListeners();
    setDefaultFilter();
    loadOrders();
    setInterval(loadOrders, 30000); // Auto-refresh every 30 seconds
});

function setDefaultFilter() {
    activeStatusFilter = 'processing';
    const processingCard = document.querySelector('.stat-card[data-status="processing"]');
    if (processingCard) {
        processingCard.classList.add('active');
    }
}

/**
 * Initialize event listeners
 */
function initializeEventListeners() {
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach(card => {
        card.addEventListener('click', () => {
            const status = card.dataset.status;
            if (activeStatusFilter === status) {
                activeStatusFilter = '';
                card.classList.remove('active');
            } else {
                activeStatusFilter = status;
                statCards.forEach(c => c.classList.remove('active'));
                card.classList.add('active');
            }
            applyFilters();
        });
    });

    const printInvoiceBtn = document.getElementById('modal-print-invoice-btn');
    if (printInvoiceBtn) {
        printInvoiceBtn.dataset.originalHtml = printInvoiceBtn.innerHTML;
        printInvoiceBtn.addEventListener('click', handleModalInvoicePrint);
    }

    const printAwbBtn = document.getElementById('modal-print-awb-btn');
    if (printAwbBtn) {
        printAwbBtn.dataset.originalHtml = printAwbBtn.innerHTML;
        printAwbBtn.addEventListener('click', handleModalAwbPrint);
    }

    const generateAwbBtn = document.getElementById('modal-generate-awb-btn');
    if (generateAwbBtn) {
        generateAwbBtn.dataset.originalHtml = generateAwbBtn.innerHTML;
    }

    resetModalActions();

    setupModalKeyboardShortcuts();

    console.log('Event listeners initialized');
}

function setupModalKeyboardShortcuts() {
    if (modalKeyboardShortcutsInitialized) {
        return;
    }

    document.addEventListener('keydown', handleModalFunctionKeys);
    modalKeyboardShortcutsInitialized = true;
}

function handleModalFunctionKeys(event) {
    if (!isOrderDetailsModalVisible()) {
        return;
    }

    const activeElement = document.activeElement;
    if (activeElement && (activeElement.tagName === 'INPUT' || activeElement.tagName === 'TEXTAREA' || activeElement.isContentEditable)) {
        return;
    }

    if (event.key === 'F1') {
        const printInvoiceBtn = document.getElementById('modal-print-invoice-btn');
        if (isModalActionButtonVisible(printInvoiceBtn)) {
            event.preventDefault();
            printInvoiceBtn.click();
        }
    } else if (event.key === 'F2') {
        const printAwbBtn = document.getElementById('modal-print-awb-btn');
        const generateAwbBtn = document.getElementById('modal-generate-awb-btn');

        if (isModalActionButtonVisible(printAwbBtn)) {
            event.preventDefault();
            printAwbBtn.click();
        } else if (isModalActionButtonVisible(generateAwbBtn)) {
            event.preventDefault();
            generateAwbBtn.click();
        } else {
            const orderHasDetails = Boolean(currentOrderDetails && currentOrderDetails.id);
            if (orderHasDetails) {
                event.preventDefault();
                showMessage('AWB lipsă pentru această comandă.', 'error');
            }
        }
    }
}

function isOrderDetailsModalVisible() {
    const modal = document.getElementById('order-details-modal');
    if (!modal) {
        return false;
    }
    const isDisplayed = modal.style.display !== 'none';
    return modal.classList.contains('show') && isDisplayed;
}

function isModalActionButtonVisible(button) {
    if (!button || button.disabled) {
        return false;
    }
    if (button.classList.contains('hidden')) {
        return false;
    }

    return button.offsetParent !== null;
}

/**
 * Load orders from API
 */
async function loadOrders() {
    const loadingEl = document.getElementById('loading');
    const ordersGrid = document.getElementById('orders-grid');
    const noOrdersEl = document.getElementById('no-orders');

    showLoading(true);
    hideOrdersGrid();

    try {
        console.log('🔍 Loading orders from API...');
        
        const endpoint = `${API_BASE}/warehouse/get_orders.php`;
        console.log(`🚀 API endpoint: ${endpoint}`);
        
        const response = await fetch(endpoint);
        const responseText = await response.text();
        
        console.log('Response status:', response.status);
        console.log('Response preview:', responseText.substring(0, 200));
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${responseText}`);
        }
        
        if (responseText.trim().startsWith('<')) {
            throw new Error('API returned HTML instead of JSON. Check server configuration and PHP errors.');
        }

        const data = JSON.parse(responseText);
        console.log('Parsed response:', data);
        
        if (data.status !== 'success') {
            throw new Error(data.message || 'API returned error status');
        }

        allOrders = data.orders || [];
        console.log(`✅ Loaded ${allOrders.length} orders`);
        
        applyFilters();
        
    } catch (error) {
        console.error('❌ Error loading orders:', error);
        showError('Error Loading Orders', error.message, endpoint);
        hideLoading();
        hideOrdersGrid();
        hideNoOrders();
    }
}

/**
 * Apply current filters to orders
 */
function applyFilters() {
    filteredOrders = allOrders.filter(order => {
        if (activeStatusFilter === 'processing') {
            return order.status === 'processing' || order.status === 'assigned';
        }
        if (activeStatusFilter === 'completed') {
            return ['completed', 'ready', 'picked'].includes(order.status);
        }
        return !activeStatusFilter || order.status === activeStatusFilter;
    });

    // Ensure orders are sorted from oldest to newest
    filteredOrders.sort((a, b) => new Date(a.order_date) - new Date(b.order_date));

    console.log(`Filtered to ${filteredOrders.length} orders`);
    displayOrders();
}

/**
 * Display orders in the grid
 */
function displayOrders() {
    hideLoading();
    
    const ordersGrid = document.getElementById('orders-grid');
    const noOrdersEl = document.getElementById('no-orders');
    
    if (!ordersGrid) {
        console.error('Orders grid element not found');
        return;
    }
    
    if (filteredOrders.length === 0) {
        hideOrdersGrid();
        showNoOrders();
        return;
    }
    
    hideNoOrders();
    showOrdersGrid();
    
    ordersGrid.innerHTML = '';
    
    filteredOrders.forEach(order => {
        const orderCard = createOrderCard(order);
        ordersGrid.appendChild(orderCard);
    });
    
    console.log(`✅ Displayed ${filteredOrders.length} orders`);
}

/**
 * Assign order for picking and redirect to mobile picker
 */
async function assignOrderForPicking(orderNumber) {
    console.log('📋 Assigning order for picking:', orderNumber);
    
    try {
        showLoading(true);
        
        const response = await fetch('/api/warehouse/assign_order.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                order_number: orderNumber,
                action: 'assign_picking'
            })
        });

        if (!response.ok) {
            throw new Error(`HTTP error ${response.status}`);
        }

        const data = await response.json();
        
        if (data.status === 'success') {
            console.log('✅ Order assigned successfully. Redirecting to mobile picker...');
            
            // Show success message briefly
            showSuccessMessage('Comandă asignată! Redirecționare către interfața de picking...');
            
            // FIXED: Redirect directly to mobile picker with order parameter
            setTimeout(() => {
                window.location.href = `mobile_picker.php?order=${encodeURIComponent(orderNumber)}`;
            }, 1500);
        } else {
            throw new Error(data.message || 'Failed to assign order');
        }
        
    } catch (error) {
        console.error('❌ Error processing order:', error);
        showErrorMessage('Eroare la procesarea comenzii: ' + error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Create order card element
 */
function createOrderCard(order) {
    const card = document.createElement('div');
    card.className = 'order-card';
    card.onclick = () => openOrderDetails(order.id);
    
    const statusClass = order.status.toLowerCase();
    const priorityClass = order.priority.toLowerCase();
    
    // Determine what actions are available based on order status
    const statusLower = order.status.toLowerCase();
    const isProcessing = statusLower === 'processing' || statusLower === 'assigned';
    let actionButtons = '';

    if (isProcessing) {
        actionButtons = `<button class="btn btn-success" onclick="event.stopPropagation(); continuePicking('${order.order_number}')">
                <span class="material-symbols-outlined">play_arrow</span>
                Continuă Picking
            </button>`;
    } else if (statusLower === 'pending') {
        actionButtons = `<button class="btn btn-primary" onclick="event.stopPropagation(); processOrder(${order.id})">
                <span class="material-symbols-outlined">engineering</span>
                Începe Procesarea
            </button>`;
    }
    
    card.innerHTML = `
        <div class="order-header">
            <div class="order-number">${escapeHtml(order.order_number)}</div>
            <div class="order-status ${statusClass}">${getStatusText(order.status)}</div>
        </div>
        
        <div class="order-info">
            <div class="order-customer">${escapeHtml(order.customer_name)}</div>
            <div class="order-details">
                <span>Produse: ${order.total_items}</span>
                <span>Valoare: ${order.total_value} RON</span>
                <span class="order-priority ${priorityClass}">${getPriorityText(order.priority)}</span>
            </div>
        </div>
        
        <div class="order-actions">
            ${actionButtons}
            <button class="btn btn-secondary" onclick="event.stopPropagation(); openOrderDetails(${order.id})">
                <span class="material-symbols-outlined">visibility</span>
                Detalii
            </button>
        </div>
    `;
    
    return card;
}

/**
 * Get status text in Romanian
 */
function getStatusText(status) {
    const statusMap = {
        'pending': 'În așteptare',
        'processing': 'În procesare',
        'assigned': 'Asignat',
        'ready': 'Gata',
        'completed': 'Finalizat'
    };
    return statusMap[status.toLowerCase()] || status;
}

/**
 * Get priority text in Romanian
 */
function getPriorityText(priority) {
    const priorityMap = {
        'urgent': 'URGENT',
        'high': 'RIDICATĂ', 
        'normal': 'NORMALĂ'
    };
    return priorityMap[priority.toLowerCase()] || priority.toUpperCase();
}

/**
 * Open order details modal
 */
function openOrderDetails(orderId) {
    currentOrderId = orderId;
    const order = allOrders.find(o => o.id === orderId);

    if (!order) {
        console.error('Order not found:', orderId);
        return;
    }

    currentOrderDetails = order;

    const modal = document.getElementById('order-details-modal');
    const content = document.getElementById('order-details-content');

    if (!modal || !content) {
        console.error('Modal elements not found');
        return;
    }

    content.innerHTML = `
        <div class="order-details-info">
            <h3>Comandă ${escapeHtml(order.order_number)}</h3>
            <div class="detail-row">
                <strong>Client:</strong> ${escapeHtml(order.customer_name)}
            </div>
            <div class="detail-row">
                <strong>Adresa:</strong> ${escapeHtml(order.shipping_address || 'Nu este specificată')}
            </div>
            <div class="detail-row">
                <strong>Status:</strong> ${getStatusText(order.status)}
            </div>
            <div class="detail-row order-awb-info ${getAwbCode(order) ? '' : 'hidden'}" id="modal-awb-row">
                <strong>AWB:</strong> <span class="awb-code" id="modal-awb-code">${escapeHtml(getAwbCode(order))}</span>
            </div>
            <div class="detail-row">
                <strong>Prioritate:</strong> ${getPriorityText(order.priority)}
            </div>
            <div class="detail-row">
                <strong>Data comenzii:</strong> ${formatDate(order.order_date)}
            </div>
            <div class="detail-row">
                <strong>Produse:</strong> ${order.total_items}
            </div>
            <div class="detail-row">
                <strong>Valoare totală:</strong> ${order.total_value} RON
            </div>
            ${order.notes ? `<div class="detail-row"><strong>Note:</strong> ${escapeHtml(order.notes)}</div>` : ''}
        </div>
    `;

    updateModalActions(order);

    modal.style.display = 'flex';
    modal.classList.add('show');
}

/**
 * Close order details modal
 */
function closeOrderDetails() {
    const modal = document.getElementById('order-details-modal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
    }
    currentOrderId = null;
    currentOrderDetails = null;
    resetModalActions();
}

function updateModalActions(order) {
    resetModalActions();

    if (!order) {
        return;
    }

    const processBtn = document.getElementById('process-order-btn');
    const printInvoiceBtn = document.getElementById('modal-print-invoice-btn');
    const generateAwbBtn = document.getElementById('modal-generate-awb-btn');
    const printAwbBtn = document.getElementById('modal-print-awb-btn');
    const awbRow = document.getElementById('modal-awb-row');
    const awbCodeEl = document.getElementById('modal-awb-code');

    const completed = isCompletedOrder(order);

    if (processBtn) {
        processBtn.classList.toggle('hidden', completed);
    }

    if (!completed) {
        return;
    }

    if (printInvoiceBtn) {
        printInvoiceBtn.classList.remove('hidden');
    }

    const awbCode = getAwbCode(order);

    if (generateAwbBtn) {
        if (order.id) {
            generateAwbBtn.setAttribute('data-order-id', order.id);
        }
        generateAwbBtn.classList.toggle('hidden', Boolean(awbCode));
    }

    if (printAwbBtn) {
        if (awbCode) {
            printAwbBtn.classList.remove('hidden');
            printAwbBtn.dataset.awbCode = awbCode;
        } else {
            printAwbBtn.classList.add('hidden');
            delete printAwbBtn.dataset.awbCode;
        }
    }

    if (awbRow && awbCodeEl) {
        if (awbCode) {
            awbCodeEl.textContent = awbCode;
            awbRow.classList.remove('hidden');
        } else {
            awbCodeEl.textContent = '';
            awbRow.classList.add('hidden');
        }
    }
}

function resetModalActions() {
    const processBtn = document.getElementById('process-order-btn');
    const printInvoiceBtn = document.getElementById('modal-print-invoice-btn');
    const generateAwbBtn = document.getElementById('modal-generate-awb-btn');
    const printAwbBtn = document.getElementById('modal-print-awb-btn');
    const awbRow = document.getElementById('modal-awb-row');
    const awbCodeEl = document.getElementById('modal-awb-code');

    if (processBtn) {
        processBtn.classList.remove('hidden');
        processBtn.disabled = false;
    }

    if (printInvoiceBtn) {
        printInvoiceBtn.classList.add('hidden');
        printInvoiceBtn.disabled = false;
        if (printInvoiceBtn.dataset.originalHtml) {
            printInvoiceBtn.innerHTML = printInvoiceBtn.dataset.originalHtml;
        }
    }

    if (generateAwbBtn) {
        generateAwbBtn.classList.add('hidden');
        generateAwbBtn.removeAttribute('data-order-id');
    }

    if (printAwbBtn) {
        printAwbBtn.classList.add('hidden');
        printAwbBtn.disabled = false;
        if (printAwbBtn.dataset.originalHtml) {
            printAwbBtn.innerHTML = printAwbBtn.dataset.originalHtml;
        }
        delete printAwbBtn.dataset.awbCode;
    }

    if (awbRow && awbCodeEl) {
        awbCodeEl.textContent = '';
        awbRow.classList.add('hidden');
    }
}

function isCompletedOrder(order) {
    if (!order || !order.status) {
        return false;
    }
    const status = String(order.status).toLowerCase();
    return ['completed', 'ready', 'picked'].includes(status);
}

function getAwbCode(order) {
    if (!order) {
        return '';
    }

    const possibleKeys = ['awb_barcode', 'awb', 'tracking_number', 'awb_code', 'awb_number', 'awb_nr'];
    for (const key of possibleKeys) {
        if (order[key]) {
            return String(order[key]).trim();
        }
    }
    return '';
}

function applyAwbToOrder(order, awbCode) {
    if (!order || !awbCode) {
        return;
    }
    order.awb_barcode = awbCode;
    order.awb = awbCode;
    order.tracking_number = awbCode;
    order.awb_code = awbCode;
}

/**
 * Process current order
 */
function processCurrentOrder() {
    if (currentOrderId) {
        processOrder(currentOrderId);
        closeOrderDetails();
    }
}

/**
 * Process order (assign to current user and redirect to picking interface)
 */
async function processOrder(orderId) {
    try {
        console.log('🔧 Processing order:', orderId);
        
        const formData = new FormData();
        formData.append('order_id', orderId);
        
        const response = await fetch(`${API_BASE}/warehouse/assign_order.php`, {
            method: 'POST',
            body: formData
        });
        
        const responseText = await response.text();
        console.log('Assignment response:', responseText);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${responseText}`);
        }
        
        const data = JSON.parse(responseText);
        
        if (data.status === 'success') {
            console.log('✅ Order assigned successfully! Redirecting to mobile picker...');
            
            // Show success message briefly
            showSuccessMessage('Comandă asignată! Redirecționare către interfața de picking...');
            
            // Redirect to mobile picker interface after short delay
            setTimeout(() => {
                window.location.href = `mobile_picker.php?order=${encodeURIComponent(data.data.order_number)}`;
            }, 1500);
        } else {
            throw new Error(data.message || 'Failed to assign order');
        }
        
    } catch (error) {
        console.error('❌ Error processing order:', error);
        showErrorMessage('Eroare la procesarea comenzii: ' + error.message);
    }
}


async function handleModalInvoicePrint(event) {
    event.preventDefault();
    event.stopPropagation();

    if (!currentOrderDetails || !currentOrderDetails.id) {
        showErrorMessage('Nicio comandă selectată pentru printarea facturii.');
        return;
    }

    const btn = event.currentTarget;
    const originalHtml = btn.dataset.originalHtml || btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined spinning">hourglass_empty</span> Se trimite la imprimantă...';

    try {
        const response = await fetch('api/invoices/print_invoice_network.php', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'Cache-Control': 'no-cache',
                'Accept': 'application/json'
            },
            body: new URLSearchParams({
                order_id: currentOrderDetails.id,
                printer_id: 2
            })
        });

        const responseText = await response.text();

        if (!response.ok) {
            if (response.status === 403) {
                throw new Error('Sesiune expirată. Reautentificare necesară.');
            }
            throw new Error(responseText || `Eroare server: HTTP ${response.status}`);
        }

        let data;
        try {
            data = JSON.parse(responseText);
        } catch (error) {
            console.error('Invoice print parse error:', error, responseText);
            throw new Error('Răspuns invalid de la server.');
        }

        if (data.status !== 'success') {
            throw new Error(data.message || 'Eroare la printarea facturii');
        }

        showSuccessMessage('Factura a fost trimisă la imprimantă.');
    } catch (error) {
        console.error('Invoice print error:', error);
        showErrorMessage(`Eroare la printarea facturii: ${error.message}`);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

async function handleModalAwbPrint(event) {
    event.preventDefault();
    event.stopPropagation();

    if (!currentOrderDetails || !currentOrderDetails.id) {
        showErrorMessage('Nicio comandă selectată pentru printarea AWB-ului.');
        return;
    }

    if (typeof printAWB !== 'function') {
        showErrorMessage('Funcționalitatea de printare AWB nu este disponibilă.');
        return;
    }

    const awbCode = getAwbCode(currentOrderDetails);

    if (!awbCode) {
        showErrorMessage('Această comandă nu are AWB generat încă.');
        return;
    }

    const btn = event.currentTarget;
    const originalHtml = btn.dataset.originalHtml || btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined spinning">hourglass_empty</span> Se pregătește printarea...';

    try {
        await printAWB(currentOrderDetails.id, awbCode, currentOrderDetails.order_number);
    } catch (error) {
        console.error('Print AWB error:', error);
        showErrorMessage(`Eroare la printarea AWB: ${error.message || error}`);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

document.addEventListener('awbGenerated', event => {
    const detail = event.detail || {};
    const orderId = detail.orderId;
    const awbCode = detail.awbCode;

    if (!orderId || !awbCode) {
        return;
    }

    const updateList = orders => {
        if (!Array.isArray(orders)) {
            return;
        }
        orders.forEach(order => {
            if (order && order.id === orderId) {
                applyAwbToOrder(order, awbCode);
            }
        });
    };

    updateList(allOrders);
    updateList(filteredOrders);

    if (currentOrderDetails && currentOrderDetails.id === orderId) {
        applyAwbToOrder(currentOrderDetails, awbCode);
        updateModalActions(currentOrderDetails);
        showSuccessMessage('AWB generat cu succes. Poți să îl printezi din această fereastră.');
    }
});


/**
 * Continue picking an order that's already assigned
 */
function continuePicking(orderNumber) {
    console.log('📋 Continuing picking for order:', orderNumber);
    showSuccessMessage('Redirecționare către interfața de picking...');
    setTimeout(() => {
        window.location.href = `mobile_picker.php?order=${encodeURIComponent(orderNumber)}`;
    }, 1000);
}
/**
 * Show success message
 */
function showSuccessMessage(message) {
    showMessage(message, 'success');
}

/**
 * Show error message
 */
function showErrorMessage(message) {
    showMessage(message, 'error');
}

/**
 * Show temporary message to user
 */
function showMessage(message, type = 'info') {
    // Remove existing messages
    const existing = document.querySelectorAll('.temp-message');
    existing.forEach(el => el.remove());
    
    const messageEl = document.createElement('div');
    messageEl.className = `temp-message alert alert-${type === 'error' ? 'danger' : type}`;
    messageEl.style.cssText = `
        position: fixed;
        top: 80px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 3000;
        min-width: 300px;
        text-align: center;
        animation: slideDown 0.3s ease;
    `;
    messageEl.textContent = message;
    
    document.body.appendChild(messageEl);
    
    // Auto-remove after 3 seconds
    setTimeout(() => {
        messageEl.style.animation = 'slideUp 0.3s ease';
        setTimeout(() => messageEl.remove(), 300);
    }, 3000);
}

// ===== UTILITY FUNCTIONS =====

/**
 * Show loading state
 */
function showLoading(show = true) {
    const loadingEl = document.getElementById('loading');
    if (loadingEl) {
        loadingEl.style.display = show ? 'block' : 'none';
    }
}

/**
 * Hide loading state
 */
function hideLoading() {
    showLoading(false);
}

/**
 * Show orders grid
 */
function showOrdersGrid() {
    const ordersGrid = document.getElementById('orders-grid');
    if (ordersGrid) {
        ordersGrid.style.display = 'grid';
    }
}

/**
 * Hide orders grid
 */
function hideOrdersGrid() {
    const ordersGrid = document.getElementById('orders-grid');
    if (ordersGrid) {
        ordersGrid.style.display = 'none';
    }
}

/**
 * Show no orders message
 */
function showNoOrders() {
    const noOrdersEl = document.getElementById('no-orders');
    if (noOrdersEl) {
        noOrdersEl.style.display = 'block';
    }
}

/**
 * Hide no orders message
 */
function hideNoOrders() {
    const noOrdersEl = document.getElementById('no-orders');
    if (noOrdersEl) {
        noOrdersEl.style.display = 'none';
    }
}

/**
 * Show error message
 */
function showError(title, message, apiUrl) {
    console.error(`❌ ${title}: ${message}`);
    
    // Create error display
    const errorHtml = `
        <div class="error-display" style="
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid var(--danger-color);
            color: var(--danger-color);
            padding: 2rem;
            border-radius: 6px;
            text-align: center;
            margin: 2rem 0;
        ">
            <h3>${title}</h3>
            <p><strong>Error:</strong> ${escapeHtml(message)}</p>
            <p><strong>API URL:</strong> ${escapeHtml(apiUrl)}</p>
            <button onclick="loadOrders()" style="
                background: var(--danger-color);
                color: white;
                border: none;
                padding: 0.5rem 1rem;
                border-radius: 4px;
                margin-top: 1rem;
                cursor: pointer;
            ">Încearcă din nou</button>
        </div>
    `;
    
    const ordersGrid = document.getElementById('orders-grid');
    if (ordersGrid) {
        ordersGrid.innerHTML = errorHtml;
        ordersGrid.style.display = 'block';
    }
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Format date for display
 */
function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('ro-RO') + ' ' + date.toLocaleTimeString('ro-RO', {
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Clear all filters
 */
function clearFilters() {
    const statusFilter = document.getElementById('status-filter');
    const priorityFilter = document.getElementById('priority-filter');
    
    if (statusFilter) statusFilter.value = '';
    if (priorityFilter) priorityFilter.value = '';
    
    applyFilters();
}

// Make functions globally available
// Expose loadOrders for retry button
window.loadOrders = loadOrders;
window.clearFilters = clearFilters;
window.closeOrderDetails = closeOrderDetails;
window.processCurrentOrder = processCurrentOrder;
window.continuePicking = continuePicking;

// Add CSS animations for messages
const style = document.createElement('style');
style.textContent = `
    @keyframes slideDown {
        from { opacity: 0; transform: translateX(-50%) translateY(-20px); }
        to { opacity: 1; transform: translateX(-50%) translateY(0); }
    }
    
    @keyframes slideUp {
        from { opacity: 1; transform: translateX(-50%) translateY(0); }
        to { opacity: 0; transform: translateX(-50%) translateY(-20px); }
    }
    
    .btn-success {
        background-color: var(--success-color);
        color: white;
    }
    
    .btn-success:hover {
        background-color: #157347;
    }
`;
document.head.appendChild(style);