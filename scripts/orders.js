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
    // Create modal if it doesn't exist
    let modal = document.getElementById('orderDetailsModal');
    if (!modal) {
        modal = createOrderDetailsModal();
        document.body.appendChild(modal);
    }
    
    // Fetch order details via AJAX
    fetch(`order_details.php?id=${orderId}`)
        .then(response => response.json())
        .then(order => {
            displayOrderDetails(order);
            modal.style.display = 'block';
        })
        .catch(error => {
            console.error('Error loading order details:', error);
            alert('Error loading order details');
        });
}

function createOrderDetailsModal() {
    const modal = document.createElement('div');
    modal.id = 'orderDetailsModal';
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h2>Detalii Comandă</h2>
                <span class="close" onclick="closeOrderDetailsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="orderDetailsContent"></div>
            </div>
        </div>
    `;
    return modal;
}

function displayOrderDetails(order) {
    const content = `
        <div class="order-details">
            <div class="details-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                <div class="detail-section">
                    <h4>Informații Comandă</h4>
                    <p><strong>Număr:</strong> ${order.order_number}</p>
                    <p><strong>Data:</strong> ${order.order_date}</p>
                    <p><strong>Status:</strong> ${order.status}</p>
                    <p><strong>Prioritate:</strong> ${order.priority}</p>
                    <p><strong>Valoare:</strong> ${order.total_value} RON</p>
                </div>
                <div class="detail-section">
                    <h4>Informații Client</h4>
                    <p><strong>Nume:</strong> ${order.customer_name}</p>
                    <p><strong>Email:</strong> ${order.customer_email}</p>
                    <p><strong>Adresă:</strong> ${order.shipping_address || 'Nu este specificată'}</p>
                </div>
            </div>
            
            <div class="items-section" style="margin-top: 2rem;">
                <h4>Produse comandate</h4>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: #f5f5f5;">
                            <th style="padding: 8px; border: 1px solid #ddd;">Produs</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">SKU</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Cantitate</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Preț unitar</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${order.items.map(item => `
                            <tr>
                                <td style="padding: 8px; border: 1px solid #ddd;">${item.product_name}</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">${item.sku}</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">${item.quantity}</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">${item.unit_price} RON</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">${(item.quantity * item.unit_price).toFixed(2)} RON</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            
            ${order.notes ? `
                <div class="notes-section" style="margin-top: 2rem;">
                    <h4>Notițe</h4>
                    <p>${order.notes}</p>
                </div>
            ` : ''}
        </div>
    `;
    
    document.getElementById('orderDetailsContent').innerHTML = content;
}

function closeOrderDetailsModal() {
    document.getElementById('orderDetailsModal').style.display = 'none';
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