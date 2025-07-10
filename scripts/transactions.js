// File: scripts/transactions.js
// JavaScript functionality for the transactions page with stock purchase

document.addEventListener('DOMContentLoaded', function() {
    console.log('Transactions page loaded');
    initializeAmountCalculations();
    initializeStockPurchase();
});

// Global variables for stock purchase
let productItemIndex = 1;

// Regular Transaction Modal functions
function openCreateModal() {
    document.getElementById('createTransactionModal').classList.add('show');
}

function closeCreateModal() {
    document.getElementById('createTransactionModal').classList.remove('show');
    document.getElementById('createTransactionForm').reset();
}

// Stock Purchase Modal functions
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

// Status and Delete Modal functions
function openStatusModal(transactionId, currentStatus) {
    document.getElementById('statusTransactionId').value = transactionId;
    document.getElementById('updateStatus').value = currentStatus;
    document.getElementById('statusModal').classList.add('show');
}

function closeStatusModal() {
    document.getElementById('statusModal').classList.remove('show');
}

function openDeleteModal(transactionId, transactionNumber) {
    document.getElementById('deleteTransactionId').value = transactionId;
    document.getElementById('deleteTransactionNumber').textContent = transactionNumber;
    document.getElementById('deleteModal').classList.add('show');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
}

function viewTransactionDetails(transactionId) {
    // Redirect to transaction details page or open details modal
    window.location.href = `transaction_details.php?id=${transactionId}`;
}

// Amount calculations for regular transactions
function initializeAmountCalculations() {
    const amountInput = document.getElementById('amount');
    const taxAmountInput = document.getElementById('tax_amount');
    
    if (amountInput && taxAmountInput) {
        amountInput.addEventListener('input', calculateTax);
        taxAmountInput.addEventListener('input', validateTax);
    }
}

function calculateTax() {
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const taxAmount = amount * 0.19; // 19% TVA default
    document.getElementById('tax_amount').value = taxAmount.toFixed(2);
}

function validateTax() {
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const taxAmount = parseFloat(document.getElementById('tax_amount').value) || 0;
    
    if (taxAmount > amount) {
        alert('TVA nu poate fi mai mare decât suma totală!');
        document.getElementById('tax_amount').value = (amount * 0.19).toFixed(2);
    }
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

function updateSellerContact() {
    const sellerSelect = document.getElementById('seller_id');
    const selectedOption = sellerSelect.options[sellerSelect.selectedIndex];
    
    if (selectedOption.value) {
        const email = selectedOption.getAttribute('data-email');
        const contact = selectedOption.getAttribute('data-contact');
        const phone = selectedOption.getAttribute('data-phone');
        
        document.getElementById('email_recipient').value = email || '';
        
        // Update placeholder or show contact info somewhere
        if (contact || phone) {
            let contactInfo = [];
            if (contact) contactInfo.push(`Contact: ${contact}`);
            if (phone) contactInfo.push(`Tel: ${phone}`);
            
            // You could show this in a tooltip or info section
            console.log('Seller contact info:', contactInfo.join(', '));
        }
    } else {
        document.getElementById('email_recipient').value = '';
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
    const item = document.querySelector(`[data-index="${index}"]`);
    
    if (selectedOption.value && item) {
        const productName = selectedOption.getAttribute('data-name');
        const productCode = selectedOption.getAttribute('data-code');
        const lastPrice = selectedOption.getAttribute('data-price');
        
        // Fill in the form fields
        item.querySelector('.product-name').value = productName || '';
        item.querySelector('.product-code').value = productCode || '';
        item.querySelector('.unit-price').value = lastPrice || '';
        item.querySelector('.purchasable-product-id').value = selectedOption.value;
        
        // Calculate total if quantity is set
        calculateItemTotal(index);
    } else if (item) {
        // Clear fields if "create new" is selected
        item.querySelector('.product-name').value = '';
        item.querySelector('.product-code').value = '';
        item.querySelector('.unit-price').value = '';
        item.querySelector('.purchasable-product-id').value = '';
        item.querySelector('.item-total').value = '';
    }
}

function calculateItemTotal(index) {
    const item = document.querySelector(`[data-index="${index}"]`);
    if (!item) return;
    
    const quantity = parseFloat(item.querySelector('.quantity').value) || 0;
    const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;
    const total = quantity * unitPrice;
    
    item.querySelector('.item-total').value = total.toFixed(2) + ' RON';
    
    // Update order total
    calculateOrderTotal();
}

function calculateOrderTotal() {
    const items = document.querySelectorAll('.product-item');
    let orderTotal = 0;
    
    items.forEach(item => {
        const quantity = parseFloat(item.querySelector('.quantity').value) || 0;
        const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;
        orderTotal += quantity * unitPrice;
    });
    
    document.getElementById('order-total').textContent = orderTotal.toFixed(2) + ' RON';
}

// Form validation
function validateStockPurchaseForm() {
    const form = document.getElementById('stockPurchaseForm');
    const sellerId = document.getElementById('seller_id').value;
    
    if (!sellerId) {
        alert('Te rog selectează un furnizor.');
        return false;
    }
    
    const items = document.querySelectorAll('.product-item');
    let hasValidItems = false;
    
    items.forEach(item => {
        const productName = item.querySelector('.product-name').value.trim();
        const quantity = parseFloat(item.querySelector('.quantity').value) || 0;
        const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;
        
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

// Close modals when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('show');
    }
});

// Handle Escape key to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const openModal = document.querySelector('.modal.show');
        if (openModal) {
            openModal.classList.remove('show');
        }
    }
});