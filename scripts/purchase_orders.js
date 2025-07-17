// File: scripts/purchase_orders.js
// Complete JavaScript functionality for purchase orders page with stock purchase

document.addEventListener('DOMContentLoaded', function() {
    console.log('Purchase Orders page loaded');
    initializeDateFields();
    initializeAmountCalculations();
    initializeStockPurchase();
    attachFormValidation();
    if (typeof initializePurchaseOrdersExisting === 'function') {
        initializePurchaseOrdersExisting();
    }
    initializeReceivingFunctionality();
});

// Global variables for stock purchase
let productItemIndex = 1;
let purchaseOrdersManager = null;
let expandedRows = new Set();
let ordersData = [];
let currentFilters = {
    status: '',
    seller_id: '',
    receiving_status: ''
};

function initializeReceivingFunctionality() {
    purchaseOrdersManager = new PurchaseOrdersReceivingManager();
    console.log('🚛 Purchase Orders Receiving functionality initialized');
}

// Initialize date fields with today's date
function initializeDateFields() {
    const today = new Date().toISOString().split('T')[0];
    
    // Set delivery date to today by default
    const deliveryDateField = document.getElementById('delivery_date');
    if (deliveryDateField) {
        deliveryDateField.value = today;
    }
    
    // Set invoice date to today by default
    const invoiceDateField = document.getElementById('invoice_date');
    if (invoiceDateField) {
        invoiceDateField.value = today;
    }
    
    // Set minimum delivery date to tomorrow
    const expectedDeliveryDate = document.getElementById('expected_delivery_date');
    if (expectedDeliveryDate) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        expectedDeliveryDate.value = tomorrow.toISOString().split('T')[0];
    }
}

// Initialize amount calculations and form interactions
function initializeAmountCalculations() {
    // Initialize any amount calculations here if needed
    console.log('Amount calculations initialized');
}

// Stock Purchase functionality
function initializeStockPurchase() {
    // Initialize existing product selectors if they exist
    const selectors = document.querySelectorAll('.existing-product-select');
    selectors.forEach(selector => {
        selector.addEventListener('change', function() {
            const index = this.closest('.product-item').getAttribute('data-index');
            selectExistingProduct(index, this);
        });
    });
}

// Attach form validation
function attachFormValidation() {
    const stockPurchaseForm = document.getElementById('stockPurchaseForm');
    if (stockPurchaseForm) {
        stockPurchaseForm.addEventListener('submit', function(e) {
            if (!validateStockPurchaseForm()) {
                e.preventDefault();
            }
        });
        console.log('Form validation attached');
    }
}

// Stock Purchase Modal functions
function openStockPurchaseModal() {
    document.getElementById('stockPurchaseModal').classList.add('show');
    // Set minimum delivery date to tomorrow
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const expectedDeliveryDate = document.getElementById('expected_delivery_date');
    if (expectedDeliveryDate) {
        expectedDeliveryDate.value = tomorrow.toISOString().split('T')[0];
    }
}

function closeStockPurchaseModal() {
    document.getElementById('stockPurchaseModal').classList.remove('show');
    document.getElementById('stockPurchaseForm').reset();
    // Reset product items to just one
    resetProductItems();
}

function resetProductItems() {
    const container = document.getElementById('product-items');
    const firstItem = container.querySelector('.product-item');
    
    // Remove all items except the first one
    const allItems = container.querySelectorAll('.product-item');
    for (let i = 1; i < allItems.length; i++) {
        allItems[i].remove();
    }
    
    // Reset the first item
    if (firstItem) {
        const productName = firstItem.querySelector('.product-name');
        const productCode = firstItem.querySelector('.product-code');
        const quantity = firstItem.querySelector('.quantity');
        const unitPrice = firstItem.querySelector('.unit-price');
        const itemTotal = firstItem.querySelector('.item-total');
        const purchasableProductId = firstItem.querySelector('.purchasable-product-id');
        const existingProductSelect = firstItem.querySelector('.existing-product-select');
        const removeButton = firstItem.querySelector('button[onclick*="removeProductItem"]');
        
        if (productName) productName.value = '';
        if (productCode) productCode.value = '';
        if (quantity) quantity.value = '';
        if (unitPrice) unitPrice.value = '';
        if (itemTotal) itemTotal.value = '';
        if (purchasableProductId) purchasableProductId.value = '';
        if (existingProductSelect) existingProductSelect.value = '';
        if (removeButton) removeButton.style.display = 'none';
    }
    
    productItemIndex = 1;
    calculateOrderTotal();
}

// FIXED: updateSellerContact function with proper null checks
// Debug the actual function execution
function updateSellerContact() {
    // Look specifically inside the modal, not the whole page
    const modal = document.getElementById('stockPurchaseModal');
    if (!modal) {
        console.error('Modal not found');
        return;
    }
    
    const sellerSelect = modal.querySelector('#seller_id');
    const emailField = modal.querySelector('#email_recipient');
    
    if (!sellerSelect) {
        console.error('Seller select element not found in modal');
        return;
    }
    
    if (!emailField) {
        console.error('Email recipient field not found in modal');
        return;
    }
    
    const selectedOption = sellerSelect.options[sellerSelect.selectedIndex];
    
    if (selectedOption && selectedOption.value && selectedOption.value !== '') {
        const email = selectedOption.getAttribute('data-email');
        const contact = selectedOption.getAttribute('data-contact');
        const phone = selectedOption.getAttribute('data-phone');
        
        console.log('Setting email to:', email); // Debug log
        
        // Set the email field value
        emailField.value = email || '';
        
        // Optional: Show contact info in console
        if (contact || phone) {
            let contactInfo = [];
            if (contact) contactInfo.push(`Contact: ${contact}`);
            if (phone) contactInfo.push(`Tel: ${phone}`);
            console.log('Seller contact info:', contactInfo.join(', '));
        }
    } else {
        emailField.value = '';
    }
}

function addProductItem() {
    const container = document.getElementById('product-items');
    const newItem = createProductItem(productItemIndex);
    container.appendChild(newItem);
    
    // Show remove button on all items when there's more than one
    updateRemoveButtons();
    
    productItemIndex++;
}

function createProductItem(index) {
    const template = `
        <div class="product-item" data-index="${index}">
            <div class="product-item-header">
                <h5>Produs ${index + 1}</h5>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeProductItem(${index})">
                    <span class="material-symbols-outlined">delete</span>
                </button>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Selectează Produs Existent</label>
                    <select class="form-control existing-product-select" onchange="selectExistingProduct(${index}, this)">
                        <option value="">Sau creează produs nou...</option>
                        ${generateProductOptions()}
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Produs Intern (opțional)</label>
                    <select class="form-control internal-product-select" name="items[${index}][internal_product_id]">
                        ${generateInternalProductOptions()}
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Nume Produs *</label>
                    <input type="text" name="items[${index}][product_name]" class="form-control product-name" required 
                           placeholder="Nume produs de la furnizor">
                    <input type="hidden" name="items[${index}][purchasable_product_id]" class="purchasable-product-id">
                </div>
                <div class="form-group">
                    <label>Cod Produs</label>
                    <input type="text" name="items[${index}][product_code]" class="form-control product-code" 
                           placeholder="Cod produs furnizor">
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Cantitate *</label>
                    <input type="number" name="items[${index}][quantity]" class="form-control quantity" 
                           step="0.001" min="0.001" required onchange="calculateItemTotal(${index})">
                </div>
                <div class="form-group">
                    <label>Preț Unitar (RON) *</label>
                    <input type="number" name="items[${index}][unit_price]" class="form-control unit-price" 
                           step="0.01" min="0.01" required onchange="calculateItemTotal(${index})">
                </div>
                <div class="form-group">
                    <label>Total</label>
                    <input type="text" class="form-control item-total" readonly>
                </div>
            </div>
            <div class="form-group">
                <label>Descriere</label>
                <textarea name="items[${index}][description]" class="form-control" rows="2" 
                          placeholder="Descriere suplimentară..."></textarea>
            </div>
        </div>
    `;
    
    const div = document.createElement('div');
    div.innerHTML = template;
    return div.firstElementChild;
}

function generateProductOptions() {
    // Get the purchasable products from the page data
    if (typeof window.purchasableProducts !== 'undefined') {
        let options = '';
        window.purchasableProducts.forEach(product => {
            options += `<option value="${product.id}"
                                data-name="${escapeHtml(product.supplier_product_name)}"
                                data-code="${escapeHtml(product.supplier_product_code || '')}"
                                data-price="${product.last_purchase_price || ''}"
                                data-internal-id="${product.internal_product_id || ''}">
                            ${escapeHtml(product.supplier_product_name)}
                            ${product.supplier_product_code ? `(${escapeHtml(product.supplier_product_code)})` : ''}
                        </option>`;
        });
        return options;
    }
    return '';
}

function generateInternalProductOptions() {
    if (typeof window.allProducts !== 'undefined') {
        let options = '<option value="">-- Produs intern --</option>';
        window.allProducts.forEach(p => {
            options += `<option value="${p.product_id}">${escapeHtml(p.name)} (${escapeHtml(p.sku)})</option>`;
        });
        return options;
    }
    return '<option value="">-- Produs intern --</option>';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function removeProductItem(index) {
    const item = document.querySelector(`[data-index="${index}"]`);
    if (item) {
        item.remove();
        updateRemoveButtons();
        calculateOrderTotal();
    }
}

function updateRemoveButtons() {
    const items = document.querySelectorAll('.product-item');
    items.forEach((item, index) => {
        const removeButton = item.querySelector('button[onclick*="removeProductItem"]');
        if (removeButton) {
            removeButton.style.display = items.length > 1 ? 'inline-block' : 'none';
        }
        
        // Update product number
        const header = item.querySelector('.product-item-header h5');
        if (header) {
            header.textContent = `Produs ${index + 1}`;
        }
    });
}

function selectExistingProduct(index, selectElement) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const item = selectElement.closest('.product-item');
    
    if (selectedOption.value && item) {
        const productName = selectedOption.getAttribute('data-name');
        const productCode = selectedOption.getAttribute('data-code');
        const lastPrice = selectedOption.getAttribute('data-price');
        
        // Fill in the form fields
        const productNameField = item.querySelector('.product-name');
        const productCodeField = item.querySelector('.product-code');
        const unitPriceField = item.querySelector('.unit-price');
        const purchasableProductIdField = item.querySelector('.purchasable-product-id');
        const internalSelect = item.querySelector('.internal-product-select');
        
        if (productNameField) productNameField.value = productName || '';
        if (productCodeField) productCodeField.value = productCode || '';
        if (unitPriceField) unitPriceField.value = lastPrice || '';
        if (purchasableProductIdField) purchasableProductIdField.value = selectedOption.value;
        if (internalSelect) internalSelect.value = selectedOption.getAttribute('data-internal-id') || '';
        
        // Calculate total if quantity is set
        const quantityField = item.querySelector('.quantity');
        if (quantityField && quantityField.value) {
            calculateItemTotal(index);
        }
    } else if (item) {
        // Clear fields if "create new" is selected
        const productNameField = item.querySelector('.product-name');
        const productCodeField = item.querySelector('.product-code');
        const unitPriceField = item.querySelector('.unit-price');
        const purchasableProductIdField = item.querySelector('.purchasable-product-id');
        const internalSelect = item.querySelector('.internal-product-select');
        const itemTotalField = item.querySelector('.item-total');
        
        if (productNameField) productNameField.value = '';
        if (productCodeField) productCodeField.value = '';
        if (unitPriceField) unitPriceField.value = '';
        if (purchasableProductIdField) purchasableProductIdField.value = '';
        if (internalSelect) internalSelect.value = '';
        if (itemTotalField) itemTotalField.value = '';
        
        calculateOrderTotal();
    }
}

function calculateItemTotal(index) {
    let item = document.querySelector(`[data-index="${index}"]`);
    
    if (!item) {
        // Fallback: try to find by input element
        const inputElement = typeof index === 'object' ? index : null;
        if (inputElement) {
            item = inputElement.closest('.product-item');
        }
    }
    
    if (!item) return;
    
    const quantityField = item.querySelector('.quantity');
    const unitPriceField = item.querySelector('.unit-price');
    const itemTotalField = item.querySelector('.item-total');
    
    if (quantityField && unitPriceField && itemTotalField) {
        const quantity = parseFloat(quantityField.value) || 0;
        const unitPrice = parseFloat(unitPriceField.value) || 0;
        const total = quantity * unitPrice;
        
        itemTotalField.value = total.toFixed(2) + ' RON';
    }
    
    // Update order total
    calculateOrderTotal();
}

function calculateOrderTotal() {
    const items = document.querySelectorAll('.product-item');
    let orderTotal = 0;
    
    items.forEach(item => {
        const quantityField = item.querySelector('.quantity');
        const unitPriceField = item.querySelector('.unit-price');
        
        if (quantityField && unitPriceField) {
            const quantity = parseFloat(quantityField.value) || 0;
            const unitPrice = parseFloat(unitPriceField.value) || 0;
            orderTotal += quantity * unitPrice;
        }
    });
    
    const orderTotalElement = document.getElementById('order-total');
    if (orderTotalElement) {
        orderTotalElement.textContent = orderTotal.toFixed(2) + ' RON';
    }
}

// FIXED: Form validation with proper checks
function validateStockPurchaseForm() {
    // Look specifically inside the modal, not the whole page
    const modal = document.getElementById('stockPurchaseModal');
    if (!modal) {
        console.error('Modal not found during validation');
        return false;
    }
    
    // Check if seller is selected (in the modal)
    const sellerSelect = modal.querySelector('#seller_id');
    if (!sellerSelect || !sellerSelect.value || sellerSelect.value === '') {
        alert('Te rog selectează un furnizor.');
        if (sellerSelect) sellerSelect.focus();
        return false;
    }

    // Email subject and body required
    const subjectField = modal.querySelector('#email_subject');
    const messageField = modal.querySelector('#custom_message');
    if (!subjectField || subjectField.value.trim() === '') {
        alert('Subiectul emailului este obligatoriu.');
        subjectField.focus();
        return false;
    }
    if (!messageField || messageField.value.trim() === '') {
        alert('Mesajul emailului este obligatoriu.');
        messageField.focus();
        return false;
    }
    
    console.log('Seller validation passed, selected:', sellerSelect.value);
    
    // Check if at least one product is added with valid data
    const items = modal.querySelectorAll('.product-item'); // Also target modal specifically
    let hasValidItems = false;
    
    items.forEach((item) => {
        const productNameField = item.querySelector('.product-name');
        const quantityField = item.querySelector('.quantity');
        const unitPriceField = item.querySelector('.unit-price');
        
        if (productNameField && quantityField && unitPriceField) {
            const name = productNameField.value.trim();
            const qty = parseFloat(quantityField.value) || 0;
            const price = parseFloat(unitPriceField.value) || 0;
            
            if (name && qty > 0 && price > 0) {
                hasValidItems = true;
            }
        }
    });
    
    if (!hasValidItems) {
        alert('Te rog adaugă cel puțin un produs valid cu cantitate și preț.');
        return false;
    }
    
    console.log('All validation passed!');
    return true;
}

// Generic modal functions
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('show');
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
        
        // Reset forms when closing modals
        const form = modal.querySelector('form');
        if (form) {
            form.reset();
        }
        
        // Clear any dynamic content
        if (modalId === 'deliveryModal') {
            const deliveryItems = document.getElementById('delivery-items');
            if (deliveryItems) deliveryItems.innerHTML = '';
        }
        if (modalId === 'invoiceModal') {
            const invoiceItems = document.getElementById('invoice-items');
            if (invoiceItems) invoiceItems.innerHTML = '';
        }
    }
}

// Status update modal
function openStatusModal(orderId, currentStatus) {
    document.getElementById('statusOrderId').value = orderId;
    document.getElementById('updateStatus').value = currentStatus;
    openModal('statusModal');
}

// Send email modal
function openSendEmailModal(orderId, supplierName) {
    document.getElementById('emailOrderId').value = orderId;
    document.getElementById('email_recipient_send').value = '';
    openModal('sendEmailModal');
}

// Delivery modal
function openDeliveryModal(orderId) {
    document.getElementById('deliveryOrderId').value = orderId;
    
    // Set delivery date to today
    const today = new Date().toISOString().split('T')[0];
    const deliveryDate = document.getElementById('delivery_date');
    if (deliveryDate) {
        deliveryDate.value = today;
    }
    
    // Load order items for delivery recording
    loadOrderItemsForDelivery(orderId);
    
    openModal('deliveryModal');
}

// Invoice modal
function openInvoiceModal(orderId) {
    document.getElementById('invoiceOrderId').value = orderId;
    
    // Set invoice date to today
    const today = new Date().toISOString().split('T')[0];
    const invoiceDate = document.getElementById('invoice_date');
    if (invoiceDate) {
        invoiceDate.value = today;
    }
    
    // Load order items for invoice recording
    loadOrderItemsForInvoice(orderId);
    
    openModal('invoiceModal');
}

// Load order items for delivery recording
function loadOrderItemsForDelivery(orderId) {
    const container = document.getElementById('delivery-items');
    if (container) {
        container.innerHTML = '<div class="loading">Se încarcă produsele...</div>';
        
        // TODO: Make AJAX call to get order items
        // For now, just clear the loading message
        setTimeout(() => {
            container.innerHTML = '';
        }, 200);
    }
}

// Load order items for invoice recording
function loadOrderItemsForInvoice(orderId) {
    const container = document.getElementById('invoice-items');
    if (container) {
        container.innerHTML = '<div class="loading">Se încarcă produsele...</div>';
        
        // TODO: Make AJAX call to get order items
        // For now, just clear the loading message
        setTimeout(() => {
            container.innerHTML = '';
        }, 200);
    }
}

// Calculate item total for invoice items
function calculateInvoiceItemTotal(inputElement) {
    const item = inputElement.closest('.invoice-item');
    if (!item) return;
    
    const quantityField = item.querySelector('.invoice-quantity');
    const unitPriceField = item.querySelector('.unit-price');
    const itemTotalField = item.querySelector('.item-total');
    
    if (quantityField && unitPriceField && itemTotalField) {
        const quantity = parseFloat(quantityField.value) || 0;
        const unitPrice = parseFloat(unitPriceField.value) || 0;
        const total = quantity * unitPrice;
        
        itemTotalField.value = total.toFixed(2) + ' RON';
        
        // Update invoice total
        updateInvoiceTotal();
    }
}

function updateInvoiceTotal() {
    const items = document.querySelectorAll('.invoice-item');
    let invoiceTotal = 0;
    
    items.forEach(item => {
        const quantityField = item.querySelector('.invoice-quantity');
        const unitPriceField = item.querySelector('.unit-price');
        
        if (quantityField && unitPriceField) {
            const quantity = parseFloat(quantityField.value) || 0;
            const unitPrice = parseFloat(unitPriceField.value) || 0;
            invoiceTotal += quantity * unitPrice;
        }
    });
    
    const totalAmountField = document.getElementById('total_amount');
    if (totalAmountField) {
        totalAmountField.value = invoiceTotal.toFixed(2);
    }
}

// purhcase orders receiving manager  class
class PurchaseOrdersReceivingManager {
    constructor() {
        this.bindEvents();
        this.loadPurchaseOrdersWithReceiving();
    }

    bindEvents() {
        // filter change handlers
        const statusFilter = document.getElementById('status');
        const sellerFilter = document.getElementById('seller_id');
        const receivingStatusFilter = document.getElementById('receiving-status-filter');

        if (statusFilter) {
            statusFilter.addEventListener('change', () =>  this.handleFilterChange());
        }

        if (sellerFilter) {
            sellerFilter.addEventListener('change', () => this.handleFilterChange());
        }

        if (receivingStatusFilter) {
            receivingStatusFilter.addEventListener('change', () => this.handleFilterChange());
        }

        // modal close events
        document.addEventListener('click', (e) => {
            if (e.target.id === 'receiving-details-modal') {
                this.closeReceivingModal();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeReceivingModal();
            }
        });
    }

    handleFilterChange() {
        // update current filters
        currentFilters.status = document.getElementById('status')?.value || '';
        currentFilters.seller_id = document.getElementById('selller_id')?.value || '';
        currentFilters.receiving_status = document.getElementById('receiving-status-filter')?.value || '';

        // reload data with new filters
        this.loadPurchaseOrdersWithReceiving();
    }

    async loadPurchaseOrdersWithReceiving() {
        try {
            this.showLoading(true);
            
            // Build query parameters
            const params = new URLSearchParams();
            if (currentFilters.status) params.append('status', currentFilters.status);
            if (currentFilters.seller_id) params.append('seller_id', currentFilters.seller_id);
            if (currentFilters.receiving_status) params.append('receiving_status', currentFilters.receiving_status);
            params.append('limit', '100');
            
            const response = await fetch(`api/receiving/purchase_order_summary.php?${params.toString()}`);
            const data = await response.json();
            
            if (data.success) {
                ordersData = data.data;
                this.renderOrdersTable(data.data);
                this.updateSummaryStats(data.summary_stats);
            } else {
                throw new Error(data.message || 'Failed to load purchase orders');
            }
            
        } catch (error) {
            console.error('Error loading purchase orders:', error);
            this.showError('Eroare la încărcarea comenzilor: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }

    renderOrdersTable(orders) {
        const tbody = document.getElementById('orders-tbody');
        if (!tbody) return;
        
        if (orders.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="12" class="text-center">
                        <div style="padding: 40px; color: var(--text-secondary);">
                            <span class="material-symbols-outlined" style="font-size: 48px; opacity: 0.5;">inventory_2</span>
                            <p>Nu există comenzi de achiziție care să corespundă filtrelor selectate</p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = orders.map(order => this.renderOrderRow(order)).join('');
    }
    
    renderOrderRow(order) {
        const receivingStatus = order.receiving_summary.status;
        const progressPercent = order.receiving_summary.receiving_progress_percent;
        const hasDiscrepancies = order.discrepancies_summary.has_pending_discrepancies;
        
        return `
            <tr data-order-id="${order.id}">
                <td>
                    <button class="expand-btn" onclick="purchaseOrdersManager.toggleRowExpansion(${order.id})">
                        <span class="material-symbols-outlined">expand_more</span>
                    </button>
                </td>
                <td>
                    <strong>${this.escapeHtml(order.order_number)}</strong>
                </td>
                <td>${this.escapeHtml(order.supplier_name)}</td>
                <td>${order.total_amount} ${order.currency}</td>
                <td>
                    <span class="status-badge status-${order.po_status.toLowerCase()}">
                        ${this.translateStatus(order.po_status)}
                    </span>
                </td>
                <td>
                    <span class="receiving-status-badge receiving-status-${receivingStatus}">
                        ${this.translateReceivingStatus(receivingStatus)}
                    </span>
                </td>
                <td>
                    <div class="progress-container">
                        <div class="progress-bar progress-${this.getProgressClass(progressPercent)}" 
                             style="width: ${progressPercent}%"></div>
                    </div>
                    <div class="progress-text">${progressPercent}%</div>
                </td>
                <td>
                    <div class="discrepancy-indicator ${hasDiscrepancies ? 'has-discrepancies' : 'no-discrepancies'}">
                        <span class="material-symbols-outlined">
                            ${hasDiscrepancies ? 'warning' : 'check_circle'}
                        </span>
                        ${order.discrepancies_summary.pending_discrepancies || 0}
                    </div>
                </td>
                <td>${this.formatDate(order.created_at)}</td>
                <td>
                    ${order.pdf_path ? `<a href="storage/purchase_order_pdfs/${order.pdf_path}" target="_blank">PDF</a>` : '-'}
                </td>
                <td>${order.actual_delivery_date || '-'}</td>
                <td>
                    <div class="action-buttons">
                        <button class="btn btn-info btn-sm" onclick="purchaseOrdersManager.showReceivingDetails(${order.id})" 
                                title="Detalii Primire">
                            <span class="material-symbols-outlined">visibility</span>
                        </button>
                    </div>
                </td>
            </tr>
            <tr class="expandable-row" data-order-id="${order.id}" style="display: none;">
                <td colspan="12">
                    <div class="expandable-content" id="expandable-${order.id}">
                        <div class="loading-spinner">
                            <span class="material-symbols-outlined spinning">refresh</span>
                            Se încarcă detaliile...
                        </div>
                    </div>
                </td>
            </tr>
        `;
    }
    
    async toggleRowExpansion(orderId) {
        const row = document.querySelector(`tr.expandable-row[data-order-id="${orderId}"]`);
        const expandBtn = document.querySelector(`tr[data-order-id="${orderId}"] .expand-btn`);
        const content = document.getElementById(`expandable-${orderId}`);
        
        if (!row || !expandBtn || !content) return;
        
        if (expandedRows.has(orderId)) {
            // Collapse
            row.style.display = 'none';
            content.classList.remove('expanded');
            expandBtn.classList.remove('expanded');
            expandedRows.delete(orderId);
        } else {
            // Expand
            row.style.display = 'table-row';
            expandBtn.classList.add('expanded');
            expandedRows.add(orderId);
            
            // Load detailed receiving data
            await this.loadReceivingDetails(orderId, content);
            content.classList.add('expanded');
        }
    }
    
    async loadReceivingDetails(orderId, contentElement) {
        try {
            const response = await fetch(`api/receiving/purchase_order_details.php?order_id=${orderId}`);
            const data = await response.json();
            
            if (data.success) {
                contentElement.innerHTML = this.renderReceivingDetails(data);
            } else {
                contentElement.innerHTML = `<p class="error">Eroare: ${data.message}</p>`;
            }
        } catch (error) {
            contentElement.innerHTML = `<p class="error">Eroare la încărcarea detaliilor: ${error.message}</p>`;
        }
    }
    
    renderReceivingDetails(data) {
        return `
            <div class="receiving-details-grid">
                <div class="receiving-section">
                    <h4><span class="material-symbols-outlined">inventory</span>Sesiuni de Primire</h4>
                    ${this.renderReceivingSessions(data.receiving_sessions)}
                </div>
                <div class="receiving-section">
                    <h4><span class="material-symbols-outlined">checklist</span>Detalii Produse</h4>
                    ${this.renderItemsDetails(data.items_detail)}
                </div>
            </div>
            ${data.discrepancies.length > 0 ? `
                <div class="receiving-section">
                    <h4><span class="material-symbols-outlined">warning</span>Discrepanțe</h4>
                    ${this.renderDiscrepancies(data.discrepancies)}
                </div>
            ` : ''}
            <div class="summary-stats">
                ${this.renderSummaryStats(data.summary_stats)}
            </div>
        `;
    }
    
    renderReceivingSessions(sessions) {
        if (sessions.length === 0) {
            return '<p>Nu există sesiuni de primire înregistrate.</p>';
        }
        
        return `
            <ul class="sessions-list">
                ${sessions.map(session => `
                    <li class="session-item">
                        <div class="session-header">
                            <span class="session-number">${session.session_number}</span>
                            <span class="session-status ${session.status}">${session.status}</span>
                        </div>
                        <div class="session-details">
                            <strong>Document:</strong> ${session.supplier_document_number}<br>
                            <strong>Primit de:</strong> ${session.received_by_name}<br>
                            <strong>Data:</strong> ${this.formatDateTime(session.completed_at || session.created_at)}
                        </div>
                    </li>
                `).join('')}
            </ul>
        `;
    }
    
    renderItemsDetails(items) {
        if (items.length === 0) {
            return '<p>Nu există detalii despre produse.</p>';
        }
        
        return `
            <table class="receiving-items-table">
                <thead>
                    <tr>
                        <th>Produs</th>
                        <th>Comandat</th>
                        <th>Primit</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map(item => `
                        <tr>
                            <td>
                                <strong>${this.escapeHtml(item.product_name)}</strong><br>
                                <small>${item.sku}</small>
                            </td>
                            <td>${item.ordered_quantity}</td>
                            <td>${item.received_quantity}</td>
                            <td>
                                <span class="item-status-${item.receiving_status}">
                                    ${this.translateItemStatus(item.receiving_status)}
                                </span>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }
    
    renderDiscrepancies(discrepancies) {
        return `
            <ul class="discrepancies-list">
                ${discrepancies.map(disc => `
                    <li class="discrepancy-item">
                        <div class="discrepancy-header">
                            <span class="discrepancy-type">${disc.discrepancy_type}</span>
                            <span class="discrepancy-status ${disc.resolution_status}">${disc.resolution_status}</span>
                        </div>
                        <div>
                            <strong>${this.escapeHtml(disc.product_name)}</strong><br>
                            ${disc.description}
                        </div>
                    </li>
                `).join('')}
            </ul>
        `;
    }
    
    renderSummaryStats(stats) {
        return `
            <div class="stat-card">
                <div class="stat-value">${stats.total_sessions}</div>
                <div class="stat-label">Sesiuni</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${stats.received_items}</div>
                <div class="stat-label">Produse</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${stats.pending_discrepancies}</div>
                <div class="stat-label">Discrepanțe</div>
            </div>
        `;
    }
    
    async showReceivingDetails(orderId) {
        const modal = document.getElementById('receiving-details-modal');
        if (!modal) {
            // Create modal if it doesn't exist
            this.createReceivingModal();
        }
        
        const modalBody = document.getElementById('modal-body');
        modalBody.innerHTML = `
            <div class="loading-spinner">
                <span class="material-symbols-outlined spinning">refresh</span>
                Se încarcă detaliile...
            </div>
        `;
        
        document.getElementById('receiving-details-modal').style.display = 'flex';
        
        try {
            const response = await fetch(`api/receiving/purchase_order_details.php?order_id=${orderId}`);
            const data = await response.json();
            
            if (data.success) {
                modalBody.innerHTML = this.renderReceivingDetails(data);
            } else {
                modalBody.innerHTML = `<p class="error">Eroare: ${data.message}</p>`;
            }
        } catch (error) {
            modalBody.innerHTML = `<p class="error">Eroare: ${error.message}</p>`;
        }
    }
    
    createReceivingModal() {
        const modal = document.createElement('div');
        modal.id = 'receiving-details-modal';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content modal-lg">
                <div class="modal-header">
                    <h3 id="modal-title">Detalii Primire Comandă</h3>
                    <button type="button" class="close-btn" onclick="purchaseOrdersManager.closeReceivingModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="modal-body" id="modal-body">
                    <!-- Content loaded via JavaScript -->
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    closeReceivingModal() {
        const modal = document.getElementById('receiving-details-modal');
        if (modal) {
            modal.style.display = 'none';
        }
    }
    
    showLoading(show) {
        const tbody = document.getElementById('orders-tbody');
        if (!tbody) return;
        
        if (show) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="12" class="text-center">
                        <div class="loading-spinner">
                            <span class="material-symbols-outlined spinning">refresh</span>
                            Se încarcă comenzile...
                        </div>
                    </td>
                </tr>
            `;
        }
    }
    
    showError(message) {
        const tbody = document.getElementById('orders-tbody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="12" class="text-center">
                        <div style="padding: 40px; color: #dc3545;">
                            <span class="material-symbols-outlined" style="font-size: 48px;">error</span>
                            <p>${this.escapeHtml(message)}</p>
                        </div>
                    </td>
                </tr>
            `;
        }
    }
    
    updateSummaryStats(stats) {
        // You can add summary stats display here if needed
        console.log('Summary stats:', stats);
    }
    
    // Utility functions
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    formatDate(dateString) {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleDateString('ro-RO');
    }
    
    formatDateTime(dateString) {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString('ro-RO');
    }
    
    translateStatus(status) {
        const translations = {
            'draft': 'Draft',
            'sent': 'Trimis',
            'confirmed': 'Confirmat',
            'delivered': 'Livrat',
            'partial_delivery': 'Parțial',
            'invoiced': 'Facturat',
            'completed': 'Finalizat',
            'cancelled': 'Anulat'
        };
        return translations[status] || status;
    }
    
    translateReceivingStatus(status) {
        const translations = {
            'not_received': 'Neprimit',
            'partial': 'Parțial',
            'complete': 'Complet',
            'with_discrepancies': 'Cu Discrepanțe'
        };
        return translations[status] || status;
    }
    
    translateItemStatus(status) {
        const translations = {
            'not_received': 'Neprimit',
            'partial': 'Parțial',
            'complete': 'Complet'
        };
        return translations[status] || status;
    }
    
    getProgressClass(percent) {
        if (percent === 0) return 'none';
        if (percent === 100) return 'complete';
        return 'partial';
    }
}

// Global functions for onclick handlers
function clearAllFilters() {
    document.getElementById('status').value = '';
    document.getElementById('seller_id').value = '';
    document.getElementById('receiving-status-filter').value = '';
    
    if (purchaseOrdersManager) {
        purchaseOrdersManager.handleFilterChange();
    }
}

// Close modals when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        const modalId = e.target.id;
        if (modalId === 'stockPurchaseModal') {
            closeStockPurchaseModal();
        } else {
            closeModal(modalId);
        }
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // ESC key to close modals
    if (e.key === 'Escape') {
        const openModals = document.querySelectorAll('.modal.show');
        openModals.forEach(modal => {
            if (modal.id === 'stockPurchaseModal') {
                closeStockPurchaseModal();
            } else {
                closeModal(modal.id);
            }
        });
    }
    
    // Ctrl+N to open stock purchase modal
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        openStockPurchaseModal();
    }
});

// Make functions globally accessible for HTML onclick attributes
window.updateSellerContact = updateSellerContact;
window.openStockPurchaseModal = openStockPurchaseModal;
window.closeStockPurchaseModal = closeStockPurchaseModal;
window.addProductItem = addProductItem;
window.removeProductItem = removeProductItem;
window.selectExistingProduct = selectExistingProduct;
window.calculateItemTotal = calculateItemTotal;
window.validateStockPurchaseForm = validateStockPurchaseForm;
window.openModal = openModal;
window.closeModal = closeModal;
window.openStatusModal = openStatusModal;
window.openSendEmailModal = openSendEmailModal;
window.openDeliveryModal = openDeliveryModal;
window.openInvoiceModal = openInvoiceModal;
window.calculateInvoiceItemTotal = calculateInvoiceItemTotal;
window.purchaseOrdersManager = purchaseOrdersManager;

console.log('Purchase Orders JS loaded with complete stock purchase functionality');