// File: scripts/warehouse_orders.js
// Warehouse Orders Dashboard JavaScript

// Global variables
let allOrders = [];
let filteredOrders = [];
let orderItemCount = 0;
let filterTimeout;

// Use PHP-provided configuration
const API_BASE = window.WMS_CONFIG?.apiBase || '/api';

console.log('Warehouse Orders JS loaded, API_BASE:', API_BASE);

// Initialize the dashboard
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing warehouse orders dashboard');
    loadOrders();
    setInterval(loadOrders, 30000); // Auto-refresh every 30 seconds
});

// ===== MAIN API FUNCTIONS =====

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
        console.log('✅ API response parsed:', data);
        
        if (data.status === 'success' && Array.isArray(data.orders)) {
            allOrders = data.orders;
            updateStats();
            filterOrders();
            showMessage(`✅ Loaded ${data.orders.length} orders successfully`, false);
        } else {
            throw new Error(`Invalid API response: ${data.message || 'Unknown error'}`);
        }

    } catch (error) {
        console.error('❌ Error loading orders:', error);
        showMessage(`❌ Error loading orders: ${error.message}`, true);
        showErrorState(error.message);
    } finally {
        showLoading(false);
    }
}

/**
 * Start picking an order
 */
async function startPicking(orderNumber) {
    try {
        console.log('🎯 Starting picking for order:', orderNumber);
        
        const endpoint = `${API_BASE}/warehouse/assign_order.php`;
        console.log('🚀 POST to:', endpoint);
        
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ order_number: orderNumber })
        });

        const responseText = await response.text();
        console.log('Assign response:', responseText);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${responseText}`);
        }
        
        if (responseText.trim().startsWith('<')) {
            throw new Error('API returned HTML instead of JSON. Check server logs for PHP errors.');
        }
        
        const data = JSON.parse(responseText);
        console.log('✅ Assign response:', data);
        
        if (data.status === 'success') {
            showMessage('✅ Order assigned successfully! Redirecting to mobile picker...', false);
            setTimeout(() => {
                window.location.href = `mobile_picker.php?order=${orderNumber}`;
            }, 1000);
        } else {
            throw new Error(data.message || 'Unknown error from server');
        }
        
    } catch (error) {
        console.error('❌ Error assigning order:', error);
        showMessage(`❌ Error assigning order: ${error.message}`, true);
    }
}

/**
 * Continue picking an order
 */
function continuePicking(orderNumber) {
    console.log('📋 Continuing picking for order:', orderNumber);
    window.location.href = `mobile_picker.php?order=${orderNumber}`;
}

/**
 * View order details
 */
async function viewOrderDetails(orderId) {
    try {
        console.log('👁️ Loading order details for ID:', orderId);
        
        const endpoint = `${API_BASE}/warehouse/order_details.php?id=${orderId}`;
        console.log('🚀 GET from:', endpoint);
        
        const response = await fetch(endpoint);
        const responseText = await response.text();
        
        console.log('Details response status:', response.status);
        console.log('Details response preview:', responseText.substring(0, 200));
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${responseText}`);
        }
        
        if (responseText.trim().startsWith('<')) {
            throw new Error('API returned HTML instead of JSON. Check server logs for PHP errors.');
        }
        
        const result = JSON.parse(responseText);
        console.log('✅ Details response:', result);
        
        if (result.status === 'success') {
            const order = result.data;
            displayOrderDetails(order);
            document.getElementById('orderDetailsModal').style.display = 'block';
        } else {
            throw new Error(result.message || 'API error');
        }
        
    } catch (error) {
        console.error('❌ Error loading order details:', error);
        showMessage(`❌ Error loading order details: ${error.message}`, true);
    }
}

// ===== UI HELPER FUNCTIONS =====

/**
 * Update statistics display
 */
function updateStats() {
    document.getElementById('total-orders').textContent = allOrders.length;
    document.getElementById('pending-orders').textContent = allOrders.filter(o => o.status === 'pending').length;
    document.getElementById('in-progress-orders').textContent = allOrders.filter(o => o.status === 'assigned').length;
    document.getElementById('completed-orders').textContent = allOrders.filter(o => o.status === 'completed').length;
}

/**
 * Filter and display orders
 */
function filterOrders() {
    const statusFilter = document.getElementById('status-filter')?.value || '';
    const priorityFilter = document.getElementById('priority-filter')?.value || '';
    
    filteredOrders = allOrders.filter(order => {
        return (!statusFilter || order.status === statusFilter) &&
               (!priorityFilter || order.priority === priorityFilter);
    });
    
    displayOrders();
}

/**
 * Display orders in grid
 */
function displayOrders() {
    const ordersGrid = document.getElementById('orders-grid');
    const noOrdersEl = document.getElementById('no-orders');
    
    if (!ordersGrid) return;
    
    if (filteredOrders.length === 0) {
        ordersGrid.style.display = 'none';
        if (noOrdersEl) noOrdersEl.style.display = 'block';
        return;
    }
    
    ordersGrid.innerHTML = filteredOrders.map(order => generateOrderCard(order)).join('');
    ordersGrid.style.display = 'grid';
    if (noOrdersEl) noOrdersEl.style.display = 'none';
}

/**
 * Generate HTML for an order card
 */
function generateOrderCard(order) {
    const priorityClass = order.priority || 'normal';
    const statusClass = order.status.toLowerCase().replace(' ', '-');
    
    return `
        <div class="order-card ${priorityClass}" data-order-id="${order.id}">
            <div class="order-header">
                <div class="order-number">${order.order_number}</div>
                <div class="order-status status-${statusClass}">${getStatusLabel(order.status)}</div>
            </div>
            
            <div class="order-info">
                <p><strong>Customer:</strong> ${order.customer_name}</p>
                <p><strong>Items:</strong> ${order.total_items} | <strong>Remaining:</strong> ${order.remaining_items}</p>
                <p><strong>Value:</strong> ${order.total_value} RON</p>
                <p><strong>Date:</strong> ${formatDate(order.order_date)}</p>
            </div>
            
            <div class="progress-container">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${calculateProgress(order)}%"></div>
                </div>
                <div class="progress-text">${calculateProgress(order)}% Complete</div>
                <small>${order.total_items - order.remaining_items} / ${order.total_items} items picked</small>
            </div>
            
            <div class="order-actions">
                ${order.status === 'pending' ? 
                    `<button class="action-btn btn-primary" onclick="startPicking('${order.order_number}')">
                        <span class="material-symbols-outlined">play_arrow</span>
                        Start Picking
                    </button>` :
                    `<button class="action-btn btn-secondary" onclick="continuePicking('${order.order_number}')">
                        <span class="material-symbols-outlined">resume</span>
                        Continue
                    </button>`
                }
                <button class="action-btn btn-secondary" onclick="viewOrderDetails(${order.id})">
                    <span class="material-symbols-outlined">visibility</span>
                    Details
                </button>
            </div>
        </div>
    `;
}

/**
 * Display order details in modal
 */
function displayOrderDetails(order) {
    const detailsHtml = `
        <div class="order-details">
            <div class="details-grid">
                <div class="detail-section">
                    <h4>Informații Comandă</h4>
                    <p><strong>Număr:</strong> ${order.order_number}</p>
                    <p><strong>Data:</strong> ${formatDateTime(order.order_date)}</p>
                    <p><strong>Status:</strong> ${order.status_label}</p>
                    ${order.tracking_number ? `<p><strong>Urmărire:</strong> ${order.tracking_number}</p>` : ''}
                    <p><strong>Valoare:</strong> ${order.total_value} RON</p>
                </div>
                <div class="detail-section">
                    <h4>Informații Client</h4>
                    <p><strong>Nume:</strong> ${order.customer_name}</p>
                    ${order.customer_email ? `<p><strong>Email:</strong> ${order.customer_email}</p>` : ''}
                    ${order.shipping_address ? `<p><strong>Adresă:</strong><br>${order.shipping_address.replace(/\n/g, '<br>')}</p>` : ''}
                </div>
            </div>
            
            <div class="progress-section">
                <h4>Progres Colectare</h4>
                <div class="progress-summary">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${order.progress.progress_percent}%"></div>
                    </div>
                    <div class="progress-stats">
                        <span>Procentaj: ${order.progress.progress_percent}%</span> |
                        <span>Preluat: ${order.progress.total_quantity_picked}</span> |
                        <span>Total: ${order.progress.total_quantity_ordered}</span> |
                        <span>Rămas: ${order.progress.total_remaining}</span>
                    </div>
                </div>
            </div>
            
            <div class="items-section">
                <h4>Produse Comandate</h4>
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>Produs</th>
                            <th>Comandat</th>
                            <th>Preluat</th>
                            <th>Rămas</th>
                            <th>Status</th>
                            <th>Preț Unitar</th>
                            <th>Total Linie</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${order.items && order.items.length > 0 ? order.items.map(item => `
                            <tr class="${item.is_complete ? 'item-complete' : 'item-pending'}">
                                <td>
                                    <div class="product-info">
                                        <strong>${item.sku}</strong><br>
                                        <small>${item.product_name}</small>
                                    </div>
                                </td>
                                <td class="text-center">${item.quantity_ordered}</td>
                                <td class="text-center">${item.picked_quantity}</td>
                                <td class="text-center">${item.remaining_to_pick}</td>
                                <td class="text-center">
                                    <span class="status-badge ${item.is_complete ? 'status-complete' : 'status-pending'}">
                                        ${item.is_complete ? 'Complet' : 'În așteptare'}
                                    </span>
                                </td>
                                <td class="text-right">${item.unit_price} RON</td>
                                <td class="text-right">${item.line_total} RON</td>
                            </tr>
                        `).join('') : '<tr><td colspan="7">Nu există produse în această comandă.</td></tr>'}
                    </tbody>
                </table>
            </div>
            
            ${order.notes ? `
                <div class="notes-section">
                    <h4>Notițe</h4>
                    <p>${order.notes.replace(/\n/g, '<br>')}</p>
                </div>
            ` : ''}
        </div>
    `;
    
    document.getElementById('orderDetailsContent').innerHTML = detailsHtml;
}

// ===== MODAL FUNCTIONS =====

function openCreateModal() {
    document.getElementById('createOrderForm').reset();
    orderItemCount = 0;
    document.getElementById('orderItems').innerHTML = '';
    addOrderItem();
    document.getElementById('createOrderModal').style.display = 'block';
}

function closeCreateModal() {
    document.getElementById('createOrderModal').style.display = 'none';
}

function openEditModal(orderId) {
    fetch(`${API_BASE}/warehouse/order_details.php?id=${orderId}`)
        .then(res => res.json())
        .then(result => {
            if (result.status === 'success') {
                const order = result.data;
                document.getElementById('edit_order_id').value = order.id;
                document.getElementById('edit_customer_name').value = order.customer_name || '';
                document.getElementById('edit_customer_email').value = order.customer_email || '';
                document.getElementById('edit_shipping_address').value = order.shipping_address || '';
                document.getElementById('edit_status').value = order.status;
                document.getElementById('edit_tracking_number').value = order.tracking_number || '';
                document.getElementById('edit_notes').value = order.notes || '';
                document.getElementById('editOrderModal').style.display = 'block';
            } else {
                throw new Error(result.message || 'Failed to load order');
            }
        })
        .catch(err => {
            console.error('Error loading order for edit:', err);
            showMessage('Error loading order for edit: ' + err.message, true);
        });
}

function closeEditModal() {
    document.getElementById('editOrderModal').style.display = 'none';
}

function closeDetailsModal() {
    document.getElementById('orderDetailsModal').style.display = 'none';
}

function confirmDelete(orderId, orderNumber) {
    document.getElementById('deleteOrderId').value = orderId;
    document.getElementById('deleteOrderNumber').textContent = orderNumber;
    document.getElementById('deleteModal').style.display = 'block';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// ===== ORDER ITEM MANAGEMENT =====

function addOrderItem() {
    orderItemCount++;
    const itemHtml = `
        <div class="order-item" id="orderItem${orderItemCount}">
            <div class="item-grid">
                <select name="items[${orderItemCount}][product_id]" class="form-input" required onchange="updatePrice(this)">
                    <option value="">Selectați produsul</option>
                    <!-- Products will be populated by PHP -->
                </select>
                <input type="number" name="items[${orderItemCount}][quantity]" class="form-input" placeholder="Cantitate" min="1" required>
                <input type="number" name="items[${orderItemCount}][unit_price]" class="form-input" placeholder="Preț unitar" step="0.01" min="0" required>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeOrderItem(${orderItemCount})">
                    <span class="material-symbols-outlined">delete</span>
                </button>
            </div>
        </div>
    `;
    document.getElementById('orderItems').insertAdjacentHTML('beforeend', itemHtml);
}

function removeOrderItem(itemId) {
    const element = document.getElementById(`orderItem${itemId}`);
    if (element) element.remove();
}

function updatePrice(selectElement) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const price = selectedOption.getAttribute('data-price');
    const priceInput = selectElement.closest('.item-grid').querySelector('input[type="number"][name*="unit_price"]');
    if (price && priceInput) {
        priceInput.value = price;
    }
}

// ===== UTILITY FUNCTIONS =====

function showLoading(show) {
    const loadingEl = document.getElementById('loading');
    if (loadingEl) {
        loadingEl.style.display = show ? 'block' : 'none';
    }
}

function hideOrdersGrid() {
    const ordersGrid = document.getElementById('orders-grid');
    const noOrdersEl = document.getElementById('no-orders');
    if (ordersGrid) ordersGrid.style.display = 'none';
    if (noOrdersEl) noOrdersEl.style.display = 'none';
}

function showErrorState(message) {
    const noOrdersEl = document.getElementById('no-orders');
    if (noOrdersEl) {
        noOrdersEl.innerHTML = `
            <div class="empty-state">
                <span class="material-symbols-outlined">error</span>
                <h3>Error Loading Orders</h3>
                <p><strong>Error:</strong> ${message}</p>
                <p><strong>API URL:</strong> ${API_BASE}/warehouse/get_orders.php</p>
                <p><strong>Troubleshooting:</strong><br>
                • Check browser console for detailed errors<br>
                • Verify API endpoints are accessible<br>
                • Check PHP error logs<br>
                • Ensure database columns exist (assigned_to, barcode)
                </p>
                <button class="btn btn-primary" onclick="loadOrders()">
                    <span class="material-symbols-outlined">refresh</span>
                    Try Again
                </button>
            </div>
        `;
        noOrdersEl.style.display = 'block';
    }
}

function showMessage(message, isError) {
    console.log(isError ? '❌' : '✅', message);
    
    // You can implement a toast notification system here
    // For now, we'll just log to console
}

function getStatusLabel(status) {
    const statusLabels = {
        'pending': 'În Așteptare',
        'processing': 'În Procesare',
        'assigned': 'Asignat',
        'completed': 'Finalizat',
        'shipped': 'Expediat',
        'cancelled': 'Anulat'
    };
    return statusLabels[status.toLowerCase()] || status;
}

function calculateProgress(order) {
    if (!order.total_items || order.total_items === 0) return 0;
    const picked = order.total_items - order.remaining_items;
    return Math.round((picked / order.total_items) * 100);
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('ro-RO');
}

function formatDateTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('ro-RO');
}

function debounceFilter(form) {
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        form.submit();
    }, 500);
}

// ===== EVENT LISTENERS =====

// Modal close on outside click
window.onclick = function(event) {
    const modals = ['createOrderModal', 'editOrderModal', 'orderDetailsModal', 'deleteModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
};

// Close modals on escape key
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeCreateModal();
        closeEditModal();
        closeDetailsModal();
        closeDeleteModal();
    }
});

// Export functions for global access
window.loadOrders = loadOrders;
window.startPicking = startPicking;
window.continuePicking = continuePicking;
window.viewOrderDetails = viewOrderDetails;
window.openCreateModal = openCreateModal;
window.closeCreateModal = closeCreateModal;
window.openEditModal = openEditModal;
window.closeEditModal = closeEditModal;
window.closeDetailsModal = closeDetailsModal;
window.confirmDelete = confirmDelete;
window.closeDeleteModal = closeDeleteModal;
window.addOrderItem = addOrderItem;
window.removeOrderItem = removeOrderItem;
window.updatePrice = updatePrice;
window.filterOrders = filterOrders;
window.debounceFilter = debounceFilter;