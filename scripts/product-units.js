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
            recalculateWeight: `${baseUrl}/api/recalculate_weight.php`,
            stockSettings: `${baseUrl}/api/inventory_settings.php`,
            barrelDimensions: `${baseUrl}/api/barrel_dimensions.php`,
            labelTemplates: `${baseUrl}/api/label_templates.php`
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
        stockSettings: [],
        stockPagination: { limit: 20, offset: 0, total: 0, has_next: false },
        pendingPagination: { limit: 20, offset: 0, total: 0, has_next: false },
        pendingList: [],
        pendingSearch: '',
        stockSearch: '',
        stockCategory: '',
        stockSeller: '',
        barrelDimensions: [],
        filteredData: [],
        isLoading: false,
        filters: {
            product: '',
            unit: '',
            status: ''
        },
        labelTemplates: [],
        labelFilters: { search: '', status: '' },
        labelStats: { total: 0, with_label: 0, without_label: 0 },
        labelPagination: { limit: 50, offset: 0, total: 0, has_next: false }
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
            productsWithLabels: document.getElementById('productsWithLabels'),
            productsWithoutLabels: document.getElementById('productsWithoutLabels'),
            showUnlabeledBtn: document.getElementById('showUnlabeledBtn'),
            
            // Filters
            productFilter: document.getElementById('productFilter'),
            unitFilter: document.getElementById('unitFilter'),
            showPendingProductsBtn: document.getElementById('showPendingProductsBtn'),
            pendingProductsModal: document.getElementById('pendingProductsModal'),
            pendingProductsList: document.getElementById('pendingProductsList'),
            pendingPagination: document.getElementById('pendingPagination'),
            pendingSearchInput: document.getElementById('pendingSearch'),
            pendingModalClose: document.querySelector('#pendingProductsModal .modal-close'),
            statusFilter: document.getElementById('statusFilter'),
            clearFilters: document.getElementById('clearFilters'),
            applyFilters: document.getElementById('applyFilters'),
            
            // Table
            productUnitsTable: document.getElementById('productUnitsTable'),
            productUnitsBody: document.getElementById('productUnitsBody'),
            tableResultsCount: document.getElementById('tableResultsCount'),
            refreshTable: document.getElementById('refreshTable'),

            // Label management
            labelTable: document.getElementById('labelTable'),
            labelTableBody: document.getElementById('labelTableBody'),
            labelTableResults: document.getElementById('labelTableResults'),
            labelSearchInput: document.getElementById('labelSearch'),
            labelStatusFilter: document.getElementById('labelStatusFilter'),
            clearLabelFilters: document.getElementById('clearLabelFilters'),
            applyLabelFilters: document.getElementById('applyLabelFilters'),
            refreshLabelTable: document.getElementById('refreshLabelTable'),
            reloadLabelData: document.getElementById('reloadLabelData'),
            bulkLabelUpload: document.getElementById('bulkLabelUpload'),
            labelPagination: document.getElementById('labelPagination'),
            
            // Buttons
            addProductUnitBtn: document.getElementById('addProductUnitBtn'),
            addProductUnitFromTab: document.getElementById('addProductUnitFromTab'),
            refreshStatsBtn: document.getElementById('refreshStatsBtn'),

            // Stock management elements
            addStockSetting: document.getElementById('addStockSetting'),
            stockSettingsBody: document.getElementById('stockSettingsBody'),
            stockSearchInput: document.getElementById('stockSearch'),
            stockCategoryFilter: document.getElementById('stockCategoryFilter'),
            stockSellerFilter: document.getElementById('stockSellerFilter'),
            stockPagination: document.getElementById('stockPagination'),
            stockSettingsModal: document.getElementById('stockSettingsModal'),
            stockSettingsForm: document.getElementById('stockSettingsForm'),
            stockProductId: document.getElementById('stockProductId'),
            stockProductSearch: document.getElementById('stockProductSearch'),
            stockProductResults: document.getElementById('stockProductResults'),
            stockModalClose: document.querySelector('#stockSettingsModal .modal-close'),
            autoOrderEnabled: document.getElementById('autoOrderEnabled'),
            minStockLevel: document.getElementById('minStockLevel'),
            minOrderQty: document.getElementById('minOrderQty'),
            assignedSupplier: document.getElementById('assignedSupplier'),
            currentStockInfo: document.getElementById('currentStockInfo'),
            noSupplierWarning: document.getElementById('noSupplierWarning'),
            
            // Modal
            modal: document.getElementById('addProductUnitModal'),
            modalForm: document.getElementById('addProductUnitForm'),
            modalClose: document.querySelector('#addProductUnitModal .modal-close'),
            productIdInput: document.getElementById('productSelect'),
            productSearchInput: document.getElementById('productSearchInput'),
            productSearchResults: document.getElementById('productSearchResults'),
            unitTypeSelect: document.getElementById('unitTypeSelect'),
            barrelDimensionSelect: document.getElementById('barrelDimensionSelect'),
            dimensionsLengthInput: document.getElementById('dimensionsLength'),
            dimensionsWidthInput: document.getElementById('dimensionsWidth'),
            dimensionsHeightInput: document.getElementById('dimensionsHeight'),
            
            // Cargus Config
            cargusConfigForm: document.getElementById('cargusConfigForm'),
            configAlert: document.getElementById('configAlert'),
            testCargusConnection: document.getElementById('testCargusConnection'),
            saveCargusConfig: document.getElementById('saveCargusConfig'),
            
            // Loading
            loadingOverlay: document.getElementById('loadingOverlay')
        };

        // Aliases for generic handlers
        this.elements.productId = this.elements.productIdInput;
        this.elements.productSearch = this.elements.productSearchInput;
        this.elements.productResults = this.elements.productSearchResults;
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
        if (this.elements.stockModalClose) {
            this.elements.stockModalClose.addEventListener('click', () => this.closeStockModal());
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

        if (this.elements.barrelDimensionSelect) {
            this.elements.barrelDimensionSelect.addEventListener('change', () => {
                const id = this.elements.barrelDimensionSelect.value;
                const dim = this.state.barrelDimensions.find(d => d.id == id);
                if (dim) {
                    if (this.elements.dimensionsLengthInput) this.elements.dimensionsLengthInput.value = dim.length_cm;
                    if (this.elements.dimensionsWidthInput) this.elements.dimensionsWidthInput.value = dim.width_cm;
                    if (this.elements.dimensionsHeightInput) this.elements.dimensionsHeightInput.value = dim.height_cm;
                }
            });
        }

        // Cancel buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-action="cancel"]')) {
                if (e.target.closest('#stockSettingsModal')) {
                    this.closeStockModal();
                } else if (e.target.closest('#pendingProductsModal')) {
                    this.closePendingProductsModal();
                } else {
                    this.closeModal();
                }
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

        if (this.elements.showPendingProductsBtn) {
            this.elements.showPendingProductsBtn.addEventListener('click', () => this.openPendingProductsModal());
        }

        if (this.elements.refreshStatsBtn) {
            this.elements.refreshStatsBtn.addEventListener('click', () => this.loadStatistics());
        }

        if (this.elements.addStockSetting) {
            this.elements.addStockSetting.addEventListener('click', () => this.openStockModal());
        }

        if (this.elements.stockSettingsModal) {
            this.elements.stockSettingsModal.addEventListener('click', (e) => {
                if (e.target === this.elements.stockSettingsModal) {
                    this.closeStockModal();
                }
            });
        }

        if (this.elements.stockSettingsForm) {
            this.elements.stockSettingsForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveStockSettings();
            });
        }

        if (this.elements.pendingProductsModal) {
            this.elements.pendingProductsModal.addEventListener('click', (e) => {
                if (e.target === this.elements.pendingProductsModal) {
                    this.closePendingProductsModal();
                }
            });
        }

        if (this.elements.stockSearchInput) {
            this.elements.stockSearchInput.addEventListener('input', this.debounce(() => {
                this.state.stockSearch = this.elements.stockSearchInput.value.trim();
                this.state.stockPagination.offset = 0;
                this.loadStockSettings();
            }, this.config.debounceDelay));
        }

        if (this.elements.stockCategoryFilter) {
            this.elements.stockCategoryFilter.addEventListener('change', () => {
                this.state.stockCategory = this.elements.stockCategoryFilter.value;
                this.state.stockPagination.offset = 0;
                this.loadStockSettings();
            });
        }

        if (this.elements.stockSellerFilter) {
            this.elements.stockSellerFilter.addEventListener('change', () => {
                this.state.stockSeller = this.elements.stockSellerFilter.value;
                this.state.stockPagination.offset = 0;
                this.loadStockSettings();
            });
        }

        if (this.elements.pendingSearchInput) {
            this.elements.pendingSearchInput.addEventListener('input', this.debounce(() => {
                this.state.pendingSearch = this.elements.pendingSearchInput.value.trim().toLowerCase();
                this.state.pendingPagination.offset = 0;
                this.renderPendingProductsTable();
                this.renderPendingPagination();
            }, this.config.debounceDelay));
        }

        if (this.elements.productSearchInput) {
            this.elements.productSearchInput.addEventListener('input', this.debounce(() => {
                const q = this.elements.productSearchInput.value.trim();
                this.searchProducts(q, 'product');
            }, this.config.debounceDelay));
        }

        if (this.elements.stockProductSearch) {
            this.elements.stockProductSearch.addEventListener('input', this.debounce(() => {
                const q = this.elements.stockProductSearch.value.trim();
                this.searchProducts(q, 'stockProduct');
            }, this.config.debounceDelay));
        }

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.seller-search-container')) {
                this.hideProductResults('product');
                this.hideProductResults('stockProduct');
            }
        });

        if (this.elements.stockPagination) {
            this.elements.stockPagination.addEventListener('click', (e) => {
                if (e.target.classList.contains('prev-page')) {
                    if (this.state.stockPagination.offset >= this.state.stockPagination.limit) {
                        this.state.stockPagination.offset -= this.state.stockPagination.limit;
                        this.loadStockSettings();
                    }
                } else if (e.target.classList.contains('next-page')) {
                    if (this.state.stockPagination.has_next) {
                        this.state.stockPagination.offset += this.state.stockPagination.limit;
                        this.loadStockSettings();
                    }
                }
            });
        }

        if (this.elements.pendingPagination) {
            this.elements.pendingPagination.addEventListener('click', (e) => {
                if (e.target.classList.contains('prev-page')) {
                    if (this.state.pendingPagination.offset >= this.state.pendingPagination.limit) {
                        this.state.pendingPagination.offset -= this.state.pendingPagination.limit;
                        this.renderPendingProductsTable();
                        this.renderPendingPagination();
                    }
                } else if (e.target.classList.contains('next-page')) {
                    if (this.state.pendingPagination.offset + this.state.pendingPagination.limit < this.state.pendingPagination.total) {
                        this.state.pendingPagination.offset += this.state.pendingPagination.limit;
                        this.renderPendingProductsTable();
                        this.renderPendingPagination();
                    }
                }
            });
        }

        if (this.elements.labelPagination) {
            this.elements.labelPagination.addEventListener('click', (e) => {
                if (e.target.classList.contains('prev-page')) {
                    if (this.state.labelPagination.offset >= this.state.labelPagination.limit) {
                        this.state.labelPagination.offset -= this.state.labelPagination.limit;
                        this.loadLabelTemplates();
                    }
                } else if (e.target.classList.contains('next-page')) {
                    if (this.state.labelPagination.has_next) {
                        this.state.labelPagination.offset += this.state.labelPagination.limit;
                        this.loadLabelTemplates();
                    }
                }
            });
        }

        // Label management events
        if (this.elements.showUnlabeledBtn) {
            this.elements.showUnlabeledBtn.addEventListener('click', () => {
                this.switchTab('label-management');
                if (this.elements.labelStatusFilter) {
                    this.elements.labelStatusFilter.value = 'without';
                }
                this.state.labelFilters = { search: '', status: 'without' };
                this.state.labelPagination.offset = 0;
                this.loadLabelTemplates();
            });
        }

        if (this.elements.applyLabelFilters) {
            this.elements.applyLabelFilters.addEventListener('click', () => {
                this.state.labelFilters.search = this.elements.labelSearchInput.value.trim();
                this.state.labelFilters.status = this.elements.labelStatusFilter.value;
                this.state.labelPagination.offset = 0;
                this.loadLabelTemplates();
            });
        }

        if (this.elements.clearLabelFilters) {
            this.elements.clearLabelFilters.addEventListener('click', () => {
                if (this.elements.labelSearchInput) this.elements.labelSearchInput.value = '';
                if (this.elements.labelStatusFilter) this.elements.labelStatusFilter.value = '';
                this.state.labelFilters = { search: '', status: '' };
                this.state.labelPagination.offset = 0;
                this.loadLabelTemplates();
            });
        }

        if (this.elements.refreshLabelTable) {
            this.elements.refreshLabelTable.addEventListener('click', () => this.loadLabelTemplates());
        }

        if (this.elements.reloadLabelData) {
            this.elements.reloadLabelData.addEventListener('click', () => this.loadLabelTemplates());
        }

        if (this.elements.labelTableBody) {
            this.elements.labelTableBody.addEventListener('change', (e) => {
                if (e.target.matches('.label-file-input')) {
                    const id = e.target.dataset.id;
                    const file = e.target.files[0];
                    if (file) {
                        this.uploadLabelTemplate(id, file);
                    }
                }
            });
            this.elements.labelTableBody.addEventListener('click', (e) => {
                const actionEl = e.target.closest('[data-action]');
                if (!actionEl) return;
                const id = actionEl.dataset.id;
                const action = actionEl.dataset.action;
                if (action === 'delete') {
                    this.deleteLabelTemplate(id);
                }
            });
        }

        if (this.elements.bulkLabelUpload) {
            this.elements.bulkLabelUpload.addEventListener('change', (e) => {
                const files = Array.from(e.target.files);
                if (!files.length) return;
                const uploads = files.map(file => {
                    const match = file.name.match(/(\d+)/);
                    if (!match) return Promise.resolve();
                    const code = match[1];
                    const product = this.state.products.find(p => p.sku && p.sku.includes(code));
                    if (!product) return Promise.resolve();
                    return this.uploadLabelTemplate(product.product_id, file, false);
                });
                Promise.all(uploads).then(() => this.loadLabelTemplates());
                this.elements.bulkLabelUpload.value = '';
            });
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
                if (this.elements.stockSettingsModal && this.elements.stockSettingsModal.style.display === 'block') {
                    this.closeStockModal();
                } else {
                    this.closeModal();
                }
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
            case 'stock-management':
                this.loadStockSettings();
                break;
            case 'cargus-config':
                this.loadCargusConfiguration();
                break;
            case 'label-management':
                this.loadLabelTemplates();
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
                this.loadProductUnits(),
                this.loadStockSettings(),
                this.loadBarrelDimensions()
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
            const [productsResponse, productUnitsResponse, labelResponse] = await Promise.all([
                this.apiCall('GET', `${this.config.apiEndpoints.products}?limit=10000`),
                this.apiCall('GET', this.config.apiEndpoints.productUnits),
                this.apiCall('GET', `${this.config.apiEndpoints.labelTemplates}?limit=0`)
            ]);

            const products = productsResponse.data || productsResponse;
            const productUnits = productUnitsResponse.data || productUnitsResponse;
            const labelStats = (labelResponse.data && labelResponse.data.stats) ? labelResponse.data.stats : (labelResponse.stats || { total:0, with_label:0, without_label:0 });

            // Update statistics
            this.updateStatistic('totalProducts', productUnits.length);
            this.updateStatistic('pendingProducts',
                products.filter(p => p.configured_units === 0).length
            );
            this.updateStatistic('productsWithLabels', labelStats.with_label);
            this.updateStatistic('productsWithoutLabels', labelStats.without_label);

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
            const url = `${this.config.apiEndpoints.products}?limit=1000`;
            const response = await this.apiCall('GET', url);
            this.state.products = response.data || response;
            this.populateStockCategoryFilter();

            console.log(`Loaded ${this.state.products.length} products`);
        } catch (error) {
            console.error('Error loading products:', error);
            this.showError('Eroare la încărcarea listei de produse');
        }
    },


    async loadBarrelDimensions() {
        try {
            const response = await this.apiCall('GET', this.config.apiEndpoints.barrelDimensions);
            this.state.barrelDimensions = response.data || response;
            this.populateBarrelDimensionSelect();
        } catch (error) {
            console.error('Error loading barrel dimensions:', error);
        }
    },

    populateBarrelDimensionSelect() {
        if (!this.elements.barrelDimensionSelect) return;
        const select = this.elements.barrelDimensionSelect;
        select.innerHTML = '<option value="">Selectează...</option>';
        this.state.barrelDimensions.forEach(dim => {
            const opt = document.createElement('option');
            opt.value = dim.id;
            opt.textContent = dim.label;
            select.appendChild(opt);
        });
    },

    populateStockCategoryFilter() {
        if (!this.elements.stockCategoryFilter) return;
        const select = this.elements.stockCategoryFilter;
        const categories = [...new Set(this.state.products.map(p => p.category).filter(Boolean))].sort();
        select.innerHTML = '<option value="">Toate categoriile</option>';
        categories.forEach(cat => {
            const opt = document.createElement('option');
            opt.value = cat;
            opt.textContent = cat;
            select.appendChild(opt);
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
    openModal(productId = null) {
        if (!this.elements.modal) return;

        if (this.elements.pendingProductsModal && this.elements.pendingProductsModal.style.display === 'block') {
            this.closePendingProductsModal();
        }


        // Reset search inputs
        if (this.elements.productIdInput) this.elements.productIdInput.value = '';
        if (this.elements.productSearchInput) this.elements.productSearchInput.value = '';
        if (this.elements.productSearchResults) this.elements.productSearchResults.innerHTML = '';
        if (this.elements.productSearchResults) this.elements.productSearchResults.classList.remove('show');

        if (this.state.barrelDimensions.length === 0) {
            this.loadBarrelDimensions();
        }

        // Reset form
        if (this.elements.modalForm) {
            this.elements.modalForm.reset();
        }

        if (this.elements.barrelDimensionSelect) {
            this.elements.barrelDimensionSelect.value = '';
        }

        if (productId) {
            const prod = this.state.products.find(p => p.id == productId);
            if (prod) {
                this.selectProduct(prod.id, prod.name, 'product');
            }
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

        if (this.elements.barrelDimensionSelect) {
            this.elements.barrelDimensionSelect.value = '';
        }
        if (this.elements.productSearchResults) {
            this.elements.productSearchResults.innerHTML = '';
            this.elements.productSearchResults.classList.remove('show');
        }

        console.log('Modal closed');
    },

    // ===== FORM HANDLING =====
    async handleProductUnitSubmit(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        const data = {
            product_id: formData.get('product_id'),
            unit_type_id: formData.get('unit_type_id'),
            weight_per_unit: formData.get('weight_per_unit'),
            volume_per_unit: formData.get('volume_per_unit') || null,
            dimensions_length: formData.get('dimensions_length') || null,
            dimensions_width: formData.get('dimensions_width') || null,
            dimensions_height: formData.get('dimensions_height') || null,
            max_stack_height: formData.get('max_stack_height') || 1,
            packaging_cost: formData.get('packaging_cost') || 0,
            fragile: formData.has('fragile'),
            hazardous: formData.has('hazardous'),
            temperature_controlled: formData.has('temperature_controlled'),
            active: formData.has('active')
        };

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

    async searchProducts(query, context = 'product') {
        const resultsEl = this.elements[`${context}Results`];
        if (!resultsEl) return;
        if (!query || query.length < 2) {
            this.hideProductResults(context);
            return;
        }
        try {
            const url = `${this.config.apiEndpoints.products}?search=${encodeURIComponent(query)}&limit=50`;
            const resp = await fetch(url);
            const data = await resp.json();
            // API may return an object with a `data` key or a raw array
            const products = Array.isArray(data) ? data : data.data;
            if (resp.ok && Array.isArray(products)) {
                this.displayProductResults(products, context);
            } else {
                this.hideProductResults(context);
            }
        } catch (err) {
            console.error('Product search error', err);
            this.hideProductResults(context);
        }
    },

    displayProductResults(products, context = 'product') {
        const container = this.elements[`${context}Results`];
        if (!container) return;
        if (products.length === 0) {
            container.innerHTML = '<div class="seller-search-item">Nu s-au găsit produse</div>';
            container.classList.add('show');
            return;
        }
        container.innerHTML = products.map((p, idx) => `
            <div class="seller-search-item" data-id="${p.id}" data-name="${this.escapeHtml(p.name)}" data-index="${idx}">
                <span class="seller-item-name">${this.escapeHtml(p.name)}</span>
                <span class="seller-item-details">${this.escapeHtml(p.code)}</span>
            </div>
        `).join('');
        container.classList.add('show');
        container.querySelectorAll('.seller-search-item').forEach(item => {
            item.addEventListener('click', () => {
                const id = item.getAttribute('data-id');
                const name = item.getAttribute('data-name');
                this.selectProduct(id, name, context);
            });
        });
    },

    selectProduct(id, name, context = 'product') {
        const idInput = this.elements[`${context}Id`];
        const searchInput = this.elements[`${context}Search`];
        if (idInput) idInput.value = id;
        if (searchInput) searchInput.value = name;
        this.hideProductResults(context);
    },

    hideProductResults(context = 'product') {
        const resultsEl = this.elements[`${context}Results`];
        if (resultsEl) {
            resultsEl.innerHTML = '';
            resultsEl.classList.remove('show');
        }
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
        const contentType = response.headers.get('content-type') || '';
        const rawText = await response.text();

        if (!response.ok) {
            let errorData;
            try {
                errorData = JSON.parse(rawText);
            } catch (_) {
                errorData = { error: rawText || `HTTP ${response.status}` };
            }
            throw new Error(errorData.error || `HTTP ${response.status}`);
        }

        if (contentType.includes('application/json')) {
            try {
                return JSON.parse(rawText);
            } catch (e) {
                throw new Error('Invalid JSON response');
            }
        }

        throw new Error('Invalid JSON response');
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
    },

    async editProductUnit(id) {
        
    this.showLoading();
    
    try {
        // Get product unit data
        const productUnit = this.state.productUnits.find(pu => pu.id === parseInt(id));
        
        if (!productUnit) {
            // If not in state, fetch from API
            const response = await this.apiCall('GET', `${this.config.apiEndpoints.productUnits}?id=${id}`);
            if (!response || response.length === 0) {
                throw new Error('Product unit not found');
            }
            productUnit = response[0];
        }

        // Show edit modal
        this.showEditProductUnitModal(productUnit);
        
    } catch (error) {
        console.error('Error loading product unit for edit:', error);
        this.showError('Eroare la încărcarea datelor pentru editare');
    } finally {
        this.hideLoading();
    }
},

showEditProductUnitModal(productUnit) {
    // Create modal if it doesn't exist
    if (!document.getElementById('editProductUnitModal')) {
        this.createEditProductUnitModal();
    }
    
    // Populate form
    document.getElementById('edit-product-unit-id').value = productUnit.id;
    document.getElementById('edit-weight-per-unit').value = productUnit.weight_per_unit;
    document.getElementById('edit-volume-per-unit').value = productUnit.volume_per_unit || '';
    document.getElementById('edit-dimensions-length').value = productUnit.dimensions?.length || '';
    document.getElementById('edit-dimensions-width').value = productUnit.dimensions?.width || '';
    document.getElementById('edit-dimensions-height').value = productUnit.dimensions?.height || '';
    document.getElementById('edit-max-stack-height').value = productUnit.max_stack_height || 1;
    document.getElementById('edit-packaging-cost').value = productUnit.packaging_cost || 0;
    
    // Checkboxes
    document.getElementById('edit-fragile').checked = productUnit.fragile || false;
    document.getElementById('edit-hazardous').checked = productUnit.hazardous || false;
    document.getElementById('edit-temperature-controlled').checked = productUnit.temperature_controlled || false;
    document.getElementById('edit-active').checked = productUnit.active !== false;
    
    // Show product info
    document.getElementById('edit-product-info').textContent = 
        `${productUnit.product_name} (${productUnit.product_code}) - ${productUnit.unit_name}`;
    
    // Show modal
    document.getElementById('editProductUnitModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
},

createEditProductUnitModal() {
    const modalHtml = `
        <div class="modal" id="editProductUnitModal" style="display: none;">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><span class="material-symbols-outlined">edit</span> Editează Configurare Produs</h3>
                        <button class="modal-close" onclick="ProductUnitsApp.closeEditModal()">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                    <form id="editProductUnitForm" onsubmit="ProductUnitsApp.handleEditSubmit(event)">
                        <div class="modal-body">
                            <input type="hidden" id="edit-product-unit-id" name="id">
                            
                            <div class="form-group">
                                <label>Produs</label>
                                <div class="form-info" id="edit-product-info"></div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit-weight-per-unit">Greutate per unitate <span class="required">*</span></label>
                                    <div class="input-group">
                                        <input type="number" id="edit-weight-per-unit" name="weight_per_unit" 
                                               step="0.001" min="0" class="form-control" required>
                                        <span class="input-group-text">kg</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="edit-volume-per-unit">Volum per unitate</label>
                                    <div class="input-group">
                                        <input type="number" id="edit-volume-per-unit" name="volume_per_unit" 
                                               step="0.001" min="0" class="form-control">
                                        <span class="input-group-text">L</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit-dimensions-length">Lungime</label>
                                    <div class="input-group">
                                        <input type="number" id="edit-dimensions-length" name="dimensions_length" 
                                               step="0.1" min="0" class="form-control">
                                        <span class="input-group-text">cm</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="edit-dimensions-width">Lățime</label>
                                    <div class="input-group">
                                        <input type="number" id="edit-dimensions-width" name="dimensions_width" 
                                               step="0.1" min="0" class="form-control">
                                        <span class="input-group-text">cm</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="edit-dimensions-height">Înălțime</label>
                                    <div class="input-group">
                                        <input type="number" id="edit-dimensions-height" name="dimensions_height" 
                                               step="0.1" min="0" class="form-control">
                                        <span class="input-group-text">cm</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit-max-stack-height">Înălțime maximă stivă</label>
                                    <input type="number" id="edit-max-stack-height" name="max_stack_height" 
                                           min="1" max="50" class="form-control" value="1">
                                </div>
                                <div class="form-group">
                                    <label for="edit-packaging-cost">Cost ambalare</label>
                                    <div class="input-group">
                                        <input type="number" id="edit-packaging-cost" name="packaging_cost" 
                                               step="0.01" min="0" class="form-control">
                                        <span class="input-group-text">RON</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <h4>Proprietăți Speciale</h4>
                                <div class="checkbox-grid">
                                    <label class="form-check">
                                        <input type="checkbox" id="edit-fragile" name="fragile">
                                        <span class="checkmark"></span>
                                        <span class="check-label">
                                            <span class="material-symbols-outlined">warning</span>
                                            Fragil
                                        </span>
                                    </label>
                                    <label class="form-check">
                                        <input type="checkbox" id="edit-hazardous" name="hazardous">
                                        <span class="checkmark"></span>
                                        <span class="check-label">
                                            <span class="material-symbols-outlined">dangerous</span>
                                            Periculos
                                        </span>
                                    </label>
                                    <label class="form-check">
                                        <input type="checkbox" id="edit-temperature-controlled" name="temperature_controlled">
                                        <span class="checkmark"></span>
                                        <span class="check-label">
                                            <span class="material-symbols-outlined">thermostat</span>
                                            Controlat termic
                                        </span>
                                    </label>
                                    <label class="form-check">
                                        <input type="checkbox" id="edit-active" name="active">
                                        <span class="checkmark"></span>
                                        <span class="check-label">
                                            <span class="material-symbols-outlined">check_circle</span>
                                            Activ
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="ProductUnitsApp.closeEditModal()">Anulează</button>
                            <button type="submit" class="btn btn-primary">
                                <span class="material-symbols-outlined">save</span>
                                Actualizează
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
},

async handleEditSubmit(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    const data = {
        id: formData.get('id'),
        weight_per_unit: formData.get('weight_per_unit'),
        volume_per_unit: formData.get('volume_per_unit') || null,
        dimensions_length: formData.get('dimensions_length') || null,
        dimensions_width: formData.get('dimensions_width') || null,
        dimensions_height: formData.get('dimensions_height') || null,
        max_stack_height: formData.get('max_stack_height') || 1,
        packaging_cost: formData.get('packaging_cost') || 0,
        fragile: formData.has('fragile'),
        hazardous: formData.has('hazardous'),
        temperature_controlled: formData.has('temperature_controlled'),
        active: formData.has('active')
    };

    if (!data.weight_per_unit) {
        this.showError('Greutatea per unitate este obligatorie');
        return;
    }

    this.showLoading();

    try {
        const response = await this.apiCall('PUT', this.config.apiEndpoints.productUnits, data);
        
        if (response.success) {
            this.showSuccess('Configurarea a fost actualizată cu succes!');
            this.closeEditModal();
            await this.loadProductUnits();
            await this.loadStatistics();
        } else {
            throw new Error(response.error || 'Eroare la actualizarea configurării');
        }
    } catch (error) {
        console.error('Error updating product unit:', error);
        this.showError(error.message || 'Eroare la actualizarea configurării');
    } finally {
        this.hideLoading();
    }
},

closeEditModal() {
    const modal = document.getElementById('editProductUnitModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        // Reset form
        const form = modal.querySelector('form');
        if (form) form.reset();
    }
},

// ===== MISSING UNIT TYPES EDIT FUNCTIONALITY =====
async editUnitType(id) {
    console.log(`Editing unit type: ${id}`);
    
    try {
        this.showLoading();
        
        // Get unit type data from API
        const response = await this.apiCall('GET', `${this.config.apiEndpoints.unitTypes || baseUrl + '/api/unit_types.php'}?id=${id}`);
        const unitType = Array.isArray(response) ? response.find(ut => ut.id === parseInt(id)) : response;
        
        if (!unitType) {
            throw new Error('Unit type not found');
        }
        
        this.showEditUnitTypeModal(unitType);
        
    } catch (error) {
        console.error('Error loading unit type:', error);
        this.showError('Eroare la încărcarea tipului de unitate pentru editare');
    } finally {
        this.hideLoading();
    }
},

showEditUnitTypeModal(unitType) {
    // Create modal if it doesn't exist
    if (!document.getElementById('editUnitTypeModal')) {
        this.createEditUnitTypeModal();
    }
    
    // Populate form
    document.getElementById('edit-unit-type-id').value = unitType.id;
    document.getElementById('edit-unit-code').value = unitType.unit_code;
    document.getElementById('edit-unit-name').value = unitType.unit_name;
    document.getElementById('edit-base-type').value = unitType.base_type;
    document.getElementById('edit-default-weight').value = unitType.default_weight_per_unit;
    document.getElementById('edit-packaging-type').value = unitType.packaging_type || 'standard';
    document.getElementById('edit-max-items-per-parcel').value = unitType.max_items_per_parcel || '';
    document.getElementById('edit-requires-separate-parcel').checked = unitType.requires_separate_parcel || false;
    document.getElementById('edit-unit-active').checked = unitType.active !== false;
    
    // Show modal
    document.getElementById('editUnitTypeModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
},

createEditUnitTypeModal() {
    const modalHtml = `
        <div class="modal" id="editUnitTypeModal" style="display: none;">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><span class="material-symbols-outlined">edit</span> Editează Tip Unitate</h3>
                        <button class="modal-close" onclick="ProductUnitsApp.closeEditUnitTypeModal()">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                    <form id="editUnitTypeForm" onsubmit="ProductUnitsApp.handleEditUnitTypeSubmit(event)">
                        <div class="modal-body">
                            <input type="hidden" id="edit-unit-type-id" name="id">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit-unit-code">Cod Unitate</label>
                                    <input type="text" id="edit-unit-code" name="unit_code" class="form-control" readonly>
                                    <small class="form-help">Codul unității nu poate fi modificat</small>
                                </div>
                                <div class="form-group">
                                    <label for="edit-unit-name">Numele Unității <span class="required">*</span></label>
                                    <input type="text" id="edit-unit-name" name="unit_name" class="form-control" required maxlength="50">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit-base-type">Tip de Bază <span class="required">*</span></label>
                                    <select id="edit-base-type" name="base_type" class="form-control" required>
                                        <option value="">Selectează tip</option>
                                        <option value="weight">Greutate</option>
                                        <option value="volume">Volum</option>
                                        <option value="count">Număr</option>
                                        <option value="length">Lungime</option>
                                        <option value="area">Suprafață</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="edit-default-weight">Greutate Implicită <span class="required">*</span></label>
                                    <div class="input-group">
                                        <input type="number" id="edit-default-weight" name="default_weight_per_unit" 
                                               step="0.001" min="0" class="form-control" required>
                                        <span class="input-group-text">kg</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit-packaging-type">Tip Ambalare</label>
                                    <select id="edit-packaging-type" name="packaging_type" class="form-control">
                                        <option value="standard">Standard</option>
                                        <option value="fragile">Fragil</option>
                                        <option value="liquid">Lichid</option>
                                        <option value="bulk">Vrac</option>
                                        <option value="custom">Personalizat</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="edit-max-items-per-parcel">Maxim pe Colet</label>
                                    <input type="number" id="edit-max-items-per-parcel" name="max_items_per_parcel" 
                                           min="1" max="1000" class="form-control">
                                    <small class="form-help">Lasă gol pentru nelimitat</small>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <h4>Proprietăți Speciale</h4>
                                <div class="checkbox-grid">
                                    <label class="form-check">
                                        <input type="checkbox" id="edit-requires-separate-parcel" name="requires_separate_parcel">
                                        <span class="checkmark"></span>
                                        <span class="check-label">
                                            <span class="material-symbols-outlined">package_2</span>
                                            Necesită colet separat
                                        </span>
                                    </label>
                                    <label class="form-check">
                                        <input type="checkbox" id="edit-unit-active" name="active">
                                        <span class="checkmark"></span>
                                        <span class="check-label">
                                            <span class="material-symbols-outlined">check_circle</span>
                                            Activ
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="ProductUnitsApp.closeEditUnitTypeModal()">Anulează</button>
                            <button type="submit" class="btn btn-primary">
                                <span class="material-symbols-outlined">save</span>
                                Actualizează
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
},

async handleEditUnitTypeSubmit(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    const data = {
        id: formData.get('id'),
        unit_name: formData.get('unit_name'),
        base_type: formData.get('base_type'),
        default_weight_per_unit: formData.get('default_weight_per_unit'),
        packaging_type: formData.get('packaging_type'),
        max_items_per_parcel: formData.get('max_items_per_parcel') || null,
        requires_separate_parcel: formData.has('requires_separate_parcel'),
        active: formData.has('active')
    };

    if (!data.unit_name || !data.base_type) {
        this.showError('Numele unității și tipul de bază sunt obligatorii');
        return;
    }

    this.showLoading();

    try {
        const response = await this.apiCall('PUT', this.config.apiEndpoints.unitTypes || baseUrl + '/api/unit_types.php', data);
        
        if (response.success) {
            this.showSuccess('Tipul de unitate a fost actualizat cu succes!');
            this.closeEditUnitTypeModal();
            // Refresh the unit types tab if it's active
            if (this.state.currentTab === 'unit-types') {
                await this.loadUnitTypes();
            }
        } else {
            throw new Error(response.error || 'Eroare la actualizarea tipului de unitate');
        }
    } catch (error) {
        console.error('Error updating unit type:', error);
        this.showError(error.message || 'Eroare la actualizarea tipului de unitate');
    } finally {
        this.hideLoading();
    }
},

closeEditUnitTypeModal() {
    const modal = document.getElementById('editUnitTypeModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        const form = modal.querySelector('form');
        if (form) form.reset();
    }
},

// ===== MISSING PACKAGING RULES EDIT/DELETE FUNCTIONALITY =====
async editPackagingRule(id) {
    console.log(`Editing packaging rule: ${id}`);
    
    try {
        this.showLoading();
        
        // Get packaging rule data from API
        const response = await this.apiCall('GET', `${this.config.apiEndpoints.packagingRules || baseUrl + '/api/packaging_rules.php'}?id=${id}`);
        const packagingRule = Array.isArray(response) ? response.find(pr => pr.id === parseInt(id)) : response;
        
        if (!packagingRule) {
            throw new Error('Packaging rule not found');
        }
        
        this.showEditPackagingRuleModal(packagingRule);
        
    } catch (error) {
        console.error('Error loading packaging rule:', error);
        this.showError('Eroare la încărcarea regulii de ambalare pentru editare');
    } finally {
        this.hideLoading();
    }
},

async deletePackagingRule(id, ruleName) {
    if (!confirm(`Sunteți sigur că doriți să ștergeți regula "${ruleName}"?`)) {
        return;
    }

    this.showLoading();

    try {
        const response = await this.apiCall('DELETE', `${this.config.apiEndpoints.packagingRules || baseUrl + '/api/packaging_rules.php'}?id=${id}`);
        
        if (response.success) {
            this.showSuccess('Regula de ambalare a fost ștearsă cu succes!');
            // Refresh the packaging rules tab if it's active
            if (this.state.currentTab === 'packaging-rules') {
                await this.loadPackagingRules();
            }
        } else {
            throw new Error(response.error || 'Eroare la ștergerea regulii de ambalare');
        }
    } catch (error) {
        console.error('Error deleting packaging rule:', error);
        this.showError(error.message || 'Eroare la ștergerea regulii de ambalare');
    } finally {
        this.hideLoading();
    }
},

// ===== UTILITY FUNCTIONS TO ADD =====
async apiCall(method, url, data = null) {
    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    };

    if (data && (method === 'POST' || method === 'PUT')) {
        options.body = JSON.stringify(data);
    }

    const response = await fetch(url, options);
    
    if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
    }

    return await response.json();
},

showLoading() {
    // Add loading indicator if it doesn't exist
    if (!document.getElementById('loadingIndicator')) {
        const loadingHtml = `
            <div id="loadingIndicator" class="loading-overlay" style="display: none;">
                <div class="loading-content">
                    <div class="loading-spinner"></div>
                    <p>Se încarcă...</p>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', loadingHtml);
    }
    
    document.getElementById('loadingIndicator').style.display = 'flex';
},

hideLoading() {
    const loading = document.getElementById('loadingIndicator');
    if (loading) {
        loading.style.display = 'none';
    }
},

showSuccess(message) {
    this.showNotification(message, 'success');
},

showError(message) {
    this.showNotification(message, 'error');
},

    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <span class="material-symbols-outlined">
            ${type === 'success' ? 'check_circle' : 
              type === 'error' ? 'error' : 'info'}
        </span>
        <span>${message}</span>
        <button class="notification-close" onclick="this.parentElement.remove()">
            <span class="material-symbols-outlined">close</span>
        </button>
    `;

    if (!document.getElementById('notificationContainer')) {
        const container = document.createElement('div');
        container.id = 'notificationContainer';
        container.className = 'notification-container';
        document.body.appendChild(container);
    }

    document.getElementById('notificationContainer').appendChild(notification);

    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
    },

    // ===== LABEL MANAGEMENT FUNCTIONS =====
    async loadLabelTemplates() {
        if (!this.elements.labelTableBody) return;
        this.elements.labelTableBody.innerHTML = `
            <tr class="loading-row"><td colspan="4" class="text-center">
                <div class="loading-spinner">
                    <span class="material-symbols-outlined spinning">progress_activity</span>
                    Încărcare date...
                </div>
            </td></tr>`;
        try {
            const params = new URLSearchParams({
                limit: this.state.labelPagination.limit,
                offset: this.state.labelPagination.offset
            });
            if (this.state.labelFilters.search) params.append('search', this.state.labelFilters.search);
            if (this.state.labelFilters.status) params.append('status', this.state.labelFilters.status);
            const url = `${this.config.apiEndpoints.labelTemplates}?${params.toString()}`;
            const response = await this.apiCall('GET', url);
            const data = response.data || response;
            this.state.labelTemplates = data.products || [];
            this.state.labelStats = data.stats || { total:0, with_label:0, without_label:0 };
            this.state.labelPagination.total = data.total || 0;
            this.state.labelPagination.has_next = data.pagination?.has_next || false;
            this.renderLabelTemplates();
            this.renderLabelPagination();
            this.updateStatistic('productsWithLabels', this.state.labelStats.with_label);
            this.updateStatistic('productsWithoutLabels', this.state.labelStats.without_label);
        } catch (error) {
            console.error('Error loading label templates:', error);
            this.elements.labelTableBody.innerHTML = '<tr><td colspan="4" class="text-center">Eroare la încărcare</td></tr>';
        }
    },

    renderLabelTemplates() {
        if (!this.elements.labelTableBody) return;
        const tbody = this.elements.labelTableBody;
        tbody.innerHTML = '';
        if (this.state.labelTemplates.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center">Niciun produs găsit</td></tr>';
            if (this.elements.labelTableResults) {
                this.elements.labelTableResults.textContent = '0 rezultate';
            }
            return;
        }
        this.state.labelTemplates.forEach(prod => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${this.escapeHtml(prod.name)}</td>
                <td>${this.escapeHtml(prod.sku)}</td>
                <td>${prod.has_label ? '<span class="status-active">Da</span>' : '<span class="status-inactive">Nu</span>'}</td>
                <td>
                    ${prod.has_label ? `<a href="${baseUrl}/storage/templates/product_labels/${prod.template}" target="_blank" class="btn btn-sm btn-secondary" data-action="preview" data-id="${prod.product_id}"><span class="material-symbols-outlined">visibility</span></a>` : ''}
                    <label class="btn btn-sm btn-primary" for="file-${prod.product_id}" data-action="upload" data-id="${prod.product_id}">
                        <span class="material-symbols-outlined">${prod.has_label ? 'upload' : 'add'}</span>
                    </label>
                    <input type="file" id="file-${prod.product_id}" data-id="${prod.product_id}" class="label-file-input" style="display:none" accept="image/png">
                    ${prod.has_label ? `<button class="btn btn-sm btn-danger" data-action="delete" data-id="${prod.product_id}"><span class="material-symbols-outlined">delete</span></button>` : ''}
                </td>`;
            tbody.appendChild(tr);
        });
        if (this.elements.labelTableResults) {
            const { offset, limit, total } = this.state.labelPagination;
            const start = total === 0 ? 0 : offset + 1;
            const end = Math.min(offset + limit, total);
            this.elements.labelTableResults.textContent = `Afișare ${start}-${end} din ${total}`;
        }
    },

    renderLabelPagination() {
        if (!this.elements.labelPagination) return;
        const { limit, offset, total, has_next } = this.state.labelPagination;
        if (total === 0) {
            this.elements.labelPagination.innerHTML = '';
            return;
        }
        const start = total === 0 ? 0 : offset + 1;
        const end = Math.min(offset + limit, total);
        const prevDisabled = offset === 0 ? 'disabled' : '';
        const nextDisabled = !has_next ? 'disabled' : '';
        this.elements.labelPagination.innerHTML = `
            <div class="pagination-info">Afișare ${start}-${end} din ${total}</div>
            <div class="pagination-controls">
                <button class="btn btn-secondary prev-page" ${prevDisabled}>Anterior</button>
                <button class="btn btn-secondary next-page" ${nextDisabled}>Următor</button>
            </div>
        `;
    },

    async uploadLabelTemplate(id, file, reload = true) {
        const formData = new FormData();
        formData.append('product_id', id);
        formData.append('template', file);
        try {
            const response = await fetch(this.config.apiEndpoints.labelTemplates, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                }
            });
            if (!response.ok) {
                throw new Error('Upload failed');
            }
            await response.json();
            if (reload) {
                this.loadLabelTemplates();
            }
        } catch (error) {
            console.error('Error uploading template:', error);
            this.showError('Eroare la încărcarea șablonului');
        }
    },

    async deleteLabelTemplate(id) {
        try {
            const response = await fetch(this.config.apiEndpoints.labelTemplates, {
                method: 'DELETE',
                body: new URLSearchParams({ product_id: id }).toString(),
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                },
                credentials: 'same-origin'
            });
            if (!response.ok) {
                throw new Error('Delete failed');
            }
            await response.json();
            this.loadLabelTemplates();
        } catch (error) {
            console.error('Error deleting template:', error);
            this.showError('Eroare la ștergerea șablonului');
        }
    },

    // ===== STOCK MANAGEMENT FUNCTIONS =====
    async loadStockSettings() {
        if (!this.elements.stockSettingsBody) return;
        this.elements.stockSettingsBody.innerHTML = `
            <tr class="loading-row"><td colspan="8" class="text-center">
                <div class="loading-spinner">
                    <span class="material-symbols-outlined spinning">progress_activity</span>
                    Încărcare date...
                </div>
            </td></tr>`;
        try {
            const params = new URLSearchParams({
                search: this.state.stockSearch,
                limit: this.state.stockPagination.limit,
                offset: this.state.stockPagination.offset
            });
            if (this.state.stockCategory) {
                params.append('category', this.state.stockCategory);
            }
            if (this.state.stockSeller) {
                params.append('seller', this.state.stockSeller);
            }
            const url = `${this.config.apiEndpoints.stockSettings}?${params.toString()}`;
            const response = await this.apiCall('GET', url);
            this.state.stockSettings = response.data || [];
            this.state.stockPagination.total = response.total || 0;
            this.state.stockPagination.has_next = response.pagination?.has_next || false;
            this.renderStockSettingsTable();
            this.renderStockPagination();
        } catch (error) {
            console.error('Error loading stock settings:', error);
            this.elements.stockSettingsBody.innerHTML = '<tr><td colspan="8" class="text-center">Eroare la încărcare</td></tr>';
            if (this.elements.stockPagination) {
                this.elements.stockPagination.innerHTML = '';
            }
        }
    },

    renderStockSettingsTable() {
        if (!this.elements.stockSettingsBody) return;
        const tbody = this.elements.stockSettingsBody;
        if (!this.state.stockSettings.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center">Nu există setări</td></tr>';
            return;
        }
        tbody.innerHTML = this.state.stockSettings.map(s => `
            <tr>
                <td><strong>${this.escapeHtml(s.product_name)}</strong><br><small>${this.escapeHtml(s.sku)}</small></td>
                <td>${this.escapeHtml(s.supplier_name || 'Neasignat')}</td>
                <td>${s.current_stock}</td>
                <td>${s.min_stock_level}</td>
                <td>${s.min_order_quantity}</td>
                <td>${s.auto_order_enabled ? '<span class="badge badge-success">Activă</span>' : '<span class="badge badge-secondary">Inactivă</span>'}</td>
                <td>${s.last_auto_order_date || '-'}</td>
                <td><button class="btn btn-sm btn-secondary" onclick="ProductUnitsApp.editStockSetting(${s.product_id})"><span class="material-symbols-outlined">edit</span></button></td>
            </tr>
        `).join('');
    },

    renderStockPagination() {
        if (!this.elements.stockPagination) return;
        const { limit, offset, total, has_next } = this.state.stockPagination;
        if (total === 0) {
            this.elements.stockPagination.innerHTML = '';
            return;
        }
        const start = total === 0 ? 0 : offset + 1;
        const end = Math.min(offset + limit, total);
        const prevDisabled = offset === 0 ? 'disabled' : '';
        const nextDisabled = !has_next ? 'disabled' : '';
        this.elements.stockPagination.innerHTML = `
            <div class="pagination-info">Afișare ${start}-${end} din ${total}</div>
            <div class="pagination-controls">
                <button class="btn btn-secondary prev-page" ${prevDisabled}>Anterior</button>
                <button class="btn btn-secondary next-page" ${nextDisabled}>Următor</button>
            </div>
        `;
    },

    openStockModal(productId = null) {
        if (!this.elements.stockSettingsModal) return;
        this.elements.stockSettingsForm.reset();

        if (this.state.products.length === 0) {
            this.loadProducts();
        }
        if (this.elements.stockProductId) this.elements.stockProductId.value = '';
        if (this.elements.stockProductSearch) this.elements.stockProductSearch.value = '';
        if (this.elements.stockProductResults) this.elements.stockProductResults.innerHTML = '';

        if (productId) {
            const prod = this.state.products.find(p => p.id == productId);
            if (prod) {
                this.selectProduct(prod.id, prod.name, 'stockProduct');
            }
        }

        this.elements.stockSettingsModal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    },

    closeStockModal() {
        if (!this.elements.stockSettingsModal) return;
        this.elements.stockSettingsModal.style.display = 'none';
        document.body.style.overflow = '';
        if (this.elements.stockProductId) this.elements.stockProductId.value = '';
        if (this.elements.stockProductSearch) this.elements.stockProductSearch.value = '';
        if (this.elements.stockProductResults) this.elements.stockProductResults.innerHTML = '';
    },

    async saveStockSettings() {
        const data = {
            product_id: this.elements.stockProductId.value,
            min_stock_level: this.elements.minStockLevel.value,
            min_order_quantity: this.elements.minOrderQty.value,
            auto_order_enabled: this.elements.autoOrderEnabled.checked
        };
        try {
            const response = await this.apiCall('POST', this.config.apiEndpoints.stockSettings, data);
            if (response.success) {
                this.closeStockModal();
                this.loadStockSettings();
                this.showSuccess('Setările au fost salvate');
            } else {
                throw new Error(response.error || 'Eroare la salvare');
            }
        } catch (e) {
            console.error('saveStockSettings error', e);
            this.showError(e.message);
        }
    },

    editStockSetting(productId) {
        const setting = this.state.stockSettings.find(s => s.product_id === productId);
        if (!setting) return;
        this.openStockModal();
        const prod = this.state.products.find(p => p.id == productId);
        if (prod) {
            this.selectProduct(prod.id, prod.name, 'stockProduct');
        }
        this.elements.minStockLevel.value = setting.min_stock_level;
        this.elements.minOrderQty.value = setting.min_order_quantity;
        this.elements.autoOrderEnabled.checked = setting.auto_order_enabled;
        this.elements.assignedSupplier.textContent = setting.supplier_name || 'Neasignat';
        this.elements.currentStockInfo.textContent = setting.current_stock;
    },
openPendingProductsModal() {
        if (!this.elements.pendingProductsModal) return;
        if (this.state.products.length === 0) {
            this.loadProducts();
        }
        const pending = this.state.products.filter(p => p.configured_units === 0);
        this.state.pendingList = pending;
        this.state.pendingPagination.total = pending.length;
        this.state.pendingPagination.offset = 0;
        this.state.pendingSearch = '';
        if (this.elements.pendingSearchInput) this.elements.pendingSearchInput.value = '';

        this.renderPendingProductsTable();
        this.renderPendingPagination();
        this.elements.pendingProductsModal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    },

    closePendingProductsModal() {
        if (!this.elements.pendingProductsModal) return;
        this.elements.pendingProductsModal.style.display = 'none';
        document.body.style.overflow = '';
        if (this.elements.pendingProductsList) {
            this.elements.pendingProductsList.innerHTML = '';
        }
        this.state.pendingPagination.offset = 0;
        this.state.pendingPagination.total = 0;
        this.state.pendingSearch = '';
        if (this.elements.pendingSearchInput) this.elements.pendingSearchInput.value = '';

        if (this.elements.pendingPagination) {
            this.elements.pendingPagination.innerHTML = '';
        }
    },

    renderPendingProductsTable() {
        if (!this.elements.pendingProductsList) return;
        const { offset, limit } = this.state.pendingPagination;
        let filtered = this.state.pendingList;
        if (this.state.pendingSearch) {
            const search = this.state.pendingSearch;
            filtered = filtered.filter(p =>
                p.name.toLowerCase().includes(search)
            );
        }
        this.state.pendingPagination.total = filtered.length;
        const slice = filtered.slice(offset, offset + limit);
        if (slice.length === 0) {
            this.elements.pendingProductsList.innerHTML = '<tr><td colspan="3" class="text-center">Toate produsele sunt configurate</td></tr>';
            return;
        }
        this.elements.pendingProductsList.innerHTML = slice.map(p => `
            <tr>
                <td>${this.escapeHtml(p.name)}</td>
                <td>${this.escapeHtml(p.code)}</td>
                <td><button class="btn btn-sm btn-primary" onclick="ProductUnitsApp.openModal(${p.id})">Configurează</button></td>
            </tr>
        `).join('');
    },

    renderPendingPagination() {
        if (!this.elements.pendingPagination) return;
        const { limit, offset, total } = this.state.pendingPagination;
        if (total <= limit) {
            this.elements.pendingPagination.innerHTML = '';
            return;
        }
        const start = total === 0 ? 0 : offset + 1;
        const end = Math.min(offset + limit, total);
        const prevDisabled = offset === 0 ? 'disabled' : '';
        const nextDisabled = offset + limit >= total ? 'disabled' : '';
        this.elements.pendingPagination.innerHTML = `
            <div class="pagination-info">Afișare ${start}-${end} din ${total}</div>
            <div class="pagination-controls">
                <button class="btn btn-secondary prev-page" ${prevDisabled}>Anterior</button>
                <button class="btn btn-secondary next-page" ${nextDisabled}>Următor</button>
            </div>
        `;
    },

};
// ===== INITIALIZATION =====
document.addEventListener('DOMContentLoaded', () => {
    ProductUnitsApp.init();
});

// Export for global access (if needed)
window.ProductUnitsApp = ProductUnitsApp;
window.editProductUnit = (id) => ProductUnitsApp.editProductUnit(id);
window.editUnitType = (id) => ProductUnitsApp.editUnitType(id);
window.editPackagingRule = (id) => ProductUnitsApp.editPackagingRule(id);
window.deletePackagingRule = (id, name) => ProductUnitsApp.deletePackagingRule(id, name);