<?php
/**
 * Printer Management - Admin Interface
 * File: printer-management.php
 * 
 * Admin interface for managing print servers, printers, and monitoring print jobs
 * Following WMS design patterns and structure
 */

// Security check
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__); 
}

require_once BASE_PATH . '/bootstrap.php';

// Session and authentication check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Page title for header
$pageTitle = 'Print Management';
$currentPage = 'printer-management';

?>
<!DOCTYPE html>
<html lang="ro" data-theme="dark">
<head>
    <?php require_once BASE_PATH . '/includes/header.php'; ?>
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?>">
</head>
<body>
    <div class="app">
        <!-- Sidebar Navigation -->
        <?php require_once BASE_PATH . '/includes/navbar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <div class="page-container">
                <div class="page-header">
                    <div class="page-title-section">
                        <h1 class="page-title">
                            <span class="material-symbols-outlined">print</span>
                            Print Management
                        </h1>
                        <p class="page-description">Manage print servers, printers, and monitor print jobs</p>
                    </div>
                    
                    <div class="page-actions">
                        <div id="print-server-status" class="status-indicator status-unknown">
                            <span class="material-symbols-outlined">hourglass_empty</span>
                            Checking servers...
                        </div>
                    </div>
                </div>

                <!-- Tab Navigation -->
                <div class="tab-container">
                    <nav class="tab-nav">
                        <button class="tab-btn active" data-tab="servers">
                            <span class="material-symbols-outlined">computer</span>
                            Print Servers
                        </button>
                        <button class="tab-btn" data-tab="printers">
                            <span class="material-symbols-outlined">print</span>
                            Printers
                        </button>
                        <button class="tab-btn" data-tab="jobs">
                            <span class="material-symbols-outlined">history</span>
                            Print Jobs
                        </button>
                    </nav>
                </div>

                <!-- Print Servers Tab -->
                <div id="servers-tab" class="tab-content active">
                    <div class="content-header">
                        <h2>Print Servers</h2>
                        <div class="content-actions">
                            <button class="btn btn-secondary" onclick="refreshServers()">
                                <span class="material-symbols-outlined">refresh</span>
                                Refresh All
                            </button>
                            <button class="btn btn-primary" onclick="openAddServerModal()">
                                <span class="material-symbols-outlined">add</span>
                                Add Print Server
                            </button>
                        </div>
                    </div>

                    <div id="servers-grid" class="card-grid">
                        <!-- Server cards will be populated here -->
                        <div class="loading-placeholder">
                            <span class="material-symbols-outlined spinning">hourglass_empty</span>
                            Loading print servers...
                        </div>
                    </div>
                </div>

                <!-- Printers Tab -->
                <div id="printers-tab" class="tab-content">
                    <div class="content-header">
                        <h2>Printers</h2>
                        <div class="content-actions">
                            <button class="btn btn-primary" onclick="openAddPrinterModal()">
                                <span class="material-symbols-outlined">add</span>
                                Add Printer
                            </button>
                        </div>
                    </div>

                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Print Server</th>
                                        <th>Status</th>
                                        <th>Last Used</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="printers-table">
                                    <tr class="loading-row">
                                        <td colspan="6" class="text-center">
                                            <span class="material-symbols-outlined spinning">hourglass_empty</span>
                                            Loading printers...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Print Jobs Tab -->
                <div id="jobs-tab" class="tab-content">
                    <div class="content-header">
                        <h2>Print Jobs History</h2>
                        <div class="content-actions">
                            <button class="btn btn-secondary" onclick="refreshJobs()">
                                <span class="material-symbols-outlined">refresh</span>
                                Refresh
                            </button>
                        </div>
                    </div>

                    <div class="jobs-container">
                        <div id="jobs-history" class="print-history">
                            <div class="loading-placeholder">
                                <span class="material-symbols-outlined spinning">hourglass_empty</span>
                                Loading print jobs...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add/Edit Server Modal -->
    <div id="server-modal" class="modal">
        <div class="modal-backdrop" onclick="closeServerModal()"></div>
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 id="server-modal-title" class="modal-title">Add Print Server</h3>
                <button type="button" class="modal-close" onclick="closeServerModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            
            <form id="server-form">
                <div class="modal-body">
                    <input type="hidden" id="server-id">
                    
                    <div class="form-group">
                        <label class="form-label" for="server-name">
                            <span class="material-symbols-outlined">badge</span>
                            Server Name
                        </label>
                        <input type="text" id="server-name" class="form-control" 
                               placeholder="e.g., Reception PC" required>
                        <small class="form-hint">A friendly name to identify this print server</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="server-ip">
                            <span class="material-symbols-outlined">network_node</span>
                            IP Address
                        </label>
                        <input type="text" id="server-ip" class="form-control" 
                               placeholder="e.g., 192.168.1.100" pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$" required>
                        <small class="form-hint">Local network IP address of the computer</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="server-port">
                            <span class="material-symbols-outlined">settings_ethernet</span>
                            Port
                        </label>
                        <input type="number" id="server-port" class="form-control" 
                               value="8000" min="1000" max="65535" required>
                        <small class="form-hint">Port where the print server is running</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="server-location">
                            <span class="material-symbols-outlined">location_on</span>
                            Location
                        </label>
                        <input type="text" id="server-location" class="form-control" 
                               placeholder="e.g., Reception Desk, Warehouse Station 1">
                        <small class="form-hint">Physical location description (optional)</small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeServerModal()">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <span class="material-symbols-outlined">save</span>
                        Save Server
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add/Edit Printer Modal -->
    <div id="printer-modal" class="modal">
        <div class="modal-backdrop" onclick="closePrinterModal()"></div>
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 id="printer-modal-title" class="modal-title">Add Printer</h3>
                <button type="button" class="modal-close" onclick="closePrinterModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            
            <form id="printer-form">
                <div class="modal-body">
                    <input type="hidden" id="printer-id">
                    
                    <div class="form-group">
                        <label class="form-label" for="printer-name">
                            <span class="material-symbols-outlined">print</span>
                            Printer Name
                        </label>
                        <input type="text" id="printer-name" class="form-control" 
                               placeholder="e.g., Reception Brother Printer" required>
                        <small class="form-hint">A friendly name to identify this printer</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="printer-network-id">
                            <span class="material-symbols-outlined">network_node</span>
                            Network Identifier
                        </label>
                        <input type="text" id="printer-network-id" class="form-control" 
                               placeholder="e.g., Brother_DCP_L3520CDW_series" required>
                        <small class="form-hint">Exact printer name as it appears in the system (use lpstat -p to find)</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="printer-server">
                            <span class="material-symbols-outlined">computer</span>
                            Print Server
                        </label>
                        <select id="printer-server" class="form-control" required>
                            <option value="">Select a print server</option>
                        </select>
                        <small class="form-hint">The computer this printer is connected to</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="printer-type">
                                <span class="material-symbols-outlined">category</span>
                                Printer Type
                            </label>
                            <select id="printer-type" class="form-control" required>
                                <option value="invoice">Invoice</option>
                                <option value="label">Label</option>
                                <option value="receipt">Receipt</option>
                                <option value="document">Document</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="printer-paper-size">
                                <span class="material-symbols-outlined">description</span>
                                Paper Size
                            </label>
                            <select id="printer-paper-size" class="form-control">
                                <option value="A4">A4</option>
                                <option value="A5">A5</option>
                                <option value="Letter">Letter</option>
                                <option value="Label">Label</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="printer-notes">
                            <span class="material-symbols-outlined">notes</span>
                            Notes
                        </label>
                        <textarea id="printer-notes" class="form-control" rows="3" 
                                  placeholder="Additional information about this printer..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closePrinterModal()">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <span class="material-symbols-outlined">save</span>
                        Save Printer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Notifications Container -->
    <div id="toast-container" class="toast-container"></div>

    <!-- Scripts -->
    <script>
        // Global configuration
        window.APP_CONFIG = {
            baseUrl: '<?= BASE_PATH ?>',
            csrfToken: '<?= $_SESSION['csrf_token'] ?>',
            userRole: '<?= $_SESSION['role'] ?>'
        };
    </script>
  <?php require_once __DIR__ . '/includes/footer.php'; ?>