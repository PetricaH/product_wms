/**
 * Product Units Management JavaScript
 * File: scripts/product-units.js
 * 
 * Handles all functionality for the product units admin interface
 * Following WMS design patterns and existing JavaScript structure
 */

'use strict';

const baseUrl = window.APP_CONFIG?.baseUrl || '';

const MESSAGES = {
    templateSaved: 'Template salvat cu succes',
    templateLoadError: 'Eroare la încărcarea template-ului',
    testEmailSent: 'Email test trimis cu succes',
    validationFailed: 'Template conține erori de validare',
    autoSaved: 'Salvare automată completă',
    unsavedChanges: 'Aveți modificări nesalvate'
};

const AUTO_ORDER_UI_TEXT = {
    statuses: {
        enabled: 'Autocomandă activă',
        disabled: 'Autocomandă dezactivată',
        belowMinimum: 'Stoc sub pragul minim',
        ready: 'Pregătit pentru autocomandă',
        lastOrder: (value) => `Ultima autocomandă: ${value}`,
        noSupplier: 'Fără furnizor asignat',
        noPrice: 'Preț lipsă pentru articol'
    },
    actions: {
        test: 'Testează autocomanda',
        configure: 'Configurează'
    }
};

const EMAIL_TEMPLATE_REQUIRED_VARIABLES = ['COMPANY_NAME', 'SUPPLIER_NAME', 'SUPPLIER_EMAIL', 'ORDER_NUMBER', 'PRODUCT_NAME', 'ORDER_QUANTITY'];
const EMAIL_TEMPLATE_RECOMMENDED_VARIABLES = ['COMPANY_ADDRESS', 'COMPANY_PHONE', 'COMPANY_EMAIL', 'DELIVERY_DATE', 'ORDER_TOTAL', 'UNIT_PRICE', 'TOTAL_PRICE', 'UNIT_MEASURE', 'CURRENT_DATE', 'CURRENT_TIME', 'SUPPLIER_PHONE', 'PRODUCT_CODE'];

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
            sellerSearch: `${baseUrl}/api/seller_search.php`,
            autoOrderTest: `${baseUrl}/api/auto_order_test.php`,
            autoOrderDashboard: `${baseUrl}/api/auto_orders/dashboard.php`,
            autoOrderHistory: `${baseUrl}/api/auto_orders/history.php`,
            barrelDimensions: `${baseUrl}/api/barrel_dimensions.php`,
            labelTemplates: `${baseUrl}/api/label_templates.php`,
            emailTemplates: `${baseUrl}/api/email_templates.php`
        },
        debounceDelay: 300,
        refreshInterval: 30000, // 30 seconds
        maxRetries: 3,
        localStorageKeys: {
            autoOrderEmailDraft: 'wmsAutoOrderEmailDraft'
        }
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
        sellerSearchController: null,
        barrelDimensions: [],
        filteredData: [],
        isLoading: false,
        filters: {
            product: '',
            unit: '',
            status: ''
        },
        selectedUnits: new Set(),
        labelTemplates: [],
        labelFilters: { search: '', status: '' },
        labelStats: { total: 0, with_label: 0, without_label: 0 },
        labelPagination: { limit: 50, offset: 0, total: 0, has_next: false },
        emailTemplate: {
            templateId: null,
            templateName: 'Șablon autocomandă personalizat',
            subject: '',
            body: '',
            isActive: true,
            isDefault: false,
            updatedAt: null,
            createdAt: null,
            createdBy: null,
            updatedByName: '',
            variablesUsed: [],
            missingRequired: [],
            missingRecommended: [],
            validation: { errors: [], warnings: [], success: [] },
            sampleData: {},
            availableVariables: {},
            history: [],
            autoSaveStatus: 'idle',
            unsavedChanges: false,
            draftSavedAt: null,
            testRecipient: ''
        },
        autoOrderTest: {
            isOpen: false,
            productId: null,
            productName: '',
            validations: [],
            emailPreview: { subject: '', body: '', missingVariables: [] },
            simulation: {},
            lastRun: null
        }
    },

    // DOM elements cache
    elements: {},

    // Initialize the application
    init() {
        this.cacheElements();
        this.enhanceEmailTemplateBuilderUI();
        this.setupBeforeUnloadHandler();
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
            selectAllUnits: document.getElementById('selectAllUnits'),
            unitBulkActionsBar: document.getElementById('unitBulkActionsBar'),
            selectedUnitsCount: document.getElementById('selectedUnitsCount'),
            bulkPropertySelect: document.getElementById('bulkPropertySelect'),
            applyBulkPropertyBtn: document.getElementById('applyBulkPropertyBtn'),
            bulkMaxValue: document.getElementById('bulkMaxValue'),
            applyBulkMaxBtn: document.getElementById('applyBulkMaxBtn'),
            bulkWeightValue: document.getElementById('bulkWeightValue'),
            applyBulkWeightBtn: document.getElementById('applyBulkWeightBtn'),

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
            stockSellerId: document.getElementById('stockSellerId'),
            stockSellerSearch: document.getElementById('stockSellerSearch'),
            stockSellerResults: document.getElementById('stockSellerResults'),
            selectedSellerInfo: document.getElementById('selectedSellerInfo'),
            clearStockSeller: document.getElementById('clearStockSeller'),
            stockModalClose: document.querySelector('#stockSettingsModal .modal-close'),
            autoOrderEnabled: document.getElementById('autoOrderEnabled'),
            minStockLevel: document.getElementById('minStockLevel'),
            minOrderQty: document.getElementById('minOrderQty'),
            stockPriceRon: document.getElementById('stockPriceRon'),
            stockPriceEur: document.getElementById('stockPriceEur'),
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
            loadingOverlay: document.getElementById('loadingOverlay'),

            // Email template builder
            openEmailTemplateBuilder: document.getElementById('openEmailTemplateBuilder'),
            emailTemplateModal: document.getElementById('emailTemplateBuilderModal'),
            emailTemplateClose: document.querySelector('#emailTemplateBuilderModal .modal-close'),
            emailTemplateForm: document.getElementById('emailTemplateForm'),
            emailTemplateSubject: document.getElementById('autoOrderEmailSubject'),
            emailTemplateBody: document.getElementById('autoOrderEmailBody'),
            emailTemplateTestRecipient: document.getElementById('autoOrderTestRecipient'),
            emailTemplatePreview: document.getElementById('emailTemplatePreview'),
            loadEmailTemplateBtn: document.getElementById('loadEmailTemplate'),
            emailSubjectError: document.getElementById('emailSubjectError'),
            emailBodyError: document.getElementById('emailBodyError'),
            templateVariables: document.querySelectorAll('.template-variable')
        };

        // Aliases for generic handlers
        this.elements.productId = this.elements.productIdInput;
        this.elements.productSearch = this.elements.productSearchInput;
        this.elements.productResults = this.elements.productSearchResults;
    },

    enhanceEmailTemplateBuilderUI() {
        if (!this.elements.emailTemplateForm) {
            return;
        }

        const form = this.elements.emailTemplateForm;

        // Template name field
        if (!form.querySelector('#autoOrderTemplateName')) {
            const subjectGroup = form.querySelector('.form-group');
            if (subjectGroup) {
                const nameGroup = document.createElement('div');
                nameGroup.className = 'form-group';
                nameGroup.innerHTML = `
                    <label for="autoOrderTemplateName">Nume Template</label>
                    <input type="text" id="autoOrderTemplateName" name="auto_order_template_name" placeholder="Șablon autocomandă personalizat" autocomplete="off">
                    <small class="form-text" id="templateNameHint">Acest nume este utilizat pentru identificarea șabloanelor salvate.</small>
                `;
                form.insertBefore(nameGroup, subjectGroup);
            }
        }

        this.elements.emailTemplateName = document.getElementById('autoOrderTemplateName');

        // Metadata bar
        if (!document.getElementById('emailTemplateMetadata')) {
            const metadata = document.createElement('div');
            metadata.id = 'emailTemplateMetadata';
            metadata.className = 'template-metadata';
            metadata.innerHTML = `
                <div class="metadata-item" id="templateUpdatedAt">Ultima actualizare: -</div>
                <div class="metadata-item" id="templateUpdatedBy">Actualizat de: -</div>
                <div class="metadata-item" id="templateCreatedAt">Creat: -</div>
            `;
            form.prepend(metadata);
        }

        this.elements.templateUpdatedAt = document.getElementById('templateUpdatedAt');
        this.elements.templateUpdatedBy = document.getElementById('templateUpdatedBy');
        this.elements.templateCreatedAt = document.getElementById('templateCreatedAt');

        // Subject char count
        if (!document.getElementById('subjectCharCount') && this.elements.emailTemplateSubject) {
            const counter = document.createElement('div');
            counter.id = 'subjectCharCount';
            counter.className = 'char-count';
            counter.textContent = '0 caractere';
            this.elements.emailTemplateSubject.parentElement?.appendChild(counter);
        }
        this.elements.subjectCharCount = document.getElementById('subjectCharCount');

        // Unsaved indicator in header
        if (this.elements.emailTemplateModal) {
            const header = this.elements.emailTemplateModal.querySelector('.modal-header');
            if (header && !document.getElementById('unsavedChangesBadge')) {
                const badge = document.createElement('span');
                badge.id = 'unsavedChangesBadge';
                badge.className = 'unsaved-indicator';
                badge.textContent = MESSAGES.unsavedChanges;
                badge.style.display = 'none';
                header.appendChild(badge);
            }
        }
        this.elements.unsavedChangesBadge = document.getElementById('unsavedChangesBadge');

        // Auto save status indicator
        const actionsRight = form.querySelector('.template-actions-right');
        if (actionsRight && !document.getElementById('autoSaveStatus')) {
            const status = document.createElement('span');
            status.id = 'autoSaveStatus';
            status.className = 'autosave-status';
            status.textContent = '';
            actionsRight.insertBefore(status, actionsRight.firstChild);
        }
        this.elements.autoSaveStatus = document.getElementById('autoSaveStatus');

        // Test template button
        if (actionsRight && !document.getElementById('testEmailTemplate')) {
            const testButton = document.createElement('button');
            testButton.type = 'button';
            testButton.className = 'btn btn-secondary';
            testButton.id = 'testEmailTemplate';
            testButton.innerHTML = '<span class="material-symbols-outlined">send</span>Testează Template';
            actionsRight.appendChild(testButton);
        }
        this.elements.testEmailTemplateBtn = document.getElementById('testEmailTemplate');

        // Duplicate and delete buttons
        if (actionsRight && !document.getElementById('duplicateTemplate')) {
            const duplicateBtn = document.createElement('button');
            duplicateBtn.type = 'button';
            duplicateBtn.className = 'btn btn-ghost';
            duplicateBtn.id = 'duplicateTemplate';
            duplicateBtn.innerHTML = '<span class="material-symbols-outlined">content_copy</span>Duplică';
            actionsRight.appendChild(duplicateBtn);
        }
        if (actionsRight && !document.getElementById('deleteTemplate')) {
            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'btn btn-danger';
            deleteBtn.id = 'deleteTemplate';
            deleteBtn.innerHTML = '<span class="material-symbols-outlined">delete</span>Șterge';
            actionsRight.appendChild(deleteBtn);
        }
        this.elements.duplicateTemplateBtn = document.getElementById('duplicateTemplate');
        this.elements.deleteTemplateBtn = document.getElementById('deleteTemplate');

        // Saved template dropdown container
        if (this.elements.loadEmailTemplateBtn && !document.getElementById('savedTemplatesDropdown')) {
            const dropdownWrapper = document.createElement('div');
            dropdownWrapper.className = 'template-dropdown-wrapper';
            const dropdown = document.createElement('select');
            dropdown.id = 'savedTemplatesDropdown';
            dropdown.className = 'template-dropdown';
            dropdown.innerHTML = '<option value="">Selectează un template salvat</option>';
            dropdownWrapper.appendChild(dropdown);
            this.elements.loadEmailTemplateBtn.replaceWith(dropdownWrapper);
        }
        this.elements.loadTemplateDropdown = document.getElementById('savedTemplatesDropdown');

        // Validation list container
        if (!document.getElementById('templateValidationList')) {
            const previewSection = form.querySelector('.preview-section');
            if (previewSection) {
                const validationBox = document.createElement('div');
                validationBox.className = 'template-validation';
                validationBox.innerHTML = `
                    <div class="validation-header">Validări template</div>
                    <ul id="templateValidationList" class="validation-list"></ul>
                `;
                previewSection.appendChild(validationBox);
            }
        }
        this.elements.templateValidationList = document.getElementById('templateValidationList');

        // Missing variables preview area
        if (!document.getElementById('templatePreviewMissing')) {
            const preview = this.elements.emailTemplatePreview;
            if (preview) {
                const missing = document.createElement('div');
                missing.id = 'templatePreviewMissing';
                missing.className = 'preview-missing';
                preview.parentElement?.appendChild(missing);
            }
        }
        this.elements.templatePreviewMissing = document.getElementById('templatePreviewMissing');

        // History sidebar
        if (!document.getElementById('templateHistoryPanel')) {
            const modalBody = this.elements.emailTemplateModal?.querySelector('.email-template-body');
            if (modalBody) {
                const historyPanel = document.createElement('aside');
                historyPanel.id = 'templateHistoryPanel';
                historyPanel.className = 'template-history-panel';
                historyPanel.innerHTML = `
                    <h3>Istoric șabloane</h3>
                    <div id="templateHistoryEmpty" class="history-empty">Nu există alte șabloane salvate.</div>
                    <ul id="templateHistoryList" class="history-list"></ul>
                `;
                modalBody.appendChild(historyPanel);
            }
        }
        this.elements.templateHistoryPanel = document.getElementById('templateHistoryPanel');
        this.elements.templateHistoryList = document.getElementById('templateHistoryList');
        this.elements.templateHistoryEmpty = document.getElementById('templateHistoryEmpty');

        // Auto order test modal
        if (!document.getElementById('autoOrderTestResultsModal')) {
            const modal = document.createElement('div');
            modal.id = 'autoOrderTestResultsModal';
            modal.className = 'modal modal-large';
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 id="autoOrderTestTitle">Rezultat Test Autocomandă</h2>
                        <button type="button" class="modal-close" data-role="auto-order-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="test-results-container">
                            <div class="validation-section">
                                <h3>Verificări Validare</h3>
                                <div class="validation-list" id="validationResults"></div>
                            </div>
                            <div class="email-preview-section">
                                <h3>Previzualizare Email</h3>
                                <div class="email-preview-container">
                                    <div class="email-subject"><strong>Subiect:</strong> <span id="emailSubjectPreview"></span></div>
                                    <div class="email-body">
                                        <strong>Conținut:</strong>
                                        <div class="email-content" id="emailBodyPreview"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="simulation-section">
                                <h3>Rezumat Simulare</h3>
                                <div class="simulation-details" id="simulationSummary"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-role="auto-order-close">Închide</button>
                        <button type="button" class="btn btn-warning" id="sendAutoOrderTestEmail">Trimite email test</button>
                        <button type="button" class="btn btn-success" id="executeAutoOrderButton" style="display: none;">Execută autocomandă reală</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }
        this.elements.autoOrderTestModal = document.getElementById('autoOrderTestResultsModal');
        this.elements.autoOrderValidationList = document.getElementById('validationResults');
        this.elements.autoOrderEmailSubject = document.getElementById('emailSubjectPreview');
        this.elements.autoOrderEmailBody = document.getElementById('emailBodyPreview');
        this.elements.autoOrderSimulationSummary = document.getElementById('simulationSummary');
        this.elements.autoOrderTestTitle = document.getElementById('autoOrderTestTitle');
        this.elements.sendAutoOrderTestEmail = document.getElementById('sendAutoOrderTestEmail');
        this.elements.executeAutoOrderButton = document.getElementById('executeAutoOrderButton');
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

        if (this.elements.openEmailTemplateBuilder) {
            this.elements.openEmailTemplateBuilder.addEventListener('click', () => this.openEmailTemplateBuilder());
        }

        if (this.elements.emailTemplateClose) {
            this.elements.emailTemplateClose.addEventListener('click', () => this.closeEmailTemplateBuilder());
        }

        // Close modal when clicking outside
        if (this.elements.modal) {
            this.elements.modal.addEventListener('click', (e) => {
                if (e.target === this.elements.modal) {
                    this.closeModal();
                }
            });
        }

        if (this.elements.emailTemplateModal) {
            this.elements.emailTemplateModal.addEventListener('click', (e) => {
                if (e.target === this.elements.emailTemplateModal) {
                    this.closeEmailTemplateBuilder();
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

        if (this.elements.emailTemplateForm) {
            this.elements.emailTemplateForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveEmailTemplate();
            });
        }

        if (this.elements.emailTemplateSubject) {
            this.elements.emailTemplateSubject.addEventListener('input', () => this.handleEmailTemplateInput());
            this.elements.emailTemplateSubject.addEventListener('dragover', (e) => this.handleVariableDragOver(e));
            this.elements.emailTemplateSubject.addEventListener('drop', (e) => this.handleVariableDrop(e, this.elements.emailTemplateSubject));
        }

        if (this.elements.emailTemplateBody) {
            this.elements.emailTemplateBody.addEventListener('input', () => this.handleEmailTemplateInput());
            this.elements.emailTemplateBody.addEventListener('dragover', (e) => this.handleVariableDragOver(e));
            this.elements.emailTemplateBody.addEventListener('drop', (e) => this.handleVariableDrop(e, this.elements.emailTemplateBody));
        }

        if (this.elements.emailTemplateName) {
            this.elements.emailTemplateName.addEventListener('input', () => this.handleEmailTemplateInput());
        }

        if (this.elements.emailTemplateTestRecipient) {
            this.elements.emailTemplateTestRecipient.addEventListener('input', (event) => {
                const value = (event.target.value || '').trim();
                this.state.emailTemplate.testRecipient = value;
                event.target.classList.remove('input-error');
            });
        }

        if (this.elements.loadTemplateDropdown) {
            this.elements.loadTemplateDropdown.addEventListener('change', (e) => this.handleSavedTemplateSelection(e));
        }

        if (this.elements.testEmailTemplateBtn) {
            this.elements.testEmailTemplateBtn.addEventListener('click', () => this.testEmailTemplate());
        }

        if (this.elements.duplicateTemplateBtn) {
            this.elements.duplicateTemplateBtn.addEventListener('click', () => this.duplicateEmailTemplate());
        }

        if (this.elements.deleteTemplateBtn) {
            this.elements.deleteTemplateBtn.addEventListener('click', () => this.deleteEmailTemplate());
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
                } else if (e.target.closest('#emailTemplateBuilderModal')) {
                    this.closeEmailTemplateBuilder();
                } else {
                    this.closeModal();
                }
            }
        });

        if (this.elements.templateHistoryList) {
            this.elements.templateHistoryList.addEventListener('click', (event) => {
                const item = event.target.closest('[data-template-id]');
                if (!item) return;
                const templateId = parseInt(item.dataset.templateId, 10);
                if (Number.isNaN(templateId)) return;
                this.loadTemplateFromHistory(templateId);
            });
        }

        if (this.elements.autoOrderTestModal) {
            this.elements.autoOrderTestModal.addEventListener('click', (event) => {
                if (event.target === this.elements.autoOrderTestModal || event.target?.dataset?.role === 'auto-order-close' || event.target.closest?.('[data-role="auto-order-close"]')) {
                    this.closeAutoOrderTestModal();
                }
            });
        }

        if (this.elements.sendAutoOrderTestEmail) {
            this.elements.sendAutoOrderTestEmail.addEventListener('click', () => {
                if (this.state.autoOrderTest.productId) {
                    this.sendAutoOrderTestEmail(this.state.autoOrderTest.productId);
                }
            });
        }

        if (this.elements.executeAutoOrderButton) {
            this.elements.executeAutoOrderButton.addEventListener('click', () => {
                if (this.state.autoOrderTest.productId) {
                    this.executeAutoOrder(this.state.autoOrderTest.productId);
                }
            });
        }

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

        if (this.elements.selectAllUnits) {
            this.elements.selectAllUnits.addEventListener('change', (event) => {
                this.handleSelectAllUnits(event.target.checked);
            });
        }

        if (this.elements.applyBulkPropertyBtn) {
            this.elements.applyBulkPropertyBtn.addEventListener('click', () => this.applyBulkBooleanProperty());
        }

        if (this.elements.applyBulkMaxBtn) {
            this.elements.applyBulkMaxBtn.addEventListener('click', () => this.applyBulkValueAction('set_max_items'));
        }

        if (this.elements.applyBulkWeightBtn) {
            this.elements.applyBulkWeightBtn.addEventListener('click', () => this.applyBulkValueAction('set_weight'));
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

        if (this.elements.stockSettingsBody) {
            this.elements.stockSettingsBody.addEventListener('click', (event) => {
                const testButton = event.target.closest('[data-action="test-auto-order"]');
                if (testButton) {
                    const productId = parseInt(testButton.dataset.productId, 10);
                    const productName = testButton.dataset.productName || '';
                    if (!Number.isNaN(productId)) {
                        this.testAutoOrderForProduct(productId, productName);
                    }
                    return;
                }
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

        if (this.elements.stockSellerSearch) {
            this.elements.stockSellerSearch.addEventListener('input', this.debounce(() => {
                const q = this.elements.stockSellerSearch.value.trim();
                this.searchStockSellers(q);
            }, this.config.debounceDelay));

            this.elements.stockSellerSearch.addEventListener('focus', () => {
                const q = this.elements.stockSellerSearch.value.trim();
                if (q.length >= 2) {
                    this.searchStockSellers(q);
                }
            });
        }

        if (this.elements.clearStockSeller) {
            this.elements.clearStockSeller.addEventListener('click', () => this.clearStockSeller());
        }

        if (this.elements.autoOrderEnabled) {
            this.elements.autoOrderEnabled.addEventListener('change', () => this.updateAutoOrderWarning());
        }

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.seller-search-container')) {
                this.hideProductResults('product');
                this.hideProductResults('stockProduct');
                this.hideSellerResults();
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

        this.setupVariableDragAndDrop();

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
                if (this.elements.emailTemplateModal && this.elements.emailTemplateModal.style.display === 'block') {
                    this.closeEmailTemplateBuilder();
                } else if (this.elements.autoOrderTestModal && this.elements.autoOrderTestModal.style.display === 'block') {
                    this.closeAutoOrderTestModal();
                } else if (this.elements.stockSettingsModal && this.elements.stockSettingsModal.style.display === 'block') {
                    this.closeStockModal();
                } else {
                    this.closeModal();
                }
            }
        });
    },

    // ===== EMAIL TEMPLATE BUILDER =====
    setupVariableDragAndDrop() {
        if (!this.elements.templateVariables || !this.elements.templateVariables.length) {
            return;
        }

        this.elements.templateVariables.forEach(variable => {
            variable.addEventListener('dragstart', (event) => this.handleVariableDragStart(event));
            variable.addEventListener('dragend', (event) => this.handleVariableDragEnd(event));
            variable.addEventListener('click', () => this.insertVariable(variable.dataset.variable));
        });
    },

    handleVariableDragStart(event) {
        const variable = event.currentTarget?.dataset?.variable;
        if (!variable) return;
        event.dataTransfer.effectAllowed = 'copy';
        event.dataTransfer.setData('text/plain', variable);
        event.currentTarget.classList.add('dragging');
    },

    handleVariableDragEnd(event) {
        event.currentTarget?.classList.remove('dragging');
    },

    handleVariableDragOver(event) {
        event.preventDefault();
        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'copy';
        }
    },

    handleVariableDrop(event, field) {
        event.preventDefault();
        const variable = event.dataTransfer?.getData('text/plain');
        if (!variable) return;
        const targetField = field || event.target;
        if (targetField === this.elements.emailTemplateSubject || targetField === this.elements.emailTemplateBody) {
            this.insertVariableAtCursor(targetField, variable);
        }
    },

    insertVariable(variableCode) {
        if (!variableCode) return;
        const activeElement = document.activeElement;
        const targetField = [this.elements.emailTemplateBody, this.elements.emailTemplateSubject].includes(activeElement)
            ? activeElement
            : this.elements.emailTemplateBody;
        this.insertVariableAtCursor(targetField, variableCode);
    },

    insertVariableAtCursor(field, variable) {
        if (!field || typeof field.value === 'undefined') return;
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? field.value.length;
        const before = field.value.substring(0, start);
        const after = field.value.substring(end);
        field.value = `${before}${variable}${after}`;
        const newPosition = start + variable.length;
        field.selectionStart = newPosition;
        field.selectionEnd = newPosition;
        field.focus();
        this.handleEmailTemplateInput();
    },

    openEmailTemplateBuilder() {
        if (!this.elements.emailTemplateModal) return;

        this.clearEmailTemplateValidation();
        this.updateUnsavedChangesIndicator(false);
        this.updateAutoSaveStatus('idle');
        this.populateEmailTemplateFields(this.state.emailTemplate);

        this.elements.emailTemplateModal.style.display = 'block';
        document.body.style.overflow = 'hidden';

        this.startTemplateAutoSaveInterval();
        this.loadEmailTemplateFromServer();

        requestAnimationFrame(() => {
            if (this.elements.emailTemplateSubject) {
                this.elements.emailTemplateSubject.focus();
            }
        });
    },

    closeEmailTemplateBuilder() {
        if (!this.elements.emailTemplateModal) return;

        this.elements.emailTemplateModal.style.display = 'none';
        document.body.style.overflow = '';
        if (this.hasUnsavedEmailChanges()) {
            this.saveTemplateDraft();
        }
        this.clearEmailTemplateValidation();
        this.stopTemplateAutoSaveInterval();
    },

    populateEmailTemplateFields(template = {}) {
        const subject = template.subject ?? template.subject_template ?? '';
        const body = template.body ?? template.body_template ?? '';
        const templateName = template.templateName ?? template.template_name ?? 'Șablon autocomandă personalizat';

        if (this.elements.emailTemplateName) {
            this.elements.emailTemplateName.value = templateName;
        }

        if (this.elements.emailTemplateSubject) {
            this.elements.emailTemplateSubject.value = subject;
        }

        if (this.elements.emailTemplateBody) {
            this.elements.emailTemplateBody.value = body;
        }

        this.state.emailTemplate.templateName = templateName;
        this.state.emailTemplate.subject = subject;
        this.state.emailTemplate.body = body;
        const testRecipient = (template.testRecipient ?? this.state.emailTemplate.testRecipient ?? '').trim();
        this.state.emailTemplate.testRecipient = testRecipient;
        if (this.elements.emailTemplateTestRecipient && document.activeElement !== this.elements.emailTemplateTestRecipient) {
            this.elements.emailTemplateTestRecipient.value = testRecipient;
        }
        this.state.emailTemplate.templateId = template.templateId ?? template.template_id ?? this.state.emailTemplate.templateId;
        this.state.emailTemplate.isActive = template.isActive ?? template.is_active ?? true;
        this.state.emailTemplate.isDefault = template.isDefault ?? template.is_default ?? false;
        this.state.emailTemplate.updatedAt = template.updatedAt ?? template.updated_at ?? null;
        this.state.emailTemplate.createdAt = template.createdAt ?? template.created_at ?? null;
        this.state.emailTemplate.variablesUsed = template.variablesUsed ?? template.variables_used ?? [];

        this.updateSubjectCharCount();
        this.updateEmailPreview();
        this.validateEmailTemplate();
    },

    handleEmailTemplateInput() {
        if (this.elements.emailTemplateSubject) {
            this.state.emailTemplate.subject = this.elements.emailTemplateSubject.value;
            if (this.elements.emailTemplateSubject.value.trim()) {
                this.setFieldError(this.elements.emailTemplateSubject, this.elements.emailSubjectError);
            }
        }

        if (this.elements.emailTemplateBody) {
            this.state.emailTemplate.body = this.elements.emailTemplateBody.value;
            if (this.elements.emailTemplateBody.value.trim()) {
                this.setFieldError(this.elements.emailTemplateBody, this.elements.emailBodyError);
            }
        }

        if (this.elements.emailTemplateName) {
            this.state.emailTemplate.templateName = this.elements.emailTemplateName.value;
        }

        this.state.emailTemplate.unsavedChanges = true;
        this.updateUnsavedChangesIndicator(true);
        this.updateAutoSaveStatus('pending');
        this.startTemplateAutoSaveInterval();
        this.updateSubjectCharCount();
        this.updateEmailPreview();
        this.validateEmailTemplate();
    },

    updateEmailPreview() {
        if (!this.elements.emailTemplatePreview) return;

        const { subject, body, sampleData } = this.state.emailTemplate;

        if (!subject && !body) {
            this.elements.emailTemplatePreview.innerHTML = '<p class="preview-empty">Completează subiectul și conținutul pentru a vedea previzualizarea.</p>';
            if (this.elements.templatePreviewMissing) {
                this.elements.templatePreviewMissing.textContent = '';
            }
            return;
        }

        const renderText = (text) => {
            if (!text) {
                return '';
            }
            const replaced = this.replaceTemplateVariables(text, sampleData);
            return this.escapeHtml(replaced).replace(/\n/g, '<br>');
        };

        const previewSubject = subject ? renderText(subject) : '<em>Fără subiect</em>';
        const previewBody = body ? renderText(body) : '<em>Fără conținut</em>';

        this.elements.emailTemplatePreview.innerHTML = `
            <div class="preview-subject"><strong>Subiect:</strong> ${previewSubject}</div>
            <div class="preview-body">${previewBody}</div>
        `;

        const missing = this.detectUnreplacedVariables(`${previewSubject} ${previewBody}`);
        if (this.elements.templatePreviewMissing) {
            if (missing.length) {
                this.elements.templatePreviewMissing.innerHTML = `Variabile nerecunoscute: ${missing.map(v => `<span class="missing-variable">${this.escapeHtml(v)}</span>`).join(', ')}`;
            } else {
                this.elements.templatePreviewMissing.textContent = '';
            }
        }
    },

    normalizeTemplateSampleData(sampleData = {}) {
        if (!sampleData || typeof sampleData !== 'object') {
            return {};
        }
        const normalized = {};
        Object.entries(sampleData).forEach(([key, value]) => {
            if (typeof key !== 'string') {
                return;
            }
            const cleanedKey = key.replace(/^[{\s]+|[}\s]+$/g, '').trim().toUpperCase();
            if (cleanedKey) {
                normalized[cleanedKey] = value;
            }
        });
        return normalized;
    },

    applyDefaultTestRecipient() {
        if (!this.elements.emailTemplateTestRecipient) {
            return;
        }
        const currentValue = (this.elements.emailTemplateTestRecipient.value || '').trim();
        if (currentValue) {
            this.state.emailTemplate.testRecipient = currentValue;
            return;
        }

        const fallback = (this.state.emailTemplate.testRecipient || '').trim()
            || (this.state.emailTemplate.sampleData?.SUPPLIER_EMAIL || '').trim()
            || (this.state.emailTemplate.sampleData?.COMPANY_EMAIL || '').trim();

        if (fallback) {
            this.elements.emailTemplateTestRecipient.value = fallback;
            this.state.emailTemplate.testRecipient = fallback;
        }
    },

    replaceTemplateVariables(text, sampleData = {}) {
        if (!text) {
            return '';
        }
        return text.replace(/\{\{\s*([A-Z0-9_]+)\s*\}\}/gi, (match, variable) => {
            const key = (variable || '').trim().toUpperCase();
            if (key && Object.prototype.hasOwnProperty.call(sampleData, key)) {
                const value = sampleData[key];
                return value !== null && value !== undefined ? String(value) : '';
            }
            return `{{${key}}}`;
        });
    },

    detectUnreplacedVariables(text) {
        if (!text) {
            return [];
        }
        const matches = text.match(/\{\{\s*[A-Z0-9_]+\s*\}\}/gi);
        return matches ? Array.from(new Set(matches.map((token) => token.replace(/\s+/g, '').toUpperCase()))) : [];
    },

    updateSubjectCharCount() {
        if (!this.elements.subjectCharCount || !this.elements.emailTemplateSubject) {
            return;
        }
        const count = this.elements.emailTemplateSubject.value.length;
        this.elements.subjectCharCount.textContent = `${count} caractere`;
    },

    updateUnsavedChangesIndicator(visible) {
        if (!this.elements.unsavedChangesBadge) return;
        this.elements.unsavedChangesBadge.style.display = visible ? 'inline-flex' : 'none';
    },

    updateAutoSaveStatus(status) {
        this.state.emailTemplate.autoSaveStatus = status;
        if (!this.elements.autoSaveStatus) {
            return;
        }

        const labels = {
            idle: '',
            pending: 'Modificări în curs...',
            saving: 'Se salvează template-ul...',
            saved: MESSAGES.autoSaved,
            error: 'Eroare la salvarea template-ului',
            loaded: 'Template încărcat'
        };
        this.elements.autoSaveStatus.textContent = labels[status] || '';
    },

    startTemplateAutoSaveInterval() {
        if (this.templateAutoSaveInterval) {
            return;
        }
        this.templateAutoSaveInterval = setInterval(() => {
            if (this.hasUnsavedEmailChanges()) {
                this.saveTemplateDraft();
            }
        }, 30000);
    },

    stopTemplateAutoSaveInterval() {
        if (this.templateAutoSaveInterval) {
            clearInterval(this.templateAutoSaveInterval);
            this.templateAutoSaveInterval = null;
        }
    },

    hasUnsavedEmailChanges() {
        return !!this.state.emailTemplate.unsavedChanges;
    },

    saveTemplateDraft({ clear = false } = {}) {
        if (typeof localStorage === 'undefined') {
            return;
        }
        const key = this.config.localStorageKeys.autoOrderEmailDraft;
        if (clear) {
            localStorage.removeItem(key);
            return;
        }
        const payload = {
            templateName: this.state.emailTemplate.templateName,
            subject: this.state.emailTemplate.subject,
            body: this.state.emailTemplate.body,
            savedAt: new Date().toISOString()
        };
        try {
            localStorage.setItem(key, JSON.stringify(payload));
            this.state.emailTemplate.draftSavedAt = payload.savedAt;
            this.updateAutoSaveStatus('saved');
        } catch (error) {
            console.error('saveTemplateDraft error', error);
        }
    },

    getTemplateDraft() {
        if (typeof localStorage === 'undefined') {
            return null;
        }
        const key = this.config.localStorageKeys.autoOrderEmailDraft;
        const raw = localStorage.getItem(key);
        if (!raw) {
            return null;
        }
        try {
            const parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object') {
                return parsed;
            }
        } catch (error) {
            console.warn('Draft parse error', error);
        }
        return null;
    },

    loadDraftIfAvailable() {
        const draft = this.getTemplateDraft();
        if (!draft) {
            return;
        }

        const draftTime = draft.savedAt ? Date.parse(draft.savedAt) : 0;
        const templateTime = this.state.emailTemplate.updatedAt ? Date.parse(this.state.emailTemplate.updatedAt) : 0;

        if (draftTime > templateTime) {
            this.populateEmailTemplateFields({
                templateName: draft.templateName,
                subject: draft.subject,
                body: draft.body
            });
            this.state.emailTemplate.unsavedChanges = true;
            this.updateUnsavedChangesIndicator(true);
            this.updateAutoSaveStatus('pending');
            this.showInfo('A fost încărcată versiunea draft salvată automat.');
        }
    },

    setupBeforeUnloadHandler() {
        window.addEventListener('beforeunload', (event) => {
            if (!this.hasUnsavedEmailChanges()) {
                return;
            }
            event.preventDefault();
            event.returnValue = MESSAGES.unsavedChanges;
            return MESSAGES.unsavedChanges;
        });
    },

    async loadEmailTemplateFromServer() {
        try {
            this.setEmailTemplateLoading(true);
            const url = `${this.config.apiEndpoints.emailTemplates}?action=load&type=auto_order`;
            const response = await this.apiCall('GET', url);
            const data = response.data || {};
            const template = data.template || null;

            this.state.emailTemplate.sampleData = this.normalizeTemplateSampleData(data.sample_data);
            this.state.emailTemplate.availableVariables = data.available_variables || {};
            this.applyDefaultTestRecipient();

            if (template) {
                this.populateEmailTemplateFields({
                    templateId: template.template_id ?? template.id,
                    templateName: template.template_name,
                    subject: template.subject_template,
                    body: template.body_template,
                    isActive: template.is_active,
                    isDefault: template.is_default,
                    updatedAt: template.updated_at,
                    createdAt: template.created_at,
                    variablesUsed: template.variables_used || []
                });
                this.populateEmailTemplateMetadata(template);
            } else {
                this.populateEmailTemplateFields({ templateName: 'Șablon autocomandă personalizat', subject: '', body: '' });
                this.populateEmailTemplateMetadata(null);
            }

            this.state.emailTemplate.unsavedChanges = false;
            this.updateUnsavedChangesIndicator(false);
            this.updateAutoSaveStatus('loaded');
            this.saveTemplateDraft({ clear: true });
            this.highlightTemplateVariables();
            await this.loadTemplateHistory();
            this.loadDraftIfAvailable();
        } catch (error) {
            console.error('loadEmailTemplateFromServer error', error);
            this.showError(MESSAGES.templateLoadError);
            this.updateAutoSaveStatus('error');
        } finally {
            this.setEmailTemplateLoading(false);
        }
    },

    setEmailTemplateLoading(isLoading) {
        if (!this.elements.emailTemplateModal) {
            return;
        }
        this.elements.emailTemplateModal.classList.toggle('is-loading', Boolean(isLoading));
    },

    populateEmailTemplateMetadata(template) {
        const updatedText = template?.updated_at ? new Date(template.updated_at).toLocaleString('ro-RO') : '-';
        const createdText = template?.created_at ? new Date(template.created_at).toLocaleString('ro-RO') : '-';
        const updatedBy = template?.updated_by_name || template?.updated_by || '-';

        if (this.elements.templateUpdatedAt) {
            this.elements.templateUpdatedAt.textContent = `Ultima actualizare: ${updatedText}`;
        }
        if (this.elements.templateUpdatedBy) {
            this.elements.templateUpdatedBy.textContent = `Actualizat de: ${updatedBy}`;
        }
        if (this.elements.templateCreatedAt) {
            this.elements.templateCreatedAt.textContent = `Creat: ${createdText}`;
        }
    },

    async loadTemplateHistory() {
        try {
            const url = `${this.config.apiEndpoints.emailTemplates}?action=history&type=auto_order`;
            const response = await this.apiCall('GET', url);
            const history = response.data?.history || response.history || [];
            this.state.emailTemplate.history = history;
            this.renderTemplateHistory();
            this.populateTemplateHistoryDropdown();
        } catch (error) {
            console.error('loadTemplateHistory error', error);
        }
    },

    renderTemplateHistory() {
        if (!this.elements.templateHistoryList || !this.elements.templateHistoryEmpty) {
            return;
        }
        const history = this.state.emailTemplate.history.filter((item) => item.template_id !== this.state.emailTemplate.templateId);
        if (!history.length) {
            this.elements.templateHistoryList.innerHTML = '';
            this.elements.templateHistoryEmpty.style.display = 'block';
            return;
        }

        this.elements.templateHistoryEmpty.style.display = 'none';
        this.elements.templateHistoryList.innerHTML = history.map((item) => {
            const updated = item.updated_at ? new Date(item.updated_at).toLocaleString('ro-RO') : '-';
            return `
                <li data-template-id="${item.template_id}">
                    <div class="history-name">${this.escapeHtml(item.template_name || 'Template fără nume')}</div>
                    <div class="history-meta">${updated}</div>
                </li>
            `;
        }).join('');
    },

    populateTemplateHistoryDropdown() {
        if (!this.elements.loadTemplateDropdown) {
            return;
        }
        const options = ['<option value="">Selectează un template salvat</option>'];
        this.state.emailTemplate.history.forEach((item) => {
            options.push(`<option value="${item.template_id}">${this.escapeHtml(item.template_name || 'Template fără nume')}</option>`);
        });
        this.elements.loadTemplateDropdown.innerHTML = options.join('');
    },

    handleSavedTemplateSelection(event) {
        const value = parseInt(event.target.value, 10);
        if (Number.isNaN(value)) {
            return;
        }
        this.loadTemplateFromHistory(value);
        event.target.value = '';
    },

    loadTemplateFromHistory(templateId) {
        const template = this.state.emailTemplate.history.find((item) => item.template_id === templateId);
        if (!template) {
            this.showError('Template-ul selectat nu a fost găsit în istoric.');
            return;
        }
        this.populateEmailTemplateFields({
            templateId: template.template_id,
            templateName: template.template_name,
            subject: template.subject_template,
            body: template.body_template,
            isActive: template.is_active,
            isDefault: template.is_default,
            updatedAt: template.updated_at,
            createdAt: template.created_at
        });
        this.populateEmailTemplateMetadata(template);
        this.state.emailTemplate.unsavedChanges = true;
        this.updateUnsavedChangesIndicator(true);
        this.updateAutoSaveStatus('pending');
        this.highlightTemplateVariables();
    },

    highlightTemplateVariables() {
        if (!this.elements.templateVariables || !this.elements.templateVariables.length) {
            return;
        }
        const usedVariables = new Set(this.getTemplateVariables());
        this.elements.templateVariables.forEach((button) => {
            const code = button.dataset.variable ? button.dataset.variable.replace(/[{}]/g, '').trim().toUpperCase() : '';
            if (!code) return;
            button.classList.toggle('variable-used', usedVariables.has(code));
            button.classList.toggle('variable-unused', !usedVariables.has(code));
        });
    },

    getTemplateVariables() {
        const subject = this.state.emailTemplate.subject || '';
        const body = this.state.emailTemplate.body || '';
        const combined = `${subject}\n${body}`;
        const matches = combined.match(/\{\{\s*([A-Z0-9_]+)\s*\}\}/gi);
        if (!matches) {
            return [];
        }
        return Array.from(new Set(matches.map((token) => token.replace(/[{}]/g, '').trim().toUpperCase())));
    },

    validateEmailTemplate() {
        const variables = this.getTemplateVariables();
        const missingRequired = EMAIL_TEMPLATE_REQUIRED_VARIABLES.filter((key) => !variables.includes(key));
        const missingRecommended = EMAIL_TEMPLATE_RECOMMENDED_VARIABLES.filter((key) => !variables.includes(key));

        this.state.emailTemplate.missingRequired = missingRequired;
        this.state.emailTemplate.missingRecommended = missingRecommended;

        if (this.elements.templateValidationList) {
            const items = [];
            if (missingRequired.length) {
                items.push(`<li class="validation-error">Lipsesc variabile obligatorii: ${missingRequired.map((key) => `{{${key}}}`).join(', ')}</li>`);
            } else {
                items.push('<li class="validation-success">Toate variabilele obligatorii sunt prezente.</li>');
            }
            if (missingRecommended.length) {
                items.push(`<li class="validation-warning">Recomandare: adaugă ${missingRecommended.map((key) => `{{${key}}}`).join(', ')}</li>`);
            } else {
                items.push('<li class="validation-success">Variabilele recomandate sunt completate.</li>');
            }
            this.elements.templateValidationList.innerHTML = items.join('');
        }

        this.highlightTemplateVariables();
        return { hasErrors: missingRequired.length > 0, missingRequired, missingRecommended };
    },

    async saveEmailTemplate() {
        await this.persistEmailTemplate();
    },

    async duplicateEmailTemplate() {
        const baseName = this.state.emailTemplate.templateName || 'Șablon autocomandă';
        const copyName = `${baseName} - copie`;
        await this.persistEmailTemplate({ templateId: null, templateName: copyName });
    },

    async deleteEmailTemplate() {
        if (!this.state.emailTemplate.templateId) {
            this.showError('Nu există un template activ care să poată fi șters.');
            return;
        }
        if (!confirm('Sigur doriți să dezactivați acest template?')) {
            return;
        }
        await this.persistEmailTemplate({ isActive: false });
    },

    async persistEmailTemplate(overrides = {}) {
        const subject = (overrides.subject ?? (this.elements.emailTemplateSubject?.value || '')).trim();
        const body = (overrides.body ?? (this.elements.emailTemplateBody?.value || '')).trim();
        const templateName = (overrides.templateName ?? (this.elements.emailTemplateName?.value || '').trim()) || 'Șablon autocomandă personalizat';
        const templateId = overrides.templateId !== undefined ? overrides.templateId : this.state.emailTemplate.templateId;
        const isActive = overrides.isActive !== undefined ? overrides.isActive : this.state.emailTemplate.isActive;
        const isDefault = overrides.isDefault !== undefined ? overrides.isDefault : this.state.emailTemplate.isDefault;

        let hasError = false;

        if (!subject) {
            this.setFieldError(this.elements.emailTemplateSubject, this.elements.emailSubjectError, 'Subiectul emailului este obligatoriu.');
            hasError = true;
        } else {
            this.setFieldError(this.elements.emailTemplateSubject, this.elements.emailSubjectError);
        }

        if (!body) {
            this.setFieldError(this.elements.emailTemplateBody, this.elements.emailBodyError, 'Conținutul emailului este obligatoriu.');
            hasError = true;
        } else {
            this.setFieldError(this.elements.emailTemplateBody, this.elements.emailBodyError);
        }

        const validation = this.validateEmailTemplate();
        if (validation.hasErrors) {
            hasError = true;
            this.showError(MESSAGES.validationFailed);
        }

        if (hasError) {
            return;
        }

        const payload = {
            template_id: templateId,
            template_type: 'auto_order',
            template_name: templateName,
            subject_template: subject,
            body_template: body,
            is_active: isActive ? 1 : 0,
            is_default: isDefault ? 1 : 0
        };

        try {
            this.updateAutoSaveStatus('saving');
            const url = `${this.config.apiEndpoints.emailTemplates}?action=save`;
            const response = await this.apiCall('POST', url, payload);
            if (response && response.success === false) {
                throw new Error(response.message || 'Nu s-a putut salva template-ul.');
            }
            const data = response.data || {};
            const template = data.template || payload;

            this.populateEmailTemplateFields({
                templateId: (template.template_id ?? template.id) ?? templateId,
                templateName: (template.template_name ?? templateName),
                subject: (template.subject_template ?? subject),
                body: (template.body_template ?? body),
                isActive: (template.is_active ?? isActive),
                isDefault: (template.is_default ?? isDefault),
                updatedAt: (template.updated_at ?? new Date().toISOString()),
                createdAt: (template.created_at ?? this.state.emailTemplate.createdAt),
                variablesUsed: template.variables_used || this.getTemplateVariables()
            });
            this.populateEmailTemplateMetadata(template);
            this.state.emailTemplate.unsavedChanges = false;
            this.updateUnsavedChangesIndicator(false);
            this.updateAutoSaveStatus('saved');
            this.saveTemplateDraft({ clear: true });
            await this.loadTemplateHistory();
            this.highlightTemplateVariables();
            this.showSuccess(MESSAGES.templateSaved);
        } catch (error) {
            console.error('persistEmailTemplate error', error);
            this.updateAutoSaveStatus('error');
            this.showError(error.message || 'Nu s-a putut salva template-ul.');
        }
    },

    async testEmailTemplate() {
        const subject = (this.elements.emailTemplateSubject?.value || '').trim();
        const body = (this.elements.emailTemplateBody?.value || '').trim();
        if (!subject || !body) {
            this.showError('Completează subiectul și conținutul pentru a trimite un email de test.');
            return;
        }

        const recipient = (this.state.emailTemplate.testRecipient
            || this.elements.emailTemplateTestRecipient?.value
            || '').trim();

        if (!recipient) {
            this.showError('Introdu adresa de email a furnizorului pentru test.');
            if (this.elements.emailTemplateTestRecipient) {
                this.elements.emailTemplateTestRecipient.classList.add('input-error');
                this.elements.emailTemplateTestRecipient.focus();
            }
            return;
        }

        if (!this.isValidEmail(recipient)) {
            this.showError('Adresa de email a furnizorului nu este validă.');
            if (this.elements.emailTemplateTestRecipient) {
                this.elements.emailTemplateTestRecipient.classList.add('input-error');
                this.elements.emailTemplateTestRecipient.focus();
            }
            return;
        }

        const validation = this.validateEmailTemplate();
        if (validation.hasErrors) {
            this.showError(MESSAGES.validationFailed);
            return;
        }

        this.state.emailTemplate.testRecipient = recipient;
        const payload = {
            template_id: this.state.emailTemplate.templateId,
            template_type: 'auto_order',
            subject_template: subject,
            body_template: body,
            recipient_email: recipient
        };

        try {
            const url = `${this.config.apiEndpoints.emailTemplates}?action=test`;
            const response = await this.apiCall('POST', url, payload);
            if (response && response.success === false) {
                throw new Error(response.message || 'Trimiterea emailului de test a eșuat.');
            }
            const data = response.data || {};
            if (data.preview) {
                this.state.emailTemplate.preview = data.preview;
                this.updateEmailPreview();
            }
            this.showSuccess(MESSAGES.testEmailSent);
        } catch (error) {
            console.error('testEmailTemplate error', error);
            this.showError(error.message || 'Trimiterea emailului de test a eșuat.');
        }
    },

    clearEmailTemplateValidation() {
        this.setFieldError(this.elements.emailTemplateSubject, this.elements.emailSubjectError);
        this.setFieldError(this.elements.emailTemplateBody, this.elements.emailBodyError);
        if (this.elements.templateValidationList) {
            this.elements.templateValidationList.innerHTML = '';
        }
        if (this.elements.templatePreviewMissing) {
            this.elements.templatePreviewMissing.textContent = '';
        }
        if (this.elements.emailTemplateTestRecipient) {
            this.elements.emailTemplateTestRecipient.classList.remove('input-error');
        }
        this.updateAutoSaveStatus('idle');
    },

    openAutoOrderTestModal(productName = '') {
        if (!this.elements.autoOrderTestModal) return;
        this.elements.autoOrderTestTitle.textContent = productName ? `Test Autocomandă - ${productName}` : 'Test Autocomandă';
        if (this.elements.autoOrderValidationList) {
            this.elements.autoOrderValidationList.innerHTML = '<div class="validation-item validation-warning">Se încarcă simularea...</div>';
        }
        if (this.elements.autoOrderEmailSubject) {
            this.elements.autoOrderEmailSubject.textContent = '';
        }
        if (this.elements.autoOrderEmailBody) {
            this.elements.autoOrderEmailBody.innerHTML = '';
        }
        if (this.elements.autoOrderSimulationSummary) {
            this.elements.autoOrderSimulationSummary.innerHTML = '';
        }
        if (this.elements.executeAutoOrderButton) {
            this.elements.executeAutoOrderButton.style.display = 'none';
        }
        this.elements.autoOrderTestModal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        this.state.autoOrderTest.isOpen = true;
    },

    closeAutoOrderTestModal() {
        if (!this.elements.autoOrderTestModal) return;
        this.elements.autoOrderTestModal.style.display = 'none';
        document.body.style.overflow = '';
        if (this.elements.executeAutoOrderButton) {
            this.elements.executeAutoOrderButton.style.display = 'none';
        }
        this.state.autoOrderTest = {
            isOpen: false,
            productId: null,
            productName: '',
            validations: [],
            emailPreview: { subject: '', body: '', missingVariables: [] },
            simulation: {},
            lastRun: null
        };
    },

    async testAutoOrderForProduct(productId, productName = '') {
        if (!productId) {
            return;
        }
        this.state.autoOrderTest.productId = productId;
        this.state.autoOrderTest.productName = productName;
        this.openAutoOrderTestModal(productName);

        try {
            const response = await fetch(`${this.config.apiEndpoints.autoOrderTest}?product_id=${productId}`);
            const payload = await response.json();
            if (!payload.success) {
                throw new Error(payload.message || 'Simularea autocomenzii a eșuat.');
            }
            this.renderAutoOrderTestResults(payload.data || {});
        } catch (error) {
            console.error('testAutoOrderForProduct error', error);
            this.showError(error.message || 'Eroare la simularea autocomenzii.');
            if (this.elements.autoOrderValidationList) {
                this.elements.autoOrderValidationList.innerHTML = '<div class="validation-item validation-error">Nu s-a putut rula simularea.</div>';
            }
        }
    },

    renderAutoOrderTestResults(data = {}) {
        const validations = data.validations || data.validari || [];
        const preview = data.template || {};
        const simulation = data.simulation || {};

        if (this.elements.autoOrderValidationList) {
            if (!validations.length) {
                this.elements.autoOrderValidationList.innerHTML = '<div class="validation-item">Nu au fost raportate validări.</div>';
            } else {
                this.elements.autoOrderValidationList.innerHTML = validations.map((item) => {
                    const status = (item.result || item.rezultat || '').toLowerCase();
                    const label = item.condition || item.conditie || 'Condiție';
                    const details = item.details || item.detalii || '';
                    let typeClass = 'validation-info';
                    if (status === 'ok') {
                        typeClass = 'validation-success';
                    } else if (status === 'eroare' || status === 'error') {
                        typeClass = 'validation-error';
                    } else if (status === 'avertisment' || status === 'warning') {
                        typeClass = 'validation-warning';
                    }
                    return `<div class="validation-item ${typeClass}"><strong>${this.escapeHtml(label)}:</strong> ${this.escapeHtml(details)}</div>`;
                }).join('');
            }
        }

        if (this.elements.autoOrderEmailSubject) {
            const subject = preview.subject_preview || preview.subject || 'Subiect indisponibil';
            this.elements.autoOrderEmailSubject.textContent = subject;
        }

        if (this.elements.autoOrderEmailBody) {
            const body = preview.body_preview || preview.body || 'Conținut indisponibil';
            this.elements.autoOrderEmailBody.innerHTML = this.escapeHtml(body).replace(/\n/g, '<br>');
        }

        if (this.elements.autoOrderSimulationSummary) {
            const entries = Object.entries(simulation);
            if (!entries.length) {
                this.elements.autoOrderSimulationSummary.innerHTML = '<p>Nu există informații suplimentare pentru simulare.</p>';
            } else {
                this.elements.autoOrderSimulationSummary.innerHTML = entries.map(([key, value]) => {
                    return `<div class="simulation-row"><span class="simulation-label">${this.escapeHtml(key)}:</span> <span class="simulation-value">${this.escapeHtml(String(value))}</span></div>`;
                }).join('');
            }
        }

        const canExecute = Boolean(simulation.poate_comanda);
        if (this.elements.executeAutoOrderButton) {
            this.elements.executeAutoOrderButton.style.display = canExecute ? 'inline-flex' : 'none';
        }

        this.state.autoOrderTest.validations = validations;
        this.state.autoOrderTest.emailPreview = preview;
        this.state.autoOrderTest.simulation = simulation;
        this.state.autoOrderTest.lastRun = new Date().toISOString();
    },

    async sendAutoOrderTestEmail(productId) {
        if (!productId) {
            return;
        }
        const requestPayload = { action: 'send_test_email', product_id: productId };

        const configuredRecipient = (this.state.emailTemplate?.testRecipient || '').trim();
        if (configuredRecipient && this.isValidEmail?.(configuredRecipient)) {
            requestPayload.test_recipient = configuredRecipient;
        }
        try {
            const response = await fetch(this.config.apiEndpoints.autoOrderTest, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestPayload)
            });
            const responsePayload = await response.json();
            if (!responsePayload.success) {
                throw new Error(responsePayload.message || 'Trimiterea emailului de test a eșuat.');
            }
            this.showSuccess(responsePayload.message || 'Emailul de test pentru autocomandă a fost trimis.');
        } catch (error) {
            console.error('sendAutoOrderTestEmail error', error);
            this.showError(error.message || 'Trimiterea emailului de test a eșuat.');
        }
    },

    async executeAutoOrder(productId) {
        if (!productId) {
            return;
        }
        try {
            const response = await fetch(this.config.apiEndpoints.autoOrderTest, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'execute_auto_order', product_id: productId })
            });
            const payload = await response.json();
            if (!payload.success) {
                throw new Error(payload.message || 'Autocomanda nu a putut fi executată.');
            }
            this.showSuccess(payload.message || 'Autocomanda a fost trimisă.');
            if (payload.order_number && typeof AutoOrderNotifications !== 'undefined') {
                AutoOrderNotifications.showAutoOrderCreated(payload.order_number, this.state.autoOrderTest.productName || '');
            }
            this.closeAutoOrderTestModal();
            this.loadStockSettings();
        } catch (error) {
            console.error('executeAutoOrder error', error);
            this.showError(error.message || 'Autocomanda nu a putut fi executată.');
        }
    },

    setFieldError(field, errorElement, message = '') {
        if (!field) return;
        if (message) {
            field.classList.add('input-error');
            field.setAttribute('aria-invalid', 'true');
            if (errorElement) {
                errorElement.textContent = message;
                errorElement.style.display = 'block';
            }
        } else {
            field.classList.remove('input-error');
            field.removeAttribute('aria-invalid');
            if (errorElement) {
                errorElement.style.display = 'none';
            }
        }
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

        // Start with the "All categories" option
        select.innerHTML = '<option value="">Toate categoriile</option>';
        
        // Add all categories
        categories.forEach(cat => {
            const opt = document.createElement('option');
            opt.value = cat;
            opt.textContent = cat;
            select.appendChild(opt);
        });
        
        // Preselect "Marfa" if it exists in the categories
        if (categories.includes('Marfa')) {
            select.value = 'Marfa';
            this.state.stockCategory = 'Marfa'; // Update the state

            // Trigger the filtering to show only Marfa products
            this.applyStockFilters();
        }
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

        this.syncSelectedUnitsWithData();

        if (this.state.filteredData.length === 0) {
            this.renderEmptyTable();
            return;
        }

        tbody.innerHTML = this.state.filteredData.map(unit => {
            const isSelected = this.state.selectedUnits.has(unit.id);
            return `
            <tr data-id="${unit.id}">
                <td class="select-cell">
                    <input type="checkbox" class="unit-checkbox" data-id="${unit.id}" ${isSelected ? 'checked' : ''}>
                </td>
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
        `;
        }).join('');

        this.updateTableInfo();
        this.initializeUnitBulkSelection();
    },

    renderEmptyTable() {
        if (!this.elements.productUnitsBody) return;

        this.elements.productUnitsBody.innerHTML = `
            <tr class="empty-row">
                <td colspan="10" class="text-center">
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
        this.initializeUnitBulkSelection();
    },

    renderTableError(message) {
        if (!this.elements.productUnitsBody) return;

        this.elements.productUnitsBody.innerHTML = `
            <tr class="error-row">
                <td colspan="10" class="text-center">
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
        this.initializeUnitBulkSelection();
    },

    showTableLoading() {
        if (!this.elements.productUnitsBody) return;

        this.elements.productUnitsBody.innerHTML = `
            <tr class="loading-row">
                <td colspan="10" class="text-center">
                    <div class="loading-spinner">
                        <span class="material-symbols-outlined spinning">progress_activity</span>
                        Încărcare date...
                    </div>
                </td>
            </tr>
        `;

        this.updateBulkUnitActionsUI();
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

    // ===== BULK SELECTION & ACTIONS =====
    syncSelectedUnitsWithData() {
        if (!(this.state.selectedUnits instanceof Set)) {
            this.state.selectedUnits = new Set(Array.isArray(this.state.selectedUnits) ? this.state.selectedUnits : []);
        }

        const validIds = new Set(this.state.filteredData.map(unit => unit.id));
        this.state.selectedUnits = new Set([...this.state.selectedUnits].filter(id => validIds.has(id)));
    },

    initializeUnitBulkSelection() {
        if (!this.elements.productUnitsBody) return;

        const checkboxes = this.elements.productUnitsBody.querySelectorAll('.unit-checkbox');
        checkboxes.forEach(checkbox => {
            const id = parseInt(checkbox.dataset.id, 10);
            checkbox.checked = this.state.selectedUnits.has(id);
            checkbox.addEventListener('change', (event) => {
                const checkboxId = parseInt(event.target.dataset.id, 10);
                if (Number.isNaN(checkboxId)) return;

                if (event.target.checked) {
                    this.state.selectedUnits.add(checkboxId);
                } else {
                    this.state.selectedUnits.delete(checkboxId);
                }

                this.updateBulkUnitActionsUI();
            });
        });

        this.updateBulkUnitActionsUI();
    },

    handleSelectAllUnits(isChecked) {
        if (!(this.state.selectedUnits instanceof Set)) {
            this.state.selectedUnits = new Set();
        }

        const checkboxes = this.elements.productUnitsBody?.querySelectorAll('.unit-checkbox') || [];
        if (isChecked) {
            this.state.selectedUnits = new Set(
                Array.from(checkboxes)
                    .map(cb => parseInt(cb.dataset.id, 10))
                    .filter(id => !Number.isNaN(id))
            );
        } else {
            this.state.selectedUnits.clear();
        }

        checkboxes.forEach(cb => {
            cb.checked = isChecked;
        });

        this.updateBulkUnitActionsUI();
    },

    updateBulkUnitActionsUI() {
        const count = this.state.selectedUnits instanceof Set ? this.state.selectedUnits.size : 0;
        const checkboxes = this.elements.productUnitsBody?.querySelectorAll('.unit-checkbox') || [];
        const total = checkboxes.length;

        if (this.elements.selectedUnitsCount) {
            this.elements.selectedUnitsCount.textContent = count;
        }

        if (this.elements.unitBulkActionsBar) {
            if (count > 0) {
                this.elements.unitBulkActionsBar.style.display = 'block';
            } else {
                this.elements.unitBulkActionsBar.style.display = 'none';
            }
        }

        if (this.elements.selectAllUnits) {
            if (total === 0 || count === 0) {
                this.elements.selectAllUnits.checked = false;
                this.elements.selectAllUnits.indeterminate = false;
            } else if (count === total) {
                this.elements.selectAllUnits.checked = true;
                this.elements.selectAllUnits.indeterminate = false;
            } else {
                this.elements.selectAllUnits.checked = false;
                this.elements.selectAllUnits.indeterminate = true;
            }
        }
    },

    getSelectedUnitIds() {
        if (!(this.state.selectedUnits instanceof Set)) {
            this.state.selectedUnits = new Set(Array.isArray(this.state.selectedUnits) ? this.state.selectedUnits : []);
        }

        return Array.from(this.state.selectedUnits).filter(id => Number.isInteger(id) && id > 0);
    },

    clearUnitSelection() {
        if (!(this.state.selectedUnits instanceof Set)) {
            this.state.selectedUnits = new Set();
        }

        this.state.selectedUnits.clear();

        const checkboxes = this.elements.productUnitsBody?.querySelectorAll('.unit-checkbox') || [];
        checkboxes.forEach(cb => { cb.checked = false; });

        if (this.elements.selectAllUnits) {
            this.elements.selectAllUnits.checked = false;
            this.elements.selectAllUnits.indeterminate = false;
        }

        this.updateBulkUnitActionsUI();
    },

    async performBulkUnitAction(action) {
        const ids = this.getSelectedUnitIds();
        if (!ids.length) {
            this.showError('Selectați cel puțin o configurare.');
            return;
        }

        switch (action) {
            case 'activate':
            case 'deactivate': {
                const confirmMessage = action === 'activate'
                    ? `Ești sigur că vrei să activezi ${ids.length} configurări?`
                    : `Ești sigur că vrei să dezactivezi ${ids.length} configurări?`;
                if (!confirm(confirmMessage)) {
                    return;
                }

                const message = action === 'activate'
                    ? `${ids.length} configurări activate cu succes.`
                    : `${ids.length} configurări dezactivate cu succes.`;

                await this.executeBulkUpdate({ active: action === 'activate' }, message);
                break;
            }
            case 'delete': {
                if (!confirm(`Ești sigur că vrei să ștergi ${ids.length} configurări?\n\nAceastă acțiune nu poate fi anulată.`)) {
                    return;
                }

                await this.executeBulkDelete(ids);
                break;
            }
            default:
                console.warn('Unknown bulk unit action:', action);
        }
    },

    async applyBulkBooleanProperty() {
        const ids = this.getSelectedUnitIds();
        if (!ids.length) {
            this.showError('Selectați cel puțin o configurare.');
            return;
        }

        const select = this.elements.bulkPropertySelect;
        if (!select) return;

        const value = select.value;
        if (!value) {
            this.showError('Selectați o proprietate pentru actualizare.');
            return;
        }

        const [field, rawValue] = value.split(':');
        if (!field || typeof rawValue === 'undefined') {
            this.showError('Valoare proprietate invalidă.');
            return;
        }

        const boolValue = rawValue === 'true';
        const labels = {
            fragile: boolValue ? 'fragil' : 'nefragil',
            hazardous: boolValue ? 'periculos' : 'nepericulos',
            temperature_controlled: boolValue ? 'cu control de temperatură' : 'fără control de temperatură'
        };
        const propertyLabel = labels[field] || 'actualizat';

        await this.executeBulkUpdate({ [field]: boolValue }, `Proprietatea a fost setată la ${propertyLabel} pentru ${ids.length} configurări.`);

        select.value = '';
    },

    async applyBulkValueAction(action) {
        const ids = this.getSelectedUnitIds();
        if (!ids.length) {
            this.showError('Selectați cel puțin o configurare.');
            return;
        }

        if (action === 'set_max_items') {
            const input = this.elements.bulkMaxValue;
            if (!input) return;

            const value = parseInt(input.value, 10);
            if (Number.isNaN(value) || value < 0) {
                this.showError('Introduceți o valoare validă pentru Max/Colet.');
                return;
            }

            await this.executeBulkUpdate({ max_items_per_parcel: value }, `Maximul pe colet a fost actualizat pentru ${ids.length} configurări.`);
            input.value = '';
        } else if (action === 'set_weight') {
            const input = this.elements.bulkWeightValue;
            if (!input) return;

            const value = parseFloat(input.value);
            if (Number.isNaN(value) || value <= 0) {
                this.showError('Introduceți o greutate validă (mai mare decât 0).');
                return;
            }

            await this.executeBulkUpdate({ weight_per_unit: value }, `Greutatea a fost actualizată pentru ${ids.length} configurări.`);
            input.value = '';
        }
    },

    async executeBulkUpdate(updates, successMessage = 'Configurările au fost actualizate.') {
        const ids = this.getSelectedUnitIds();
        if (!ids.length) {
            this.showError('Selectați cel puțin o configurare.');
            return;
        }

        if (!updates || typeof updates !== 'object' || Object.keys(updates).length === 0) {
            this.showError('Nu există modificări de aplicat.');
            return;
        }

        this.showLoading();

        try {
            const response = await this.apiCall('POST', this.config.apiEndpoints.productUnits, {
                bulk_action: 'update',
                ids,
                updates
            });

            if (response.success) {
                this.showSuccess(response.message || successMessage);
                this.clearUnitSelection();
                await this.loadProductUnits();
                await this.loadStatistics();
            } else {
                throw new Error(response.error || 'Eroare la actualizarea configurărilor.');
            }
        } catch (error) {
            console.error('Bulk update error:', error);
            this.showError(error.message || 'Eroare la actualizarea configurărilor.');
        } finally {
            this.hideLoading();
        }
    },

    async executeBulkDelete(ids) {
        if (!ids || !ids.length) {
            this.showError('Selectați cel puțin o configurare.');
            return;
        }

        this.showLoading();

        try {
            const response = await this.apiCall('POST', this.config.apiEndpoints.productUnits, {
                bulk_action: 'delete',
                ids
            });

            if (response.success) {
                const message = response.message || `${ids.length} configurări au fost șterse.`;
                this.showSuccess(message);
                this.clearUnitSelection();
                await this.loadProductUnits();
                await this.loadStatistics();
            } else {
                throw new Error(response.error || 'Eroare la ștergerea configurărilor.');
            }
        } catch (error) {
            console.error('Bulk delete error:', error);
            this.showError(error.message || 'Eroare la ștergerea configurărilor.');
        } finally {
            this.hideLoading();
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
        if (context === 'stockProduct') {
            this.setStockSeller('', '');
        }
        this.hideProductResults(context);
    },

    hideProductResults(context = 'product') {
        const resultsEl = this.elements[`${context}Results`];
        if (resultsEl) {
            resultsEl.innerHTML = '';
            resultsEl.classList.remove('show');
        }
    },

    hideSellerResults() {
        if (this.state.sellerSearchController) {
            this.state.sellerSearchController.abort();
            this.state.sellerSearchController = null;
        }
        if (this.elements.stockSellerResults) {
            this.elements.stockSellerResults.innerHTML = '';
            this.elements.stockSellerResults.classList.remove('show');
        }
    },

    async searchStockSellers(query) {
        const container = this.elements.stockSellerResults;
        if (!container) return;

        if (!query || query.length < 2) {
            this.hideSellerResults();
            return;
        }

        if (this.state.sellerSearchController) {
            this.state.sellerSearchController.abort();
        }

        const controller = new AbortController();
        this.state.sellerSearchController = controller;

        container.innerHTML = '<div class="seller-search-item">Se caută...</div>';
        container.classList.add('show');

        try {
            const url = `${this.config.apiEndpoints.sellerSearch}?q=${encodeURIComponent(query)}&limit=10`;
            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                signal: controller.signal
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            const sellers = data?.sellers || [];

            if (!sellers.length) {
                container.innerHTML = '<div class="seller-search-item">Nu s-au găsit furnizori</div>';
                return;
            }

            container.innerHTML = sellers.map((seller, idx) => {
                const id = Number.parseInt(seller.id, 10);
                const safeId = Number.isFinite(id) ? id : '';
                const safeName = this.escapeHtml(seller.name || '');
                const detailsParts = [];
                if (seller.code) {
                    detailsParts.push(`Cod: ${seller.code}`);
                }
                if (seller.contact_person) {
                    detailsParts.push(seller.contact_person);
                }
                if (seller.city) {
                    detailsParts.push(seller.city);
                }
                const detailsText = detailsParts.length ? `<span class="seller-item-details">${this.escapeHtml(detailsParts.join(' • '))}</span>` : '';
                return `
                    <div class="seller-search-item" data-id="${safeId}" data-name="${safeName}" data-index="${idx}">
                        <span class="seller-item-name">${safeName}</span>
                        ${detailsText}
                    </div>
                `;
            }).join('');

            container.querySelectorAll('.seller-search-item').forEach((item) => {
                item.addEventListener('click', () => {
                    const id = item.getAttribute('data-id');
                    const name = item.getAttribute('data-name');
                    this.selectStockSeller(id, name);
                });
            });
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            console.error('Seller search error', error);
            container.innerHTML = '<div class="seller-search-item">Eroare la căutare</div>';
        } finally {
            if (this.state.sellerSearchController === controller) {
                this.state.sellerSearchController = null;
            }
        }
    },

    selectStockSeller(id, name) {
        this.setStockSeller(id, name);
        this.hideSellerResults();
    },

    setStockSeller(id = '', name = '') {
        const normalizedId = id ? String(id) : '';
        if (this.elements.stockSellerId) {
            this.elements.stockSellerId.value = normalizedId;
        }
        if (this.elements.stockSellerSearch) {
            this.elements.stockSellerSearch.value = name || '';
        }
        const hasSeller = Boolean(normalizedId);
        if (this.elements.assignedSupplier) {
            this.elements.assignedSupplier.textContent = hasSeller ? name : 'Neasignat';
        }
        if (this.elements.selectedSellerInfo) {
            this.elements.selectedSellerInfo.classList.toggle('unassigned', !hasSeller);
        }
        if (this.elements.clearStockSeller) {
            this.elements.clearStockSeller.style.display = hasSeller ? 'inline-flex' : 'none';
        }
        this.updateAutoOrderWarning();
    },

    clearStockSeller() {
        this.setStockSeller('', '');
        if (this.elements.stockSellerSearch) {
            this.elements.stockSellerSearch.focus();
        }
        this.hideSellerResults();
    },

    updateAutoOrderWarning() {
        if (!this.elements.noSupplierWarning) return;
        const autoOrderEnabled = Boolean(this.elements.autoOrderEnabled?.checked);
        const hasSeller = Boolean(this.elements.stockSellerId?.value);
        this.elements.noSupplierWarning.style.display = autoOrderEnabled && !hasSeller ? 'flex' : 'none';
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
                const expiry = response.token_expiry ? `Token valabil până: ${response.token_expiry}` : 'Token valabil nelimitat';
                this.showSuccess(`Conexiunea la Cargus este funcțională. ${expiry}`);
            } else {
                this.showError(response.error || 'Eroare la conexiunea cu Cargus.');
            }
        } catch (error) {
            console.error('Error testing Cargus connection:', error);
            this.showError(`Eroare la testarea conexiunii: ${error.message}`);
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

    isValidEmail(email) {
        if (typeof email !== 'string') {
            return false;
        }
        const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return pattern.test(email.trim());
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
        this.showNotification(message, 'success');
    },

    showError(message) {
        this.showNotification(message, 'error');
    },

    showInfo(message) {
        this.showNotification(message, 'info');
    },

    showNotification(message, type = 'info') {
        if (!message) {
            return;
        }
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <span class="material-symbols-outlined">
                ${type === 'success' ? 'check_circle' : type === 'error' ? 'error' : 'info'}
            </span>
            <span>${this.escapeHtml(String(message))}</span>
            <button class="notification-close" type="button" aria-label="Închide notificarea">
                <span class="material-symbols-outlined">close</span>
            </button>
        `;

        let container = document.getElementById('notificationContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'notificationContainer';
            container.className = 'notification-container';
            document.body.appendChild(container);
        }

        notification.querySelector('.notification-close')?.addEventListener('click', () => {
            notification.remove();
        });

        container.appendChild(notification);

        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
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
    applyStockFilters() {
        if (this.elements.stockCategoryFilter) {
            this.elements.stockCategoryFilter.value = this.state.stockCategory || '';
        }

        this.state.stockPagination.offset = 0;
        return this.loadStockSettings();
    },

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
        tbody.innerHTML = this.state.stockSettings.map((s) => {
            const indicators = this.buildStockStatusIndicators(s);
            const lastOrder = s.last_auto_order_date ? new Date(s.last_auto_order_date).toLocaleString('ro-RO') : null;
            const hasMinStockLevel = s.min_stock_level !== null && s.min_stock_level !== undefined;
            const stockWarning = hasMinStockLevel && s.min_stock_level > 0 && s.current_stock <= s.min_stock_level;
            const currentStockDisplay = (s.current_stock !== null && s.current_stock !== undefined) ? s.current_stock : '-';
            const minStockDisplay = hasMinStockLevel ? s.min_stock_level : '-';
            const minOrderDisplay = (s.min_order_quantity !== null && s.min_order_quantity !== undefined) ? s.min_order_quantity : '-';
            const lastOrderDisplay = lastOrder ? this.escapeHtml(lastOrder) : '-';
            return `
                <tr class="stock-row" data-product-id="${s.product_id}">
                    <td>
                        <div class="stock-product-info">
                            <strong>${this.escapeHtml(s.product_name)}</strong>
                            <small>${this.escapeHtml(s.sku)}</small>
                        </div>
                    </td>
                    <td>${this.escapeHtml(s.supplier_name || 'Neasignat')}</td>
                    <td class="${stockWarning ? 'stock-level-warning' : ''}">${currentStockDisplay}</td>
                    <td>${minStockDisplay}</td>
                    <td>${minOrderDisplay}</td>
                    <td><div class="auto-order-status">${indicators.join('')}</div></td>
                    <td>${lastOrderDisplay}</td>
                    <td>
                        <div class="action-group">
                            <button class="btn btn-sm btn-test-auto-order" data-action="test-auto-order" data-product-id="${s.product_id}" data-product-name="${this.escapeHtml(s.product_name)}">${AUTO_ORDER_UI_TEXT.actions.test}</button>
                            <button class="btn btn-sm btn-secondary" onclick="ProductUnitsApp.editStockSetting(${s.product_id})"><span class="material-symbols-outlined">edit</span><span>${AUTO_ORDER_UI_TEXT.actions.configure}</span></button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    },

    buildStockStatusIndicators(setting) {
        const indicators = [];
        if (setting.auto_order_enabled) {
            indicators.push(`<span class="status-indicator status-enabled">${AUTO_ORDER_UI_TEXT.statuses.enabled}</span>`);
            if (setting.min_stock_level > 0 && setting.current_stock <= setting.min_stock_level) {
                indicators.push(`<span class="status-indicator status-warning">${AUTO_ORDER_UI_TEXT.statuses.belowMinimum}</span>`);
            } else {
                indicators.push(`<span class="status-indicator status-ready">${AUTO_ORDER_UI_TEXT.statuses.ready}</span>`);
            }
        } else {
            indicators.push(`<span class="status-indicator status-disabled">${AUTO_ORDER_UI_TEXT.statuses.disabled}</span>`);
        }

        if (!setting.seller_id) {
            indicators.push(`<span class="status-indicator status-error">${AUTO_ORDER_UI_TEXT.statuses.noSupplier}</span>`);
        }

        return indicators;
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
        this.setStockSeller('', '');
        this.hideSellerResults();
        if (this.elements.stockPriceRon) this.elements.stockPriceRon.value = '';
        if (this.elements.stockPriceEur) this.elements.stockPriceEur.value = '';
        if (this.elements.currentStockInfo) this.elements.currentStockInfo.textContent = '0';
        if (this.elements.noSupplierWarning) this.elements.noSupplierWarning.style.display = 'none';

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
        this.setStockSeller('', '');
        this.hideSellerResults();
        if (this.elements.stockPriceRon) this.elements.stockPriceRon.value = '';
        if (this.elements.stockPriceEur) this.elements.stockPriceEur.value = '';
    },

    async saveStockSettings() {
        const rawProductId = this.elements.stockProductId?.value ?? '';
        const parsedProductId = Number(rawProductId);

        if (!rawProductId || !Number.isFinite(parsedProductId) || parsedProductId <= 0) {
            this.showError('Selectați un produs valid.');
            return;
        }

        const minStockValue = Number.parseInt(this.elements.minStockLevel?.value ?? '0', 10);
        const minOrderValue = Number.parseInt(this.elements.minOrderQty?.value ?? '1', 10);
        const sellerIdValue = (this.elements.stockSellerId?.value || '').trim();
        let sellerId = null;
        if (sellerIdValue !== '') {
            const parsedSellerId = Number(sellerIdValue);
            if (Number.isFinite(parsedSellerId) && parsedSellerId > 0) {
                sellerId = parsedSellerId;
            }
        }

        const priceRonRaw = this.elements.stockPriceRon?.value ?? '';
        const priceEurRaw = this.elements.stockPriceEur?.value ?? '';

        const normalizePrice = (value) => {
            if (typeof value !== 'string') {
                return null;
            }
            const trimmed = value.trim();
            if (trimmed === '') {
                return null;
            }
            const normalized = trimmed.replace(/\s+/g, '');
            const parsed = Number.parseFloat(normalized.replace(/,/g, '.'));
            if (!Number.isFinite(parsed) || parsed < 0) {
                return NaN;
            }
            return parsed;
        };

        const priceRon = normalizePrice(priceRonRaw);
        if (Number.isNaN(priceRon)) {
            this.showError('Introduceți un preț RON valid.');
            return;
        }

        const priceEur = normalizePrice(priceEurRaw);
        if (Number.isNaN(priceEur)) {
            this.showError('Introduceți un preț EUR valid.');
            return;
        }

        const data = {
            product_id: parsedProductId,
            min_stock_level: Number.isFinite(minStockValue) && minStockValue >= 0 ? minStockValue : 0,
            min_order_quantity: Number.isFinite(minOrderValue) && minOrderValue > 0 ? minOrderValue : 1,
            auto_order_enabled: Boolean(this.elements.autoOrderEnabled?.checked),
            seller_id: sellerId,
            price: priceRon === null ? null : priceRon,
            price_eur: priceEur === null ? null : priceEur
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
        const id = Number(productId);
        if (!Number.isFinite(id)) {
            return;
        }

        const setting = this.state.stockSettings.find((s) => Number(s.product_id) === id);
        if (!setting) {
            return;
        }

        this.openStockModal();

        const productName = setting.product_name || setting.name || '';
        this.selectProduct(id, productName, 'stockProduct');

        if (this.elements.minStockLevel) {
            this.elements.minStockLevel.value = setting.min_stock_level ?? 0;
        }

        if (this.elements.minOrderQty) {
            this.elements.minOrderQty.value = setting.min_order_quantity ?? 1;
        }

        if (this.elements.autoOrderEnabled) {
            this.elements.autoOrderEnabled.checked = Boolean(setting.auto_order_enabled);
        }

        this.setStockSeller(
            setting.seller_id ? String(setting.seller_id) : '',
            setting.supplier_name || ''
        );

        if (this.elements.currentStockInfo) {
            this.elements.currentStockInfo.textContent = setting.current_stock ?? '-';
        }
        if (this.elements.stockPriceRon) {
            const ronValue = setting.price;
            this.elements.stockPriceRon.value = (ronValue !== null && ronValue !== undefined) ? ronValue : '';
        }
        if (this.elements.stockPriceEur) {
            const eurValue = setting.price_eur;
            this.elements.stockPriceEur.value = (eurValue !== null && eurValue !== undefined) ? eurValue : '';
        }
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