/**
 * Enhanced Locations JavaScript - Dynamic Level Creation System
 * File: scripts/locations.js
 * Dynamic level creation with enhanced subdivisions per level
 */

// =================== GLOBAL VARIABLES ===================
let qr = null;
let levelCounter = 0;
let createdLevels = [];
let levelMultiProducts = {};
let multiProductTimeouts = {};

function getNextAvailableLevelId() {
    let id = 1;
    const existing = createdLevels.map(l => l.id);
    while (existing.includes(id)) id++;
    return id;
}
let currentLevels = 0; // Start with 0, user creates levels dynamically
let levelSettingsEnabled = true; // Force enable enhanced system
let dynamicSystemInitialized = false; // Flag to prevent duplicate initialization

// =================== INITIALIZATION ===================
document.addEventListener('DOMContentLoaded', function() {
    console.log('Enhanced Locations page loaded - Dynamic Level System');
    
    // Initialize warehouse visualization if data exists
    if (typeof window.warehouseData !== 'undefined') {
        window.warehouseViz = new EnhancedWarehouseVisualization();
    }

    // Initialize core functionality
    initializeQRCode();
    setupEventListeners();
    
    // Initialize the DYNAMIC level system
    initializeDynamicLevelSystem();
    
    // Make sure Add Location button works
    const addLocationBtn = document.querySelector('[onclick="openCreateModal()"]');
    if (addLocationBtn) {
        addLocationBtn.addEventListener('click', function(e) {
            e.preventDefault();
            openCreateModal();
        });
        console.log('✅ Add Location button connected');
    }
    
    // Initialize view switching buttons
    document.querySelectorAll('.view-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const view = this.dataset.view;
            if (window.warehouseViz) {
                window.warehouseViz.switchView(view);
            }
        });
    });
    
    console.log('✅ All functionality initialized');
});

/**
 * Initialize the dynamic level system
 */
function initializeDynamicLevelSystem() {
    if (dynamicSystemInitialized) {
        console.log('⚠️ Dynamic system already initialized, skipping...');
        return;
    }
    
    console.log('🔧 Initializing dynamic level system...');
    
    // STEP 1: Remove basic form fields first
    updateModalStructure();
    
    // STEP 2: Add tabbed interface (this creates the #dynamic-levels-section)
    addEnhancedTabs();
    
    // STEP 3: Now populate the dynamic level container (after tabs are created)
    createDynamicLevelContainer();
    
    // STEP 4: Enhance form submission
    enhanceFormSubmission();
    
    // Mark as initialized
    dynamicSystemInitialized = true;
    console.log('✅ Dynamic level system initialized successfully');
}

/**
 * Update modal structure to remove old elements and add tabs
 */
function updateModalStructure() {
    // Remove old general settings fields that will be replaced by dynamic levels
    const fieldsToRemove = [
        'capacity',
        'levels', 
        'length_mm',
        'depth_mm', 
        'height_mm',
        'max_weight_kg'
    ];
    
    fieldsToRemove.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            const formGroup = field.closest('.form-group');
            if (formGroup) formGroup.remove();
        }
    });
    
    // Remove description field from basic tab (will be moved to basic info)
    const descField = document.getElementById('description');
    if (descField) {
        const formGroup = descField.closest('.form-group');
        if (formGroup) formGroup.remove();
    }
}

/**
 * Add enhanced tabs to modal
 */
function addEnhancedTabs() {
    const form = document.getElementById('locationForm');
    if (!form) return;

    const modalBody = form.querySelector('.modal-body');
    if (!modalBody) return;
    
    // Avoid duplicating tabs if they already exist
    if (modalBody.querySelector('.location-tabs')) {
        return;
    }

    // Preserve existing modal body content
    const existingContent = modalBody.innerHTML;
    modalBody.innerHTML = `
        <div class="location-tabs">
            <button type="button" class="tab-button active" onclick="switchLocationTab('basic')">
                <span class="material-symbols-outlined">info</span>
                Informații de Bază
            </button>
            <button type="button" class="tab-button" onclick="switchLocationTab('dimensions')">
                <span class="material-symbols-outlined">straighten</span>
                Dimensiuni Globale
            </button>
            <button type="button" class="tab-button" onclick="switchLocationTab('levels')">
                <span class="material-symbols-outlined">layers</span>
                Configurare Niveluri
            </button>
        </div>

        <!-- Basic Information Tab -->
        <div id="basic-tab" class="tab-content active">
            ${existingContent}
            <!-- Add description field back to basic tab -->
            <div class="form-group">
                <label for="description" class="form-label">
                    <span class="material-symbols-outlined">description</span>
                    Descriere
                </label>
                <textarea id="description" name="description" class="form-control" rows="3" 
                          placeholder="Descriere opțională a locației"></textarea>
            </div>
        </div>
        
        <!-- Global Dimensions Tab -->
        <div id="dimensions-tab" class="tab-content">
            <div class="form-grid">
                <div class="form-group">
                    <label for="global_length_mm" class="form-label">
                        <span class="material-symbols-outlined">straighten</span>
                        Lungime Globală (mm)
                    </label>
                    <input type="number" id="global_length_mm" name="global_length_mm" class="form-control" 
                           value="1000" min="100" max="10000">
                    <small class="form-help">Lungimea totală a structurii de stocare</small>
                </div>
                
                <div class="form-group">
                    <label for="global_depth_mm" class="form-label">
                        <span class="material-symbols-outlined">width</span>
                        Adâncime Globală (mm)
                    </label>
                    <input type="number" id="global_depth_mm" name="global_depth_mm" class="form-control" 
                           value="400" min="100" max="2000">
                    <small class="form-help">Adâncimea structurii de stocare</small>
                </div>
                
                <div class="form-group">
                    <label for="global_max_weight_kg" class="form-label">
                        <span class="material-symbols-outlined">scale</span>
                        Greutate Maximă Globală (kg)
                    </label>
                    <input type="number" id="global_max_weight_kg" name="global_max_weight_kg" class="form-control" 
                           value="500" min="10" max="5000" step="0.1">
                    <small class="form-help">Greutatea maximă suportată de întreaga structură</small>
                </div>
            </div>
        </div>
        
        <!-- Dynamic Levels Tab -->
        <div id="levels-tab" class="tab-content">
            <div id="dynamic-levels-section">
                <!-- This will be populated by createDynamicLevelContainer -->
            </div>
        </div>
    `;
}

/**
 * Create the dynamic level management container
 */
function createDynamicLevelContainer() {
    const levelsSection = document.getElementById('dynamic-levels-section');
    if (!levelsSection) {
        console.error('❌ dynamic-levels-section not found! Cannot create level container.');
        return;
    }
    
    console.log('✅ Creating dynamic level container...');
    
    levelsSection.innerHTML = `
        <div class="section-header">
            <h4 class="form-section-title">
                <span class="material-symbols-outlined">layers</span>
                Configurare Niveluri Dinamice
            </h4>
            <button type="button" class="btn btn-success add-level-btn" onclick="addNewLevel()">
                <span class="material-symbols-outlined">add</span>
                Adaugă Nivel
            </button>
        </div>
        <div id="levels-container" class="levels-container">
            <!-- Dynamic levels will be added here -->
        </div>
        <div class="levels-summary" id="levels-summary" style="display: none;">
            <small class="text-muted">
                <span class="material-symbols-outlined">info</span>
                Total niveluri: <span id="total-levels-count">0</span>
            </small>
        </div>
    `;
    
    console.log('✅ Dynamic level container created successfully');
}

// =================== TAB SWITCHING ===================

/**
 * Switch between location tabs
 */
function switchLocationTab(tabName) {
    console.log('🔄 Switching to tab:', tabName);
    
    // Hide all tab contents
    const allTabContents = document.querySelectorAll('.tab-content');
    console.log(`Found ${allTabContents.length} tab contents`);
    
    allTabContents.forEach((content, index) => {
        content.classList.remove('active');
        content.style.display = 'none'; // Force hide with style
        console.log(`Tab ${index}: ${content.id} - hidden`);
    });
    
    // Remove active class from all tab buttons
    const allTabButtons = document.querySelectorAll('.tab-button');
    console.log(`Found ${allTabButtons.length} tab buttons`);
    
    allTabButtons.forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab content
    const targetTab = document.getElementById(tabName + '-tab');
    if (targetTab) {
        targetTab.classList.add('active');
        targetTab.style.display = 'block'; // Force show with style
        console.log(`✅ Activated tab: ${targetTab.id}`);
    } else {
        console.error(`❌ Target tab not found: ${tabName}-tab`);
    }
    
    // Add active class to clicked button
    const tabButtons = document.querySelectorAll('.tab-button');
    tabButtons.forEach(button => {
        const onclick = button.getAttribute('onclick');
        if (onclick && onclick.includes(`switchLocationTab('${tabName}')`)) {
            button.classList.add('active');
            console.log(`✅ Activated button for: ${tabName}`);
        }
    });
    
    console.log(`✅ Tab switch completed: ${tabName}`);
}

// =================== DYNAMIC LEVEL MANAGEMENT ===================

/**
 * Add a new level with full configuration
 */
function addNewLevel() {
    console.log('🔧 Adding new level...');
    
    // Safety check - make sure container exists
    const container = document.getElementById('levels-container');
    if (!container) {
        console.error('❌ levels-container not found! Cannot add level.');
        alert('Error: Level container not found. Please refresh the page.');
        return;
    }
    
    const levelId = getNextAvailableLevelId();
    if (levelId > levelCounter) {
        levelCounter = levelId;
    }
    
    // Create level object
    const newLevel = {
        id: levelId,
        name: `Nivel ${levelId}`,
        position: getLevelPosition(levelId)
    };
    
    createdLevels.push(newLevel);
    currentLevels = createdLevels.length;
    
    // Create the level HTML
    const levelElement = document.createElement('div');
    levelElement.className = 'dynamic-level-item';
    levelElement.id = `level-item-${levelId}`;
    levelElement.innerHTML = createLevelHTML(newLevel);
    
    // Add to container
    container.appendChild(levelElement);
    
    // Update summary
    updateLevelsSummary();
    
    // Initialize level interactions
    initializeLevelInteractions(levelId);
    
    // Scroll to new level
    levelElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    
    // Focus on level name input
    setTimeout(() => {
        const nameInput = levelElement.querySelector('.level-name-input');
        if (nameInput) nameInput.focus();
    }, 300);
    
    console.log('✅ Added new level:', levelId);
}

/**
 * Create HTML for a single level with enhanced subdivisions
 */
function createLevelHTML(level) {
    return `
        <div class="level-card" data-level-id="${level.id}">
            <div class="level-header">
                <div class="level-header-content">
                    <div class="level-icon">
                        <span class="material-symbols-outlined">layers</span>
                    </div>
                    <div class="level-info">
                        <input type="text" class="level-name-input" value="${level.name}" 
                               placeholder="Nume nivel" onchange="updateLevelName(${level.id}, this.value)">
                        <small class="level-position">${level.position}</small>
                    </div>
                </div>
                <div class="level-actions">
                    <button type="button" class="btn-icon btn-toggle" onclick="toggleLevel(${level.id})" 
                            title="Expandează/Restrânge">
                        <span class="material-symbols-outlined">expand_more</span>
                    </button>
                    <button type="button" class="btn-icon btn-danger" onclick="removeLevel(${level.id})" 
                            title="Șterge nivel">
                        <span class="material-symbols-outlined">delete</span>
                    </button>
                </div>
            </div>
            
            <div class="level-content" id="level-content-${level.id}" style="display: none;">
                <div class="level-settings-grid">
                    <!-- Enhanced Subdivisions Section -->
                    <div class="settings-section">
                        <h5>
                            <span class="material-symbols-outlined">view_module</span>
                            Configurare Subdiviziuni
                        </h5>
                        <div class="form-group">
                            <label class="form-check-label">
                                <input type="checkbox" 
                                       id="level_${level.id}_enable_subdivisions" 
                                       name="level_${level.id}_enable_subdivisions"
                                       onchange="toggleSubdivisions(${level.id})">
                                Permite subdiviziuni în acest nivel
                            </label>
                            <small class="form-help">Când este activat, nivelul va permite mai multe produse diferite în subdiviziuni separate</small>
                        </div>
                    </div>

                    <!-- Storage Policy Section -->
                    <div class="settings-section" id="storage-policy-${level.id}">
                        <h5>
                            <span class="material-symbols-outlined">policy</span>
                            Politica de Stocare
                        </h5>
                        <div class="storage-policy-options">
                            <div class="policy-option" onclick="selectStoragePolicy(${level.id}, 'multiple_products', event)">
                                <input type="radio" name="level_${level.id}_storage_policy" value="multiple_products">
                                <div>
                                    <div class="policy-title">Multiple Produse</div>
                                    <div class="policy-description">Permite stocarea mai multor tipuri de produse</div>
                                    <div class="multi-product-search" id="level_${level.id}_multi_products_container" style="display: none;" onclick="event.stopPropagation()">
                                        <div class="product-search-container">
                                            <input type="text" class="form-control product-search-input" placeholder="Caută produs..." autocomplete="off" onkeyup="searchMultiProduct(${level.id}, this.value)" onfocus="showMultiProductResults(${level.id})">
                                            <div class="product-search-results" id="level_${level.id}_multi_product_results"></div>
                                        </div>
                                        <div class="selected-products" id="level_${level.id}_selected_products"></div>
                                        <input type="hidden" id="level_${level.id}_multi_products" name="level_${level.id}_multi_products">
                                    </div>
                                </div>
                            </div>
                            <div class="policy-option" onclick="selectStoragePolicy(${level.id}, 'single_product_type', event)">
                                <input type="radio" name="level_${level.id}_storage_policy" value="single_product_type">
                                <div>
                                    <div class="policy-title">Un Singur Tip</div>
                                    <div class="policy-description">Permite doar un tip de produs pe nivel</div>
                                </div>
                            </div>
                            <div class="policy-option" onclick="selectStoragePolicy(${level.id}, 'category_restricted', event)">
                                <input type="radio" name="level_${level.id}_storage_policy" value="category_restricted">
                                <div>
                                    <div class="policy-title">Restricționat pe Categorie</div>
                                    <div class="policy-description">Permite doar produse din aceeași categorie</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dimensions Section -->
                    <div class="settings-section">
                        <h5>
                            <span class="material-symbols-outlined">straighten</span>
                            Dimensiuni Nivel
                        </h5>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Înălțime nivel (mm)</label>
                                <input type="number" id="level_${level.id}_height" name="level_${level.id}_height" 
                                       class="form-control" value="300" min="100" max="1000">
                            </div>
                            <div class="form-group">
                                <label>Capacitate greutate (kg)</label>
                                <input type="number" id="level_${level.id}_weight" name="level_${level.id}_weight" 
                                       class="form-control" value="50" min="1" max="500" step="0.1">
                            </div>
                            <div class="form-group">
                                <label>Capacitate articole</label>
                                <input type="number" id="level_${level.id}_capacity" name="level_${level.id}_capacity" 
                                       class="form-control" min="1" placeholder="Nr. articole">
                            </div>
                        </div>
                    </div>

                    <!-- Product Restrictions Section -->
                    <div class="settings-section" id="product-section-${level.id}">
                        <h5>
                            <span class="material-symbols-outlined">tune</span>
                            Restricții Produse
                        </h5>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Produs dedicat</label>
                                <div class="product-search-container">
                                    <input type="hidden" id="level_${level.id}_dedicated_product" name="level_${level.id}_dedicated_product">
                                    <input type="text" class="form-control product-search-input" id="level_${level.id}_dedicated_product_search"
                                           placeholder="Caută produs..." autocomplete="off"
                                           onkeyup="searchProductForLevel(${level.id}, this.value)"
                                           onfocus="showLevelProductResults(${level.id})">
                                    <div class="product-search-results" id="level_${level.id}_dedicated_product_results"></div>
                                </div>
                            </div>
                            <div class="form-group form-check-container">
                                <label class="form-check">
                                    <input type="checkbox" id="level_${level.id}_allow_others" 
                                           name="level_${level.id}_allow_others" checked>
                                    Permite alte produse
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Category Restriction Section -->
                    <div class="settings-section" id="category-section-${level.id}" style="display: none;">
                        <h5>
                            <span class="material-symbols-outlined">category</span>
                            Categorie Permisă
                        </h5>
                        <div class="form-group">
                            <label>Selectează categoria</label>
                            <select class="form-control" id="level_${level.id}_category" name="level_${level.id}_category">
                                <option value="">Alege categorie...</option>
                                ${window.allCategories.map(c => `<option value="${c}">${c}</option>`).join('')}
                            </select>
                        </div>
                    </div>


                    <!-- QR Code Section -->
                    <div class="settings-section">
                    <h5>
                    <span class="material-symbols-outlined">qr_code_2</span>
                    Cod QR Nivel
                    </h5>
                    <div class="form-group">
                    <canvas id="level_qr_canvas_${level.id}" width="120" height="120" 
                    style="border: 1px solid #ddd; margin-bottom: 0.5rem; display: block;"></canvas>
                    <button type="button" class="btn btn-secondary btn-sm" 
                    onclick="downloadLevelQR(${level.id})" title="Descarcă QR">
                    <span class="material-symbols-outlined">download</span>
                    Descarcă QR Nivel
                    </button>
                    </div>
                    </div>
                    </div>
                    <!-- Enhanced Subdivisions Management Section -->
                    <div class="settings-section subdivisions-section" id="subdivisions-section-${level.id}" style="display: none;">
                        <h5>
                            <span class="material-symbols-outlined">grid_view</span>
                            Gestiune Subdiviziuni
                        </h5>
                        <div class="subdivisions-list" id="subdivisions-list-${level.id}">
                            <!-- Subdivisions will be added here -->
                        </div>
                        <button type="button" class="btn btn-sm btn-primary" onclick="addSubdivision(${level.id})">
                            <span class="material-symbols-outlined">add</span>
                            Adaugă Subdiviziune
                        </button>
                    </div>
                    </div>
                    </div>
    `;
}

/**
 * Get level position description
 */
function getLevelPosition(levelNumber) {
    if (levelNumber === 1) return "Primul nivel";
    if (levelNumber === 2) return "Al doilea nivel"; 
    if (levelNumber === 3) return "Al treilea nivel";
    return `Al ${levelNumber}-lea nivel`;
}

/**
 * Initialize interactions for a specific level
 */
function initializeLevelInteractions(levelId) {
    console.log('Initializing interactions for level:', levelId);
    
    // Initialize storage policy radio buttons
    const policyRadios = document.querySelectorAll(`input[name="level_${levelId}_storage_policy"]`);
    policyRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            updatePolicyOptions(levelId, this.value);
        });
    });
    

    
    // Initialize QR code for this level
    initializeLevelQRCode(levelId);
}

/**
 * Initialize QR code for a specific level
 */
function initializeLevelQRCode(levelId) {
    const canvas = document.getElementById(`level_qr_canvas_${levelId}`);
    if (!canvas || !window.QRious) return;
    
    try {
        const zoneName = document.getElementById('zone')?.value || '';
        const levelName = document.querySelector(`#level-item-${levelId} .level-name-input`)?.value || `Nivel ${levelId}`;
        const qrValue = zoneName ? `${zoneName}-${levelName}` : levelName;
        
        const levelQR = new QRious({
            element: canvas,
            size: 120,
            value: qrValue,
            foreground: '#000000',
            background: '#ffffff'
        });
        
        // Store QR instance for later updates
        canvas._qrInstance = levelQR;
        
        console.log('QR initialized for level:', levelId);
    } catch (error) {
        console.error('Error initializing level QR:', error);
    }
}

/**
 * Download QR code for a specific level
 */
function downloadLevelQR(levelId) {
    const canvas = document.getElementById(`level_qr_canvas_${levelId}`);
    if (!canvas) return;
    
    const zoneName = document.getElementById('zone')?.value || '';
    const levelName = document.querySelector(`#level-item-${levelId} .level-name-input`)?.value || `Nivel_${levelId}`;
    const fileName = zoneName ? `${zoneName}_${levelName}_QR.png` : `${levelName}_QR.png`;
    
    try {
        const link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        console.log('Downloaded QR for level:', levelId);
    } catch (error) {
        console.error('Error downloading level QR:', error);
    }
}

/**
 * Update level name
 */
function updateLevelName(levelId, newName) {
    const level = createdLevels.find(l => l.id === levelId);
    if (level) {
        level.name = newName || `Nivel ${levelId}`;
        
        // Update QR code with new name and zone
        const canvas = document.getElementById(`level_qr_canvas_${levelId}`);
        if (canvas && canvas._qrInstance) {
            const zoneName = document.getElementById('zone')?.value || '';
            const qrValue = zoneName ? `${zoneName}-${level.name}` : level.name;
            canvas._qrInstance.set({ value: qrValue });
        }
    }
}

/**
 * Toggle level content visibility
 */
function toggleLevel(levelId) {
    const content = document.getElementById(`level-content-${levelId}`);
    const toggleBtn = document.querySelector(`[onclick="toggleLevel(${levelId})"] span`);
    
    if (content.style.display === 'none' || !content.style.display) {
        content.style.display = 'block';
        toggleBtn.textContent = 'expand_less';
        console.log('Expanded level:', levelId);
    } else {
        content.style.display = 'none';
        toggleBtn.textContent = 'expand_more';
        console.log('Collapsed level:', levelId);
    }
}

/**
 * Remove a level
 */
function removeLevel(levelId) {
    if (!confirm('Sigur doriți să ștergeți acest nivel?')) {
        return;
    }
    
    console.log('Removing level:', levelId);
    
    // Remove from DOM
    const levelElement = document.getElementById(`level-item-${levelId}`);
    if (levelElement) {
        levelElement.remove();
    }
    
    // Remove from array
    createdLevels = createdLevels.filter(l => l.id !== levelId);
    currentLevels = createdLevels.length;
    
    // Update summary
    updateLevelsSummary();
    
    console.log('Level removed. Remaining levels:', createdLevels.length);
}

/**
 * Update levels summary
 */
function updateLevelsSummary() {
    const summary = document.getElementById('levels-summary');
    const countSpan = document.getElementById('total-levels-count');
    
    // Safety check - elements might not exist yet
    if (!summary || !countSpan) {
        console.log('⚠️ Summary elements not found, skipping update');
        return;
    }
    
    if (createdLevels.length > 0) {
        summary.style.display = 'block';
        countSpan.textContent = createdLevels.length;
    } else {
        summary.style.display = 'none';
    }
}

/**
 * Update policy options based on selection
 */
function updatePolicyOptions(levelId, policy) {
    const dedicatedProduct = document.getElementById(`level_${levelId}_dedicated_product`);
    const allowOthers = document.getElementById(`level_${levelId}_allow_others`);
    const productSection = document.getElementById(`product-section-${levelId}`);
    const categorySection = document.getElementById(`category-section-${levelId}`);
    const multiContainer = document.getElementById(`level_${levelId}_multi_products_container`);
    const subdivisionsEnabled = document.getElementById(`level_${levelId}_enable_subdivisions`)?.checked;

    if (policy === 'single_product_type') {
        dedicatedProduct.required = true;
        allowOthers.checked = false;
        allowOthers.disabled = true;
        if (productSection) productSection.style.display = 'block';
        if (categorySection) categorySection.style.display = 'none';
        if (multiContainer) multiContainer.style.display = 'none';
    } else if (policy === 'category_restricted') {
        if (dedicatedProduct) {
            dedicatedProduct.required = false;
            dedicatedProduct.value = '';
        }
        const searchInput = document.getElementById(`level_${levelId}_dedicated_product_search`);
        if (searchInput) searchInput.value = '';
        if (allowOthers) {
            allowOthers.checked = true;
            allowOthers.disabled = true;
        }
        if (productSection) productSection.style.display = 'none';
        if (categorySection) categorySection.style.display = 'block';
        if (multiContainer) multiContainer.style.display = 'none';
    } else {
        // multiple_products
        if (dedicatedProduct) {
            dedicatedProduct.required = false;
            dedicatedProduct.value = '';
        }
        if (allowOthers) {
            allowOthers.disabled = false;
        }
        if (subdivisionsEnabled) {
            if (productSection) productSection.style.display = 'none';
            if (multiContainer) multiContainer.style.display = 'none';
        } else {
            if (productSection) productSection.style.display = 'none';
            if (multiContainer) multiContainer.style.display = 'block';
        }
        if (categorySection) categorySection.style.display = 'none';
    }
}

/**
 * Select storage policy for a level
 */
function selectStoragePolicy(levelId, policy, eventOrElement = null) {
    const container = document.querySelector(`#level-content-${levelId} .storage-policy-options`);
    if (!container) {
        console.error(`Storage policy container not found for level ${levelId}`);
        return;
    }
    
    const options = container.querySelectorAll('.policy-option');
    
    // Clear all selected states
    options.forEach(option => option.classList.remove('selected'));
    
    let targetElement = null;
    
    // Determine the target element
    if (eventOrElement && eventOrElement.currentTarget) {
        // Called from event (user click)
        targetElement = eventOrElement.currentTarget;
    } else if (eventOrElement && eventOrElement.nodeType) {
        // Passed an element directly
        targetElement = eventOrElement;
    } else {
        // Called programmatically - find the correct element by policy value
        targetElement = container.querySelector(`.policy-option input[value="${policy}"]`)?.closest('.policy-option');
    }
    
    if (targetElement) {
        targetElement.classList.add('selected');
        
        // Update radio button
        const radio = targetElement.querySelector('input[type="radio"]');
        if (radio) {
            radio.checked = true;
        }
    } else {
        console.warn(`Could not find policy option for policy: ${policy} in level ${levelId}`);
        
        // Fallback: try to find and select the radio button directly
        const radio = container.querySelector(`input[value="${policy}"]`);
        if (radio) {
            radio.checked = true;
            const policyOption = radio.closest('.policy-option');
            if (policyOption) {
                policyOption.classList.add('selected');
            }
        }
    }
    
    // Update policy options
    updatePolicyOptions(levelId, policy);
}

/**
 * Alternative version that completely avoids event handling
 * Call this version when populating from database
 */
function selectStoragePolicyProgrammatic(levelId, policy) {
    const container = document.querySelector(`#level-content-${levelId} .storage-policy-options`);
    if (!container) {
        console.error(`Storage policy container not found for level ${levelId}`);
        return;
    }
    
    const options = container.querySelectorAll('.policy-option');
    
    // Clear all selected states
    options.forEach(option => option.classList.remove('selected'));
    
    // Find and select the correct option
    const targetRadio = container.querySelector(`input[name="level_${levelId}_storage_policy"][value="${policy}"]`);
    if (targetRadio) {
        targetRadio.checked = true;
        const policyOption = targetRadio.closest('.policy-option');
        if (policyOption) {
            policyOption.classList.add('selected');
        }
    }
    
    // Update policy options
    updatePolicyOptions(levelId, policy);
}

// =================== ENHANCED SUBDIVISION FUNCTIONS ===================

/**
 * Toggle subdivisions for a level (ENHANCED system)
 */
function toggleSubdivisions(levelId) {
    console.log('toggleSubdivisions called for level:', levelId, 'Stack:', new Error().stack);
    const enableCheckbox = document.getElementById(`level_${levelId}_enable_subdivisions`);
    const subdivisionSection = document.getElementById(`subdivisions-section-${levelId}`);
    const storagePolicy = document.getElementById(`storage-policy-${levelId}`);
    
    if (enableCheckbox.checked) {
        // Enable subdivisions mode
        subdivisionSection.style.display = 'block';
        
        // Force Multiple Products policy and properly update UI
        const multipleProductsRadio = document.querySelector(`input[name="level_${levelId}_storage_policy"][value="multiple_products"]`);
        if (multipleProductsRadio) {
            multipleProductsRadio.checked = true;
            
            // Clear all selected states first
            const allPolicyOptions = storagePolicy.querySelectorAll('.policy-option');
            allPolicyOptions.forEach(option => option.classList.remove('selected'));
            
            // Select the multiple products option
            const multipleProductsOption = multipleProductsRadio.closest('.policy-option');
            if (multipleProductsOption) {
                multipleProductsOption.classList.add('selected');
            }
        }
        
        // Disable other policy options
        const policyOptions = storagePolicy.querySelectorAll('.policy-option');
        policyOptions.forEach((option, index) => {
            if (index === 0) { // Multiple products option
                option.style.opacity = '1';
                option.style.pointerEvents = 'auto';
            } else {
                option.classList.remove('selected');
                option.style.opacity = '0.5';
                option.style.pointerEvents = 'none';
            }
        });
        
        // Add policy note
        if (!document.getElementById(`subdivision-policy-note-${levelId}`)) {
            const note = document.createElement('div');
            note.id = `subdivision-policy-note-${levelId}`;
            note.className = 'form-help';
            note.style.color = 'var(--success-color)';
            note.innerHTML = '<span class="material-symbols-outlined">info</span> Politica "Multiple Produse" este activată automat pentru subdiviziuni';
            storagePolicy.appendChild(note);
        }
        
        // Initialize with one subdivision if none exist
        const subdivisionsList = document.getElementById(`subdivisions-list-${levelId}`);
        if (subdivisionsList.children.length === 0) {
            addSubdivision(levelId);
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
        const note = document.getElementById(`subdivision-policy-note-${levelId}`);
        if (note) {
            note.remove();
        }
        
        // Clear all subdivisions
        clearSubdivisions(levelId);
    }

    const currentPolicy = document.querySelector(`input[name="level_${levelId}_storage_policy"]:checked`)?.value || 'multiple_products';
    updatePolicyOptions(levelId, currentPolicy);
}

/**
 * Add a subdivision to a level
 */
function addSubdivision(levelId) {
    const subdivisionsList = document.getElementById(`subdivisions-list-${levelId}`);
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
            <button type="button" class="btn btn-sm btn-danger" onclick="removeSubdivision(${levelId}, ${subdivisionIndex})">
                <span class="material-symbols-outlined">delete</span>
            </button>
        </div>
        <div class="subdivision-content">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Produs *</label>
                    <div class="product-search-container">
                        <input type="hidden" 
                               name="level_${levelId}_subdivision_${subdivisionIndex}_product_id" 
                               id="level_${levelId}_subdivision_${subdivisionIndex}_product_id">
                        <input type="text" 
                               class="form-control product-search-input" 
                               id="level_${levelId}_subdivision_${subdivisionIndex}_product_search"
                               placeholder="Caută produs..." 
                               autocomplete="off"
                               onkeyup="searchProductsForSubdivision(${levelId}, ${subdivisionIndex}, this.value)"
                               onfocus="showProductResults(${levelId}, ${subdivisionIndex})">
                        <div class="product-search-results" 
                             id="level_${levelId}_subdivision_${subdivisionIndex}_results"
                             style="display: none;"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Capacitate articole *</label>
                    <input type="number" 
                           name="level_${levelId}_subdivision_${subdivisionIndex}_capacity" 
                           id="level_${levelId}_subdivision_${subdivisionIndex}_capacity"
                           class="form-control" 
                           min="1" 
                           placeholder="Nr. articole"
                           required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notițe</label>
                <textarea name="level_${levelId}_subdivision_${subdivisionIndex}_notes" 
                          class="form-control" 
                          rows="2" 
                          placeholder="Notițe opționale pentru această subdiviziune"></textarea>
            </div>
        </div>
    `;
    
    subdivisionsList.appendChild(subdivisionDiv);
    
    // Focus on product search
    setTimeout(() => {
        const searchInput = document.getElementById(`level_${levelId}_subdivision_${subdivisionIndex}_product_search`);
        if (searchInput) {
            searchInput.focus();
        }
    }, 100);
}

/**
 * Remove a subdivision
 */
function removeSubdivision(levelId, subdivisionIndex) {
    if (!confirm('Ești sigur că vrei să ștergi această subdiviziune?')) {
        return;
    }
    
    const subdivisionDiv = document.querySelector(`#subdivisions-list-${levelId} [data-subdivision="${subdivisionIndex}"]`);
    if (subdivisionDiv) {
        subdivisionDiv.remove();
    }
    
    // Renumber remaining subdivisions
    renumberSubdivisions(levelId);
}

/**
 * Clear all subdivisions
 */
function clearSubdivisions(levelId) {
    const subdivisionsList = document.getElementById(`subdivisions-list-${levelId}`);
    if (subdivisionsList) {
        subdivisionsList.innerHTML = '';
    }
}

/**
 * Renumber subdivisions after removal
 */
function renumberSubdivisions(levelId) {
    const subdivisionsList = document.getElementById(`subdivisions-list-${levelId}`);
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
            deleteBtn.setAttribute('onclick', `removeSubdivision(${levelId}, ${newIndex})`);
        }
        
        // Update search function calls
        const searchInput = subdivision.querySelector('.product-search-input');
        if (searchInput) {
            searchInput.setAttribute('onkeyup', `searchProductsForSubdivision(${levelId}, ${newIndex}, this.value)`);
            searchInput.setAttribute('onfocus', `showProductResults(${levelId}, ${newIndex})`);
        }
    });
}

// =================== PRODUCT SEARCH FOR SUBDIVISIONS ===================

let searchTimeouts = {};
let productSearchCache = {};

/**
 * Search products for subdivision
 */
function searchProductsForSubdivision(levelId, subdivisionIndex, query) {
    const timeoutKey = `${levelId}_${subdivisionIndex}`;
    
    // Clear previous timeout
    if (searchTimeouts[timeoutKey]) {
        clearTimeout(searchTimeouts[timeoutKey]);
    }
    
    // Set new timeout to avoid too many requests
    searchTimeouts[timeoutKey] = setTimeout(async () => {
        if (query.length < 2) {
            hideProductResults(levelId, subdivisionIndex);
            return;
        }
        
        try {
            // Check cache first
            if (productSearchCache[query]) {
                displayProductResults(levelId, subdivisionIndex, productSearchCache[query]);
                return;
            }
            
            const response = await fetch(`api/products.php?search=${encodeURIComponent(query)}&limit=10`);
            const data = await response.json();
            const products = Array.isArray(data) ? data : data.data;

            if (response.ok && Array.isArray(products)) {
                productSearchCache[query] = products;
                displayProductResults(levelId, subdivisionIndex, products);
            }
        } catch (error) {
            console.error('Product search error:', error);
        }
    }, 300);
}

/**
 * Display product search results
 */
function displayProductResults(levelId, subdivisionIndex, products) {
    const resultsContainer = document.getElementById(`level_${levelId}_subdivision_${subdivisionIndex}_results`);
    if (!resultsContainer) return;
    
    if (products.length === 0) {
        resultsContainer.innerHTML = '<div class="search-result-item no-results">Nu s-au găsit produse</div>';
    } else {
        resultsContainer.innerHTML = products.map(product => `
            <div class="search-result-item" onclick="selectProduct(${levelId}, ${subdivisionIndex}, ${product.id}, '${escapeHtml(product.name)}')">
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
function selectProduct(levelId, subdivisionIndex, productId, productName) {
    const hiddenInput = document.getElementById(`level_${levelId}_subdivision_${subdivisionIndex}_product_id`);
    const searchInput = document.getElementById(`level_${levelId}_subdivision_${subdivisionIndex}_product_search`);
    
    if (hiddenInput) hiddenInput.value = productId;
    if (searchInput) searchInput.value = productName;
    
    hideProductResults(levelId, subdivisionIndex);
}

/**
 * Show product results
 */
function showProductResults(levelId, subdivisionIndex) {
    const resultsContainer = document.getElementById(`level_${levelId}_subdivision_${subdivisionIndex}_results`);
    const searchInput = document.getElementById(`level_${levelId}_subdivision_${subdivisionIndex}_product_search`);
    
    if (resultsContainer && searchInput && searchInput.value.length >= 2) {
        resultsContainer.style.display = 'block';
    }
}

/**
 * Hide product results
 */
function hideProductResults(levelId, subdivisionIndex) {
    const resultsContainer = document.getElementById(`level_${levelId}_subdivision_${subdivisionIndex}_results`);
    if (resultsContainer) {
        resultsContainer.style.display = 'none';
    }
}

// =================== PRODUCT SEARCH FOR DEDICATED LEVEL PRODUCT ===================

let levelProductTimeouts = {};

function searchProductForLevel(levelId, query) {
    const timeoutKey = `level_${levelId}`;

    if (levelProductTimeouts[timeoutKey]) {
        clearTimeout(levelProductTimeouts[timeoutKey]);
    }

    levelProductTimeouts[timeoutKey] = setTimeout(async () => {
        if (query.length < 2) {
            hideLevelProductResults(levelId);
            return;
        }

        try {
            if (productSearchCache[query]) {
                displayLevelProductResults(levelId, productSearchCache[query]);
                return;
            }

            const response = await fetch(`api/products.php?search=${encodeURIComponent(query)}&limit=10`);
            const data = await response.json();
            const products = Array.isArray(data) ? data : data.data;

            if (response.ok && Array.isArray(products)) {
                productSearchCache[query] = products;
                displayLevelProductResults(levelId, products);
            }
        } catch (error) {
            console.error('Product search error:', error);
        }
    }, 300);
}

function displayLevelProductResults(levelId, products) {
    const container = document.getElementById(`level_${levelId}_dedicated_product_results`);
    if (!container) return;

    if (products.length === 0) {
        container.innerHTML = '<div class="search-result-item no-results">Nu s-au găsit produse</div>';
    } else {
        container.innerHTML = products.map(p => `
            <div class="search-result-item" onclick="selectLevelProduct(${levelId}, ${p.id}, '${escapeHtml(p.name)}')">
                <div class="product-name">${escapeHtml(p.name)}</div>
                <div class="product-details">${escapeHtml(p.code)} - ${escapeHtml(p.category)}</div>
            </div>
        `).join('');
    }

    container.style.display = 'block';
}

function selectLevelProduct(levelId, productId, productName) {
    const hidden = document.getElementById(`level_${levelId}_dedicated_product`);
    const search = document.getElementById(`level_${levelId}_dedicated_product_search`);

    if (hidden) hidden.value = productId;
    if (search) search.value = productName;

    hideLevelProductResults(levelId);
}

function showLevelProductResults(levelId) {
    const container = document.getElementById(`level_${levelId}_dedicated_product_results`);
    const input = document.getElementById(`level_${levelId}_dedicated_product_search`);

    if (container && input && input.value.length >= 2) {
        container.style.display = 'block';
    }
}

function hideLevelProductResults(levelId) {
    const container = document.getElementById(`level_${levelId}_dedicated_product_results`);
    if (container) container.style.display = 'none';
}

// ===== Multiple Products Selection =====
function searchMultiProduct(levelId, query) {
    const timeoutKey = `multi_${levelId}`;

    if (multiProductTimeouts[timeoutKey]) {
        clearTimeout(multiProductTimeouts[timeoutKey]);
    }

    multiProductTimeouts[timeoutKey] = setTimeout(async () => {
        if (query.length < 2) {
            hideMultiProductResults(levelId);
            return;
        }

        try {
            if (productSearchCache[query]) {
                displayMultiProductResults(levelId, productSearchCache[query]);
                return;
            }

            const response = await fetch(`api/products.php?search=${encodeURIComponent(query)}&limit=10`);
            const data = await response.json();
            const products = Array.isArray(data) ? data : data.data;

            if (response.ok && Array.isArray(products)) {
                productSearchCache[query] = products;
                displayMultiProductResults(levelId, products);
            }
        } catch (error) {
            console.error('Product search error:', error);
        }
    }, 300);
}

function displayMultiProductResults(levelId, products) {
    const container = document.getElementById(`level_${levelId}_multi_product_results`);
    if (!container) return;

    if (products.length === 0) {
        container.innerHTML = '<div class="search-result-item no-results">Nu s-au găsit produse</div>';
    } else {
        container.innerHTML = products.map(p => `
            <div class="search-result-item" onclick="addMultiProduct(${levelId}, ${p.id}, '${escapeHtml(p.name)}'); event.stopPropagation();">
                <div class="product-name">${escapeHtml(p.name)}</div>
                <div class="product-details">${escapeHtml(p.code)} - ${escapeHtml(p.category)}</div>
            </div>
        `).join('');
    }

    container.style.display = 'block';
}

function showMultiProductResults(levelId) {
    const container = document.getElementById(`level_${levelId}_multi_product_results`);
    const input = document.querySelector(`#level_${levelId}_multi_products_container .product-search-input`);
    if (container && input && input.value.length >= 2) {
        container.style.display = 'block';
    }
}

function hideMultiProductResults(levelId) {
    const container = document.getElementById(`level_${levelId}_multi_product_results`);
    if (container) container.style.display = 'none';
}

function addMultiProduct(levelId, productId, productName) {
    if (!levelMultiProducts[levelId]) levelMultiProducts[levelId] = [];
    const exists = levelMultiProducts[levelId].some(p => p.id === productId);
    if (exists) {
        hideMultiProductResults(levelId);
        return;
    }
    levelMultiProducts[levelId].push({ id: productId, name: productName });
    renderSelectedProducts(levelId);
    hideMultiProductResults(levelId);
}

function removeMultiProduct(levelId, productId) {
    if (!levelMultiProducts[levelId]) return;
    levelMultiProducts[levelId] = levelMultiProducts[levelId].filter(p => p.id !== productId);
    renderSelectedProducts(levelId);
}

function renderSelectedProducts(levelId) {
    const container = document.getElementById(`level_${levelId}_selected_products`);
    const hidden = document.getElementById(`level_${levelId}_multi_products`);
    if (!container || !hidden) return;

    const items = levelMultiProducts[levelId] || [];
    container.innerHTML = items.map(p => `
        <span class="selected-product-item">${escapeHtml(p.name)} <button type="button" onclick="removeMultiProduct(${levelId}, ${p.id}); event.stopPropagation();">&times;</button></span>
    `).join('');
    hidden.value = JSON.stringify(items.map(p => p.id));
}

// =================== MODAL FUNCTIONS ===================

/**
 * Open create modal with proper initialization
 */
function openCreateModal() {
    console.log('🚀 Opening create modal...');
    
    try {
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
        
        // CRITICAL: Initialize the dynamic system if not already done
        if (!dynamicSystemInitialized) {
            console.log('🔧 Initializing dynamic system for create modal...');
            initializeDynamicLevelSystem();
        }
        
        // Clear all existing levels
        levelCounter = 0;
        createdLevels = [];
        currentLevels = 0;
        levelMultiProducts = {};
        const container = document.getElementById('levels-container');
        if (container) {
            container.innerHTML = '';
            console.log('✅ Cleared existing levels');
        } else {
            console.log('⚠️ levels-container not found during create modal open');
        }
        updateLevelsSummary();

        initializeQRCode();
        setupEventListeners();
        // Update QR code
        setTimeout(() => {
            updateLocationQr();
        }, 100);
        
        // Switch to basic tab
        switchLocationTab('basic');
        
        // Show modal
        const modal = document.getElementById('locationModal');
        if (modal) {
            modal.classList.add('show');
            console.log('✅ Modal shown successfully');
        } else {
            console.error('❌ Modal element not found');
        }
        
        // Focus on location code input
        setTimeout(() => {
            const locationCodeInput = document.getElementById('location_code');
            if (locationCodeInput) {
                locationCodeInput.focus();
                console.log('✅ Focused on location code input');
            }
        }, 100);
        
        console.log('✅ Create modal opened successfully');
        
    } catch (error) {
        console.error('❌ Error opening create modal:', error);
    }
}

/**
 * Open edit modal with proper initialization
 */
function openEditModal(location) {
    console.log('🔧 Opening edit modal for location:', location.id);
    
    document.getElementById('modalTitle').textContent = 'Editează Locație';
    document.getElementById('formAction').value = 'update';
    document.getElementById('locationId').value = location.id;
    document.getElementById('submitBtn').textContent = 'Actualizează';
    
    // CRITICAL: Initialize the dynamic system FIRST before populating
    if (!dynamicSystemInitialized) {
        console.log('🔧 Initializing dynamic system for edit modal...');
        initializeDynamicLevelSystem();
    }
    
    // Clear existing levels first
    levelCounter = 0;
    createdLevels = [];
    currentLevels = 0;
    levelMultiProducts = {};
    const container = document.getElementById('levels-container');
    if (container) {
        container.innerHTML = '';
        console.log('✅ Cleared existing levels for edit');
    }
    
    initializeQRCode();
    setupEventListeners();
    // Populate form with null checks
    const locationCodeInput = document.getElementById('location_code');
    if (locationCodeInput) locationCodeInput.value = location.location_code || '';
    
    const zoneInput = document.getElementById('zone');
    if (zoneInput) zoneInput.value = location.zone || '';
    
    const typeInput = document.getElementById('type');
    if (typeInput) typeInput.value = location.type || 'Shelf';
    
    // Populate global dimensions
    const globalLengthInput = document.getElementById('global_length_mm');
    if (globalLengthInput) globalLengthInput.value = location.length_mm || 1000;
    
    const globalDepthInput = document.getElementById('global_depth_mm');
    if (globalDepthInput) globalDepthInput.value = location.depth_mm || 400;
    
    const globalWeightInput = document.getElementById('global_max_weight_kg');
    if (globalWeightInput) globalWeightInput.value = location.max_weight_kg || 500;
    
    // Convert database status to form value
    const statusInput = document.getElementById('status');
    if (statusInput) {
        const statusValue = location.status === 'active' ? '1' : '0';
        statusInput.value = statusValue;
    }
    
    const descriptionInput = document.getElementById('description');
    if (descriptionInput) descriptionInput.value = location.notes || '';
    
    // NOW populate dynamic levels after the system is initialized
    if (location.level_settings) {
        let levelData = location.level_settings;

        // Support objects keyed by level number by converting to an array
        if (!Array.isArray(levelData)) {
            try {
                levelData = Object.values(levelData);
            } catch (e) {
                console.error('❌ Failed to normalize level settings', e);
                levelData = [];
            }
        }

        if (Array.isArray(levelData) && levelData.length > 0) {
            console.log('🔧 Populating levels after system init...');
            setTimeout(() => {
                populateDynamicLevels(levelData);
            }, 100);
        }
    }
    
    // Update QR code
    setTimeout(() => {
        updateLocationQr();
    }, 200);
    
    // Switch to basic tab
    switchLocationTab('basic');
    
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

/**
 * Fetch product name by ID using the updated API
 */
async function fetchProductName(productId) {
    if (!productId) return null;
    
    try {
        // Use the modified products API with the ID parameter
        const response = await fetch(`api/products.php?id=${productId}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        // The API returns an array, check if we have results
        if (Array.isArray(data) && data.length > 0) {
            return data[0].name;
        }
        
    } catch (error) {
        console.error('Error fetching product name:', error);
    }
    
    // Fallback display
    return `Product ID: ${productId}`;
}

/**
 * Set dedicated product for a level (both hidden ID and visible name)
 */
async function setLevelDedicatedProduct(levelId, productId) {
    const hiddenInput = document.getElementById(`level_${levelId}_dedicated_product`);
    const searchInput = document.getElementById(`level_${levelId}_dedicated_product_search`);
    
    if (hiddenInput) {
        hiddenInput.value = productId || '';
    }
    
    if (searchInput) {
        if (productId) {
            // Fetch and display the product name
            const productName = await fetchProductName(productId);
            searchInput.value = productName || `Product ID: ${productId}`;
        } else {
            searchInput.value = '';
        }
    }
}

/**
 * Populate dynamic levels from loaded data
 */
async function populateDynamicLevels(levelSettings) {
    console.log('Populating dynamic levels:', levelSettings);
    
    // Safety check - make sure container exists before adding levels
    const container = document.getElementById('levels-container');
    if (!container) {
        console.error('❌ Cannot populate levels - levels-container not found!');
        return;
    }
    
    // Process each level setting
    for (const [index, setting] of levelSettings.entries()) {
        // Add new level
        console.log(`Adding level ${index + 1} from settings...`);
        addNewLevel();
        const levelId = levelCounter;

        // Populate level data
        const nameInput = document.querySelector(`#level-item-${levelId} .level-name-input`);
        if (nameInput) nameInput.value = setting.level_name || `Nivel ${levelId}`;

        // FIX: Use the programmatic version to avoid event error
        if (setting.storage_policy) {
            selectStoragePolicyProgrammatic(levelId, setting.storage_policy);
        }

        const heightInput = document.getElementById(`level_${levelId}_height`);
        if (heightInput) heightInput.value = setting.height_mm || 300;

        const weightInput = document.getElementById(`level_${levelId}_weight`);
        if (weightInput) weightInput.value = setting.max_weight_kg || 50;

        const capacityInput = document.getElementById(`level_${levelId}_capacity`);
        if (capacityInput) capacityInput.value = setting.items_capacity ?? '';

        // FIX: Set dedicated product with both ID and name
        if (setting.dedicated_product_id) {
            await setLevelDedicatedProduct(levelId, setting.dedicated_product_id);
        }

        if (setting.allowed_product_types && Array.isArray(setting.allowed_product_types)) {
            if (setting.storage_policy === 'category_restricted') {
                const catSelect = document.getElementById(`level_${levelId}_category`);
                if (catSelect) {
                    catSelect.value = setting.allowed_product_types[0] || '';
                }
            } else if (setting.storage_policy === 'multiple_products' && !setting.subdivisions_enabled) {
                for (const pid of setting.allowed_product_types) {
                    const name = await fetchProductName(pid);
                    addMultiProduct(levelId, pid, name || `Produs ${pid}`);
                }
            }
        }

        const allowOthersInput = document.getElementById(`level_${levelId}_allow_others`);
        if (allowOthersInput) allowOthersInput.checked = !!Number(setting.allow_other_products);

        // === Populate subdivision data if available ===
        if (setting.subdivisions_enabled) {
            const enableCheckbox = document.getElementById(`level_${levelId}_enable_subdivisions`);
            if (enableCheckbox) {
                enableCheckbox.checked = true;
                toggleSubdivisions(levelId);
                clearSubdivisions(levelId);
            }

            if (Array.isArray(setting.subdivisions)) {
                for (const [subIdx, sub] of setting.subdivisions.entries()) {
                    addSubdivision(levelId);
                    const index = subIdx + 1;

                    if (sub.dedicated_product_id) {
                        selectProduct(levelId, index, sub.dedicated_product_id, sub.product_name || `Product ID: ${sub.dedicated_product_id}`);
                    }
                    const capInput = document.getElementById(`level_${levelId}_subdivision_${index}_capacity`);
                    if (capInput) capInput.value = sub.items_capacity ?? '';
                    const notesInput = document.querySelector(`[name="level_${levelId}_subdivision_${index}_notes"]`);
                    if (notesInput) notesInput.value = sub.notes || '';
                }
            }
        }

        // Initialize level QR code
        setTimeout(() => {
            initializeLevelQRCode(levelId);
        }, 100);
    }

    updateLevelsSummary();
    console.log('✅ Dynamic levels populated successfully');
}

function closeModal() {
    document.getElementById('locationModal').classList.remove('show');
    
    // Clear any existing auto-fill messages
    const existingMessage = document.querySelector('.zone-autofill-message'); 
    if (existingMessage) {
        existingMessage.remove();
    }
}

// =================== FORM SUBMISSION AND DATA COLLECTION ===================

/**
 * Collect all level data for form submission
 */
function collectLevelData() {
    const levelData = {};
    
    createdLevels.forEach(level => {
        const levelId = level.id;
        
        levelData[levelId] = {
            name: level.name,
            storage_policy: document.querySelector(`input[name="level_${levelId}_storage_policy"]:checked`)?.value || 'multiple_products',
            height_mm: parseInt(document.getElementById(`level_${levelId}_height`)?.value) || 300,
            max_weight_kg: parseFloat(document.getElementById(`level_${levelId}_weight`)?.value) || 50,
            items_capacity: parseInt(document.getElementById(`level_${levelId}_capacity`)?.value) || null,
            dedicated_product_id: document.getElementById(`level_${levelId}_dedicated_product`)?.value || null,
            allow_other_products: document.getElementById(`level_${levelId}_allow_others`)?.checked ?? true,
            allowed_product_types: (function() {
                const policy = document.querySelector(`input[name="level_${levelId}_storage_policy"]:checked`)?.value;
                const subdivisionsEnabled = document.getElementById(`level_${levelId}_enable_subdivisions`)?.checked;
                if (policy === 'category_restricted') {
                    const cat = document.getElementById(`level_${levelId}_category`)?.value;
                    return cat ? [cat] : null;
                } else if (policy === 'multiple_products' && !subdivisionsEnabled) {
                    const val = document.getElementById(`level_${levelId}_multi_products`)?.value;
                    if (val) {
                        try { return JSON.parse(val); } catch (e) { return null; }
                    }
                }
                return null;
            })()
        };
    });
    
    return levelData;
}

/**
 * Collect subdivision data for form submission
 */
function collectSubdivisionData() {
    const subdivisionData = {};

    createdLevels.forEach(level => {
        const levelId = level.id;
        const enableCheckbox = document.getElementById(`level_${levelId}_enable_subdivisions`);

        if (enableCheckbox && enableCheckbox.checked) {
            const subdivisionsList = document.getElementById(`subdivisions-list-${levelId}`);
            if (!subdivisionsList) {
                console.warn(`Subdivisions list not found for level ${levelId}`);
                return;
            }

            const subdivisions = subdivisionsList.querySelectorAll('.subdivision-item');

            subdivisionData[levelId] = {
                enabled: true,
                subdivisions: []
            };

            subdivisions.forEach((subdivision, index) => {
                const subdivisionIndex = index + 1;
                const productId = document.getElementById(`level_${levelId}_subdivision_${subdivisionIndex}_product_id`)?.value;
                const capacity = document.getElementById(`level_${levelId}_subdivision_${subdivisionIndex}_capacity`)?.value;
                const notes = document.querySelector(`[name="level_${levelId}_subdivision_${subdivisionIndex}_notes"]`)?.value;

                if (productId && capacity) {
                    subdivisionData[levelId].subdivisions.push({
                        product_id: productId,
                        capacity: parseInt(capacity),
                        notes: notes || ''
                    });
                }
            });
        }
    });

    return subdivisionData;
}


/**
 * Enhanced form submission with level data
 */
// Add this enhanced version of enhanceFormSubmission to your locations.js file
// This includes extensive debugging to identify the issue

function enhanceFormSubmission() {
    const form = document.getElementById('locationForm');
    if (!form) {
        console.error('❌ locationForm not found');
        return;
    }

    form.addEventListener('submit', function(event) {
        event.preventDefault();
        console.log('Form submission started.');

        // Check if all required data is present
        const levelData = collectLevelData();
        console.log('Created levels:', Object.keys(levelData).length);
        console.log('Level data collected:', levelData);
        
        // Add to form as hidden field
        let hiddenField = document.getElementById('dynamic_levels_data');
        if (!hiddenField) {
            hiddenField = document.createElement('input');
            hiddenField.type = 'hidden';
            hiddenField.id = 'dynamic_levels_data';
            hiddenField.name = 'dynamic_levels_data';
            form.appendChild(hiddenField);
        }
        hiddenField.value = JSON.stringify(levelData);
        
        // Collect subdivision data
        const subdivisionData = collectSubdivisionData();
        const existingSubdivisionField = document.getElementById('subdivision_form_data');
        if (Object.keys(subdivisionData).length > 0) {
            let subdivisionField = existingSubdivisionField;
            if (!subdivisionField) {
                subdivisionField = document.createElement('input');
                subdivisionField.type = 'hidden';
                subdivisionField.id = 'subdivision_form_data';
                subdivisionField.name = 'subdivision_form_data';
                form.appendChild(subdivisionField);
            }
            subdivisionField.value = JSON.stringify(subdivisionData);
            console.log('Subdivision data collected:', subdivisionData);
        } else if (existingSubdivisionField) {
            // Remove stale subdivision data to avoid unintended creation
            existingSubdivisionField.remove();
        }
        
        console.log('Form submission data prepared');

        // DEBUG: Check if action field exists and has value
        const actionField = document.getElementById('formAction');
        if (!actionField) {
            console.error('❌ formAction field not found!');
            alert('Form action field missing. Please refresh the page and try again.');
            return;
        }
        console.log('🔍 Action field value:', actionField.value);

        // DEBUG: Check if location ID exists for updates
        const locationIdField = document.getElementById('locationId');
        if (locationIdField) {
            console.log('🔍 Location ID:', locationIdField.value);
        }

        // DEBUG: Log all form fields before submission
        const formData = new FormData(form);
        console.log('🔍 Form data contents:');
        for (let [key, value] of formData.entries()) {
            console.log(`  ${key}: ${value}`);
        }

        // Add AJAX indicator
        formData.append('ajax', '1');

        console.log('🚀 Starting fetch request to locations.php...');

        fetch('locations.php', {
            method: 'POST',
            headers: { 
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
        .then(response => {
            console.log('✅ Response received:', response.status, response.statusText);
            
            // Check if response is OK
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            // Check content type
            const contentType = response.headers.get('content-type');
            console.log('🔍 Content-Type:', contentType);
            
            if (!contentType || !contentType.includes('application/json')) {
                console.warn('⚠️ Response is not JSON. Getting text instead...');
                return response.text().then(text => {
                    console.log('📄 Response text:', text);
                    throw new Error('Server returned non-JSON response: ' + text.substring(0, 200));
                });
            }
            
            return response.json();
        })
        .then(data => {
            console.log('✅ JSON data received:', data);
            
            if (data.success) {
                console.log('🎉 Success! Reloading page...');
                window.location.reload();
            } else {
                console.error('❌ Server returned error:', data.message);
                alert(data.message || 'Eroare la salvarea locației');
            }
        })
        .catch(err => {
            console.error('❌ AJAX submit failed:', err);
            console.error('Full error object:', err);
            alert('Eroare la salvarea locației: ' + err.message);
        });
    });
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
    const zoneInput = document.getElementById('zone');
    const code = codeInput ? codeInput.value.trim() : '';
    const zoneName = zoneInput ? zoneInput.value.trim() : '';
    
    try {
        if (code && code.length > 0) {
            qr.set({ value: code });
            
            // Also update all level QR codes
            createdLevels.forEach(level => {
                const canvas = document.getElementById(`level_qr_canvas_${level.id}`);
                if (canvas && canvas._qrInstance) {
                    const qrValue = zoneName ? `${zoneName}-${level.name}` : level.name;
                    canvas._qrInstance.set({ value: qrValue });
                }
            });
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

function printLocationQr(labelOrCode = null, buttonElement = null) {
    const codeInput = document.getElementById('location_code');
    let code = '';

    if (typeof labelOrCode === 'string' && labelOrCode.trim()) {
        code = labelOrCode.trim();
    } else if (buttonElement && buttonElement.dataset.locationCode) {
        code = buttonElement.dataset.locationCode.trim();
    } else if (codeInput) {
        code = codeInput.value.trim();
    }

    // Ensure we have a proper location name - avoid any potential WMS_API_KEY issues
    let locationName = '';
    if (buttonElement && buttonElement.dataset.locationName) {
        locationName = buttonElement.dataset.locationName.trim();
    } else if (typeof labelOrCode === 'string' && labelOrCode.trim() && labelOrCode.trim() !== code) {
        locationName = labelOrCode.trim();
    } else if (code) {
        locationName = code;
    }

    if (!code) {
        alert('Cod locație invalid');
        return;
    }

    // Debug logging
    console.log('Print QR Debug:', {
        code,
        locationName,
        labelOrCode,
        codeInput: codeInput ? codeInput.value : 'null'
    });

    // Show loading state using the passed button element
    const targetButton = buttonElement || document.getElementById('printQrBtn');
    const originalText = targetButton?.textContent;
    if (targetButton) {
        targetButton.textContent = 'Se printează...';
        targetButton.disabled = true;
    }

    fetch('api/locations/print_qr.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'include',
        body: new URLSearchParams({
            location_code: code,
            location_name: locationName,
            printer_id: 4 // GODEX G500
        })
    })
    .then(resp => resp.json())
    .then(data => {
        console.log('Print response:', data);
        if (data.success) {
            alert('QR trimis la imprimantă cu succes!');
        } else {
            alert(data.error || 'Eroare la printarea QR-ului');
        }
    })
    .catch(err => {
        console.error('QR print failed', err);
        alert('Eroare la printarea QR-ului');
    })
    .finally(() => {
        // Restore button state
        if (targetButton && originalText) {
            targetButton.textContent = originalText;
            targetButton.disabled = false;
        }
    });
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

    if (zoneInput) {
        zoneInput.addEventListener('input', function() {
            const zoneName = this.value.trim();
            createdLevels.forEach(level => {
                const canvas = document.getElementById(`level_qr_canvas_${level.id}`);
                if (canvas && canvas._qrInstance) {
                    const qrValue = zoneName ? `${zoneName}-${level.name}` : level.name;
                    canvas._qrInstance.set({ value: qrValue });
                }
            });
        });
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

// =================== WAREHOUSE VISUALIZATION ===================

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
        this.tooltipCache = {};
        this.hideTooltipTimeout = null;
        this.isScrolling = false;
        this.scrollTimeout = null;
        this.currentShelfElement = null;
        
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
                temporaryCount: parseInt(zone.temporary_count) || 0,
                locationCount: parseInt(zone.location_count) || ((parseInt(zone.shelf_count) || 0) + (parseInt(zone.temporary_count) || 0)),
                avgOccupancy: parseFloat(zone.avg_occupancy) || 0,
                totalCapacity: parseInt(zone.total_capacity) || 0,
                totalItems: parseInt(zone.total_items) || 0,
                // include shelves and temporary locations
                shelves: this.locations.filter(l => l.zone === zone.zone_name && ['shelf','temporary'].includes(l.type.toLowerCase()))
            }));
        }
        
        // Fallback: extract from locations data
        const zoneMap = new Map();
        
        this.locations.forEach(location => {
            const zoneName = location.zone;
            if (zoneName && ['shelf','temporary'].includes(location.type.toLowerCase())) {
                if (!zoneMap.has(zoneName)) {
                    zoneMap.set(zoneName, {
                        name: zoneName,
                        shelves: [],
                        totalOccupancy: 0,
                        locationCount: 0,
                        shelfCount: 0,
                        temporaryCount: 0
                    });
                }

                const zone = zoneMap.get(zoneName);
                zone.shelves.push(location);
                zone.locationCount++;
                if (location.type.toLowerCase() === 'temporary') {
                    zone.temporaryCount++;
                } else {
                    zone.shelfCount++;
                }
                zone.totalOccupancy += (location.occupancy?.total || 0);
            }
        });
        
        // Calculate average occupancy for each zone
        const zones = Array.from(zoneMap.values()).map(zone => ({
            ...zone,
            avgOccupancy: zone.locationCount > 0 ? zone.totalOccupancy / zone.locationCount : 0
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
                    this.showEnhancedTooltip(shelfElement, shelf);
                }
            } else if (e.target.closest('#enhancedTooltip')) {
                clearTimeout(this.hideTooltipTimeout);
            }
        });

        document.addEventListener('mouseout', (e) => {
            if (this.isScrolling) return;
            const toElement = e.relatedTarget;
            if (e.target.closest('.shelf-item')) {
                if (!toElement || (!toElement.closest('.shelf-item') && !toElement.closest('#enhancedTooltip'))) {
                    clearTimeout(this.hideTooltipTimeout);
                    this.hideTooltipTimeout = setTimeout(() => this.hideTooltip(), 200);
                }
            } else if (e.target.closest('#enhancedTooltip')) {
                if (!toElement || !toElement.closest('#enhancedTooltip')) {
                    clearTimeout(this.hideTooltipTimeout);
                    this.hideTooltipTimeout = setTimeout(() => this.hideTooltip(), 200);
                }
            }
        });

        window.addEventListener('scroll', () => {
            if (this.tooltip && this.tooltip.style.opacity === '1') {
                this.updateTooltipPosition();
            }
            this.isScrolling = true;
            clearTimeout(this.hideTooltipTimeout);
            clearTimeout(this.scrollTimeout);
            this.scrollTimeout = setTimeout(() => {
                this.isScrolling = false;
            }, 100);
        }, true);

        window.addEventListener('wheel', () => {
            this.isScrolling = true;
            clearTimeout(this.scrollTimeout);
            this.scrollTimeout = setTimeout(() => {
                this.isScrolling = false;
            }, 100);
        }, { passive: true });

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
        
        zonesGrid.innerHTML = this.zones.map(zone => {
            const count = typeof zone.locationCount !== 'undefined' ? zone.locationCount : (zone.shelfCount || 0);
            const hasShelves = (zone.shelfCount || 0) > 0;
            const hasTemps = (zone.temporaryCount || 0) > 0;
            const label = hasShelves && !hasTemps ? 'rafturi' : 'locații';
            return `
            <div class="storage-zone" data-zone="${zone.name}">
                <span class="material-symbols-outlined zone-icon">shelves</span>
                <div class="zone-label">Zona ${zone.name}</div>
                <div class="zone-stats">${count} ${label} • ${Math.round(zone.avgOccupancy || 0)}% ocupare</div>
            </div>
        `;
        }).join('');
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
            const zoneInfo = this.zones.find(z => z.name === this.currentZone);
            const hasShelves = zoneInfo && (zoneInfo.shelfCount || 0) > 0 && (zoneInfo.temporaryCount || 0) === 0;
            shelvesTitle.textContent = `${hasShelves ? 'Rafturi' : 'Locații'} - Zona ${this.currentZone}`;
        }
        
        // FIXED: Case insensitive type filtering
        const zoneShelves = this.locations.filter(l =>
            l.zone === this.currentZone && ['shelf','temporary'].includes(l.type.toLowerCase())
        );
        
        if (zoneShelves.length === 0) {
            shelvesGrid.innerHTML = this.getEmptyShelvesState(`Nu există locații în zona ${this.currentZone}`);
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
        const levelsHTML = shelf.type.toLowerCase() === 'temporary'
            ? this.renderSimpleShelfBar(occupancyTotal)
            : this.renderDynamicShelfLevels(shelf);

        return `
            <div class="shelf-item ${this.getOccupancyClass(occupancyTotal)}" data-shelf-id="${shelf.id}">
                <div class="shelf-code">${shelf.location_code}</div>
                ${levelsHTML}
                <div class="shelf-occupancy">${Math.round(occupancyTotal)}%</div>
            </div>
        `;
    }

    async getDynamicLevelData(shelfId) {
        const response = await fetch(`/api/location_info.php?id=${shelfId}`);
        const data = await response.json();
        return data.levels || [];
    }

    renderDynamicShelfLevels(shelf) {
        const levels = shelf.occupancy?.levels || {};
        const levelBars = Object.entries(levels)
            .sort(([a], [b]) => parseInt(b) - parseInt(a))
            .map(([levelNum, levelData]) => {
                const percentage = levelData.percentage || 0;
                return `<div class="shelf-level" data-level="${levelNum}" title="${levelData.level_name}: ${Math.round(percentage)}%">
                            <div class="level-fill ${this.getOccupancyClass(percentage)}" style="width: ${percentage}%"></div>
                        </div>`;
            }).join('');

        return `<div class="shelf-levels">${levelBars}</div>`;
    }

    renderSimpleShelfBar(percentage) {
        return `
            <div class="shelf-levels">
                <div class="shelf-level">
                    <div class="level-fill ${this.getOccupancyClass(percentage)}" style="width: ${percentage}%"></div>
                </div>
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
            const isShelf = location.type.toLowerCase() === 'shelf';
            const levelsData = location.occupancy?.levels || {};
            const bottom = levelsData['1']?.percentage || 0;
            const middle = levelsData['2']?.percentage || 0;
            const top = levelsData['3']?.percentage || 0;
            const safeCodeJs = JSON.stringify(location.location_code || '');
            const safeCodeAttr = escapeHtml(location.location_code || '');

            return `
                    <tr>
                        <td><strong>${location.location_code}</strong></td>
                        <td>Zona ${location.zone}</td>
                        <td>${location.type}</td>
                        <td><span class="occupancy-badge ${occupancyClass}">${Math.round(location.occupancy?.total || 0)}%</span></td>
                        <td>${isShelf ? Math.round(bottom) + '%' : '-'}</td>
                        <td>${isShelf ? Math.round(middle) + '%' : '-'}</td>
                        <td>${isShelf ? Math.round(top) + '%' : '-'}</td>
                        <td>${location.total_items || 0}</td>
                        <td>${location.unique_products || 0}</td>
                        <td>
                            <button class="btn btn-sm btn-outline" onclick="openEditModalById(${location.id})" title="Editează">
                                <span class="material-symbols-outlined">edit</span>
                            </button>
                            <button class="btn btn-sm btn-outline" onclick='printLocationQr(${safeCodeJs}, this)' data-location-code="${safeCodeAttr}" data-location-name="${safeCodeAttr}" title="Printează QR">
                                <span class="material-symbols-outlined">qr_code_2</span>
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

    showEnhancedTooltip(shelfElement, shelf) {
        if (!this.tooltip) return;
        this.currentShelfElement = shelfElement;
    
        const render = (data) => {
            const cap = data.capacity_details || {};
            const percent = Math.round(cap.utilization_percentage || 0);
            const statusClass = this.getCapacityStatus(percent);
            
            // NEW: Aggregate products by name/SKU to show total quantities instead of individual entries
            const aggregatedProducts = {};
            
            (data.products || []).forEach(product => {
                const key = product.sku || product.name; // Use SKU as primary key, fallback to name
                
                if (!aggregatedProducts[key]) {
                    aggregatedProducts[key] = {
                        name: product.name,
                        sku: product.sku,
                        totalQuantity: 0,
                        entries: 0 // Track number of individual entries
                    };
                }
                
                aggregatedProducts[key].totalQuantity += parseInt(product.quantity) || 0;
                aggregatedProducts[key].entries += 1;
            });
            
            // Convert aggregated products to HTML
            const productsHtml = Object.values(aggregatedProducts)
                .sort((a, b) => b.totalQuantity - a.totalQuantity) // Sort by quantity descending
                .map(p => {
                    const displayName = p.sku ? `${p.name} (${p.sku})` : p.name;
                    return `<li>${displayName} (${p.totalQuantity} buc)</li>`;
                })
                .join('');
                
            const levelsHtml = (data.levels || [])
                .map(l => `<div class="level-row"><span>${l.name}:</span><span>${Math.round(l.occupancy_percentage || 0)}% (${l.current_stock || 0}${l.capacity ? '/' + l.capacity : ''} articole)</span></div>`)
                .join('');
                
            const alertsHtml = (data.alerts || [])
                .map(a => `<div class="alert ${a.type}">${a.message}</div>`)
                .join('');
    
            this.tooltip.innerHTML = `
                <div class="tooltip-header">
                    <strong>${shelf.location_code}</strong>
                    <span class="status-indicator ${statusClass}">${percent}%</span>
                </div>
                <div class="capacity-section">
                    <div class="capacity-bar"><div class="fill ${statusClass}" style="width:${percent}%"></div></div>
                    <div class="capacity-text">${cap.current_stock || 0}/${cap.total_capacity || 0} articole</div>
                    <div class="capacity-available">Disponibil: ${cap.available_space || 0}</div>
                </div>
                ${levelsHtml ? `<div class="levels-section"><div class="section-title">Ocupare pe nivel</div>${levelsHtml}</div>` : ''}
                ${productsHtml ? `<div class="products-section"><div class="section-title">Top produse</div><ul class="product-list">${productsHtml}</ul></div>` : ''}
                ${alertsHtml ? `<div class="alerts">${alertsHtml}</div>` : ''}
                <div class="quick-actions">
                    <button data-action="move">Mută stoc</button>
                    <button data-action="inventory">Verifică inventar</button>
                    <button data-action="add">Adaugă stoc</button>
                    <button data-action="details">Vezi detalii</button>
                </div>
            `;
    
            this.attachQuickActions(shelf.id);
        };
    
        const cached = this.tooltipCache[shelf.id];
        if (cached) {
            render(cached);
        } else {
            this.tooltip.innerHTML = '<div class="tooltip-loading">Încărcare...</div>';
            fetch(`api/location_info.php?id=${shelf.id}`)
                .then(r => r.json())
                .then(data => {
                    this.tooltipCache[shelf.id] = data;
                    render(data);
                })
                .catch(() => {
                    this.tooltip.innerHTML = '<div class="tooltip-error">Eroare la încărcare</div>';
                });
        }
    
        this.tooltip.style.opacity = '1';
        this.tooltip.style.transform = 'translateY(0)';
        this.updateTooltipPosition();
    }

    /**
     * Attach quick action handlers for tooltip buttons
     * @param {number} locationId
     */
    attachQuickActions(locationId) {
        const actions = this.tooltip.querySelectorAll('.quick-actions [data-action]');
        actions.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const action = btn.dataset.action;
                switch (action) {
                    case 'move':
                        window.location.href = `warehouse_relocation.php?from=${locationId}`;
                        break;
                    case 'inventory':
                        window.location.href = `warehouse_inventory.php?location=${locationId}`;
                        break;
                    case 'add':
                        window.location.href = `warehouse_receiving.php?location=${locationId}`;
                        break;
                    case 'details':
                        window.location.href = `locations.php?id=${locationId}`;
                        break;
                }
            });
        });
    }

    /**
     * Determine CSS class for capacity status
     * @param {number} percent
     * @returns {string}
     */
    getCapacityStatus(percent) {
        if (percent >= 95) return 'critical';
        if (percent >= 90) return 'warning';
        return 'normal';
    }

    updateTooltipPosition() {
        if (!this.tooltip || !this.currentShelfElement) return;

        const shelfRect = this.currentShelfElement.getBoundingClientRect();
        const tooltipRect = this.tooltip.getBoundingClientRect();

        let x = shelfRect.right + 10;
        let y = shelfRect.top + (shelfRect.height - tooltipRect.height) / 2;

        // Keep tooltip within viewport
        if (x + tooltipRect.width > window.innerWidth) {
            x = shelfRect.left - tooltipRect.width - 10;
        }
        if (y < 10) {
            y = 10;
        }
        if (y + tooltipRect.height > window.innerHeight) {
            y = window.innerHeight - tooltipRect.height - 10;
        }

        this.tooltip.style.left = x + 'px';
        this.tooltip.style.top = y + 'px';
    }

    hideTooltip() {
        if (this.tooltip) {
            this.tooltip.style.opacity = '0';
            this.tooltip.style.transform = 'translateY(10px)';
        }
        this.currentShelfElement = null;
        clearTimeout(this.hideTooltipTimeout);
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

// Export for global access
window.EnhancedWarehouseVisualization = EnhancedWarehouseVisualization;

// =================== GLOBAL FUNCTION EXPORTS ===================

// Make functions globally available
window.closeModal = closeModal;
window.openDeleteModal = openDeleteModal;
window.closeDeleteModal = closeDeleteModal;
window.switchLocationTab = switchLocationTab;
window.toggleLevel = toggleLevel;
window.selectStoragePolicy = selectStoragePolicy;
window.updateLocationQr = updateLocationQr;
window.downloadLocationQr = downloadLocationQr;
window.printLocationQr = printLocationQr;
window.openCreateModal = openCreateModal;
window.openEditModal = openEditModal;
window.openEditModalById = openEditModalById;
window.addNewLevel = addNewLevel;
window.removeLevel = removeLevel;
window.updateLevelName = updateLevelName;
window.toggleSubdivisions = toggleSubdivisions;
window.addSubdivision = addSubdivision;
window.removeSubdivision = removeSubdivision;
window.searchProductsForSubdivision = searchProductsForSubdivision;
window.selectProduct = selectProduct;
window.showProductResults = showProductResults;
window.hideProductResults = hideProductResults;
window.downloadLevelQR = downloadLevelQR;
window.searchProductForLevel = searchProductForLevel;
window.showLevelProductResults = showLevelProductResults;
window.hideLevelProductResults = hideLevelProductResults;
window.selectLevelProduct = selectLevelProduct;
window.selectStoragePolicyProgrammatic = selectStoragePolicyProgrammatic;
window.fetchProductName = fetchProductName;
window.setLevelDedicatedProduct = setLevelDedicatedProduct;
window.searchMultiProduct = searchMultiProduct;
window.showMultiProductResults = showMultiProductResults;
window.hideMultiProductResults = hideMultiProductResults;
window.addMultiProduct = addMultiProduct;
window.removeMultiProduct = removeMultiProduct;

console.log('✅ Enhanced Dynamic Locations JavaScript loaded successfully');
