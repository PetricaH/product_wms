<?php
// File: inventory.php - Updated with table layout and fixed modals
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

// Database connection
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    die("Database connection factory not configured correctly.");
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include models
require_once BASE_PATH . '/models/Inventory.php';
require_once BASE_PATH . '/models/Product.php';
require_once BASE_PATH . '/models/Location.php';
require_once BASE_PATH . '/models/LocationLevelSettings.php';
require_once BASE_PATH . '/models/User.php';
require_once BASE_PATH . '/models/BarcodeCaptureTask.php';
require_once BASE_PATH . '/models/Seller.php';

$inventoryModel = new Inventory($db);
$productModel = new Product($db);
$locationModel = new Location($db);
$userModel = new Users($db);
$barcodeTaskModel = new BarcodeCaptureTask($db);
$sellerModel = new Seller($db);

$transactionTypes = [
    'receive' => ['label' => 'Primire', 'color' => 'success', 'icon' => '📦'],
    'move' => ['label' => 'Mutare', 'color' => 'info', 'icon' => '🔄'],
    'pick' => ['label' => 'Ridicare', 'color' => 'warning', 'icon' => '📤'],
    'adjust' => ['label' => 'Ajustare', 'color' => 'secondary', 'icon' => '⚖️'],
    'qc_hold' => ['label' => 'Control Calitate', 'color' => 'danger', 'icon' => '🛑'],
    'qc_release' => ['label' => 'Eliberare CC', 'color' => 'success', 'icon' => '✅'],
    'expire' => ['label' => 'Expirare', 'color' => 'dark', 'icon' => '⏰'],
    'damage' => ['label' => 'Deteriorare', 'color' => 'danger', 'icon' => '💥'],
    'return' => ['label' => 'Retur', 'color' => 'info', 'icon' => '↩️'],
    'correction' => ['label' => 'Corecție', 'color' => 'warning', 'icon' => '🔧'],
    'relocation' => ['label' => 'Relocare', 'color' => 'primary', 'icon' => '🔁']
];

// Handle operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_stock':
            $stockData = [
                'product_id' => intval($_POST['product_id'] ?? 0),
                'location_id' => intval($_POST['location_id'] ?? 0),
                'quantity' => intval($_POST['quantity'] ?? 0),
                'batch_number' => trim($_POST['batch_number'] ?? ''),
                'lot_number' => trim($_POST['lot_number'] ?? ''),
                'expiry_date' => $_POST['expiry_date'] ?? null,
                'received_at' => $_POST['received_at'] ?? date('Y-m-d H:i:s'),
                'shelf_level' => $_POST['shelf_level'] ?? null,
                'subdivision_number' => isset($_POST['subdivision_number']) ? intval($_POST['subdivision_number']) : null
            ];
            $trackingMethod = $_POST['tracking_method'] ?? 'bulk';

            if (!empty($stockData['received_at'])) {
                if (strpos($stockData['received_at'], 'T') !== false) {
                    $dateTime = DateTime::createFromFormat('Y-m-d\TH:i', $stockData['received_at']);
                    if ($dateTime) {
                        $stockData['received_at'] = $dateTime->format('Y-m-d H:i:s');
                    }
                }
            }
            
            if ($stockData['product_id'] <= 0 || $stockData['location_id'] <= 0 || $stockData['quantity'] <= 0) {
                $message = 'Produsul, locația și cantitatea sunt obligatorii.';
                $messageType = 'error';
            } else {
                if ($trackingMethod === 'individual') {
                    $taskId = $barcodeTaskModel->createTask(
                        $stockData['product_id'],
                        $stockData['location_id'],
                        $stockData['quantity'],
                        $_SESSION['user_id'] ?? 0
                    );
                    if ($taskId) {
                        $message = '✅ Barcode capture task created. Warehouse team needs to scan ' . $stockData['quantity'] . ' individual units.';
                        $messageType = 'success';
                    } else {
                        $message = 'Eroare la crearea taskului de scanare.';
                        $messageType = 'error';
                    }
                } else {
                    if ($inventoryModel->addStock($stockData)) {
                        $message = 'Stocul a fost adăugat cu succes.';
                        $messageType = 'success';
                    } else {
                        $message = 'Eroare la adăugarea stocului.';
                        $messageType = 'error';
                    }
                }
            }
            break;
            
        case 'remove_stock':
            $productId = intval($_POST['product_id'] ?? 0);
            $quantity = intval($_POST['quantity'] ?? 0);
            $locationId = !empty($_POST['location_id']) ? intval($_POST['location_id']) : null;
            
            if ($productId <= 0 || $quantity <= 0) {
                $message = 'Produsul și cantitatea sunt obligatorii.';
                $messageType = 'error';
            } else {
                if ($inventoryModel->removeStock($productId, $quantity, $locationId)) {
                    $message = 'Stocul a fost redus cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la reducerea stocului sau stoc insuficient.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'move_stock':
            $productId      = intval($_POST['product_id'] ?? 0);
            $fromLocationId = intval($_POST['from_location_id'] ?? 0);
            $newLocationId  = intval($_POST['new_location_id'] ?? 0);
            $moveQuantity   = intval($_POST['move_quantity'] ?? 0);
            $inventoryId    = intval($_POST['inventory_id'] ?? 0) ?: null;

            if ($productId <= 0 || $fromLocationId <= 0 || $newLocationId <= 0 || $moveQuantity <= 0) {
                $message = 'Toate câmpurile sunt obligatorii pentru mutarea stocului.';
                $messageType = 'error';
            } else {
                if ($inventoryModel->moveStock($productId, $fromLocationId, $newLocationId, $moveQuantity, $inventoryId)) {
                    $message = 'Stocul a fost mutat cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la mutarea stocului.';
                    $messageType = 'error';
                }
            }
            break;
    }
}

// Get view mode and filters
$view = $_GET['view'] ?? 'detailed';
$productFilter = $_GET['product'] ?? '';
$locationFilter = $_GET['location'] ?? '';
$lowStockOnly = isset($_GET['low_stock']);

$inventoryExportColumns = [
    'location_code' => ['label' => 'Locație', 'default' => true],
    'shelf_level' => ['label' => 'Raft', 'default' => true],
    'subdivision_number' => ['label' => 'Subdiviziune', 'default' => false],
    'product_name' => ['label' => 'Produs', 'default' => true],
    'sku' => ['label' => 'SKU', 'default' => true],
    'quantity' => ['label' => 'Cantitate', 'default' => true],
    'batch_number' => ['label' => 'Batch', 'default' => false],
    'lot_number' => ['label' => 'Lot', 'default' => false],
    'received_at' => ['label' => 'Data Primirii', 'default' => false],
    'expiry_date' => ['label' => 'Data Expirării', 'default' => false],
];

if (isset($_GET['export_inventory'])) {
    $requestedColumns = isset($_GET['columns']) && is_array($_GET['columns'])
        ? array_map('strval', $_GET['columns'])
        : [];

    $availableKeys = array_keys($inventoryExportColumns);
    $selectedColumns = array_values(array_intersect($requestedColumns, $availableKeys));

    if (empty($selectedColumns)) {
        $selectedColumns = array_keys(array_filter(
            $inventoryExportColumns,
            static fn($cfg) => !empty($cfg['default'])
        ));
    }

    $exportRows = $inventoryModel->getInventoryWithFilters($productFilter, $locationFilter, $lowStockOnly);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory_export_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    $headerRow = array_map(static function ($columnKey) use ($inventoryExportColumns) {
        return $inventoryExportColumns[$columnKey]['label'];
    }, $selectedColumns);
    fputcsv($out, $headerRow);

    foreach ($exportRows as $row) {
        $dataRow = [];
        foreach ($selectedColumns as $columnKey) {
            switch ($columnKey) {
                case 'location_code':
                    $dataRow[] = $row['location_code'] ?? '';
                    break;
                case 'shelf_level':
                    $level = $row['shelf_level'] ?? '';
                    $dataRow[] = $level !== null && $level !== '' ? ('Raft ' . $level) : '';
                    break;
                case 'subdivision_number':
                    $sub = $row['subdivision_number'] ?? '';
                    $dataRow[] = $sub !== null && $sub !== '' ? ('Subdiviziune ' . $sub) : '';
                    break;
                case 'product_name':
                    $dataRow[] = $row['product_name'] ?? ($row['name'] ?? '');
                    break;
                case 'sku':
                    $dataRow[] = $row['sku'] ?? '';
                    break;
                case 'quantity':
                    $dataRow[] = isset($row['quantity']) ? (string)$row['quantity'] : '';
                    break;
                case 'batch_number':
                    $dataRow[] = $row['batch_number'] ?? '';
                    break;
                case 'lot_number':
                    $dataRow[] = $row['lot_number'] ?? '';
                    break;
                case 'received_at':
                    $dataRow[] = !empty($row['received_at']) ? date('Y-m-d', strtotime($row['received_at'])) : '';
                    break;
                case 'expiry_date':
                    $dataRow[] = !empty($row['expiry_date']) ? date('Y-m-d', strtotime($row['expiry_date'])) : '';
                    break;
                default:
                    $dataRow[] = '';
            }
        }
        fputcsv($out, $dataRow);
    }

    fclose($out);
    exit;
}

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($page - 1) * $pageSize;
$receivingEntries = [];
$entriesBaseParams = [];
$entriesPageLink = static function(int $pageNumber): string {
    return '?view=entries&page=' . max($pageNumber, 1);
};
$entriesFilters = [];

// Get data based on view
switch ($view) {
    case 'entries':
        $pageSize = isset($_GET['page_size']) && in_array((int)$_GET['page_size'], [25, 50, 100], true)
            ? (int)$_GET['page_size']
            : 25;
        $offset = ($page - 1) * $pageSize;
        $entriesFilters = [
            'date_from' => $_GET['entries_date_from'] ?? date('Y-m-d', strtotime('-30 days')),
            'date_to' => $_GET['entries_date_to'] ?? date('Y-m-d'),
            'seller_id' => isset($_GET['entries_seller']) && $_GET['entries_seller'] !== '' ? (int)$_GET['entries_seller'] : '',
            'product_id' => isset($_GET['entries_product']) && $_GET['entries_product'] !== '' ? (int)$_GET['entries_product'] : '',
            'invoice_status' => $_GET['entries_invoice_status'] ?? 'all',
            'verification_status' => $_GET['entries_invoice_verification'] ?? 'all',
            'search' => trim($_GET['entries_search'] ?? ''),
        ];

        $entriesQueryFilters = [
            'date_from' => $entriesFilters['date_from'],
            'date_to' => $entriesFilters['date_to'],
            'seller_id' => $entriesFilters['seller_id'],
            'product_id' => $entriesFilters['product_id'],
            'invoice_status' => $entriesFilters['invoice_status'],
            'verification_status' => $entriesFilters['verification_status'],
            'search' => $entriesFilters['search'],
        ];

        $entriesData = $inventoryModel->getReceivingEntriesWithDetails($entriesQueryFilters, $page, $pageSize);
        $receivingEntries = $entriesData['data'];
        $totalCount = $entriesData['total'];

        $entriesBaseParams = [
            'view' => 'entries',
            'page_size' => $pageSize,
            'entries_date_from' => $entriesFilters['date_from'],
            'entries_date_to' => $entriesFilters['date_to'],
            'entries_seller' => $entriesFilters['seller_id'],
            'entries_product' => $entriesFilters['product_id'],
            'entries_invoice_status' => $entriesFilters['invoice_status'],
            'entries_invoice_verification' => $entriesFilters['verification_status'],
            'entries_search' => $entriesFilters['search'],
        ];

        $entriesPageLink = static function(int $pageNumber) use ($entriesBaseParams): string {
            $params = $entriesBaseParams;
            $params['page'] = max($pageNumber, 1);
            return '?' . http_build_query(array_filter($params, static fn($value) => $value !== '' && $value !== null));
        };
        break;
    case 'summary':
        $allInventory = $inventoryModel->getStockSummary();
        $totalCount = count($allInventory);
        $inventory = array_slice($allInventory, $offset, $pageSize);
        break;
    case 'detailed':
        $allInventory = $inventoryModel->getInventoryWithFilters($productFilter, $locationFilter, $lowStockOnly);
        $totalCount = count($allInventory);
        $inventory = array_slice($allInventory, $offset, $pageSize);
        break;
    case 'low-stock':
        $allInventory = $inventoryModel->getLowStockItems();
        $totalCount = count($allInventory);
        $inventory = array_slice($allInventory, $offset, $pageSize);
        break;
    case 'movements':
        $pageSize = isset($_GET['page_size']) && in_array((int)$_GET['page_size'], [25,50,100])
            ? (int)$_GET['page_size'] : 25;
        $offset = ($page - 1) * $pageSize;
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        $typeFilter = $_GET['transaction_type'] ?? 'all';
        $productSearch = $_GET['product_search'] ?? '';
        $movementLocation = $_GET['movement_location'] ?? '';
        $userFilter = $_GET['user_id'] ?? '';
        $sort = $_GET['sort'] ?? 'created_at';
        $direction = $_GET['direction'] ?? 'DESC';
        $filters = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'transaction_type' => $typeFilter,
            'product_search' => $productSearch,
            'location_id' => $movementLocation,
            'user_id' => $userFilter
        ];
        $movementData = $inventoryModel->getStockMovements($filters, $page, $pageSize, $sort, $direction);
        $movements = $movementData['data'];
        $totalCount = $movementData['total'];
        $movementSummary = $inventoryModel->getTodayMovementSummary();
        $allUsers = $userModel->getAllUsers();
        $baseParams = [
            'view' => 'movements',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'transaction_type' => $typeFilter,
            'product_search' => $productSearch,
            'movement_location' => $movementLocation,
            'user_id' => $userFilter,
            'page_size' => $pageSize,
            'sort' => $sort,
            'direction' => $direction
        ];
        $sortLink = function($column) use ($baseParams, $sort, $direction) {
            $params = $baseParams;
            $params['sort'] = $column;
            $params['direction'] = ($sort === $column && strtoupper($direction) === 'ASC') ? 'DESC' : 'ASC';
            $params['page'] = 1;
            return '?' . http_build_query($params);
        };
        $movementPageLink = function($p) use ($baseParams) {
            $params = $baseParams;
            $params['page'] = $p;
            return '?' . http_build_query($params);
        };
        $exportLink = '?' . http_build_query($baseParams);
        if (isset($_GET['export'])) {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="movements.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Data/Ora','Tip','Produs','SKU','Locație','Cantitate','Înainte','După','Operator','Motiv']);
            foreach ($movements as $mv) {
                $locationDisplay = $mv['location_code'] ?? '';
                if (($mv['transaction_type'] ?? '') === 'move') {
                    $locationDisplay = ($mv['source_location_code'] ?? '-') . '->' . ($mv['location_code'] ?? '-');
                } elseif (($mv['transaction_type'] ?? '') === 'relocation') {
                    $direction = $mv['movement_direction'] ?? '';
                    if ($direction === 'out') {
                        $locationDisplay = ($mv['location_code'] ?? '-') . '->' . ($mv['target_location_code'] ?? '-');
                    } else {
                        $locationDisplay = ($mv['source_location_code'] ?? '-') . '->' . (($mv['location_code'] ?? '') ?: ($mv['target_location_code'] ?? '-'));
                    }
                }

                $quantityBefore = $mv['quantity_before'] ?? null;
                $quantityAfter = $mv['quantity_after'] ?? null;

                fputcsv($out, [
                    $mv['created_at'],
                    $mv['transaction_type'],
                    $mv['product_name'],
                    $mv['sku'],
                    $locationDisplay,
                    $mv['quantity_change'],
                    $quantityBefore !== null ? $quantityBefore : '-',
                    $quantityAfter !== null ? $quantityAfter : '-',
                    $mv['full_name'] ?: $mv['username'],
                    $mv['reason']
                ]);
            }
            fclose($out);
            exit;
        }
        break;
    default:
        $allInventory = $inventoryModel->getInventoryWithFilters($productFilter, $locationFilter, $lowStockOnly);
        $totalCount = count($allInventory);
        $inventory = array_slice($allInventory, $offset, $pageSize);
}

$totalPages = ceil($totalCount / $pageSize);

$allProducts = $productModel->getAllProductsForDropdown();
$productSearchOptions = array_map(static function ($product) {
    return [
        'id' => (string)($product['product_id'] ?? ''),
        'name' => $product['name'] ?? '',
        'sku' => $product['sku'] ?? ''
    ];
}, $allProducts);
$selectedProductName = '';
if (!empty($productFilter)) {
    foreach ($productSearchOptions as $option) {
        if ($option['id'] === (string)$productFilter) {
            $selectedProductName = $option['name'];
            break;
        }
    }
}
$allLocations = $locationModel->getAllLocations();
$lowStockItems = $inventoryModel->getLowStockItems();
$expiringProducts = $inventoryModel->getExpiringProducts();
$allSellersList = $sellerModel->getAllSellers();

// Define current page for footer
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <style>
        #exportInventoryModal .export-columns-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }

        #exportInventoryModal .export-column-option {
            margin: 0;
            padding: 8px 10px;
            border: 1px solid rgba(15, 23, 42, 0.16);
            border-radius: 10px;
            background: #f8fafc;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
            cursor: pointer;
            min-height: 48px;
        }

        #exportInventoryModal .export-column-option:hover {
            border-color: rgba(13, 110, 253, 0.5);
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
        }

        #exportInventoryModal .export-column-option input[type="checkbox"] {
            margin: 0;
        }

        #exportInventoryModal .export-column-option .export-order-badge {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #0d6efd;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.85rem;
            visibility: hidden;
        }

        #exportInventoryModal .export-column-option.is-selected {
            border-color: #0d6efd;
            background: #eef4ff;
            box-shadow: 0 0 0 1px rgba(13, 110, 253, 0.25);
        }

        #exportInventoryModal .export-column-option.is-selected .export-order-badge {
            visibility: visible;
        }

        #exportInventoryModal .export-column-option .export-label-text {
            flex: 1;
            font-weight: 500;
            color: #1f2937;
        }
    </style>
    <title>Gestionare Inventar - WMS</title>
</head>
<body>
    <div class="app">
        <?php require_once __DIR__ . '/includes/navbar.php'; ?>
        
        <div class="main-content">
            <div class="page-container">
                <!-- Page Header -->
                <header class="page-header">
                    <div class="page-header-content">
                        <h1 class="page-title">
                            <span class="material-symbols-outlined">inventory_2</span>
                            Gestionare Inventar
                        </h1>
                        <button class="btn btn-primary" onclick="openAddStockModal()">
                            <span class="material-symbols-outlined">add_box</span>
                            Adaugă Stoc
                        </button>
                        <button class="btn btn-secondary" onclick="openImportStockModal()" style="margin-left:10px;">
                            <span class="material-symbols-outlined">upload_file</span>
                            Import Stoc
                        </button>
                        <button class="btn btn-outline-secondary" onclick="openExportModal()" style="margin-left:10px;">
                            <span class="material-symbols-outlined">download</span>
                            Exportă Inventar
                        </button>
                    </div>
                </header>
                
                <!-- Alert Messages -->
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>" role="alert">
                        <span class="material-symbols-outlined">
                            <?= $messageType === 'success' ? 'check_circle' : 'error' ?>
                        </span>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <!-- Low Stock & Expiry Alerts -->
                <?php if (!empty($lowStockItems) && $view !== 'low-stock'): ?>
                    <div class="low-stock-warning">
                        <span class="material-symbols-outlined">warning</span>
                        <strong>Atenție:</strong> Există <?= count($lowStockItems) ?> produse cu stoc scăzut.
                        <a href="?view=low-stock" class="alert-link">Vezi produsele</a>
                    </div>
                <?php endif; ?>

                <?php if (!empty($expiringProducts)): ?>
                    <div class="low-stock-warning">
                        <span class="material-symbols-outlined">schedule</span>
                        <strong>Atenție:</strong> Există <?= count($expiringProducts) ?> produse care expiră în 30 de zile.
                    </div>
                <?php endif; ?>
                <?php if ($view === 'movements'): ?>
                    <div class="summary-cards">
                        <?php $m = $movementSummary ?? ['movements'=>0,'products'=>0,'locations'=>0,'avg_duration'=>0]; ?>
                        <div class="card-metric">
                            <div class="metric-label">Mișcări Astăzi</div>
                            <div class="metric-value"><?= number_format($m['movements']) ?></div>
                        </div>
                        <div class="card-metric">
                            <div class="metric-label">Produse Afectate</div>
                            <div class="metric-value"><?= number_format($m['products']) ?></div>
                        </div>
                        <div class="card-metric">
                            <div class="metric-label">Locații Active</div>
                            <div class="metric-value"><?= number_format($m['locations']) ?></div>
                        </div>
                        <div class="card-metric">
                            <div class="metric-label">Timp Mediu Procesare</div>
                            <div class="metric-value"><?= $m['avg_duration'] ? gmdate('H:i:s', (int)$m['avg_duration']) : '-' ?></div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Control Inventar</h3>
                            <div class="card-actions">
                                <div class="view-toggle">
                                    <a href="?view=detailed" class="toggle-link <?= $view === 'detailed' ? 'active' : '' ?>">
                                        <span class="material-symbols-outlined">table_view</span>
                                        Detaliat
                                    </a>
                                    <a href="?view=summary" class="toggle-link <?= $view === 'summary' ? 'active' : '' ?>">
                                        <span class="material-symbols-outlined">dashboard</span>
                                        Sumar
                                    </a>
                                    <a href="?view=low-stock" class="toggle-link <?= $view === 'low-stock' ? 'active' : '' ?>">
                                        <span class="material-symbols-outlined">warning</span>
                                        Stoc Scăzut
                                    </a>
                                    <a href="?view=movements" class="toggle-link <?= $view === 'movements' ? 'active' : '' ?>">
                                        <span class="material-symbols-outlined">swap_horiz</span>
                                        Mișcări Stocuri
                                    </a>
                                    <a href="?view=entries" class="toggle-link <?= $view === 'entries' ? 'active' : '' ?>">
                                        <span class="material-symbols-outlined">receipt_long</span>
                                        Intrări Stoc
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="movements-layout">
                        <div class="card filter-card">
                            <div class="card-body">
                                <form method="GET" id="movements-filter-form" class="filter-form">
                                    <input type="hidden" name="view" value="movements">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">De la</label>
                                            <input type="date" name="date_from" id="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Până la</label>
                                            <input type="date" name="date_to" id="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Tip Mișcare</label>
                                            <select name="transaction_type" class="form-control">
                                                <option value="all">Toate tipurile</option>
                                                <?php foreach ($transactionTypes as $key => $t): ?>
                                                    <option value="<?= $key ?>" <?= $typeFilter === $key ? 'selected' : '' ?>><?= $t['label'] ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Produs</label>
                                            <input type="text" name="product_search" class="form-control" placeholder="Căutare produs..." value="<?= htmlspecialchars($productSearch) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Locație</label>
                                            <select name="movement_location" class="form-control">
                                                <option value="">Toate locațiile</option>
                                                <?php foreach ($allLocations as $location): ?>
                                                    <option value="<?= $location['id'] ?>" <?= $movementLocation == $location['id'] ? 'selected' : '' ?>><?= htmlspecialchars($location['location_code']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Operator</label>
                                            <select name="user_id" class="form-control">
                                                <option value="">Toți operatorii</option>
                                                <?php foreach ($allUsers as $user): ?>
                                                    <option value="<?= $user['id'] ?>" <?= $userFilter == $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($user['username']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group quick-filters">
                                            <label class="form-label">Perioadă rapidă</label>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-secondary" onclick="setDateRange('today')">Astăzi</button>
                                                <button type="button" class="btn btn-secondary" onclick="setDateRange('week')">Săptămâna aceasta</button>
                                                <button type="button" class="btn btn-secondary" onclick="setDateRange('month')">Luna aceasta</button>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Înregistrări</label>
                                            <select name="page_size" class="form-control">
                                                <?php foreach ([25,50,100] as $ps): ?>
                                                    <option value="<?= $ps ?>" <?= $pageSize == $ps ? 'selected' : '' ?>><?= $ps ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-secondary">
                                        <span class="material-symbols-outlined">filter_alt</span>
                                        Filtrează
                                    </button>
                                    <a href="?view=movements" class="btn btn-secondary">
                                        <span class="material-symbols-outlined">refresh</span>
                                        Reset
                                    </a>
                                    <a href="<?= $exportLink ?>&export=1" class="btn btn-secondary">Exportă în CSV</a>
                                </form>
                            </div>
                        </div>

                        <div class="card table-card">
                            <div class="card-body">
                                <?php if (!empty($movements)): ?>
                                    <div class="table-container">
                                        <table class="table" id="stock-movements-table">
                                        <thead>
                                            <tr>
                                                <th><a href="<?= $sortLink('created_at') ?>">Data/Ora</a></th>
                                                <th>Tip Mișcare</th>
                                                <th>Produs</th>
                                                <th>Locație</th>
                                                <th>Cantitate</th>
                                                <th>Stoc Înainte/După</th>
                                                <th>Operator</th>
                                                <th>Motiv</th>
                                                <th>Durată</th>
                                                <th>Acțiuni</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($movements as $mv): ?>
                                                <tr>
                                                    <td><?= date('d.m.Y H:i', strtotime($mv['created_at'])) ?></td>
                                                    <td><span class="badge status-badge bg-<?= $transactionTypes[$mv['transaction_type']]['color'] ?? 'secondary' ?>"><?= $transactionTypes[$mv['transaction_type']]['label'] ?? $mv['transaction_type'] ?></span></td>
                                                    <td><?= htmlspecialchars($mv['product_name']) ?> <small class="text-muted"><?= htmlspecialchars($mv['sku']) ?></small></td>
                                                    <td>
                                                        <?php if (($mv['transaction_type'] ?? '') === 'move'): ?>
                                                            <?= htmlspecialchars($mv['source_location_code'] ?? '-') ?> → <?= htmlspecialchars($mv['location_code'] ?? '-') ?>
                                                        <?php elseif (($mv['transaction_type'] ?? '') === 'relocation'): ?>
                                                            <?php
                                                                $direction = $mv['movement_direction'] ?? '';
                                                                $badgeClass = $direction === 'out' ? 'bg-danger' : 'bg-success';
                                                                $badgeLabel = strtoupper($direction ?: '');
                                                                if ($direction === 'out') {
                                                                    $fromLocation = $mv['location_code'] ?? '-';
                                                                    $toLocation = $mv['target_location_code'] ?? '-';
                                                                } else {
                                                                    $fromLocation = $mv['source_location_code'] ?? '-';
                                                                    $toLocation = ($mv['location_code'] ?? '') ?: ($mv['target_location_code'] ?? '-');
                                                                }
                                                            ?>
                                                            <?php if ($badgeLabel): ?>
                                                                <span class="badge status-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                                                            <?php endif; ?>
                                                            <?= htmlspecialchars($fromLocation) ?> → <?= htmlspecialchars($toLocation) ?>
                                                        <?php else: ?>
                                                            <?= htmlspecialchars(($mv['location_code'] ?? '') ?: '-') ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><span class="<?= $mv['quantity_change'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($mv['quantity_change'] >= 0 ? '+' : '') . $mv['quantity_change'] ?></span></td>
                                                    <td>
                                                        <?php if ($mv['quantity_before'] !== null && $mv['quantity_after'] !== null): ?>
                                                            <?= $mv['quantity_before'] ?> → <?= $mv['quantity_after'] ?>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($mv['full_name'] ?: $mv['username'] ?: '-') ?></td>
                                                    <td><?= htmlspecialchars($mv['reason'] ?? '-') ?></td>
                                                    <td><?= $mv['duration_seconds'] ? gmdate('H:i:s', $mv['duration_seconds']) : '-' ?></td>
                                                    <td><button class="btn btn-outline-secondary view-transaction" data-details='<?= htmlspecialchars(json_encode($mv), ENT_QUOTES) ?>'>Detalii</button></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if ($totalPages > 1): ?>
                                    <div class="pagination-container">
                                        <div class="pagination-info">
                                            Afișare <?= ($offset + 1) ?>-<?= min($offset + $pageSize, $totalCount) ?> din <?= number_format($totalCount) ?> elemente
                                        </div>
                                        <div class="pagination-controls">
                                            <?php if ($page > 1): ?>
                                                <a href="<?= $movementPageLink($page-1) ?>" class="pagination-btn">
                                                    <span class="material-symbols-outlined">chevron_left</span>
                                                    Anterior
                                                </a>
                                            <?php endif; ?>
                                            <span class="pagination-current">Pagina <?= $page ?> din <?= $totalPages ?></span>
                                            <?php if ($page < $totalPages): ?>
                                                <a href="<?= $movementPageLink($page+1) ?>" class="pagination-btn">
                                                    Următor
                                                    <span class="material-symbols-outlined">chevron_right</span>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php else: ?>
                                    <p>Nu există mișcări de stoc</p>
                                <?php endif; ?>
                    </div>
                </div>
            </div>
                <?php elseif ($view === 'entries'): ?>
                <div class="card card--searchable receiving-entries-card">
                    <div class="card-header">
                        <h3 class="card-title">Intrări Stoc</h3>
                        <div class="card-actions">
                            <div class="view-toggle">
                                <a href="?view=detailed" class="toggle-link <?= $view === 'detailed' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">table_view</span>
                                    Detaliat
                                </a>
                                <a href="?view=summary" class="toggle-link <?= $view === 'summary' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">dashboard</span>
                                    Sumar
                                </a>
                                <a href="?view=low-stock" class="toggle-link <?= $view === 'low-stock' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">warning</span>
                                    Stoc Scăzut
                                </a>
                                <a href="?view=movements" class="toggle-link <?= $view === 'movements' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">swap_horiz</span>
                                    Mișcări Stocuri
                                </a>
                                <a href="?view=entries" class="toggle-link <?= $view === 'entries' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">receipt_long</span>
                                    Intrări Stoc
                                </a>
                            </div>
                            <button type="button" class="receiving-photo-modal__nav receiving-photo-modal__nav--next" data-modal-nav="next" aria-label="Imagine următoare">
                                <span class="material-symbols-outlined">chevron_right</span>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="filter-form receiving-entries-filters">
                            <input type="hidden" name="view" value="entries">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="entries-date-from">De la</label>
                                    <input type="date" id="entries-date-from" name="entries_date_from" class="form-control"
                                           value="<?= htmlspecialchars($entriesFilters['date_from'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="entries-date-to">Până la</label>
                                    <input type="date" id="entries-date-to" name="entries_date_to" class="form-control"
                                           value="<?= htmlspecialchars($entriesFilters['date_to'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="entries-seller">Furnizor</label>
                                    <select id="entries-seller" name="entries_seller" class="form-control">
                                        <option value="">Toți furnizorii</option>
                                        <?php foreach ($allSellersList as $seller): ?>
                                            <option value="<?= $seller['id'] ?>" <?= (string)$entriesFilters['seller_id'] === (string)$seller['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($seller['supplier_name'] ?? $seller['name'] ?? '') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="entries-product">Produs</label>
                                    <select id="entries-product" name="entries_product" class="form-control">
                                        <option value="">Toate produsele</option>
                                        <?php foreach ($allProducts as $product): ?>
                                            <option value="<?= $product['product_id'] ?>" <?= (string)$entriesFilters['product_id'] === (string)$product['product_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($product['name'] ?? '') ?>
                                                <?php if (!empty($product['sku'])): ?>
                                                    (<?= htmlspecialchars($product['sku']) ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="entries-invoice-status">Status Factură</label>
                                    <select id="entries-invoice-status" name="entries_invoice_status" class="form-control">
                                        <option value="all" <?= ($entriesFilters['invoice_status'] ?? 'all') === 'all' ? 'selected' : '' ?>>Toate</option>
                                        <option value="with" <?= ($entriesFilters['invoice_status'] ?? 'all') === 'with' ? 'selected' : '' ?>>Cu factură</option>
                                        <option value="without" <?= ($entriesFilters['invoice_status'] ?? 'all') === 'without' ? 'selected' : '' ?>>Fără factură</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="entries-invoice-verification">Verificare</label>
                                    <select id="entries-invoice-verification" name="entries_invoice_verification" class="form-control">
                                        <option value="all" <?= ($entriesFilters['verification_status'] ?? 'all') === 'all' ? 'selected' : '' ?>>Toate</option>
                                        <option value="verified" <?= ($entriesFilters['verification_status'] ?? 'all') === 'verified' ? 'selected' : '' ?>>Verificate</option>
                                        <option value="unverified" <?= ($entriesFilters['verification_status'] ?? 'all') === 'unverified' ? 'selected' : '' ?>>Neverificate</option>
                                    </select>
                                </div>
                                <div class="form-group stretch">
                                    <label class="form-label" for="entries-search">Căutare</label>
                                    <input type="text" id="entries-search" name="entries_search" class="form-control"
                                           placeholder="Caută în furnizori, produse, sesiuni, facturi" value="<?= htmlspecialchars($entriesFilters['search'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="entries-page-size">Înregistrări</label>
                                    <select id="entries-page-size" name="page_size" class="form-control">
                                        <?php foreach ([25, 50, 100] as $ps): ?>
                                            <option value="<?= $ps ?>" <?= (int)$pageSize === (int)$ps ? 'selected' : '' ?>><?= $ps ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-secondary">
                                    <span class="material-symbols-outlined">filter_alt</span>
                                    Filtrează
                                </button>
                                <a href="?view=entries" class="btn btn-outline-secondary">
                                    <span class="material-symbols-outlined">refresh</span>
                                    Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div id="entries-notification" class="entries-notification" role="status" aria-live="polite"></div>

                <div class="card table-card">
                    <div class="card-body">
                        <?php if (!empty($receivingEntries)): ?>
                            <div class="table-container">
                                <table class="inventory-table receiving-entries-table">
                                    <thead>
                                        <tr>
                                            <th>Data</th>
                                            <th>Furnizor</th>
                                            <th>Produs</th>
                                            <th>Cantitate</th>
                                            <th>SKU</th>
                                            <th>Factură</th>
                                            <th>Verificată</th>
                                            <th>Observații</th>
                                            <th>Poze</th>
                                            <th>Acțiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($receivingEntries as $entry): ?>
                                            <?php
                                                $photoData = $entry['photos'] ?? [];
                                                $photoCount = count($photoData);
                                                $photosJson = htmlspecialchars(json_encode($photoData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                                $invoicePath = $entry['invoice_file_path'] ?? '';
                                                $invoiceUrl = '';
                                                if (!empty($invoicePath)) {
                                                    $normalized = ltrim($invoicePath, '/');
                                                    if (strpos($normalized, 'storage/') !== 0) {
                                                        $normalized = 'storage/' . $normalized;
                                                    }
                                                    $invoiceUrl = (defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/' : '') . $normalized;
                                                }
                                                $quantityFormatted = number_format((float)$entry['received_quantity'], 3, '.', '');
                                                $quantityFormatted = rtrim(rtrim($quantityFormatted, '0'), '.');
                                                if ($quantityFormatted === '') {
                                                    $quantityFormatted = '0';
                                                }
                                                $unitLabel = $entry['unit_of_measure'] ?? 'buc';
                                                $verified = !empty($entry['invoice_verified']);
                                                $workerNoteSegments = [];
                                                $descriptionText = trim((string)($entry['description_text'] ?? ''));
                                                if ($descriptionText !== '') {
                                                    $workerNoteSegments[] = $descriptionText;
                                                }
                                                $itemNotes = trim((string)($entry['item_notes'] ?? ''));
                                                if ($itemNotes !== '') {
                                                    $workerNoteSegments[] = $itemNotes;
                                                }
                                                $workerNotes = implode("\n\n", $workerNoteSegments);
                                                $adminNotes = trim((string)($entry['admin_notes'] ?? ''));
                                                $adminNoteAuthor = trim((string)($entry['admin_notes_updated_by_name'] ?? ''));
                                                $adminNoteTimestamp = '';
                                                if (!empty($entry['admin_notes_updated_at']) && $entry['admin_notes_updated_at'] !== '0000-00-00 00:00:00') {
                                                    $adminNoteTimestamp = date('d.m.Y H:i', strtotime($entry['admin_notes_updated_at']));
                                                }
                                            ?>
                                            <tr data-receiving-item="<?= $entry['receiving_item_id'] ?>">
                                                <td>
                                                    <div class="table-date">
                                                        <strong><?= $entry['received_at'] ? date('d.m.Y', strtotime($entry['received_at'])) : '-' ?></strong>
                                                        <small><?= $entry['received_at'] ? date('H:i', strtotime($entry['received_at'])) : '' ?></small>
                                                    </div>
                                                    <div class="text-muted small">Sesiune <?= htmlspecialchars($entry['session_number'] ?? '-') ?></div>
                                                    <?php if (!empty($entry['purchase_order_id']) && !empty($entry['order_number'])): ?>
                                                        <div class="text-muted small">
                                                            <a class="po-link" href="<?= htmlspecialchars(getNavUrl('purchase_orders.php')) ?>?focus_order=<?= (int)$entry['purchase_order_id'] ?>#po-<?= (int)$entry['purchase_order_id'] ?>">
                                                                Comandă <?= htmlspecialchars($entry['order_number']) ?>
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="supplier-name"><?= htmlspecialchars($entry['supplier_name'] ?? '-') ?></div>
                                                </td>
                                                <td>
                                                    <div class="product-name"><?= htmlspecialchars($entry['product_name'] ?? '-') ?></div>
                                                </td>
                                                <td>
                                                    <strong><?= $quantityFormatted ?></strong> <span class="text-muted"><?= htmlspecialchars($unitLabel) ?></span>
                                                </td>
                                                <td><code class="sku-code"><?= htmlspecialchars($entry['sku'] ?? '-') ?></code></td>
                                                <td class="receiving-entry-cell receiving-entry-cell--invoice">
                                                    <?php if (!empty($invoiceUrl)): ?>
                                                        <a href="<?= htmlspecialchars($invoiceUrl) ?>" target="_blank" class="btn btn-outline-primary btn-sm invoice-view-btn">
                                                            <span class="material-symbols-outlined">visibility</span>
                                                            Vezi Factură
                                                        </a>
                                                    <?php elseif (!empty($entry['purchase_order_id'])): ?>
                                                        <button type="button" class="btn btn-sm btn-success entry-upload-invoice-btn" data-order-id="<?= $entry['purchase_order_id'] ?>">
                                                            <span class="material-symbols-outlined">upload</span>
                                                            Încarcă Factură
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted">Fără comandă</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="receiving-entry-cell receiving-entry-cell--verification">
                                                    <?php if (!empty($entry['purchase_order_id'])): ?>
                                                        <label class="invoice-verified-toggle-wrapper <?= $verified ? 'is-verified' : '' ?> <?= empty($invoiceUrl) ? 'is-disabled' : '' ?>">
                                                            <input type="checkbox" class="invoice-verified-toggle" data-order-id="<?= $entry['purchase_order_id'] ?>"
                                                                   data-invoice-present="<?= !empty($invoiceUrl) ? '1' : '0' ?>"
                                                                   aria-label="Factura verificată"
                                                                   <?= (empty($invoiceUrl) ? 'disabled' : '') ?> <?= $verified ? 'checked' : '' ?>>
                                                            <span class="invoice-verified-check" aria-hidden="true">task_alt</span>
                                                        </label>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                    <?php if ($verified && !empty($entry['invoice_verified_at'])): ?>
                                                        <div class="invoice-verified-meta">
                                                            <span class="material-symbols-outlined">task_alt</span>
                                                            <span><?= date('d.m.Y H:i', strtotime($entry['invoice_verified_at'])) ?></span>
                                                            <?php if (!empty($entry['invoice_verified_by_name'])): ?>
                                                                <span>de <?= htmlspecialchars($entry['invoice_verified_by_name']) ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="receiving-entry-cell receiving-entry-cell--notes">
                                                    <?php if ($workerNotes !== ''): ?>
                                                        <div class="entry-note-block entry-note-block--worker">
                                                            <div class="entry-note-meta">
                                                                <span class="entry-note-role">Magazie</span>
                                                                <?php if (!empty($entry['received_by_username'])): ?>
                                                                    <span class="entry-note-author"><?= htmlspecialchars($entry['received_by_username']) ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="entry-note-content">
                                                                <?= nl2br(htmlspecialchars($workerNotes)) ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="entry-note-block entry-note-block--admin" data-item-id="<?= (int)$entry['receiving_item_id'] ?>">
                                                        <div class="entry-note-meta entry-note-meta--admin" data-item-id="<?= (int)$entry['receiving_item_id'] ?>">
                                                            <span class="entry-note-role">Admin</span>
                                                            <span class="entry-note-author admin-note-author<?= $adminNoteAuthor === '' ? ' is-empty' : '' ?>" data-item-id="<?= (int)$entry['receiving_item_id'] ?>"><?= $adminNoteAuthor !== '' ? htmlspecialchars($adminNoteAuthor) : '' ?></span>
                                                            <span class="entry-note-timestamp admin-note-timestamp<?= $adminNoteTimestamp === '' ? ' is-empty' : '' ?>" data-item-id="<?= (int)$entry['receiving_item_id'] ?>"><?= $adminNoteTimestamp !== '' ? htmlspecialchars($adminNoteTimestamp) : '' ?></span>
                                                        </div>
                                                        <div class="entry-notes-editor"
                                                             contenteditable="true"
                                                             data-item-id="<?= (int)$entry['receiving_item_id'] ?>"
                                                             data-original-value="<?= htmlspecialchars($adminNotes, ENT_QUOTES, 'UTF-8') ?>"
                                                             data-placeholder="Adaugă observații admin"><?php echo htmlspecialchars($adminNotes); ?></div>
                                                        <div class="entry-notes-status" data-item-id="<?= (int)$entry['receiving_item_id'] ?>" aria-live="polite"></div>
                                                    </div>
                                                </td>
                                                <td class="receiving-entry-cell receiving-entry-cell--photos">
                                                    <?php if ($photoCount > 0): ?>
                                                        <div class="receiving-entry-photos">
                                                            <?php foreach (array_slice($photoData, 0, 3) as $index => $photo): ?>
                                                                <button type="button" class="entry-photo-thumb" data-photos='<?= $photosJson ?>' data-index="<?= $index ?>" title="Deschide galeria">
                                                                    <img src="<?= htmlspecialchars($photo['thumbnail_url'] ?? $photo['url']) ?>" alt="Poză recepție">
                                                                    <?php if ($index === 0 && $photoCount > 1): ?>
                                                                        <span class="photo-count-badge">+<?= $photoCount - 1 ?></span>
                                                                    <?php endif; ?>
                                                                </button>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="receiving-entry-cell receiving-entry-cell--actions">
                                                    <div class="entries-actions">
                                                        <a class="btn btn-outline-secondary btn-sm" href="receiving_history.php?session_id=<?= $entry['receiving_session_id'] ?>" target="_blank">
                                                            <span class="material-symbols-outlined">visibility</span>
                                                            Detalii
                                                        </a>
                                                        <?php if (!empty($invoiceUrl)): ?>
                                                            <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($invoiceUrl) ?>" target="_blank" download>
                                                                <span class="material-symbols-outlined">download</span>
                                                                Descarcă FC
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if (empty($invoiceUrl) && !empty($entry['purchase_order_id'])): ?>
                                                            <button type="button" class="btn btn-outline-success btn-sm entry-upload-invoice-btn" data-order-id="<?= $entry['purchase_order_id'] ?>">
                                                                <span class="material-symbols-outlined">upload_file</span>
                                                                Factură
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($totalPages > 1): ?>
                                <div class="pagination-container">
                                    <div class="pagination-info">
                                        Afișare <?= ($offset + 1) ?>-<?= min($offset + $pageSize, $totalCount) ?> din <?= number_format($totalCount) ?> intrări
                                    </div>
                                    <div class="pagination-controls">
                                        <?php if ($page > 1): ?>
                                            <a href="<?= $entriesPageLink($page - 1) ?>" class="pagination-btn">
                                                <span class="material-symbols-outlined">chevron_left</span>
                                                Anterior
                                            </a>
                                        <?php endif; ?>
                                        <span class="pagination-current">Pagina <?= $page ?> din <?= $totalPages ?></span>
                                        <?php if ($page < $totalPages): ?>
                                            <a href="<?= $entriesPageLink($page + 1) ?>" class="pagination-btn">
                                                Următor
                                                <span class="material-symbols-outlined">chevron_right</span>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p>Nu există intrări de stoc pentru criteriile selectate.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <input type="file" id="entries-invoice-upload" accept=".pdf,.jpg,.jpeg,.png" style="display:none;">

                <div id="receiving-photo-modal" class="receiving-photo-modal" aria-hidden="true">
                    <div class="receiving-photo-modal__backdrop" data-modal-dismiss></div>
                    <div class="receiving-photo-modal__dialog" role="dialog" aria-modal="true">
                        <button type="button" class="receiving-photo-modal__close" data-modal-dismiss aria-label="Închide">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                        <div class="receiving-photo-modal__image-wrapper">
                            <img id="receiving-photo-modal-image" src="" alt="Imagine recepție">
                        </div>
                        <div class="receiving-photo-modal__footer">
                            <button type="button" class="receiving-photo-modal__nav receiving-photo-modal__nav--prev" data-modal-nav="prev" aria-label="Imagine anterioară">
                                <span class="material-symbols-outlined">chevron_left</span>
                            </button>
                            <div class="receiving-photo-modal__caption">
                                <span id="receiving-photo-modal-filename"></span>
                                <span id="receiving-photo-modal-counter"></span>
                            </div>
                            <button type="button" class="receiving-photo-modal__nav receiving-photo-modal__nav--next" data-modal-nav="next" aria-label="Imagine următoare">
                                <span class="material-symbols-outlined">chevron_right</span>
                            </button>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- View Controls -->
                <div class="card card--searchable">
                    <div class="card-header">
                        <h3 class="card-title">Control Inventar</h3>
                        <div class="card-actions">
                            <!-- View Toggle -->
                            <div class="view-toggle">
                                <a href="?view=detailed<?= $productFilter ? '&product=' . $productFilter : '' ?><?= $locationFilter ? '&location=' . $locationFilter : '' ?><?= $lowStockOnly ? '&low_stock=1' : '' ?>" 
                                   class="toggle-link <?= $view === 'detailed' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">table_view</span>
                                    Detaliat
                                </a>
                                <a href="?view=summary<?= $productFilter ? '&product=' . $productFilter : '' ?><?= $locationFilter ? '&location=' . $locationFilter : '' ?><?= $lowStockOnly ? '&low_stock=1' : '' ?>" 
                                   class="toggle-link <?= $view === 'summary' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">dashboard</span>
                                    Sumar
                                </a>
                                <a href="?view=low-stock"
                                   class="toggle-link <?= $view === 'low-stock' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">warning</span>
                                    Stoc Scăzut
                                </a>
                                <a href="?view=movements"
                                   class="toggle-link <?= $view === 'movements' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">swap_horiz</span>
                                    Mișcări Stocuri
                                </a>
                                <a href="?view=entries"
                                   class="toggle-link <?= $view === 'entries' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">receipt_long</span>
                                    Intrări Stoc
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($view === 'detailed'): ?>
                    <div class="card-body">
                        <!-- Filters -->
                        <form method="GET" class="filter-form">
                            <input type="hidden" name="view" value="detailed">
                            
                            <div class="form-group">
                                <label class="form-label" for="product-search-input">Produs</label>
                                <div class="product-search-container" data-product-search='<?= htmlspecialchars(json_encode($productSearchOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>'>
                                    <input
                                        type="text"
                                        id="product-search-input"
                                        class="form-control product-search-input"
                                        placeholder="Caută produs după nume sau SKU"
                                        autocomplete="off"
                                        value="<?= htmlspecialchars($selectedProductName) ?>"
                                        data-selected-label="<?= htmlspecialchars(trim($selectedProductName)) ?>"
                                        data-selected-id="<?= htmlspecialchars($productFilter) ?>"
                                    >
                                    <input type="hidden" name="product" id="product-search-id" value="<?= htmlspecialchars($productFilter) ?>">
                                    <div id="product-search-results" class="product-search-results"></div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Locație</label>
                                <select name="location" class="form-control">
                                    <option value="">Toate locațiile</option>
                                    <?php foreach ($allLocations as $location): ?>
                                        <option value="<?= $location['id'] ?>" <?= $locationFilter == $location['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($location['location_code']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="low_stock" value="1" <?= $lowStockOnly ? 'checked' : '' ?>>
                                    Doar stoc scăzut
                                </label>
                            </div>
                            
                            <button type="submit" class="btn btn-secondary">
                                <span class="material-symbols-outlined">filter_alt</span>
                                Filtrează
                            </button>
                            
                            <a href="?view=detailed" class="btn btn-secondary">
                                <span class="material-symbols-outlined">refresh</span>
                                Reset
                            </a>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Inventory Table -->
                <div class="card">
                    <div class="card-body">
                        <?php if (!empty($inventory)): ?>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <?php if ($view === 'summary'): ?>
                                                <th>SKU</th>
                                                <th>Nume Produs</th>
                                                <th>Categorie</th>
                                                <th>Stoc Total</th>
                                                <th>Locații</th>
                                                <th>Status</th>
                                                <th>Acțiuni</th>
                                            <?php elseif ($view === 'low-stock'): ?>
                                                <th>SKU</th>
                                                <th>Nume Produs</th>
                                                <th>Stoc Curent</th>
                                                <th>Stoc Minim</th>
                                                <th>Diferență</th>
                                                <th>Locații</th>
                                                <th>Acțiuni</th>
                                            <?php else: ?>
                                                <th>SKU</th>
                                                <th>Produs</th>
                                                <th>Locație</th>
                                                <th>Cantitate</th>
                                                <th>Batch/Lot</th>
                                                <th>Primire</th>
                                                <th>Expirare</th>
                                                <th>Acțiuni</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($inventory as $item): ?>
                                            <tr>
                                                <?php if ($view === 'summary'): ?>
                                                    <td>
                                                        <code class="sku-code"><?= htmlspecialchars($item['sku']) ?></code>
                                                    </td>
                                                    <td>
                                                        <div class="product-info">
                                                            <strong><?= htmlspecialchars($item['name']) ?></strong>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="location-badge"><?= htmlspecialchars($item['category'] ?? 'N/A') ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="stock-info <?= ($item['total_stock'] ?? 0) <= ($item['min_stock_level'] ?? 0) ? 'stock-low' : 'stock-good' ?>">
                                                            <?= number_format($item['total_stock'] ?? 0) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= $item['locations_count'] ?? 0 ?> locații</td>
                                                    <td>
                                                        <?php if (($item['total_stock'] ?? 0) <= ($item['min_stock_level'] ?? 0)): ?>
                                                            <span class="badge badge-danger">Stoc Scăzut</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-success">OK</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group">
                                                            <button class="btn btn-sm btn-outline-primary" onclick="addStockForProduct(<?= $item['product_id'] ?>)" title="Adaugă stoc">
                                                                <span class="material-symbols-outlined">add</span>
                                                            </button>
                                                            <a href="?view=detailed&product=<?= $item['product_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Vezi detalii">
                                                                <span class="material-symbols-outlined">visibility</span>
                                                            </a>
                                                        </div>
                                                    </td>
                                                <?php elseif ($view === 'low-stock'): ?>
                                                    <td>
                                                        <code class="sku-code"><?= htmlspecialchars($item['sku']) ?></code>
                                                    </td>
                                                    <td>
                                                        <div class="product-info">
                                                            <strong><?= htmlspecialchars($item['name']) ?></strong>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="stock-info stock-low"><?= number_format($item['current_stock'] ?? 0) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="stock-info"><?= number_format($item['min_stock_level'] ?? 0) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="stock-info stock-low">
                                                            <?= number_format(($item['min_stock_level'] ?? 0) - ($item['current_stock'] ?? 0)) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= $item['locations_count'] ?? 0 ?> locații</td>
                                                    <td>
                                                        <div class="btn-group">
                                                            <button class="btn btn-sm btn-outline-primary" onclick="addStockForProduct(<?= $item['product_id'] ?>)" title="Adaugă stoc">
                                                                <span class="material-symbols-outlined">add</span>
                                                            </button>
                                                        </div>
                                                    </td>
                                                <?php else: ?>
                                                    <td>
                                                        <code class="sku-code"><?= htmlspecialchars($item['sku']) ?></code>
                                                    </td>
                                                    <td>
                                                        <div class="product-info">
                                                            <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="location-badge"><?= htmlspecialchars($item['location_code']) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="stock-info <?= $item['quantity'] <= ($item['min_stock_level'] ?? 0) ? 'stock-low' : 'stock-good' ?>">
                                                            <?= number_format($item['quantity']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="batch-lot-info">
                                                            <?php if (!empty($item['batch_number'])): ?>
                                                                <div><small>Batch: <code><?= htmlspecialchars($item['batch_number']) ?></code></small></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($item['lot_number'])): ?>
                                                                <div><small>Lot: <code><?= htmlspecialchars($item['lot_number']) ?></code></small></div>
                                                            <?php endif; ?>
                                                            <?php if (empty($item['batch_number']) && empty($item['lot_number'])): ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($item['received_at'])): ?>
                                                            <small><?= date('d.m.Y', strtotime($item['received_at'])) ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($item['expiry_date'])): ?>
                                                            <?php 
                                                            $expiryDate = new DateTime($item['expiry_date']);
                                                            $today = new DateTime();
                                                            $diff = $today->diff($expiryDate);
                                                            $isExpired = $expiryDate < $today;
                                                            $isExpiringSoon = $diff->days <= 30 && !$isExpired;
                                                            ?>
                                                            <span class="expiry-date <?= $isExpired ? 'expired' : ($isExpiringSoon ? 'expiring-soon' : '') ?>">
                                                                <?= $expiryDate->format('d.m.Y') ?>
                                                                <?php if ($isExpired): ?>
                                                                    <br><small>(Expirat)</small>
                                                                <?php elseif ($isExpiringSoon): ?>
                                                                    <br><small>(<?= $diff->days ?> zile)</small>
                                                                <?php endif; ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group">
                                                            <button class="btn btn-sm btn-outline-primary" 
                                                                    onclick="openMoveStockModal(<?= htmlspecialchars(json_encode($item)) ?>)"
                                                                    title="Mută stoc">
                                                                <span class="material-symbols-outlined">move_location</span>
                                                            </button>
                                                            <button class="btn btn-sm btn-outline-danger" 
                                                                    onclick="openRemoveStockModal(<?= $item['product_id'] ?>, '<?= htmlspecialchars(addslashes($item['product_name'])) ?>')"
                                                                    title="Reduce stoc">
                                                                <span class="material-symbols-outlined">remove</span>
                                                            </button>
                                                        </div>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <div class="pagination-container">
                                    <div class="pagination-info">
                                        Afișare <?= ($offset + 1) ?>-<?= min($offset + $pageSize, $totalCount) ?> din <?= number_format($totalCount) ?> elemente
                                    </div>
                                    <div class="pagination-controls">
                                        <?php if ($page > 1): ?>
                                            <a href="?view=<?= $view ?><?= $productFilter ? '&product=' . $productFilter : '' ?><?= $locationFilter ? '&location=' . $locationFilter : '' ?><?= $lowStockOnly ? '&low_stock=1' : '' ?>&page=<?= ($page - 1) ?>" 
                                               class="pagination-btn">
                                                <span class="material-symbols-outlined">chevron_left</span>
                                                Anterior
                                            </a>
                                        <?php endif; ?>
                                        
                                        <span class="pagination-current">
                                            Pagina <?= $page ?> din <?= $totalPages ?>
                                        </span>
                                        
                                        <?php if ($page < $totalPages): ?>
                                            <a href="?view=<?= $view ?><?= $productFilter ? '&product=' . $productFilter : '' ?><?= $locationFilter ? '&location=' . $locationFilter : '' ?><?= $lowStockOnly ? '&low_stock=1' : '' ?>&page=<?= ($page + 1) ?>" 
                                               class="pagination-btn">
                                                Următor
                                                <span class="material-symbols-outlined">chevron_right</span>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">inventory_2</span>
                                <h3>Nu există produse în inventar</h3>
                                <p>Adaugă primul produs în inventar folosind butonul de mai sus.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Inventory Export Modal -->
    <div class="modal" id="exportInventoryModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Export Inventar</h3>
                    <button class="modal-close" onclick="closeExportModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="GET" id="inventory-export-form">
                    <div class="modal-body">
                        <input type="hidden" name="export_inventory" value="1">
                        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                        <?php if (!empty($productFilter)): ?>
                            <input type="hidden" name="product" value="<?= htmlspecialchars($productFilter) ?>">
                        <?php endif; ?>
                        <?php if (!empty($locationFilter)): ?>
                            <input type="hidden" name="location" value="<?= htmlspecialchars($locationFilter) ?>">
                        <?php endif; ?>
                        <?php if ($lowStockOnly): ?>
                            <input type="hidden" name="low_stock" value="1">
                        <?php endif; ?>

                        <p>Selectează coloanele care vor fi incluse în fișierul exportat. Ordinea din CSV va urma numerele marcate pe fiecare câmp, în funcție de ordinea în care bifezi opțiunile.</p>
                        <div class="export-columns-grid">
                            <?php foreach ($inventoryExportColumns as $key => $columnConfig): ?>
                                <?php $isDefault = !empty($columnConfig['default']); ?>
                                <label class="export-column-option checkbox-label" data-column="<?= htmlspecialchars($key) ?>">
                                    <input
                                        type="checkbox"
                                        name="columns[]"
                                        value="<?= htmlspecialchars($key) ?>"
                                        <?= $isDefault ? 'checked' : '' ?>
                                    >
                                    <span class="export-order-badge" aria-hidden="true"></span>
                                    <span class="export-label-text"><?= htmlspecialchars($columnConfig['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeExportModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Exportă</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Transaction Details Modal -->
    <div class="modal" id="transactionDetailsModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Detalii tranzacție</h3>
                    <button class="modal-close" onclick="closeTransactionModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="modal-body" id="transaction-details-content"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeTransactionModal()">Închide</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Stock Modal -->
    <div class="modal" id="addStockModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Adaugă Stoc</h3>
                    <button class="modal-close" onclick="closeAddStockModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_stock">
                        
                        <div class="form-group">
                            <label class="form-label">Produs *</label>
                            <div class="product-search-container">
                                <input type="hidden" id="add-product" name="product_id">
                                <input type="text" id="add-product-search" class="form-control product-search-input" placeholder="Caută produs..." autocomplete="off"
                                       onkeyup="searchProducts(this.value)" onfocus="showProductResults()">
                                <div class="product-search-results" id="add-product-results"></div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="add-location" class="form-label">Locație *</label>
                            <select id="add-location" name="location_id" class="form-control" required>
                                <option value="">Selectează locația</option>
                                <?php foreach ($allLocations as $location): ?>
                                    <option value="<?= $location['id'] ?>">
                                        <?= htmlspecialchars($location['location_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="shelf_level" class="form-label">Nivel raft</label>
                            <select id="shelf_level" name="shelf_level" class="form-control" onchange="updateSubdivisionOptions()">
                                <option value="">--</option>
                            </select>
                        </div>
                        <div class="form-group" id="subdivision-container" style="display:none;">
                            <label for="subdivision_number" class="form-label">Subdiviziune</label>
                            <select id="subdivision_number" name="subdivision_number" class="form-control">
                                <option value="">--</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="add-quantity" class="form-label">Cantitate <span id="total-articles" style="font-weight:normal;color:var(--text-secondary);"></span> *</label>
                                <input type="number" id="add-quantity" name="quantity" class="form-control" min="1" required>
                            </div>
                            <div class="form-group">
                                <label for="add-expiry" class="form-label">Data Expirării</label>
                                <input type="date" id="add-expiry" name="expiry_date" class="form-control">
                                <div class="expiry-quick-options">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setExpiry('none')">Fără dată de expirare</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setExpiry('6m')">6 luni</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setExpiry('1y')">1 an</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setExpiry('2y')">2 ani</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setExpiry('3y')">3 ani</button>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Metodă de urmărire</label>
                            <div>
                                <label><input type="radio" name="tracking_method" value="bulk" checked> Bulk inventory</label>
                                <label style="margin-left:1rem;"><input type="radio" name="tracking_method" value="individual"> Individual unit tracking</label>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="form-group">
                                <label for="add-batch" class="form-label">Număr Batch</label>
                                <input type="text" id="add-batch" name="batch_number" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="add-lot" class="form-label">Număr Lot</label>
                                <input type="text" id="add-lot" name="lot_number" class="form-control">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="add-received" class="form-label">Data Primirii</label>
                            <input type="datetime-local" id="add-received" name="received_at" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeAddStockModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Adaugă Stoc</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Remove Stock Modal -->
    <div class="modal" id="removeStockModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Reduce Stoc</h3>
                    <button class="modal-close" onclick="closeRemoveStockModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="remove_stock">
                        <input type="hidden" id="remove-product-id" name="product_id">
                        
                        <div class="alert alert-warning">
                            <span class="material-symbols-outlined">warning</span>
                            Vei reduce stocul pentru produsul: <strong id="remove-product-name"></strong>
                        </div>
                        
                        <div class="form-group">
                            <label for="remove-quantity" class="form-label">Cantitate de redus *</label>
                            <input type="number" id="remove-quantity" name="quantity" class="form-control" min="1" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="remove-location" class="form-label">Locație (opțional)</label>
                            <select id="remove-location" name="location_id" class="form-control">
                                <option value="">Din toate locațiile</option>
                                <?php foreach ($allLocations as $location): ?>
                                    <option value="<?= $location['id'] ?>">
                                        <?= htmlspecialchars($location['location_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeRemoveStockModal()">Anulează</button>
                        <button type="submit" class="btn btn-danger">Reduce Stoc</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Move Stock Modal -->
    <div class="modal" id="moveStockModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Mută Stoc</h3>
                    <button class="modal-close" onclick="closeMoveStockModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="move_stock">
                        <input type="hidden" id="move-inventory-id" name="inventory_id">
                        <input type="hidden" id="move-product-id" name="product_id">
                        <input type="hidden" id="move-from-location-id" name="from_location_id">
                        
                        <div class="alert alert-info">
                            <span class="material-symbols-outlined">info</span>
                            Mutare stoc pentru: <strong id="move-product-name"></strong>
                        </div>
                        
                        <div class="form-group">
                            <label for="move-new-location" class="form-label">Locație nouă *</label>
                            <select id="move-new-location" name="new_location_id" class="form-control" required>
                                <option value="">Selectează locația</option>
                                <?php foreach ($allLocations as $location): ?>
                                    <option value="<?= $location['id'] ?>">
                                        <?= htmlspecialchars($location['location_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="move-quantity" class="form-label">Cantitate de mutat *</label>
                            <input type="number" id="move-quantity" name="move_quantity" class="form-control" min="1" required>
                            <small class="form-text text-muted">Cantitate disponibilă: <span id="available-quantity"></span></small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeMoveStockModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Mută Stoc</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Stock Import Modal -->
    <div class="modal" id="importStockModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Import Stoc</h3>
                    <button class="modal-close" onclick="closeImportStockModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="stock-import-upload" class="stock-import-step">
                        <div id="stock-import-drop" class="file-drop-area">
                            <span class="material-symbols-outlined">upload_file</span>
                            <p>Trage fișierul aici sau apasă pentru selectare</p>
                            <input type="file" id="stock-import-file" accept=".xls,.xlsx" style="display:none;">
                        </div>
                        <div id="stock-import-selected" class="selected-file" style="display:none; gap:10px; align-items:center; margin-top:10px;">
                            <span id="stock-import-filename"></span>
                            <button type="button" class="btn btn-sm btn-secondary" id="stock-import-remove">Șterge</button>
                        </div>
                        <div style="margin-top:15px;">
                            <button type="button" class="btn btn-primary" id="stock-import-start" disabled>Importă</button>
                        </div>
                    </div>
                    <div id="stock-import-progress" class="stock-import-step" style="display:none;">
                        <div class="progress-bar" style="height:20px;background:#f0f0f0;border-radius:4px;overflow:hidden;">
                            <div id="stock-import-progress-bar" class="progress" style="height:100%;width:0;background:#4caf50;"></div>
                        </div>
                        <p style="margin-top:10px;">Procesare fișier...</p>
                    </div>
                    <div id="stock-import-results" class="stock-import-step" style="display:none;">
                        <div id="stock-import-summary"></div>
                        <div id="stock-import-warnings" style="margin-top:10px;"></div>
                        <div id="stock-import-errors" style="margin-top:10px;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeImportStockModal()">Închide</button>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
