/**
 * Enhanced Locations JavaScript - Clean Consolidated Version
 * File: scripts/locations.js
 * Auto-populates zone from location_code and enhanced warehouse visualization
 * Uses ONLY the enhanced subdivision system with per-level checkboxes
 */

// =================== GLOBAL VARIABLES ===================
let qr = null;
let currentLevels = 3; // Properly initialized with default
let levelSettingsEnabled = true; // Force enable enhanced system

// =================== INITIALIZATION ===================
document.addEventListener('DOMContentLoaded', function() {
    console.log('Enhanced Locations page loaded');
    
    // Initialize warehouse visualization if data exists
    if (typeof window.warehouseData !== 'undefined') {
        window.warehouseViz = new EnhancedWarehouseVisualization();
    }

    // Initialize core functionality
    initializeQRCode();
    setupEventListeners();
    
    // ONLY initialize the enhanced subdivision system
    initializeEnhancedLevelSettings();
});

/**
 * Initialize enhanced level settings functionality ONLY
 */
function initializeEnhancedLevelSettings() {
    console.log('Initializing enhanced level settings...');
    
    // Enable enhanced system
    levelSettingsEnabled = true;
    
    // Add level settings tab to modal
    addLevelSettingsTab();
    
    // Initialize level configuration
    const levelsInput = document.getElementById('levels');
    if (levelsInput) {
        levelsInput.addEventListener('change', updateLevelSettings);
    }
    
    // Initialize dimension distribution
    const dimensionInputs = ['height_mm', 'max_weight_kg', 'capacity'];
    dimensionInputs.forEach(inputId => {
        const input = document.getElementById(inputId);
        if (input) {
            input.addEventListener('change', () => distributeDimensions(inputId));
        }
    });
}

/**
 * Add level settings tab to the existing modal
 */
function addLevelSettingsTab() {
    const modalBody = document.querySelector('#locationModal .modal-body');
    if (!modalBody) return;
    
    // Check if tabs already exist
    let tabsContainer = modalBody.querySelector('.location-tabs');
    
    if (!tabsContainer) {
        // Create tabs container
        tabsContainer = document.createElement('div');
        tabsContainer.className = 'location-tabs';
        tabsContainer.innerHTML = `
            <button type="button" class="tab-button active" onclick="switchLocationTab('basic')">
                <span class="material-symbols-outlined">info</span>
                Informații de Bază
            </button>
            <button type="button" class="tab-button" onclick="switchLocationTab('dimensions')">
                <span class="material-symbols-outlined">straighten</span>
                Dimensiuni
            </button>
            <button type="button" class="tab-button" onclick="switchLocationTab('levels')">
                <span class="material-symbols-outlined">layers</span>
                Configurare Niveluri
            </button>
        `;
        
        // Insert tabs at the beginning of modal body
        modalBody.insertBefore(tabsContainer, modalBody.firstChild);
    }
    
    // Wrap existing form content in basic tab
    const existingContent = modalBody.querySelector('form').innerHTML;
    modalBody.querySelector('form').innerHTML = `
        <!-- Basic Information Tab -->
        <div id="basic-tab" class="tab-content active">
            ${existingContent}
        </div>
        
        <!-- Dimensions Tab -->
        <div id="dimensions-tab" class="tab-content">
            <div class="form-grid">
                <div class="form-group">
                    <label for="length_mm" class="form-label">
                        <span class="material-symbols-outlined">straighten</span>
                        Lungime (mm)
                    </label>
                    <input type="number" id="length_mm" name="length_mm" class="form-control" 
                           value="1000" min="100" max="10000">
                    <small class="form-help">Lungimea totală a raftului</small>
                </div>
                
                <div class="form-group">
                    <label for="depth_mm" class="form-label">
                        <span class="material-symbols-outlined">width</span>
                        Adâncime (mm)
                    </label>
                    <input type="number" id="depth_mm" name="depth_mm" class="form-control" 
                           value="400" min="100" max="2000">
                    <small class="form-help">Adâncimea raftului</small>
                </div>
                
                <div class="form-group">
                    <label for="height_mm" class="form-label">
                        <span class="material-symbols-outlined">height</span>
                        Înălțime Totală (mm)
                    </label>
                    <input type="number" id="height_mm" name="height_mm" class="form-control" 
                           value="900" min="200" max="5000" onchange="distributeLevelHeights()">
                    <small class="form-help">Înălțimea totală (distribuită pe niveluri)</small>
                </div>
                
                <div class="form-group">
                    <label for="max_weight_kg" class="form-label">
                        <span class="material-symbols-outlined">scale</span>
                        Greutate Maximă (kg)
                    </label>
                    <input type="number" id="max_weight_kg" name="max_weight_kg" class="form-control" 
                           value="150" min="10" max="2000" step="0.1" onchange="distributeWeightCapacity()">
                    <small class="form-help">Greutatea maximă suportată (distribuită pe niveluri)</small>
                </div>
            </div>
        </div>
        
        <!-- Level Settings Tab -->
        <div id="levels-tab" class="tab-content">
            <div class="form-check" style="margin-bottom: 1.5rem;">
                <input type="checkbox" id="enable_global_auto_repartition" name="enable_global_auto_repartition">
                <label for="enable_global_auto_repartition" class="form-label">
                    Activează repartizarea automată pentru toate nivelurile
                </label>
            </div>
            
            <div id="level-settings-container">
                <!-- Level settings will be generated dynamically -->
            </div>
        </div>
    `;
}

// =================== MODAL FUNCTIONS ===================

/**
 * Open create modal with proper initialization
 */
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Adaugă Locație';
    document.getElementById('formAction').value = 'create';
    document.getElementById('locationId').value = '';
    document.getElementById('submitBtn').textContent = 'Salvează';
    
    // Clear form and reset zone input styling
    document.getElementById('locationForm').reset();
    const zoneInput = document.getElementById('zone');
    if (zoneInput) {
        zoneInput.style.backgroundColor = '';
    }
    
    // Clear any existing auto-fill messages
    const existingMessage = document.querySelector('.zone-autofill-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    // Set default levels
    const levelsField = document.getElementById('levels');
    if (levelsField) levelsField.value = '3';

    // CRITICAL: Initialize currentLevels BEFORE calling functions that use it
    currentLevels = 3;

    // Update QR code
    setTimeout(() => {
        updateLocationQr();
    }, 100);
    
    // Initialize level settings for enhanced system
    if (levelSettingsEnabled) {
        generateLevelSettings();
        distributeLevelHeights();
        distributeWeightCapacity();
        distributeItemCapacity();
        
        // Switch to basic tab
        switchLocationTab('basic');
    }
    
    // Show modal
    document.getElementById('locationModal').classList.add('show');
    
    // Focus on location code input
    setTimeout(() => {
        const locationCodeInput = document.getElementById('location_code');
        if (locationCodeInput) {
            locationCodeInput.focus();
        }
    }, 100);
}

/**
 * Open edit modal with proper initialization
 */
function openEditModal(location) {
    document.getElementById('modalTitle').textContent = 'Editează Locație';
    document.getElementById('formAction').value = 'update';
    document.getElementById('locationId').value = location.id;
    document.getElementById('submitBtn').textContent = 'Actualizează';
    
    // CRITICAL: Initialize currentLevels FIRST, before any other function calls
    currentLevels = parseInt(location.levels) || 3;
    
    // Populate form with null checks
    const locationCodeInput = document.getElementById('location_code');
    if (locationCodeInput) locationCodeInput.value = location.location_code || '';
    
    const zoneInput = document.getElementById('zone');
    if (zoneInput) zoneInput.value = location.zone || '';
    
    const typeInput = document.getElementById('type');
    if (typeInput) typeInput.value = location.type || 'Shelf';
    
    const capacityInput = document.getElementById('capacity');
    if (capacityInput) capacityInput.value = location.capacity || '';
    
    const levelsFieldEdit = document.getElementById('levels');
    if (levelsFieldEdit) {
        levelsFieldEdit.value = location.levels || 3;
    }
    
    // Populate dimensions if available
    const lengthInput = document.getElementById('length_mm');
    if (lengthInput) lengthInput.value = location.length_mm || 1000;
    
    const depthInput = document.getElementById('depth_mm');
    if (depthInput) depthInput.value = location.depth_mm || 400;
    
    const heightInput = document.getElementById('height_mm');
    if (heightInput) heightInput.value = location.height_mm || 900;
    
    const weightInput = document.getElementById('max_weight_kg');
    if (weightInput) weightInput.value = location.max_weight_kg || 150;
    
    // Convert database status to form value
    const statusInput = document.getElementById('status');
    if (statusInput) {
        const statusValue = location.status === 'active' ? '1' : '0';
        statusInput.value = statusValue;
    }
    
    const descriptionInput = document.getElementById('description');
    if (descriptionInput) descriptionInput.value = location.notes || '';
    
    // NOW it's safe to call functions that use currentLevels
    if (levelSettingsEnabled) {
        generateLevelSettings();

        // Populate level settings if provided
        if (location.level_settings) {
            populateLevelSettings(location.level_settings);
        }

        distributeLevelHeights();
        distributeWeightCapacity();
        distributeItemCapacity();

        // Switch to basic tab
        switchLocationTab('basic');
    }
    
    // Update QR code
    setTimeout(() => {
        updateLocationQr();
    }, 100);
    
    // Show modal
    document.getElementById('locationModal').classList.add('show');
}

function openEditModalById(id) {
    fetch('locations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_location_details', id })
    })
    .then(resp => resp.json())
    .then(data => {
        if (data.success && data.location) {
            openEditModal(data.location);
        } else {
            alert(data.message || 'Eroare la încărcarea detaliilor locației');
        }
    })
    .catch(err => {
        console.error('Failed to load location details', err);
        alert('Eroare la încărcarea detaliilor locației');
    });
}

function closeModal() {
    document.getElementById('locationModal').classList.remove('show');
    
    // Clear any existing auto-fill messages
    const existingMessage = document.querySelector('.zone-autofill-message');
    if (existingMessage) {
        existingMessage.remove();
    }
}

// =================== TAB SWITCHING ===================

/**
 * Switch between location tabs
 */
function switchLocationTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab content
    const targetTab = document.getElementById(tabName + '-tab');
    if (targetTab) {
        targetTab.classList.add('active');
    }
    
    // Add active class to clicked button
    const tabButtons = document.querySelectorAll('.tab-button');
    tabButtons.forEach(button => {
        const onclick = button.getAttribute('onclick');
        if (onclick && onclick.includes(`switchLocationTab('${tabName}')`)) {
            button.classList.add('active');
        }
    });
}

// =================== LEVEL SETTINGS (ENHANCED SYSTEM ONLY) ===================

/**
 * Update level settings when number of levels changes
 */
function updateLevelSettings() {
    if (!levelSettingsEnabled) return;
    
    const levelsInput = document.getElementById('levels');
    const newLevels = parseInt(levelsInput.value) || 3;
    
    if (newLevels === currentLevels) return;
    
    currentLevels = newLevels;
    generateLevelSettings();
    distributeLevelHeights();
    distributeWeightCapacity();
    distributeItemCapacity();
}

/**
 * Generate level settings dynamically
 */
function generateLevelSettings() {
    const container = document.getElementById('level-settings-container');
    if (!container) return;
    
    container.innerHTML = '';
    
    for (let level = 1; level <= currentLevels; level++) {
        const levelDiv = createLevelSettingsDiv(level);
        container.appendChild(levelDiv);
        
        // Populate product dropdown for this level
        populateProductDropdown(level);
    }
}

/**
 * Create level settings div with ENHANCED subdivision system
 */
function createLevelSettingsDiv(levelNumber) {
    const levelDiv = document.createElement('div');
    levelDiv.className = 'level-settings-container';
    
    const levelName = getLevelName(levelNumber);
    
    levelDiv.innerHTML = `
        <div class="level-header" onclick="toggleLevel(${levelNumber})">
            <div class="level-title">
                <span class="material-symbols-outlined">layers</span>
                <span>Nivel ${levelNumber} - ${levelName}</span>
            </div>
            <span class="level-toggle material-symbols-outlined">expand_more</span>
        </div>
        <div class="level-content" id="level-content-${levelNumber}">
            <div class="level-grid">
                <!-- ENHANCED Subdivisions Section -->
                <div class="settings-section">
                    <h5>
                        <span class="material-symbols-outlined">view_module</span>
                        Configurare Subdiviziuni
                    </h5>
                    <div class="form-group">
                        <label class="form-check-label">
                            <input type="checkbox" 
                                   id="level_${levelNumber}_enable_subdivisions" 
                                   name="level_${levelNumber}_enable_subdivisions"
                                   onchange="toggleSubdivisions(${levelNumber})">
                            Permite subdiviziuni în acest nivel
                        </label>
                        <small class="form-help">Când este activat, nivelul va permite mai multe produse diferite în subdiviziuni separate</small>
                    </div>
                </div>
                
                <!-- Storage Policy Section -->
                <div class="settings-section" id="storage-policy-${levelNumber}">
                    <h5>
                        <span class="material-symbols-outlined">policy</span>
                        Politica de Stocare
                    </h5>
                    <div class="storage-policy-options">
                        <div class="policy-option selected" onclick="selectStoragePolicy(${levelNumber}, 'multiple_products')">
                            <input type="radio" name="level_${levelNumber}_storage_policy" value="multiple_products" checked>
                            <div>
                                <div class="policy-title">Multiple Produse</div>
                                <div class="policy-description">Permite stocarea mai multor tipuri de produse</div>
                            </div>
                        </div>
                        <div class="policy-option" onclick="selectStoragePolicy(${levelNumber}, 'single_product_type')">
                            <input type="radio" name="level_${levelNumber}_storage_policy" value="single_product_type">
                            <div>
                                <div class="policy-title">Un Singur Tip</div>
                                <div class="policy-description">Permite doar un tip de produs pe nivel</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Traditional Level Settings -->
                <div class="settings-section" id="traditional-settings-${levelNumber}">
                    <h5>
                        <span class="material-symbols-outlined">straighten</span>
                        Setări Nivel
                    </h5>
                    <div class="form-group">
                        <label class="form-label">Înălțime nivel (mm)</label>
                        <input type="number" name="level_${levelNumber}_height" id="level_${levelNumber}_height" 
                               class="form-control" value="300" min="100" max="2000">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Capacitate greutate (kg)</label>
                        <input type="number" name="level_${levelNumber}_weight" id="level_${levelNumber}_weight" 
                               class="form-control" value="50" min="1" max="500" step="0.1">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Capacitate articole</label>
                        <input type="number" name="level_${levelNumber}_capacity" id="level_${levelNumber}_capacity" 
                               class="form-control" placeholder="Opțional">
                    </div>
                    <div class="form-group" id="single-product-settings-${levelNumber}" style="display: none;">
                        <label class="form-label">Produs dedicat</label>
                        <select name="level_${levelNumber}_dedicated_product" id="level_${levelNumber}_dedicated_product" 
                                class="form-control">
                            <option value="">-- Selectează produs --</option>
                        </select>
                    </div>
                </div>
                
                <!-- ENHANCED Subdivisions Management Section -->
                <div class="settings-section subdivisions-section" id="subdivisions-section-${levelNumber}" style="display: none;">
                    <h5>
                        <span class="material-symbols-outlined">grid_view</span>
                        Gestiune Subdiviziuni
                    </h5>
                    <div class="subdivisions-list" id="subdivisions-list-${levelNumber}">
                        <!-- Subdivisions will be added here -->
                    </div>
                    <button type="button" class="btn btn-sm btn-primary" onclick="addSubdivision(${levelNumber})">
                        <span class="material-symbols-outlined">add</span>
                        Adaugă Subdiviziune
                    </button>
                </div>
            </div>
        </div>
    `;
    
    return levelDiv;
}

// =================== ENHANCED SUBDIVISION FUNCTIONS ===================

/**
 * Toggle subdivisions for a level (ENHANCED system)
 */
function toggleSubdivisions(levelNumber) {
    const enableCheckbox = document.getElementById(`level_${levelNumber}_enable_subdivisions`);
    const subdivisionSection = document.getElementById(`subdivisions-section-${levelNumber}`);
    const storagePolicy = document.getElementById(`storage-policy-${levelNumber}`);
    
    if (enableCheckbox.checked) {
        // Enable subdivisions mode
        subdivisionSection.style.display = 'block';
        
        // Force Multiple Products policy and disable other options
        const multipleProductsRadio = document.querySelector(`input[name="level_${levelNumber}_storage_policy"][value="multiple_products"]`);
        if (multipleProductsRadio) {
            multipleProductsRadio.checked = true;
        }
        
        // Disable policy selection
        const policyOptions = storagePolicy.querySelectorAll('.policy-option');
        policyOptions.forEach((option, index) => {
            if (index === 0) { // Multiple products option
                option.classList.add('selected');
                option.style.opacity = '1';
            } else {
                option.classList.remove('selected');
                option.style.opacity = '0.5';
                option.style.pointerEvents = 'none';
            }
        });
        
        // Hide single product settings
        const singleProductSettings = document.getElementById(`single-product-settings-${levelNumber}`);
        if (singleProductSettings) {
            singleProductSettings.style.display = 'none';
        }
        
        // Add policy note
        if (!document.getElementById(`subdivision-policy-note-${levelNumber}`)) {
            const note = document.createElement('div');
            note.id = `subdivision-policy-note-${levelNumber}`;
            note.className = 'form-help';
            note.style.color = 'var(--success-color)';
            note.innerHTML = '<span class="material-symbols-outlined">info</span> Politica "Multiple Produse" este activată automat pentru subdiviziuni';
            storagePolicy.appendChild(note);
        }
        
        // Initialize with one subdivision if none exist
        const subdivisionsList = document.getElementById(`subdivisions-list-${levelNumber}`);
        if (subdivisionsList.children.length === 0) {
            addSubdivision(levelNumber);
        }
        
    } else {
        // Disable subdivisions mode
        subdivisionSection.style.display = 'none';
        
        // Re-enable policy selection
        const policyOptions = storagePolicy.querySelectorAll('.policy-option');
        policyOptions.forEach(option => {
            option.style.opacity = '1';
            option.style.pointerEvents = 'auto';
        });
        
        // Remove policy note
        const note = document.getElementById(`subdivision-policy-note-${levelNumber}`);
        if (note) {
            note.remove();
        }
        
        // Clear all subdivisions
        clearSubdivisions(levelNumber);
    }
}

/**
 * Add a subdivision to a level
 */
function addSubdivision(levelNumber) {
    const subdivisionsList = document.getElementById(`subdivisions-list-${levelNumber}`);
    const subdivisionIndex = subdivisionsList.children.length + 1;
    
    const subdivisionDiv = document.createElement('div');
    subdivisionDiv.className = 'subdivision-item';
    subdivisionDiv.setAttribute('data-subdivision', subdivisionIndex);
    
    subdivisionDiv.innerHTML = `
        <div class="subdivision-header">
            <span class="subdivision-title">
                <span class="material-symbols-outlined">view_module</span>
                Subdiviziunea ${subdivisionIndex}
            </span>
            <button type="button" class="btn btn-sm btn-danger" onclick="removeSubdivision(${levelNumber}, ${subdivisionIndex})">
                <span class="material-symbols-outlined">delete</span>
            </button>
        </div>
        <div class="subdivision-content">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Produs *</label>
                    <div class="product-search-container">
                        <input type="hidden" 
                               name="level_${levelNumber}_subdivision_${subdivisionIndex}_product_id" 
                               id="level_${levelNumber}_subdivision_${subdivisionIndex}_product_id">
                        <input type="text" 
                               class="form-control product-search-input" 
                               id="level_${levelNumber}_subdivision_${subdivisionIndex}_product_search"
                               placeholder="Caută produs..." 
                               autocomplete="off"
                               onkeyup="searchProductsForSubdivision(${levelNumber}, ${subdivisionIndex}, this.value)"
                               onfocus="showProductResults(${levelNumber}, ${subdivisionIndex})">
                        <div class="product-search-results" 
                             id="level_${levelNumber}_subdivision_${subdivisionIndex}_results"
                             style="display: none;"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Capacitate articole *</label>
                    <input type="number" 
                           name="level_${levelNumber}_subdivision_${subdivisionIndex}_capacity" 
                           id="level_${levelNumber}_subdivision_${subdivisionIndex}_capacity"
                           class="form-control" 
                           min="1" 
                           placeholder="Nr. articole"
                           required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notițe</label>
                <textarea name="level_${levelNumber}_subdivision_${subdivisionIndex}_notes" 
                          class="form-control" 
                          rows="2" 
                          placeholder="Notițe opționale pentru această subdiviziune"></textarea>
            </div>
        </div>
    `;
    
    subdivisionsList.appendChild(subdivisionDiv);
    
    // Focus on product search
    setTimeout(() => {
        const searchInput = document.getElementById(`level_${levelNumber}_subdivision_${subdivisionIndex}_product_search`);
        if (searchInput) {
            searchInput.focus();
        }
    }, 100);
}

/**
 * Remove a subdivision
 */
function removeSubdivision(levelNumber, subdivisionIndex) {
    if (!confirm('Ești sigur că vrei să ștergi această subdiviziune?')) {
        return;
    }
    
    const subdivisionDiv = document.querySelector(`#subdivisions-list-${levelNumber} [data-subdivision="${subdivisionIndex}"]`);
    if (subdivisionDiv) {
        subdivisionDiv.remove();
    }
    
    // Renumber remaining subdivisions
    renumberSubdivisions(levelNumber);
}

/**
 * Clear all subdivisions
 */
function clearSubdivisions(levelNumber) {
    const subdivisionsList = document.getElementById(`subdivisions-list-${levelNumber}`);
    if (subdivisionsList) {
        subdivisionsList.innerHTML = '';
    }
}

/**
 * Renumber subdivisions after removal
 */
function renumberSubdivisions(levelNumber) {
    const subdivisionsList = document.getElementById(`subdivisions-list-${levelNumber}`);
    const subdivisions = subdivisionsList.querySelectorAll('.subdivision-item');
    
    subdivisions.forEach((subdivision, index) => {
        const newIndex = index + 1;
        const oldIndex = subdivision.getAttribute('data-subdivision');
        
        // Update data attribute
        subdivision.setAttribute('data-subdivision', newIndex);
        
        // Update title
        const title = subdivision.querySelector('.subdivision-title');
        if (title) {
            title.innerHTML = `<span class="material-symbols-outlined">view_module</span> Subdiviziunea ${newIndex}`;
        }
        
        // Update all IDs and names
        const elements = subdivision.querySelectorAll('[id*="_subdivision_"], [name*="_subdivision_"]');
        elements.forEach(element => {
            if (element.id) {
                element.id = element.id.replace(`_subdivision_${oldIndex}_`, `_subdivision_${newIndex}_`);
            }
            if (element.name) {
                element.name = element.name.replace(`_subdivision_${oldIndex}_`, `_subdivision_${newIndex}_`);
            }
        });
        
        // Update onclick attributes
        const deleteBtn = subdivision.querySelector('.btn-danger');
        if (deleteBtn) {
            deleteBtn.setAttribute('onclick', `removeSubdivision(${levelNumber}, ${newIndex})`);
        }
        
        // Update search function calls
        const searchInput = subdivision.querySelector('.product-search-input');
        if (searchInput) {
            searchInput.setAttribute('onkeyup', `searchProductsForSubdivision(${levelNumber}, ${newIndex}, this.value)`);
            searchInput.setAttribute('onfocus', `showProductResults(${levelNumber}, ${newIndex})`);
        }
    });
}

// =================== PRODUCT SEARCH FOR SUBDIVISIONS ===================

let searchTimeouts = {};
let productSearchCache = {};

/**
 * Search products for subdivision
 */
function searchProductsForSubdivision(levelNumber, subdivisionIndex, query) {
    const timeoutKey = `${levelNumber}_${subdivisionIndex}`;
    
    // Clear previous timeout
    if (searchTimeouts[timeoutKey]) {
        clearTimeout(searchTimeouts[timeoutKey]);
    }
    
    // Set new timeout to avoid too many requests
    searchTimeouts[timeoutKey] = setTimeout(async () => {
        if (query.length < 2) {
            hideProductResults(levelNumber, subdivisionIndex);
            return;
        }
        
        try {
            // Check cache first
            if (productSearchCache[query]) {
                displayProductResults(levelNumber, subdivisionIndex, productSearchCache[query]);
                return;
            }
            
            const response = await fetch(`api/products.php?search=${encodeURIComponent(query)}&limit=10`);
            const products = await response.json();
            
            if (response.ok && Array.isArray(products)) {
                productSearchCache[query] = products;
                displayProductResults(levelNumber, subdivisionIndex, products);
            }
        } catch (error) {
            console.error('Product search error:', error);
        }
    }, 300);
}

/**
 * Display product search results
 */
function displayProductResults(levelNumber, subdivisionIndex, products) {
    const resultsContainer = document.getElementById(`level_${levelNumber}_subdivision_${subdivisionIndex}_results`);
    if (!resultsContainer) return;
    
    if (products.length === 0) {
        resultsContainer.innerHTML = '<div class="search-result-item no-results">Nu s-au găsit produse</div>';
    } else {
        resultsContainer.innerHTML = products.map(product => `
            <div class="search-result-item" onclick="selectProduct(${levelNumber}, ${subdivisionIndex}, ${product.id}, '${escapeHtml(product.name)}')">
                <div class="product-name">${escapeHtml(product.name)}</div>
                <div class="product-details">${escapeHtml(product.code)} - ${escapeHtml(product.category)}</div>
            </div>
        `).join('');
    }
    
    resultsContainer.style.display = 'block';
}

/**
 * Select a product for subdivision
 */
function selectProduct(levelNumber, subdivisionIndex, productId, productName) {
    const hiddenInput = document.getElementById(`level_${levelNumber}_subdivision_${subdivisionIndex}_product_id`);
    const searchInput = document.getElementById(`level_${levelNumber}_subdivision_${subdivisionIndex}_product_search`);
    
    if (hiddenInput) hiddenInput.value = productId;
    if (searchInput) searchInput.value = productName;
    
    hideProductResults(levelNumber, subdivisionIndex);
}

/**
 * Show product results
 */
function showProductResults(levelNumber, subdivisionIndex) {
    const resultsContainer = document.getElementById(`level_${levelNumber}_subdivision_${subdivisionIndex}_results`);
    const searchInput = document.getElementById(`level_${levelNumber}_subdivision_${subdivisionIndex}_product_search`);
    
    if (resultsContainer && searchInput && searchInput.value.length >= 2) {
        resultsContainer.style.display = 'block';
    }
}

/**
 * Hide product results
 */
function hideProductResults(levelNumber, subdivisionIndex) {
    const resultsContainer = document.getElementById(`level_${levelNumber}_subdivision_${subdivisionIndex}_results`);
    if (resultsContainer) {
        resultsContainer.style.display = 'none';
    }
}

// =================== OTHER LEVEL FUNCTIONS ===================

/**
 * Toggle level visibility
 */
function toggleLevel(levelNumber) {
    const header = event.currentTarget;
    const content = document.getElementById(`level-content-${levelNumber}`);
    const toggle = header.querySelector('.level-toggle');
    
    if (content.classList.contains('active')) {
        content.classList.remove('active');
        header.classList.remove('active');
        toggle.style.transform = 'rotate(0deg)';
    } else {
        content.classList.add('active');
        header.classList.add('active');
        toggle.style.transform = 'rotate(180deg)';
    }
}

/**
 * Select storage policy for a level
 */
function selectStoragePolicy(levelNumber, policy) {
    const container = document.querySelector(`#level-content-${levelNumber} .storage-policy-options`);
    const options = container.querySelectorAll('.policy-option');
    
    options.forEach(option => option.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
    
    // Update radio button
    const radio = event.currentTarget.querySelector('input[type="radio"]');
    radio.checked = true;
}

/**
 * Distribute level heights
 */
function distributeLevelHeights() {
    // Safety check for currentLevels
    if (typeof currentLevels === 'undefined' || currentLevels <= 0) {
        currentLevels = 3; // Default fallback
    }
    
    const totalHeight = parseInt(document.getElementById('height_mm')?.value) || 900;
    const levelHeight = Math.floor(totalHeight / currentLevels);
    
    for (let level = 1; level <= currentLevels; level++) {
        const input = document.getElementById(`level_${level}_height`);
        if (input) {
            input.value = levelHeight;
        }
    }
}

/**
 * Distribute weight capacity
 */
function distributeWeightCapacity() {
    // Safety check for currentLevels
    if (typeof currentLevels === 'undefined' || currentLevels <= 0) {
        currentLevels = 3; // Default fallback
    }
    
    const totalWeight = parseFloat(document.getElementById('max_weight_kg')?.value) || 150;
    const levelWeight = (totalWeight / currentLevels).toFixed(1);
    
    for (let level = 1; level <= currentLevels; level++) {
        const input = document.getElementById(`level_${level}_weight`);
        if (input) {
            input.value = levelWeight;
        }
    }
}

/**
 * Distribute item capacity
 */
function distributeItemCapacity() {
    // Safety check for currentLevels
    if (typeof currentLevels === 'undefined' || currentLevels <= 0) {
        currentLevels = 3; // Default fallback
    }
    
    const totalCap = parseInt(document.getElementById('capacity')?.value) || 0;
    const perLevel = currentLevels > 0 ? Math.floor(totalCap / currentLevels) : 0;
    for (let level = 1; level <= currentLevels; level++) {
        const input = document.getElementById(`level_${level}_capacity`);
        if (input) {
            input.value = perLevel;
        }
    }
}

/**
 * Distribute dimensions based on input
 */
function distributeDimensions(inputId) {
    if (inputId === 'height_mm') {
        distributeLevelHeights();
    } else if (inputId === 'max_weight_kg') {
        distributeWeightCapacity();
    } else if (inputId === 'capacity') {
         distributeItemCapacity();
    }
}

/**
 * Get level name based on number
 */
function getLevelName(levelNumber) {
    switch (levelNumber) {
        case 1: return 'Jos';
        case 2: return 'Mijloc';
        case 3: return 'Sus';
        default: return `Nivel ${levelNumber}`;
    }
}

/**
 * Populate product dropdown for level
 */
function populateProductDropdown(levelNumber) {
    const select = document.getElementById(`level_${levelNumber}_dedicated_product`);
    if (!select) return;
    
    // Add products if available
    if (typeof window.allProducts !== 'undefined') {
        window.allProducts.forEach(product => {
            const option = document.createElement('option');
            option.value = product.product_id;
            option.textContent = `${product.name} (${product.sku})`;
            select.appendChild(option);
        });
    }
}

/**
 * Populate level settings from loaded data
 */
function populateLevelSettings(levelSettings) {
    levelSettings.forEach(setting => {
        const level = setting.level_number;
        
        // Storage policy
        const policyRadio = document.querySelector(`input[name="level_${level}_storage_policy"][value="${setting.storage_policy}"]`);
        if (policyRadio) {
            policyRadio.checked = true;
            selectStoragePolicy(level, setting.storage_policy);
        }
        
        // Dimensions
        const heightInput = document.getElementById(`level_${level}_height`);
        if (heightInput) heightInput.value = setting.height_mm || 300;
        
        const weightInput = document.getElementById(`level_${level}_weight`);
        if (weightInput) weightInput.value = setting.max_weight_kg || 50;

        const capacityInput = document.getElementById(`level_${level}_capacity`);
        if (capacityInput) capacityInput.value = setting.items_capacity || '';

        const productSelect = document.getElementById(`level_${level}_dedicated_product`);
        if (productSelect) productSelect.value = setting.dedicated_product_id || '';
    });
}

// =================== FORM SUBMISSION AND DATA COLLECTION ===================

/**
 * Collect subdivision data for form submission
 */
function collectSubdivisionData() {
    const subdivisionData = {};
    
    for (let level = 1; level <= currentLevels; level++) {
        const enableCheckbox = document.getElementById(`level_${level}_enable_subdivisions`);
        
        if (enableCheckbox && enableCheckbox.checked) {
            const subdivisionsList = document.getElementById(`subdivisions-list-${level}`);
            const subdivisions = subdivisionsList.querySelectorAll('.subdivision-item');
            
            subdivisionData[level] = {
                enabled: true,
                subdivisions: []
            };
            
            subdivisions.forEach((subdivision, index) => {
                const subdivisionIndex = index + 1;
                const productId = document.getElementById(`level_${level}_subdivision_${subdivisionIndex}_product_id`)?.value;
                const capacity = document.getElementById(`level_${level}_subdivision_${subdivisionIndex}_capacity`)?.value;
                const notes = document.querySelector(`[name="level_${level}_subdivision_${subdivisionIndex}_notes"]`)?.value;
                
                if (productId && capacity) {
                    subdivisionData[level].subdivisions.push({
                        product_id: productId,
                        capacity: parseInt(capacity),
                        notes: notes || ''
                    });
                }
            });
        } else {
            subdivisionData[level] = {
                enabled: false,
                subdivisions: []
            };
        }
    }
    
    return subdivisionData;
}

// =================== QR CODE FUNCTIONS ===================

function initializeQRCode() {
    const qrCanvas = document.getElementById('locationQrCanvas');
    
    if (!qrCanvas) {
        console.error('QR Canvas not found');
        return;
    }
    
    if (!window.QRious) {
        console.error('QRious library not loaded');
        return;
    }
    
    try {
        const locationCodeInput = document.getElementById('location_code');
        const initialValue = locationCodeInput ? locationCodeInput.value.trim() : '';
        
        qr = new QRious({ 
            element: qrCanvas, 
            size: 150, 
            value: initialValue || 'EMPTY',
            foreground: '#000000',
            background: '#ffffff'
        });
        
        console.log('QR initialized with value:', initialValue || 'EMPTY');
        
    } catch (error) {
        console.error('Error initializing QR code:', error);
    }
}

function updateLocationQr() {
    if (!qr) {
        console.error('QR object not initialized, trying to reinitialize...');
        initializeQRCode();
        return;
    }
    
    const codeInput = document.getElementById('location_code');
    const code = codeInput ? codeInput.value.trim() : '';
    
    try {
        if (code && code.length > 0) {
            qr.set({ value: code });
        } else {
            qr.set({ value: 'EMPTY' });
        }
    } catch (error) {
        console.error('Error updating QR code:', error);
    }
}

function downloadLocationQr() {
    const canvas = document.getElementById('locationQrCanvas');
    if (!canvas) {
        console.error('Canvas not found for download');
        return;
    }
    
    const codeInput = document.getElementById('location_code');
    const code = codeInput ? codeInput.value.trim() : 'location';
    
    try {
        const link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = `${code}_qr.png`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    } catch (error) {
        console.error('Error downloading QR code:', error);
    }
}

// =================== EVENT LISTENERS ===================

function setupEventListeners() {
    // Location code input listener
    const locationCodeInput = document.getElementById('location_code');
    const zoneInput = document.getElementById('zone');
    
    if (locationCodeInput) {
        locationCodeInput.addEventListener('input', function() {
            const code = this.value.trim();
            
            // Update QR code immediately
            updateLocationQr();
            
            // Also update zone extraction
            if (zoneInput && code && code.includes('-')) {
                const extractedZone = code.split('-')[0].toUpperCase();
                zoneInput.value = extractedZone;
                zoneInput.style.backgroundColor = 'var(--success-color-light, #d4edda)';
                showZoneAutoFill(extractedZone);
            } else if (zoneInput) {
                zoneInput.value = '';
                zoneInput.style.backgroundColor = '';
            }
        });
    }
    
    // Levels input listener
    const levelsInput = document.getElementById('levels');
    if (levelsInput) {
        levelsInput.addEventListener('change', updateLocationQr);
    }
}

// =================== UTILITY FUNCTIONS ===================

function showZoneAutoFill(zone) {
    const existingMessage = document.querySelector('.zone-autofill-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    const zoneInput = document.getElementById('zone');
    const message = document.createElement('small');
    message.className = 'zone-autofill-message';
    message.style.color = 'var(--success-color, #198754)';
    message.style.fontSize = '0.75rem';
    message.style.marginTop = '0.25rem';
    message.style.display = 'block';
    message.textContent = `✓ Zonă detectată automat: ${zone}`;
    
    zoneInput.parentNode.appendChild(message);
    
    // Remove message after 3 seconds
    setTimeout(() => {
        if (message.parentNode) {
            message.parentNode.removeChild(message);
        }
    }, 3000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// =================== ADDITIONAL MODAL FUNCTIONS ===================

function openDeleteModal(locationId, locationCode) {
    document.getElementById('deleteLocationId').value = locationId;
    document.getElementById('deleteLocationCode').textContent = locationCode;
    document.getElementById('deleteModal').classList.add('show');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
}

// Close modals when clicking outside
window.onclick = function(event) {
    const locationModal = document.getElementById('locationModal');
    const deleteModal = document.getElementById('deleteModal');
    
    if (event.target === locationModal) {
        closeModal();
    }
    if (event.target === deleteModal) {
        closeDeleteModal();
    }
}

// Close modals with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeModal();
        closeDeleteModal();
    }
});

// =================== FORM VALIDATION ===================

// Enhanced form validation with level settings support
document.getElementById('locationForm')?.addEventListener('submit', function(event) {
    const locationCode = document.getElementById('location_code').value.trim();
    const zone = document.getElementById('zone').value.trim();
    const type = document.getElementById('type').value;
    
    if (!locationCode || !zone) {
        event.preventDefault();
        alert('Codul locației și zona sunt obligatorii!');
        return false;
    }
    
    // Additional validation for level settings if enabled
    if (levelSettingsEnabled && currentLevels > 0) {
        let validationErrors = [];
        
        for (let level = 1; level <= currentLevels; level++) {
            const heightInput = document.getElementById(`level_${level}_height`);
            const weightInput = document.getElementById(`level_${level}_weight`);
            
            if (heightInput && parseInt(heightInput.value) < 100) {
                validationErrors.push(`Înălțimea nivelului ${level} trebuie să fie cel puțin 100mm`);
            }
            
            if (weightInput && parseFloat(weightInput.value) < 1) {
                validationErrors.push(`Capacitatea de greutate a nivelului ${level} trebuie să fie cel puțin 1kg`);
            }
        }
        
        if (validationErrors.length > 0) {
            event.preventDefault();
            alert('Erori de validare:\n' + validationErrors.join('\n'));
            return false;
        }

        // Collect level settings data and store in hidden field
        const levelData = {};
        for (let level = 1; level <= currentLevels; level++) {
            levelData[level] = {
                storage_policy: document.querySelector(`input[name="level_${level}_storage_policy"]:checked`)?.value || 'multiple_products',
                height_mm: parseInt(document.getElementById(`level_${level}_height`)?.value) || 0,
                max_weight_kg: parseFloat(document.getElementById(`level_${level}_weight`)?.value) || 0,
                items_capacity: parseInt(document.getElementById(`level_${level}_capacity`)?.value) || null,
                dedicated_product_id: document.getElementById(`level_${level}_dedicated_product`)?.value || null,
                allow_other_products: document.getElementById(`level_${level}_allow_others`)?.checked ?? true,
                volume_min_liters: parseFloat(document.querySelector(`input[name="level_${level}_volume_min"]`)?.value) || null,
                volume_max_liters: parseFloat(document.querySelector(`input[name="level_${level}_volume_max"]`)?.value) || null,
                enable_auto_repartition: document.getElementById(`level_${level}_auto_repartition`)?.checked || false,
                repartition_trigger_threshold: parseInt(document.querySelector(`input[name="level_${level}_threshold"]`)?.value) || 80,
                priority_order: parseInt(document.querySelector(`input[name="level_${level}_priority"]`)?.value) || 1
            };
        }
        
        // Add subdivision data
        const subdivisionData = collectSubdivisionData();
        if (Object.keys(subdivisionData).length > 0) {
            // Add subdivision data as hidden field
            let subdivisionField = document.getElementById('subdivision_form_data');
            if (!subdivisionField) {
                subdivisionField = document.createElement('input');
                subdivisionField.type = 'hidden';
                subdivisionField.id = 'subdivision_form_data';
                subdivisionField.name = 'subdivision_form_data';
                this.appendChild(subdivisionField);
            }
            subdivisionField.value = JSON.stringify(subdivisionData);
        }
        
        const hiddenField = document.getElementById('level_settings_data');
        if (hiddenField) {
            hiddenField.value = JSON.stringify(levelData);
        }
    }

    return true;
});

// =================== WAREHOUSE VISUALIZATION (Keep existing) ===================
// [Include your existing EnhancedWarehouseVisualization class here - it's working fine]
/**
 * Enhanced Warehouse Visualization Class
 * Complete implementation with dynamic zones and enhanced functionality
 */
class EnhancedWarehouseVisualization {
    constructor() {
        this.currentView = 'total';
        this.currentZone = null;
        this.locations = window.warehouseData || [];
        this.zones = this.extractZones();
        this.tooltip = null;
        this.isLoading = false;
        
        this.init();
    }

    init() {
        this.createTooltip();
        this.bindEvents();
        this.renderZones();
        this.updateCurrentViewIndicator();
        
        // Auto-select first zone if available
        if (this.zones.length > 0) {
            this.selectZone(this.zones[0].name);
        }
        
        // Show legend if shelves are visible
        this.toggleLegend(this.zones.length > 0 && this.currentZone);
    }

    extractZones() {
    // Use dynamic zones from PHP if available, otherwise extract from locations
    if (window.dynamicZones && window.dynamicZones.length > 0) {
        return window.dynamicZones.map(zone => ({
            name: zone.zone_name,
            shelfCount: parseInt(zone.shelf_count) || 0,
            avgOccupancy: parseFloat(zone.avg_occupancy) || 0,
            totalCapacity: parseInt(zone.total_capacity) || 0,
            totalItems: parseInt(zone.total_items) || 0,
            // FIXED: Case insensitive filtering
            shelves: this.locations.filter(l => l.zone === zone.zone_name && l.type.toLowerCase() === 'shelf')
        }));
    }
    
    // Fallback: extract from locations data
    const zoneMap = new Map();
    
    this.locations.forEach(location => {
        const zoneName = location.zone;
        // FIXED: Case insensitive type check
        if (zoneName && location.type.toLowerCase() === 'shelf') {
            if (!zoneMap.has(zoneName)) {
                zoneMap.set(zoneName, {
                    name: zoneName,
                    shelves: [],
                    totalOccupancy: 0,
                    shelfCount: 0
                });
            }
            
            const zone = zoneMap.get(zoneName);
            zone.shelves.push(location);
            zone.shelfCount++;
            zone.totalOccupancy += (location.occupancy?.total || 0);
        }
    });
    
    // Calculate average occupancy for each zone
    const zones = Array.from(zoneMap.values()).map(zone => ({
        ...zone,
        avgOccupancy: zone.shelfCount > 0 ? zone.totalOccupancy / zone.shelfCount : 0
    }));
    
    return zones.sort((a, b) => a.name.localeCompare(b.name));
}

    createTooltip() {
        if (document.getElementById('enhancedTooltip')) return;
        
        const tooltip = document.createElement('div');
        tooltip.id = 'enhancedTooltip';
        tooltip.className = 'enhanced-tooltip';
        tooltip.style.cssText = `
            position: absolute;
            background: var(--black, #0F1013);
            color: var(--white, #FEFFFF);
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color-strong, rgba(255,255,255,0.25));
            font-size: 0.8rem;
            z-index: 1000;
            min-width: 280px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
            pointer-events: none;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.2s ease;
        `;
        
        document.body.appendChild(tooltip);
        this.tooltip = tooltip;
    }

    bindEvents() {
        // View buttons
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const view = e.currentTarget.dataset.view;
                this.switchView(view);
            });
        });

        // Zone selection
        document.addEventListener('click', (e) => {
            if (e.target.closest('.storage-zone')) {
                const zone = e.target.closest('.storage-zone').dataset.zone;
                this.selectZone(zone);
            }
        });

        // Enhanced hover tooltips
        document.addEventListener('mouseover', (e) => {
            if (e.target.closest('.shelf-item')) {
                const shelfElement = e.target.closest('.shelf-item');
                const shelfId = parseInt(shelfElement.dataset.shelfId);
                const shelf = this.locations.find(s => s.id === shelfId);
                if (shelf) {
                    this.showEnhancedTooltip(e, shelf);
                }
            }
        });

        document.addEventListener('mouseout', (e) => {
            if (e.target.closest('.shelf-item')) {
                this.hideTooltip();
            }
        });

        document.addEventListener('mousemove', (e) => {
            if (this.tooltip && this.tooltip.style.opacity === '1') {
                this.updateTooltipPosition(e);
            }
        });

        // Table filters
        const zoneFilter = document.getElementById('zoneFilter');
        const typeFilter = document.getElementById('typeFilter');
        const searchFilter = document.getElementById('searchFilter');
        
        if (zoneFilter) zoneFilter.addEventListener('change', () => this.filterTable());
        if (typeFilter) typeFilter.addEventListener('change', () => this.filterTable());
        if (searchFilter) searchFilter.addEventListener('input', this.debounce(() => this.filterTable(), 300));
    }

    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    switchView(view) {
        this.currentView = view;

        // Update active button
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelector(`[data-view="${view}"]`)?.classList.add('active');

        // Toggle between visualization and table
        const warehouseViz = document.getElementById('warehouseVisualization');
        const tableContainer = document.getElementById('tableContainer');

        if (view === 'table') {
            if (warehouseViz) warehouseViz.classList.add('hidden');
            if (tableContainer) tableContainer.classList.add('active');
            this.renderTable();
        } else {
            if (warehouseViz) warehouseViz.classList.remove('hidden');
            if (tableContainer) tableContainer.classList.remove('active');
            this.renderShelves();
        }

        this.updateCurrentViewIndicator();
    }

    updateCurrentViewIndicator() {
        const indicator = document.getElementById('currentViewText');
        const icon = document.getElementById('currentViewIcon');

        if (!indicator || !icon) return;

        const viewLabels = {
            'total': 'Vizualizare Zone și Rafturi',
            'table': 'Vizualizare Tabel'
        };

        const viewIcons = {
            'total': 'shelves',
            'table': 'table_view'
        };

        indicator.textContent = viewLabels[this.currentView] || 'Necunoscut';
        icon.textContent = viewIcons[this.currentView] || 'help';
    }

    selectZone(zoneName) {
        this.currentZone = zoneName;
        
        // Update zone selection visual feedback
        document.querySelectorAll('.storage-zone').forEach(z => {
            const isSelected = z.dataset.zone === zoneName;
            z.style.borderColor = isSelected ? 'var(--success-color, #198754)' : 'var(--border-color)';
            z.style.backgroundColor = isSelected ? 'var(--button-hover)' : '';
            
            // Add selected class for better styling
            if (isSelected) {
                z.classList.add('selected');
            } else {
                z.classList.remove('selected');
            }
        });

        this.renderShelves();
        this.toggleLegend(true);
    }

    renderZones() {
        const zonesGrid = document.getElementById('storageZonesGrid');
        if (!zonesGrid) return;
        
        if (this.zones.length === 0) {
            zonesGrid.innerHTML = this.getEmptyZonesState();
            return;
        }
        
        zonesGrid.innerHTML = this.zones.map(zone => `
            <div class="storage-zone" data-zone="${zone.name}">
                <span class="material-symbols-outlined zone-icon">shelves</span>
                <div class="zone-label">Zona ${zone.name}</div>
                <div class="zone-stats">${zone.shelfCount || 0} rafturi • ${Math.round(zone.avgOccupancy || 0)}% ocupare</div>
            </div>
        `).join('');
    }

    renderShelves() {
    const shelvesGrid = document.getElementById('shelvesGrid');
    const shelvesContainer = document.getElementById('shelvesContainer');
    const shelvesTitle = document.getElementById('shelvesTitle');
    
    if (!shelvesGrid) return;
    
    if (!this.currentZone) {
        shelvesGrid.innerHTML = this.getEmptyShelvesState();
        if (shelvesTitle) {
            shelvesTitle.textContent = 'Selectează o zonă pentru a vedea rafturile';
        }
        this.toggleLegend(false);
        return;
    }
    
    // Update header
    if (shelvesTitle) {
        shelvesTitle.textContent = `Rafturi - Zona ${this.currentZone}`;
    }
    
    // FIXED: Case insensitive type filtering
    const zoneShelves = this.locations.filter(l => 
        l.zone === this.currentZone && l.type.toLowerCase() === 'shelf'
    );
    
    if (zoneShelves.length === 0) {
        shelvesGrid.innerHTML = this.getEmptyShelvesState(`Nu există rafturi în zona ${this.currentZone}`);
        this.toggleLegend(false);
        return;
    }
    
    // Sort shelves by location code for consistent display
    zoneShelves.sort((a, b) => a.location_code.localeCompare(b.location_code));
    
    shelvesGrid.innerHTML = zoneShelves.map(shelf => this.createShelfElement(shelf)).join('');
    this.toggleLegend(true);
}

    createShelfElement(shelf) {
        const occupancyTotal = shelf.occupancy?.total || 0;
        const levels = parseInt(shelf.levels || 3);

        // Build occupancy array top-down. If we only have the classic
        // top/middle/bottom values use them, otherwise distribute total
        // occupancy evenly across all configured levels.
        let levelOccupancies = [];
        if (levels === 3) {
            levelOccupancies = [
                shelf.occupancy?.top || 0,
                shelf.occupancy?.middle || 0,
                shelf.occupancy?.bottom || 0
            ];
        } else {
            const each = levels > 0 ? occupancyTotal / levels : 0;
            for (let i = 0; i < levels; i++) {
                levelOccupancies.push(each);
            }
        }

        // Generate level bars from top (index 0) to bottom (last index)
        const levelsHTML = `
            <div class="shelf-levels">
                ${levelOccupancies.map((occ, idx) => {
                    const levelName = levels - idx;
                    return `<div class="shelf-level" data-level="${levelName}" title="Nivel ${levelName}: ${Math.round(occ)}%">
                                <div class="level-fill ${this.getOccupancyClass(occ)}" style="width: ${occ}%"></div>
                            </div>`;
                }).join('')}
            </div>
        `;

        return `
            <div class="shelf-item ${this.getOccupancyClass(occupancyTotal)}" data-shelf-id="${shelf.id}">
                <div class="shelf-code">${shelf.location_code}</div>
                ${levelsHTML}
                <div class="shelf-occupancy">${Math.round(occupancyTotal)}%</div>
            </div>
        `;
    }

    getOccupancyClass(percentage) {
        if (percentage === 0) return 'occupancy-empty';
        if (percentage <= 50) return 'occupancy-low';
        if (percentage <= 79) return 'occupancy-medium';
        if (percentage <= 94) return 'occupancy-high';
        return 'occupancy-full';
    }

    toggleLegend(show) {
        const legend = document.getElementById('occupancyLegend');
        if (legend) {
            legend.style.display = show ? 'flex' : 'none';
        }
    }

    renderTable() {
    const tbody = document.getElementById('locationsTableBody');
    if (!tbody) return;

    let filteredLocations = [...this.locations];
    
    // Apply current filters
    const zoneFilter = document.getElementById('zoneFilter')?.value;
    const typeFilter = document.getElementById('typeFilter')?.value;
    const searchFilter = document.getElementById('searchFilter')?.value.toLowerCase();
    
    if (zoneFilter) {
        filteredLocations = filteredLocations.filter(l => l.zone === zoneFilter);
    }
    
    if (typeFilter) {
        // FIXED: Case insensitive type filtering
        filteredLocations = filteredLocations.filter(l => l.type.toLowerCase() === typeFilter.toLowerCase());
    }
    
    if (searchFilter) {
        filteredLocations = filteredLocations.filter(l => 
            l.location_code.toLowerCase().includes(searchFilter)
        );
    }
    
    tbody.innerHTML = filteredLocations.map(location => {
        const occupancyClass = this.getOccupancyClass(location.occupancy?.total || 0);
        // FIXED: Case insensitive type check
        const isShelf = location.type.toLowerCase() === 'shelf';
        
        return `
                <tr>
                    <td><strong>${location.location_code}</strong></td>
                    <td>Zona ${location.zone}</td>
                    <td>${location.type}</td>
                    <td><span class="occupancy-badge ${occupancyClass}">${Math.round(location.occupancy?.total || 0)}%</span></td>
                    <td>${isShelf ? Math.round(location.occupancy?.bottom || 0) + '%' : '-'}</td>
                    <td>${isShelf ? Math.round(location.occupancy?.middle || 0) + '%' : '-'}</td>
                   <td>${isShelf ? Math.round(location.occupancy?.top || 0) + '%' : '-'}</td>
                    <td>${location.items?.total || location.total_items || 0}</td>
                    <td>${location.unique_products || 0}</td>
                    <td>
                        <button class="btn btn-sm btn-outline" onclick="openEditModalById(${location.id})" title="Editează">
                            <span class="material-symbols-outlined">edit</span>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="openDeleteModal(${location.id}, '${location.location_code}')" title="Șterge">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </td>
                </tr>
            `;
    }).join('');
    
    // Update table info
    this.updateTableInfo(filteredLocations.length, this.locations.length);
}

    updateTableInfo(filtered, total) {
        // You can add table pagination info here if needed
        console.log(`Showing ${filtered} of ${total} locations`);
    }

    filterTable() {
        if (this.currentView === 'table') {
            this.renderTable();
        }
    }

    showEnhancedTooltip(event, shelf) {
        if (!this.tooltip) return;

        const occupancy = shelf.occupancy || {};
        const items = shelf.items || {
            total: shelf.total_items || 0,
            bottom: shelf.bottom_items || 0,
            middle: shelf.middle_items || 0,
            top: shelf.top_items || 0
        };

        this.tooltip.innerHTML = `
            <div style="font-weight: 600; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <span class="material-symbols-outlined" style="font-size: 1rem;">info</span>
                Raft ${shelf.location_code}
            </div>
            <div style="margin-bottom: 0.75rem;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem; font-size: 0.75rem;">
                    <span>🔼 Sus:</span>
                    <span>${Math.round(occupancy.top || 0)}% (${items.top || 0} articole)</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem; font-size: 0.75rem;">
                    <span>➖ Mijloc:</span>
                    <span>${Math.round(occupancy.middle || 0)}% (${items.middle || 0} articole)</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem; font-size: 0.75rem;">
                    <span>🔽 Jos:</span>
                    <span>${Math.round(occupancy.bottom || 0)}% (${items.bottom || 0} articole)</span>
                </div>
            </div>
            <div style="padding-top: 0.5rem; border-top: 1px solid var(--border-color); font-size: 0.7rem; color: var(--text-secondary);">
                📦 Total: ${items.total || 0} articole din ${shelf.capacity || 0}<br>
                🏷️ Produse unice: ${shelf.unique_products || 0}<br>
                📍 Zonă: ${shelf.zone}
            </div>
        `;

        this.tooltip.style.opacity = '1';
        this.tooltip.style.transform = 'translateY(0)';
        this.updateTooltipPosition(event);
    }

    updateTooltipPosition(event) {
        if (!this.tooltip) return;

        const rect = this.tooltip.getBoundingClientRect();
        let x = event.clientX + 15;
        let y = event.clientY - rect.height - 15;

        // Keep tooltip within viewport
        if (x + rect.width > window.innerWidth) {
            x = event.clientX - rect.width - 15;
        }
        if (y < 0) {
            y = event.clientY + 15;
        }

        this.tooltip.style.left = x + 'px';
        this.tooltip.style.top = y + 'px';
    }

    hideTooltip() {
        if (this.tooltip) {
            this.tooltip.style.opacity = '0';
            this.tooltip.style.transform = 'translateY(10px)';
        }
    }

    getEmptyZonesState() {
        return `
            <div style="grid-column: 1 / -1; text-align: center; padding: 2rem; color: var(--text-secondary);">
                <span class="material-symbols-outlined" style="font-size: 3rem; margin-bottom: 1rem; display: block; color: var(--border-color);">
                    shelves
                </span>
                <h3 style="margin-bottom: 0.5rem; color: var(--text-primary);">Nu există zone de stocare</h3>
                <p>Adaugă rafturi cu format de cod ca MID-1A pentru a crea zone automat.</p>
            </div>
        `;
    }

    getEmptyShelvesState(customMessage = null) {
        return `
            <div style="grid-column: 1 / -1; text-align: center; padding: 2rem; color: var(--text-secondary);">
                <span class="material-symbols-outlined" style="font-size: 2.5rem; margin-bottom: 1rem; display: block; color: var(--border-color);">
                    inventory_2
                </span>
                <p>${customMessage || 'Selectează o zonă pentru a vedea rafturile'}</p>
            </div>
        `;
    }

    // Public methods for external use
    refresh() {
        this.locations = window.warehouseData || [];
        this.zones = this.extractZones();
        this.renderZones();
        if (this.currentZone) {
            this.renderShelves();
        }
        if (this.currentView === 'table') {
            this.renderTable();
        }
    }

    setLoading(loading) {
        this.isLoading = loading;
        // Add loading state UI updates here if needed
    }

    debugLocationsData() {
    console.log('=== DEBUGGING LOCATIONS DATA ===');
    console.log('Total locations:', this.locations.length);
    console.log('Locations by type:');
    
    const typeMap = new Map();
    this.locations.forEach(loc => {
        const type = loc.type;
        if (!typeMap.has(type)) {
            typeMap.set(type, []);
        }
        typeMap.get(type).push(loc.location_code);
    });
    
    typeMap.forEach((codes, type) => {
        console.log(`  ${type}: ${codes.length} locations - ${codes.slice(0, 5).join(', ')}${codes.length > 5 ? '...' : ''}`);
    });
    
    console.log('Dynamic zones:', window.dynamicZones);
    console.log('Extracted zones:', this.zones);
    console.log('================================');
}

}
// =================== GLOBAL FUNCTION EXPORTS ===================

// Make functions globally available
window.closeModal = closeModal;
window.openDeleteModal = openDeleteModal;
window.closeDeleteModal = closeDeleteModal;
window.switchLocationTab = switchLocationTab;
window.updateLevelSettings = updateLevelSettings;
window.toggleLevel = toggleLevel;
window.selectStoragePolicy = selectStoragePolicy;
window.distributeLevelHeights = distributeLevelHeights;
window.distributeWeightCapacity = distributeWeightCapacity;
window.distributeItemCapacity = distributeItemCapacity;
window.updateLocationQr = updateLocationQr;
window.downloadLocationQr = downloadLocationQr;
window.openCreateModal = openCreateModal;
window.openEditModal = openEditModal;
window.openEditModalById = openEditModalById;
window.toggleSubdivisions = toggleSubdivisions;
window.addSubdivision = addSubdivision;
window.removeSubdivision = removeSubdivision;
window.searchProductsForSubdivision = searchProductsForSubdivision;
window.selectProduct = selectProduct;
window.showProductResults = showProductResults;
window.hideProductResults = hideProductResults;
window.collectSubdivisionData = collectSubdivisionData;

console.log('✅ Enhanced Locations JavaScript loaded successfully');