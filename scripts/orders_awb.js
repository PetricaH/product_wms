// File: scripts/orders_awb.js
// AWB generation functionality for orders page and picking interface

document.addEventListener('DOMContentLoaded', function() {
    initializeAWBGeneration();
    initializeAWBStatus();
});

function initializeAWBGeneration() {
    // Add event listeners for AWB buttons
    document.addEventListener('click', function(e) {
        if (e.target.matches('.generate-awb-btn') || e.target.closest('.generate-awb-btn')) {
            const button = e.target.matches('.generate-awb-btn') ? e.target : e.target.closest('.generate-awb-btn');
            const orderId = button.getAttribute('data-order-id');
            if (orderId) {
                generateAWB(orderId);
            }
        }
    });
}

function initializeAWBStatus() {
    document.querySelectorAll('.awb-status').forEach(el => {
        const awb = el.getAttribute('data-awb');
        if (awb) {
            fetchAwbStatus(awb, el);
        }
    });

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.refresh-status-btn');
        if (btn) {
            const awb = btn.getAttribute('data-awb');
            const el = document.querySelector(`.awb-status[data-awb="${awb}"]`);
            if (el) {
                el.textContent = 'Se verifică...';
            }
            fetchAwbStatus(awb, el, true);
        }
    });
}

/**
 * Generate AWB for order with requirements check
 */
async function generateAWB(orderId) {
    if (!orderId) {
        showNotification('ID comandă lipsă', 'error');
        return;
    }
    
    try {
        // First check requirements
        const reqResponse = await fetch(`/api/awb/generate_awb.php?action=requirements&order_id=${orderId}`);
        const reqJson = await reqResponse.json();
        if (!reqJson.success) {
            showNotification(reqJson.error || 'Eroare la verificarea cerințelor', 'error');
            return;
        }
        const requirements = reqJson.data || {};

        if (!requirements.can_generate) {
            showAWBRequirementsModal(orderId, requirements);
            return;
        }
        
        // Can generate directly
        await generateAWBDirect(orderId);
        
    } catch (error) {
        console.error('AWB requirements check failed:', error);
        showNotification('Eroare la verificarea cerințelor AWB', 'error');
    }
}

/**
 * Generate AWB directly (all requirements met)
 */
async function generateAWBDirect(orderId, manualData = {}) {
    const button = document.querySelector(`.generate-awb-btn[data-order-id="${orderId}"]`);
    const originalContent = button ? button.innerHTML : '';
    
    if (button) {
        button.disabled = true;
        button.innerHTML = '<span class="material-symbols-outlined spinning">hourglass_empty</span> Se generează...';
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'generate');
        formData.append('order_id', orderId);
        
        // Add CSRF token
        const csrfToken = getCsrfToken();
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
        
        // Add manual data
        Object.keys(manualData).forEach(key => {
            if (manualData[key] !== undefined && manualData[key] !== '') {
                formData.append(key, manualData[key]);
            }
        });
        
        const response = await fetch('/api/awb/generate_awb.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();

        if (result.success) {
            const awbCode = result.data?.awb_barcode || result.data?.barcode || '';
            showNotification(`AWB generat cu succes: ${awbCode || 'necunoscut'}`, 'success');

            updateOrderAWBStatus(orderId, awbCode);

            setTimeout(() => {
                window.location.reload();
            }, 2000);

        } else {
            if (result.data?.require_manual_input) {
                showAWBManualInputModal(orderId, result.data);
            } else {
                showNotification(`Eroare la generarea AWB: ${result.error}`, 'error');
            }
        }
        
    } catch (error) {
        console.error('AWB generation failed:', error);
        showNotification('A apărut o eroare la generarea AWB', 'error');
    } finally {
        if (button) {
            button.disabled = false;
            button.innerHTML = originalContent;
        }
    }
}

async function fetchAwbStatus(awb, el, refresh = false) {
    if (!el) return;
    try {
        const url = `/api/awb/track_awb.php?awb=${encodeURIComponent(awb)}${refresh ? '&refresh=1' : ''}`;
        const res = await fetch(url);
        const data = await res.json();
        if (data.success && data.data) {
            el.textContent = data.data.status || 'Status necunoscut';
        } else {
            el.textContent = 'Status unavailable';
        }
    } catch (err) {
        console.error('Status fetch failed', err);
        el.textContent = 'Status unavailable';
    }
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]));
}

async function printAWB(orderId, awbCode, orderNumber) {
    try {
        const form = new FormData();
        form.append('order_id', orderId);
        form.append('awb', awbCode);
        const csrf = getCsrfToken();
        if (csrf) form.append('csrf_token', csrf);
        fetch('/api/awb/log_print.php', {method: 'POST', body: form});
    } catch (e) {}

    try {
        const w = window.open('', '_blank');
        if (!w) throw new Error('Popup blocked');
        w.document.write(`<!DOCTYPE html><html><head><title>AWB ${escapeHtml(awbCode)}</title><style>body{font-family:Arial,sans-serif;text-align:center;margin:40px}.barcode{font-size:32px;margin-top:20px}</style></head><body><h1>AWB: ${escapeHtml(awbCode)}</h1><p>Comanda: ${escapeHtml(orderNumber)}</p><div class="barcode">${escapeHtml(awbCode)}</div><script>window.print();</script></body></html>`);
        w.document.close();
    } catch (err) {
        console.error('Print error', err);
        try {
            await navigator.clipboard.writeText(awbCode);
            showNotification('AWB copiat în clipboard', 'info');
        } catch (copyErr) {
            showNotification('Nu se poate printa sau copia AWB', 'error');
        }
    }
}

/**
 * Show AWB requirements modal
 */
function showAWBRequirementsModal(orderId, requirements) {
    const modalHtml = `
        <div id="awb-requirements-modal" class="modal" style="display: flex;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Cerințe pentru generarea AWB</h3>
                    <button type="button" class="close-modal" onclick="closeAWBModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="modal-body">
                    ${requirements.existing_barcode ? `
                        <div class="alert alert-info">
                            <span class="material-symbols-outlined">info</span>
                            AWB deja generat: <strong>${requirements.existing_barcode}</strong>
                        </div>
                    ` : `
                        <div class="alert alert-warning">
                            <span class="material-symbols-outlined">warning</span>
                            Nu se poate genera AWB. Cerințe lipsă:
                        </div>
                        <ul class="requirements-list">
                            ${requirements.requirements.map(req => `<li>${req}</li>`).join('')}
                        </ul>
                        
                        ${requirements.weight_info && requirements.weight_info.weight > 0 ? `
                            <div class="weight-info">
                                <p><strong>Greutate calculată:</strong> ${requirements.weight_info.weight} kg</p>
                                ${requirements.weight_info.warning ? `<p class="warning">${requirements.weight_info.warning}</p>` : ''}
                            </div>
                        ` : ''}
                        
                        ${requirements.address_parsed ? `
                            <div class="address-info">
                                <p><strong>Adresă detectată:</strong></p>
                                <ul>
                                    ${requirements.address_parsed.county ? `<li>Județ: ${requirements.address_parsed.county}</li>` : ''}
                                    ${requirements.address_parsed.locality ? `<li>Localitate: ${requirements.address_parsed.locality}</li>` : ''}
                                    ${requirements.address_parsed.street ? `<li>Stradă: ${requirements.address_parsed.street}</li>` : ''}
                                </ul>
                            </div>
                        ` : ''}
                    `}
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeAWBModal()">
                            Închide
                        </button>
                        ${!requirements.existing_barcode && requirements.requirements.length > 0 ? `
                            <button type="button" class="btn btn-primary" onclick="showAWBManualInputModal(${orderId}, ${JSON.stringify(requirements).replace(/"/g, '&quot;')})">
                                Completează Manual
                            </button>
                        ` : ''}
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existing = document.getElementById('awb-requirements-modal');
    if (existing) {
        existing.remove();
    }
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    document.body.style.overflow = 'hidden';
}

/**
 * Show manual input modal for AWB generation
 */
function showAWBManualInputModal(orderId, requirementsData) {
    const orderData = requirementsData.order_data || {};
    const addressParsed = requirementsData.parsed_address || {};
    
    const modalHtml = `
        <div id="awb-manual-modal" class="modal" style="display: flex;">
            <div class="modal-content large-modal">
                <div class="modal-header">
                    <h3>Completare date AWB - Comanda #${orderData.order_number || orderId}</h3>
                    <button type="button" class="close-modal" onclick="closeAWBModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="awb-manual-form">
                        <div class="form-grid">
                            <!-- Recipient Info -->
                            <div class="form-section">
                                <h4>Informații Destinatar</h4>
                                <div class="form-group">
                                    <label>Nume destinatar *</label>
                                    <input type="text" name="recipient_name" value="${orderData.customer_name || ''}" required>
                                </div>
                                <div class="form-group">
                                    <label>Persoană contact</label>
                                    <input type="text" name="recipient_contact_person" value="${orderData.customer_name || ''}">
                                </div>
                                <div class="form-group">
                                    <label>Telefon *</label>
                                    <input type="text" name="recipient_phone" value="${orderData.recipient_phone || ''}" required>
                                </div>
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" name="recipient_email" value="${orderData.customer_email || ''}">
                                </div>
                            </div>
                            
                            <!-- Address Info -->
                            <div class="form-section">
                                <h4>Adresă Livrare</h4>
                                <div class="form-group">
                                    <label>ID Județ Cargus *</label>
                                    <input type="number" name="recipient_county_id" value="${orderData.recipient_county_id || ''}" required>
                                    <small>Județul detectat: ${addressParsed.county || 'N/A'}</small>
                                </div>
                                <div class="form-group">
                                    <label>Nume Județ</label>
                                    <input type="text" name="recipient_county_name" value="${orderData.recipient_county_name || addressParsed.county || ''}">
                                </div>
                                <div class="form-group">
                                    <label>ID Localitate Cargus *</label>
                                    <input type="number" name="recipient_locality_id" value="${orderData.recipient_locality_id || ''}" required>
                                    <small>Localitatea detectată: ${addressParsed.locality || 'N/A'}</small>
                                </div>
                                <div class="form-group">
                                    <label>Nume Localitate</label>
                                    <input type="text" name="recipient_locality_name" value="${orderData.recipient_locality_name || addressParsed.locality || ''}">
                                </div>
                                <div class="form-group">
                                    <label>Adresă completă *</label>
                                    <textarea name="shipping_address" required>${orderData.shipping_address || ''}</textarea>
                                </div>
                            </div>
                            
                            <!-- Package Info -->
                            <div class="form-section">
                                <h4>Informații Colet</h4>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Greutate (kg) *</label>
                                        <input type="number" step="0.1" name="total_weight"
                                               value="${requirementsData.weight_info?.weight || ''}" required>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Colete</label>
                                        <input type="number" name="parcels_count" value="${orderData.parcels_count || 1}">
                                    </div>
                                    <div class="form-group">
                                        <label>Plicuri</label>
                                        <input type="number" name="envelopes_count" value="${orderData.envelopes_count || 1}" max="9">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Conținut colet</label>
                                    <input type="text" name="package_content" value="Diverse produse">
                                </div>
                            </div>
                            
                            <!-- Special Services -->
                            <div class="form-section">
                                <h4>Servicii Speciale</h4>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Ramburs cash</label>
                                        <input type="number" step="0.01" name="cash_repayment" value="0">
                                    </div>
                                    <div class="form-group">
                                        <label>Ramburs bancar</label>
                                        <input type="number" step="0.01" name="bank_repayment" value="0">
                                    </div>
                                </div>
                                <div class="form-checkboxes">
                                    <label><input type="checkbox" name="saturday_delivery" value="1"> Livrare sâmbătă</label>
                                    <label><input type="checkbox" name="morning_delivery" value="1"> Livrare dimineață</label>
                                    <label><input type="checkbox" name="open_package" value="1"> Colet deschis</label>
                                </div>
                                <div class="form-group">
                                    <label>Observații</label>
                                    <textarea name="observations">${orderData.observations || ''}</textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="modal-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeAWBModal()">
                                Anulează
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <span class="material-symbols-outlined">local_shipping</span>
                                Generează AWB
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modals
    closeAWBModal();
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    document.body.style.overflow = 'hidden';
    
    // Add form submit handler
    document.getElementById('awb-manual-form').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const manualData = {};
        
        for (let [key, value] of formData.entries()) {
            if (value !== '') {
                manualData[key] = value;
            }
        }
        
        // Close modal and generate AWB
        closeAWBModal();
        generateAWBDirect(orderId, manualData);
    });
}

/**
 * Close AWB modals
 */
function closeAWBModal() {
    const modals = [
        document.getElementById('awb-requirements-modal'),
        document.getElementById('awb-manual-modal')
    ];
    
    modals.forEach(modal => {
        if (modal) {
            modal.remove();
        }
    });
    
    document.body.style.overflow = '';
}

/**
 * Update order UI to show AWB generated
 */
function updateOrderAWBStatus(orderId, barcode) {
    // Find the order row or card
    const orderElements = document.querySelectorAll(`[data-order-id="${orderId}"]`);
    orderElements.forEach(element => {
        const awbButton = element.querySelector('.generate-awb-btn');
        if (awbButton) {
            // Replace button with AWB info
            awbButton.outerHTML = `
                <div class="awb-info">
                    <span class="awb-barcode">${barcode}</span>
                    <small>AWB generat</small>
                </div>
            `;
        }
        
        // Update any AWB column if present
        const awbCell = element.querySelector('.awb-column');
        if (awbCell) {
            awbCell.innerHTML = `
                <div class="awb-info">
                    <span class="awb-barcode">${barcode}</span>
                    <small>AWB generat</small>
                </div>
            `;
        }
    });
}

/**
 * Get CSRF token from meta tag or form
 */
function getCsrfToken() {
    // Try meta tag first
    const metaToken = document.querySelector('meta[name="csrf-token"]');
    if (metaToken) {
        return metaToken.getAttribute('content');
    }
    
    // Try hidden form input
    const formToken = document.querySelector('input[name="csrf_token"]');
    if (formToken) {
        return formToken.value;
    }
    
    // Try global variable
    if (typeof window.csrfToken !== 'undefined') {
        return window.csrfToken;
    }
    
    return null;
}

/**
 * Show notification
 */
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} notification`;
    notification.innerHTML = `
        <span class="material-symbols-outlined">
            ${type === 'success' ? 'check_circle' : type === 'error' ? 'error' : 'info'}
        </span>
        ${message}
    `;
    
    // Add to page
    let container = document.querySelector('.notifications-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'notifications-container';
        container.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            pointer-events: none;
        `;
        document.body.appendChild(container);
    }
    
    container.appendChild(notification);
    
    // Style notification
    notification.style.cssText = `
        margin-bottom: 10px;
        padding: 12px 16px;
        border-radius: 4px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        pointer-events: auto;
        cursor: pointer;
        animation: slideIn 0.3s ease-out;
        min-width: 300px;
        display: flex;
        align-items: center;
        gap: 12px;
    `;
    
    // Auto remove
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
    
    // Click to dismiss
    notification.addEventListener('click', () => {
        notification.remove();
    });
}

// CSS animation for notifications
if (!document.querySelector('#awb-notification-styles')) {
    const style = document.createElement('style');
    style.id = 'awb-notification-styles';
    style.textContent = `
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    `;
    document.head.appendChild(style);
}

// Export functions for global access
window.generateAWB = generateAWB;
window.closeAWBModal = closeAWBModal;
window.showAWBRequirementsModal = showAWBRequirementsModal;
window.showAWBManualInputModal = showAWBManualInputModal;
window.printAWB = printAWB;
