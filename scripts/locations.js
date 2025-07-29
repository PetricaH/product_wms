/**
 * Enhanced Locations JavaScript - Complete Updated File
 * File: scripts/locations.js
 * Auto-populates zone from location_code and enhanced warehouse visualization
 * Includes all fixes and enhancements for storage-focused visualization
 */

let qr = null;

let levelCounter = 0;
let createdLevels = [];

document.addEventListener('DOMContentLoaded', function() {
    console.log('Enhanced Locations page loaded');
    
    // Initialize warehouse visualization if data exists
    if (typeof window.warehouseData !== 'undefined') {
        window.warehouseViz = new EnhancedWarehouseVisualization();
    }

    initializeQRCode();
    setupEventListeners();
    
    // Auto-populate zone when location_code changes
    const locationCodeInput = document.getElementById('location_code');
    const zoneInput = document.getElementById('zone');
    const qrCanvas = document.getElementById('locationQrCanvas');
    if (qrCanvas && window.QRious) {
        qr = new QRious({ element: qrCanvas, size: 150, value: '' });
    }

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
            updateLocationQr();
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

function openEditModal(location) {
    document.getElementById('modalTitle').textContent = 'Editează Locație';
    document.getElementById('formAction').value = 'update';
    document.getElementById('locationId').value = location.id;
    document.getElementById('submitBtn').textContent = 'Actualizează';
    
    // Populate form with null checks
    const locationCodeInput = document.getElementById('location_code');
    if (locationCodeInput) locationCodeInput.value = location.location_code;
    
    const zoneInput = document.getElementById('zone');
    if (zoneInput) zoneInput.value = location.zone;
    
    const typeInput = document.getElementById('type');
    if (typeInput) typeInput.value = location.type || 'Shelf';
    
    // These fields might be removed by dynamic level system
    const capacityInput = document.getElementById('capacity');
    if (capacityInput) capacityInput.value = location.capacity || '';
    
    const levelsFieldEdit = document.getElementById('levels');
    if (levelsFieldEdit) {
        levelsFieldEdit.value = location.levels || 3;
        currentLevels = parseInt(location.levels) || 3;
    }
    
    // Populate dimensions if available (might be in tabs)
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
    
    // Initialize level settings or dynamic levels
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
    } else if (typeof populateDynamicLevels === 'function') {
        if (location.level_settings) {
            populateDynamicLevels(location.level_settings);
        } else if (location.levels) {
            const placeholders = Array.from({ length: location.levels }, () => ({}));
            populateDynamicLevels(placeholders);
        }
    }
    
    // Update QR code with the location code
    setTimeout(() => {
        updateLocationQr();
    }, 100);
    
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

    if (levelSettingsEnabled && currentLevels > 0) {
        const data = {};
        for (let lvl = 1; lvl <= currentLevels; lvl++) {
            data[lvl] = {
                storage_policy: document.querySelector(`input[name="level_${lvl}_storage_policy"]:checked`)?.value || 'multiple_products',
                height_mm: parseInt(document.getElementById(`level_${lvl}_height`)?.value) || 0,
                max_weight_kg: parseFloat(document.getElementById(`level_${lvl}_weight`)?.value) || 0,
                items_capacity: parseInt(document.getElementById(`level_${lvl}_capacity`)?.value) || null,
                dedicated_product_id: document.getElementById(`level_${lvl}_dedicated_product`)?.value || null,
                allow_other_products: document.getElementById(`level_${lvl}_allow_others`)?.checked ?? true,
                volume_min_liters: parseFloat(document.querySelector(`input[name="level_${lvl}_volume_min"]`)?.value) || null,
                volume_max_liters: parseFloat(document.querySelector(`input[name="level_${lvl}_volume_max"]`)?.value) || null,
                enable_auto_repartition: document.getElementById(`level_${lvl}_auto_repartition`)?.checked || false,
                repartition_trigger_threshold: parseInt(document.querySelector(`input[name="level_${lvl}_threshold"]`)?.value) || 80,
                priority_order: parseInt(document.querySelector(`input[name="level_${lvl}_priority"]`)?.value) || 1
            };
        }
        const field = document.getElementById('level_settings_data');
        if (field) {
            field.value = JSON.stringify(data);
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

// Export for global access
window.EnhancedWarehouseVisualization = EnhancedWarehouseVisualization;
/**
 * Enhanced Locations Management JavaScript
 * Includes per-level configuration functionality
 */

/**
 * Initialize the locations page
 */
document.addEventListener('DOMContentLoaded', function() {
    // Check if enhanced models are available
    checkLevelSettingsAvailability();
    
    // Initialize existing functionality
    initializeExistingFunctionality();
    
    // Initialize enhanced functionality if available
    if (levelSettingsEnabled) {
        initializeLevelSettings();
    }
});

/**
 * Check if level settings functionality is available
 */
function checkLevelSettingsAvailability() {
    // Try to detect if enhanced models are available by checking for specific elements
    // This would be set by the PHP side if models are available
    levelSettingsEnabled = window.levelSettingsAvailable || false;
}

/**
 * Initialize level settings functionality
 */
function initializeLevelSettings() {
    // Add level settings tab if not exists
    addLevelSettingsTab();
    
    // Initialize level configuration
    const levelsInput = document.getElementById('levels');
    if (levelsInput) {
        levelsInput.addEventListener('change', updateLevelSettings);
        // Initialize with current value
        updateLevelSettings();
    }
    
    // Initialize dimension distribution
    const dimensionInputs = ['height_mm', 'max_weight_kg'];
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

/**
 * Switch between location tabs
 * @param {string} tabName - Name of the tab to switch to
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
    // Check if event exists and has a target before trying to access it
    if (typeof event !== 'undefined' && event && event.target) {
        const tabButton = event.target.closest('.tab-button');
        if (tabButton) {
            tabButton.classList.add('active');
        }
    } else {
        // When called programmatically, find and activate the correct tab button
        const tabButtons = document.querySelectorAll('.tab-button');
        tabButtons.forEach(button => {
            const onclick = button.getAttribute('onclick');
            if (onclick && onclick.includes(`switchLocationTab('${tabName}')`)) {
                button.classList.add('active');
            }
        });
    }
}

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
    }
}

/**
 * Create level settings div
 * @param {number} levelNumber - Level number
 * @returns {HTMLElement} - Level settings element
 */
function createLevelSettingsDiv(levelNumber) {
    const levelDiv = document.createElement('div');
    levelDiv.className = 'level-settings-container';
    
    const levelName = getLevelName(levelNumber);
    const isActive = '';
    
    levelDiv.innerHTML = `
        <div class="level-header ${isActive}" onclick="toggleLevel(${levelNumber})">
            <div class="level-title">
                <span class="material-symbols-outlined">layers</span>
                <span>Nivel ${levelNumber} - ${levelName}</span>
            </div>
            <span class="level-toggle material-symbols-outlined">expand_more</span>
        </div>
        <div class="level-content ${isActive}" id="level-content-${levelNumber}">
            <div class="level-grid">
                <div class="settings-section">
                    <h4>
                        <span class="material-symbols-outlined">policy</span>
                        Politica de Stocare
                    </h4>
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
                        <div class="policy-option" onclick="selectStoragePolicy(${levelNumber}, 'category_restricted')">
                            <input type="radio" name="level_${levelNumber}_storage_policy" value="category_restricted">
                            <div>
                                <div class="policy-title">Restricționat pe Categorie</div>
                                <div class="policy-description">Permite doar anumite categorii de produse</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="settings-section">
                    <h4>
                        <span class="material-symbols-outlined">straighten</span>
                        Dimensiuni Nivel
                    </h4>
                    <div class="form-group">
                        <label class="form-label">Înălțime nivel (mm)</label>
                        <input type="number" name="level_${levelNumber}_height" id="level_${levelNumber}_height" 
                               class="form-control" value="300" min="100" max="1000">
                    </div>
                   <div class="form-group">
                       <label class="form-label">Capacitate greutate (kg)</label>
                       <input type="number" name="level_${levelNumber}_weight" id="level_${levelNumber}_weight"
                              class="form-control" value="50" min="1" max="500" step="0.1">
                   </div>
                    <div class="form-group">
                        <label class="form-label">Articole pe nivel</label>
                        <input type="number" name="level_${levelNumber}_capacity" id="level_${levelNumber}_capacity"
                               class="form-control" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Produs dedicat</label>
                        <select name="level_${levelNumber}_dedicated_product" id="level_${levelNumber}_dedicated_product" class="form-control">
                            ${generateInternalProductOptions()}
                        </select>
                        <div class="form-check" style="margin-top:0.5rem;">
                            <input type="checkbox" id="level_${levelNumber}_allow_others" name="level_${levelNumber}_allow_others" checked>
                            <label for="level_${levelNumber}_allow_others" class="form-label">Permite alte produse</label>
                        </div>
                    </div>
                </div>
                
                <div class="settings-section">
                    <h4>
                        <span class="material-symbols-outlined">tune</span>
                        Restricții Produse
                    </h4>
                    <div class="form-group">
                        <label class="form-label">Volum minim (L)</label>
                        <input type="number" name="level_${levelNumber}_volume_min" 
                               class="form-control" step="0.1" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Volum maxim (L)</label>
                        <input type="number" name="level_${levelNumber}_volume_max" 
                               class="form-control" step="0.1" min="0">
                    </div>
                </div>
                
                <div class="settings-section">
                    <h4>
                        <span class="material-symbols-outlined">auto_fix_high</span>
                        Repartizare Automată
                    </h4>
                    <div class="form-check">
                        <input type="checkbox" id="level_${levelNumber}_auto_repartition" 
                               name="level_${levelNumber}_auto_repartition">
                        <label for="level_${levelNumber}_auto_repartition" class="form-label">
                            Activează pentru acest nivel
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prag activare (%)</label>
                        <input type="number" name="level_${levelNumber}_threshold" 
                               class="form-control" value="80" min="50" max="95">
                    </div>
                <div class="form-group">
                    <label class="form-label">Prioritate nivel</label>
                    <input type="number" name="level_${levelNumber}_priority"
                               class="form-control" value="${currentLevels - levelNumber + 1}" min="1" max="10">
                    <small class="form-help">Prioritate mai mare = plasat primul</small>
                </div>
                </div>
                <div class="settings-section">
                    <h4>
                        <span class="material-symbols-outlined">view_column</span>
                        Subdiviziuni
                    </h4>
                    <div class="form-group">
                        <label class="form-label">Număr subdiviziuni</label>
                        <div class="subdivision-control">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="changeSubdivision(${levelNumber}, -1)">-</button>
                            <input type="number" name="level_${levelNumber}_subdivisions" id="level_${levelNumber}_subdivisions" class="form-control" value="1" min="1" style="width:60px;display:inline-block;margin:0 0.5rem;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="changeSubdivision(${levelNumber}, 1)">+</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    return levelDiv;
}

/**
 * Toggle level visibility
 * @param {number} levelNumber - Level number
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
 * @param {number} levelNumber - Level number
 * @param {string} policy - Policy type
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
    const totalWeight = parseFloat(document.getElementById('max_weight_kg')?.value) || 150;
    const levelWeight = (totalWeight / currentLevels).toFixed(1);
    
    for (let level = 1; level <= currentLevels; level++) {
        const input = document.getElementById(`level_${level}_weight`);
        if (input) {
            input.value = levelWeight;
        }
    }
}

function distributeItemCapacity() {
    const totalCap = parseInt(document.getElementById('capacity')?.value) || 0;
    const perLevel = currentLevels > 0 ? Math.floor(totalCap / currentLevels) : 0;
    for (let level = 1; level <= currentLevels; level++) {
        const input = document.getElementById(`level_${level}_capacity`);
        if (input) {
            input.value = perLevel;
        }
    }
}

function changeSubdivision(levelId, delta) {
    const input = document.getElementById(`level_${levelId}_subdivisions`);
    if (!input) return;
    let value = parseInt(input.value) || 1;
    value += delta;
    if (value < 1) value = 1;
    input.value = value;
}

function generateInternalProductOptions() {
    if (typeof window.allProducts !== 'undefined') {
        let options = '<option value="">-- Produs intern --</option>';
        window.allProducts.forEach(p => {
            options += `<option value="${p.product_id}">${escapeHtml(p.name)} (${escapeHtml(p.sku)})</option>`;
        });
        return options;
    }
    return '<option value="">-- Produs intern --</option>';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Distribute dimensions based on input
 * @param {string} inputId - Input element ID
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
 * @param {number} levelNumber - Level number
 * @returns {string} - Level name
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
 * Enhanced openCreateModal to support level settings
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

    // Update QR code after a short delay to ensure form is reset
    setTimeout(() => {
        updateLocationQr();
    }, 100);
    
    // Initialize level settings if available
    if (levelSettingsEnabled) {
        currentLevels = 3;
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
 * Populate level settings from loaded data
 * @param {Array} levelSettings - Level settings data
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

        const allowOthersCheckbox = document.getElementById(`level_${level}_allow_others`);
        if (allowOthersCheckbox) allowOthersCheckbox.checked = setting.allow_other_products !== false;
        
        // Restrictions
        const volumeMinInput = document.querySelector(`input[name="level_${level}_volume_min"]`);
        if (volumeMinInput) volumeMinInput.value = setting.volume_min_liters || '';
        
        const volumeMaxInput = document.querySelector(`input[name="level_${level}_volume_max"]`);
        if (volumeMaxInput) volumeMaxInput.value = setting.volume_max_liters || '';
        
        // Auto repartition
        const autoRepartitionCheckbox = document.getElementById(`level_${level}_auto_repartition`);
        if (autoRepartitionCheckbox) autoRepartitionCheckbox.checked = setting.enable_auto_repartition || false;
        
        const thresholdInput = document.querySelector(`input[name="level_${level}_threshold"]`);
        if (thresholdInput) thresholdInput.value = setting.repartition_trigger_threshold || 80;
        
        const priorityInput = document.querySelector(`input[name="level_${level}_priority"]`);
        if (priorityInput) priorityInput.value = setting.priority_order || 1;

        const subdivInput = document.getElementById(`level_${level}_subdivisions`);
        if (subdivInput) subdivInput.value = setting.subdivision_count || 1;
    });
}

// =================== PRESERVE ALL EXISTING FUNCTIONS ===================

function extractZoneFromCode() {
    const locationCode = document.getElementById('location_code').value.trim().toUpperCase();
    const zoneInput = document.getElementById('zone');
    
    if (!locationCode || !zoneInput) return;
    
    // Extract zone from location code (before first dash)
    const parts = locationCode.split('-');
    if (parts.length >= 2) {
        const zone = parts[0];
        
        if (zone && zone !== zoneInput.value) {
            zoneInput.value = zone;
            zoneInput.style.backgroundColor = 'rgba(25, 135, 84, 0.1)';
            zoneInput.style.borderColor = 'var(--success-color)';
            
            showZoneAutoFill(zone);
            
            setTimeout(() => {
                zoneInput.style.backgroundColor = '';
                zoneInput.style.borderColor = '';
            }, 2000);
        }
    }
}

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
        // Get initial value from input
        const locationCodeInput = document.getElementById('location_code');
        const initialValue = locationCodeInput ? locationCodeInput.value.trim() : '';
        
        // Initialize QRious
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

function setupEventListeners() {
    // Location code input listener
    const locationCodeInput = document.getElementById('location_code');
    const zoneInput = document.getElementById('zone');
    
    if (locationCodeInput) {
        locationCodeInput.addEventListener('input', function() {
            const code = this.value.trim();
            console.log('Location code changed to:', code);
            
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

function updateLocationQr() {
    if (!qr) {
        console.error('QR object not initialized, trying to reinitialize...');
        initializeQRCode();
        return;
    }
    
    const codeInput = document.getElementById('location_code');
    const code = codeInput ? codeInput.value.trim() : '';
    
    console.log('Updating QR with code:', code);
    
    try {
        if (code && code.length > 0) {
            qr.set({ value: code });
            console.log('✅ QR updated successfully with:', code);
        } else {
            qr.set({ value: 'EMPTY' });
            console.log('QR updated with EMPTY placeholder');
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
        console.log('QR download initiated for:', code);
    } catch (error) {
        console.error('Error downloading QR code:', error);
    }
}

/**
 * Generate QR images for all levels after location creation
 */
function generateLevelQRImages(locationId, locationCode, levelNames) {
    Object.entries(levelNames).forEach(([level, name]) => {
        const levelCode = `${locationCode}\n${name}`;

        // Create canvas for this level
        const canvas = document.createElement('canvas');
        const qr = new QRious({
            element: canvas,
            size: 150,
            value: levelCode,
            foreground: '#000000',
            background: '#ffffff'
        });
        
        // Convert to blob and save (you can implement saving logic here)
        canvas.toBlob(function(blob) {
            // Create download link for each level QR
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `${locationCode}_level_${level}_qr.png`;
            
            // Optionally auto-download or just log
            console.log(`QR code generated for ${levelCode}`);
            
            // You could also send this to server to save the file
            uploadQRCodeToServer(blob, locationId, level);
        });
    });
}

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
        const hiddenField = document.getElementById('level_settings_data');
        if (hiddenField) {
            hiddenField.value = JSON.stringify(levelData);
        }
    }

    return true;
});

/**
 * Initialize the dynamic level system
 */
function initializeDynamicLevelSystem() {
    // Remove the old level settings functionality
    levelSettingsEnabled = false;
    
    // Clear any existing level settings
    const levelSettingsSection = document.getElementById('level-settings-section');
    if (levelSettingsSection) {
        levelSettingsSection.remove();
    }
    
    // Create the dynamic level management container
    createDynamicLevelContainer();
    
    // Update modal structure
    updateModalStructure();
}

/**
 * Create the dynamic level management container
 */
function createDynamicLevelContainer() {
    const formElement = document.getElementById('locationForm');
    if (!formElement) return;
    
    // Find where to insert the new container (after basic fields)
    const insertAfter = document.querySelector('.form-row:last-of-type') || 
                       document.getElementById('description')?.closest('.form-group');
    
    if (!insertAfter) return;
    
    // Create the dynamic levels container
    const dynamicContainer = document.createElement('div');
    dynamicContainer.id = 'dynamic-levels-section';
    dynamicContainer.className = 'form-section';
    dynamicContainer.style.marginTop = '2rem';
    
    dynamicContainer.innerHTML = `
        <div class="section-header">
            <h4 class="form-section-title">
                <span class="material-symbols-outlined">layers</span>
                Configurare Niveluri
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
    
    // Insert after the last form row
    insertAfter.parentNode.insertBefore(dynamicContainer, insertAfter.nextSibling);
}

/**
 * Add a new level with full configuration
 */
function addNewLevel() {
    levelCounter++;
    const levelId = `level_${levelCounter}`;
    
    // Create level object
    const newLevel = {
        id: levelCounter,
        name: `Nivel ${levelCounter}`,
        position: getLevelPosition(levelCounter)
    };
    
    createdLevels.push(newLevel);
    
    // Create the level HTML
    const levelHTML = createLevelHTML(newLevel);
    
    // Add to container
    const container = document.getElementById('levels-container');
    const levelElement = document.createElement('div');
    levelElement.className = 'dynamic-level-item';
    levelElement.id = `level-item-${levelCounter}`;
    levelElement.innerHTML = levelHTML;
    
    container.appendChild(levelElement);
    
    // Update summary
    updateLevelsSummary();
    
    // Initialize level interactions
    initializeLevelInteractions(levelCounter);
    
    // Scroll to new level
    levelElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    
    // Focus on level name input
    setTimeout(() => {
        const nameInput = levelElement.querySelector('.level-name-input');
        if (nameInput) nameInput.focus();
    }, 300);
}

/**
 * Create HTML for a single level
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
            
            <div class="level-content" id="level-content-${level.id}">
                <div class="level-settings-grid">
                    <!-- Storage Policy Section -->
                    <div class="settings-section">
                        <h5>
                            <span class="material-symbols-outlined">policy</span>
                            Politica de Stocare
                        </h5>
                        <div class="storage-policy-options">
                            <label class="policy-option">
                                <input type="radio" name="level_${level.id}_storage_policy" value="multiple_products" checked>
                                <div class="policy-content">
                                    <strong>Multiple Produse</strong>
                                    <div class="policy-description">Permite stocarea mai multor tipuri de produse</div>
                                </div>
                            </label>
                            <label class="policy-option">
                            <input type="radio" name="level_${level.id}_storage_policy" value="single_product_type">
                                <div class="policy-content">
                                    <strong>Un Singur Tip</strong>
                                    <div class="policy-description">Permite doar un tip de produs pe nivel</div>
                                </div>
                            </label>
                            <label class="policy-option">
                                <input type="radio" name="level_${level.id}_storage_policy" value="category_restricted">
                                <div class="policy-content">
                                    <strong>Restricționat pe Categorie</strong>
                                    <div class="policy-description">Permite doar anumite categorii de produse</div>
                                </div>
                            </label>
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
                                <label>Articole pe nivel</label>
                                <input type="number" id="level_${level.id}_capacity" name="level_${level.id}_capacity" 
                                       class="form-control" min="1" placeholder="Nr. articole">
                            </div>
                        </div>
                    </div>

                    <!-- Product Restrictions Section -->
                    <div class="settings-section">
                        <h5>
                            <span class="material-symbols-outlined">tune</span>
                            Restricții Produse
                        </h5>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Produs dedicat</label>
                                <select id="level_${level.id}_dedicated_product" name="level_${level.id}_dedicated_product" 
                                        class="form-control">
                                    <option value="">-- Selectează produs --</option>
                                    <!-- Products will be populated dynamically -->
                                </select>
                            </div>
                            <div class="form-group form-check-container">
                                <label class="form-check">
                                    <input type="checkbox" id="level_${level.id}_allow_others" 
                                           name="level_${level.id}_allow_others" checked>
                                    Permite alte produse
                                </label>
                            </div>
                            <div class="form-group">
                                <label>Volum minim (L)</label>
                                <input type="number" id="level_${level.id}_volume_min" name="level_${level.id}_volume_min" 
                                       class="form-control" step="0.1" placeholder="Volum minim">
                            </div>
                            <div class="form-group">
                                <label>Volum maxim (L)</label>
                                <input type="number" id="level_${level.id}_volume_max" name="level_${level.id}_volume_max" 
                                       class="form-control" step="0.1" placeholder="Volum maxim">
                            </div>
                        </div>
                    </div>

                    <!-- Auto-Repartition Section -->
                    <div class="settings-section">
                        <h5>
                            <span class="material-symbols-outlined">auto_fix_high</span>
                            Repartizare Automată
                        </h5>
                        <div class="form-group form-check-container">
                            <label class="form-check">
                                <input type="checkbox" id="level_${level.id}_auto_repartition" 
                                       name="level_${level.id}_auto_repartition">
                                Activează pentru acest nivel
                            </label>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Prag activare (%)</label>
                                <input type="number" id="level_${level.id}_threshold" name="level_${level.id}_threshold" 
                                       class="form-control" value="80" min="50" max="95">
                            </div>
                            <div class="form-group">
                                <label>Prioritate nivel</label>
                                <input type="number" id="level_${level.id}_priority" name="level_${level.id}_priority" 
                                       class="form-control" value="1" min="1" max="10">
                                <small class="form-help">Prioritate mai mare = plasat primul</small>
                            </div>
                        </div>
                    </div>
                    <div class="settings-section">
                        <h5>
                            <span class="material-symbols-outlined">view_column</span>
                            Subdiviziuni
                        </h5>
                        <div class="form-group">
                            <label>Număr subdiviziuni</label>
                            <div class="subdivision-control">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="changeSubdivision(${level.id}, -1)">-</button>
                                <input type="number" id="level_${level.id}_subdivisions" name="level_${level.id}_subdivisions" class="form-control" value="1" min="1" style="width:60px;display:inline-block;margin:0 0.5rem;">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="changeSubdivision(${level.id}, 1)">+</button>
                            </div>
                        </div>
                    </div>
                    <div class="settings-section">
                        <h5>
                            <span class="material-symbols-outlined">qr_code_2</span>
                            Cod QR Nivel
                        </h5>
                        <div class="form-group">
                            <img id="level_qr_img_${level.id}" src="" alt="QR Nivel" style="width:120px;height:120px;display:block;margin-bottom:0.5rem;" />
                            <a id="level_qr_link_${level.id}" href="#" download class="btn btn-secondary btn-sm">Descarcă QR</a>
                        </div>
                    </div>
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
    // Initialize storage policy radio buttons
    const policyRadios = document.querySelectorAll(`input[name="level_${levelId}_storage_policy"]`);
    policyRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            updatePolicyOptions(levelId, this.value);
        });
    });
    
    // Initialize product selection
    populateProductOptions(levelId);
}

/**
 * Update level name
 */
function updateLevelName(levelId, newName) {
    const level = createdLevels.find(l => l.id === levelId);
    if (level) {
        level.name = newName || `Nivel ${levelId}`;
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
    } else {
        content.style.display = 'none';
        toggleBtn.textContent = 'expand_more';
    }
}

/**
 * Remove a level
 */
function removeLevel(levelId) {
    if (confirm('Sigur doriți să ștergeți acest nivel?')) {
        // Remove from DOM
        const levelElement = document.getElementById(`level-item-${levelId}`);
        if (levelElement) {
            levelElement.remove();
        }
        
        // Remove from array
        createdLevels = createdLevels.filter(l => l.id !== levelId);
        
        // Update summary and counter
        updateLevelsSummary();
        levelCounter = createdLevels.length;
    }
}

/**
 * Update policy options based on selection
 */
function updatePolicyOptions(levelId, policy) {
    const dedicatedProduct = document.getElementById(`level_${levelId}_dedicated_product`);
    const allowOthers = document.getElementById(`level_${levelId}_allow_others`);
    
    if (policy === 'single_product_type') {
        dedicatedProduct.required = true;
        allowOthers.checked = false;
        allowOthers.disabled = true;
    } else {
        dedicatedProduct.required = false;
        allowOthers.disabled = false;
    }
}

/**
 * Populate product options for a level
 */
function populateProductOptions(levelId) {
    const select = document.getElementById(`level_${levelId}_dedicated_product`);
    if (!select) return;
    
    // Add default option
    select.innerHTML = '<option value="">-- Selectează produs --</option>';
    
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
 * Update levels summary
 */
function updateLevelsSummary() {
    const summary = document.getElementById('levels-summary');
    const countSpan = document.getElementById('total-levels-count');
    
    if (createdLevels.length > 0) {
        summary.style.display = 'block';
        countSpan.textContent = createdLevels.length;
    } else {
        summary.style.display = 'none';
    }
}

/**
 * Update modal structure to remove old elements
 */
function updateModalStructure() {
    // Remove old general settings fields
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
    
    // Remove old level settings section if exists
    const oldSection = document.getElementById('level-settings-section');
    if (oldSection) oldSection.remove();
}

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
            volume_min_liters: parseFloat(document.getElementById(`level_${levelId}_volume_min`)?.value) || null,
            volume_max_liters: parseFloat(document.getElementById(`level_${levelId}_volume_max`)?.value) || null,
            enable_auto_repartition: document.getElementById(`level_${levelId}_auto_repartition`)?.checked || false,
            repartition_trigger_threshold: parseInt(document.getElementById(`level_${levelId}_threshold`)?.value) || 80,
            priority_order: parseInt(document.getElementById(`level_${levelId}_priority`)?.value) || 1
        };
        const subdivInput = document.getElementById(`level_${levelId}_subdivisions`);
        if (subdivInput) {
            levelData[levelId].subdivision_count = parseInt(subdivInput.value) || 1;
        }
    });
    
    return levelData;
}

function populateDynamicLevels(levelSettings) {
    const container = document.getElementById('levels-container');
    if (!container) return;

    container.innerHTML = '';
    createdLevels = [];
    levelCounter = 0;

    levelSettings.forEach(setting => {
        addNewLevel();
        const id = levelCounter;

        const nameInput = document.querySelector(`#level-item-${id} .level-name-input`);
        if (nameInput) nameInput.value = setting.level_name || `Nivel ${id}`;

        const policyRadio = document.querySelector(`input[name="level_${id}_storage_policy"][value="${setting.storage_policy}"]`);
        if (policyRadio) policyRadio.checked = true;

        document.getElementById(`level_${id}_height`).value = setting.height_mm || 300;
        document.getElementById(`level_${id}_weight`).value = setting.max_weight_kg || 50;
        document.getElementById(`level_${id}_capacity`).value = setting.items_capacity || '';
        document.getElementById(`level_${id}_dedicated_product`).value = setting.dedicated_product_id || '';
        document.getElementById(`level_${id}_allow_others`).checked = setting.allow_other_products !== false;
        document.getElementById(`level_${id}_volume_min`).value = setting.volume_min_liters || '';
        document.getElementById(`level_${id}_volume_max`).value = setting.volume_max_liters || '';
        document.getElementById(`level_${id}_auto_repartition`).checked = setting.enable_auto_repartition || false;
        document.getElementById(`level_${id}_threshold`).value = setting.repartition_trigger_threshold || 80;
        document.getElementById(`level_${id}_priority`).value = setting.priority_order || id;
        document.getElementById(`level_${id}_subdivisions`).value = setting.subdivision_count || 1;

        if (setting.qr_code_path) {
            const link = document.getElementById(`level_qr_link_${id}`);
            const img = document.getElementById(`level_qr_img_${id}`);
            if (link) link.href = setting.qr_code_path;
            if (img) img.src = setting.qr_code_path;
        }
    });

    updateLevelsSummary();
}

/**
 * Enhanced form submission with level data
 */
function enhanceFormSubmission() {
    const form = document.getElementById('locationForm');
    if (!form) return;
    
    form.addEventListener('submit', function(event) {
        if (createdLevels.length === 0) {
            event.preventDefault();
            alert('Trebuie să adăugați cel puțin un nivel!');
            return false;
        }
        
        // Collect level data
        const levelData = collectLevelData();
        
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
    });
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Wait a bit for other scripts to load
    setTimeout(() => {
        initializeDynamicLevelSystem();
        enhanceFormSubmission();
    }, 500);
});

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
window.generateInternalProductOptions = generateInternalProductOptions;

// QR CODES
window.updateLocationQr = updateLocationQr;
window.downloadLocationQr = downloadLocationQr;
window.openCreateModal = openCreateModal;
window.openEditModal = openEditModal;
window.openEditModalById = openEditModalById;

window.addNewLevel = addNewLevel;
window.updateLevelName = updateLevelName;
window.toggleLevel = toggleLevel;
window.removeLevel = removeLevel;
window.populateDynamicLevels = populateDynamicLevels;
window.changeSubdivision = changeSubdivision;