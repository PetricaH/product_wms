// File: scripts/orders_awb.js
// AWB generation functionality for orders page and picking interface

document.addEventListener('DOMContentLoaded', function() {
    initializeAWBGeneration();
    initializeAWBStatus();
    loadAvailablePrinters();
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

let availablePrinters = [];

async function loadAvailablePrinters() {
    try {
        const response = await fetch('api/printer_management.php?path=printers', {
            credentials: 'same-origin'
        });
        if (response.ok) {
            const data = await response.json();
            availablePrinters = data.filter(p => 
                p.is_active && 
                (p.printer_type === 'awb' || p.printer_type === 'label' || p.printer_type === 'universal')
            );
        }
    } catch (error) {
        console.error('Failed to load printers:', error);
        availablePrinters = [];
    }
}

function initializeAWBStatus() {
    // Don't automatically fetch status for existing AWBs - set them to unknown initially
    document.querySelectorAll('.awb-status').forEach(el => {
        const awb = el.getAttribute('data-awb');
        if (awb && el.textContent === 'Se verifică...') {
            el.textContent = 'Status necunoscut';
            el.className = 'awb-status awb-status-unknown';
        }
    });

    // Set up click handlers for refresh buttons  
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.refresh-status-btn');
        if (btn) {
            const awb = btn.getAttribute('data-awb');
            const el = document.querySelector(`.awb-status[data-awb="${awb}"]`);
            if (el && awb) {
                fetchAwbStatus(awb, el, true); // Always refresh when button is clicked
            }
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

            if (window.IS_PICKING_INTERFACE) {
                document.dispatchEvent(new CustomEvent('awbGenerated', { detail: { orderId, awbCode } }));
            } else {
                updateOrderAWBStatus(orderId, awbCode, button);
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            }

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

/**
 * Download AWB document directly (secure POST method)
 */
async function downloadAWB(orderId, awbCode, format = 'label') {
    if (!orderId || !awbCode) {
        showNotification('Date lipsă pentru descărcarea AWB', 'error');
        return;
    }

    // Validate AWB number format
    if (!/^\d+$/.test(awbCode)) {
        showNotification('Format AWB invalid', 'error');
        return;
    }

    try {
        showNotification('Se descarcă documentul AWB...', 'info');

        // Create form data
        const formData = new FormData();
        formData.append('order_id', orderId);
        formData.append('awb', awbCode);
        formData.append('format', format);
        
        const csrf = getCsrfToken();
        if (csrf) {
            formData.append('csrf_token', csrf);
        }

        // Make the download request
        const response = await fetch('/api/awb/download_awb.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        if (!response.ok) {
            // Try to get error message from JSON response
            let errorMessage;
            try {
                const errorData = await response.json();
                errorMessage = errorData.error || `Server error: HTTP ${response.status}`;
            } catch (jsonError) {
                errorMessage = `Server error: HTTP ${response.status}`;
            }
            throw new Error(errorMessage);
        }

        // Check content type
        const contentType = response.headers.get('content-type');
        
        if (contentType && contentType.includes('application/pdf')) {
            // Success - handle PDF download
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            
            // Create download link
            const a = document.createElement('a');
            a.href = url;
            a.download = `AWB_${awbCode}.pdf`;
            document.body.appendChild(a);
            a.click();
            
            // Cleanup
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
            
            showNotification('AWB descărcat cu succes', 'success');
            
        } else {
            // Response is likely JSON error
            const errorData = await response.json();
            throw new Error(errorData.error || 'Format răspuns invalid');
        }

    } catch (error) {
        console.error('AWB download error:', error);
        
        let errorMessage = 'Eroare la descărcarea AWB';
        
        if (error.message.includes('Authentication') || error.message.includes('Access denied')) {
            errorMessage = 'Sesiune expirată. Autentifică-te din nou.';
        } else if (error.message.includes('CSRF')) {
            errorMessage = 'Token securitate invalid. Reîncarcă pagina.';
        } else if (error.message.includes('Invalid AWB')) {
            errorMessage = 'Format AWB invalid.';
        } else if (error.message) {
            errorMessage = error.message;
        }
        
        showNotification(errorMessage, 'error');
    }
}


async function fetchAwbStatus(awb, el, refresh = false) {
    if (!el) return;
    
    const originalText = el.textContent;
    const refreshBtn = document.querySelector(`.refresh-status-btn[data-awb="${awb}"]`);
    const originalBtnHtml = refreshBtn ? refreshBtn.innerHTML : '';
    
    try {
        // Update UI to show tracking in progress
        el.textContent = 'Se verifică...';
        el.className = 'awb-status awb-status-checking';
        
        if (refreshBtn) {
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = `
                <span class="material-symbols-outlined spinning">refresh</span>
                Se verifică...
            `;
        }
        
        // Build URL with proper error handling for server issues
        const url = `api/awb/track_awb.php?awb=${encodeURIComponent(awb)}${refresh ? '&refresh=1' : ''}`;
        
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 second timeout
        
        const res = await fetch(url, {
            signal: controller.signal,
            headers: {
                'Cache-Control': 'no-cache'
            }
        });
        
        clearTimeout(timeoutId);
        
        if (!res.ok) {
            if (res.status === 502) {
                throw new Error('Server temporar indisponibil (502). Încearcă din nou.');
            } else if (res.status === 504) {
                throw new Error('Timeout server (504). Încearcă din nou.');
            } else {
                throw new Error(`Server error: HTTP ${res.status}`);
            }
        }
        
        const data = await res.json();
        
        if (data.success && data.data) {
            const status = data.data.status || 'Status necunoscut';
            el.textContent = status;
            el.className = 'awb-status ' + getAwbStatusClass(status);
            showNotification(`Status AWB actualizat: ${status}`, 'success');
        } else {
            el.textContent = 'Status indisponibil';
            el.className = 'awb-status awb-status-error';
            showNotification('Nu s-a putut actualiza statusul AWB', 'warning');
        }
        
    } catch (err) {
        console.error('Status fetch failed', err);
        
        if (err.name === 'AbortError') {
            el.textContent = 'Timeout - încearcă din nou';
            showNotification('Timeout la verificarea statusului AWB', 'warning');
        } else {
            el.textContent = 'Eroare la verificare';
            showNotification(`Eroare la verificarea statusului: ${err.message}`, 'error');
        }
        
        el.className = 'awb-status awb-status-error';
        
    } finally {
        // Restore button state
        if (refreshBtn) {
            refreshBtn.disabled = false;
            refreshBtn.innerHTML = originalBtnHtml;
        }
    }
}

function getAwbStatusClass(status) {
    const statusLower = status.toLowerCase();
    
    if (statusLower.includes('livrat') || statusLower.includes('delivered')) {
        return 'awb-status-delivered';
    } else if (statusLower.includes('în curs') || statusLower.includes('transit')) {
        return 'awb-status-in-transit';
    } else if (statusLower.includes('preluat') || statusLower.includes('picked')) {
        return 'awb-status-picked-up';
    } else if (statusLower.includes('returnat') || statusLower.includes('return')) {
        return 'awb-status-returned';
    } else if (statusLower.includes('anulat') || statusLower.includes('cancelled')) {
        return 'awb-status-cancelled';
    } else {
        return 'awb-status-unknown';
    }
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]));
}

async function printAWB(orderId, awbCode, orderNumber) {
    try {
        // Log the print attempt first
        const logForm = new FormData();
        logForm.append('order_id', orderId);
        logForm.append('awb', awbCode);
        const csrf = getCsrfToken();
        if (csrf) logForm.append('csrf_token', csrf);
        
        fetch('api/awb/log_print.php', {method: 'POST', body: logForm}).catch(() => {});
        
        // Show printer selection if multiple printers available
        if (availablePrinters.length > 1) {
            showAWBPrinterSelectionModal(orderId, awbCode, orderNumber);
        } else if (availablePrinters.length === 1) {
            // Use the only available printer
            await performAwbPrint(orderId, awbCode, orderNumber, availablePrinters[0].id);
        } else {
            // No printers available - generate PDF for download
            await performAwbPrint(orderId, awbCode, orderNumber, null);
        }
        
    } catch (error) {
        console.error('Print AWB error:', error);
        showNotification(`Eroare la printarea AWB: ${error.message}`, 'error');
    }
}

function showAWBPrinterSelectionModal(orderId, awbCode, orderNumber) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.style.display = 'flex';
    
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3>Selectează imprimanta pentru AWB ${escapeHtml(awbCode)}</h3>
                <button type="button" class="modal-close" onclick="this.closest('.modal').remove()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="printer-selection">
                    <h4>Imprimante disponibile:</h4>
                    ${availablePrinters.map(printer => `
                        <button type="button" class="printer-option-btn" onclick="selectPrinter(${orderId}, '${awbCode}', '${escapeHtml(orderNumber)}', ${printer.id})">
                            <span class="material-symbols-outlined">print</span>
                            <div>
                                <strong>${escapeHtml(printer.name)}</strong><br>
                                <small>${escapeHtml(printer.printer_type)} - ${printer.is_default ? 'Default' : 'Available'}</small>
                            </div>
                        </button>
                    `).join('')}
                    
                    <div class="format-selection">
                        <h4>Format de printare:</h4>
                        <div class="btn-group">
                            <button type="button" class="btn btn-outline-secondary format-btn active" data-format="label">
                                <span class="material-symbols-outlined">label</span>
                                Etichetă (10x14)
                            </button>
                            <button type="button" class="btn btn-outline-secondary format-btn" data-format="a4">
                                <span class="material-symbols-outlined">description</span>
                                A4
                            </button>
                        </div>
                    </div>
                    
                    <button type="button" class="btn btn-outline-info manual-print-btn" onclick="downloadAWB(${orderId}, '${awbCode}', getSelectedFormat()); this.closest('.modal').remove();">                        <span class="material-symbols-outlined">download</span>
                        Descarcă PDF (printare manuală)
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Setup format selection
    modal.querySelectorAll('.format-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            modal.querySelectorAll('.format-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
    });
    
    // Close modal on background click
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.remove();
        }
    });
}


function selectPrinter(orderId, awbCode, orderNumber, printerId) {
    const modal = document.querySelector('.modal');
    const format = modal.querySelector('.format-btn.active')?.dataset.format || 'label';
    modal.remove();
    performAwbPrint(orderId, awbCode, orderNumber, printerId, format);
}

function getSelectedFormat() {
    const activeBtn = document.querySelector('.modal .format-btn.active');
    return activeBtn ? activeBtn.dataset.format : 'label';
}


async function performAwbPrint(orderId, awbCode, orderNumber, printerId = null, format = 'label') {
    try {
        showNotification('Se generează AWB PDF din Cargus API...', 'info');
        
        const formData = new FormData();
        formData.append('order_id', orderId);
        formData.append('awb', awbCode);
        if (printerId) formData.append('printer_id', printerId);
        formData.append('format', format);
        
        const csrf = getCsrfToken();
        if (csrf) formData.append('csrf_token', csrf);
        
        const response = await fetch('api/awb/print_awb.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            throw new Error(`Server error: HTTP ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success) {
            if (result.printer) {
                showNotification(`AWB trimis la imprimanta ${result.printer}`, 'success');
            } else if (result.pdf_url) {
                showNotification('AWB PDF generat cu succes', 'success');
                
                // Create download link
                const link = document.createElement('a');
                link.href = result.pdf_url;
                link.download = `AWB_${awbCode}.pdf`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            } else {
                showNotification('AWB procesat cu succes', 'success');
            }
        } else {
            throw new Error(result.error || 'Unknown error occurred');
        }
        
    } catch (error) {
        console.error('AWB print error:', error);
        showNotification(`Eroare la printarea AWB: ${error.message}`, 'error');
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
function updateOrderAWBStatus(orderId, barcode, generateBtn) {
    const row = generateBtn.closest('tr');
    const orderNumberEl = row ? row.querySelector('.order-number') : null;
    const orderNumber = orderNumberEl ? orderNumberEl.textContent.trim() : '';
    const escapedOrderNumber = orderNumber.replace(/'/g, "\\'");

    const awbCell = generateBtn.closest('.awb-column');
    if (awbCell) {
        awbCell.innerHTML = `
            <div class="awb-info">
                <span class="awb-barcode">${barcode}</span>
                <div class="awb-status awb-status-unknown" data-awb="${barcode}">Status necunoscut</div>
                <button type="button" class="btn btn-sm btn-outline-secondary refresh-status-btn" data-awb="${barcode}">
                    <span class="material-symbols-outlined">refresh</span> Track AWB
                </button>
                <button type="button" class="btn btn-sm btn-outline-success print-awb-btn" onclick="printAWB(${orderId}, '${barcode}', '${escapedOrderNumber}')">
                    <span class="material-symbols-outlined">print</span> Printează AWB
                </button>
            </div>
        `;
        
        // Keep your existing logic to automatically fetch status
        const statusEl = awbCell.querySelector('.awb-status');
        fetchAwbStatus(barcode, statusEl);
    }
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
    
    // Try window.WMS_CONFIG (set by warehouse_header.php)
    if (window.WMS_CONFIG && window.WMS_CONFIG.csrfToken) {
        return window.WMS_CONFIG.csrfToken;
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
    
    console.warn('CSRF token not found. AWB generation may fail.');
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
window.performAwbPrint = performAwbPrint;
