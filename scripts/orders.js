// File: scripts/orders.js
// JavaScript functionality for the orders page

let itemCounter = 1;

document.addEventListener('DOMContentLoaded', function() {
    console.log('Orders page loaded');
});

// Modal functions
function openCreateModal() {
    document.getElementById('createOrderModal').classList.add('show');
}

function closeCreateModal() {
    document.getElementById('createOrderModal').classList.remove('show');
    document.getElementById('createOrderForm').reset();
    resetOrderItems();
}

function openStatusModal(orderId, currentStatus) {
    document.getElementById('statusOrderId').value = orderId;
    document.getElementById('updateStatus').value = currentStatus;
    document.getElementById('statusModal').classList.add('show');
}

function closeStatusModal() {
    document.getElementById('statusModal').classList.remove('show');
}

function openDeleteModal(orderId, orderNumber) {
    document.getElementById('deleteOrderId').value = orderId;
    document.getElementById('deleteOrderNumber').textContent = orderNumber;
    document.getElementById('deleteModal').classList.add('show');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
}

// Order items management
function addOrderItem() {
    const container = document.getElementById('orderItems');
    const newItem = document.createElement('div');
    newItem.className = 'order-item';
    
    newItem.innerHTML = `
        <div class="row">
            <div class="form-group">
                <label class="form-label">Produs</label>
                <select name="items[${itemCounter}][product_id]" class="form-control" required>
                    <option value="">Selectează produs</option>
                    ${getProductOptions()}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Cantitate</label>
                <input type="number" name="items[${itemCounter}][quantity]" class="form-control" min="1" required>
            </div>
            <div class="form-group">
                <label class="form-label">Preț Unitar</label>
                <input type="number" name="items[${itemCounter}][unit_price]" class="form-control" step="0.01" min="0" required>
            </div>
            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <button type="button" class="btn btn-danger" onclick="removeOrderItem(this)">
                    <span class="material-symbols-outlined">delete</span>
                </button>
            </div>
        </div>
    `;
    
    container.appendChild(newItem);
    itemCounter++;
    
    // Add event listener for product selection
    const productSelect = newItem.querySelector('select');
    const priceInput = newItem.querySelector('input[name*="unit_price"]');
    
    productSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.dataset.price) {
            priceInput.value = selectedOption.dataset.price;
        }
    });
}

function removeOrderItem(button) {
    const container = document.getElementById('orderItems');
    if (container.children.length > 1) {
        button.closest('.order-item').remove();
    } else {
        alert('Comanda trebuie să conțină cel puțin un produs!');
    }
}

function resetOrderItems() {
    const container = document.getElementById('orderItems');
    // Keep only the first item and reset it
    const items = container.querySelectorAll('.order-item');
    for (let i = 1; i < items.length; i++) {
        items[i].remove();
    }
    
    // Reset first item
    const firstItem = container.querySelector('.order-item');
    firstItem.querySelectorAll('select, input').forEach(field => {
        field.value = '';
    });
    
    itemCounter = 1;
}

function getProductOptions() {
    const originalSelect = document.querySelector('select[name="items[0][product_id]"]');
    let options = '';
    
    for (let i = 0; i < originalSelect.options.length; i++) {
        const option = originalSelect.options[i];
        options += `<option value="${option.value}" data-price="${option.dataset.price || ''}">${option.textContent}</option>`;
    }
    
    return options;
}

function viewOrderDetails(orderId) {
    // Redirect to order details page or open details modal
    window.location.href = `order_details.php?id=${orderId}`;
}

// Product selection auto-price fill
document.addEventListener('change', function(e) {
    if (e.target.matches('select[name*="[product_id]"]')) {
        const selectedOption = e.target.options[e.target.selectedIndex];
        const priceInput = e.target.closest('.row').querySelector('input[name*="unit_price"]');
        
        if (selectedOption.dataset.price && priceInput) {
            priceInput.value = selectedOption.dataset.price;
        }
    }
});

// Close modals when clicking outside
window.onclick = function(event) {
    const modals = ['createOrderModal', 'statusModal', 'deleteModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target === modal) {
            modal.classList.remove('show');
        }
    });
}

// Close modals with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeCreateModal();
        closeStatusModal();
        closeDeleteModal();
    }
});

// Form validation
document.getElementById('createOrderForm').addEventListener('submit', function(event) {
    const customerName = document.getElementById('customer_name').value.trim();
    const items = document.querySelectorAll('#orderItems .order-item');
    
    if (!customerName) {
        event.preventDefault();
        alert('Numele clientului este obligatoriu!');
        return false;
    }
    
    let hasValidItems = false;
    items.forEach(item => {
        const productSelect = item.querySelector('select[name*="product_id"]');
        const quantityInput = item.querySelector('input[name*="quantity"]');
        const priceInput = item.querySelector('input[name*="unit_price"]');
        
        if (productSelect.value && quantityInput.value && priceInput.value) {
            hasValidItems = true;
        }
    });
    
    if (!hasValidItems) {
        event.preventDefault();
        alert('Comanda trebuie să conțină cel puțin un produs valid!');
        return false;
    }
});