/**
 * Dashboard Statistics Manager
 * File: scripts/index.js
 * 
 * Handles real-time updates for the duration statistics cards
 * in the admin dashboard index page
 */

class DashboardStatsManager {
    constructor() {
        this.isUpdating = false;
        this.updateInterval = null;
        this.init();
    }

    init() {
        console.log('Dashboard Statistics Manager initialized');
        
        // Start auto-refresh
        this.startAutoRefresh();
        
        // Listen for custom events from other parts of the system
        this.setupEventListeners();
    }

    setupEventListeners() {
        // Listen for order completion/processing events
        document.addEventListener('orderCompleted', () => {
            this.refreshStats();
        });

        document.addEventListener('orderProcessed', () => {
            this.refreshStats();
        });

        // Listen for receiving completion events
        document.addEventListener('receivingCompleted', () => {
            this.refreshStats();
        });
    }

    startAutoRefresh() {
        // Auto-refresh every 2 minutes
        this.updateInterval = setInterval(() => {
            this.refreshStats();
        }, 120000);
    }

    stopAutoRefresh() {
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
            this.updateInterval = null;
        }
    }

    async refreshStats() {
        if (this.isUpdating) return;
        
        this.isUpdating = true;
        console.log('Refreshing duration statistics...');

        try {
            const response = await fetch('api/dashboard_stats.php');
            const result = await response.json();

            if (result.success) {
                this.updateStatsCards(result.data);
                console.log('Statistics updated successfully');
            } else {
                console.error('Failed to refresh stats:', result.error);
            }
        } catch (error) {
            console.error('Error refreshing stats:', error);
        } finally {
            this.isUpdating = false;
        }
    }

    updateStatsCards(data) {
        // Update all 4 duration cards
        this.updateDurationCard('picking-operator', data.pickingByOperator);
        this.updateDurationCard('picking-category', data.pickingByCategory);
        this.updateDurationCard('receiving-operator', data.receivingByOperator);
        this.updateDurationCard('receiving-category', data.receivingByCategory);
    }

    updateDurationCard(cardType, data) {
        const cardSelector = this.getCardSelector(cardType);
        const card = document.querySelector(cardSelector);
        
        if (!card) {
            console.warn(`Card not found for type: ${cardType}`);
            return;
        }

        const contentDiv = card.querySelector('.card-content');
        if (!contentDiv) {
            console.warn(`Content div not found in card: ${cardType}`);
            return;
        }

        // Update the content
        if (!data || data.length === 0) {
            contentDiv.innerHTML = this.getEmptyStateHTML();
        } else {
            contentDiv.innerHTML = this.getDurationListHTML(data);
        }
        
        // Add visual feedback
        this.showUpdateFeedback(card);
    }

    getCardSelector(cardType) {
        // Map card types to CSS selectors based on the card structure
        const cardSelectors = {
            'picking-operator': '.duration-card:nth-child(1)',
            'picking-category': '.duration-card:nth-child(2)',
            'receiving-operator': '.duration-card:nth-child(3)',
            'receiving-category': '.duration-card:nth-child(4)'
        };

        return cardSelectors[cardType] || '.duration-card';
    }

    getEmptyStateHTML() {
        return `
            <div class="empty-state">
                <span class="material-symbols-outlined">info</span>
                <p>Fără date</p>
            </div>
        `;
    }

    getDurationListHTML(data) {
        const listItems = data.map(item => {
            const name = item.operator || item.category || 'N/A';
            const minutes = parseFloat(item.avg_minutes || 0).toFixed(1);
            
            return `
                <div class="duration-item">
                    <span class="duration-name">${this.escapeHtml(name)}</span>
                    <span class="duration-value">${minutes} min</span>
                </div>
            `;
        }).join('');

        return `<div class="duration-list">${listItems}</div>`;
    }

    showUpdateFeedback(card) {
        // Add updated class for visual feedback
        card.classList.add('stats-updated');
        
        // Remove the class after animation
        setTimeout(() => {
            card.classList.remove('stats-updated');
        }, 1500);
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Public methods for manual control
    forceRefresh() {
        return this.refreshStats();
    }

    destroy() {
        this.stopAutoRefresh();
        document.removeEventListener('orderCompleted', this.refreshStats);
        document.removeEventListener('orderProcessed', this.refreshStats);
        document.removeEventListener('receivingCompleted', this.refreshStats);
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize the dashboard stats manager
    window.dashboardStatsManager = new DashboardStatsManager();
    
    // Add CSS for visual feedback
    const style = document.createElement('style');
    style.textContent = `
        .stats-updated {
            border-left: 4px solid #28a745 !important;
            background-color: rgba(40, 167, 69, 0.1) !important;
            transition: all 0.3s ease;
        }
        
        .duration-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .duration-item:last-child {
            border-bottom: none;
        }
        
        .duration-name {
            font-weight: 500;
        }
        
        .duration-value {
            font-weight: 600;
            color: #28a745;
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: rgba(255, 255, 255, 0.6);
        }
        
        .empty-state .material-symbols-outlined {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
    `;
    document.head.appendChild(style);
    
    console.log('Dashboard index.js loaded successfully');
});

// Global functions for external triggering
window.refreshDashboardStats = function() {
    if (window.dashboardStatsManager) {
        return window.dashboardStatsManager.forceRefresh();
    }
};

// Helper functions to trigger events from other parts of the system
window.triggerOrderCompleted = function(orderId) {
    document.dispatchEvent(new CustomEvent('orderCompleted', { detail: { orderId } }));
};

window.triggerOrderProcessed = function(orderId) {
    document.dispatchEvent(new CustomEvent('orderProcessed', { detail: { orderId } }));
};

window.triggerReceivingCompleted = function(sessionId) {
    document.dispatchEvent(new CustomEvent('receivingCompleted', { detail: { sessionId } }));
};