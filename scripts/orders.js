/**
 * scripts/orders.js
 * JavaScript functionality for the WMS Orders page.
 */

document.addEventListener('DOMContentLoaded', function() {
    console.log('Orders page successfully loaded and scripts are running.');
});

let itemCounter = 1;

/**
 * Opens the modal for creating a new order.
 */
function openCreateModal() {
    document.getElementById('createOrderModal').classList.add('show');
}

/**
 * Closes and resets the "Create Order" modal.
 */
function closeCreateModal() {
    document.getElementById('createOrderModal').classList.remove('show');
    document.getElementById('createOrderForm').reset();
    resetOrderItems();
}

/**
 * Opens the modal to change an order's status.
 * @param {number} orderId The ID of the order to update.
 * @param {string} currentStatus The current status of the order.
 */
function openStatusModal(orderId, currentStatus) {
    document.getElementById('statusOrderId').value = orderId;
    document.getElementById('updateStatus').value = currentStatus;
    document.getElementById('statusModal').classList.add('show');
}

/**
 * Closes the "Update Status" modal.
 */
function closeStatusModal() {
    document.getElementById('statusModal').classList.remove('show');
}

/**
 * Opens the confirmation modal for deleting an order.
 * @param {number} orderId The ID of the order to delete.
 * @param {string} orderNumber The human-readable order number for the confirmation message.
 */
function openDeleteModal(orderId, orderNumber) {
    document.getElementById('deleteOrderId').value = orderId;
    document.getElementById('deleteOrderNumber').textContent = orderNumber;
    document.getElementById('deleteModal').classList.add('show');
}

/**
 * Closes the "Delete Order" confirmation modal.
 */
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
}

/**
 * Opens the details modal and fetches the order data from the API.
 * @param {number} orderId The ID of the order to display.
 */
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

/**
 * Sends a request to the server to generate a Cargus AWB for the specified order.
 * @param {number} orderId The ID of the order.
 */
function generateAWB(orderId) {
    if (!confirm(`Sunteți sigur că doriți să generați un AWB pentru comanda #${orderId}?`)) {
        return;
    }

    console.log(`Generating AWB for order ${orderId}...`);

    // Get CSRF token from meta tag
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (!csrfToken) {
        alert('Eroare de securitate: CSRF token lipsește. Reîncărcați pagina și încercați din nou.');
        return;
    }

    fetch(`/web/awb.php/orders/${orderId}/awb`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
        },
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(errorData => {
                throw new Error(errorData.error || `HTTP ${response.status}`);
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert(`AWB ${data.data.awb_barcode} a fost generat cu succes! Pagina se va reîncărca.`);
            location.reload();
        } else {
            alert(`Eroare la generarea AWB: ${data.error || 'Răspuns nevalid de la server.'}`);
        }
    })
    .catch(error => {
        console.error('Error generating AWB:', error);
        
        let errorMessage = 'A apărut o eroare neașteptată în timpul comunicării cu serverul.';
        if (error.message.includes('Authentication required')) {
            errorMessage = 'Sesiunea a expirat. Vă rugăm să vă autentificați din nou.';
        } else if (error.message.includes('CSRF')) {
            errorMessage = 'Eroare de securitate. Reîncărcați pagina și încercați din nou.';
        } else if (error.message) {
            errorMessage = `Eroare: ${error.message}`;
        }
        
        alert(`Eroare la generarea AWB: ${errorMessage}`);
    });
}

/**
 * Placeholder function to handle printing the AWB.
 * @param {string} barcode The AWB barcode/tracking number.
 */
function printAWB(barcode) {
    alert(`Printează AWB: ${barcode}\n(Funcționalitatea de printare trebuie implementată.)`);
    // Example: window.open(`/api/awb/print/${barcode}`, '_blank');
}

/**
 * Dynamically adds a new product row to the "Create Order" form.
 */
function addOrderItem() {
    const container = document.getElementById('orderItems');
    const newItem = document.createElement('div');
    newItem.className = 'order-item';
    
    newItem.innerHTML = `
        <div class="row">
            <div class="form-group">
                <select name="items[${itemCounter}][product_id]" class="form-control item-product" required>
                    <option value="">Selectează produs</option>
                    ${getProductOptions()}
                </select>
            </div>
            <div class="form-group">
                <input type="number" name="items[${itemCounter}][quantity]" class="form-control item-quantity" placeholder="Cant." min="1" required>
            </div>
            <div class="form-group">
                <input type="number" name="items[${itemCounter}][unit_price]" class="form-control item-price" placeholder="Preț" step="0.01" min="0" required>
            </div>
            <div class="form-group form-group-sm">
                <button type="button" class="btn btn-danger btn-sm" onclick="removeOrderItem(this)" title="Șterge produs">
                    <span class="material-symbols-outlined">delete</span>
                </button>
            </div>
        </div>
    `;
    
    container.appendChild(newItem);
    itemCounter++;
}

/**
 * Removes a product row from the "Create Order" form.
 * @param {HTMLElement} button The remove button that was clicked.
 */
function removeOrderItem(button) {
    const container = document.getElementById('orderItems');
    if (container.children.length > 1) {
        button.closest('.order-item').remove();
    } else {
        alert('Comanda trebuie să conțină cel puțin un produs.');
    }
}

/**
 * Resets the "Create Order" form to its initial state with only one empty product row.
 */
function resetOrderItems() {
    const container = document.getElementById('orderItems');
    const items = container.querySelectorAll('.order-item');
    for (let i = 1; i < items.length; i++) {
        items[i].remove();
    }
    container.querySelector('.order-item').querySelectorAll('select, input').forEach(field => {
        field.value = '';
    });
    itemCounter = 1;
}

/**
 * Gets the HTML <option> tags from the first product dropdown to use in new rows.
 * @returns {string} A string of HTML <option> elements.
 */
function getProductOptions() {
    const originalSelect = document.querySelector('select[name="items[0][product_id]"]');
    return Array.from(originalSelect.options)
        .map(option => `<option value="${option.value}" data-price="${option.dataset.price || ''}">${escapeHTML(option.textContent)}</option>`)
        .join('');
}

/**
 * A security utility to prevent Cross-Site Scripting (XSS) attacks.
 * @param {string} str The string to sanitize.
 * @returns {string} The sanitized string.
 */
function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    return str.toString().replace(/[&<>"']/g, match => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[match]));
}

// Set up event listeners for page interactions.
function setupEventListeners() {
    // Listener for auto-filling the price when a product is selected.
    document.addEventListener('change', function(e) {
        if (e.target.matches('select[name*="[product_id]"]')) {
            const selectedOption = e.target.options[e.target.selectedIndex];
            const priceInput = e.target.closest('.row').querySelector('input[name*="unit_price"]');
            if (selectedOption.dataset.price && priceInput) {
                priceInput.value = selectedOption.dataset.price;
            }
        }
    });

    // Listener for closing modals.
    const allModals = document.querySelectorAll('.modal');
    window.addEventListener('click', function(event) {
        allModals.forEach(modal => {
            if (event.target === modal) {
                modal.classList.remove('show');
            }
        });
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            allModals.forEach(modal => modal.classList.remove('show'));
        }
    });

    // Listener for validating the "Create Order" form on submission.
    const createOrderForm = document.getElementById('createOrderForm');
    if (createOrderForm) {
        createOrderForm.addEventListener('submit', function(event) {
            const customerName = document.getElementById('customer_name').value.trim();
            if (!customerName) {
                event.preventDefault();
                alert('Numele clientului este obligatoriu!');
                return;
            }
            
            const items = document.querySelectorAll('#orderItems .order-item');
            let hasValidItems = false;
            items.forEach(item => {
                const productSelect = item.querySelector('select[name*="product_id"]');
                const quantityInput = item.querySelector('input[name*="quantity"]');
                if (productSelect.value && quantityInput.value) {
                    hasValidItems = true;
                }
            });
            
            if (!hasValidItems) {
                event.preventDefault();
                alert('Comanda trebuie să conțină cel puțin un produs valid (produs și cantitate).');
            }
        });
    }
}

// Initialize all event listeners when the page is ready.
setupEventListeners();