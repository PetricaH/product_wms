// Clean Returns Dashboard Logic - No Duplicates
let returnsTable;
let returnsChart;

// Enhanced fetchSummary with loading states and error handling
async function fetchSummary() {
    try {
        // Add loading state to stat cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.classList.add('loading');
        });

        const res = await fetch(`${WMS_CONFIG.apiBase}/returns/admin.php?action=summary`);
        
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        }
        
        const data = await res.json();
        
        if (data && data.summary) {
            // Animate numbers counting up
            animateNumber('stat-in-progress', data.summary.in_progress || 0);
            animateNumber('stat-pending', data.summary.pending || 0);
            animateNumber('stat-completed', data.summary.completed || 0);
            animateNumber('stat-discrepancies', data.summary.discrepancies || 0);
        } else {
            throw new Error('Invalid data structure received');
        }
    } catch (err) {
        console.error('Summary error', err);
        showErrorToast('Failed to load summary data: ' + err.message);
        
        // Set all stats to 0 as fallback
        document.getElementById('stat-in-progress').textContent = '0';
        document.getElementById('stat-pending').textContent = '0';
        document.getElementById('stat-completed').textContent = '0';
        document.getElementById('stat-discrepancies').textContent = '0';
    } finally {
        // Remove loading state
        document.querySelectorAll('.stat-card').forEach(card => {
            card.classList.remove('loading');
        });
    }
}

// Number animation function
function animateNumber(elementId, targetValue) {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    const startValue = parseInt(element.textContent) || 0;
    const duration = 1000;
    const startTime = performance.now();
    
    function updateNumber(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        
        // Easing function for smooth animation
        const easeOut = 1 - Math.pow(1 - progress, 3);
        const currentValue = Math.round(startValue + (targetValue - startValue) * easeOut);
        
        element.textContent = currentValue;
        
        if (progress < 1) {
            requestAnimationFrame(updateNumber);
        }
    }
    
    requestAnimationFrame(updateNumber);
}

// Enhanced table initialization
function initTable() {
    // Destroy existing DataTable if it exists
    if ($.fn.DataTable.isDataTable('#returns-table')) {
        $('#returns-table').DataTable().destroy();
        $('#returns-table').empty(); // Clear the table content
    }
    
    returnsTable = $('#returns-table').DataTable({
        ajax: {
            url: `${WMS_CONFIG.apiBase}/returns/admin.php?action=list`,
            dataSrc: function(json) { 
                console.log('DataTables received:', json);
                if (json && json.returns) {
                    return json.returns;
                }
                console.warn('No returns data received:', json);
                return []; 
            },
            error: function(xhr, error, thrown) {
                console.error('DataTables AJAX error:', {xhr, error, thrown});
                showErrorToast('Failed to load returns data: ' + error);
            }
        },
        columns: [
            { data: 'id' },
            { data: 'order_number' },
            { data: 'customer_name' },
            { 
                data: 'status',
                render: function(data, type, row) {
                    if (type === 'display') {
                        return `<span class="status-badge status-${data}">${data}</span>`;
                    }
                    return data;
                }
            },
            { data: 'processed_by' },
            { data: 'created_at' },
            { data: 'verified_at' },
            { 
                data: 'discrepancies',
                render: function(data, type, row) {
                    if (type === 'display') {
                        const count = parseInt(data) || 0;
                        if (count > 0) {
                            return `<span class="badge badge-warning">${count}</span>`;
                        }
                        return '<span class="badge badge-success">0</span>';
                    }
                    return data;
                }
            }
        ],
        language: {
            "lengthMenu": "Show _MENU_ returns",
            "zeroRecords": "No returns found",
            "info": "Showing _START_ to _END_ of _TOTAL_ returns",
            "infoEmpty": "Showing 0 to 0 of 0 returns",
            "infoFiltered": "(filtered from _MAX_ total returns)",
            "search": "Search returns:",
            "paginate": {
                "first": "First",
                "last": "Last",
                "next": "Next",
                "previous": "Previous"
            },
            "processing": "Loading returns..."
        },
        processing: true,
        pageLength: 25,
        responsive: true,
        order: [[0, 'desc']],
        drawCallback: function() {
            // Re-attach row click handlers after redraw
            attachRowClickHandlers();
        }
    });
}

// Separate function for row click handlers
function attachRowClickHandlers() {
    $('#returns-table tbody tr').off('click').on('click', function(){
        const data = returnsTable.row(this).data();
        if (data && data.id) {
            // Add loading state to clicked row
            $(this).addClass('loading');
            loadReturnDetail(data.id).finally(() => {
                $(this).removeClass('loading');
            });
        }
    });
}

// Enhanced loadReturnDetail with better UX
async function loadReturnDetail(id) {
    try {
        const res = await fetch(`${WMS_CONFIG.apiBase}/returns/admin.php?action=detail&id=${id}`);
        
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        }
        
        const data = await res.json();
        
        if (!data || !data.success) {
            throw new Error('Failed to load return details');
        }
        
        const r = data.return;
        let html = `
            <div class="return-header">
                <h3>Return #${r.id} - ${r.order_number}</h3>
                <div class="return-meta">
                    <p><strong>Status:</strong> <span class="status-badge status-${r.status}">${r.status}</span></p>
                    <p><strong>Client:</strong> ${r.customer_name}</p>
                    <p><strong>Created:</strong> ${r.created_at}</p>
                    ${r.verified_at ? `<p><strong>Verified:</strong> ${r.verified_at}</p>` : ''}
                </div>
            </div>
        `;
        
        html += '<div class="return-section"><h4><span class="material-symbols-outlined">inventory_2</span> Products</h4><ul class="products-list">';
        if (data.items && data.items.length > 0) {
            data.items.forEach(i => {
                html += `
                    <li class="product-item">
                        <div class="product-info">
                            <strong>${i.sku}</strong> - ${i.name}
                            <br><small>Quantity: ${i.quantity_returned} | Condition: ${i.item_condition}</small>
                        </div>
                    </li>`;
            });
        } else {
            html += '<li>No products found</li>';
        }
        html += '</ul></div>';
        
        html += '<div class="return-section"><h4><span class="material-symbols-outlined">warning</span> Discrepancies</h4><ul class="discrepancies-list">';
        if (!data.discrepancies || data.discrepancies.length === 0) {
            html += '<li class="no-discrepancies"><span class="material-symbols-outlined">check_circle</span> No discrepancies found</li>';
        } else {
            data.discrepancies.forEach(d => {
                html += `
                    <li class="discrepancy-item ${d.resolution_status}">
                        <div class="discrepancy-info">
                            <strong>${d.sku}</strong> - ${d.discrepancy_type}
                            <br><small>Status: ${d.resolution_status}</small>
                        </div>
                    </li>`;
            });
        }
        html += '</ul></div>';
        
        document.getElementById('return-details').innerHTML = html;
        showModal('return-modal');
        
    } catch (err) {
        console.error('Detail error', err);
        showErrorToast('Failed to load return details: ' + err.message);
    }
}

// Enhanced modal functions
function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        // Focus trap
        const focusableElements = modal.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        if (focusableElements.length > 0) {
            focusableElements[0].focus();
        }
    }
}

function closeReturnModal() {
    const modal = document.getElementById('return-modal');
    if (modal) {
        modal.classList.remove('show');
        
        setTimeout(() => {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }, 300);
    }
}

// Enhanced chart loading
async function loadChart() {
    try {
        const res = await fetch(`${WMS_CONFIG.apiBase}/returns/admin.php?action=stats`);
        
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        }
        
        const data = await res.json();
        
        if (!data || !data.stats) {
            console.warn('No chart data received:', data);
            return;
        }
        
        const labels = data.stats.map(r => {
            const date = new Date(r.day);
            return date.toLocaleDateString('ro-RO', { month: 'short', day: 'numeric' });
        });
        const values = data.stats.map(r => parseInt(r.total) || 0);
        
        // Destroy existing chart before creating new one
        if (returnsChart) {
            returnsChart.destroy();
        }
        
        const ctx = document.getElementById('returns-chart');
        if (!ctx) {
            console.error('Chart canvas not found');
            return;
        }
        
        returnsChart = new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: { 
                labels, 
                datasets: [{
                    label: 'Returns',
                    data: values,
                    borderColor: '#1a73e8',
                    backgroundColor: 'rgba(26, 115, 232, 0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 3,
                    pointBackgroundColor: '#1a73e8',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: { 
                responsive: true,
                maintainAspectRatio: false,
                scales: { 
                    y: { 
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                }
            }
        });
    } catch (err) { 
        console.error('Chart error', err);
        showErrorToast('Failed to load chart data: ' + err.message);
    }
}

// Toast notification system
function showErrorToast(message) {
    showToast(message, 'error');
}

function showSuccessToast(message) {
    showToast(message, 'success');
}

function showToast(message, type = 'info') {
    // Remove existing toasts
    document.querySelectorAll('.toast').forEach(t => t.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <div class="toast-content">
            <span class="material-symbols-outlined">${getToastIcon(type)}</span>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => toast.classList.add('show'), 100);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            if (toast.parentNode) {
                document.body.removeChild(toast);
            }
        }, 300);
    }, 5000);
}

function getToastIcon(type) {
    const icons = {
        success: 'check_circle',
        error: 'error',
        warning: 'warning',
        info: 'info'
    };
    return icons[type] || 'info';
}

// Enhanced initialization
document.addEventListener('DOMContentLoaded', () => {
    console.log('🚀 Returns Dashboard initializing...');
    
    // Check if WMS_CONFIG is available
    if (typeof WMS_CONFIG === 'undefined') {
        console.error('WMS_CONFIG not available');
        showErrorToast('Configuration error. Please refresh the page.');
        return;
    }
    
    console.log('✅ WMS_CONFIG available:', WMS_CONFIG);
    console.log('🔗 API calls will go to:', WMS_CONFIG.apiBase);
    
    // Initialize components with delay to ensure DOM is ready
    setTimeout(() => {
        fetchSummary();
        initTable();
        loadChart();
    }, 100);

    // Better event handling for filter form
    const filterForm = document.getElementById('filter-form');
    if (filterForm) {
        filterForm.addEventListener('submit', e => {
            e.preventDefault();
            if (returnsTable) {
                const params = new URLSearchParams(new FormData(e.target));
                const url = `${WMS_CONFIG.apiBase}/returns/admin.php?action=list&${params.toString()}`;
                console.log('Applying filters with URL:', url);
                returnsTable.ajax.url(url).load();
                showSuccessToast('Filters applied successfully');
            }
        });
    }

    // Export button
    const exportBtn = document.getElementById('export-btn');
    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            const filterForm = document.getElementById('filter-form');
            const params = new URLSearchParams(new FormData(filterForm));
            const url = `${WMS_CONFIG.apiBase}/returns/admin.php?action=list&export=csv&${params.toString()}`;
            console.log('Exporting with URL:', url);
            showSuccessToast('Export started...');
            window.open(url);
        });
    }

    // Modal close on backdrop click
    const modal = document.getElementById('return-modal');
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) {
                closeReturnModal();
            }
        });
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeReturnModal();
        }
    });

    // Auto-refresh with better error handling
    setInterval(() => {
        console.log('🔄 Auto-refreshing data...');
        fetchSummary();
        loadChart();
        if (returnsTable) {
            returnsTable.ajax.reload(null, false);
        }
    }, 60000);
});

// Add CSS styles for enhanced features
const additionalStyles = `
<style>
.loading {
    opacity: 0.6;
    pointer-events: none;
    position: relative;
}

.loading::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
    animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

.status-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-in_progress { 
    background: rgba(13, 202, 240, 0.2); 
    color: #0dcaf0; 
}

.status-pending { 
    background: rgba(255, 193, 7, 0.2); 
    color: #ffc107; 
}

.status-completed { 
    background: rgba(25, 135, 84, 0.2); 
    color: #198754; 
}

.badge {
    padding: 0.25rem 0.4rem;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 500;
}

.badge-warning { 
    background: #fff3cd; 
    color: #856404; 
}

.badge-success { 
    background: #d1e7dd; 
    color: #0f5132; 
}

.toast {
    position: fixed;
    top: 20px;
    right: 20px;
    background: var(--container-background, #fff);
    border: 1px solid var(--border-color, #ddd);
    border-radius: 8px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    z-index: 1060;
    transform: translateX(400px);
    opacity: 0;
    transition: all 0.3s ease;
    min-width: 300px;
    max-width: 500px;
}

.toast.show {
    transform: translateX(0);
    opacity: 1;
}

.toast-content {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    color: var(--text-primary, #333);
}

.toast-error { 
    border-left: 4px solid #dc3545; 
}

.toast-success { 
    border-left: 4px solid #198754; 
}

.toast-warning { 
    border-left: 4px solid #ffc107; 
}

.toast-info { 
    border-left: 4px solid #0dcaf0; 
}

.return-header {
    border-bottom: 1px solid var(--border-color, #ddd);
    padding-bottom: 1rem;
    margin-bottom: 1.5rem;
}

.return-meta p {
    margin: 0.5rem 0;
}

.return-section {
    margin: 1.5rem 0;
}

.return-section h4 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--primary-color, #007bff);
    margin-bottom: 1rem;
}

.products-list,
.discrepancies-list {
    list-style: none;
    padding: 0;
}

.product-item,
.discrepancy-item {
    background: var(--surface-background, #f8f9fa);
    border: 1px solid var(--border-color, #ddd);
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 0.5rem;
}

.no-discrepancies {
    background: rgba(25, 135, 84, 0.1);
    color: #198754;
    border-color: #198754;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
</style>
`;

// Inject additional styles
if (document.head) {
    document.head.insertAdjacentHTML('beforeend', additionalStyles);
}