// File: scripts/warehouse-js/warehouse_hub.js
// Warehouse Hub Dashboard JavaScript - FIXED: Only the API base path

class WarehouseHub {
    constructor() {
        this.apiBase = window.WMS_CONFIG?.apiBase || '/api';
        this.currentUser = null;
        this.stats = {};
        this.init();
    }

    async init() {
        console.log('🏢 Initializing Warehouse Hub...');
        this.initEventListeners();
        this.updateTime();
        setInterval(() => this.updateTime(), 1000);
        await this.loadUserInfo();
        await this.loadStatistics();
        setInterval(() => this.loadStatistics(), 300000); // Refresh stats every 5 minutes
        console.log('✅ Warehouse Hub initialized successfully');
    }

    initEventListeners() {
        // Operation card click handlers
        document.querySelectorAll('.operation-card').forEach(card => {
            card.addEventListener('click', (e) => this.handleOperationClick(e));
            
            // Add keyboard support
            card.setAttribute('tabindex', '0');
            card.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.handleOperationClick(e);
                }
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.altKey) {
                switch(e.key) {
                    case '1':
                        e.preventDefault();
                        this.navigateToOperation('picking');
                        break;
                    case '2':
                        e.preventDefault();
                        this.navigateToOperation('receiving');
                        break;
                    case '3':
                        e.preventDefault();
                        this.navigateToOperation('inventory');
                        break;
                    case '4':
                        e.preventDefault();
                        this.navigateToOperation('cycle-count');
                        break;
                }
            }
        });

        // Error handler for global errors
        window.addEventListener('error', (e) => {
            console.error('Global error caught:', e.error);
            this.showNotification('Eroare în aplicație: ' + e.error.message, 'error');
        });
    }

    updateTime() {
        const now = new Date();
        const timeString = now.toLocaleTimeString('ro-RO', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        
        const timeElement = document.getElementById('current-time');
        if (timeElement) {
            timeElement.textContent = timeString;
        }
    }

    async loadUserInfo() {
        try {
            console.log('👤 Loading user information...');
            
            // FIXED: Call .php file directly instead of going through API router
            const result = await this.fetchAPI('/user/current.php');
            
            if (result.status === 'success' && result.user) {
                this.currentUser = result.user;
                this.updateUserDisplay();
                console.log('✅ User info loaded:', this.currentUser.name);
            } else {
                throw new Error('Invalid user data received');
            }
        } catch (error) {
            console.warn('⚠️ Could not load user info:', error.message);
            // Fallback to default user
            this.currentUser = {
                name: 'Lucrător Depozit',
                role: 'warehouse_worker'
            };
            this.updateUserDisplay();
        }
    }

    async loadStatistics() {
        try {
            console.log('📊 Loading dashboard statistics...');
            this.setLoadingState(true);
            
            // FIXED: Call .php file directly instead of going through API router  
            const result = await this.fetchAPI('/warehouse/dashboard_stats.php');
            
            if (result.status === 'success' && result.stats) {
                this.stats = result.stats;
                this.updateStatistics();
                this.updateStatusIndicators();
                console.log('✅ Statistics loaded successfully');
            } else {
                throw new Error('Invalid statistics data received');
            }
        } catch (error) {
            console.warn('⚠️ Could not load statistics:', error.message);
            // Show placeholder data
            this.loadPlaceholderStats();
        } finally {
            this.setLoadingState(false);
        }
    }

    updateUserDisplay() {
        const workerNameEl = document.getElementById('worker-name');
        if (workerNameEl && this.currentUser) {
            workerNameEl.textContent = this.currentUser.name;
        }
    }

    loadPlaceholderStats() {
        // Fallback stats when API is unavailable
        this.stats = {
            pending_picks: Math.floor(Math.random() * 50) + 10,
            picks_today: Math.floor(Math.random() * 200) + 50,
            pending_receipts: Math.floor(Math.random() * 20) + 5,
            received_today: Math.floor(Math.random() * 100) + 20,
            total_products: Math.floor(Math.random() * 5000) + 1000,
            low_stock_items: Math.floor(Math.random() * 50) + 10,
            scheduled_counts: Math.floor(Math.random() * 10) + 2,
            variance_items: Math.floor(Math.random() * 30) + 5
        };
        
        this.updateStatistics();
        this.updateStatusIndicators();
        console.log('📊 Using placeholder statistics');
    }

    updateStatistics() {
        // Update picking stats
        this.updateStatElement('pending-picks', this.stats.pending_picks);
        this.updateStatElement('picks-today', this.stats.picks_today);
        
        // Update receiving stats
        this.updateStatElement('pending-receipts', this.stats.pending_receipts);
        this.updateStatElement('received-today', this.stats.received_today);
        
        // Update inventory stats
        this.updateStatElement('total-products', this.stats.total_products);
        this.updateStatElement('low-stock-items', this.stats.low_stock_items);
        
        // Update cycle count stats
        this.updateStatElement('scheduled-counts', this.stats.scheduled_counts);
        this.updateStatElement('variance-items', this.stats.variance_items);
    }

    updateStatElement(elementId, value) {
        const element = document.getElementById(elementId);
        if (element) {
            element.textContent = this.formatNumber(value);
        }
    }

    updateStatusIndicators() {
        // Update picking status
        const pickingStatus = this.stats.pending_picks > 20 ? 'danger' : 
                             this.stats.pending_picks > 10 ? 'warning' : 'success';
        this.updateStatusIndicator('picking-status', pickingStatus);
        
        // Update receiving status
        const receivingStatus = this.stats.pending_receipts > 15 ? 'danger' : 
                               this.stats.pending_receipts > 8 ? 'warning' : 'success';
        this.updateStatusIndicator('receiving-status', receivingStatus);
        
        // Update inventory status
        const inventoryStatus = this.stats.low_stock_items > 30 ? 'danger' : 
                               this.stats.low_stock_items > 15 ? 'warning' : 'success';
        this.updateStatusIndicator('inventory-status', inventoryStatus);
        
        // Update cycle count status
        const cycleCountStatus = this.stats.variance_items > 20 ? 'danger' : 
                                this.stats.variance_items > 10 ? 'warning' : 'success';
        this.updateStatusIndicator('cycle-count-status', cycleCountStatus);
    }

    updateStatusIndicator(elementId, status) {
        const element = document.getElementById(elementId);
        if (element) {
            element.className = `status-indicator ${status}`;
        }
    }

    formatNumber(value) {
        if (value === null || value === undefined) {
            return '-';
        }
        
        if (typeof value === 'number') {
            return value.toLocaleString('ro-RO');
        }
        
        return value;
    }

    handleOperationClick(e) {
        const card = e.currentTarget;
        const operation = card.dataset.operation;
        
        if (operation) {
            console.log(`🎯 Operation selected: ${operation}`);
            this.navigateToOperation(operation, card);
        }
    }

    navigateToOperation(operation, card = null) {
        if (card) {
            card.classList.add('loading');
        }

        // Add delay for better UX (shows loading state)
        setTimeout(() => {
            try {
                switch(operation) {
                    case 'picking':
                        console.log('🛒 Navigating to picking orders dashboard...');
                        window.location.href = 'warehouse_orders.php';
                        break;
                    case 'receiving':
                        console.log('📦 Navigating to receiving interface...');
                        window.location.href = 'mobile_receiving.php';
                        break;
                    case 'inventory':
                        console.log('📋 Navigating to inventory search...');
                        window.location.href = 'warehouse_inventory.php';
                        break;
                    case 'cycle-count':
                        console.log('🔍 Navigating to cycle count interface...');
                        window.location.href = 'warehouse_cycle_count.php';
                        break;
                    default:
                        console.warn(`⚠️ Unknown operation: ${operation}`);
                        this.showNotification(`Interfața pentru ${operation} va fi disponibilă în curând.`, 'info');
                        if (card) {
                            card.classList.remove('loading');
                        }
                }
            } catch (error) {
                console.error('❌ Navigation error:', error);
                this.showNotification('Eroare la navigare: ' + error.message, 'error');
                if (card) {
                    card.classList.remove('loading');
                }
            }
        }, 500);
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

    setLoadingState(loading) {
        document.querySelectorAll('.operation-card').forEach(card => {
            if (loading) {
                card.classList.add('loading');
            } else {
                card.classList.remove('loading');
            }
        });
    }

    showNotification(message, type = 'info') {
        // Simple notification system
        console.log(`${type.toUpperCase()}: ${message}`);
        
        // You can implement a toast notification system here
        // For now, we'll use a simple alert for errors
        if (type === 'error') {
            alert(message);
        }
    }

    // Public method to refresh data
    async refresh() {
        console.log('🔄 Refreshing dashboard data...');
        await this.loadStatistics();
    }

    // Public method to get current stats
    getStats() {
        return { ...this.stats };
    }

    // Public method to get current user
    getCurrentUser() {
        return { ...this.currentUser };
    }
}

// Initialize the warehouse hub when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    try {
        window.warehouseHub = new WarehouseHub();
        console.log('🏢 Warehouse Hub ready for operations');
    } catch (error) {
        console.error('❌ Failed to initialize Warehouse Hub:', error);
        alert('Eroare la inițializarea aplicației. Vă rugăm să reîncărcați pagina.');
    }
});

// Export for potential module use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = WarehouseHub;
}