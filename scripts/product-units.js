/**
 * Product Units Management JavaScript
 * File: scripts/product-units.js
 * 
 * Handles all functionality for the product units admin interface
 * Following WMS design patterns and existing JavaScript structure
 */

'use strict';

const baseUrl = window.APP_CONFIG?.baseUrl || '';

// ===== GLOBAL VARIABLES AND CONFIGURATION =====
const ProductUnitsApp = {
    // Configuration
    config: {
        apiEndpoints: {
            productUnits: `${baseUrl}/api/product_units.php`,
            products: `${baseUrl}/api/products.php`,
            cargusConfig: `${baseUrl}/api/cargus_config.php`,
            testCargus: `${baseUrl}/api/test_cargus.php`,
            recalculateWeight: `${baseUrl}/api/recalculate_weight.php`
        },
        debounceDelay: 300,
        refreshInterval: 30000, // 30 seconds
        maxRetries: 3
    },

    // State management
    state: {
        currentTab: 'product-units',
        productUnits: [],
        products: [],
        filteredData: [],
        isLoading: false,
        filters: {
            product: '',
            unit: '',
            status: ''
        }
    },

    // DOM elements cache
    elements: {},

    // Initialize the application
    init() {
        this.cacheElements();
        this.bindEvents();
        this.initializeTabs();
        this.loadInitialData();
        this.setupPeriodicRefresh();
        
        console.log('ProductUnitsApp initialized successfully');
    },

    // Cache frequently used DOM elements
    cacheElements() {
        this.elements = {
            // Tabs
            tabButtons: document.querySelectorAll('.tab-button'),
            tabContents: document.querySelectorAll('.tab-content'),
            
            // Statistics
            totalProducts: document.getElementById('totalProducts'),
            totalUnitTypes: document.getElementById('totalUnitTypes'),
            totalPackagingRules: document.getElementById('totalPackagingRules'),
            pendingProducts: document.getElementById('pendingProducts'),
            
            // Filters
            productFilter: document.getElementById('productFilter'),
            unitFilter: document.getElementById('unitFilter'),
            statusFilter: document.getElementById('statusFilter'),
            clearFilters: document.getElementById('clearFilters'),
            applyFilters: document.getElementById('applyFilters'),
            
            // Table
            productUnitsTable: document.getElementById('productUnitsTable'),
            productUnitsBody: document.getElementById('productUnitsBody'),
            tableResultsCount: document.getElementById('tableResultsCount'),
            refreshTable: document.getElementById('refreshTable'),
            
            // Buttons
            addProductUnitBtn: document.getElementById('addProductUnitBtn'),
            addProductUnitFromTab: document.getElementById('addProductUnitFromTab'),
            refreshStatsBtn: document.getElementById('refreshStatsBtn'),
            
            // Modal
            modal: document.getElementById('addProductUnitModal'),
            modalForm: document.getElementById('addProductUnitForm'),
            modalClose: document.querySelector('.modal-close'),
            productSelect: document.getElementById('productSelect'),
            unitTypeSelect: document.getElementById('unitTypeSelect'),
            
            // Cargus Config
            cargusConfigForm: document.getElementById('cargusConfigForm'),
            configAlert: document.getElementById('configAlert'),
            testCargusConnection: document.getElementById('testCargusConnection'),
            saveCargusConfig: document.getElementById('saveCargusConfig'),
            
            // Loading
            loadingOverlay: document.getElementById('loadingOverlay')
        };
    },

    // Bind all event listeners
    bindEvents() {
        // Tab switching
        this.elements.tabButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const tabId = button.dataset.tab;
                this.switchTab(tabId);
            });
        });

        // Modal events
        if (this.elements.addProductUnitBtn) {
            this.elements.addProductUnitBtn.addEventListener('click', () => this.openModal());
        }
        
        if (this.elements.addProductUnitFromTab) {
            this.elements.addProductUnitFromTab.addEventListener('click', () => this.openModal());
        }

        if (this.elements.modalClose) {
            this.elements.modalClose.addEventListener('click', () => this.closeModal());
        }

        // Close modal when clicking outside
        if (this.elements.modal) {
            this.elements.modal.addEventListener('click', (e) => {
                if (e.target === this.elements.modal) {
                    this.closeModal();
                }
            });
        }

        // Form submissions
        if (this.elements.modalForm) {
            this.elements.modalForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleProductUnitSubmit(e);
            });
        }

        // Cancel buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-action="cancel"]')) {
                this.closeModal();
            }
        });

        // Filter events
        if (this.elements.productFilter) {
            this.elements.productFilter.addEventListener('input', 
                this.debounce(() => this.applyFilters(), this.config.debounceDelay)
            );
        }

        if (this.elements.unitFilter) {
            this.elements.unitFilter.addEventListener('change', () => this.applyFilters());
        }

        if (this.elements.statusFilter) {
            this.elements.statusFilter.addEventListener('change', () => this.applyFilters());
        }

        if (this.elements.clearFilters) {
            this.elements.clearFilters.addEventListener('click', () => this.clearFilters());
        }

        if (this.elements.applyFilters) {
            this.elements.applyFilters.addEventListener('click', () => this.applyFilters());
        }

        // Refresh buttons
        if (this.elements.refreshTable) {
            this.elements.refreshTable.addEventListener('click', () => this.loadProductUnits());
        }

        if (this.elements.refreshStatsBtn) {
            this.elements.refreshStatsBtn.addEventListener('click', () => this.loadStatistics());
        }

        // Cargus config events
        if (this.elements.testCargusConnection) {
            this.elements.testCargusConnection.addEventListener('click', () => this.testCargusConnection());
        }

        if (this.elements.saveCargusConfig) {
            this.elements.saveCargusConfig.addEventListener('click', () => this.saveCargusConfiguration());
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeModal();
            }
        });
    },

    // ===== TAB MANAGEMENT =====
    initializeTabs() {
        // Set initial active tab
        this.switchTab(this.state.currentTab);
    },

    switchTab(tabId) {
        // Update state
        this.state.currentTab = tabId;

        // Update tab buttons
        this.elements.tabButtons.forEach(button => {
            button.classList.toggle('active', button.dataset.tab === tabId);
        });

        // Update tab content
        this.elements.tabContents.forEach(content => {
            content.classList.toggle('active', content.id === tabId);
        });

        // Load tab-specific data
        this.loadTabData(tabId);

        console.log(`Switched to tab: ${tabId}`);
    },

    loadTabData(tabId) {
        switch (tabId) {
            case 'product-units':
                this.loadProductUnits();
                break;
            case 'cargus-config':
                this.loadCargusConfiguration();
                break;
            // Other tabs can be implemented as needed
        }
    },

    // ===== DATA LOADING =====
    async loadInitialData() {
        this.showLoading();
        
        try {
            await Promise.all([
                this.loadStatistics(),
                this.loadProducts(),
                this.loadProductUnits()
            ]);
        } catch (error) {
            console.error('Error loading initial data:', error);
            this.showError('Eroare la încărcarea datelor inițiale');
        } finally {
            this.hideLoading();
        }
    },

    async loadStatistics() {
        try {
            const [productsResponse, productUnitsResponse] = await Promise.all([
                this.apiCall('GET', this.config.apiEndpoints.products),
                this.apiCall('GET', this.config.apiEndpoints.productUnits)
            ]);

            const products = productsResponse.data || productsResponse;
            const productUnits = productUnitsResponse.data || productUnitsResponse;

            // Update statistics
            this.updateStatistic('totalProducts', productUnits.length);
            this.updateStatistic('pendingProducts', 
                products.filter(p => p.configured_units === 0).length
            );

            console.log('Statistics updated successfully');
        } catch (error) {
            console.error('Error loading statistics:', error);
        }
    },

    updateStatistic(elementId, value) {
        const element = this.elements[elementId];
        if (element) {
            // Animate the number change
            this.animateNumber(element, parseInt(element.textContent) || 0, value);
        }
    },

    animateNumber(element, start, end) {
        const duration = 500;
        const startTime = performance.now();
        
        const updateNumber = (currentTime) => {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            const current = Math.round(start + (end - start) * progress);
            element.textContent = current;
            
            if (progress < 1) {
                requestAnimationFrame(updateNumber);
            }
        };
        
        requestAnimationFrame(updateNumber);
    },

    async loadProducts() {
        try {
            const response = await this.apiCall('GET', this.config.apiEndpoints.products);
            this.state.products = response.data || response;
            this.populateProductSelect();
            
            console.log(`Loaded ${this.state.products.length} products`);
        } catch (error) {
            console.error('Error loading products:', error);
            this.showError('Eroare la încărcarea listei de produse');
        }
    },

    populateProductSelect() {
        if (!this.elements.productSelect) return;

        this.elements.productSelect.innerHTML = '<option value="">Selectează produs...</option>';
        
        this.state.products.forEach(product => {
            const option = document.createElement('option');
            option.value = product.id;
            option.textContent = `${product.name} (${product.code})`;
            this.elements.productSelect.appendChild(option);
        });
    },

    async loadProductUnits() {
        if (this.state.isLoading) return;

        this.state.isLoading = true;
        this.showTableLoading();

        try {
            const response = await this.apiCall('GET', this.config.apiEndpoints.productUnits);
            this.state.productUnits = response.data || response;
            this.state.filteredData = [...this.state.productUnits];
            
            this.applyFilters();
            this.renderProductUnitsTable();
            
            console.log(`Loaded ${this.state.productUnits.length} product units`);
        } catch (error) {
            console.error('Error loading product units:', error);
            this.showError('Eroare la încărcarea configurărilor de unități');
            this.renderTableError('Eroare la încărcarea datelor');
        } finally {
            this.state.isLoading = false;
        }
    },

    // ===== TABLE RENDERING =====
    renderProductUnitsTable() {
        if (!this.elements.productUnitsBody) return;

        const tbody = this.elements.productUnitsBody;
        
        if (this.state.filteredData.length === 0) {
            this.renderEmptyTable();
            return;
        }

        tbody.innerHTML = this.state.filteredData.map(unit => `
            <tr data-id="${unit.id}">
                <td>
                    <div class="product-info">
                        <strong>${this.escapeHtml(unit.product_name)}</strong>
                        <small class="text-muted">${this.escapeHtml(unit.product_code || '')}</small>
                    </div>
                </td>
                <td><code>${this.escapeHtml(unit.product_code || '-')}</code></td>
                <td>
                    <span class="badge badge-primary">${this.escapeHtml(unit.unit_code)}</span>
                    <small class="text-muted d-block">${this.escapeHtml(unit.unit_name)}</small>
                </td>
                <td><strong>${unit.weight_per_unit} kg</strong></td>
                <td>${unit.volume_per_unit ? unit.volume_per_unit + ' L' : '-'}</td>
                <td>
                    <div class="properties-list">
                        ${unit.fragile ? '<span class="badge badge-warning">Fragil</span>' : ''}
                        ${unit.hazardous ? '<span class="badge badge-danger">Periculos</span>' : ''}
                        ${unit.temperature_controlled ? '<span class="badge badge-info">Temp Control</span>' : ''}
                        ${!unit.fragile && !unit.hazardous && !unit.temperature_controlled ? '<span class="text-muted">-</span>' : ''}
                    </div>
                </td>
                <td>${unit.max_items_per_parcel || '-'}</td>
                <td>
                    <span class="status-${unit.active ? 'active' : 'inactive'}">
                        ${unit.active ? 'Activ' : 'Inactiv'}
                    </span>
                </td>
                <td>
                    <div class="action-buttons">
                        <button class="btn btn-sm btn-secondary" 
                                onclick="ProductUnitsApp.editProductUnit(${unit.id})" 
                                title="Editează">
                            <span class="material-symbols-outlined">edit</span>
                        </button>
                        <button class="btn btn-sm btn-danger" 
                                onclick="ProductUnitsApp.deleteProductUnit(${unit.id})" 
                                title="Șterge">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');

        this.updateTableInfo();
    },

    renderEmptyTable() {
        if (!this.elements.productUnitsBody) return;

        this.elements.productUnitsBody.innerHTML = `
            <tr class="empty-row">
                <td colspan="9" class="text-center">
                    <div class="empty-state">
                        <span class="material-symbols-outlined">inventory_2</span>
                        <h3>Nu există configurări</h3>
                        <p>Nu au fost găsite configurări de unități pentru produse.</p>
                        <button class="btn btn-primary" onclick="ProductUnitsApp.openModal()">
                            <span class="material-symbols-outlined">add</span>
                            Adaugă Prima Configurare
                        </button>
                    </div>
                </td>
            </tr>
        `;

        this.updateTableInfo();
    },

    renderTableError(message) {
        if (!this.elements.productUnitsBody) return;

        this.elements.productUnitsBody.innerHTML = `
            <tr class="error-row">
                <td colspan="9" class="text-center">
                    <div class="error-state">
                        <span class="material-symbols-outlined">error</span>
                        <h3>Eroare la încărcare</h3>
                        <p>${this.escapeHtml(message)}</p>
                        <button class="btn btn-secondary" onclick="ProductUnitsApp.loadProductUnits()">
                            <span class="material-symbols-outlined">refresh</span>
                            Încearcă din nou
                        </button>
                    </div>
                </td>
            </tr>
        `;

        this.updateTableInfo();
    },

    showTableLoading() {
        if (!this.elements.productUnitsBody) return;

        this.elements.productUnitsBody.innerHTML = `
            <tr class="loading-row">
                <td colspan="9" class="text-center">
                    <div class="loading-spinner">
                        <span class="material-symbols-outlined spinning">progress_activity</span>
                        Încărcare date...
                    </div>
                </td>
            </tr>
        `;
    },

    updateTableInfo() {
        if (!this.elements.tableResultsCount) return;

        const total = this.state.productUnits.length;
        const filtered = this.state.filteredData.length;
        
        if (total === filtered) {
            this.elements.tableResultsCount.textContent = `${total} configurări`;
        } else {
            this.elements.tableResultsCount.textContent = `${filtered} din ${total} configurări`;
        }
    },

    // ===== FILTERING =====
    applyFilters() {
        if (!this.state.productUnits.length) return;

        const filters = {
            product: this.elements.productFilter?.value.toLowerCase().trim() || '',
            unit: this.elements.unitFilter?.value || '',
            status: this.elements.statusFilter?.value || ''
        };

        this.state.filters = filters;

        this.state.filteredData = this.state.productUnits.filter(unit => {
            // Product name filter
            if (filters.product && 
                !unit.product_name.toLowerCase().includes(filters.product) &&
                !unit.product_code.toLowerCase().includes(filters.product)) {
                return false;
            }

            // Unit type filter
            if (filters.unit && unit.unit_code !== filters.unit) {
                return false;
            }

            // Status filter
            if (filters.status) {
                const isActive = unit.active;
                if (filters.status === 'active' && !isActive) return false;
                if (filters.status === 'inactive' && isActive) return false;
            }

            return true;
        });

        this.renderProductUnitsTable();
        
        console.log(`Applied filters, showing ${this.state.filteredData.length} of ${this.state.productUnits.length} items`);
    },

    clearFilters() {
        // Clear filter inputs
        if (this.elements.productFilter) this.elements.productFilter.value = '';
        if (this.elements.unitFilter) this.elements.unitFilter.value = '';
        if (this.elements.statusFilter) this.elements.statusFilter.value = '';

        // Reset filtered data
        this.state.filteredData = [...this.state.productUnits];
        this.state.filters = { product: '', unit: '', status: '' };

        this.renderProductUnitsTable();
        
        console.log('Filters cleared');
    },

    // ===== MODAL MANAGEMENT =====
    openModal() {
        if (!this.elements.modal) return;

        // Load products if not already loaded
        if (this.state.products.length === 0) {
            this.loadProducts();
        }

        // Reset form
        if (this.elements.modalForm) {
            this.elements.modalForm.reset();
        }

        // Show modal
        this.elements.modal.style.display = 'block';
        document.body.style.overflow = 'hidden';

        console.log('Modal opened');
    },

    closeModal() {
        if (!this.elements.modal) return;

        this.elements.modal.style.display = 'none';
        document.body.style.overflow = '';

        // Reset form
        if (this.elements.modalForm) {
            this.elements.modalForm.reset();
        }

        console.log('Modal closed');
    },

    // ===== FORM HANDLING =====
    async handleProductUnitSubmit(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        const data = Object.fromEntries(formData);

        // Convert checkboxes
        data.fragile = formData.has('fragile');
        data.hazardous = formData.has('hazardous');

        // Validate required fields
        if (!data.product_id || !data.unit_type_id || !data.weight_per_unit) {
            this.showError('Vă rugăm să completați toate câmpurile obligatorii');
            return;
        }

        this.showLoading();

        try {
            const response = await this.apiCall('POST', this.config.apiEndpoints.productUnits, data);
            
            if (response.success || response.id) {
                this.showSuccess('Configurarea a fost salvată cu succes!');
                this.closeModal();
                await this.loadProductUnits();
                await this.loadStatistics();
            } else {
                throw new Error(response.error || 'Eroare la salvarea configurării');
            }
        } catch (error) {
            console.error('Error saving product unit:', error);
            this.showError(error.message || 'Eroare la salvarea configurării');
        } finally {
            this.hideLoading();
        }
    },

    // ===== CRUD OPERATIONS =====
    async editProductUnit(id) {
        console.log(`Edit product unit: ${id}`);
        // TODO: Implement edit functionality
        this.showInfo('Funcționalitatea de editare va fi implementată în curând!');
    },

    async deleteProductUnit(id) {
        if (!confirm('Sunteți sigur că doriți să ștergeți această configurare?')) {
            return;
        }

        this.showLoading();

        try {
            const response = await this.apiCall('DELETE', `${this.config.apiEndpoints.productUnits}?id=${id}`);
            
            if (response.success) {
                this.showSuccess('Configurarea a fost ștearsă cu succes!');
                await this.loadProductUnits();
                await this.loadStatistics();
            } else {
                throw new Error(response.error || 'Eroare la ștergerea configurării');
            }
        } catch (error) {
            console.error('Error deleting product unit:', error);
            this.showError(error.message || 'Eroare la ștergerea configurării');
        } finally {
            this.hideLoading();
        }
    },

    // ===== CARGUS CONFIGURATION =====
    async loadCargusConfiguration() {
        try {
            const response = await this.apiCall('GET', this.config.apiEndpoints.cargusConfig);
            const config = response.data || response;

            // Populate form fields
            if (this.elements.cargusConfigForm) {
                Object.keys(config).forEach(key => {
                    const input = this.elements.cargusConfigForm.querySelector(`[name="${key}"]`);
                    if (input) {
                        if (input.type === 'checkbox') {
                            input.checked = config[key];
                        } else {
                            input.value = config[key] || '';
                        }
                    }
                });
            }

            console.log('Cargus configuration loaded');
        } catch (error) {
            console.error('Error loading Cargus configuration:', error);
        }
    },

    async saveCargusConfiguration() {
        if (!this.elements.cargusConfigForm) return;

        const formData = new FormData(this.elements.cargusConfigForm);
        const data = {};

        // Process form data
        for (const [key, value] of formData.entries()) {
            const input = this.elements.cargusConfigForm.querySelector(`[name="${key}"]`);
            if (input.type === 'checkbox') {
                data[key] = input.checked;
            } else if (input.type === 'number') {
                data[key] = parseFloat(value) || 0;
            } else {
                data[key] = value;
            }
        }

        // Handle unchecked checkboxes
        this.elements.cargusConfigForm.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            if (!formData.has(checkbox.name)) {
                data[checkbox.name] = false;
            }
        });

        this.showLoading();

        try {
            const response = await this.apiCall('POST', this.config.apiEndpoints.cargusConfig, data);
            
            if (response.success) {
                this.showConfigAlert('Configurările au fost salvate cu succes!');
            } else {
                throw new Error(response.error || 'Eroare la salvarea configurării');
            }
        } catch (error) {
            console.error('Error saving Cargus configuration:', error);
            this.showError(error.message || 'Eroare la salvarea configurării');
        } finally {
            this.hideLoading();
        }
    },

    async testCargusConnection() {
        this.showLoading();

        try {
            const response = await this.apiCall('GET', this.config.apiEndpoints.testCargus);
            
            if (response.success) {
                const message = `✅ Conexiunea la Cargus este funcțională!\n\nToken valabil până: ${response.token_expiry || 'N/A'}`;
                alert(message);
            } else {
                const message = `❌ Eroare la conexiunea cu Cargus:\n${response.error}`;
                alert(message);
            }
        } catch (error) {
            console.error('Error testing Cargus connection:', error);
            alert(`❌ Eroare la testarea conexiunii:\n${error.message}`);
        } finally {
            this.hideLoading();
        }
    },

    showConfigAlert(message) {
        if (!this.elements.configAlert) return;

        this.elements.configAlert.textContent = message;
        this.elements.configAlert.style.display = 'flex';

        setTimeout(() => {
            this.elements.configAlert.style.display = 'none';
        }, 5000);
    },

    // ===== UTILITY FUNCTIONS =====
    async apiCall(method, url, data = null) {
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            },
            credentials: 'same-origin'
        };

        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }

        const response = await fetch(url, options);
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({ error: `HTTP ${response.status}` }));
            throw new Error(errorData.error || `HTTP ${response.status}`);
        }

        return await response.json();
    },

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
    },

    escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, (m) => map[m]);
    },

    showLoading() {
        if (this.elements.loadingOverlay) {
            this.elements.loadingOverlay.style.display = 'flex';
        }
    },

    hideLoading() {
        if (this.elements.loadingOverlay) {
            this.elements.loadingOverlay.style.display = 'none';
        }
    },

    showSuccess(message) {
        // Use existing notification system or simple alert
        alert(`✅ ${message}`);
    },

    showError(message) {
        alert(`❌ ${message}`);
    },

    showInfo(message) {
        alert(`ℹ️ ${message}`);
    },

    setupPeriodicRefresh() {
        // Refresh statistics periodically
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.loadStatistics();
            }
        }, this.config.refreshInterval);
    }
};

// ===== INITIALIZATION =====
document.addEventListener('DOMContentLoaded', () => {
    ProductUnitsApp.init();
});

// Export for global access (if needed)
window.ProductUnitsApp = ProductUnitsApp;