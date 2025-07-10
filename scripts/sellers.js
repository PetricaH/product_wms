// File: scripts/sellers.js
// JavaScript functionality for the sellers management page

document.addEventListener('DOMContentLoaded', function() {
    console.log('Sellers page loaded');
});

// Global variable to store current seller data
let currentSellerData = null;

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
    if (modalId === 'editSellerModal') {
        document.getElementById('editSellerForm').innerHTML = `
            <div class="loading-message">
                <span class="material-symbols-outlined">hourglass_empty</span>
                Se încarcă informațiile furnizorului...
            </div>
        `;
    }
    
    if (modalId === 'sellerDetailsModal') {
        document.getElementById('sellerDetailsContent').innerHTML = `
            <div class="loading-message">
                <span class="material-symbols-outlined">hourglass_empty</span>
                Se încarcă detaliile furnizorului...
            </div>
        `;
    }
    
    // Clear current seller data
    currentSellerData = null;
}

// Create seller modal
function openCreateModal() {
    openModal('createSellerModal');
    
    // Focus on the first input
    setTimeout(() => {
        const firstInput = document.getElementById('supplier_name');
        if (firstInput) {
            firstInput.focus();
        }
    }, 100);
}

// Edit seller modal
function openEditModal(sellerId) {
    document.getElementById('editSellerId').value = sellerId;
    loadSellerData(sellerId, 'edit');
    openModal('editSellerModal');
}

// Delete seller modal
function openDeleteModal(sellerId, sellerName) {
    document.getElementById('deleteSellerId').value = sellerId;
    document.getElementById('deleteSellerName').textContent = sellerName;
    openModal('deleteSellerModal');
}

// View seller details modal
function viewSellerDetails(sellerId) {
    loadSellerData(sellerId, 'view');
    openModal('sellerDetailsModal');
}

// Load seller data via AJAX
function loadSellerData(sellerId, mode) {
    const endpoint = `api/seller_details.php?id=${sellerId}`;
    
    fetch(endpoint)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error('Error loading seller data:', data.error);
                alert('Eroare la încărcarea datelor furnizorului: ' + data.error);
                return;
            }
            
            currentSellerData = data;
            
            if (mode === 'edit') {
                populateEditForm(data);
            } else if (mode === 'view') {
                populateDetailsView(data);
            }
        })
        .catch(error => {
            console.error('Error loading seller data:', error);
            alert('Eroare la încărcarea datelor furnizorului.');
        });
}

// Populate edit form with seller data
function populateEditForm(seller) {
    const formHtml = `
        <!-- Basic Information -->
        <div class="form-section">
            <h4>Informații de Bază</h4>
            <div class="row">
                <div class="form-group">
                    <label for="edit_supplier_name" class="form-label">Nume Furnizor *</label>
                    <input type="text" name="supplier_name" id="edit_supplier_name" class="form-control" 
                           value="${escapeHtml(seller.supplier_name || '')}" required>
                </div>
                <div class="form-group">
                    <label for="edit_supplier_code" class="form-label">Cod Furnizor</label>
                    <input type="text" name="supplier_code" id="edit_supplier_code" class="form-control" 
                           value="${escapeHtml(seller.supplier_code || '')}">
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label for="edit_status" class="form-label">Status</label>
                    <select name="status" id="edit_status" class="form-control">
                        <option value="active" ${seller.status === 'active' ? 'selected' : ''}>Activ</option>
                        <option value="inactive" ${seller.status === 'inactive' ? 'selected' : ''}>Inactiv</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Fiscal Information -->
        <div class="form-section">
            <h4>Informații Fiscale</h4>
            <div class="row">
                <div class="form-group">
                    <label for="edit_cif" class="form-label">CIF</label>
                    <input type="text" name="cif" id="edit_cif" class="form-control" 
                           value="${escapeHtml(seller.cif || '')}">
                </div>
                <div class="form-group">
                    <label for="edit_registration_number" class="form-label">Număr Înregistrare</label>
                    <input type="text" name="registration_number" id="edit_registration_number" class="form-control" 
                           value="${escapeHtml(seller.registration_number || '')}">
                </div>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="form-section">
            <h4>Informații de Contact</h4>
            <div class="row">
                <div class="form-group">
                    <label for="edit_contact_person" class="form-label">Persoană de Contact</label>
                    <input type="text" name="contact_person" id="edit_contact_person" class="form-control" 
                           value="${escapeHtml(seller.contact_person || '')}">
                </div>
                <div class="form-group">
                    <label for="edit_email" class="form-label">Email</label>
                    <input type="email" name="email" id="edit_email" class="form-control" 
                           value="${escapeHtml(seller.email || '')}">
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label for="edit_phone" class="form-label">Telefon</label>
                    <input type="tel" name="phone" id="edit_phone" class="form-control" 
                           value="${escapeHtml(seller.phone || '')}">
                </div>
            </div>
        </div>

        <!-- Address Information -->
        <div class="form-section">
            <h4>Adresă</h4>
            <div class="form-group">
                <label for="edit_address" class="form-label">Adresă</label>
                <textarea name="address" id="edit_address" class="form-control" rows="2">${escapeHtml(seller.address || '')}</textarea>
            </div>
            <div class="row">
                <div class="form-group">
                    <label for="edit_city" class="form-label">Localitate</label>
                    <input type="text" name="city" id="edit_city" class="form-control" 
                           value="${escapeHtml(seller.city || '')}">
                </div>
                <div class="form-group">
                    <label for="edit_county" class="form-label">Județ</label>
                    <input type="text" name="county" id="edit_county" class="form-control" 
                           value="${escapeHtml(seller.county || '')}">
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label for="edit_country" class="form-label">Țară</label>
                    <input type="text" name="country" id="edit_country" class="form-control" 
                           value="${escapeHtml(seller.country || 'Romania')}">
                </div>
            </div>
        </div>

        <!-- Banking Information -->
        <div class="form-section">
            <h4>Informații Bancare</h4>
            <div class="row">
                <div class="form-group">
                    <label for="edit_bank_name" class="form-label">Banca</label>
                    <input type="text" name="bank_name" id="edit_bank_name" class="form-control" 
                           value="${escapeHtml(seller.bank_name || '')}">
                </div>
                <div class="form-group">
                    <label for="edit_iban" class="form-label">IBAN</label>
                    <input type="text" name="iban" id="edit_iban" class="form-control" 
                           value="${escapeHtml(seller.iban || '')}">
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="form-section">
            <h4>Observații</h4>
            <div class="form-group">
                <label for="edit_notes" class="form-label">Observații</label>
                <textarea name="notes" id="edit_notes" class="form-control" rows="3">${escapeHtml(seller.notes || '')}</textarea>
            </div>
        </div>
    `;
    
    document.getElementById('editSellerForm').innerHTML = formHtml;
    
    // Focus on the first input
    setTimeout(() => {
        const firstInput = document.getElementById('edit_supplier_name');
        if (firstInput) {
            firstInput.focus();
        }
    }, 100);
}

// Populate details view with seller data
function populateDetailsView(seller) {
    const detailsHtml = `
        <div class="seller-details">
            <div class="details-grid">
                <!-- Basic Information -->
                <div class="details-section">
                    <h4>Informații de Bază</h4>
                    <div class="details-item">
                        <strong>Nume:</strong> ${escapeHtml(seller.supplier_name || 'N/A')}
                    </div>
                    ${seller.supplier_code ? `
                    <div class="details-item">
                        <strong>Cod Furnizor:</strong> ${escapeHtml(seller.supplier_code)}
                    </div>
                    ` : ''}
                    <div class="details-item">
                        <strong>Status:</strong> 
                        <span class="status-badge status-${seller.status}">${seller.status === 'active' ? 'Activ' : 'Inactiv'}</span>
                    </div>
                </div>

                <!-- Fiscal Information -->
                ${seller.cif || seller.registration_number ? `
                <div class="details-section">
                    <h4>Informații Fiscale</h4>
                    ${seller.cif ? `
                    <div class="details-item">
                        <strong>CIF:</strong> ${escapeHtml(seller.cif)}
                    </div>
                    ` : ''}
                    ${seller.registration_number ? `
                    <div class="details-item">
                        <strong>Număr Înregistrare:</strong> ${escapeHtml(seller.registration_number)}
                    </div>
                    ` : ''}
                </div>
                ` : ''}

                <!-- Contact Information -->
                ${seller.contact_person || seller.email || seller.phone ? `
                <div class="details-section">
                    <h4>Informații de Contact</h4>
                    ${seller.contact_person ? `
                    <div class="details-item">
                        <strong>Persoană de Contact:</strong> ${escapeHtml(seller.contact_person)}
                    </div>
                    ` : ''}
                    ${seller.email ? `
                    <div class="details-item">
                        <strong>Email:</strong> <a href="mailto:${escapeHtml(seller.email)}">${escapeHtml(seller.email)}</a>
                    </div>
                    ` : ''}
                    ${seller.phone ? `
                    <div class="details-item">
                        <strong>Telefon:</strong> <a href="tel:${escapeHtml(seller.phone)}">${escapeHtml(seller.phone)}</a>
                    </div>
                    ` : ''}
                </div>
                ` : ''}

                <!-- Address Information -->
                ${seller.address || seller.city || seller.county || seller.country ? `
                <div class="details-section">
                    <h4>Adresă</h4>
                    ${seller.address ? `
                    <div class="details-item">
                        <strong>Adresă:</strong> ${escapeHtml(seller.address)}
                    </div>
                    ` : ''}
                    ${seller.city || seller.county ? `
                    <div class="details-item">
                        <strong>Localitate:</strong> 
                        ${escapeHtml(seller.city || '')}${seller.city && seller.county ? ', ' : ''}${escapeHtml(seller.county || '')}
                    </div>
                    ` : ''}
                    ${seller.country && seller.country !== 'Romania' ? `
                    <div class="details-item">
                        <strong>Țară:</strong> ${escapeHtml(seller.country)}
                    </div>
                    ` : ''}
                </div>
                ` : ''}

                <!-- Banking Information -->
                ${seller.bank_name || seller.iban ? `
                <div class="details-section">
                    <h4>Informații Bancare</h4>
                    ${seller.bank_name ? `
                    <div class="details-item">
                        <strong>Banca:</strong> ${escapeHtml(seller.bank_name)}
                    </div>
                    ` : ''}
                    ${seller.iban ? `
                    <div class="details-item">
                        <strong>IBAN:</strong> ${escapeHtml(seller.iban)}
                    </div>
                    ` : ''}
                </div>
                ` : ''}

                <!-- Notes -->
                ${seller.notes ? `
                <div class="details-section full-width">
                    <h4>Observații</h4>
                    <div class="details-item">
                        ${escapeHtml(seller.notes).replace(/\n/g, '<br>')}
                    </div>
                </div>
                ` : ''}

                <!-- System Information -->
                <div class="details-section">
                    <h4>Informații Sistem</h4>
                    <div class="details-item">
                        <strong>Data Creării:</strong> ${formatDate(seller.created_at)}
                    </div>
                    ${seller.updated_at && seller.updated_at !== seller.created_at ? `
                    <div class="details-item">
                        <strong>Ultima Actualizare:</strong> ${formatDate(seller.updated_at)}
                    </div>
                    ` : ''}
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('sellerDetailsContent').innerHTML = detailsHtml;
}

// Edit current seller from details view
function editCurrentSeller() {
    if (currentSellerData) {
        closeModal('sellerDetailsModal');
        openEditModal(currentSellerData.id);
    }
}

// Utility function to escape HTML
function escapeHtml(text) {
    if (text === null || text === undefined) {
        return '';
    }
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Utility function to format date
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    
    try {
        const date = new Date(dateString);
        return date.toLocaleString('ro-RO', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch (error) {
        return dateString;
    }
}

// Form validation
function validateSellerForm(form) {
    const supplierName = form.querySelector('[name="supplier_name"]').value.trim();
    const email = form.querySelector('[name="email"]').value.trim();
    
    if (!supplierName) {
        alert('Numele furnizorului este obligatoriu.');
        return false;
    }
    
    if (email && !isValidEmail(email)) {
        alert('Adresa de email nu este validă.');
        return false;
    }
    
    return true;
}

// Email validation
function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

// Add form validation on submit
document.addEventListener('DOMContentLoaded', function() {
    // Create form validation
    const createForm = document.querySelector('#createSellerModal form');
    if (createForm) {
        createForm.addEventListener('submit', function(e) {
            if (!validateSellerForm(this)) {
                e.preventDefault();
            }
        });
    }
    
    // Edit form validation (will be attached when form is created)
    document.addEventListener('submit', function(e) {
        if (e.target.closest('#editSellerModal')) {
            if (!validateSellerForm(e.target)) {
                e.preventDefault();
            }
        }
    });
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

// Auto-format IBAN input
document.addEventListener('input', function(e) {
    if (e.target.name === 'iban') {
        // Remove spaces and convert to uppercase
        let value = e.target.value.replace(/\s/g, '').toUpperCase();
        
        // Add spaces every 4 characters
        value = value.replace(/(.{4})/g, '$1 ').trim();
        
        e.target.value = value;
    }
});

// Auto-format phone input
document.addEventListener('input', function(e) {
    if (e.target.name === 'phone') {
        // Remove all non-digit characters except + and spaces
        let value = e.target.value.replace(/[^\d\+\s\-\(\)]/g, '');
        e.target.value = value;
    }
});