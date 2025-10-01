// File: scripts/warehouse-js/warehouse_hub.js
// Warehouse Hub Dashboard JavaScript - FIXED: Only the API base path

class WarehouseHub {
    constructor() {
        this.apiBase = window.WMS_CONFIG?.apiBase || '/api';
        this.baseUrl = window.WMS_CONFIG?.baseUrl || '/';
        this.currentUser = null;
        this.stats = {};
        this.pollInterval = 5000;
        this.pollTimer = null;
        this.isFetching = false;
        this.hasQueuedFetch = false;
        this.hasLoadedOnce = false;
        this.skipNextNotification = false;
        this.statsAbortController = null;
        this.timeInterval = null;
        this.toastTimeout = null;
        this.visibilityHandler = this.handleVisibilityChange.bind(this);
        this.cleanupHandler = this.cleanup.bind(this);
        this.isDestroyed = false;
        this.init();
    }

    async init() {
        console.log('🏢 Initializing Warehouse Hub...');
        this.initEventListeners();
        this.updateTime();
        this.timeInterval = setInterval(() => this.updateTime(), 1000);
        await this.loadUserInfo();
        await this.loadStatistics();

        if (!document.hidden) {
            this.scheduleNextPoll();
        }

        document.addEventListener('visibilitychange', this.visibilityHandler);
        window.addEventListener('beforeunload', this.cleanupHandler);
        window.addEventListener('pagehide', this.cleanupHandler);
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
                    case '5':
                        e.preventDefault();
                        this.navigateToOperation('relocation');
                        break;
                    case '6':
                        e.preventDefault();
                        this.navigateToOperation('barcode');
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

    async loadStatistics({ background = false } = {}) {
        if (this.isFetching) {
            this.hasQueuedFetch = true;
            return;
        }

        const shouldShowLoading = !background && !this.hasLoadedOnce;

        if (shouldShowLoading) {
            this.setLoadingState(true);
        }

        this.isFetching = true;
        const controller = new AbortController();
        this.statsAbortController = controller;

        try {
            if (!background) {
                console.log('📊 Loading dashboard statistics...');
            }

            // FIXED: Call .php file directly instead of going through API router
            const result = await this.fetchAPI('/warehouse/dashboard_stats.php', {
                signal: controller.signal,
                cache: 'no-store'
            });

            if (result.status === 'success' && result.stats) {
                const previousStats = this.hasLoadedOnce ? { ...this.stats } : null;
                this.stats = this.normalizeStats(result.stats);
                this.updateStatistics(previousStats);
                this.updateStatusIndicators();
                this.checkForNotifications(previousStats, this.stats);
                this.hasLoadedOnce = true;
                console.log('✅ Statistics loaded successfully');
            } else {
                throw new Error('Invalid statistics data received');
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                console.log('ℹ️ Statistic update aborted');
                return;
            }

            console.warn('⚠️ Could not load statistics:', error.message);

            if (!this.hasLoadedOnce) {
                this.loadPlaceholderStats();
            }
        } finally {
            if (this.statsAbortController === controller) {
                this.statsAbortController = null;
            }

            this.isFetching = false;

            if (shouldShowLoading) {
                this.setLoadingState(false);
            }

            if (this.hasQueuedFetch && !document.hidden) {
                this.hasQueuedFetch = false;
                this.loadStatistics({ background: true });
            }
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
        const previousStats = this.hasLoadedOnce ? { ...this.stats } : null;

        this.stats = this.normalizeStats({
            pending_picks: Math.floor(Math.random() * 50) + 10,
            picks_today: Math.floor(Math.random() * 200) + 50,
            pending_receipts: Math.floor(Math.random() * 20) + 5,
            received_today: Math.floor(Math.random() * 100) + 20,
            total_products: Math.floor(Math.random() * 5000) + 1000,
            low_stock_items: Math.floor(Math.random() * 50) + 10,
            scheduled_counts: Math.floor(Math.random() * 10) + 2,
            variance_items: Math.floor(Math.random() * 30) + 5,
            pending_relocations: Math.floor(Math.random() * 20),
            relocations_today: Math.floor(Math.random() * 20),
            pending_barcode_tasks: Math.floor(Math.random() * 10),
            barcode_tasks_today: Math.floor(Math.random() * 10)
        });

        this.updateStatistics(previousStats);
        this.updateStatusIndicators();
        this.hasLoadedOnce = true;
        this.skipNextNotification = true;
        console.log('📊 Using placeholder statistics');
    }

    normalizeStats(rawStats = {}) {
        const toNumber = (value) => {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const numeric = Number(value);
            return Number.isFinite(numeric) ? numeric : 0;
        };

        const normalized = {
            pending_picks: this.stats.pending_picks ?? 0,
            picks_today: this.stats.picks_today ?? 0,
            pending_receipts: this.stats.pending_receipts ?? 0,
            received_today: this.stats.received_today ?? this.stats.receipts_today ?? 0,
            total_products: this.stats.total_products ?? 0,
            low_stock_items: this.stats.low_stock_items ?? 0,
            scheduled_counts: this.stats.scheduled_counts ?? 0,
            variance_items: this.stats.variance_items ?? 0,
            pending_relocations: this.stats.pending_relocations ?? 0,
            relocations_today: this.stats.relocations_today ?? 0,
            pending_barcode_tasks: this.stats.pending_barcode_tasks ?? 0,
            barcode_tasks_today: this.stats.barcode_tasks_today ?? 0,
            receipts_today: this.stats.receipts_today ?? this.stats.received_today ?? 0
        };

        const assignIfDefined = (key, value) => {
            if (value !== undefined && value !== null && value !== '') {
                normalized[key] = toNumber(value);
            }
        };

        assignIfDefined('pending_picks', rawStats.pending_picks);
        assignIfDefined('picks_today', rawStats.picks_today);
        assignIfDefined('pending_receipts', rawStats.pending_receipts);
        assignIfDefined('total_products', rawStats.total_products);
        assignIfDefined('low_stock_items', rawStats.low_stock_items);
        assignIfDefined('scheduled_counts', rawStats.scheduled_counts);
        assignIfDefined('variance_items', rawStats.variance_items);
        assignIfDefined('pending_relocations', rawStats.pending_relocations);
        assignIfDefined('relocations_today', rawStats.relocations_today);
        assignIfDefined('pending_barcode_tasks', rawStats.pending_barcode_tasks);
        assignIfDefined('barcode_tasks_today', rawStats.barcode_tasks_today ?? rawStats.completed_barcode_tasks);

        const receivedToday = rawStats.received_today ?? rawStats.receipts_today;
        if (receivedToday !== undefined && receivedToday !== null && receivedToday !== '') {
            normalized.received_today = toNumber(receivedToday);
        }

        normalized.receipts_today = normalized.received_today;

        return normalized;
    }

    updateStatistics(previousStats = null) {
        // Update picking stats
        this.updateStatElement('pending-picks', this.stats.pending_picks, previousStats?.pending_picks);
        this.updateStatElement('picks-today', this.stats.picks_today, previousStats?.picks_today);

        // Update receiving stats
        this.updateStatElement('pending-receipts', this.stats.pending_receipts, previousStats?.pending_receipts);
        this.updateStatElement('received-today', this.stats.received_today, previousStats?.received_today ?? previousStats?.receipts_today);

        // Update inventory stats
        this.updateStatElement('total-products', this.stats.total_products, previousStats?.total_products);
        this.updateStatElement('low-stock-items', this.stats.low_stock_items, previousStats?.low_stock_items);

        // Update cycle count stats
        this.updateStatElement('scheduled-counts', this.stats.scheduled_counts, previousStats?.scheduled_counts);
        this.updateStatElement('variance-items', this.stats.variance_items, previousStats?.variance_items);

        // Update relocation stats
        this.updateStatElement('pending-relocations', this.stats.pending_relocations, previousStats?.pending_relocations);
        this.updateStatElement('relocated-today', this.stats.relocations_today, previousStats?.relocations_today);

        // Update barcode task stats
        this.updateStatElement('pending-barcode-tasks', this.stats.pending_barcode_tasks, previousStats?.pending_barcode_tasks);
        this.updateStatElement('completed-barcode-tasks', this.stats.barcode_tasks_today, previousStats?.barcode_tasks_today);
    }

    updateStatElement(elementId, value, previousValue) {
        const element = document.getElementById(elementId);
        if (element) {
            const formattedValue = this.formatNumber(value);
            const hasPrevious = previousValue !== undefined && previousValue !== null;
            const hasChanged = hasPrevious ? value !== previousValue : element.textContent !== formattedValue;

            if (!hasChanged) {
                return;
            }

            element.textContent = formattedValue;

            if (hasPrevious) {
                this.flashStatUpdate(element, value, previousValue);
            }
        }
    }

    flashStatUpdate(element, newValue, oldValue) {
        if (!element) {
            return;
        }

        const isNumeric = typeof newValue === 'number' && typeof oldValue === 'number';
        let directionClass = '';

        if (isNumeric) {
            if (newValue > oldValue) {
                directionClass = 'stat-increase';
            } else if (newValue < oldValue) {
                directionClass = 'stat-decrease';
            }
        }

        element.classList.remove('stat-changed', 'stat-increase', 'stat-decrease');

        // Force reflow to restart animation
        void element.offsetWidth; // eslint-disable-line no-unused-expressions

        const classesToAdd = ['stat-changed'];
        if (directionClass) {
            classesToAdd.push(directionClass);
        }

        element.classList.add(...classesToAdd);

        if (element._statTimeout) {
            clearTimeout(element._statTimeout);
        }

        element._statTimeout = setTimeout(() => {
            element.classList.remove('stat-changed', 'stat-increase', 'stat-decrease');
            element._statTimeout = null;
        }, 900);
    }

    checkForNotifications(previousStats, currentStats) {
        if (!previousStats) {
            return;
        }

        if (this.skipNextNotification) {
            this.skipNextNotification = false;
            return;
        }

        const deltas = (key) => {
            const prevValue = typeof previousStats[key] === 'number' ? previousStats[key] : 0;
            const currentValue = typeof currentStats[key] === 'number' ? currentStats[key] : 0;
            return currentValue - prevValue;
        };

        const notifications = [];

        const pendingPicksDelta = deltas('pending_picks');
        if (pendingPicksDelta > 0) {
            notifications.push(`Noi comenzi de picking disponibile (+${pendingPicksDelta}).`);
        }

        const pendingReceiptsDelta = deltas('pending_receipts');
        if (pendingReceiptsDelta > 0) {
            notifications.push(`Noi recepții de procesat (+${pendingReceiptsDelta}).`);
        }

        const pendingRelocationsDelta = deltas('pending_relocations');
        if (pendingRelocationsDelta > 0) {
            notifications.push(`Sarcini noi de relocare disponibile (+${pendingRelocationsDelta}).`);
        }

        const pendingBarcodeDelta = deltas('pending_barcode_tasks');
        if (pendingBarcodeDelta > 0) {
            notifications.push(`Sarcini noi de scanare coduri (+${pendingBarcodeDelta}).`);
        }

        if (notifications.length > 0) {
            this.showToast(notifications[0], 'warning');
        }
    }

    showToast(message, type = 'info') {
        const toastElement = document.getElementById('dashboard-toast');

        if (!toastElement) {
            console.log(`${type.toUpperCase()}: ${message}`);
            return;
        }

        toastElement.textContent = message;

        const typeClasses = ['info', 'success', 'warning', 'error'];
        toastElement.classList.remove('visible', ...typeClasses);

        void toastElement.offsetWidth; // Restart transition

        const toastType = typeClasses.includes(type) ? type : 'info';
        toastElement.classList.add(toastType, 'visible');

        if (this.toastTimeout) {
            clearTimeout(this.toastTimeout);
        }

        this.toastTimeout = setTimeout(() => {
            toastElement.classList.remove('visible');
        }, 3200);
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

        // Update relocation status
        const relocationStatus = this.stats.pending_relocations > 15 ? 'danger' :
                                 this.stats.pending_relocations > 5 ? 'warning' : 'success';
        this.updateStatusIndicator('relocation-status', relocationStatus);

        const barcodeStatus = this.stats.pending_barcode_tasks > 20 ? 'danger' :
                               this.stats.pending_barcode_tasks > 0 ? 'warning' : 'success';
        this.updateStatusIndicator('barcode-task-status', barcodeStatus);
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
                        window.location.href = `${this.baseUrl}warehouse_orders.php`;
                        break;
                    case 'receiving':
                        console.log('📦 Navigating to receiving interface...');
                        window.location.href = `${this.baseUrl}warehouse_receiving.php`;
                        break;
                    case 'inventory':
                        console.log('📋 Navigating to inventory search...');
                        window.location.href = `${this.baseUrl}warehouse_inventory.php`;
                        break;
                    case 'cycle-count':
                        console.log('🔍 Navigating to cycle count interface...');
                        window.location.href = `${this.baseUrl}warehouse_cycle_count.php`;
                        break;
                    case 'relocation':
                        console.log('🔄 Navigating to relocation tasks...');
                        window.location.href = `${this.baseUrl}warehouse_relocation.php`;
                        break;
                    case 'barcode':
                        console.log('📋 Navigating to barcode tasks...');
                        window.location.href = `${this.baseUrl}warehouse_barcode_tasks.php`;
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
            const { headers = {}, ...restOptions } = options;
            const fetchOptions = {
                cache: 'no-store',
                ...restOptions,
                headers: {
                    'Accept': 'application/json',
                    ...headers
                }
            };

            if (fetchOptions.body && !fetchOptions.headers['Content-Type']) {
                fetchOptions.headers['Content-Type'] = 'application/json';
            }

            const response = await fetch(`${this.apiBase}${endpoint}`, fetchOptions);

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
        if (type === 'error') {
            console.error(message);
            alert(message);
            return;
        }

        this.showToast(message, type);
    }

    scheduleNextPoll() {
        this.stopPolling();

        if (this.pollInterval <= 0 || this.isDestroyed) {
            return;
        }

        this.pollTimer = setTimeout(() => this.executePoll(), this.pollInterval);
    }

    async executePoll() {
        this.pollTimer = null;

        if (this.isDestroyed || document.hidden) {
            return;
        }

        await this.loadStatistics({ background: true });

        if (!this.isDestroyed && !document.hidden) {
            this.scheduleNextPoll();
        }
    }

    stopPolling() {
        if (this.pollTimer) {
            clearTimeout(this.pollTimer);
            this.pollTimer = null;
        }
    }

    handleVisibilityChange() {
        if (document.hidden) {
            this.stopPolling();
            this.hasQueuedFetch = false;

            if (this.statsAbortController) {
                this.statsAbortController.abort();
            }
        } else {
            this.loadStatistics({ background: true });
            this.scheduleNextPoll();
        }
    }

    cleanup() {
        if (this.isDestroyed) {
            return;
        }

        this.isDestroyed = true;
        this.stopPolling();

        if (this.timeInterval) {
            clearInterval(this.timeInterval);
            this.timeInterval = null;
        }

        if (this.toastTimeout) {
            clearTimeout(this.toastTimeout);
            this.toastTimeout = null;
        }

        if (this.statsAbortController) {
            this.statsAbortController.abort();
            this.statsAbortController = null;
        }

        document.removeEventListener('visibilitychange', this.visibilityHandler);
        window.removeEventListener('beforeunload', this.cleanupHandler);
        window.removeEventListener('pagehide', this.cleanupHandler);
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