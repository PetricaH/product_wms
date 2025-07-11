// File: scripts/locations.js
// JavaScript functionality for the locations page

document.addEventListener('DOMContentLoaded', function() {
    console.log('Locations page loaded');
});

// Modal functions
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Adaugă Locație';
    document.getElementById('formAction').value = 'create';
    document.getElementById('locationId').value = '';
    document.getElementById('submitBtn').textContent = 'Salvează';
    
    // Clear form
    document.getElementById('locationForm').reset();
    
    // Show modal
    document.getElementById('locationModal').classList.add('show');
}

function openEditModal(location) {
    document.getElementById('modalTitle').textContent = 'Editează Locație';
    document.getElementById('formAction').value = 'update';
    document.getElementById('locationId').value = location.id;
    document.getElementById('submitBtn').textContent = 'Actualizează';
    
    // Populate form
    document.getElementById('location_code').value = location.location_code;
    document.getElementById('zone').value = location.zone;
    document.getElementById('type').value = location.type || 'shelf';
    document.getElementById('capacity').value = location.capacity || '';
    
    // Convert database enum to form value
    const statusValue = location.status === 'active' ? '1' : '0';
    document.getElementById('status').value = statusValue;
    
    document.getElementById('description').value = location.notes || ''; // Use 'notes' field
    
    // Show modal
    document.getElementById('locationModal').classList.add('show');
}

function closeModal() {
    document.getElementById('locationModal').classList.remove('show');
}

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

// Form validation
document.getElementById('locationForm').addEventListener('submit', function(event) {
    const locationCode = document.getElementById('location_code').value.trim();
    const zone = document.getElementById('zone').value.trim();
    
    if (!locationCode || !zone) {
        event.preventDefault();
        alert('Codul locației și zona sunt obligatorii!');
        return false;
    }
    
    // Validate location code format (optional)
    const codePattern = /^[A-Z0-9\-]+$/i;
    if (!codePattern.test(locationCode)) {
        event.preventDefault();
        alert('Codul locației poate conține doar litere, cifre și cratima (-)');
        return false;
    }
});

/**
 * Warehouse Visualization JavaScript
 * File: assets/js/warehouse-visualization.js
 * Production-ready implementation for WMS
 */

class WarehouseVisualization {
    constructor() {
        this.currentView = 'total';
        this.shelfData = [];
        this.isLoading = false;
        this.refreshInterval = null;
        this.selectedShelf = null;
        
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadWarehouseData();
        this.setupKeyboardNavigation();
        this.startPeriodicRefresh();
    }

    bindEvents() {
        // View mode buttons
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const view = e.currentTarget.dataset.view;
                if (view === 'table') {
                    this.toggleTableView();
                } else {
                    this.switchView(view);
                }
            });
        });

        // Filter change events
        const filterElements = document.querySelectorAll('#zoneFilter, #typeFilter, #searchInput');
        filterElements.forEach(element => {
            if (element) {
                element.addEventListener('input', this.debounce(() => {
                    this.refreshWarehouseData();
                }, 300));
            }
        });

        // Modal events
        const editBtn = document.getElementById('editLocationBtn');
        if (editBtn) {
            editBtn.addEventListener('click', () => {
                if (this.selectedShelf) {
                    window.location.href = `locations.php?action=edit&id=${this.selectedShelf.id}`;
                }
            });
        }
    }

    loadWarehouseData() {
        if (!window.warehouseData || !Array.isArray(window.warehouseData)) {
            this.handleEmptyData();
            return;
        }

        this.shelfData = window.warehouseData.map(location => {
            return {
                id: parseInt(location.id),
                code: location.location_code,
                zone: location.zone,
                type: location.type || 'shelf',
                capacity: parseInt(location.capacity) || 0,
                levelCapacity: parseInt(location.level_capacity) || 0,
                totalItems: parseInt(location.total_items) || 0,
                occupancy: {
                    total: parseFloat(location.occupancy?.total) || 0,
                    bottom: parseFloat(location.occupancy?.bottom) || 0,
                    middle: parseFloat(location.occupancy?.middle) || 0,
                    top: parseFloat(location.occupancy?.top) || 0
                },
                items: {
                    total: parseInt(location.total_items) || 0,
                    bottom: parseInt(location.bottom_items) || 0,
                    middle: parseInt(location.middle_items) || 0,
                    top: parseInt(location.top_items) || 0
                },
                status: location.status || 'active',
                uniqueProducts: parseInt(location.unique_products) || 0,
                lastUpdated: new Date()
            };
        });

        this.renderWarehouse();
        this.updateStats();
    }

    renderWarehouse() {
        const shelvesGrid = document.getElementById('shelvesGrid');
        if (!shelvesGrid) return;

        if (this.shelfData.length === 0) {
            shelvesGrid.innerHTML = this.getEmptyState();
            return;
        }

        shelvesGrid.className = `shelves-grid view-mode-${this.currentView}`;
        shelvesGrid.innerHTML = '';

        // Sort shelves by zone and code
        const sortedShelves = [...this.shelfData].sort((a, b) => {
            if (a.zone !== b.zone) return a.zone.localeCompare(b.zone);
            return a.code.localeCompare(b.code);
        });

        sortedShelves.forEach(shelf => {
            const shelfElement = this.createShelfElement(shelf);
            shelvesGrid.appendChild(shelfElement);
        });
    }

    createShelfElement(shelf) {
        const shelfDiv = document.createElement('div');
        shelfDiv.className = 'shelf-container';
        shelfDiv.dataset.shelfId = shelf.id;
        shelfDiv.setAttribute('tabindex', '0');
        shelfDiv.setAttribute('role', 'button');
        shelfDiv.setAttribute('aria-label', `Locația ${shelf.code}, ocupare ${shelf.occupancy.total}%`);

        const occupancyValue = this.currentView === 'total' 
            ? shelf.occupancy.total 
            : shelf.occupancy[this.currentView];
        const occupancyClass = this.getOccupancyClass(occupancyValue);

        shelfDiv.innerHTML = `
            <div class="shelf-header">
                <div class="shelf-code">${this.escapeHtml(shelf.code)}</div>
                <div class="shelf-occupancy-badge ${occupancyClass}">${occupancyValue.toFixed(1)}%</div>
            </div>
            <div class="shelf-levels">
                ${this.createLevelElements(shelf)}
            </div>
        `;

        // Event listeners
        shelfDiv.addEventListener('click', () => this.selectShelf(shelf));
        shelfDiv.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.selectShelf(shelf);
            }
        });

        shelfDiv.addEventListener('mouseenter', (e) => this.showTooltip(e, shelf));
        shelfDiv.addEventListener('mouseleave', () => this.hideTooltip());

        return shelfDiv;
    }

    createLevelElements(shelf) {
        const levels = ['top', 'middle', 'bottom'];
        
        return levels.map(level => {
            const occupancy = shelf.occupancy[level];
            const items = shelf.items[level];
            const isActive = this.currentView === level;
            const occupancyClass = this.getOccupancyClass(occupancy);
            
            return `
                <div class="shelf-level ${isActive ? 'active' : ''}" data-level="${level}">
                    <div class="level-fill ${occupancyClass}" 
                         style="width: ${occupancy}%"
                         title="${this.capitalizeFirst(level)}: ${occupancy.toFixed(1)}% (${items}/${shelf.levelCapacity} articole)">
                    </div>
                </div>
            `;
        }).join('');
    }

    getOccupancyClass(percentage) {
        if (percentage === 0) return 'occupancy-empty';
        if (percentage <= 50) return 'occupancy-low';
        if (percentage <= 79) return 'occupancy-medium';
        if (percentage <= 94) return 'occupancy-high';
        return 'occupancy-full';
    }

    switchView(newView) {
        this.currentView = newView;

        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelector(`[data-view="${newView}"]`)?.classList.add('active');

        const warehouseSection = document.getElementById('warehouseVisualization');
        const tableSection = document.querySelector('.table-responsive');
        if (warehouseSection && tableSection) {
            warehouseSection.classList.remove('d-none');
            tableSection.classList.add('d-none');
        }

        this.renderWarehouse();
    }

    toggleTableView() {
        const warehouseSection = document.getElementById('warehouseVisualization');
        const tableSection = document.querySelector('.table-responsive');

        if (warehouseSection && tableSection) {
            warehouseSection.classList.add('d-none');
            tableSection.classList.remove('d-none');
        }
    }

    async selectShelf(shelf) {
        this.selectedShelf = shelf;
        await this.showLocationDetails(shelf);
    }

    async showLocationDetails(shelf) {
        const modal = new bootstrap.Modal(document.getElementById('locationDetailsModal'));
        const content = document.getElementById('locationDetailsContent');
        
        if (!content) return;

        content.innerHTML = this.getModalLoadingState();
        modal.show();

        try {
            const response = await fetch('locations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_location_details&id=${shelf.id}`
            });

            const data = await response.json();
            
            if (data.success) {
                content.innerHTML = this.generateLocationDetailsHTML(data.location, shelf);
            } else {
                content.innerHTML = this.getModalErrorState(data.message || 'Eroare la încărcarea datelor.');
            }
        } catch (error) {
            console.error('Error fetching location details:', error);
            content.innerHTML = this.getModalErrorState('Eroare de conexiune.');
        }
    }

    generateLocationDetailsHTML(locationData, shelf) {
        return `
            <div class="location-details">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="mb-3"><i class="material-symbols-outlined me-2">info</i>Informații Generale</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Cod Locație:</strong></td><td><code>${this.escapeHtml(shelf.code)}</code></td></tr>
                            <tr><td><strong>Zonă:</strong></td><td>${this.escapeHtml(shelf.zone)}</td></tr>
                            <tr><td><strong>Tip:</strong></td><td>${this.escapeHtml(shelf.type)}</td></tr>
                            <tr><td><strong>Capacitate Totală:</strong></td><td>${shelf.capacity} articole</td></tr>
                            <tr><td><strong>Capacitate/Nivel:</strong></td><td>${shelf.levelCapacity} articole</td></tr>
                            <tr><td><strong>Produse Unice:</strong></td><td>${shelf.uniqueProducts}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="mb-3"><i class="material-symbols-outlined me-2">analytics</i>Ocupare pe Nivele</h6>
                        <div class="level-breakdown">
                            ${this.generateLevelBreakdown(shelf)}
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12">
                        <h6 class="mb-3"><i class="material-symbols-outlined me-2">inventory</i>Distribuție Articole</h6>
                        <div class="progress-group">
                            <div class="progress mb-2" style="height: 25px;">
                                <div class="progress-bar ${this.getProgressBarClass(shelf.occupancy.total)}" 
                                     style="width: ${shelf.occupancy.total}%">
                                    ${shelf.occupancy.total.toFixed(1)}% (${shelf.items.total}/${shelf.capacity})
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    generateLevelBreakdown(shelf) {
        const levels = [
            { key: 'top', label: 'Nivel Superior', icon: 'vertical_align_top' },
            { key: 'middle', label: 'Nivel Mijloc', icon: 'horizontal_rule' },
            { key: 'bottom', label: 'Nivel Inferior', icon: 'vertical_align_bottom' }
        ];

        return levels.map(level => {
            const occupancy = shelf.occupancy[level.key];
            const items = shelf.items[level.key];
            const progressClass = this.getProgressBarClass(occupancy);
            
            return `
                <div class="level-row mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="level-label">
                            <i class="material-symbols-outlined me-1" style="font-size: 1rem;">${level.icon}</i>
                            ${level.label}
                        </span>
                        <span class="level-value">${occupancy.toFixed(1)}%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar ${progressClass}" 
                             style="width: ${occupancy}%"
                             title="${items}/${shelf.levelCapacity} articole">
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    getProgressBarClass(percentage) {
        if (percentage === 0) return 'bg-light';
        if (percentage <= 50) return 'bg-success';
        if (percentage <= 79) return 'bg-warning';
        if (percentage <= 94) return 'bg-danger';
        return 'bg-dark';
    }

    updateStats() {
        if (this.shelfData.length === 0) return;

        const totalOccupancy = this.shelfData.reduce((sum, shelf) => sum + shelf.occupancy.total, 0) / this.shelfData.length;
        const occupiedShelves = this.shelfData.filter(shelf => shelf.totalItems > 0).length;
        const totalItems = this.shelfData.reduce((sum, shelf) => sum + shelf.totalItems, 0);
        const highOccupancyShelves = this.shelfData.filter(shelf => shelf.occupancy.total >= 80).length;

        // Update stat cards
        const statValues = document.querySelectorAll('.stat-value');
        if (statValues[0]) statValues[0].textContent = `${totalOccupancy.toFixed(1)}%`;
        if (statValues[1]) statValues[1].textContent = `${occupiedShelves}/${this.shelfData.length}`;
        if (statValues[2]) statValues[2].textContent = totalItems.toLocaleString();
        if (statValues[3]) statValues[3].textContent = highOccupancyShelves;
    }

    async refreshWarehouseData() {
        if (this.isLoading) return;
        
        this.isLoading = true;
        
        try {
            const currentFilters = window.currentFilters || {};
            const params = new URLSearchParams({
                action: 'get_warehouse_data',
                zone: currentFilters.zone || '',
                type: currentFilters.type || '',
                search: currentFilters.search || ''
            });

            const response = await fetch(`locations.php?${params}`);
            const data = await response.json();
            
            if (data.success) {
                window.warehouseData = data.locations;
                this.loadWarehouseData();
            }
        } catch (error) {
            console.error('Error refreshing warehouse data:', error);
        } finally {
            this.isLoading = false;
        }
    }

    setupKeyboardNavigation() {
        document.addEventListener('keydown', (e) => {
            if (e.target.classList.contains('shelf-container')) {
                const shelves = Array.from(document.querySelectorAll('.shelf-container'));
                const currentIndex = shelves.indexOf(e.target);
                let nextIndex = currentIndex;

                switch (e.key) {
                    case 'ArrowRight':
                        nextIndex = Math.min(currentIndex + 1, shelves.length - 1);
                        break;
                    case 'ArrowLeft':
                        nextIndex = Math.max(currentIndex - 1, 0);
                        break;
                    case 'ArrowDown':
                        nextIndex = Math.min(currentIndex + 6, shelves.length - 1);
                        break;
                    case 'ArrowUp':
                        nextIndex = Math.max(currentIndex - 6, 0);
                        break;
                    default:
                        return;
                }

                if (nextIndex !== currentIndex) {
                    e.preventDefault();
                    shelves[nextIndex].focus();
                }
            }
        });
    }

    startPeriodicRefresh() {
        // Refresh every 30 seconds
        this.refreshInterval = setInterval(() => {
            this.refreshWarehouseData();
        }, 30000);
    }

    stopPeriodicRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    }

    showTooltip(event, shelf) {
        // Simple browser tooltip via title attribute (already handled in createLevelElements)
        // Can be enhanced with custom tooltip library if needed
    }

    hideTooltip() {
        // Handled by browser for title tooltips
    }

    handleEmptyData() {
        const shelvesGrid = document.getElementById('shelvesGrid');
        if (shelvesGrid) {
            shelvesGrid.innerHTML = this.getEmptyState();
        }
    }

    getEmptyState() {
        return `
            <div class="warehouse-empty">
                <i class="material-symbols-outlined">inventory</i>
                <h5>Nu există locații disponibile</h5>
                <p>Nu au fost găsite locații care să corespundă criteriilor de filtrare.</p>
            </div>
        `;
    }

    getModalLoadingState() {
        return `
            <div class="warehouse-loading">
                <i class="material-symbols-outlined">refresh</i>
                Încărcare detalii...
            </div>
        `;
    }

    getModalErrorState(message) {
        return `
            <div class="alert alert-danger">
                <i class="material-symbols-outlined me-2">error</i>
                ${this.escapeHtml(message)}
            </div>
        `;
    }

    // Utility methods
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    capitalizeFirst(str) {
        return str.charAt(0).toUpperCase() + str.slice(1);
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

    // Cleanup method
    destroy() {
        this.stopPeriodicRefresh();
        // Remove event listeners if needed
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const warehouseEl = document.getElementById('warehouseVisualization');
    const tableEl = document.querySelector('.table-responsive');
    const controls = document.querySelector('.view-controls');

    if (window.innerWidth <= 768) {
        warehouseEl?.classList.add('d-none');
        tableEl?.classList.remove('d-none');
        controls?.classList.add('d-none');
    } else if (warehouseEl && tableEl) {
        tableEl.classList.add('d-none');
    }

    if (warehouseEl) {
        window.warehouseViz = new WarehouseVisualization();
    }
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (window.warehouseViz) {
        window.warehouseViz.destroy();
    }
});