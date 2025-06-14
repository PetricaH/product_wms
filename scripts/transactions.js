// JavaScript functions for Transaction Details API integration

/**
 * Fetch transaction details from API
 * @param {number} transactionId - Transaction ID
 * @param {boolean} fullDetails - Whether to fetch full details with items
 * @param {boolean} includeAudit - Whether to include audit trail
 * @returns {Promise<Object>} Transaction details
 */
async function fetchTransactionDetails(transactionId, fullDetails = true, includeAudit = false) {
    try {
        const params = new URLSearchParams({
            id: transactionId,
            full: fullDetails ? '1' : '0',
            audit: includeAudit ? '1' : '0'
        });
        
        const response = await fetch(`api/transaction_details.php?${params}`);
        
        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.error || `HTTP error! status: ${response.status}`);
        }
        
        return await response.json();
    } catch (error) {
        console.error('Error fetching transaction details:', error);
        throw error;
    }
}

/**
 * Display transaction details in a modal
 * @param {number} transactionId - Transaction ID
 */
async function showTransactionDetails(transactionId) {
    try {
        // Show loading state
        const modal = document.getElementById('transactionDetailsModal');
        const content = document.getElementById('transactionDetailsContent');
        
        content.innerHTML = `
            <div class="loading-state">
                <span class="material-symbols-outlined rotating">sync</span>
                <p>Se încarcă detaliile tranzacției...</p>
            </div>
        `;
        modal.style.display = 'block';
        
        // Fetch transaction details
        const transaction = await fetchTransactionDetails(transactionId, true, true);
        
        // Generate and display transaction details HTML
        content.innerHTML = generateTransactionDetailsHtml(transaction);
        
    } catch (error) {
        console.error('Error showing transaction details:', error);
        
        const content = document.getElementById('transactionDetailsContent');
        content.innerHTML = `
            <div class="error-state">
                <span class="material-symbols-outlined">error</span>
                <p>Eroare la încărcarea detaliilor: ${error.message}</p>
                <button onclick="closeDetailsModal()" class="btn btn-secondary">Închide</button>
            </div>
        `;
    }
}

/**
 * Load transaction for editing
 * @param {number} transactionId - Transaction ID
 */
async function editTransaction(transactionId) {
    try {
        // Fetch basic transaction details
        const transaction = await fetchTransactionDetails(transactionId, false, false);
        
        // Populate edit form
        populateEditForm(transaction);
        
        // Show edit modal
        document.getElementById('editTransactionModal').style.display = 'block';
        
    } catch (error) {
        console.error('Error loading transaction for edit:', error);
        alert('Eroare la încărcarea tranzacției pentru editare: ' + error.message);
    }
}

/**
 * Populate edit form with transaction data
 * @param {Object} transaction - Transaction data
 */
function populateEditForm(transaction) {
    const form = document.getElementById('editTransactionForm');
    if (!form) return;
    
    // Populate form fields
    const fields = [
        'transaction_type', 'amount', 'tax_amount', 'net_amount', 
        'currency', 'description', 'customer_name', 'supplier_name',
        'invoice_date', 'series', 'status'
    ];
    
    fields.forEach(fieldName => {
        const field = form.querySelector(`[name="${fieldName}"]`);
        if (field && transaction[fieldName] !== undefined) {
            field.value = transaction[fieldName];
        }
    });
    
    // Set transaction ID for update
    const idField = form.querySelector('[name="transaction_id"]');
    if (idField) {
        idField.value = transaction.id;
    }
}

/**
 * Enhanced transaction details HTML generator
 * @param {Object} transaction - Transaction data
 * @returns {string} HTML string
 */
function generateTransactionDetailsHtml(transaction) {
    // Status icons mapping
    const statusIcons = {
        'pending': 'schedule',
        'processing': 'sync', 
        'completed': 'check_circle',
        'failed': 'error',
        'cancelled': 'cancel'
    };
    
    // Generate items table
    let itemsHtml = '';
    if (transaction.items && transaction.items.length > 0) {
        itemsHtml = `
            <div class="detail-section">
                <h4><span class="material-symbols-outlined">inventory_2</span> Articole</h4>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Produs</th>
                                <th class="text-center">Cantitate</th>
                                <th class="text-right">Preț unitar</th>
                                <th class="text-center">TVA (%)</th>
                                <th class="text-center">Disc. (%)</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${transaction.items.map(item => `
                                <tr>
                                    <td>
                                        <strong>${item.sku || 'N/A'}</strong><br>
                                        <small class="text-muted">${item.product_name || 'Produs necunoscut'}</small>
                                    </td>
                                    <td class="text-center">${parseFloat(item.quantity || 0).toFixed(3)} ${item.unit_of_measure || 'buc'}</td>
                                    <td class="text-right">${parseFloat(item.unit_price || 0).toFixed(2)} ${transaction.currency}</td>
                                    <td class="text-center">${parseFloat(item.tax_percent || 0).toFixed(2)}%</td>
                                    <td class="text-center">${parseFloat(item.discount_percent || 0).toFixed(2)}%</td>
                                    <td class="text-right"><strong>${parseFloat(item.total_amount || item.line_total || 0).toFixed(2)} ${transaction.currency}</strong></td>
                                </tr>
                            `).join('')}
                        </tbody>
                        <tfoot>
                            <tr class="table-totals">
                                <td colspan="5" class="text-right"><strong>Subtotal:</strong></td>
                                <td class="text-right"><strong>${parseFloat(transaction.calculated_subtotal || transaction.amount - transaction.tax_amount).toFixed(2)} ${transaction.currency}</strong></td>
                            </tr>
                            <tr class="table-totals">
                                <td colspan="5" class="text-right"><strong>TVA:</strong></td>
                                <td class="text-right"><strong>${parseFloat(transaction.calculated_tax || transaction.tax_amount).toFixed(2)} ${transaction.currency}</strong></td>
                            </tr>
                            <tr class="table-totals">
                                <td colspan="5" class="text-right"><strong>Total:</strong></td>
                                <td class="text-right"><strong>${parseFloat(transaction.calculated_total || transaction.amount).toFixed(2)} ${transaction.currency}</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        `;
    }
    
    // Generate SmartBill sync info
    let smartbillHtml = '';
    if (transaction.smartbill_sync || transaction.smartbill_doc_number) {
        const syncStatus = transaction.sync_status || (transaction.smartbill_doc_number ? 'synced' : 'not_synced');
        const syncStatusClass = syncStatus === 'synced' ? 'success' : (syncStatus === 'failed' ? 'danger' : 'warning');
        
        smartbillHtml = `
            <div class="detail-section">
                <h4><span class="material-symbols-outlined">cloud_sync</span> SmartBill</h4>
                <div class="smartbill-info">
                    <p><strong>Status sincronizare:</strong> 
                        <span class="badge badge-${syncStatusClass}">${syncStatus}</span>
                    </p>
                    ${transaction.smartbill_doc_number ? `
                        <p><strong>Document SmartBill:</strong> ${transaction.smartbill_doc_type || 'N/A'} #${transaction.smartbill_doc_number}</p>
                    ` : ''}
                    ${transaction.sync_date ? `
                        <p><strong>Data sincronizării:</strong> ${new Date(transaction.sync_date).toLocaleString('ro-RO')}</p>
                    ` : ''}
                    ${transaction.sync_message ? `
                        <p><strong>Mesaj:</strong> <em>${transaction.sync_message}</em></p>
                    ` : ''}
                </div>
            </div>
        `;
    }
    
    // Generate audit trail
    let auditHtml = '';
    if (transaction.audit_trail && transaction.audit_trail.length > 0) {
        auditHtml = `
            <div class="detail-section">
                <h4><span class="material-symbols-outlined">history</span> Istoric modificări</h4>
                <div class="audit-trail">
                    ${transaction.audit_trail.map(entry => `
                        <div class="audit-entry">
                            <div class="audit-header">
                                <strong>${entry.action_type}</strong>
                                <span class="audit-time">${entry.created_at_formatted}</span>
                            </div>
                            <div class="audit-details">
                                <small>
                                    <strong>Utilizator:</strong> ${entry.user_display || 'System'}<br>
                                    ${entry.old_value ? `<strong>Valoare veche:</strong> ${entry.old_value}<br>` : ''}
                                    ${entry.new_value ? `<strong>Valoare nouă:</strong> ${entry.new_value}<br>` : ''}
                                    ${entry.description ? `<strong>Descriere:</strong> ${entry.description}` : ''}
                                </small>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }
    
    return `
        <div class="transaction-details">
            <div class="details-header">
                <h3>
                    <span class="material-symbols-outlined">${statusIcons[transaction.status] || 'description'}</span>
                    Tranzacția #${transaction.id}
                </h3>
                <span class="status-badge status-${transaction.status}">
                    ${transaction.status_label || transaction.status}
                </span>
            </div>
            
            <div class="details-grid">
                <div class="detail-section">
                    <h4><span class="material-symbols-outlined">info</span> Informații generale</h4>
                    <div class="info-grid">
                        <p><strong>Tip tranzacție:</strong> ${transaction.type_label || transaction.transaction_type}</p>
                        <p><strong>Referință:</strong> ${transaction.reference_type_label || transaction.reference_type} 
                           ${transaction.reference_id ? `#${transaction.reference_id}` : ''}</p>
                        <p><strong>Data creării:</strong> ${new Date(transaction.created_at).toLocaleString('ro-RO')}</p>
                        <p><strong>Ultima actualizare:</strong> ${new Date(transaction.updated_at).toLocaleString('ro-RO')}</p>
                        ${transaction.description ? `<p><strong>Descriere:</strong> ${transaction.description}</p>` : ''}
                    </div>
                </div>
                
                <div class="detail-section">
                    <h4><span class="material-symbols-outlined">person</span> Părți implicate</h4>
                    <div class="info-grid">
                        ${transaction.customer_name ? `<p><strong>Client:</strong> ${transaction.customer_name}</p>` : ''}
                        ${transaction.supplier_name ? `<p><strong>Furnizor:</strong> ${transaction.supplier_name}</p>` : ''}
                        ${transaction.party_name ? `<p><strong>Partea:</strong> ${transaction.party_name}</p>` : ''}
                        ${transaction.invoice_date ? `<p><strong>Data facturii:</strong> ${transaction.invoice_date}</p>` : ''}
                        ${transaction.series ? `<p><strong>Serie:</strong> ${transaction.series}</p>` : ''}
                    </div>
                </div>
                
                <div class="detail-section">
                    <h4><span class="material-symbols-outlined">payments</span> Valori financiare</h4>
                    <div class="amount-details">
                        <div class="amount-row">
                            <span>Sumă netă:</span>
                            <strong>${parseFloat(transaction.net_amount || 0).toFixed(2)} ${transaction.currency}</strong>
                        </div>
                        <div class="amount-row">
                            <span>TVA:</span>
                            <strong>${parseFloat(transaction.tax_amount || 0).toFixed(2)} ${transaction.currency}</strong>
                        </div>
                        <div class="amount-row total">
                            <span>Total:</span>
                            <strong>${parseFloat(transaction.amount || 0).toFixed(2)} ${transaction.currency}</strong>
                        </div>
                    </div>
                </div>
            </div>
            
            ${itemsHtml}
            ${smartbillHtml}
            ${auditHtml}
            
            <div class="details-actions">
                <button onclick="closeDetailsModal()" class="btn btn-secondary">
                    <span class="material-symbols-outlined">close</span> Închide
                </button>
                ${transaction.status === 'pending' || transaction.status === 'failed' ? `
                    <button onclick="retryTransaction(${transaction.id})" class="btn btn-warning">
                        <span class="material-symbols-outlined">replay</span> Reîncearcare
                    </button>
                ` : ''}
                ${transaction.status === 'pending' ? `
                    <button onclick="cancelTransaction(${transaction.id})" class="btn btn-danger">
                        <span class="material-symbols-outlined">cancel</span> Anulare
                    </button>
                ` : ''}
                ${transaction.status === 'completed' && !transaction.smartbill_doc_number ? `
                    <button onclick="syncNow(${transaction.id})" class="btn btn-primary">
                        <span class="material-symbols-outlined">cloud_sync</span> Sincronizare SmartBill
                    </button>
                ` : ''}
            </div>
        </div>
    `;
}

/**
 * Close transaction details modal
 */
function closeDetailsModal() {
    const modal = document.getElementById('transactionDetailsModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

/**
 * Refresh transaction data in current view
 * @param {number} transactionId - Optional specific transaction to refresh
 */
async function refreshTransactionData(transactionId = null) {
    try {
        if (transactionId) {
            // Refresh specific transaction row
            const row = document.querySelector(`tr[data-transaction-id="${transactionId}"]`);
            if (row) {
                const transaction = await fetchTransactionDetails(transactionId, false);
                updateTransactionRow(row, transaction);
            }
        } else {
            // Refresh entire page/table
            location.reload();
        }
    } catch (error) {
        console.error('Error refreshing transaction data:', error);
    }
}

/**
 * Update transaction row with new data
 * @param {HTMLElement} row - Table row element
 * @param {Object} transaction - Updated transaction data
 */
function updateTransactionRow(row, transaction) {
    // Update status badge
    const statusBadge = row.querySelector('.status-badge');
    if (statusBadge) {
        statusBadge.className = `status-badge status-${transaction.status}`;
        statusBadge.textContent = transaction.status_label || transaction.status;
    }
    
    // Update amount if displayed
    const amountCell = row.querySelector('.amount-cell');
    if (amountCell) {
        amountCell.innerHTML = `
            <div class="amount-info">
                <strong>${parseFloat(transaction.amount).toFixed(2)} ${transaction.currency}</strong>
                ${transaction.tax_amount ? `<small>TVA: ${parseFloat(transaction.tax_amount).toFixed(2)} ${transaction.currency}</small>` : ''}
            </div>
        `;
    }
}

// Export functions for global use
window.fetchTransactionDetails = fetchTransactionDetails;
window.showTransactionDetails = showTransactionDetails;
window.editTransaction = editTransaction;
window.closeDetailsModal = closeDetailsModal;
window.refreshTransactionData = refreshTransactionData;
//# sourceMappingURL=transactions.js.map
