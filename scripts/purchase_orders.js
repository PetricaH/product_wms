// File: scripts/purchase_orders.js
// Complete JavaScript functionality for purchase orders page with stock purchase

document.addEventListener('DOMContentLoaded', function() {
    console.log('Purchase Orders page loaded');
    initializeDateFields();
    initializeAmountCalculations();
    initializeStockPurchase();
    initializeSellerSearch();
    attachFormValidation();
    if (typeof initializePurchaseOrdersExisting === 'function') {
        initializePurchaseOrdersExisting();
    }
    initializeReceivingFunctionality();

    const filterIds = ['status', 'seller_id', 'receiving-status-filter', 'order_type_filter', 'order_sort'];
    filterIds.forEach((id) => {
        const element = document.getElementById(id);
        if (!element) {
            return;
        }
        element.addEventListener('change', (event) => {
            event.preventDefault();
            if (purchaseOrdersManager && typeof purchaseOrdersManager.handleFilterChange === 'function') {
                purchaseOrdersManager.handleFilterChange();
            }
        });
    });
});

// Global variables for stock purchase
let productItemIndex = 1;
let purchaseOrdersManager = null;
let expandedRows = new Set();
let ordersData = [];
let purchasableProductSearchData = [];
let internalProductSearchData = [];
let currentFilters = {
    status: '',
    seller_id: '',
    receiving_status: '',
    order_type: '',
    sort: 'created_desc'
};

const AUTO_ORDER_TEXTS = {
    badges: {
        autoOrder: 'Autocomandă',
        manual: 'Manuală',
        success: 'Succes',
        failed: 'Eșuat',
        pending: 'În așteptare',
        processing: 'În procesare'
    },
    statuses: {
        enabled: 'Autocomandă activă',
        disabled: 'Autocomandă dezactivată',
        belowMinimum: 'Stoc sub pragul minim',
        willTrigger: 'Va declanșa autocomanda',
        noSupplier: 'Fără furnizor',
        noPrice: 'Fără preț definit'
    },
    actions: {
        test: 'Test autocomandă',
        configure: 'Configurează',
        history: 'Istoric',
        retry: 'Reîncearcă'
    }
};

class AutoOrderNotifications {
    static showNotification(message, type = 'info', icon = '') {
        const container = document.getElementById('autoOrderNotifications') || AutoOrderNotifications.createContainer();
        const notification = document.createElement('div');
        notification.className = `auto-order-notification ${type}`;
        if (icon) {
            const iconSpan = document.createElement('span');
            iconSpan.className = 'notification-icon material-symbols-outlined';
            iconSpan.textContent = icon;
            notification.appendChild(iconSpan);
        }
        const textSpan = document.createElement('span');
        textSpan.className = 'notification-text';
        textSpan.textContent = message;
        notification.appendChild(textSpan);
        container.appendChild(notification);

        setTimeout(() => {
            notification.classList.add('visible');
        }, 50);

        setTimeout(() => {
            notification.classList.remove('visible');
            setTimeout(() => notification.remove(), 300);
        }, 4000);
    }

    static createContainer() {
        const container = document.createElement('div');
        container.id = 'autoOrderNotifications';
        container.className = 'auto-order-notifications';
        document.body.appendChild(container);
        return container;
    }

    static showSuccess(message) {
        this.showNotification(message, 'success', 'check_circle');
    }

    static showWarning(message) {
        this.showNotification(message, 'warning', 'warning');
    }

    static showError(message) {
        this.showNotification(message, 'error', 'error');
    }

    static showAutoOrderCreated(orderNumber, productName) {
        const label = productName ? `${orderNumber} - ${productName}` : orderNumber;
        this.showNotification(`Autocomandă creată: ${label}`, 'success', 'smart_toy');
    }

    static showStockLow(productName, currentStock, minStock) {
        this.showNotification(
            `Stoc scăzut pentru ${productName}: ${currentStock}/${minStock}`,
            'warning',
            'inventory_2'
        );
    }
}

function initializeReceivingFunctionality() {
    purchaseOrdersManager = new PurchaseOrdersReceivingManager();
    console.log('Purchase Orders Receiving functionality initialized');
    if (!purchaseOrdersManager) {
        return;
    }

    if (typeof purchaseOrdersManager.handleFilterChange === 'function') {
        purchaseOrdersManager.handleFilterChange(false);
    }

    if (typeof purchaseOrdersManager.loadPurchaseOrdersWithReceiving === 'function') {
        purchaseOrdersManager.loadPurchaseOrdersWithReceiving();
    }

    if (typeof purchaseOrdersManager.refreshAutoOrderStats === 'function') {
        purchaseOrdersManager.refreshAutoOrderStats();
    }

    if (typeof purchaseOrdersManager.loadAutoOrderHistory === 'function') {
        purchaseOrdersManager.loadAutoOrderHistory();
    }
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
    buildProductSearchData();

    const container = document.getElementById('product-items');
    if (!container) {
        return;
    }

    const items = container.querySelectorAll('.product-item');
    items.forEach(item => setupProductItemSearch(item));
}

function initializeSellerSearch() {
    const modal = document.getElementById('stockPurchaseModal');
    if (!modal) {
        console.warn('Stock purchase modal not found for seller search initialization');
        return;
    }

    const searchInput = modal.querySelector('#seller_search_input');
    const hiddenField = modal.querySelector('#seller_id_field');
    if (!searchInput || !hiddenField) {
        console.warn('Seller search inputs not found inside modal');
        return;
    }

    const datalist = document.getElementById('seller-options');

    const assignSeller = () => {
        const rawValue = searchInput.value.trim();
        let matchedSeller = null;

        if (rawValue !== '') {
            const normalizedValue = rawValue.toLowerCase();

            if (Array.isArray(window.sellersList)) {
                matchedSeller = window.sellersList.find(item => {
                    const sellerName = (item.supplier_name || '').toLowerCase();
                    return sellerName === normalizedValue;
                }) || null;
            }

            if (!matchedSeller && datalist && datalist.options) {
                const option = Array.from(datalist.options).find(opt => opt.value.trim().toLowerCase() === normalizedValue);
                if (option) {
                    matchedSeller = {
                        id: option.dataset.id || '',
                        email: option.dataset.email || '',
                        contact_person: option.dataset.contact || '',
                        phone: option.dataset.phone || '',
                        supplier_name: option.value
                    };
                }
            }
        }

        if (matchedSeller && matchedSeller.id) {
            hiddenField.value = matchedSeller.id;
            updateSellerContact(matchedSeller);
        } else {
            hiddenField.value = '';
            updateSellerContact(null);
        }
    };

    searchInput.addEventListener('input', assignSeller);
    searchInput.addEventListener('change', assignSeller);
    searchInput.addEventListener('blur', () => {
        if (!hiddenField.value) {
            searchInput.value = '';
        }
    });
}

function buildProductSearchData() {
    purchasableProductSearchData = Array.isArray(window.purchasableProducts)
        ? window.purchasableProducts.map(product => ({
            data: product,
            searchText: [
                product.supplier_product_name || '',
                product.supplier_product_code || '',
                product.barcode || ''
            ].join(' ').toLowerCase()
        }))
        : [];

    internalProductSearchData = Array.isArray(window.allProducts)
        ? window.allProducts.map(product => ({
            data: product,
            searchText: [
                product.name || '',
                product.sku || '',
                product.barcode || ''
            ].join(' ').toLowerCase()
        }))
        : [];
}

function setupProductItemSearch(item) {
    setupExistingProductSearch(item);
    setupInternalProductSearch(item);
}

function setupExistingProductSearch(item) {
    const searchInput = item.querySelector('.existing-product-search');
    const resultsContainer = item.querySelector('.existing-product-results');
    const purchasableField = item.querySelector('.purchasable-product-id');

    if (!searchInput || !resultsContainer) {
        return;
    }

    const showResults = () => {
        const query = searchInput.value.trim().toLowerCase();
        resultsContainer.innerHTML = '';

        if (!query) {
            resultsContainer.classList.remove('show');
            if (purchasableField) {
                purchasableField.value = '';
            }
            return;
        }

        const matches = purchasableProductSearchData
            .filter(entry => entry.searchText.includes(query))
            .slice(0, 15);

        if (matches.length === 0) {
            const noResult = document.createElement('div');
            noResult.className = 'search-result-item no-results';
            noResult.textContent = 'Nu s-au găsit produse';
            resultsContainer.appendChild(noResult);
            resultsContainer.classList.add('show');
            if (purchasableField) {
                purchasableField.value = '';
            }
            return;
        }

        matches.forEach(entry => {
            const product = entry.data;
            const option = document.createElement('div');
            option.className = 'search-result-item';
            option.innerHTML = `
                <div class="product-name">${escapeHtml(product.supplier_product_name || '')}</div>
                <div class="product-details">
                    ${(product.supplier_product_code || '').trim() ? `Cod: ${escapeHtml(product.supplier_product_code)}` : 'Fără cod'}
                </div>`;
            option.addEventListener('mousedown', event => {
                event.preventDefault();
                applyExistingProductSelection(item, product);
                resultsContainer.classList.remove('show');
            });
            resultsContainer.appendChild(option);
        });

        resultsContainer.classList.add('show');
    };

    searchInput.addEventListener('input', () => {
        if (purchasableField) {
            purchasableField.value = '';
        }
        showResults();
    });

    searchInput.addEventListener('focus', showResults);

    searchInput.addEventListener('blur', () => {
        setTimeout(() => {
            resultsContainer.classList.remove('show');
            if (purchasableField && !purchasableField.value) {
                searchInput.value = '';
            }
        }, 150);
    });
}

function setupInternalProductSearch(item) {
    const searchInput = item.querySelector('.internal-product-search');
    const resultsContainer = item.querySelector('.internal-product-results');
    const hiddenField = item.querySelector('.internal-product-id');

    if (!searchInput || !resultsContainer || !hiddenField) {
        return;
    }

    const showResults = () => {
        const query = searchInput.value.trim().toLowerCase();
        resultsContainer.innerHTML = '';

        if (!query) {
            hiddenField.value = '';
            resultsContainer.classList.remove('show');
            return;
        }

        const matches = internalProductSearchData
            .filter(entry => entry.searchText.includes(query))
            .slice(0, 15);

        if (matches.length === 0) {
            const noResult = document.createElement('div');
            noResult.className = 'search-result-item no-results';
            noResult.textContent = 'Nu s-au găsit produse interne';
            resultsContainer.appendChild(noResult);
            resultsContainer.classList.add('show');
            hiddenField.value = '';
            return;
        }

        matches.forEach(entry => {
            const product = entry.data;
            const option = document.createElement('div');
            option.className = 'search-result-item';
            option.innerHTML = `
                <div class="product-name">${escapeHtml(product.name || '')}</div>
                <div class="product-details">
                    SKU: ${escapeHtml(product.sku || 'N/A')}
                </div>`;
            option.addEventListener('mousedown', event => {
                event.preventDefault();
                applyInternalProductSelection(item, product);
                resultsContainer.classList.remove('show');
            });
            resultsContainer.appendChild(option);
        });

        resultsContainer.classList.add('show');
    };

    searchInput.addEventListener('input', () => {
        hiddenField.value = '';
        showResults();
    });

    searchInput.addEventListener('focus', showResults);

    searchInput.addEventListener('blur', () => {
        setTimeout(() => {
            resultsContainer.classList.remove('show');
            if (!hiddenField.value) {
                searchInput.value = '';
            }
        }, 150);
    });
}

function applyExistingProductSelection(item, product) {
    const displayInput = item.querySelector('.existing-product-search');
    const purchasableField = item.querySelector('.purchasable-product-id');
    const productNameField = item.querySelector('.product-name');
    const productCodeField = item.querySelector('.product-code');
    const unitPriceField = item.querySelector('.unit-price');
    const internalHiddenField = item.querySelector('.internal-product-id');
    const internalSearchInput = item.querySelector('.internal-product-search');

    const label = formatExistingProductLabel(product);
    if (displayInput) {
        displayInput.value = label;
    }

    if (purchasableField) {
        purchasableField.value = product.id || '';
    }

    if (productNameField) {
        productNameField.value = product.supplier_product_name || '';
    }

    if (productCodeField) {
        productCodeField.value = product.supplier_product_code || '';
    }

    if (unitPriceField) {
        unitPriceField.value = product.last_purchase_price || '';
    }

    if (internalHiddenField) {
        internalHiddenField.value = product.internal_product_id || '';
    }

    if (internalSearchInput) {
        if (product.internal_product_id) {
            const internalProduct = findInternalProductById(product.internal_product_id);
            if (internalProduct) {
                internalSearchInput.value = `${internalProduct.name || ''} (${internalProduct.sku || 'N/A'})`;
            } else {
                internalSearchInput.value = '';
            }
        } else {
            internalSearchInput.value = '';
        }
    }

    const index = item.getAttribute('data-index');
    if (index !== null) {
        calculateItemTotal(index);
    }
}

function applyInternalProductSelection(item, product) {
    const searchInput = item.querySelector('.internal-product-search');
    const hiddenField = item.querySelector('.internal-product-id');

    if (searchInput) {
        searchInput.value = `${product.name || ''} (${product.sku || 'N/A'})`;
    }

    if (hiddenField) {
        hiddenField.value = product.product_id || '';
    }
}

function formatExistingProductLabel(product) {
    const name = product.supplier_product_name || '';
    const code = product.supplier_product_code ? ` (${product.supplier_product_code})` : '';
    return `${name}${code}`.trim();
}

function findInternalProductById(id) {
    if (!id || !Array.isArray(window.allProducts)) {
        return null;
    }

    return window.allProducts.find(product => String(product.product_id) === String(id)) || null;
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
    
    // Clear the hidden status field
    document.getElementById('order_status').value = '';
    
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
        const existingProductInput = firstItem.querySelector('.existing-product-search');
        const existingProductResults = firstItem.querySelector('.existing-product-results');
        const internalProductInput = firstItem.querySelector('.internal-product-search');
        const internalProductHidden = firstItem.querySelector('.internal-product-id');
        const internalProductResults = firstItem.querySelector('.internal-product-results');
        const removeButton = firstItem.querySelector('button[onclick*="removeProductItem"]');

        if (productName) productName.value = '';
        if (productCode) productCode.value = '';
        if (quantity) quantity.value = '';
        if (unitPrice) unitPrice.value = '';
        if (itemTotal) itemTotal.value = '';
        if (purchasableProductId) purchasableProductId.value = '';
        if (existingProductInput) existingProductInput.value = '';
        if (existingProductResults) {
            existingProductResults.innerHTML = '';
            existingProductResults.classList.remove('show');
        }
        if (internalProductInput) internalProductInput.value = '';
        if (internalProductHidden) internalProductHidden.value = '';
        if (internalProductResults) {
            internalProductResults.innerHTML = '';
            internalProductResults.classList.remove('show');
        }
        if (removeButton) removeButton.style.display = 'none';
    }
    
    productItemIndex = 1;
    calculateOrderTotal();
}

// Update seller contact details when the supplier changes
function updateSellerContact(sellerData = null) {
    const modal = document.getElementById('stockPurchaseModal');
    if (!modal) {
        console.error('Modal not found');
        return;
    }

    const emailField = modal.querySelector('#email_recipient');
    const hiddenField = modal.querySelector('#seller_id_field');

    if (!emailField) {
        console.error('Email recipient field not found in modal');
        return;
    }

    if (!hiddenField) {
        console.error('Hidden seller field not found in modal');
        emailField.value = '';
        return;
    }

    let seller = sellerData;

    if (!seller) {
        const sellerId = hiddenField.value;
        if (!sellerId) {
            emailField.value = '';
            return;
        }

        if (Array.isArray(window.sellersList)) {
            seller = window.sellersList.find(item => String(item.id) === String(sellerId)) || null;
        }
    }

    if (seller) {
        emailField.value = seller.email || '';

        const contact = seller.contact_person || seller.contact || '';
        const phone = seller.phone || '';
        if (contact || phone) {
            const details = [];
            if (contact) details.push(`Contact: ${contact}`);
            if (phone) details.push(`Tel: ${phone}`);
            console.log('Seller contact info:', details.join(', '));
        }
    } else {
        emailField.value = '';
    }
}

function addProductItem() {
    const container = document.getElementById('product-items');
    const newItem = createProductItem(productItemIndex);
    container.appendChild(newItem);

    setupProductItemSearch(newItem);

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
                    <div class="product-search-container">
                        <input type="text"
                               class="form-control product-search-input existing-product-search"
                               placeholder="Caută produs furnizor..."
                               autocomplete="off">
                        <div class="product-search-results existing-product-results"></div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Produs Intern *</label>
                    <div class="product-search-container">
                        <input type="hidden" name="items[${index}][internal_product_id]" class="internal-product-id">
                        <input type="text"
                               class="form-control product-search-input internal-product-search"
                               placeholder="Caută produs intern..."
                               autocomplete="off"
                               required>
                        <div class="product-search-results internal-product-results"></div>
                    </div>
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

function submitStockPurchase(status) {
    if (!validateStockPurchaseForm()) {
        return false;
    }
    
    // Set the order status based on which button was clicked
    document.getElementById('order_status').value = status;
    
    // Submit the form
    document.getElementById('stockPurchaseForm').submit();
}

// FIXED: Form validation with proper checks
function validateStockPurchaseForm() {
    const modal = document.getElementById('stockPurchaseModal');
    if (!modal) {
        console.error('Modal not found during validation');
        return false;
    }
    
    // Check if seller is selected via the hidden field populated by the search
    const sellerHiddenField = modal.querySelector('#seller_id_field');
    const sellerSearchInput = modal.querySelector('#seller_search_input');
    if (!sellerHiddenField || !sellerHiddenField.value) {
        alert('Te rog selectează un furnizor din lista de sugestii.');
        if (sellerSearchInput) sellerSearchInput.focus();
        return false;
    }

    // Email subject and body required
    const subjectField = modal.querySelector('#email_subject');
    const messageField = modal.querySelector('#custom_message');
    const subjectValue = ((subjectField && subjectField.value) ?? '').toString().trim();
    const messageValue = ((messageField && messageField.value) ?? '').toString().trim();

    if (subjectField && subjectValue === '') {
        alert('Subiectul emailului este obligatoriu.');
        subjectField.focus();
        return false;
    }
    if (messageField && messageValue === '') {
        alert('Mesajul emailului este obligatoriu.');
        messageField.focus();
        return false;
    }
    
    // Check for valid products
    const productItems = document.querySelectorAll('.product-item');
    let hasValidItems = false;

    for (const item of productItems) {
        const productNameField = item.querySelector('.product-name');
        const quantityField = item.querySelector('.quantity');
        const unitPriceField = item.querySelector('.unit-price');
        const internalProductHidden = item.querySelector('.internal-product-id');
        const internalProductSearch = item.querySelector('.internal-product-search');

        const productName = ((productNameField && productNameField.value) ?? '').toString().trim();
        const quantity = quantityField ? parseFloat(quantityField.value) || 0 : 0;
        const unitPrice = unitPriceField ? parseFloat(unitPriceField.value) || 0 : 0;

        if (productName && quantity > 0 && unitPrice > 0) {
            if (!internalProductHidden || !internalProductHidden.value) {
                alert('Te rog selectează produsul intern din lista de sugestii.');
                if (internalProductSearch) {
                    internalProductSearch.focus();
                }
                return false;
            }
            hasValidItems = true;
        }
    }

    if (!hasValidItems) {
        alert('Te rog adaugă cel puțin un produs valid cu cantitate și preț.');
        return false;
    }
    
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

function updateStatusBadge(element, status) {
    const statusClasses = {
        'draft': 'status-draft',
        'sent': 'status-sent', 
        'confirmed': 'status-confirmed',
        'partial_delivery': 'status-partial_delivery',
        'delivered': 'status-delivered',
        'cancelled': 'status-cancelled',
        'returned': 'status-returned',
        'completed': 'status-completed'
    };
    
    // Remove all existing status classes
    Object.values(statusClasses).forEach(cls => element.classList.remove(cls));
    
    // Add the new status class
    if (statusClasses[status]) {
        element.classList.add(statusClasses[status]);
    }
    
    // Update text content
    const statusTexts = {
        'draft': 'DRAFT',
        'sent': 'TRIMIS',
        'confirmed': 'CONFIRMAT',
        'partial_delivery': 'LIVRARE PARȚIALĂ',
        'delivered': 'LIVRAT',
        'cancelled': 'ANULAT',
        'returned': 'RETURNAT',
        'completed': 'COMPLET'
    };
    
    element.textContent = statusTexts[status] || status.toUpperCase();
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
function openInvoiceUploadModal(orderId) {
    document.getElementById('invoiceOrderId').value = orderId;
    
    // Clear any previous file selection
    const fileInput = document.getElementById('invoice_file');
    if (fileInput) {
        fileInput.value = '';
    }
    
    // Show info about status change
    const infoDiv = document.getElementById('invoice-upload-info');
    if (infoDiv) {
        infoDiv.innerHTML = '<i class="material-symbols-outlined">info</i> Încărcarea facturii va schimba statusul comenzii în "CONFIRMAT".';
    }
    
    openModal('invoiceUploadModal');
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
        this.initializeInvoiceUpload();
    }

    // NEW: Initialize invoice upload functionality
    initializeInvoiceUpload() {
        const form = document.getElementById('invoiceUploadForm');
        if (form) {
            form.addEventListener('submit', this.handleInvoiceUpload.bind(this));
        }
    }

    async handleInvoiceUpload(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const submitButton = e.target.querySelector('button[type="submit"]');
        
        // Show loading state
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="material-symbols-outlined spinning">sync</span> Încarcă...';
        
        try {
            const response = await fetch('api/receiving/upload_invoice.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Close modal and refresh table
                closeModal('invoiceUploadModal');
                this.loadPurchaseOrdersWithReceiving();
                
                // Show success message
                this.showNotification('Factura a fost încărcată cu succes!', 'success');
            } else {
                this.showNotification(result.message || 'Eroare la încărcarea facturii', 'error');
            }
        } catch (error) {
            console.error('Error uploading invoice:', error);
            this.showNotification('Eroare la încărcarea facturii', 'error');
        } finally {
            // Restore button state
            submitButton.disabled = false;
            submitButton.innerHTML = '<span class="material-symbols-outlined">upload</span> Încarcă Factura';
        }
    }

    renderOrderRow(order) {
        const progressPercent = order.receiving_summary.quantity_progress_percent;
        const hasDiscrepancies = order.discrepancies_summary.has_pending_discrepancies;
        const qtyDiscrepancy = this.getQuantityDiscrepancyType(order);
        const pendingShort = order.discrepancies_summary.pending_items_short || 0;
        const pendingOver = order.discrepancies_summary.pending_items_over || 0;
        const pendingItems = order.discrepancies_summary.pending_discrepant_items || 0;
        let discrepancyCount = pendingItems;
        if (qtyDiscrepancy === 'quantity_short' && pendingShort) {
            discrepancyCount = `-${pendingShort}`;
        } else if (qtyDiscrepancy === 'quantity_over' && pendingOver) {
            discrepancyCount = `+${pendingOver}`;
        }

        const isAutoOrder = order.order_type === 'auto';
        const orderBadges = [];
        if (isAutoOrder) {
            orderBadges.push(`<span class="badge badge-auto">${AUTO_ORDER_TEXTS.badges.autoOrder}</span>`);
            orderBadges.push(`
                <span class="badge badge-status status-${order.auto_order_status || 'pending'}">
                    ${this.getAutoOrderStatusLabel(order.auto_order_status)}
                </span>
            `);
        } else {
            orderBadges.push(`<span class="badge badge-manual">${AUTO_ORDER_TEXTS.badges.manual}</span>`);
        }

        const autoIndicator = isAutoOrder
            ? `
                <div class="auto-order-status-indicator status-${order.auto_order_status || 'pending'}">
                    <span class="status-dot"></span>
                    <span class="status-text">${this.getAutoOrderStatusLabel(order.auto_order_status)}</span>
                </div>
            `
            : '';

        const rowClass = isAutoOrder ? 'auto-order-row' : '';

        // Updated invoice display logic - show button for all statuses except 'draft'
        let invoiceDisplay = '-';
        if (order.invoiced) {
            const invoiceUrl = order.invoice_file_path.startsWith('storage/')
                ? order.invoice_file_path
                : `storage/${order.invoice_file_path}`;
            invoiceDisplay = `
                <div class="invoice-container">
                    <div class="invoice-date-badge">
                        Primit Factura La: ${this.formatDate(order.invoiced_at)}
                    </div>
                    <a href="${invoiceUrl}" target="_blank" class="invoice-file-link">
                        <span class="material-symbols-outlined">description</span>
                        Factura
                    </a>
                </div>
            `;
        } else if (order.po_status !== 'draft') {
            // Show upload button for all statuses except 'draft'
            invoiceDisplay = `
                <button class="btn btn-sm btn-success" onclick="openInvoiceUploadModal(${order.id})" 
                        title="Încarcă Factura">
                    <span class="material-symbols-outlined">upload</span>
                    Încarcă Factura
                </button>
            `;
        }

        return `
            <tr data-order-id="${order.id}" class="${rowClass}">
                <td>
                    <button class="expand-btn" onclick="purchaseOrdersManager.toggleRowExpansion(${order.id})">
                        <span class="material-symbols-outlined">expand_more</span>
                    </button>
                </td>
                <td>
                    <div class="order-number-cell">
                        <div class="order-number-header">
                            <strong>${this.escapeHtml(order.order_number)}</strong>
                            ${order.invoiced ? '<span class="invoiced-badge">Facturat</span>' : ''}
                        </div>
                        <div class="order-badges">${orderBadges.join('')}</div>
                        ${autoIndicator}
                    </div>
                </td>
                <td>${this.escapeHtml(order.supplier_name)}</td>
                <td>${order.total_amount} ${order.currency}</td>
                <td>
                    <span class="status-badge status-${order.po_status.toLowerCase()}">
                        ${this.translateStatus(order.po_status)}
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
                    <div class="discrepancy-indicator ${qtyDiscrepancy === 'quantity_over' ? 'overdelivery' : qtyDiscrepancy === 'quantity_short' ? 'underdelivery' : (hasDiscrepancies ? 'has-discrepancies' : 'no-discrepancies')}">
                        <span class="material-symbols-outlined">
                            ${qtyDiscrepancy === 'quantity_over' ? 'add' : qtyDiscrepancy === 'quantity_short' ? 'remove' : (hasDiscrepancies ? 'warning' : 'check_circle')}
                        </span>
                        ${discrepancyCount}
                    </div>
                </td>
                <td>${this.formatDate(order.created_at)}</td>
                <td>
                    ${order.pdf_path ? `<a href="storage/purchase_order_pdfs/${order.pdf_path}" target="_blank">PDF</a>` : '-'}
                </td>
                <td>${order.actual_delivery_date || '-'}</td>
                <td>${invoiceDisplay}</td>
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

    getAutoOrderStatusLabel(status) {
        const map = {
            success: AUTO_ORDER_TEXTS.badges.success,
            failed: AUTO_ORDER_TEXTS.badges.failed,
            pending: AUTO_ORDER_TEXTS.badges.pending,
            processing: AUTO_ORDER_TEXTS.badges.processing,
            manual: AUTO_ORDER_TEXTS.badges.manual
        };
        return map[status] || AUTO_ORDER_TEXTS.badges.pending;
    }
    // NEW: Updated summary stats to include invoice information
    updateSummaryStats(stats) {
        const elements = {
            'total-orders': stats.total_orders_last_30_days,
            'delivered-orders': stats.delivered_orders,
            'partial-orders': stats.partial_orders,
            'orders-with-receiving': stats.orders_with_receiving,
            'orders-with-discrepancies': stats.orders_with_pending_discrepancies,
            'invoiced-orders': stats.invoiced_orders,
            'pending-invoices': stats.pending_invoices
        };
        
        Object.entries(elements).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = value || 0;
            }
        });
    }

    // NEW: Notification system
    showNotification(message, type) {
        // Remove existing notifications
        const existingNotifications = document.querySelectorAll('.notification');
        existingNotifications.forEach(notification => notification.remove());
        
        // Create new notification
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.textContent = message;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 5px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            ${type === 'success' ? 'background-color: #28a745;' : 'background-color: #dc3545;'}
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    // Updated methods with invoice support
    async showReceivingDetails(orderId) {
        // Create modal if it doesn't exist
        if (!document.getElementById('receiving-details-modal')) {
            this.createReceivingModal();
        }
        
        const modal = document.getElementById('receiving-details-modal');
        const modalBody = document.getElementById('modal-body');
        
        // Show loading state
        modalBody.innerHTML = `
            <div class="loading-spinner" style="text-align: center; padding: 40px;">
                <span class="material-symbols-outlined spinning">refresh</span>
                <p>Se încarcă detaliile...</p>
            </div>
        `;
        
        // Show modal
        modal.classList.add('show');
        modal.style.display = '';
        
        try {
            const response = await fetch(`api/receiving/purchase_order_details.php?order_id=${orderId}`);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                modalBody.innerHTML = this.renderReceivingDetails(data);
            } else {
                modalBody.innerHTML = `
                    <div class="error-message" style="padding: 20px; background: #fee; border: 1px solid #fcc; border-radius: 4px; color: #c44;">
                        <h4>Eroare la încărcarea detaliilor</h4>
                        <p><strong>Mesaj:</strong> ${data.message || 'Unable to fetch purchase order receiving details'}</p>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error fetching details:', error);
            modalBody.innerHTML = `
                <div class="error-message" style="padding: 20px; background: #fee; border: 1px solid #fcc; border-radius: 4px; color: #c44;">
                    <h4>Eroare de conectare</h4>
                    <p><strong>Mesaj:</strong> ${error.message}</p>
                </div>
            `;
        }
    }

    // Existing methods remain the same...
    async loadPurchaseOrdersWithReceiving() {
        try {
            this.showLoading(true);
            
            const params = new URLSearchParams();
            if (currentFilters.status) params.append('status', currentFilters.status);
            if (currentFilters.seller_id) params.append('seller_id', currentFilters.seller_id);
            if (currentFilters.receiving_status) params.append('receiving_status', currentFilters.receiving_status);
            if (currentFilters.order_type) params.append('order_type', currentFilters.order_type);
            if (currentFilters.sort) params.append('sort', currentFilters.sort);
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

    // Helper methods
    formatDate(dateString) {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleDateString('ro-RO');
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    translateStatus(status) {
        const statusMap = {
            'draft': 'Draft',
            'sent': 'Trimis',
            'confirmed': 'Confirmat',
            'partial_delivery': 'Livrare Parțială',
            'delivered': 'Livrat',
            'completed': 'Finalizat',
            'cancelled': 'Anulat'
        };
        return statusMap[status] || status;
    }

    translateReceivingStatus(status) {
        const statusMap = {
            'not_received': 'Nu este primit',
            'partial': 'Parțial',
            'complete': 'Complet',
            'with_discrepancies': 'Cu discrepanțe'
        };
        return statusMap[status] || status;
    }

    getProgressClass(percent) {
        if (percent === 0) return 'none';
        if (percent === 100) return 'complete';
        return 'partial';
    }

    getQuantityDiscrepancyType(order) {
        const diff = (order.receiving_summary.total_quantity_received || 0) -
                     (order.order_summary.total_quantity_ordered || 0);
        if (diff > 0) return 'quantity_over';
        if (diff < 0) return 'quantity_short';
        return null;
    }

    translateDiscrepancyType(type) {
        const map = {
            'quantity_short': 'Mai Puțină Cantitate',
            'quantity_over': 'Mai Multă Cantitate',
            'quality_issue': 'Problemă Calitate',
            'missing_item': 'Articol Lipsă',
            'unexpected_item': 'Articol Neașteptat'
        };
        return map[type] || type;
    }

    createReceivingModal() {
        const existingModal = document.getElementById('receiving-details-modal');
        if (existingModal) {
            existingModal.remove();
        }
        
        const modal = document.createElement('div');
        modal.id = 'receiving-details-modal';
        modal.className = 'modal';
        modal.style.display = 'none';
        modal.innerHTML = `
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 id="modal-title">Detalii Primire Comandă</h3>
                        <button type="button" class="modal-close" aria-label="Închide" onclick="closeReceivingModal()">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                    <div class="modal-body" id="modal-body">
                        <!-- Content loaded via JavaScript -->
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Add click outside to close
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeReceivingModal();
            }
        });
    }

    closeReceivingModal() {
        const modal = document.getElementById('receiving-details-modal');
        if (modal) {
            modal.classList.remove('show');
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
        if (!tbody) return;
        
        tbody.innerHTML = `
            <tr>
                <td colspan="12" class="text-center">
                    <div class="error-message" style="padding: 20px; color: #dc3545;">
                        <span class="material-symbols-outlined">error</span>
                        <p>${message}</p>
                    </div>
                </td>
            </tr>
        `;
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

    async refreshAutoOrderStats() {
        try {
            const response = await fetch('api/auto_orders/dashboard.php');
            const payload = await response.json();
            if (!payload.success) {
                throw new Error(payload.message || 'Nu s-au putut încărca statisticile.');
            }
            this.renderAutoOrderDashboard(payload.data || {});
        } catch (error) {
            console.error('Auto-order dashboard error:', error);
            AutoOrderNotifications.showWarning('Nu s-au putut încărca statisticile autocomenzii.');
        }
    }

    renderAutoOrderDashboard(data) {
        if (!data) return;
        const stats = data.statistics || {};
        const mapping = {
            totalAutoOrders: stats.totalAutoOrders ?? 0,
            autoOrdersToday: stats.autoOrdersToday ?? 0,
            productsAtMinimum: stats.productsAtMinimum ?? 0,
            failedAutoOrders: stats.failedAutoOrders ?? 0
        };

        Object.entries(mapping).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = value;
            }
        });

        const timelineContainer = document.getElementById('recentAutoOrdersList');
        if (timelineContainer) {
            const recentOrders = Array.isArray(data.recentOrders) ? data.recentOrders : [];
            if (!recentOrders.length) {
                timelineContainer.innerHTML = '<div class="empty-auto-orders">Nu există autocomenzi în ultimele 7 zile.</div>';
            } else {
                timelineContainer.innerHTML = recentOrders.map(item => this.renderRecentAutoOrder(item)).join('');
            }
        }

        const attentionContainer = document.getElementById('attentionProductsList');
        if (attentionContainer) {
            const products = Array.isArray(data.attentionProducts) ? data.attentionProducts : [];
            if (!products.length) {
                attentionContainer.innerHTML = '<div class="empty-auto-orders">Nu există produse care să necesite atenție.</div>';
            } else {
                attentionContainer.innerHTML = products.map(item => this.renderAttentionProduct(item)).join('');
            }
        }
    }

    renderRecentAutoOrder(order) {
        const statusClass = `status-${order.status?.toLowerCase() || 'pending'}`;
        const autoStatus = order.email_sent_at ? AUTO_ORDER_TEXTS.badges.success : AUTO_ORDER_TEXTS.badges.pending;
        const createdAt = order.created_at ? new Date(order.created_at).toLocaleString('ro-RO') : '-';
        const supplier = order.supplier_name ? this.escapeHtml(order.supplier_name) : 'Furnizor necunoscut';
        const products = order.product_names ? this.escapeHtml(order.product_names) : 'Produs necunoscut';
        return `
            <div class="timeline-item ${statusClass}">
                <div class="timeline-header">
                    <span class="order-number">${this.escapeHtml(order.order_number || '')}</span>
                    <span class="timeline-status">${autoStatus}</span>
                </div>
                <div class="timeline-meta">
                    <span>${createdAt}</span>
                    <span>${supplier}</span>
                </div>
                <div class="timeline-products">${products}</div>
            </div>
        `;
    }

    renderAttentionProduct(product) {
        const productName = this.escapeHtml(product.name || 'Produs necunoscut');
        const sku = this.escapeHtml(product.sku || 'N/A');
        const supplier = this.escapeHtml(product.supplier_name || 'Fără furnizor');
        const stock = Number(product.quantity ?? 0);
        const minimum = Number(product.min_stock_level ?? 0);
        return `
            <div class="attention-item">
                <div class="attention-details">
                    <strong>${productName}</strong>
                    <span class="attention-meta">SKU: ${sku}</span>
                    <span class="attention-meta">${supplier}</span>
                </div>
                <div class="attention-stock">${stock} / ${minimum}</div>
            </div>
        `;
    }

    async loadAutoOrderHistory() {
        const fromInput = document.getElementById('historyDateFrom');
        const toInput = document.getElementById('historyDateTo');
        const statusInput = document.getElementById('historyStatus');

        const params = new URLSearchParams();
        if (fromInput?.value) params.append('date_from', fromInput.value);
        if (toInput?.value) params.append('date_to', toInput.value);
        if (statusInput?.value) params.append('status', statusInput.value);

        try {
            const response = await fetch(`api/auto_orders/history.php?${params.toString()}`);
            const payload = await response.json();
            if (!payload.success) {
                throw new Error(payload.message || 'Nu s-a putut încărca istoricul.');
            }
            this.renderAutoOrderHistory(payload.data || []);
        } catch (error) {
            console.error('Auto-order history error:', error);
            const container = document.getElementById('autoOrderHistoryList');
            if (container) {
                container.innerHTML = '<div class="history-error">Istoricul autocomenzilor nu poate fi afișat momentan.</div>';
            }
        }
    }

    renderAutoOrderHistory(entries) {
        const container = document.getElementById('autoOrderHistoryList');
        if (!container) return;

        if (!entries.length) {
            container.innerHTML = '<div class="history-empty">Nu există înregistrări în intervalul selectat.</div>';
            return;
        }

        container.innerHTML = entries.map((entry) => {
            const statusClass = `status-${entry.auto_order_status || 'pending'}`;
            const createdAt = entry.created_at ? new Date(entry.created_at).toLocaleString('ro-RO') : '-';
            const total = entry.total_amount ? Number(entry.total_amount).toFixed(2) : '0.00';
            const products = this.escapeHtml(entry.products || '');
            return `
                <div class="history-item ${statusClass}">
                    <div class="history-item-header">
                        <span class="history-order">${this.escapeHtml(entry.order_number || '')}</span>
                        <span class="history-status">${this.getAutoOrderStatusLabel(entry.auto_order_status)}</span>
                    </div>
                    <div class="history-meta">
                        <span>${createdAt}</span>
                        <span>${this.escapeHtml(entry.supplier_name || 'Furnizor necunoscut')}</span>
                        <span>Total: ${total} ${this.escapeHtml(entry.currency || 'RON')}</span>
                    </div>
                    <div class="history-products">${products}</div>
                </div>
            `;
        }).join('');
    }

    handleFilterChange(shouldReload = true) {
        currentFilters.status = document.getElementById('status')?.value || '';
        currentFilters.seller_id = document.getElementById('seller_id')?.value || '';
        currentFilters.receiving_status = document.getElementById('receiving-status-filter')?.value || '';
        currentFilters.order_type = document.getElementById('order_type_filter')?.value || '';
        currentFilters.sort = document.getElementById('order_sort')?.value || 'created_desc';

        if (shouldReload) {
            this.loadPurchaseOrdersWithReceiving();
        }
    }

    async toggleRowExpansion(orderId) {
        const row = document.querySelector(`tr.expandable-row[data-order-id="${orderId}"]`);
        const expandBtn = document.querySelector(`tr[data-order-id="${orderId}"] .expand-btn`);
        const content = document.getElementById(`expandable-${orderId}`);
        
        if (!row || !expandBtn || !content) return;
        
        if (expandedRows.has(orderId)) {
            row.style.display = 'none';
            content.classList.remove('expanded');
            expandBtn.classList.remove('expanded');
            expandedRows.delete(orderId);
        } else {
            row.style.display = 'table-row';
            expandBtn.classList.add('expanded');
            expandedRows.add(orderId);
            
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
            contentElement.innerHTML = `<p class="error">Eroare: ${error.message}</p>`;
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
                    <h4><span class="material-symbols-outlined">list_alt</span>Detalii Produse</h4>
                    ${this.renderItemsDetails(data.items_detail)}
                </div>
                ${data.discrepancies.length > 0 ? `
                    <div class="receiving-section">
                        <h4><span class="material-symbols-outlined">warning</span>Discrepanțe</h4>
                        ${this.renderDiscrepancies(data.discrepancies)}
                    </div>
                ` : ''}
            </div>
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
                                <span class="receiving-status ${item.receiving_status}">
                                    ${this.translateReceivingStatus(item.receiving_status)}
                                </span>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    renderDiscrepancies(discrepancies) {
        if (discrepancies.length === 0) {
            return '<p>Nu există discrepanțe înregistrate.</p>';
        }
        
        return `
            <table class="discrepancies-table">
                <thead>
                    <tr>
                        <th>Produs</th>
                        <th>Tip Discrepanță</th>
                        <th>Descriere</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    ${discrepancies.map(discrepancy => `
                        <tr>
                            <td>
                                <strong>${this.escapeHtml(discrepancy.product_name)}</strong><br>
                                <small>${discrepancy.sku}</small>
                            </td>
                            <td>${discrepancy.discrepancy_type_label || this.translateDiscrepancyType(discrepancy.discrepancy_type)}</td>
                            <td>${this.escapeHtml(discrepancy.description)}</td>
                            <td>
                                <span class="discrepancy-status ${discrepancy.resolution_status}">
                                    ${discrepancy.resolution_status}
                                </span>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    renderSummaryStats(stats) {
        return `
            <div class="stats-grid">
                <div class="stat-item">
                    <span class="stat-label">Total Sesiuni:</span>
                    <span class="stat-value">${stats.total_sessions}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Sesiuni Completate:</span>
                    <span class="stat-value">${stats.completed_sessions}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Total Produse:</span>
                    <span class="stat-value">${stats.total_items}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Produse Primite:</span>
                    <span class="stat-value">${stats.received_items}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Discrepanțe Pendente:</span>
                    <span class="stat-value">${stats.pending_discrepancies}</span>
                </div>
            </div>
        `;
    }

    formatDateTime(dateString) {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString('ro-RO');
    }
}

// Global functions for onclick handlers
function clearAllFilters() {
    document.getElementById('status').value = '';
    document.getElementById('seller_id').value = '';
    document.getElementById('receiving-status-filter').value = '';
    const orderType = document.getElementById('order_type_filter');
    const orderSort = document.getElementById('order_sort');
    if (orderType) {
        orderType.value = '';
    }
    if (orderSort) {
        orderSort.value = 'created_desc';
    }

    if (purchaseOrdersManager) {
        purchaseOrdersManager.handleFilterChange();
    }
}

function closeReceivingModal() {
    if (window.purchaseOrdersManager &&
        typeof window.purchaseOrdersManager.closeReceivingModal === 'function') {
        window.purchaseOrdersManager.closeReceivingModal();
        return;
    }

    const fallbackModal = document.getElementById('receiving-details-modal');
    if (fallbackModal) {
        fallbackModal.classList.remove('show');
        fallbackModal.style.display = 'none';
    }
}

// Close modals when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        const modalId = e.target.id;
        if (modalId === 'stockPurchaseModal') {
            closeStockPurchaseModal();
        } else if (modalId === 'receiving-details-modal') {
            closeReceivingModal();
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
window.calculateItemTotal = calculateItemTotal;
window.validateStockPurchaseForm = validateStockPurchaseForm;
window.openModal = openModal;
window.closeModal = closeModal;
window.openStatusModal = openStatusModal;
window.openSendEmailModal = openSendEmailModal;
window.openDeliveryModal = openDeliveryModal;
window.openInvoiceUploadModal = openInvoiceUploadModal;
window.closeReceivingModal = closeReceivingModal;
window.calculateInvoiceItemTotal = calculateInvoiceItemTotal;
window.purchaseOrdersManager = purchaseOrdersManager;
window.submitStockPurchase = submitStockPurchase;
window.updateStatusBadge = updateStatusBadge;
window.showNotification = showNotification;

console.log('Purchase Orders JS loaded with complete stock purchase functionality');