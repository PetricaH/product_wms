 // Tab functionality
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.closest('.tab-button').classList.add('active');
        }

        // Manual sync functions
        async function manualSync(syncType) {
            const button = event.target.closest('button');
            const originalText = button.innerHTML;
            
            button.classList.add('loading');
            button.disabled = true;
            button.innerHTML = '<span class="material-symbols-outlined">sync</span> Sincronizare...';
            
            try {
                const response = await fetch('?action=ajax', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax_action=manual_sync&sync_type=${syncType}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Sincronizare completă: ' + result.message, 'success');
                    updateStats();
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showNotification('Eroare: ' + result.message, 'error');
                }
            } catch (error) {
                showNotification('Eroare de conexiune: ' + error.message, 'error');
            } finally {
                button.classList.remove('loading');
                button.disabled = false;
                button.innerHTML = originalText;
            }
        }

        // Test connection
        async function testConnection() {
            const button = event.target.closest('button');
            const originalText = button.innerHTML;
            
            button.classList.add('loading');
            button.disabled = true;
            button.innerHTML = '<span class="material-symbols-outlined">sync</span> Test...';
            
            try {
                const response = await fetch('?action=ajax', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'ajax_action=test_connection'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Conexiunea funcționează!', 'success');
                } else {
                    showNotification('Eroare: ' + result.message, 'error');
                }
            } catch (error) {
                showNotification('Eroare de conexiune: ' + error.message, 'error');
            } finally {
                button.classList.remove('loading');
                button.disabled = false;
                button.innerHTML = originalText;
            }
        }

        // Update statistics
        async function updateStats() {
            try {
                const response = await fetch('?action=ajax&ajax_action=get_status');
                const data = await response.json();
                
                if (data.metrics) {
                    document.getElementById('successful-syncs').textContent = data.metrics.successful_syncs || 0;
                    document.getElementById('failed-syncs').textContent = data.metrics.failed_syncs || 0;
                }
                
                if (data.pending_invoices !== undefined) {
                    document.getElementById('pending-invoices').textContent = data.pending_invoices;
                }
            } catch (error) {
                console.error('Error updating stats:', error);
            }
        }

        // Show notification
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `message ${type}`;
            notification.innerHTML = `
                <span class="material-symbols-outlined">
                    ${type === 'success' ? 'check_circle' : 'error'}
                </span>
                ${message}
            `;
            
            const container = document.querySelector('.container');
            container.insertBefore(notification, container.firstChild);
            
            setTimeout(() => {
                notification.remove();
            }, 5000);
        }

        // Auto-refresh functionality
        setInterval(updateStats, 30000); // Update stats every 30 seconds
        
        // Initial load
        document.addEventListener('DOMContentLoaded', function() {
            updateStats();
        });