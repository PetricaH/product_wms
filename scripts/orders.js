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
    
    // Show loading state
    const content = document.getElementById('orderDetailsContent');
    content.innerHTML = '<div class="loading" style="text-align: center; padding: 2rem; color: #666;">Se încarcă detaliile comenzii...</div>';
    modal.style.display = 'block';
    
    // Fetch order details via the warehouse API
    fetch(`api/warehouse/order_details.php?id=${orderId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Server returned non-JSON response');
            }
            
            return response.json();
        })
        .then(response => {
            // Debug log
            console.log('API Response:', response);
            
            if (response.status !== 'success') {
                throw new Error(response.message || 'API returned error status');
            }
            
            // Extract the actual order data from the nested structure
            const order = response.data;
            displayOrderDetails(order);
        })
        .catch(error => {
            console.error('Error loading order details:', error);
            
            content.innerHTML = `
                <div class="error-message" style="text-align: center; padding: 2rem;">
                    <h3 style="color: #dc3545; margin-bottom: 1rem;">Eroare la încărcarea detaliilor comenzii</h3>
                    <p style="color: #666; margin-bottom: 1.5rem;">${error.message}</p>
                    <button onclick="closeOrderDetailsModal()" class="btn btn-secondary" style="padding: 0.5rem 1rem; background-color: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Închide</button>
                </div>
            `;
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
    // Debug: log the order data to see what we're getting
    console.log('Order data for display:', order);
    
    // Ensure items is always an array
    const items = order.items || [];
    
    // Generate items table HTML
    let itemsTableHtml = '';
    if (items.length > 0) {
        itemsTableHtml = `
            <div class="items-section" style="margin-top: 2rem;">
                <h4>Produse comandate</h4>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: #f5f5f5;">
                            <th style="padding: 8px; border: 1px solid #ddd;">Produs</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">SKU</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Cantitate</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Cantitate ridicată</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Preț unitar</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${items.map(item => `
                            <tr>
                                <td style="padding: 8px; border: 1px solid #ddd;">${item.product_name || 'Produs necunoscut'}</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">${item.sku || '-'}</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">${item.quantity_ordered || item.quantity || '0'}</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">${item.picked_quantity || '0'}</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">${parseFloat(item.unit_price || 0).toFixed(2)} RON</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">${(parseFloat(item.quantity_ordered || item.quantity || 0) * parseFloat(item.unit_price || 0)).toFixed(2)} RON</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    } else {
        itemsTableHtml = `
            <div class="items-section" style="margin-top: 2rem;">
                <h4>Produse comandate</h4>
                <p style="color: #666; font-style: italic;">Nu au fost găsite produse pentru această comandă.</p>
            </div>
        `;
    }
    
    // Format the date nicely
    let formattedDate = order.order_date || 'N/A';
    if (order.order_date && order.order_date !== 'N/A') {
        try {
            const date = new Date(order.order_date);
            formattedDate = date.toLocaleDateString('ro-RO') + ' ' + date.toLocaleTimeString('ro-RO', {hour: '2-digit', minute: '2-digit'});
        } catch (e) {
            formattedDate = order.order_date;
        }
    }
    
    const content = `
        <div class="order-details">
            <div class="details-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                <div class="detail-section">
                    <h4>Informații Comandă</h4>
                    <p><strong>Număr:</strong> ${order.order_number || 'N/A'}</p>
                    <p><strong>Data:</strong> ${formattedDate}</p>
                    <p><strong>Status:</strong> ${order.status_label || order.status || 'N/A'}</p>
                    <p><strong>Valoare:</strong> ${parseFloat(order.total_value || 0).toFixed(2)} RON</p>
                    ${order.tracking_number ? `<p><strong>AWB:</strong> ${order.tracking_number}</p>` : ''}
                </div>
                <div class="detail-section">
                    <h4>Informații Client</h4>
                    <p><strong>Nume:</strong> ${order.customer_name || 'N/A'}</p>
                    <p><strong>Email:</strong> ${order.customer_email || 'N/A'}</p>
                    <p><strong>Adresă:</strong> ${order.shipping_address || 'Nu este specificată'}</p>
                </div>
            </div>
            
            ${order.progress ? `
                <div class="progress-section" style="margin-top: 2rem;">
                    <h4>Progres comandă</h4>
                    <p><strong>Total articole:</strong> ${order.progress.total_items || 0}</p>
                    <p><strong>Cantitate comandată:</strong> ${order.progress.total_quantity_ordered || 0}</p>
                    <p><strong>Cantitate ridicată:</strong> ${order.progress.total_quantity_picked || 0}</p>
                    <p><strong>Rămas de ridicat:</strong> ${order.progress.total_remaining || 0}</p>
                    <p><strong>Progres:</strong> ${order.progress.progress_percent || 0}%</p>
                </div>
            ` : ''}
            
            ${itemsTableHtml}
            
            ${order.notes ? `
                <div class="notes-section" style="margin-top: 2rem;">
                    <h4>Observații</h4>
                    <p>${order.notes}</p>
                </div>
            ` : ''}
        </div>
    `;
    
    document.getElementById('orderDetailsContent').innerHTML = content;
}

function closeOrderDetailsModal() {
    const modal = document.getElementById('orderDetailsModal');
    if (modal) {
        modal.style.display = 'none';
    }
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

    // Use query parameters instead of path info
    const baseUrl = window.APP_CONFIG && window.APP_CONFIG.baseUrl ? window.APP_CONFIG.baseUrl : '';
    const awbUrl = `${baseUrl.replace(/\/$/, '')}/web/awb.php?order_id=${orderId}`;

    console.log(`Making request to: ${awbUrl}`);

    fetch(awbUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            order_id: orderId,
            csrf_token: csrfToken
        })
    })
    .then(response => {
        console.log(`Response status: ${response.status}`);
        
        if (!response.ok) {
            return response.text().then(errorText => {
                console.error('Error response:', errorText);
                try {
                    const errorData = JSON.parse(errorText);
                    throw new Error(errorData.error || `HTTP ${response.status}`);
                } catch (parseError) {
                    throw new Error(`HTTP ${response.status}: ${errorText.substring(0, 200)}`);
                }
            });
        }
        return response.json();
    })
    .then(data => {
        console.log('Success response:', data);

        if (data.success) {
            const barcode = data.data?.awb_barcode || data.data?.barcode || data.barcode || data.awb_barcode || '';
            alert(`AWB ${barcode || 'necunoscut'} a fost generat cu succes! Pagina se va reîncărca.`);
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
        } else if (error.message.includes('Order not found')) {
            errorMessage = 'Comanda nu a fost găsită.';
        } else if (error.message.includes('AWB already exists')) {
            errorMessage = 'AWB-ul a fost deja generat pentru această comandă.';
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
 * Send request to generate and print the invoice for a specific order.
 * @param {number} orderId Order ID
 */
function printInvoice(orderId, printerId = null) {
    if (!confirm(`Trimite factura la imprimantă pentru comanda #${orderId}?`)) {
        return;
    }

    // Show loading state
    const printBtn = document.querySelector(`button[onclick="printInvoice(${orderId})"]`);
    if (printBtn) {
        printBtn.disabled = true;
        printBtn.innerHTML = '<span class="material-symbols-outlined spinning">hourglass_empty</span>';
    }

    const formData = new FormData();
    formData.append('order_id', orderId);
    if (printerId) {
        formData.append('printer_id', printerId);
    }

    fetch('api/invoices/print_invoice_network.php', {
        method: 'POST',
        body: formData
    })
        .then(resp => resp.text())
        .then(text => {
            try {
                const data = JSON.parse(text);
                if (data.status === 'success') {
                    showNotification(
                        `Factura a fost trimisă la imprimantă: ${data.printer || 'imprimanta implicită'}`,
                        'success'
                    );
                    
                    // Update print job if available
                    if (data.job_id) {
                        console.log(`Print job created: ${data.job_id}`);
                    }
                } else {
                    showNotification(
                        `Eroare la imprimare: ${data.message}`,
                        'error'
                    );
                    
                    // Show detailed error for admins
                    if (data.job_id && window.userRole === 'admin') {
                        console.error('Print job failed:', data);
                    }
                }
            } catch (e) {
                console.error('Parse error:', text);
                showNotification('Eroare la procesarea răspunsului de la server', 'error');
            }
        })
        .catch(err => {
            console.error('Print request failed:', err);
            let errorMessage = 'A apărut o eroare neașteptată în timpul imprimării.';
            
            if (err.message.includes('503')) {
                errorMessage = 'Serverul de imprimare nu este disponibil. Verificați conexiunea cu imprimanta.';
            } else if (err.message.includes('404')) {
                errorMessage = 'Nu s-a găsit o imprimantă configurată pentru facturi.';
            } else if (err.message.includes('Authentication')) {
                errorMessage = 'Sesiunea a expirat. Vă rugăm să vă autentificați din nou.';
            }
            
            showNotification(`Eroare la imprimare: ${errorMessage}`, 'error');
        })
        .finally(() => {
            // Restore button state
            if (printBtn) {
                printBtn.disabled = false;
                printBtn.innerHTML = '<span class="material-symbols-outlined">print</span>';
            }
        });
}

/**
 * Enhanced print invoice with printer selection
 * @param {number} orderId Order ID
 */
function printInvoiceWithSelection(orderId) {
    // First, get available printers
    fetch('api/printer_management.php?path=printers')
        .then(resp => resp.json())
        .then(printers => {
            const invoicePrinters = printers.filter(p => 
                p.is_active && 
                p.print_server && 
                p.print_server.is_active && 
                (p.printer_type === 'invoice' || p.printer_type === 'document')
            );
            
            if (invoicePrinters.length === 0) {
                showNotification('Nu sunt disponibile imprimante pentru facturi.', 'warning');
                return;
            }
            
            if (invoicePrinters.length === 1) {
                // Only one printer available, use it directly
                printInvoice(orderId, invoicePrinters[0].id);
                return;
            }
            
            // Multiple printers - show selection modal
            showPrinterSelectionModal(orderId, invoicePrinters);
        })
        .catch(err => {
            console.error('Failed to load printers:', err);
            // Fallback to default printer
            printInvoice(orderId);
        });
}

/**
 * Show printer selection modal
 * @param {number} orderId 
 * @param {Array} printers 
 */
function showPrinterSelectionModal(orderId, printers) {
    const modal = document.createElement('div');
    modal.className = 'modal show';
    modal.innerHTML = `
        <div class="modal-content" style="max-width: 400px;">
            <h3>Selectează imprimanta</h3>
            <p>Selectează imprimanta pentru factura comenzii #${orderId}:</p>
            
            <div style="margin: 1.5rem 0;">
                ${printers.map(printer => `
                    <div style="margin-bottom: 1rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="radio" name="selected-printer" value="${printer.id}" 
                                   ${printer.is_default ? 'checked' : ''}>
                            <div>
                                <strong>${printer.name}</strong>
                                <br>
                                <small style="color: var(--text-muted);">
                                    ${printer.print_server.name} - ${printer.printer_type}
                                    ${printer.is_default ? ' (implicit)' : ''}
                                </small>
                            </div>
                        </label>
                    </div>
                `).join('')}
            </div>
            
            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                <button class="btn btn-secondary" onclick="this.closest('.modal').remove()">
                    Anulează
                </button>
                <button class="btn btn-primary" onclick="confirmPrinterSelection(${orderId})">
                    Printează
                </button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Auto-select default printer if available
    const defaultPrinter = modal.querySelector('input[type="radio"]:checked');
    if (!defaultPrinter && printers.length > 0) {
        modal.querySelector('input[type="radio"]').checked = true;
    }
}

/**
 * Confirm printer selection and print
 * @param {number} orderId 
 */
function confirmPrinterSelection(orderId) {
    const selectedPrinter = document.querySelector('input[name="selected-printer"]:checked');
    if (!selectedPrinter) {
        showNotification('Vă rugăm să selectați o imprimantă.', 'warning');
        return;
    }
    
    const printerId = parseInt(selectedPrinter.value);
    
    // Close modal
    document.querySelector('.modal').remove();
    
    // Print with selected printer
    printInvoice(orderId, printerId);
}

/**
 * Enhanced notification system
 * @param {string} message 
 * @param {string} type 
 */
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span class="material-symbols-outlined">
                ${type === 'success' ? 'check_circle' : type === 'error' ? 'error' : type === 'warning' ? 'warning' : 'info'}
            </span>
            <span>${message}</span>
        </div>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; color: inherit; cursor: pointer;">
            <span class="material-symbols-outlined">close</span>
        </button>
    `;
    
    // Add styles if not already present
    if (!document.querySelector('#notification-styles')) {
        const styles = document.createElement('style');
        styles.id = 'notification-styles';
        styles.textContent = `
            .notification {
                position: fixed;
                top: 20px;
                right: 20px;
                background: var(--container-background);
                border: 1px solid var(--border-color);
                border-radius: var(--border-radius);
                padding: 1rem;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                z-index: 10000;
                display: flex;
                justify-content: space-between;
                align-items: center;
                min-width: 300px;
                animation: slideInRight 0.3s ease;
            }
            
            .notification-success {
                border-left: 4px solid #22c55e;
                background: rgba(34, 197, 94, 0.05);
            }
            
            .notification-error {
                border-left: 4px solid #ef4444;
                background: rgba(239, 68, 68, 0.05);
            }
            
            .notification-warning {
                border-left: 4px solid #fbbf24;
                background: rgba(251, 191, 36, 0.05);
            }
            
            .notification-info {
                border-left: 4px solid #3b82f6;
                background: rgba(59, 130, 246, 0.05);
            }
            
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            
            .spinning {
                animation: spin 1s linear infinite;
            }
            
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(styles);
    }
    
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}

/**
 * Check print server status and show indicators
 */
async function checkPrintServerStatus() {
    try {
        const response = await fetch('api/printer_management.php?path=print-servers');
        const servers = await response.json();
        
        const onlineServers = servers.filter(s => s.is_active).length;
        const totalServers = servers.length;
        
        // Update UI indicator if present
        const statusIndicator = document.getElementById('print-server-status');
        if (statusIndicator) {
            statusIndicator.innerHTML = `
                <span class="material-symbols-outlined">print</span>
                Servere imprimare: ${onlineServers}/${totalServers} online
            `;
            statusIndicator.className = `status-indicator ${onlineServers > 0 ? 'status-online' : 'status-offline'}`;
        }
        
        return { online: onlineServers, total: totalServers };
    } catch (error) {
        console.error('Failed to check print server status:', error);
        return { online: 0, total: 0 };
    }
}

// Initialize print server status checking
document.addEventListener('DOMContentLoaded', function() {
    // Check status initially
    checkPrintServerStatus();
    
    // Check every 30 seconds
    setInterval(checkPrintServerStatus, 30000);
});

// Enhanced print functions for different document types
function printAWB(orderId, awbCode) {
    // Similar implementation for AWB printing
    console.log(`Print AWB ${awbCode} for order ${orderId}`);
    showNotification('AWB printing not implemented yet', 'info');
}

function printLabel(orderId, labelType = 'shipping') {
    // Implementation for label printing
    console.log(`Print ${labelType} label for order ${orderId}`);
    showNotification('Label printing not implemented yet', 'info');
}

function printPackingList(orderId) {
    // Implementation for packing list printing
    console.log(`Print packing list for order ${orderId}`);
    showNotification('Packing list printing not implemented yet', 'info');
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