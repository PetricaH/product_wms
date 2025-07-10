// File: scripts/purchase_orders.js
// Complete JavaScript functionality for purchase orders page with stock purchase (MOVED FROM transactions.js)

document.addEventListener('DOMContentLoaded', function() {
    console.log('Purchase Orders page loaded');
    initializeDateFields();
    initializeAmountCalculations();
    initializeStockPurchase();
});

// Global variables for stock purchase
let productItemIndex = 1;

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
}

// Initialize amount calculations and form interactions
function initializeAmountCalculations() {
    // Initialize any amount calculations here if needed
    console.log('Amount calculations initialized');
}

// Stock Purchase functionality - MOVED FROM transactions.js
function initializeStockPurchase() {
    // Initialize existing product selectors if they exist
    const selectors = document.querySelectorAll('.existing-product-select');
    selectors.forEach(selector => {
        selector.addEventListener('change', function() {
            const index = this.closest('.product-item').getAttribute('data-index');
            selectExistingProduct(index, this);
        });
    });
    
    // Set minimum delivery date to tomorrow
    const expectedDeliveryDate = document.getElementById('expected_delivery_date');
    if (expectedDeliveryDate) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        expectedDeliveryDate.value = tomorrow.toISOString().split('T')[0];
    }
}

// Stock Purchase Modal functions - MOVED FROM transactions.js
function openStockPurchaseModal() {
    document.getElementById('stockPurchaseModal').classList.add('show');
    // Set minimum delivery date to tomorrow
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    document.getElementById('expected_delivery_date').value = tomorrow.toISOString().split('T')[0];
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
        firstItem.querySelector('.product-name').value = '';
        firstItem.querySelector('.product-code').value = '';
        firstItem.querySelector('.quantity').value = '';
        firstItem.querySelector('.unit-price').value = '';
        firstItem.querySelector('.item-total').value = '';
        firstItem.querySelector('.purchasable-product-id').value = '';
        firstItem.querySelector('.existing-product-select').value = '';
        firstItem.querySelector('button[onclick*="removeProductItem"]').style.display = 'none';
    }
    
    productItemIndex = 1;
    calculateOrderTotal();
}

function updateSellerContact() {
    const sellerSelect = document.getElementById('seller_id');
    const selectedOption = sellerSelect.options[sellerSelect.selectedIndex];
    const emailField = document.getElementById('email_recipient');
    
    if (selectedOption && selectedOption.value && emailField) {
        const email = selectedOption.getAttribute('data-email');
        const contact = selectedOption.getAttribute('data-contact');
        const phone = selectedOption.getAttribute('data-phone');
        
        emailField.value = email || '';
        
        // Update placeholder or show contact info somewhere
        if (contact || phone) {
            let contactInfo = [];
            if (contact) contactInfo.push(`Contact: ${contact}`);
            if (phone) contactInfo.push(`Tel: ${phone}`);
            
            console.log('Seller contact info:', contactInfo.join(', '));
        }
    } else if (emailField) {
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
    // This assumes the PHP page includes the products data in a JavaScript variable
    if (typeof window.purchasableProducts !== 'undefined') {
        let options = '';
        window.purchasableProducts.forEach(product => {
            options += `<option value="${product.id}" 
                                data-name="${product.supplier_product_name}"
                                data-code="${product.supplier_product_code || ''}"
                                data-price="${product.last_purchase_price || ''}">
                            ${product.supplier_product_name}
                            ${product.supplier_product_code ? `(${product.supplier_product_code})` : ''}
                        </option>`;
        });
        return options;
    }
    return '';
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
        
        if (productNameField) productNameField.value = productName || '';
        if (productCodeField) productCodeField.value = productCode || '';
        if (unitPriceField) unitPriceField.value = lastPrice || '';
        if (purchasableProductIdField) purchasableProductIdField.value = selectedOption.value;
        
        // Calculate total if quantity is set
        const quantityField = item.querySelector('.quantity');
        if (quantityField && quantityField.value) {
            calculateItemTotal(quantityField);
        }
    } else if (item) {
        // Clear fields if "create new" is selected
        const productNameField = item.querySelector('.product-name');
        const productCodeField = item.querySelector('.product-code');
        const unitPriceField = item.querySelector('.unit-price');
        const purchasableProductIdField = item.querySelector('.purchasable-product-id');
        const itemTotalField = item.querySelector('.item-total');
        
        if (productNameField) productNameField.value = '';
        if (productCodeField) productCodeField.value = '';
        if (unitPriceField) unitPriceField.value = '';
        if (purchasableProductIdField) purchasableProductIdField.value = '';
        if (itemTotalField) itemTotalField.value = '';
        
        calculateOrderTotal();
    }
}

function calculateItemTotal(index) {
    const item = document.querySelector(`[data-index="${index}"]`);
    if (!item) {
        // Fallback: try to find by input element
        const inputElement = typeof index === 'object' ? index : null;
        if (inputElement) {
            const productItem = inputElement.closest('.product-item');
            if (productItem) {
                const quantity = parseFloat(productItem.querySelector('.quantity').value) || 0;
                const unitPrice = parseFloat(productItem.querySelector('.unit-price').value) || 0;
                const total = quantity * unitPrice;
                
                const itemTotalField = productItem.querySelector('.item-total');
                if (itemTotalField) {
                    itemTotalField.value = total.toFixed(2) + ' RON';
                }
                
                calculateOrderTotal();
                return;
            }
        }
        return;
    }
    
    const quantity = parseFloat(item.querySelector('.quantity').value) || 0;
    const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;
    const total = quantity * unitPrice;
    
    const itemTotalField = item.querySelector('.item-total');
    if (itemTotalField) {
        itemTotalField.value = total.toFixed(2) + ' RON';
    }
    
    // Update order total
    calculateOrderTotal();
}

function calculateOrderTotal() {
    const items = document.querySelectorAll('.product-item');
    let orderTotal = 0;
    
    items.forEach(item => {
        const quantity = parseFloat(item.querySelector('.quantity') ? item.querySelector('.quantity').value : 0) || 0;
        const unitPrice = parseFloat(item.querySelector('.unit-price') ? item.querySelector('.unit-price').value : 0) || 0;
        orderTotal += quantity * unitPrice;
    });
    
    const orderTotalElement = document.getElementById('order-total');
    if (orderTotalElement) {
        orderTotalElement.textContent = orderTotal.toFixed(2) + ' RON';
    }
}

// Form validation
function validateStockPurchaseForm() {
    const sellerSelect = document.getElementById('seller_id');
    const sellerId = sellerSelect ? sellerSelect.value : '';
    
    console.log('Validating form - Seller ID:', sellerId); // Debug log
    
    if (!sellerId || sellerId === '') {
        alert('Te rog selectează un furnizor.');
        return false;
    }
    
    const items = document.querySelectorAll('.product-item');
    let hasValidItems = false;
    
    items.forEach(item => {
        const productName = item.querySelector('.product-name') ? item.querySelector('.product-name').value.trim() : '';
        const quantity = parseFloat(item.querySelector('.quantity') ? item.querySelector('.quantity').value : 0) || 0;
        const unitPrice = parseFloat(item.querySelector('.unit-price') ? item.querySelector('.unit-price').value : 0) || 0;
        
        if (productName && quantity > 0 && unitPrice > 0) {
            hasValidItems = true;
        }
    });
    
    if (!hasValidItems) {
        alert('Te rog adaugă cel puțin un produs valid cu cantitate și preț.');
        return false;
    }
    
    return true;
}

// Generic modal functions
function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
    
    // Reset forms when closing modals
    const modal = document.getElementById(modalId);
    const form = modal.querySelector('form');
    if (form) {
        form.reset();
    }
    
    // Clear any dynamic content
    if (modalId === 'deliveryModal') {
        document.getElementById('delivery-items').innerHTML = '';
    }
    if (modalId === 'invoiceModal') {
        document.getElementById('invoice-items').innerHTML = '';
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
    document.getElementById('delivery_date').value = today;
    
    // TODO: Load order items for delivery recording
    loadOrderItemsForDelivery(orderId);
    
    openModal('deliveryModal');
}

// Invoice modal
function openInvoiceModal(orderId) {
    document.getElementById('invoiceOrderId').value = orderId;
    
    // Set invoice date to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('invoice_date').value = today;
    
    // TODO: Load order items for invoice recording
    loadOrderItemsForInvoice(orderId);
    
    openModal('invoiceModal');
}

// Load order items for delivery recording
function loadOrderItemsForDelivery(orderId) {
    const container = document.getElementById('delivery-items');
    container.innerHTML = '<div class="loading">Se încarcă produsele...</div>';
    
    // TODO: Make AJAX call to get order items
    // For now, just clear the loading message
    setTimeout(() => {
        container.innerHTML = '';
    }, 200);
}

// Load order items for invoice recording
function loadOrderItemsForInvoice(orderId) {
    const container = document.getElementById('invoice-items');
    container.innerHTML = '<div class="loading">Se încarcă produsele...</div>';
    
    // TODO: Make AJAX call to get order items
    // For now, just clear the loading message
    setTimeout(() => {
        container.innerHTML = '';
    }, 200);
}

// Calculate item total for invoice items
function calculateItemTotal(inputElement) {
    const item = inputElement.closest('.invoice-item');
    const quantity = parseFloat(item.querySelector('.invoice-quantity').value) || 0;
    const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;
    const total = quantity * unitPrice;
    
    item.querySelector('.item-total').value = total.toFixed(2) + ' RON';
    
    // Update invoice total
    updateInvoiceTotal();
}

function updateInvoiceTotal() {
    const items = document.querySelectorAll('.invoice-item');
    let invoiceTotal = 0;
    
    items.forEach(item => {
        const quantity = parseFloat(item.querySelector('.invoice-quantity').value) || 0;
        const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;
        invoiceTotal += quantity * unitPrice;
    });
    
    document.getElementById('total_amount').value = invoiceTotal.toFixed(2);
}

// Add form validation on submit
document.addEventListener('DOMContentLoaded', function() {
    const stockPurchaseForm = document.getElementById('stockPurchaseForm');
    if (stockPurchaseForm) {
        stockPurchaseForm.addEventListener('submit', function(e) {
            if (!validateStockPurchaseForm()) {
                e.preventDefault();
            }
        });
    }
});

// Form submission handling
document.addEventListener('submit', function(e) {
    if (e.target.id === 'stockPurchaseForm') {
        if (!validateStockPurchaseForm()) {
            e.preventDefault();
        }
    }
});

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

// Handle Escape key to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const openModal = document.querySelector('.modal.show');
        if (openModal) {
            if (openModal.id === 'stockPurchaseModal') {
                closeStockPurchaseModal();
            } else {
                openModal.classList.remove('show');
            }
        }
    }
});

console.log('Purchase Orders JS loaded with complete stock purchase functionality');