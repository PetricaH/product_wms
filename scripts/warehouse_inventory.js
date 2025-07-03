// File: scripts/warehouse_inventory.js
// Warehouse Inventory Search Interface

class InventorySearch {
    constructor() {
        this.apiBase = window.WMS_CONFIG?.apiBase || '/api';
        this.scanner = null;
        this.searchCount = 0;
        this.currentTab = 'sku';
        this.searchHistory = [];
        this.init();
    }

    init() {
        console.log('🔍 Initializing Inventory Search...');
        this.initEventListeners();
        this.updateSearchCounter();
        console.log('✅ Inventory Search initialized successfully');
    }

    initEventListeners() {
        // Tab switching
        document.querySelectorAll('.search-tab').forEach(tab => {
            tab.addEventListener('click', (e) => this.switchTab(e.target.dataset.tab));
        });

        // Search buttons
        document.getElementById('search-sku-btn')?.addEventListener('click', () => this.searchBySku());
        document.getElementById('search-name-btn')?.addEventListener('click', () => this.searchByName());
        document.getElementById('search-location-btn')?.addEventListener('click', () => this.searchByLocation());

        // Scanner controls
        document.getElementById('start-scan-btn')?.addEventListener('click', () => this.startScanner());
        document.getElementById('stop-scan-btn')?.addEventListener('click', () => this.stopScanner());

        // Back button
        document.getElementById('back-btn')?.addEventListener('click', () => {
            window.location.href = 'warehouse_hub.php';
        });

        // Enter key support for inputs
        document.querySelectorAll('.form-input').forEach(input => {
            input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.performSearchForCurrentTab();
                }
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.altKey) {
                switch(e.key) {
                    case '1':
                        e.preventDefault();
                        this.switchTab('sku');
                        break;
                    case '2':
                        e.preventDefault();
                        this.switchTab('name');
                        break;
                    case '3':
                        e.preventDefault();
                        this.switchTab('location');
                        break;
                    case 's':
                        e.preventDefault();
                        this.startScanner();
                        break;
                }
            }
        });
    }

    switchTab(tab) {
        this.currentTab = tab;
        
        // Update tab appearance
        document.querySelectorAll('.search-tab').forEach(t => t.classList.remove('active'));
        document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
        
        // Show/hide relevant sections
        document.querySelectorAll('.tab-content').forEach(content => content.classList.add('hidden'));
        document.getElementById(`${tab}-tab`).classList.remove('hidden');
        
        // Focus the input
        const input = document.querySelector(`#${tab}-tab .form-input`);
        if (input) {
            setTimeout(() => input.focus(), 100);
        }
    }

    performSearchForCurrentTab() {
        switch(this.currentTab) {
            case 'sku':
                this.searchBySku();
                break;
            case 'name':
                this.searchByName();
                break;
            case 'location':
                this.searchByLocation();
                break;
        }
    }

    async searchBySku() {
        const sku = document.getElementById('sku-input')?.value.trim();
        if (!sku) {
            this.showStatusMessage('error', 'Introduceți codul SKU.');
            return;
        }
        await this.performSearch('sku', sku);
    }

    async searchByName() {
        const name = document.getElementById('name-input')?.value.trim();
        if (!name) {
            this.showStatusMessage('error', 'Introduceți numele produsului.');
            return;
        }
        await this.performSearch('name', name);
    }

    async searchByLocation() {
        const location = document.getElementById('location-input')?.value.trim();
        if (!location) {
            this.showStatusMessage('error', 'Introduceți codul locației.');
            return;
        }
        await this.performSearch('location', location);
    }

    async startScanner() {
        try {
            console.log('📷 Starting QR scanner...');
            
            document.getElementById('start-scan-btn').style.display = 'none';
            document.getElementById('stop-scan-btn').style.display = 'block';
            document.getElementById('scanner-container').classList.add('active');

            if (typeof Html5QrcodeScanner !== 'undefined') {
                this.scanner = new Html5QrcodeScanner("reader", {
                    qrbox: { width: 250, height: 250 },
                    fps: 10,
                    aspectRatio: 1.0
                });

                this.scanner.render(
                    (decodedText) => this.handleScan(decodedText),
                    (error) => {
                        // Only log errors that aren't common scanning noise
                        if (error.includes('No MultiFormat Readers were able to detect the code')) {
                            return; // Ignore this common scanning message
                        }
                        console.log('Scan error:', error);
                    }
                );
                
                this.showStatusMessage('info', 'Scanner activ. Scanați un cod de bare sau QR.');
            } else {
                throw new Error('Scanner library not loaded');
            }
        } catch (error) {
            console.error('❌ Scanner error:', error);
            this.showStatusMessage('error', 'Eroare la pornirea scannerului: ' + error.message);
            this.stopScanner();
        }
    }

    stopScanner() {
        console.log('📷 Stopping QR scanner...');
        
        if (this.scanner) {
            this.scanner.clear();
            this.scanner = null;
        }
        
        document.getElementById('start-scan-btn').style.display = 'block';
        document.getElementById('stop-scan-btn').style.display = 'none';
        document.getElementById('scanner-container').classList.remove('active');
    }

    async handleScan(scannedText) {
        console.log('📷 Scanned:', scannedText);
        this.stopScanner();
        
        // Auto-detect scan type and switch to appropriate tab
        if (scannedText.includes('-') && scannedText.length < 20) {
            // Looks like a location code
            this.switchTab('location');
            document.getElementById('location-input').value = scannedText;
            await this.performSearch('location', scannedText);
        } else {
            // Default to SKU search
            this.switchTab('sku');
            document.getElementById('sku-input').value = scannedText;
            await this.performSearch('sku', scannedText);
        }
    }

    async performSearch(type, query) {
        try {
            console.log(`🔍 Searching ${type}:`, query);
            this.setLoading(true);
            this.showStatusMessage('info', 'Se caută...');

            const endpoint = this.getSearchEndpoint(type);
            const response = await this.fetchAPI(`${endpoint}?${type}=${encodeURIComponent(query)}`);

            if (response.success && response.data) {
                this.displayResults(response.data, type);
                this.searchCount++;
                this.updateSearchCounter();
                this.addToSearchHistory(type, query, response.data.length);
                this.showStatusMessage('success', `Găsite ${response.data.length} rezultate pentru "${query}".`);
            } else {
                this.displayNoResults();
                this.showStatusMessage('warning', `Nu au fost găsite rezultate pentru "${query}".`);
            }
        } catch (error) {
            console.error('❌ Search error:', error);
            this.showStatusMessage('error', 'Eroare la căutare: ' + error.message);
            this.displayNoResults();
        } finally {
            this.setLoading(false);
        }
    }

    getSearchEndpoint(type) {
        const endpoints = {
            'sku': '/warehouse/search_by_sku.php',
            'name': '/warehouse/search_by_name.php',
            'location': '/warehouse/search_by_location.php'
        };
        return endpoints[type] || endpoints['sku'];
    }

    displayResults(data, searchType) {
        const resultsSection = document.getElementById('results-section');
        const resultsContainer = document.getElementById('results-container');
        
        if (!resultsContainer) return;

        resultsSection.classList.remove('hidden');
        
        // Update results count
        document.getElementById('results-count').textContent = `${data.length} rezultate`;
        
        // Generate HTML for results
        let html = '';
        
        if (searchType === 'location') {
            html = this.generateLocationResults(data);
        } else {
            html = this.generateProductResults(data);
        }
        
        resultsContainer.innerHTML = html;
    }

    generateProductResults(products) {
        return products.map(product => `
            <div class="product-card">
                <div class="product-header">
                    <div class="product-sku">${this.escapeHtml(product.sku)}</div>
                    <div class="stock-status ${this.getStockStatusClass(product.total_quantity)}">
                        ${this.getStockStatusText(product.total_quantity)}
                    </div>
                </div>
                
                <div class="product-name">${this.escapeHtml(product.name)}</div>
                
                <div class="product-details">
                    <div class="detail-item">
                        <span class="detail-label">Stoc Total:</span>
                        <span class="detail-value">${product.total_quantity || 0}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Categoría:</span>
                        <span class="detail-value">${this.escapeHtml(product.category || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Locații:</span>
                        <span class="detail-value">${product.location_count || 0}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Preț:</span>
                        <span class="detail-value">${product.price ? product.price + ' RON' : 'N/A'}</span>
                    </div>
                </div>
                
                ${product.locations && product.locations.length > 0 ? `
                    <div class="locations-section">
                        <div class="locations-title">Locații disponibile:</div>
                        ${product.locations.map(loc => `
                            <div class="location-card">
                                <div class="location-header">
                                    <span class="location-code">${this.escapeHtml(loc.location_code)}</span>
                                    <span class="location-quantity">${loc.quantity} buc</span>
                                </div>
                                <div class="location-details">
                                    ${loc.zone ? `Zona: ${this.escapeHtml(loc.zone)}` : ''}
                                    ${loc.last_updated ? ` • Actualizat: ${this.formatDate(loc.last_updated)}` : ''}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                ` : ''}
            </div>
        `).join('');
    }

    generateLocationResults(locations) {
        return locations.map(location => `
            <div class="product-card">
                <div class="product-header">
                    <div class="product-sku">${this.escapeHtml(location.location_code)}</div>
                    <div class="stock-status ${location.total_items > 0 ? 'in-stock' : 'out-of-stock'}">
                        ${location.total_items || 0} produse
                    </div>
                </div>
                
                <div class="product-details">
                    <div class="detail-item">
                        <span class="detail-label">Zonă:</span>
                        <span class="detail-value">${this.escapeHtml(location.zone || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Capacitate:</span>
                        <span class="detail-value">${location.capacity || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Utilizare:</span>
                        <span class="detail-value">${location.utilization || 0}%</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value">${this.escapeHtml(location.status || 'Activ')}</span>
                    </div>
                </div>
                
                ${location.products && location.products.length > 0 ? `
                    <div class="locations-section">
                        <div class="locations-title">Produse în locație:</div>
                        ${location.products.map(prod => `
                            <div class="location-card">
                                <div class="location-header">
                                    <span class="location-code">${this.escapeHtml(prod.sku)}</span>
                                    <span class="location-quantity">${prod.quantity} buc</span>
                                </div>
                                <div class="location-details">${this.escapeHtml(prod.name || '')}</div>
                            </div>
                        `).join('')}
                    </div>
                ` : ''}
            </div>
        `).join('');
    }

    displayNoResults() {
        const resultsSection = document.getElementById('results-section');
        const resultsContainer = document.getElementById('results-container');
        
        if (!resultsContainer) return;

        resultsSection.classList.remove('hidden');
        document.getElementById('results-count').textContent = '0 rezultate';
        
        resultsContainer.innerHTML = `
            <div class="empty-state">
                <span class="material-symbols-outlined empty-icon">search_off</span>
                <h3>Nu au fost găsite rezultate</h3>
                <p>Încercați cu alți termeni de căutare sau verificați ortografia.</p>
            </div>
        `;
    }

    getStockStatusClass(quantity) {
        if (!quantity || quantity === 0) return 'out-of-stock';
        if (quantity < 10) return 'low-stock';
        return 'in-stock';
    }

    getStockStatusText(quantity) {
        if (!quantity || quantity === 0) return 'Lipsă stoc';
        if (quantity < 10) return 'Stoc redus';
        return 'În stoc';
    }

    updateSearchCounter() {
        const counterEl = document.getElementById('search-counter');
        if (counterEl) {
            counterEl.textContent = this.searchCount;
        }
    }

    addToSearchHistory(type, query, resultCount) {
        this.searchHistory.unshift({
            type,
            query,
            resultCount,
            timestamp: new Date()
        });
        
        // Keep only last 10 searches
        if (this.searchHistory.length > 10) {
            this.searchHistory = this.searchHistory.slice(0, 10);
        }
    }

    showStatusMessage(type, message) {
        const statusContainer = document.getElementById('status-message-container');
        if (!statusContainer) {
            console.log(`${type.toUpperCase()}: ${message}`);
            return;
        }

        statusContainer.innerHTML = `
            <div class="status-message status-${type}">
                <span class="material-symbols-outlined">
                    ${type === 'success' ? 'check_circle' : 
                      type === 'error' ? 'error' : 
                      type === 'warning' ? 'warning' : 'info'}
                </span>
                ${message}
            </div>
        `;

        // Auto-hide after 5 seconds for non-error messages
        if (type !== 'error') {
            setTimeout(() => {
                statusContainer.innerHTML = '';
            }, 5000);
        }
    }

    setLoading(loading) {
        const searchButtons = document.querySelectorAll('.btn');
        searchButtons.forEach(btn => {
            if (loading) {
                btn.disabled = true;
            } else {
                btn.disabled = false;
            }
        });

        if (loading) {
            document.body.classList.add('loading');
        } else {
            document.body.classList.remove('loading');
        }
    }

    async fetchAPI(endpoint, options = {}) {
        try {
            const response = await fetch(`${this.apiBase}${endpoint}`, {
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    ...options.headers
                },
                ...options
            });

            if (!response.ok) {
                throw new Error(`API Error: ${response.status} ${response.statusText}`);
            }

            const data = await response.json();
            return data;
        } catch (error) {
            console.error(`API call failed for ${endpoint}:`, error);
            throw error;
        }
    }

    escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    formatDate(dateString) {
        if (!dateString) return '';
        try {
            return new Date(dateString).toLocaleDateString('ro-RO');
        } catch {
            return dateString;
        }
    }

    // Public methods for external access
    getSearchHistory() {
        return [...this.searchHistory];
    }

    clearResults() {
        const resultsSection = document.getElementById('results-section');
        if (resultsSection) {
            resultsSection.classList.add('hidden');
        }
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    try {
        window.inventorySearch = new InventorySearch();
        console.log('🔍 Inventory Search ready for operations');
    } catch (error) {
        console.error('❌ Failed to initialize Inventory Search:', error);
        alert('Eroare la inițializarea interfeței de căutare. Vă rugăm să reîncărcați pagina.');
    }
});

// Export for potential module use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = InventorySearch;
}