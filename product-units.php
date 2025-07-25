<?php
/**
 * Product Units Management - Admin Interface
 * File: product-units.php
 * 
 * Admin interface for managing product units, weights, and packaging rules
 * Following WMS design patterns and structure
 */

// Security check
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__); 
}

require_once BASE_PATH . '/bootstrap.php';

// Session and authentication check
// session_start();
// if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
//     header('Location: login.php');
//     exit;
// }

// Generate CSRF token
// if (!isset($_SESSION['csrf_token'])) {
//     $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
// }

// Page title for header
$pageTitle = 'Administrare Unități Produse';
$currentPage = 'product-units';

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

        <!-- Main Content Area -->
        <main class="main-content">
            <div class="page-container">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="page-header-content">
                        <h1 class="page-title">
                            <span class="material-symbols-outlined">inventory_2</span>
                            Administrare Unități Produse
                        </h1>
                        <div class="header-actions">
                            <button class="btn btn-secondary" id="refreshStatsBtn">
                                <span class="material-symbols-outlined">refresh</span>
                                Actualizează
                            </button>
                            <button class="btn btn-primary" id="addProductUnitBtn">
                                <span class="material-symbols-outlined">add</span>
                                Adaugă Configurare
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Statistics Dashboard -->
                <div class="stats-section">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <span class="material-symbols-outlined">inventory</span>
                            </div>
                            <div class="stat-content">
                                <div class="stat-number" id="totalProducts">-</div>
                                <div class="stat-label">Produse Configurate</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <span class="material-symbols-outlined">straighten</span>
                            </div>
                            <div class="stat-content">
                                <div class="stat-number" id="totalUnitTypes">8</div>
                                <div class="stat-label">Tipuri de Unități</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <span class="material-symbols-outlined">package_2</span>
                            </div>
                            <div class="stat-content">
                                <div class="stat-number" id="totalPackagingRules">4</div>
                                <div class="stat-label">Reguli Ambalare</div>
                            </div>
                        </div>
                        <div class="stat-card warning">
                            <div class="stat-icon">
                                <span class="material-symbols-outlined">warning</span>
                            </div>
                            <div class="stat-content">
                                <div class="stat-number" id="pendingProducts">-</div>
                                <div class="stat-label">Produse Fără Configurare</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation Tabs -->
                <div class="tabs-container">
                    <nav class="tabs-nav">
                        <button class="tab-button active" data-tab="product-units">
                            <span class="material-symbols-outlined">inventory_2</span>
                            Unități Produse
                        </button>
                        <button class="tab-button" data-tab="unit-types">
                            <span class="material-symbols-outlined">straighten</span>
                            Tipuri Unități
                        </button>
                        <button class="tab-button" data-tab="packaging-rules">
                            <span class="material-symbols-outlined">package_2</span>
                            Reguli Ambalare
                        </button>
                        <button class="tab-button" data-tab="stock-management">
                            <span class="material-symbols-outlined">warehouse</span>
                            Gestiune Stocuri
                        </button>
                        <button class="tab-button" data-tab="cargus-config">
                            <span class="material-symbols-outlined">settings</span>
                            Configurare Cargus
                        </button>
                    </nav>
                </div>

                <!-- Tab Content -->
                <div class="tabs-content">
                    <!-- Product Units Tab -->
                    <div id="product-units" class="tab-content active">
                        <div class="tab-header">
                            <h2>Configurare Unități Produse</h2>
                            <div class="tab-actions">
                                <button class="btn btn-secondary" id="exportProductUnits">
                                    <span class="material-symbols-outlined">download</span>
                                    Export
                                </button>
                                <button class="btn btn-primary" id="addProductUnitFromTab">
                                    <span class="material-symbols-outlined">add</span>
                                    Adaugă Configurare
                                </button>
                            </div>
                        </div>

                        <!-- Filters -->
                        <div class="filters-section">
                            <div class="filters-form">
                                <div class="filter-group">
                                    <label for="productFilter" class="filter-label">Produs</label>
                                    <input type="text" 
                                           id="productFilter" 
                                           class="filter-input" 
                                           placeholder="Caută după numele produsului...">
                                </div>
                                <div class="filter-group">
                                    <label for="unitFilter" class="filter-label">Unitate</label>
                                    <select id="unitFilter" class="filter-select">
                                        <option value="">Toate unitățile</option>
                                        <option value="litri">Litri</option>
                                        <option value="buc">Bucăți</option>
                                        <option value="cartus">Cartușe</option>
                                        <option value="kg">Kilograme</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label for="statusFilter" class="filter-label">Status</label>
                                    <select id="statusFilter" class="filter-select">
                                        <option value="">Toate</option>
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                                <div class="filter-actions">
                                    <button type="button" class="btn btn-secondary" id="clearFilters">
                                        <span class="material-symbols-outlined">clear</span>
                                        Șterge
                                    </button>
                                    <button type="button" class="btn btn-primary" id="applyFilters">
                                        <span class="material-symbols-outlined">search</span>
                                        Filtrează
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Product Units Table -->
                        <div class="table-container">
                            <div class="table-header">
                                <div class="table-info">
                                    <span id="tableResultsCount">Încărcare...</span>
                                </div>
                                <div class="table-controls">
                                    <button class="btn btn-secondary btn-sm" id="refreshTable">
                                        <span class="material-symbols-outlined">refresh</span>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="table-wrapper">
                                <table class="data-table" id="productUnitsTable">
                                    <thead>
                                        <tr>
                                            <th>Produs</th>
                                            <th>Cod Produs</th>
                                            <th>Unitate</th>
                                            <th>Greutate/Unitate</th>
                                            <th>Volum/Unitate</th>
                                            <th>Proprietăți</th>
                                            <th>Max/Colet</th>
                                            <th>Status</th>
                                            <th>Acțiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody id="productUnitsBody">
                                        <!-- Table rows will be loaded via JavaScript -->
                                        <tr class="loading-row">
                                            <td colspan="9" class="text-center">
                                                <div class="loading-spinner">
                                                    <span class="material-symbols-outlined spinning">progress_activity</span>
                                                    Încărcare date...
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Unit Types Tab -->
                    <div id="unit-types" class="tab-content">
                        <div class="tab-header">
                            <h2>Tipuri de Unități</h2>
                            <div class="tab-actions">
                                <button class="btn btn-primary" id="addUnitType">
                                    <span class="material-symbols-outlined">add</span>
                                    Adaugă Tip Unitate
                                </button>
                            </div>
                        </div>

                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Cod Unitate</th>
                                        <th>Nume</th>
                                        <th>Tip Bază</th>
                                        <th>Greutate Implicită</th>
                                        <th>Tip Ambalare</th>
                                        <th>Max/Colet</th>
                                        <th>Colet Separat</th>
                                        <th>Acțiuni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><code>litri</code></td>
                                        <td>Litri</td>
                                        <td><span class="badge badge-info">Volume</span></td>
                                        <td><strong>1.000 kg</strong></td>
                                        <td><span class="badge badge-warning">Lichid</span></td>
                                        <td>1</td>
                                        <td><span class="status-active">Da</span></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-secondary" title="Editează">
                                                    <span class="material-symbols-outlined">edit</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><code>buc</code></td>
                                        <td>Bucăți</td>
                                        <td><span class="badge badge-success">Count</span></td>
                                        <td><strong>0.500 kg</strong></td>
                                        <td><span class="badge badge-primary">Single</span></td>
                                        <td>10</td>
                                        <td><span class="status-inactive">Nu</span></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-secondary" title="Editează">
                                                    <span class="material-symbols-outlined">edit</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><code>cartus</code></td>
                                        <td>Cartușe</td>
                                        <td><span class="badge badge-success">Count</span></td>
                                        <td><strong>0.200 kg</strong></td>
                                        <td><span class="badge badge-primary">Single</span></td>
                                        <td>20</td>
                                        <td><span class="status-inactive">Nu</span></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-secondary" title="Editează">
                                                    <span class="material-symbols-outlined">edit</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><code>kg</code></td>
                                        <td>Kilograme</td>
                                        <td><span class="badge badge-danger">Weight</span></td>
                                        <td><strong>1.000 kg</strong></td>
                                        <td><span class="badge badge-secondary">Bulk</span></td>
                                        <td>1</td>
                                        <td><span class="status-inactive">Nu</span></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-secondary" title="Editează">
                                                    <span class="material-symbols-outlined">edit</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Packaging Rules Tab -->
                    <div id="packaging-rules" class="tab-content">
                        <div class="tab-header">
                            <h2>Reguli de Ambalare</h2>
                            <div class="tab-actions">
                                <button class="btn btn-primary" id="addPackagingRule">
                                    <span class="material-symbols-outlined">add</span>
                                    Adaugă Regulă
                                </button>
                            </div>
                        </div>

                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Nume Regulă</th>
                                        <th>Tip</th>
                                        <th>Greutate Max</th>
                                        <th>Articole Max</th>
                                        <th>Prioritate</th>
                                        <th>Status</th>
                                        <th>Acțiuni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Lichide - parcel separat</strong></td>
                                        <td><span class="badge badge-warning">product_type</span></td>
                                        <td>30.000 kg</td>
                                        <td>1</td>
                                        <td><span class="priority-high">100</span></td>
                                        <td><span class="status-active">Activ</span></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-secondary" title="Editează">
                                                    <span class="material-symbols-outlined">edit</span>
                                                </button>
                                                <button class="btn btn-sm btn-danger" title="Șterge">
                                                    <span class="material-symbols-outlined">delete</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Produse mici - combinabile</strong></td>
                                        <td><span class="badge badge-success">count_based</span></td>
                                        <td>20.000 kg</td>
                                        <td>50</td>
                                        <td><span class="priority-medium">50</span></td>
                                        <td><span class="status-active">Activ</span></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-secondary" title="Editează">
                                                    <span class="material-symbols-outlined">edit</span>
                                                </button>
                                                <button class="btn btn-sm btn-danger" title="Șterge">
                                                    <span class="material-symbols-outlined">delete</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Produse grele - individual</strong></td>
                                        <td><span class="badge badge-danger">weight_based</span></td>
                                        <td>30.000 kg</td>
                                        <td>1</td>
                                        <td><span class="priority-high">75</span></td>
                                        <td><span class="status-active">Activ</span></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-secondary" title="Editează">
                                                    <span class="material-symbols-outlined">edit</span>
                                                </button>
                                                <button class="btn btn-sm btn-danger" title="Șterge">
                                                    <span class="material-symbols-outlined">delete</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Stock Management Tab -->
                    <div id="stock-management" class="tab-content">
                        <div class="tab-header">
                            <h2>Gestiune Stocuri</h2>
                            <div class="tab-actions">
                                <button class="btn btn-primary" id="addStockSetting">
                                    <span class="material-symbols-outlined">add</span>
                                    Adaugă Setări
                                </button>
                            </div>
                        </div>

                        <div class="stock-search-bar">
                            <input type="text" id="stockSearch" class="stock-search-input" placeholder="Caută produs...">
                        </div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Produs</th>
                                        <th>Furnizor Asignat</th>
                                        <th>Stoc Curent</th>
                                        <th>Stoc Minim</th>
                                        <th>Cant. Min. Comandă</th>
                                        <th>Autocomandă</th>
                                        <th>Ultima Comandă Auto</th>
                                        <th>Acțiuni</th>
                                    </tr>
                                </thead>
                                <tbody id="stockSettingsBody">
                                    <tr class="loading-row">
                                        <td colspan="8" class="text-center">
                                            <div class="loading-spinner">
                                                <span class="material-symbols-outlined spinning">progress_activity</span>
                                                Încărcare date...
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="stockPagination" class="pagination-wrapper"></div>
                    </div>

                    <!-- Cargus Config Tab -->
                    <div id="cargus-config" class="tab-content">
                        <div class="tab-header">
                            <h2>Configurare Cargus API</h2>
                            <div class="tab-actions">
                                <button class="btn btn-secondary" id="testCargusConnection">
                                    <span class="material-symbols-outlined">wifi</span>
                                    Testează Conexiunea
                                </button>
                                <button class="btn btn-success" id="saveCargusConfig">
                                    <span class="material-symbols-outlined">save</span>
                                    Salvează Configurația
                                </button>
                            </div>
                        </div>

                        <!-- Success Alert -->
                        <div class="alert alert-success" style="display: none;" id="configAlert">
                            <span class="material-symbols-outlined">check_circle</span>
                            Configurările au fost salvate cu succes!
                        </div>

                        <!-- Configuration Form -->
                        <form id="cargusConfigForm" class="config-form">
                            <div class="form-section">
                                <h3 class="section-title">
                                    <span class="material-symbols-outlined">api</span>
                                    Configurare API
                                </h3>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="apiUrl">URL API Cargus</label>
                                        <input type="url" 
                                               id="apiUrl" 
                                               name="api_url" 
                                               value="https://urgentcargus.portal.azure-api.net/" 
                                               required>
                                        <small class="form-text">URL-ul de bază pentru API-ul Cargus</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="subscriptionKey">Subscription Key</label>
                                        <input type="password" 
                                               id="subscriptionKey" 
                                               name="subscription_key" 
                                               placeholder="Cheia de subscripție Cargus..." 
                                               required>
                                        <small class="form-text">Cheia primară din portalul Cargus</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="username">Username</label>
                                        <input type="text" 
                                               id="username" 
                                               name="username" 
                                               placeholder="Utilizator Cargus..." 
                                               required>
                                    </div>
                                    <div class="form-group">
                                        <label for="password">Password</label>
                                        <input type="password" 
                                               id="password" 
                                               name="password" 
                                               placeholder="Parola Cargus..." 
                                               required>
                                    </div>
                                    <div class="form-group">
                                        <label for="defaultServiceId">ID Serviciu Implicit</label>
                                        <select id="defaultServiceId" name="default_service_id">
                                            <option value="34">34 - Economic Standard</option>
                                            <option value="35">35 - Standard Plus</option>
                                            <option value="36">36 - Palet Standard</option>
                                            <option value="38">38 - PUDO Delivery</option>
                                            <option value="39">39 - Multipiece</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="tokenCacheDuration">Durată Cache Token (ore)</label>
                                        <input type="number" 
                                               id="tokenCacheDuration" 
                                               name="token_cache_duration" 
                                               value="23" 
                                               min="1" 
                                               max="24">
                                        <small class="form-text">Numărul de ore pentru cache-ul token-ului</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h3 class="section-title">
                                    <span class="material-symbols-outlined">settings</span>
                                    Setări Calcul Automat
                                </h3>
                                <div class="form-grid">
                                    <div class="form-group checkbox-group">
                                        <label class="checkbox-label">
                                            <input type="checkbox" 
                                                   id="autoCalculateWeight" 
                                                   name="auto_calculate_weight" 
                                                   checked>
                                            <span class="checkmark"></span>
                                            Calculează automat greutatea
                                        </label>
                                        <small class="form-text">Calculează greutatea pe baza tipurilor de produse</small>
                                    </div>
                                    <div class="form-group checkbox-group">
                                        <label class="checkbox-label">
                                            <input type="checkbox" 
                                                   id="autoCalculateParcels" 
                                                   name="auto_calculate_parcels" 
                                                   checked>
                                            <span class="checkmark"></span>
                                            Calculează automat numărul de colete
                                        </label>
                                        <small class="form-text">Optimizează ambalarea pe baza regulilor</small>
                                    </div>
                                    <div class="form-group checkbox-group">
                                        <label class="checkbox-label">
                                            <input type="checkbox" 
                                                   id="liquidSeparateParcels" 
                                                   name="liquid_separate_parcels" 
                                                   checked>
                                            <span class="checkmark"></span>
                                            Lichidele în colete separate
                                        </label>
                                        <small class="form-text">Ambalează lichidele în colete separate pentru siguranță</small>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Product Unit Modal -->
    <div id="addProductUnitModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Adaugă Configurare Unitate Produs</h2>
                <button class="modal-close" type="button">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form id="addProductUnitForm" class="modal-form">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="productSelect">Produs <span class="required">*</span></label>
                        <select id="productSelect" name="product_id" required>
                            <option value="">Selectează produs...</option>
                            <!-- Options will be loaded via JavaScript -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="unitTypeSelect">Tip Unitate <span class="required">*</span></label>
                        <select id="unitTypeSelect" name="unit_type_id" required>
                            <option value="">Selectează unitate...</option>
                            <option value="1">Litri</option>
                            <option value="2">Bucăți</option>
                            <option value="3">Cartușe</option>
                            <option value="4">Kilograme</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="weightPerUnit">Greutate per Unitate (kg) <span class="required">*</span></label>
                        <input type="number" 
                               id="weightPerUnit" 
                               name="weight_per_unit" 
                               step="0.001" 
                               min="0.001" 
                               placeholder="1.000" 
                               required>
                    </div>
                    <div class="form-group">
                        <label for="volumePerUnit">Volum per Unitate (L)</label>
                    <input type="number"
                               id="volumePerUnit"
                               name="volume_per_unit"
                               step="0.001"
                               min="0"
                               placeholder="0.000">
                    </div>
                    <div class="form-group">
                        <label for="barrelDimensionSelect">Dimensiune Standard Bidon</label>
                        <select id="barrelDimensionSelect">
                            <option value="">Selectează...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="dimensionsLength">Lungime (cm)</label>
                        <input type="number" id="dimensionsLength" name="dimensions_length"
                               step="0.1" min="0" placeholder="0.0">
                    </div>
                    <div class="form-group">
                        <label for="dimensionsWidth">Lățime (cm)</label>
                        <input type="number" id="dimensionsWidth" name="dimensions_width"
                               step="0.1" min="0" placeholder="0.0">
                    </div>
                    <div class="form-group">
                        <label for="dimensionsHeight">Înălțime (cm)</label>
                        <input type="number" id="dimensionsHeight" name="dimensions_height"
                               step="0.1" min="0" placeholder="0.0">
                    </div>
                    <div class="form-group">
                        <label for="maxStackHeight">Înălțime maximă stivă</label>
                        <input type="number" id="maxStackHeight" name="max_stack_height"
                               min="1" max="50" value="1">
                    </div>
                    <div class="form-group">
                        <label for="packagingCost">Cost ambalare</label>
                        <input type="number" id="packagingCost" name="packaging_cost"
                               step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="fragile" name="fragile">
                            <span class="checkmark"></span>
                            Produs fragil
                        </label>
                    </div>
                    <div class="form-group checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="hazardous" name="hazardous">
                            <span class="checkmark"></span>
                            Produs periculos
                        </label>
                    </div>
                    <div class="form-group checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="temperatureControlled" name="temperature_controlled">
                            <span class="checkmark"></span>
                            Controlat termic
                        </label>
                    </div>
                    <div class="form-group checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="active" name="active" checked>
                            <span class="checkmark"></span>
                            Activ
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-action="cancel">
                        <span class="material-symbols-outlined">close</span>
                        Anulează
                    </button>
                    <button type="submit" class="btn btn-success">
                        <span class="material-symbols-outlined">save</span>
                        Salvează
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Stock Settings Modal -->
    <div id="stockSettingsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Configurare Gestiune Stoc</h2>
                <button class="modal-close" type="button">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form id="stockSettingsForm" class="modal-form">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="stockProductSelect">Produs <span class="required">*</span></label>
                        <select id="stockProductSelect" name="product_id" required></select>
                    </div>
                    <div class="form-group">
                        <label>Furnizor Asignat</label>
                        <div id="assignedSupplier" class="form-info">-</div>
                    </div>
                    <div class="form-group">
                        <label>Stoc Curent</label>
                        <div id="currentStockInfo" class="form-info">0</div>
                    </div>
                    <div class="form-group">
                        <label for="minStockLevel">Stoc Minim</label>
                        <input type="number" id="minStockLevel" name="min_stock_level" min="0" value="0">
                    </div>
                    <div class="form-group">
                        <label for="minOrderQty">Cantitate Min. Comandă</label>
                        <input type="number" id="minOrderQty" name="min_order_quantity" min="1" value="1">
                    </div>
                    <div class="form-group checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="autoOrderEnabled" name="auto_order_enabled">
                            <span class="checkmark"></span>
                            Autocomandă Activă
                        </label>
                    </div>
                    <div class="form-group full-width warning" id="noSupplierWarning" style="display:none;">
                        <span class="material-symbols-outlined">warning</span>
                        Asignați un furnizor pentru a activa autocomanda.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-action="cancel">
                        <span class="material-symbols-outlined">close</span>
                        Anulează
                    </button>
                    <button type="submit" class="btn btn-success">
                        <span class="material-symbols-outlined">save</span>
                        Salvează
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay" style="display: none;">
        <div class="loading-content">
            <span class="material-symbols-outlined spinning">progress_activity</span>
            <span>Se încarcă...</span>
        </div>
    </div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>