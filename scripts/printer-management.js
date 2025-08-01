/**
 * Printer Management JavaScript
 * File: scripts/printer-management.js
 * 
 * Handles all functionality for the printer management admin interface
 */

'use strict';

// Application state management
const PrinterManagement = {
    state: {
        servers: [],
        printers: [],
        jobs: [],
        activeTab: 'servers',
        loading: {
            servers: false,
            printers: false,
            jobs: false
        }
    },
    
    // API endpoints
    api: {
        servers: 'api/printer_management.php?path=print-servers',
        printers: 'api/printer_management.php?path=printers',
        jobs: 'api/printer_management.php?path=print-jobs',
        ping: 'api/printer_management.php?path=ping-server',
        test: 'api/printer_management.php?path=test-print'
    },
    
    // DOM elements cache
    elements: {},
    
    // Initialize the application
    init() {
        this.cacheElements();
        this.bindEvents();
        this.loadAllData();
    },
    
    // Cache frequently used DOM elements
    cacheElements() {
        this.elements = {
            // Tab elements
            tabBtns: document.querySelectorAll('.tab-btn'),
            tabContents: document.querySelectorAll('.tab-content'),
            
            // Server elements
            serversGrid: document.getElementById('servers-grid'),
            serverModal: document.getElementById('server-modal'),
            serverForm: document.getElementById('server-form'),
            serverModalTitle: document.getElementById('server-modal-title'),
            
            // Printer elements
            printersTable: document.getElementById('printers-table'),
            printerModal: document.getElementById('printer-modal'),
            printerForm: document.getElementById('printer-form'),
            printerModalTitle: document.getElementById('printer-modal-title'),
            printerServerSelect: document.getElementById('printer-server'),
            printerDefaultCheckbox: document.getElementById('printer-default'),
            
            // Job elements
            jobsHistory: document.getElementById('jobs-history'),
            
            // Status elements
            printServerStatus: document.getElementById('print-server-status'),
            
            // Toast container
            toastContainer: document.getElementById('toast-container')
        };
    },
    
    // Bind event listeners
    bindEvents() {
        // Tab navigation
        this.elements.tabBtns.forEach(btn => {
            btn.addEventListener('click', () => this.switchTab(btn.dataset.tab));
        });
        
        // Form submissions
        this.elements.serverForm.addEventListener('submit', (e) => this.handleServerSubmit(e));
        this.elements.printerForm.addEventListener('submit', (e) => this.handlePrinterSubmit(e));
        
        // Global keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleKeyboardShortcuts(e));
    },
    
    // Handle keyboard shortcuts
    handleKeyboardShortcuts(e) {
        // ESC to close modals
        if (e.key === 'Escape') {
            this.closeAllModals();
        }
        
        // Ctrl/Cmd + R to refresh current tab
        if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
            e.preventDefault();
            this.refreshCurrentTab();
        }
    },
    
    // Tab management
    switchTab(tabName) {
        // Update tab buttons
        this.elements.tabBtns.forEach(btn => btn.classList.remove('active'));
        document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');
        
        // Update tab content
        this.elements.tabContents.forEach(content => content.classList.remove('active'));
        document.getElementById(`${tabName}-tab`).classList.add('active');
        
        this.state.activeTab = tabName;
        
        // Load data if not already loaded
        if (tabName === 'jobs' && this.state.jobs.length === 0) {
            this.loadJobs();
        }
    },
    
    // Data loading functions
    async loadAllData() {
        await Promise.all([
            this.loadServers(),
            this.loadPrinters(),
            this.loadJobs()
        ]);
        
        this.checkPrintServerStatus();
    },
    
    async loadServers() {
        this.state.loading.servers = true;
        this.showLoadingState('servers');
        
        try {
            const response = await fetch(this.api.servers);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            
            this.state.servers = await response.json();
            this.renderServers();
        } catch (error) {
            console.error('Failed to load servers:', error);
            this.showToast('Failed to load print servers', 'error');
            this.showErrorState('servers');
        } finally {
            this.state.loading.servers = false;
        }
    },
    
    async loadPrinters() {
        this.state.loading.printers = true;
        this.showLoadingState('printers');
        
        try {
            const response = await fetch(this.api.printers);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            
            this.state.printers = await response.json();
            this.renderPrinters();
            this.updatePrinterServerSelect();
        } catch (error) {
            console.error('Failed to load printers:', error);
            this.showToast('Failed to load printers', 'error');
            this.showErrorState('printers');
        } finally {
            this.state.loading.printers = false;
        }
    },
    
    async loadJobs() {
        this.state.loading.jobs = true;
        this.showLoadingState('jobs');
        
        try {
            const response = await fetch(this.api.jobs);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            
            this.state.jobs = await response.json();
            this.renderJobs();
        } catch (error) {
            console.error('Failed to load jobs:', error);
            this.showToast('Failed to load print jobs', 'error');
            this.showErrorState('jobs');
        } finally {
            this.state.loading.jobs = false;
        }
    },
    
    // Rendering functions
    renderServers() {
        if (!this.elements.serversGrid) return;
        
        if (this.state.servers.length === 0) {
            this.elements.serversGrid.innerHTML = this.getEmptyState('servers');
            return;
        }
        
        this.elements.serversGrid.innerHTML = this.state.servers.map(server => `
            <div class="server-card ${server.is_active ? 'online' : 'offline'}">
                <div class="server-header">
                    <h3 class="server-title">${this.escapeHtml(server.name)}</h3>
                    <div class="server-actions">
                        <button class="icon-btn" onclick="PrinterManagement.pingServer(${server.id})" title="Ping Server">
                            <span class="material-symbols-outlined">network_ping</span>
                        </button>
                        <button class="icon-btn" onclick="PrinterManagement.editServer(${server.id})" title="Edit">
                            <span class="material-symbols-outlined">edit</span>
                        </button>
                        <button class="icon-btn" onclick="PrinterManagement.deleteServer(${server.id})" title="Delete">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </div>
                </div>
                
                <div class="server-info">
                    <div class="info-row">
                        <span>Status:</span>
                        <span class="status-indicator ${server.is_active ? 'status-online' : 'status-offline'}">
                            <span class="material-symbols-outlined">${server.is_active ? 'check_circle' : 'cancel'}</span>
                            ${server.is_active ? 'Online' : 'Offline'}
                        </span>
                    </div>
                    <div class="info-row">
                        <span>Address:</span>
                        <span>${server.ip_address}:${server.port}</span>
                    </div>
                    <div class="info-row">
                        <span>Location:</span>
                        <span>${server.location || 'Not specified'}</span>
                    </div>
                    <div class="info-row">
                        <span>Printers:</span>
                        <span>${server.printer_count} connected</span>
                    </div>
                    <div class="info-row">
                        <span>Last Ping:</span>
                        <span>${server.last_ping ? this.formatDateTime(server.last_ping) : 'Never'}</span>
                    </div>
                </div>
            </div>
        `).join('');
    },
    
    renderPrinters() {
        if (!this.elements.printersTable) return;
        
        if (this.state.printers.length === 0) {
            this.elements.printersTable.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center" style="padding: 3rem 1rem; color: var(--text-muted);">
                        <span class="material-symbols-outlined" style="font-size: 3rem; margin-bottom: 1rem; display: block;">print_disabled</span>
                        No printers configured yet
                    </td>
                </tr>
            `;
            return;
        }
        
        this.elements.printersTable.innerHTML = this.state.printers.map(printer => `
            <tr>
                <td>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <strong>${this.escapeHtml(printer.name)}</strong>
                        ${printer.is_default ? '<span class="status-indicator" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6; font-size: 0.7rem;">Default</span>' : ''}
                    </div>
                    <small style="color: var(--text-muted);">${this.escapeHtml(printer.network_identifier)}</small>
                </td>
                <td>
                    <span class="job-status" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                        ${printer.printer_type}
                    </span>
                </td>
                <td>
                    ${printer.print_server ? `
                        <div>
                            <strong>${this.escapeHtml(printer.print_server.name)}</strong>
                            <br>
                            <small style="color: var(--text-muted);">${printer.print_server.ip_address}:${printer.print_server.port}</small>
                        </div>
                    ` : '<span style="color: var(--text-muted);">No server</span>'}
                </td>
                <td>
                    <span class="status-indicator ${printer.is_active && printer.print_server?.is_active ? 'status-online' : 'status-offline'}">
                        <span class="material-symbols-outlined">${printer.is_active && printer.print_server?.is_active ? 'check_circle' : 'cancel'}</span>
                        ${printer.is_active && printer.print_server?.is_active ? 'Active' : 'Inactive'}
                    </span>
                </td>
                <td>${printer.last_used ? this.formatDateTime(printer.last_used) : 'Never'}</td>
                <td>
                    <div style="display: flex; gap: 0.25rem;">
                        <button class="icon-btn" onclick="PrinterManagement.testPrinter(${printer.id})" title="Test Print">
                            <span class="material-symbols-outlined">print</span>
                        </button>
                        <button class="icon-btn" onclick="PrinterManagement.editPrinter(${printer.id})" title="Edit">
                            <span class="material-symbols-outlined">edit</span>
                        </button>
                        <button class="icon-btn" onclick="PrinterManagement.deletePrinter(${printer.id})" title="Delete">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
    },
    
    renderJobs() {
        if (!this.elements.jobsHistory) return;
        
        if (this.state.jobs.length === 0) {
            this.elements.jobsHistory.innerHTML = `
                <div class="loading-placeholder">
                    <span class="material-symbols-outlined">history</span>
                    No print jobs found
                </div>
            `;
            return;
        }
        
        this.elements.jobsHistory.innerHTML = this.state.jobs.map(job => `
            <div class="job-item">
                <div class="job-info">
                    <strong>Order #${job.order_id || 'N/A'}</strong> - ${job.job_type}
                    <br>
                    <small>${this.escapeHtml(job.printer_name || 'Unknown')} (${this.escapeHtml(job.server_name || 'Unknown')})</small>
                    ${job.error_message ? `<br><small style="color: #ef4444;">Error: ${this.escapeHtml(job.error_message)}</small>` : ''}
                </div>
                <div class="job-meta">
                    <div class="job-status job-${job.status}">${job.status}</div>
                    <small>${this.formatDateTime(job.created_at)}</small>
                    ${job.attempts > 1 ? `<small>Attempts: ${job.attempts}</small>` : ''}
                </div>
            </div>
        `).join('');
    },
    
    // Server management functions
    openAddServerModal() {
        this.elements.serverModalTitle.textContent = 'Add Print Server';
        this.elements.serverForm.reset();
        document.getElementById('server-id').value = '';
        this.showModal('server');
    },
    
    editServer(id) {
        const server = this.state.servers.find(s => s.id === id);
        if (!server) return;
        
        this.elements.serverModalTitle.textContent = 'Edit Print Server';
        document.getElementById('server-id').value = server.id;
        document.getElementById('server-name').value = server.name;
        document.getElementById('server-ip').value = server.ip_address;
        document.getElementById('server-port').value = server.port;
        document.getElementById('server-location').value = server.location || '';
        this.showModal('server');
    },
    
    async pingServer(id) {
        const button = event.currentTarget;
        const originalContent = button.innerHTML;
        console.log('Ping URL:', `${this.api.ping}?server_id=${id}`);
        // Show loading state
        button.innerHTML = '<span class="material-symbols-outlined spinning">hourglass_empty</span>';
        button.disabled = true;
        
        try {
            const response = await fetch(`${this.api.ping}&server_id=${id}`);
            console.log('Response status:', response.status); // DEBUG
            const result = await response.json();
            console.log('Response data:', result); // DEBUG
            
            if (result.success) {
                this.showToast('Server is online and responding', 'success');
            } else {
                this.showToast('Server is offline or not responding', 'error');
            }
            
            // Refresh server data
            await this.loadServers();
        } catch (error) {
            console.error('Ping failed:', error);
            this.showToast('Failed to ping server', 'error');
        } finally {
            button.innerHTML = originalContent;
            button.disabled = false;
        }
    },
    
    async deleteServer(id) {
        const server = this.state.servers.find(s => s.id === id);
        if (!server) return;
        
        if (!confirm(`Are you sure you want to delete "${server.name}"?\n\nThis action cannot be undone.`)) {
            return;
        }
        
        try {
            const response = await fetch(`${this.api.servers}?id=${id}`, { 
                method: 'DELETE' 
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showToast('Print server deleted successfully', 'success');
                await this.loadServers();
                await this.loadPrinters(); // Reload printers as they might be affected
            } else {
                this.showToast(result.error || 'Failed to delete print server', 'error');
            }
        } catch (error) {
            console.error('Delete failed:', error);
            this.showToast('Failed to delete print server', 'error');
        }
    },
    
    // Printer management functions
    openAddPrinterModal() {
        this.elements.printerModalTitle.textContent = 'Add Printer';
        this.elements.printerForm.reset();
        document.getElementById('printer-id').value = '';
        this.elements.printerDefaultCheckbox.checked = false;
        this.showModal('printer');
    },
    
    editPrinter(id) {
        const printer = this.state.printers.find(p => p.id === id);
        if (!printer) return;
        
        this.elements.printerModalTitle.textContent = 'Edit Printer';
        document.getElementById('printer-id').value = printer.id;
        document.getElementById('printer-name').value = printer.name;
        document.getElementById('printer-network-id').value = printer.network_identifier;
        document.getElementById('printer-server').value = printer.print_server ? printer.print_server.id : '';
        document.getElementById('printer-type').value = printer.printer_type;
        document.getElementById('printer-paper-size').value = printer.paper_size;
        document.getElementById('printer-notes').value = printer.notes || '';
        this.elements.printerDefaultCheckbox.checked = printer.is_default;
        this.showModal('printer');
    },
    
    async testPrinter(id) {
        const button = event.currentTarget;
        const originalContent = button.innerHTML;
        
        // Show loading state
        button.innerHTML = '<span class="material-symbols-outlined spinning">hourglass_empty</span>';
        button.disabled = true;
        
        try {
            const response = await fetch(this.api.test, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ printer_id: id })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showToast('Test print sent successfully', 'success');
                await this.loadPrinters(); // Refresh to update last_used timestamp
            } else {
                this.showToast(`Test print failed: ${result.error}`, 'error');
            }
        } catch (error) {
            console.error('Test print failed:', error);
            this.showToast('Failed to send test print', 'error');
        } finally {
            button.innerHTML = originalContent;
            button.disabled = false;
        }
    },
    
    async deletePrinter(id) {
        const printer = this.state.printers.find(p => p.id === id);
        if (!printer) return;
        
        if (!confirm(`Are you sure you want to delete "${printer.name}"?\n\nThis action cannot be undone.`)) {
            return;
        }
        
        try {
            const response = await fetch(`${this.api.printers}?id=${id}`, { 
                method: 'DELETE' 
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showToast('Printer deleted successfully', 'success');
                await this.loadPrinters();
            } else {
                this.showToast(result.error || 'Failed to delete printer', 'error');
            }
        } catch (error) {
            console.error('Delete failed:', error);
            this.showToast('Failed to delete printer', 'error');
        }
    },
    
    // Form submission handlers
    async handleServerSubmit(e) {
        e.preventDefault();
        
        const formData = {
            name: document.getElementById('server-name').value.trim(),
            ip_address: document.getElementById('server-ip').value.trim(),
            port: parseInt(document.getElementById('server-port').value),
            location: document.getElementById('server-location').value.trim()
        };
        
        // Validation
        if (!formData.name || !formData.ip_address || !formData.port) {
            this.showToast('Please fill in all required fields', 'warning');
            return;
        }
        
        if (!this.isValidIPAddress(formData.ip_address)) {
            this.showToast('Please enter a valid IP address', 'warning');
            return;
        }
        
        const serverId = document.getElementById('server-id').value;
        const isEdit = !!serverId;
        
        if (isEdit) {
            formData.id = parseInt(serverId);
        }
        
        try {
            const response = await fetch(this.api.servers, {
                method: isEdit ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData)
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showToast(`Print server ${isEdit ? 'updated' : 'created'} successfully`, 'success');
                this.closeModal('server');
                await this.loadServers();
                
                // Auto-ping new server
                if (!isEdit && result.id) {
                    setTimeout(() => this.pingServer(result.id), 1000);
                }
            } else {
                this.showToast(result.error || 'Failed to save print server', 'error');
            }
        } catch (error) {
            console.error('Server save failed:', error);
            this.showToast('Failed to save print server', 'error');
        }
    },
    
    async handlePrinterSubmit(e) {
        e.preventDefault();
        
        const formData = {
            name: document.getElementById('printer-name').value.trim(),
            network_identifier: document.getElementById('printer-network-id').value.trim(),
            print_server_id: parseInt(document.getElementById('printer-server').value) || null,
            printer_type: document.getElementById('printer-type').value,
            paper_size: document.getElementById('printer-paper-size').value,
            notes: document.getElementById('printer-notes').value.trim(),
            is_default: this.elements.printerDefaultCheckbox.checked
        };
        
        // Validation
        if (!formData.name || !formData.network_identifier) {
            this.showToast('Please fill in all required fields', 'warning');
            return;
        }
        
        const printerId = document.getElementById('printer-id').value;
        const isEdit = !!printerId;
        
        if (isEdit) {
            formData.id = parseInt(printerId);
        }
        
        try {
            const response = await fetch(this.api.printers, {
                method: isEdit ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData)
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showToast(`Printer ${isEdit ? 'updated' : 'created'} successfully`, 'success');
                this.closeModal('printer');
                await this.loadPrinters();
            } else {
                this.showToast(result.error || 'Failed to save printer', 'error');
            }
        } catch (error) {
            console.error('Printer save failed:', error);
            this.showToast('Failed to save printer', 'error');
        }
    },
    
    // Utility functions
    updatePrinterServerSelect() {
        if (!this.elements.printerServerSelect) return;
        
        const activeServers = this.state.servers.filter(s => s.is_active);
        
        this.elements.printerServerSelect.innerHTML = '<option value="">Select a print server</option>' +
            activeServers.map(server => 
                `<option value="${server.id}">${this.escapeHtml(server.name)} (${server.ip_address})</option>`
            ).join('');
    },
    
    async checkPrintServerStatus() {
        const onlineServers = this.state.servers.filter(s => s.is_active).length;
        const totalServers = this.state.servers.length;
        
        if (this.elements.printServerStatus) {
            this.elements.printServerStatus.innerHTML = `
                <span class="material-symbols-outlined">print</span>
                Print Servers: ${onlineServers}/${totalServers} online
            `;
            this.elements.printServerStatus.className = `status-indicator ${onlineServers > 0 ? 'status-online' : 'status-offline'}`;
        }
        
        return { online: onlineServers, total: totalServers };
    },
    
    // Modal management
    showModal(type) {
        const modal = document.getElementById(`${type}-modal`);
        if (modal) {
            modal.classList.add('show');
            // Focus first input
            const firstInput = modal.querySelector('input, select, textarea');
            if (firstInput) {
                setTimeout(() => firstInput.focus(), 100);
            }
        }
    },
    
    closeModal(type) {
        const modal = document.getElementById(`${type}-modal`);
        if (modal) {
            modal.classList.remove('show');
        }
    },
    
    closeAllModals() {
        document.querySelectorAll('.modal.show').forEach(modal => {
            modal.classList.remove('show');
        });
    },
    
    // State management
    showLoadingState(section) {
        const element = this.elements[`${section}Grid`] || this.elements[`${section}Table`] || this.elements[`${section}History`];
        if (!element) return;
        
        const loadingHtml = `
            <div class="loading-placeholder">
                <span class="material-symbols-outlined spinning">hourglass_empty</span>
                Loading ${section}...
            </div>
        `;
        
        if (section === 'printers') {
            element.innerHTML = `<tr><td colspan="6">${loadingHtml}</td></tr>`;
        } else {
            element.innerHTML = loadingHtml;
        }
    },
    
    showErrorState(section) {
        const element = this.elements[`${section}Grid`] || this.elements[`${section}Table`] || this.elements[`${section}History`];
        if (!element) return;
        
        const errorHtml = `
            <div class="loading-placeholder">
                <span class="material-symbols-outlined">error</span>
                Failed to load ${section}
                <br>
                <button class="btn btn-sm btn-secondary" onclick="PrinterManagement.load${section.charAt(0).toUpperCase() + section.slice(1)}()">
                    Try again
                </button>
            </div>
        `;
        
        if (section === 'printers') {
            element.innerHTML = `<tr><td colspan="6">${errorHtml}</td></tr>`;
        } else {
            element.innerHTML = errorHtml;
        }
    },
    
    getEmptyState(section) {
        const states = {
            servers: `
                <div class="loading-placeholder">
                    <span class="material-symbols-outlined">computer</span>
                    No print servers configured yet
                    <br>
                    <button class="btn btn-primary" onclick="PrinterManagement.openAddServerModal()">
                        Add your first print server
                    </button>
                </div>
            `
        };
        
        return states[section] || `<div class="loading-placeholder">No ${section} found</div>`;
    },
    
    // Toast notification system
    showToast(message, type = 'info', duration = 5000) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const icons = {
            success: 'check_circle',
            error: 'error',
            warning: 'warning',
            info: 'info'
        };
        
        toast.innerHTML = `
            <div class="toast-content">
                <span class="material-symbols-outlined">${icons[type] || 'info'}</span>
                <span>${this.escapeHtml(message)}</span>
            </div>
            <button class="toast-close" onclick="this.parentElement.remove()">
                <span class="material-symbols-outlined">close</span>
            </button>
        `;
        
        this.elements.toastContainer.appendChild(toast);
        
        // Auto-remove after duration
        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
        }, duration);
    },
    
    // Refresh functions
    async refreshCurrentTab() {
        switch (this.state.activeTab) {
            case 'servers':
                await this.loadServers();
                break;
            case 'printers':
                await this.loadPrinters();
                break;
            case 'jobs':
                await this.loadJobs();
                break;
        }
        this.checkPrintServerStatus();
    },
    
    // Validation helpers
    isValidIPAddress(ip) {
        const regex = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
        return regex.test(ip);
    },
    
    // Formatting helpers
    formatDateTime(dateString) {
        try {
            const date = new Date(dateString);
            return date.toLocaleString('ro-RO', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        } catch (error) {
            return dateString;
        }
    },
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

// Global functions for onclick handlers
window.PrinterManagement = PrinterManagement;

// Convenience functions for global access
window.openAddServerModal = () => PrinterManagement.openAddServerModal();
window.openAddPrinterModal = () => PrinterManagement.openAddPrinterModal();
window.closeServerModal = () => PrinterManagement.closeModal('server');
window.closePrinterModal = () => PrinterManagement.closeModal('printer');
window.refreshServers = () => PrinterManagement.loadServers();
window.refreshJobs = () => PrinterManagement.loadJobs();

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    PrinterManagement.init();
    
    // Set up periodic status checking
    setInterval(() => {
        if (!document.hidden) { // Only check when page is visible
            PrinterManagement.checkPrintServerStatus();
        }
    }, 30000); // Every 30 seconds
});