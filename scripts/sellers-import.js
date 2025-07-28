// File: scripts/sellers-import.js
// Import functionality for sellers Excel files

let selectedSellersFile = null;
let sellersImportInProgress = false;

/**
 * Initialize sellers import functionality
 */
function initializeSellersImport() {
    const fileInput = document.getElementById('sellersExcelFile');
    const uploadArea = document.getElementById('sellersUploadArea');
    
    if (!fileInput || !uploadArea) {
        console.warn('Sellers import elements not found');
        return;
    }
    
    // File input change handler
    fileInput.addEventListener('change', handleSellersFileSelect);
    
    // Drag and drop functionality
    uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (!uploadArea.contains(e.relatedTarget)) {
            uploadArea.classList.remove('dragover');
        }
    });
    
    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            handleSellersDroppedFile(files[0]);
        }
    });
    
    // Click to upload
    uploadArea.addEventListener('click', function(e) {
        if (e.target === uploadArea || e.target.closest('.upload-content')) {
            fileInput.click();
        }
    });
}

/**
 * Open sellers import modal
 */
function openImportSellersModal() {
    document.getElementById('importSellersModal').style.display = 'flex';
    // Reset modal state
    showSellersStep('step-upload');
    resetSellersImportForm();
}

/**
 * Close sellers import modal
 */
function closeImportSellersModal() {
    if (sellersImportInProgress) {
        if (!confirm('Importul este în curs. Sigur vrei să închizi?')) {
            return;
        }
    }
    
    document.getElementById('importSellersModal').style.display = 'none';
    resetSellersImportForm();
}

/**
 * Reset import form
 */
function resetSellersImportForm() {
    selectedSellersFile = null;
    sellersImportInProgress = false;
    
    // Hide file info
    hideSellersFileInfo();
    
    // Reset progress
    const progressFill = document.getElementById('sellersProgressFill');
    if (progressFill) progressFill.style.width = '0%';
    
    // Reset checkboxes
    document.getElementById('updateExistingSellers').checked = true;
    document.getElementById('skipDuplicates').checked = false;
    
    // Reset buttons
    const processBtn = document.getElementById('sellersProcessBtn');
    if (processBtn) {
        processBtn.disabled = true;
        processBtn.innerHTML = '<span class="material-symbols-outlined">play_arrow</span>Selectează un fișier';
    }
    
    // Reset file input
    const fileInput = document.getElementById('sellersExcelFile');
    if (fileInput) fileInput.value = '';
}

/**
 * Show specific step
 */
function showSellersStep(stepId) {
    const steps = ['step-upload', 'step-progress', 'step-results'];
    steps.forEach(step => {
        const element = document.getElementById(step);
        if (element) {
            element.style.display = step === stepId ? 'block' : 'none';
        }
    });
}

/**
 * Handle file selection from input
 */
function handleSellersFileSelect() {
    const fileInput = document.getElementById('sellersExcelFile');
    const file = fileInput.files[0];
    
    if (!file) {
        hideSellersFileInfo();
        return;
    }
    
    validateAndShowSellersFile(file);
}

/**
 * Handle dropped file
 */
function handleSellersDroppedFile(file) {
    const fileInput = document.getElementById('sellersExcelFile');
    
    // Create a new FileList-like object
    const dt = new DataTransfer();
    dt.items.add(file);
    fileInput.files = dt.files;
    
    validateAndShowSellersFile(file);
}

/**
 * Validate and display file information
 */
function validateAndShowSellersFile(file) {
    // Validate file type
    const fileExtension = file.name.toLowerCase().split('.').pop();
    if (!['xls', 'xlsx'].includes(fileExtension)) {
        showNotification('Vă rog să selectați un fișier Excel (.xls sau .xlsx)', 'error');
        return;
    }
    
    // Validate file size (max 10MB)
    const maxSize = 10 * 1024 * 1024; // 10MB
    if (file.size > maxSize) {
        showNotification('Fișierul este prea mare. Mărimea maximă permisă este 10MB.', 'error');
        return;
    }
    
    selectedSellersFile = file;
    showSellersFileInfo(file);
    
    // Enable the process button
    const processBtn = document.getElementById('sellersProcessBtn');
    if (processBtn) {
        processBtn.disabled = false;
        processBtn.innerHTML = '<span class="material-symbols-outlined">play_arrow</span>Procesează Fișierul';
    }
}

/**
 * Format file size for display
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

/**
 * Show notification message
 */
function showNotification(message, type = 'info') {
    // Try to use existing notification system first
    if (typeof window.showNotification === 'function') {
        window.showNotification(message, type);
        return;
    }
    
    // Fallback: create simple notification
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 24px;
        background: ${type === 'error' ? '#dc3545' : type === 'success' ? '#28a745' : '#007bff'};
        color: white;
        border-radius: 4px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        z-index: 9999;
        max-width: 400px;
        word-wrap: break-word;
    `;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.parentNode.removeChild(notification);
        }
    }, 5000);
}

/**
 * Show file information
 */
function showSellersFileInfo(file) {
    const fileInfo = document.getElementById('sellersFileInfo');
    const fileName = document.getElementById('sellersFileName');
    const fileSize = document.getElementById('sellersFileSize');
    
    if (fileName) fileName.textContent = file.name;
    if (fileSize) fileSize.textContent = formatFileSize(file.size);
    if (fileInfo) fileInfo.style.display = 'flex';
}

/**
 * Hide file information
 */
function hideSellersFileInfo() {
    const fileInfo = document.getElementById('sellersFileInfo');
    if (fileInfo) fileInfo.style.display = 'none';
    
    const processBtn = document.getElementById('sellersProcessBtn');
    if (processBtn) {
        processBtn.disabled = true;
        processBtn.innerHTML = '<span class="material-symbols-outlined">play_arrow</span>Selectează un fișier';
    }
    
    selectedSellersFile = null;
}

/**
 * Remove selected file
 */
function removeSellersFile() {
    const fileInput = document.getElementById('sellersExcelFile');
    if (fileInput) fileInput.value = '';
    
    hideSellersFileInfo();
}

/**
 * Start processing the Excel file
 */
async function startSellersProcessing() {
    if (!selectedSellersFile) {
        showNotification('Vă rog să selectați un fișier Excel.', 'error');
        return;
    }
    
    if (sellersImportInProgress) {
        showNotification('Un import este deja în curs. Vă rog să așteptați.', 'warning');
        return;
    }
    
    sellersImportInProgress = true;
    showSellersStep('step-progress');
    
    // Update progress
    updateSellersProgress(10, 'Se citește fișierul Excel...');
    
    try {
        // Read the Excel file
        const arrayBuffer = await selectedSellersFile.arrayBuffer();
        
        updateSellersProgress(30, 'Se procesează datele...');
        
        // Parse Excel data
        const workbook = XLSX.read(arrayBuffer, { type: 'array' });
        const worksheet = workbook.Sheets[workbook.SheetNames[0]];
        const rawData = XLSX.utils.sheet_to_json(worksheet, { header: 1 });
        
        updateSellersProgress(50, 'Se mapează coloanele...');
        
        // Process the data
        const processedData = processSellersData(rawData);
        
        updateSellersProgress(70, 'Se pregătește importul...');
        
        // Get import options
        const options = {
            updateExisting: document.getElementById('updateExistingSellers').checked,
            skipDuplicates: document.getElementById('skipDuplicates').checked
        };
        
        updateSellersProgress(80, 'Se importă furnizorii...');
        
        // Send data to server
        const response = await fetch('api/sellers_import.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'import_sellers',
                sellers: processedData,
                options: options
            })
        });
        
        updateSellersProgress(90, 'Se finalizează importul...');
        
        const result = await response.json();
        
        updateSellersProgress(100, 'Import finalizat!');
        
        // Show results
        setTimeout(() => {
            showSellersResults(result);
        }, 500);
        
    } catch (error) {
        console.error('Import error:', error);
        showNotification('Eroare la importul fișierului: ' + error.message, 'error');
        sellersImportInProgress = false;
        showSellersStep('step-upload');
    }
}

/**
 * Process Excel data into sellers format
 */
function processSellersData(rawData) {
    if (!rawData || rawData.length < 2) {
        throw new Error('Fișierul Excel este gol sau nu conține date valide.');
    }
    
    const headers = rawData[0];
    const dataRows = rawData.slice(1);
    
    // Map column headers to field names
    const columnMap = mapSellersColumns(headers);
    
    const processedSellers = [];
    
    for (let i = 0; i < dataRows.length; i++) {
        const row = dataRows[i];
        
        // Skip empty rows
        if (!row || row.every(cell => !cell || cell.toString().trim() === '')) {
            continue;
        }
        
        try {
            const sellerData = extractSellerData(row, columnMap);
            
            // Validate required fields
            if (!sellerData.supplier_name || sellerData.supplier_name.trim() === '') {
                console.warn(`Row ${i + 2}: Missing supplier name, skipping`);
                continue;
            }
            
            processedSellers.push(sellerData);
            
        } catch (error) {
            console.warn(`Row ${i + 2}: ${error.message}`);
        }
    }
    
    if (processedSellers.length === 0) {
        throw new Error('Nu s-au găsit furnizori valizi în fișier.');
    }
    
    return processedSellers;
}

/**
 * Map Excel columns to seller fields
 */
function mapSellersColumns(headers) {
    const mappings = {
        'supplier_name': ['denumire furnizor', 'nume furnizor', 'supplier_name', 'supplier', 'name'],
        'tax_id': ['cif', 'cui', 'tax_id', 'cod fiscal'],
        'reg_com': ['reg com', 'registrul comertului', 'reg_com', 'registration'],
        'supplier_code': ['cod furnizor', 'supplier_code', 'code', 'cod'],
        'address': ['adresa', 'address', 'strada'],
        'city': ['localitate', 'oras', 'city', 'orasul'],
        'county': ['judet', 'county', 'judetu'],
        'bank': ['banca', 'bank', 'banca partenera'],
        'iban': ['iban', 'cont bancar', 'account'],
        'country': ['tara', 'country', 'tara de origine'],
        'email': ['email', 'e-mail', 'adresa email'],
        'contact_person': ['pers contact', 'persoana contact', 'contact_person', 'contact'],
        'phone': ['telefon', 'phone', 'tel', 'numar telefon']
    };
    
    const map = {};
    
    for (let index = 0; index < headers.length; index++) {
        const header = headers[index];
        if (!header) continue;
        
        const normalizedHeader = header.toString().toLowerCase().trim();
        
        for (const [field, variants] of Object.entries(mappings)) {
            for (const variant of variants) {
                if (normalizedHeader === variant || normalizedHeader.includes(variant)) {
                    map[field] = index;
                    break;
                }
            }
        }
    }
    
    return map;
}

/**
 * Extract seller data from row
 */
function extractSellerData(row, columnMap) {
    const sellerData = {};
    
    for (const [field, columnIndex] of Object.entries(columnMap)) {
        if (columnIndex !== undefined && row[columnIndex] !== undefined) {
            let value = row[columnIndex];
            
            if (value !== null && value !== undefined) {
                value = value.toString().trim();
                
                // Skip empty values
                if (value === '' || value === '-') {
                    continue;
                }
                
                sellerData[field] = value;
            }
        }
    }
    
    // Set default values
    if (!sellerData.country) {
        sellerData.country = 'Romania';
    }
    
    if (!sellerData.status) {
        sellerData.status = 'active';
    }
    
    return sellerData;
}

/**
 * Update progress bar and text
 */
function updateSellersProgress(percentage, text) {
    const progressFill = document.getElementById('sellersProgressFill');
    const progressText = document.getElementById('sellersProgressText');
    
    if (progressFill) progressFill.style.width = percentage + '%';
    if (progressText) progressText.textContent = text;
}

/**
 * Show import results
 */
function showSellersResults(result) {
    sellersImportInProgress = false;
    showSellersStep('step-results');
    
    const statsContainer = document.getElementById('sellersResultsStats');
    const detailsContainer = document.getElementById('sellersResultsDetails');
    
    if (result.success) {
        // Show success stats
        const stats = `
            <div class="stat-item">
                <span class="stat-number">${result.imported || 0}</span>
                <span class="stat-label">Furnizori noi</span>
            </div>
            <div class="stat-item">
                <span class="stat-number">${result.updated || 0}</span>
                <span class="stat-label">Furnizori actualizați</span>
            </div>
            <div class="stat-item">
                <span class="stat-number">${result.skipped || 0}</span>
                <span class="stat-label">Omiși</span>
            </div>
            <div class="stat-item">
                <span class="stat-number">${result.processed || 0}</span>
                <span class="stat-label">Total procesați</span>
            </div>
        `;
        
        if (statsContainer) statsContainer.innerHTML = stats;
        
        // Show details if there are errors or warnings
        if (result.errors && result.errors.length > 0) {
            let details = '<div class="import-errors"><h5>Erori:</h5><ul>';
            result.errors.forEach(error => {
                details += `<li>${error}</li>`;
            });
            details += '</ul></div>';
            
            if (detailsContainer) detailsContainer.innerHTML = details;
        }
        
        showNotification('Import finalizat cu succes!', 'success');
        
    } else {
        // Show error
        if (statsContainer) {
            statsContainer.innerHTML = '<div class="error-message">Import eșuat</div>';
        }
        
        if (detailsContainer) {
            detailsContainer.innerHTML = `<div class="import-errors"><p>${result.message || 'Eroare necunoscută'}</p></div>`;
        }
        
        showNotification('Eroare la import: ' + (result.message || 'Eroare necunoscută'), 'error');
    }
    
    // Update button
    const processBtn = document.getElementById('sellersProcessBtn');
    if (processBtn) {
        processBtn.innerHTML = '<span class="material-symbols-outlined">refresh</span>Import nou';
        processBtn.disabled = false;
        processBtn.onclick = function() {
            showSellersStep('step-upload');
            resetSellersImportForm();
        };
    }
    
    // Update cancel button
    const cancelBtn = document.getElementById('sellersCancelBtn');
    if (cancelBtn) {
        cancelBtn.textContent = 'Închide';
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initializeSellersImport();
});