/**
 * Enhanced Locations JavaScript - Complete Updated File
 * File: scripts/locations.js
 * Auto-populates zone from location_code and enhanced warehouse visualization
 * Includes all fixes and enhancements for storage-focused visualization
 */

document.addEventListener('DOMContentLoaded', function() {
    console.log('Enhanced Locations page loaded');
    
    // Initialize warehouse visualization if data exists
    if (typeof window.warehouseData !== 'undefined') {
        window.warehouseViz = new EnhancedWarehouseVisualization();
    }
    
    // Auto-populate zone when location_code changes
    const locationCodeInput = document.getElementById('location_code');
    const zoneInput = document.getElementById('zone');
    
    if (locationCodeInput && zoneInput) {
        locationCodeInput.addEventListener('input', function() {
            const locationCode = this.value.trim();
            if (locationCode && locationCode.includes('-')) {
                const extractedZone = locationCode.split('-')[0].toUpperCase();
                zoneInput.value = extractedZone;
                zoneInput.style.backgroundColor = 'var(--success-color-light, #d4edda)';
                
                // Show success message briefly
                showZoneAutoFill(extractedZone);
            } else {
                zoneInput.value = '';
                zoneInput.style.backgroundColor = '';
            }
        });
    }
});

// Show zone auto-fill message
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

// Modal functions (enhanced)
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

function openEditModal(location) {
    document.getElementById('modalTitle').textContent = 'Editează Locație';
    document.getElementById('formAction').value = 'update';
    document.getElementById('locationId').value = location.id;
    document.getElementById('submitBtn').textContent = 'Actualizează';
    
    // Populate form
    document.getElementById('location_code').value = location.location_code;
    document.getElementById('zone').value = location.zone;
    document.getElementById('type').value = location.type || 'Shelf';
    document.getElementById('capacity').value = location.capacity || '';
    
    // Convert database status to form value
    const statusValue = location.status === 'active' ? '1' : '0';
    document.getElementById('status').value = statusValue;
    
    document.getElementById('description').value = location.notes || '';
    
    // Show modal
    document.getElementById('locationModal').classList.add('show');
}

function closeModal() {
    document.getElementById('locationModal').classList.remove('show');
    
    // Clear any existing auto-fill messages
    const existingMessage = document.querySelector('.zone-autofill-message');
    if (existingMessage) {
        existingMessage.remove();
    }
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

// Enhanced form validation with validation support
document.getElementById('locationForm').addEventListener('submit', function(event) {
    const locationCode = document.getElementById('location_code').value.trim();
    const zone = document.getElementById('zone').value.trim();
    const type = document.getElementById('type').value;
    
    if (!locationCode || !zone) {
        event.preventDefault();
        alert('Codul locației și zona sunt obligatorii!');
        return false;
    }
    
    // Use enhanced validation if available
    if (window.locationValidation) {
        const validation = window.locationValidation.validateLocationCode(locationCode, type);
        if (!validation.valid) {
            event.preventDefault();
            alert('Erori de validare:\n' + validation.errors.join('\n'));
            return false;
        }
        
        // Validate that zone matches location code prefix for shelves
        if (type === 'Shelf' && validation.extractedZone && zone.toUpperCase() !== validation.extractedZone) {
            event.preventDefault();
            alert(`Zona "${zone}" nu corespunde cu prefixul din codul locației "${validation.extractedZone}"`);
            return false;
        }
    } else {
        // Fallback validation
        if (type === 'Shelf' && !locationCode.includes('-')) {
            event.preventDefault();
            alert('Pentru rafturi, codul locației trebuie să conțină cratimă (ex: MID-1A)');
            return false;
        }
        
        if (type === 'Shelf' && locationCode.includes('-')) {
            const extractedZone = locationCode.split('-')[0].toUpperCase();
            if (zone.toUpperCase() !== extractedZone) {
                event.preventDefault();
                alert(`Zona "${zone}" nu corespunde cu prefixul din codul locației "${extractedZone}"`);
                return false;
            }
        }
    }
});

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
                shelves: this.locations.filter(l => l.zone === zone.zone_name && l.type === 'Shelf')
            }));
        }
        
        // Fallback: extract from locations data
        const zoneMap = new Map();
        
        this.locations.forEach(location => {
            const zoneName = location.zone;
            if (zoneName && location.type === 'Shelf') {
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
        
        const zoneShelves = this.locations.filter(l => 
            l.zone === this.currentZone && l.type === 'Shelf'
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
        const occupancyBottom = shelf.occupancy?.bottom || 0;
        const occupancyMiddle = shelf.occupancy?.middle || 0;
        const occupancyTop = shelf.occupancy?.top || 0;

        // Create level bars (always visible now) - top to bottom order
        const levelsHTML = `
            <div class="shelf-levels">
                <div class="shelf-level" data-level="top" title="Nivel superior: ${Math.round(occupancyTop)}%">
                    <div class="level-fill ${this.getOccupancyClass(occupancyTop)}" 
                         style="width: ${occupancyTop}%"></div>
                </div>
                <div class="shelf-level" data-level="middle" title="Nivel mijloc: ${Math.round(occupancyMiddle)}%">
                    <div class="level-fill ${this.getOccupancyClass(occupancyMiddle)}" 
                         style="width: ${occupancyMiddle}%"></div>
                </div>
                <div class="shelf-level" data-level="bottom" title="Nivel inferior: ${Math.round(occupancyBottom)}%">
                    <div class="level-fill ${this.getOccupancyClass(occupancyBottom)}" 
                         style="width: ${occupancyBottom}%"></div>
                </div>
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
            filteredLocations = filteredLocations.filter(l => l.type === typeFilter);
        }
        
        if (searchFilter) {
            filteredLocations = filteredLocations.filter(l => 
                l.location_code.toLowerCase().includes(searchFilter)
            );
        }
        
        tbody.innerHTML = filteredLocations.map(location => {
            const occupancyClass = this.getOccupancyClass(location.occupancy?.total || 0);
            const isShelf = location.type === 'Shelf';
            
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
                        <button class="btn btn-sm btn-outline" onclick="openEditModal(${JSON.stringify(location).replace(/"/g, '&quot;')})" title="Editează">
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
}

// Export for global access
window.EnhancedWarehouseVisualization = EnhancedWarehouseVisualization;