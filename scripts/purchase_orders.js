// File: scripts/purchase_orders.js
// JavaScript functionality for the purchase orders page

document.addEventListener('DOMContentLoaded', function() {
    console.log('Purchase Orders page loaded');
    initializeDateFields();
});

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
    
    // You could pre-populate email from supplier data here
    // For now, just open the modal
    openModal('sendEmailModal');
}

// Delivery recording modal
function openDeliveryModal(orderId) {
    document.getElementById('deliveryOrderId').value = orderId;
    
    // Load order items for delivery recording
    loadOrderItemsForDelivery(orderId);
    
    openModal('deliveryModal');
}

// Invoice recording modal
function openInvoiceModal(orderId) {
    document.getElementById('invoiceOrderId').value = orderId;
    
    // Load order items for invoice recording
    loadOrderItemsForInvoice(orderId);
    
    openModal('invoiceModal');
}

// Load order items for delivery recording
function loadOrderItemsForDelivery(orderId) {
    const container = document.getElementById('delivery-items');
    container.innerHTML = `
        <div class="loading-message">
            <span class="material-symbols-outlined">hourglass_empty</span>
            Se încarcă produsele comenzii...
        </div>
    `;
    
    // Make AJAX call to get order items
    fetch(`api/purchase_order_items.php?order_id=${orderId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                container.innerHTML = `<div class="alert alert-danger">Eroare: ${data.error}</div>`;
                return;
            }
            
            const itemsHtml = createDeliveryItemsForm(data.items || []);
            container.innerHTML = itemsHtml;
        })
        .catch(error => {
            console.error('Error loading order items:', error);
            container.innerHTML = `<div class="alert alert-danger">Eroare la încărcarea produselor.</div>`;
        });
}

// Load order items for invoice recording
function loadOrderItemsForInvoice(orderId) {
    const container = document.getElementById('invoice-items');
    container.innerHTML = `
        <div class="loading-message">
            <span class="material-symbols-outlined">hourglass_empty</span>
            Se încarcă produsele comenzii...
        </div>
    `;
    
    // Make AJAX call to get order items
    fetch(`api/purchase_order_items.php?order_id=${orderId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                container.innerHTML = `<div class="alert alert-danger">Eroare: ${data.error}</div>`;
                return;
            }
            
            const itemsHtml = createInvoiceItemsForm(data.items || []);
            container.innerHTML = itemsHtml;
            calculateInvoiceTotal();
        })
        .catch(error => {
            console.error('Error loading order items:', error);
            container.innerHTML = `<div class="alert alert-danger">Eroare la încărcarea produselor.</div>`;
        });
}

// Create delivery items form
function createDeliveryItemsForm(items) {
    if (!items || items.length === 0) {
        return '<div class="alert alert-info">Nu există produse de livrat în această comandă.</div>';
    }
    
    let html = '<div class="delivery-items-list">';
    
    items.forEach((item, index) => {
        const remainingQuantity = item.remaining_to_deliver || 0;
        
        html += `
            <div class="delivery-item" data-item-id="${item.id}">
                <div class="item-header">
                    <h5>${item.product_name}</h5>
                    ${item.product_code ? `<small class="text-muted">Cod: ${item.product_code}</small>` : ''}
                    <span class="item-status">
                        Comandat: ${item.ordered_quantity} ${item.unit} | 
                        Livrat anterior: ${item.delivered_quantity} ${item.unit} | 
                        Rămas: ${remainingQuantity} ${item.unit}
                    </span>
                </div>
                <div class="row">
                    <div class="form-group">
                        <label>Cantitate Livrată Acum</label>
                        <input type="number" 
                               name="delivery_items[${item.id}][quantity]" 
                               class="form-control delivery-quantity" 
                               min="0" 
                               max="${remainingQuantity}" 
                               step="0.001"
                               placeholder="0">
                    </div>
                    <div class="form-group">
                        <label>Stare Produs</label>
                        <select name="delivery_items[${item.id}][condition]" class="form-control">
                            <option value="good">Bună</option>
                            <option value="damaged">Deteriorat</option>
                            <option value="incomplete">Incomplet</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Observații</label>
                    <textarea name="delivery_items[${item.id}][notes]" 
                              class="form-control" 
                              rows="2" 
                              placeholder="Observații despre livrare..."></textarea>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    return html;
}

// Create invoice items form
function createInvoiceItemsForm(items) {
    if (!items || items.length === 0) {
        return '<div class="alert alert-info">Nu există produse de facturat în această comandă.</div>';
    }
    
    let html = '<div class="invoice-items-list">';
    
    items.forEach((item, index) => {
        const remainingQuantity = item.remaining_to_invoice || 0;
        
        html += `
            <div class="invoice-item" data-item-id="${item.id}">
                <div class="item-header">
                    <h5>${item.product_name}</h5>
                    ${item.product_code ? `<small class="text-muted">Cod: ${item.product_code}</small>` : ''}
                    <span class="item-status">
                        Comandat: ${item.ordered_quantity} ${item.unit} | 
                        Facturat anterior: ${item.invoiced_quantity} ${item.unit} | 
                        Rămas: ${remainingQuantity} ${item.unit}
                    </span>
                </div>
                <div class="row">
                    <div class="form-group">
                        <label>Cantitate Facturată</label>
                        <input type="number" 
                               name="invoice_items[${item.id}][quantity]" 
                               class="form-control invoice-quantity" 
                               min="0" 
                               max="${remainingQuantity}" 
                               step="0.001"
                               onchange="calculateItemTotal(this)"
                               placeholder="0">
                    </div>
                    <div class="form-group">
                        <label>Preț Unitar</label>
                        <input type="number" 
                               name="invoice_items[${item.id}][unit_price]" 
                               class="form-control unit-price" 
                               step="0.01"
                               value="${item.unit_price}"
                               onchange="calculateItemTotal(this)"
                               placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Total</label>
                        <input type="text" class="form-control item-total" readonly placeholder="0.00 RON">
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    return html;
}

// Calculate item total for invoice items
function calculateItemTotal(inputElement) {
    const item = inputElement.closest('.invoice-item');
    const quantity = parseFloat(item.querySelector('.invoice-quantity').value) || 0;
    const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;
    const total = quantity * unitPrice;
    
    item.querySelector('.item-total').value = total.toFixed(2) + ' RON';
    
    // Update invoice total
    calculateInvoiceTotal();
}

// Calculate total invoice amount
function calculateInvoiceTotal() {
    const items = document.querySelectorAll('.invoice-item');
    let total = 0;
    
    items.forEach(item => {
        const quantity = parseFloat(item.querySelector('.invoice-quantity').value) || 0;
        const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;
        total += quantity * unitPrice;
    });
    
    const totalAmountField = document.getElementById('total_amount');
    if (totalAmountField) {
        totalAmountField.value = total.toFixed(2);
    }
}

// View order details
function viewOrderDetails(orderId) {
    // Navigate to dedicated order details page
    window.location.href = `purchase_order_details.php?id=${orderId}`;
}

// Form validation for delivery
function validateDeliveryForm() {
    const deliveryDate = document.getElementById('delivery_date').value;
    if (!deliveryDate) {
        alert('Data livrării este obligatorie.');
        return false;
    }
    
    // Check if at least one item has a delivery quantity
    const quantityInputs = document.querySelectorAll('.delivery-quantity');
    let hasDelivery = false;
    
    quantityInputs.forEach(input => {
        if (parseFloat(input.value) > 0) {
            hasDelivery = true;
        }
    });
    
    if (!hasDelivery) {
        alert('Trebuie să specifici cantitatea livrată pentru cel puțin un produs.');
        return false;
    }
    
    return true;
}

// Form validation for invoice
function validateInvoiceForm() {
    const invoiceNumber = document.getElementById('invoice_number').value;
    const invoiceDate = document.getElementById('invoice_date').value;
    
    if (!invoiceNumber || !invoiceDate) {
        alert('Numărul și data facturii sunt obligatorii.');
        return false;
    }
    
    // Check if at least one item has an invoice quantity
    const quantityInputs = document.querySelectorAll('.invoice-quantity');
    let hasInvoice = false;
    
    quantityInputs.forEach(input => {
        if (parseFloat(input.value) > 0) {
            hasInvoice = true;
        }
    });
    
    if (!hasInvoice) {
        alert('Trebuie să specifici cantitatea facturată pentru cel puțin un produs.');
        return false;
    }
    
    return true;
}

// Add form validation on submit
document.addEventListener('DOMContentLoaded', function() {
    // Delivery form validation
    const deliveryModal = document.getElementById('deliveryModal');
    if (deliveryModal) {
        const deliveryForm = deliveryModal.querySelector('form');
        if (deliveryForm) {
            deliveryForm.addEventListener('submit', function(e) {
                if (!validateDeliveryForm()) {
                    e.preventDefault();
                }
            });
        }
    }
    
    // Invoice form validation
    const invoiceModal = document.getElementById('invoiceModal');
    if (invoiceModal) {
        const invoiceForm = invoiceModal.querySelector('form');
        if (invoiceForm) {
            invoiceForm.addEventListener('submit', function(e) {
                if (!validateInvoiceForm()) {
                    e.preventDefault();
                }
            });
        }
    }
});

// Close modals when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        const modalId = e.target.id;
        closeModal(modalId);
    }
});

// Handle Escape key to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const openModal = document.querySelector('.modal.show');
        if (openModal) {
            closeModal(openModal.id);
        }
    }
});

// Auto-save functionality for forms (optional)
function enableAutoSave() {
    const forms = document.querySelectorAll('.modal form');
    
    forms.forEach(form => {
        const inputs = form.querySelectorAll('input, select, textarea');
        
        inputs.forEach(input => {
            input.addEventListener('change', function() {
                // Save form data to localStorage
                const formData = new FormData(form);
                const data = {};
                for (let [key, value] of formData.entries()) {
                    data[key] = value;
                }
                
                const formId = form.closest('.modal').id;
                localStorage.setItem(`purchase_order_form_${formId}`, JSON.stringify(data));
            });
        });
    });
}

// Load saved form data (optional)
function loadSavedFormData(modalId) {
    const savedData = localStorage.getItem(`purchase_order_form_${modalId}`);
    if (savedData) {
        try {
            const data = JSON.parse(savedData);
            const form = document.querySelector(`#${modalId} form`);
            
            Object.keys(data).forEach(key => {
                const input = form.querySelector(`[name="${key}"]`);
                if (input) {
                    input.value = data[key];
                }
            });
        } catch (e) {
            console.error('Error loading saved form data:', e);
        }
    }
}

// Clear saved form data
function clearSavedFormData(modalId) {
    localStorage.removeItem(`purchase_order_form_${modalId}`);
}