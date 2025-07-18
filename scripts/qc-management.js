/**
 * QC Management JavaScript
 * File: scripts/qc-management.js
 * 
 * Handles all functionality for the Quality Control management interface
 */

'use strict';

// Main QC Manager Object
const QCManager = {
    // State management
    state: {
        currentTab: 'pending',
        selectedItems: new Set(),
        filters: {
            location_type: '',
            date_from: '',
            date_to: ''
        },
        pagination: {
            limit: 20,
            offset: 0,
            total: 0
        },
        isLoading: false
    },
    
    // API endpoints
    api: {
        qcManagement: 'api/qc_management.php',
        locations: 'api/qc_management.php?path=qc-locations'
    },
    
    // DOM elements cache
    elements: {},
    
    // Initialize the application
    init() {
        this.cacheElements();
        this.bindEvents();
        this.loadInitialData();
        this.setupAutoRefresh();
        
        console.log('QC Manager initialized successfully');
    },
    
    // Cache frequently used DOM elements
    cacheElements() {
        this.elements = {
            // Tabs
            tabButtons: document.querySelectorAll('.tab-btn'),
            tabContents: document.querySelectorAll('.tab-content'),
            
            // Stats
            statsGrid: document.getElementById('stats-grid'),
            pendingCount: document.getElementById('pending-count'),
            
            // Bulk actions
            bulkActions: document.getElementById('bulk-actions'),
            selectedCount: document.getElementById('selected-count'),
            
            // Filters
            locationFilter: document.getElementById('location-filter'),
            dateFromFilter: document.getElementById('date-from'),
            dateToFilter: document.getElementById('date-to'),
            
            // Content containers
            pendingContainer: document.getElementById('pending-items-container'),
            approvedContainer: document.getElementById('approved-items-container'),
            rejectedContainer: document.getElementById('rejected-items-container'),
            historyContainer: document.getElementById('history-container'),
            paginationContainer: document.getElementById('pagination-container'),
            
            // Modals
            bulkApproveModal: document.getElementById('bulk-approve-modal'),
            bulkRejectModal: document.getElementById('bulk-reject-modal'),
            loadingOverlay: document.getElementById('loading-overlay'),
            
            // Forms
            bulkApproveForm: document.getElementById('bulk-approve-form'),
            bulkRejectForm: document.getElementById('bulk-reject-form'),
            
            // Modal fields
            approveNotes: document.getElementById('approve-notes'),
            moveToLocation: document.getElementById('move-to-location'),
            rejectReason: document.getElementById('reject-reason'),
            rejectNotes: document.getElementById('reject-notes'),
            approveCount: document.getElementById('approve-count'),
            rejectCount: document.getElementById('reject-count'),
            
            // Alert container
            alertContainer: document.getElementById('alert-container')
        };
    },
    
    // Bind event listeners
    bindEvents() {
        // Tab navigation
        this.elements.tabButtons.forEach(btn => {
            btn.addEventListener('click', (e) => {
                const tab = e.currentTarget.dataset.tab;
                this.switchTab(tab);
            });
        });
        
        // Global checkbox for select all
        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('select-all-checkbox')) {
                this.handleSelectAll(e.target.checked);
            } else if (e.target.classList.contains('item-checkbox')) {
                this.handleItemSelection(e.target);
            }
        });
        
        // Individual item actions
        document.addEventListener('click', (e) => {
            if (e.target.closest('.approve-single-btn')) {
                const itemId = e.target.closest('.qc-item-card').dataset.itemId;
                this.approveSingleItem(itemId);
            } else if (e.target.closest('.reject-single-btn')) {
                const itemId = e.target.closest('.qc-item-card').dataset.itemId;
                this.rejectSingleItem(itemId);
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'a':
                        e.preventDefault();
                        this.selectAllVisibleItems();
                        break;
                    case 'r':
                        e.preventDefault();
                        this.refreshData();
                        break;
                }
            }
        });
    },
    
    // Load initial data
    async loadInitialData() {
        try {
            this.showLoading();
            
            // Load QC locations for dropdown
            await this.loadQcLocations();
            
            // Load statistics
            await this.loadStats();
            
            // Load pending items (default tab)
            await this.loadPendingItems();
            
        } catch (error) {
            console.error('Error loading initial data:', error);
            this.showAlert('Eroare la încărcarea datelor inițiale', 'error');
        } finally {
            this.hideLoading();
        }
    },
    
    // Load QC statistics
    async loadStats() {
        try {
            const response = await fetch(`${this.api.qcManagement}?path=qc-stats&timeframe=30`);
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.message || 'Failed to load stats');
            }
            
            this.renderStats(data.stats, data.location_breakdown);
            
            // Update pending count badge
            if (this.elements.pendingCount) {
                this.elements.pendingCount.textContent = data.stats.pending_count || 0;
            }
            
        } catch (error) {
            console.error('Error loading stats:', error);
        }
    },
    
    // Render statistics
    renderStats(stats, locationBreakdown) {
        if (!this.elements.statsGrid) return;
        
        const statsHtml = `
            <div class="stat-card">
                <span class="stat-number pending">${stats.pending_count || 0}</span>
                <span class="stat-label">În Așteptare</span>
            </div>
            <div class="stat-card">
                <span class="stat-number approved">${stats.approved_count || 0}</span>
                <span class="stat-label">Aprobate</span>
            </div>
            <div class="stat-card">
                <span class="stat-number rejected">${stats.rejected_count || 0}</span>
                <span class="stat-label">Respinse</span>
            </div>
            <div class="stat-card">
                <span class="stat-number total">${stats.total_items || 0}</span>
                <span class="stat-label">Total Articole</span>
            </div>
            <div class="stat-card">
                <span class="stat-number">${stats.damaged_count || 0}</span>
                <span class="stat-label">Deteriorate</span>
            </div>
            <div class="stat-card">
                <span class="stat-number">${stats.discrepancy_count || 0}</span>
                <span class="stat-label">Discrepanțe</span>
            </div>
        `;
        
        this.elements.statsGrid.innerHTML = statsHtml;
    },
    
    // Load pending items
    async loadPendingItems() {
        try {
            const params = new URLSearchParams({
                path: 'pending-items',
                status: 'pending',
                limit: this.state.pagination.limit,
                offset: this.state.pagination.offset,
                ...this.state.filters
            });
            
            const response = await fetch(`${this.api.qcManagement}?${params}`);
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.message || 'Failed to load pending items');
            }
            
            this.renderPendingItems(data.data);
            this.renderPagination(data.pagination);
            this.state.pagination.total = data.total;
            
        } catch (error) {
            console.error('Error loading pending items:', error);
            this.showAlert('Eroare la încărcarea articolelor în așteptare', 'error');
        }
    },
    
    // Render pending items
    renderPendingItems(items) {
        if (!this.elements.pendingContainer) return;
        
        if (!items || items.length === 0) {
            this.elements.pendingContainer.innerHTML = `
                <div class="empty-state">
                    <span class="material-symbols-outlined">check_circle</span>
                    <h3>Nu există articole în așteptare</h3>
                    <p>Toate articolele au fost procesate.</p>
                </div>
            `;
            return;
        }
        
        const itemsHtml = items.map(item => this.renderQcItemCard(item)).join('');
        
        const selectAllHtml = `
            <div class="select-all-section">
                <label class="checkbox-label">
                    <input type="checkbox" class="select-all-checkbox">
                    <span>Selectează toate articolele vizibile</span>
                </label>
            </div>
        `;
        
        this.elements.pendingContainer.innerHTML = selectAllHtml + itemsHtml;
    },
    
    // Render QC item card
    renderQcItemCard(item) {
        const quantityDiscrepancy = item.received_quantity !== item.expected_quantity;
        const conditionClass = item.condition_status === 'good' ? 'condition-good' : 
                             item.condition_status === 'damaged' ? 'condition-damaged' : 'condition-defective';
        
        return `
            <div class="qc-item-card" data-item-id="${item.id}">
                <input type="checkbox" class="item-checkbox" value="${item.id}">
                <div class="item-content">
                    <div class="item-header">
                        <h3 class="item-title">${this.escapeHtml(item.product_name || item.internal_product_name)}</h3>
                        <div class="item-actions">
                            <button type="button" class="btn btn-sm btn-success approve-single-btn">
                                <span class="material-symbols-outlined">check_circle</span>
                                Aprobă
                            </button>
                            <button type="button" class="btn btn-sm btn-danger reject-single-btn">
                                <span class="material-symbols-outlined">cancel</span>
                                Respinge
                            </button>
                        </div>
                    </div>
                    
                    <div class="item-details">
                        <div class="detail-group">
                            <h4>Informații Produs</h4>
                            <p><strong>Cod:</strong> ${this.escapeHtml(item.product_code || item.internal_sku || 'N/A')}</p>
                            <p><strong>Comandă:</strong> ${this.escapeHtml(item.order_number || 'N/A')}</p>
                            <p><strong>Furnizor:</strong> ${this.escapeHtml(item.supplier_name || 'N/A')}</p>
                        </div>
                        
                        <div class="detail-group">
                            <h4>Cantități</h4>
                            <div class="quantity-comparison">
                                <span class="quantity-expected">Așteptat: ${item.expected_quantity}</span>
                                <span class="quantity-arrow">→</span>
                                <span class="quantity-received">Primit: ${item.received_quantity}</span>
                                ${quantityDiscrepancy ? `<span class="quantity-difference ${item.received_quantity > item.expected_quantity ? 'over' : 'short'}">
                                    ${item.received_quantity > item.expected_quantity ? '+' : ''}${item.received_quantity - item.expected_quantity}
                                </span>` : ''}
                            </div>
                        </div>
                        
                        <div class="detail-group">
                            <h4>Status & Locație</h4>
                            <p><strong>Stare:</strong> <span class="${conditionClass}">${this.translateCondition(item.condition_status)}</span></p>
                            <p><strong>Locația:</strong> ${this.escapeHtml(item.location_code)} (${this.escapeHtml(item.zone)})</p>
                            <p><strong>Tip Locație:</strong> ${this.translateLocationType(item.location_type)}</p>
                        </div>
                        
                        <div class="detail-group">
                            <h4>Detalii Sesiune</h4>
                            <p><strong>Sesiune:</strong> ${this.escapeHtml(item.session_number)}</p>
                            <p><strong>Primit de:</strong> ${this.escapeHtml(item.received_by_user)}</p>
                            <p><strong>Data:</strong> ${this.formatDate(item.received_at)}</p>
                        </div>
                    </div>
                    
                    ${quantityDiscrepancy ? `
                        <div class="discrepancy-warning">
                            <span class="material-symbols-outlined">warning</span>
                            <div class="discrepancy-text">
                                <strong>Discrepanță de cantitate:</strong> 
                                ${item.discrepancy_description || `Așteptat ${item.expected_quantity}, primit ${item.received_quantity}`}
                            </div>
                        </div>
                    ` : ''}
                    
                    ${item.receiving_notes ? `
                        <div class="notes-section">
                            <h4>Note Primire:</h4>
                            <p>${this.escapeHtml(item.receiving_notes)}</p>
                        </div>
                    ` : ''}
                    
                    ${item.batch_number || item.expiry_date ? `
                        <div class="batch-info">
                            ${item.batch_number ? `<span><strong>Lot:</strong> ${this.escapeHtml(item.batch_number)}</span>` : ''}
                            ${item.expiry_date ? `<span><strong>Expirare:</strong> ${this.formatDate(item.expiry_date)}</span>` : ''}
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    },
    
    // Load QC locations for dropdown
    async loadQcLocations() {
        try {
            const response = await fetch(this.api.locations);
            const data = await response.json();
            
            if (data.success && this.elements.moveToLocation) {
                const options = data.locations.map(loc => 
                    `<option value="${loc.id}">${this.escapeHtml(loc.location_code)} - ${this.escapeHtml(loc.zone)}</option>`
                ).join('');
                
                this.elements.moveToLocation.innerHTML = 
                    '<option value="">Păstrează locația curentă</option>' + options;
            }
        } catch (error) {
            console.error('Error loading QC locations:', error);
        }
    },
    
    // Switch tabs
    switchTab(tabName) {
        this.state.currentTab = tabName;
        
        // Update tab buttons
        this.elements.tabButtons.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tabName);
        });
        
        // Update tab contents
        this.elements.tabContents.forEach(content => {
            content.classList.toggle('active', content.id === `${tabName}-tab`);
        });
        
        // Load tab-specific data
        this.loadTabData(tabName);
    },
    
    // Load data for specific tab
    async loadTabData(tabName) {
        try {
            this.showLoading();
            
            switch (tabName) {
                case 'pending':
                    await this.loadPendingItems();
                    break;
                case 'approved':
                    await this.loadApprovedItems();
                    break;
                case 'rejected':
                    await this.loadRejectedItems();
                    break;
                case 'history':
                    await this.loadDecisionHistory();
                    break;
            }
        } catch (error) {
            console.error(`Error loading ${tabName} data:`, error);
            this.showAlert(`Eroare la încărcarea datelor pentru ${tabName}`, 'error');
        } finally {
            this.hideLoading();
        }
    },
    
    // Handle item selection
    handleItemSelection(checkbox) {
        const itemId = parseInt(checkbox.value);
        
        if (checkbox.checked) {
            this.state.selectedItems.add(itemId);
        } else {
            this.state.selectedItems.delete(itemId);
        }
        
        this.updateBulkActionsVisibility();
        this.updateSelectedCount();
    },
    
    // Handle select all
    handleSelectAll(checked) {
        const itemCheckboxes = document.querySelectorAll('.item-checkbox');
        
        itemCheckboxes.forEach(checkbox => {
            checkbox.checked = checked;
            this.handleItemSelection(checkbox);
        });
    },
    
    // Update bulk actions visibility
    updateBulkActionsVisibility() {
        const hasSelected = this.state.selectedItems.size > 0;
        
        if (this.elements.bulkActions) {
            this.elements.bulkActions.style.display = hasSelected ? 'flex' : 'none';
        }
    },
    
    // Update selected count
    updateSelectedCount() {
        if (this.elements.selectedCount) {
            this.elements.selectedCount.textContent = this.state.selectedItems.size;
        }
    },
    
    // Clear selection
    clearSelection() {
        this.state.selectedItems.clear();
        
        const checkboxes = document.querySelectorAll('.item-checkbox, .select-all-checkbox');
        checkboxes.forEach(checkbox => checkbox.checked = false);
        
        this.updateBulkActionsVisibility();
        this.updateSelectedCount();
    },
    
    // Show bulk approve modal
    showBulkApproveModal() {
        if (this.state.selectedItems.size === 0) {
            this.showAlert('Selectați cel puțin un articol pentru aprobare', 'warning');
            return;
        }
        
        if (this.elements.approveCount) {
            this.elements.approveCount.textContent = this.state.selectedItems.size;
        }
        
        this.showModal(this.elements.bulkApproveModal);
    },
    
    // Show bulk reject modal
    showBulkRejectModal() {
        if (this.state.selectedItems.size === 0) {
            this.showAlert('Selectați cel puțin un articol pentru respingere', 'warning');
            return;
        }
        
        if (this.elements.rejectCount) {
            this.elements.rejectCount.textContent = this.state.selectedItems.size;
        }
        
        this.showModal(this.elements.bulkRejectModal);
    },
    
    // Confirm bulk approve
    async confirmBulkApprove() {
        try {
            const formData = {
                item_ids: Array.from(this.state.selectedItems),
                supervisor_notes: this.elements.approveNotes?.value || '',
                move_to_location: this.elements.moveToLocation?.value || null
            };
            
            this.showLoading();
            
            const response = await fetch(`${this.api.qcManagement}?path=approve-items`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.CSRF_TOKEN
                },
                body: JSON.stringify(formData)
            });
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Failed to approve items');
            }
            
            this.showAlert(`${data.approved_count} articole au fost aprobate cu succes`, 'success');
            this.closeBulkApproveModal();
            this.clearSelection();
            await this.refreshData();
            
        } catch (error) {
            console.error('Error approving items:', error);
            this.showAlert('Eroare la aprobarea articolelor: ' + error.message, 'error');
        } finally {
            this.hideLoading();
        }
    },
    
    // Confirm bulk reject
    async confirmBulkReject() {
        const reason = this.elements.rejectReason?.value;
        if (!reason) {
            this.showAlert('Motivul respingerii este obligatoriu', 'warning');
            return;
        }
        
        try {
            const formData = {
                item_ids: Array.from(this.state.selectedItems),
                rejection_reason: reason,
                supervisor_notes: this.elements.rejectNotes?.value || ''
            };
            
            this.showLoading();
            
            const response = await fetch(`${this.api.qcManagement}?path=reject-items`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.CSRF_TOKEN
                },
                body: JSON.stringify(formData)
            });
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Failed to reject items');
            }
            
            this.showAlert(`${data.rejected_count} articole au fost respinse`, 'success');
            this.closeBulkRejectModal();
            this.clearSelection();
            await this.refreshData();
            
        } catch (error) {
            console.error('Error rejecting items:', error);
            this.showAlert('Eroare la respingerea articolelor: ' + error.message, 'error');
        } finally {
            this.hideLoading();
        }
    },
    
    // Apply filters
    applyFilters() {
        this.state.filters = {
            location_type: this.elements.locationFilter?.value || '',
            date_from: this.elements.dateFromFilter?.value || '',
            date_to: this.elements.dateToFilter?.value || ''
        };
        
        this.state.pagination.offset = 0; // Reset pagination
        this.loadTabData(this.state.currentTab);
    },
    
    // Clear filters
    clearFilters() {
        if (this.elements.locationFilter) this.elements.locationFilter.value = '';
        if (this.elements.dateFromFilter) this.elements.dateFromFilter.value = '';
        if (this.elements.dateToFilter) this.elements.dateToFilter.value = '';
        
        this.applyFilters();
    },
    
    // Refresh all data
    async refreshData() {
        await this.loadStats();
        await this.loadTabData(this.state.currentTab);
    },
    
    // Setup auto refresh
    setupAutoRefresh() {
        // Refresh every 30 seconds
        setInterval(() => {
            if (!this.state.isLoading && this.state.currentTab === 'pending') {
                this.loadStats();
                this.loadPendingItems();
            }
        }, 30000);
    },
    
    // Modal management
    showModal(modal) {
        if (modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    },
    
    hideModal(modal) {
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    },
    
    closeBulkApproveModal() {
        this.hideModal(this.elements.bulkApproveModal);
        if (this.elements.bulkApproveForm) this.elements.bulkApproveForm.reset();
    },
    
    closeBulkRejectModal() {
        this.hideModal(this.elements.bulkRejectModal);
        if (this.elements.bulkRejectForm) this.elements.bulkRejectForm.reset();
    },
    
    // Loading management
    showLoading() {
        this.state.isLoading = true;
        if (this.elements.loadingOverlay) {
            this.elements.loadingOverlay.style.display = 'flex';
        }
    },
    
    hideLoading() {
        this.state.isLoading = false;
        if (this.elements.loadingOverlay) {
            this.elements.loadingOverlay.style.display = 'none';
        }
    },
    
    // Alert management
    showAlert(message, type = 'info') {
        if (!this.elements.alertContainer) return;
        
        const alertId = 'alert-' + Date.now();
        const alertHtml = `
            <div id="${alertId}" class="alert alert-${type} alert-dismissible">
                <span class="material-symbols-outlined">
                    ${type === 'success' ? 'check_circle' : type === 'error' ? 'error' : type === 'warning' ? 'warning' : 'info'}
                </span>
                <span class="alert-message">${this.escapeHtml(message)}</span>
                <button type="button" class="alert-close" onclick="document.getElementById('${alertId}').remove()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
        `;
        
        this.elements.alertContainer.insertAdjacentHTML('afterbegin', alertHtml);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            const alert = document.getElementById(alertId);
            if (alert) alert.remove();
        }, 5000);
    },
    
    // Utility functions
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },
    
    formatDate(dateString) {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('ro-RO', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    },
    
    translateCondition(condition) {
        const translations = {
            'good': 'Bună',
            'damaged': 'Deteriorată',
            'defective': 'Defectă'
        };
        return translations[condition] || condition;
    },
    
    translateLocationType(type) {
        const translations = {
            'qc_hold': 'Așteptare QC',
            'quarantine': 'Carantină',
            'pending_approval': 'Pending Aprobare'
        };
        return translations[type] || type;
    }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    QCManager.init();
});

// Export for global access
window.QCManager = QCManager;