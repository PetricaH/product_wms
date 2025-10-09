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

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['ajax_action'] ?? '') === 'lookup_invoice_locations'
) {
    header('Content-Type: application/json; charset=utf-8');

    $skuPayload = $_POST['skus'] ?? '[]';
    $decodedSkus = json_decode($skuPayload, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedSkus)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Lista de SKU-uri este invalidă.'
        ]);
        exit;
    }

    $skus = array_values(array_unique(array_filter(array_map(static function ($value) {
        return is_string($value) ? trim($value) : '';
    }, $decodedSkus))));

    $locations = [];

    try {
        if (!empty($skus)) {
            $placeholders = implode(',', array_fill(0, count($skus), '?'));
            $lookupQuery = <<<SQL
                SELECT
                    TRIM(COALESCE(p.sku, '')) AS sku,
                    COALESCE(l.location_code, '') AS location_code,
                    COALESCE(l.name, '') AS location_name
                FROM inventory i
                INNER JOIN products p ON i.product_id = p.product_id
                INNER JOIN locations l ON i.location_id = l.id
                WHERE p.sku IN ($placeholders)
                ORDER BY i.quantity DESC, i.received_at DESC
            SQL;

            $lookupStatement = $db->prepare($lookupQuery);
            $lookupStatement->execute($skus);

            while ($row = $lookupStatement->fetch(PDO::FETCH_ASSOC)) {
                $sku = $row['sku'] ?? '';
                if ($sku === '' || isset($locations[$sku])) {
                    continue;
                }

                $locations[$sku] = [
                    'location_code' => $row['location_code'] ?? '',
                    'location_name' => $row['location_name'] ?? ''
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'locations' => $locations
        ]);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Nu s-au putut prelua locațiile produselor.'
        ]);
    }

    exit;
}

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
$receivedFrom = $_GET['received_from'] ?? '';
$receivedTo = $_GET['received_to'] ?? '';
$receivedQuick = $_GET['received_quick'] ?? '';
$onlyReturnsFilter = isset($_GET['only_returns']) && $_GET['only_returns'] === '1';

$receivedFrom = is_string($receivedFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedFrom) ? $receivedFrom : '';
$receivedTo = is_string($receivedTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedTo) ? $receivedTo : '';
$receivedQuick = is_string($receivedQuick) ? $receivedQuick : '';

if ($receivedQuick === 'today') {
    $today = date('Y-m-d');
    $receivedFrom = $today;
    $receivedTo = $today;
    $onlyReturnsFilter = false;
} elseif ($receivedQuick === 'returns_today') {
    $today = date('Y-m-d');
    $receivedFrom = $today;
    $receivedTo = $today;
    $onlyReturnsFilter = true;
}

if ($receivedFrom && $receivedTo && $receivedFrom > $receivedTo) {
    $swapValue = $receivedFrom;
    $receivedFrom = $receivedTo;
    $receivedTo = $swapValue;
}

$inventoryFilterOptions = [
    'received_from' => $receivedFrom,
    'received_to' => $receivedTo,
    'only_returns' => $onlyReturnsFilter,
];

$detailedBaseParams = [
    'view' => 'detailed',
];

if ($productFilter !== '') {
    $detailedBaseParams['product'] = $productFilter;
}

if ($locationFilter !== '') {
    $detailedBaseParams['location'] = $locationFilter;
}

if ($lowStockOnly) {
    $detailedBaseParams['low_stock'] = '1';
}

if ($receivedFrom !== '') {
    $detailedBaseParams['received_from'] = $receivedFrom;
}

if ($receivedTo !== '') {
    $detailedBaseParams['received_to'] = $receivedTo;
}

if ($receivedQuick !== '') {
    $detailedBaseParams['received_quick'] = $receivedQuick;
}

if ($onlyReturnsFilter) {
    $detailedBaseParams['only_returns'] = '1';
}

$detailedPageLink = static function (int $pageNumber) use ($detailedBaseParams): string {
    $params = $detailedBaseParams;
    $params['page'] = max($pageNumber, 1);
    return '?' . http_build_query($params);
};

$detailedViewLink = '?' . http_build_query($detailedBaseParams);

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

if (isset($_GET['export_inventory_pdf'])) {
    try {
        if (!class_exists('FPDF')) {
            $composerAutoload = BASE_PATH . '/vendor/autoload.php';
            if (file_exists($composerAutoload)) {
                require_once $composerAutoload;
            }
        }

        if (!class_exists('FPDF') && file_exists(BASE_PATH . '/lib/fpdf.php')) {
            require_once BASE_PATH . '/lib/fpdf.php';
        }

        if (!class_exists('FPDF')) {
            throw new RuntimeException('Biblioteca FPDF nu este disponibilă pentru generarea raportului.');
        }

        $exportRows = $inventoryModel->getInventoryWithFilters(
            $productFilter,
            $locationFilter,
            $lowStockOnly,
            $inventoryFilterOptions
        );

        $totalQuantity = 0;
        foreach ($exportRows as $row) {
            $totalQuantity += (float)($row['quantity'] ?? 0);
        }

        $convertText = static function ($text) {
            $text = (string)$text;
            $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
            if ($converted === false) {
                $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
            }
            return $converted === false ? $text : $converted;
        };

        $formatDate = static function (?string $value, bool $includeTime = false): string {
            if (empty($value)) {
                return '-';
            }
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                return '-';
            }
            return $includeTime ? date('d.m.Y H:i', $timestamp) : date('d.m.Y', $timestamp);
        };

        $filterDetails = [];

        if ($productFilter !== '') {
            try {
                $productStmt = $db->prepare('SELECT name, sku FROM products WHERE product_id = :product_id LIMIT 1');
                $productStmt->execute([':product_id' => $productFilter]);
                $productInfo = $productStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $productInfo = [];
                error_log('[INVENTORY][PDF_EXPORT] Nu s-a putut prelua produsul filtrat: ' . $e->getMessage());
            }

            if (!empty($productInfo)) {
                $parts = [];
                if (!empty($productInfo['name'])) {
                    $parts[] = $productInfo['name'];
                }
                if (!empty($productInfo['sku'])) {
                    $parts[] = 'SKU: ' . $productInfo['sku'];
                }
                $filterDetails[] = 'Produs: ' . implode(' • ', $parts);
            } else {
                $filterDetails[] = 'Produs filtrat ID: ' . $productFilter;
            }
        }

        if ($locationFilter !== '') {
            try {
                $locationStmt = $db->prepare('SELECT location_code FROM locations WHERE id = :location_id LIMIT 1');
                $locationStmt->execute([':location_id' => $locationFilter]);
                $locationInfo = $locationStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $locationInfo = [];
                error_log('[INVENTORY][PDF_EXPORT] Nu s-a putut prelua locația filtrată: ' . $e->getMessage());
            }

            if (!empty($locationInfo['location_code'])) {
                $filterDetails[] = 'Locație: ' . $locationInfo['location_code'];
            } else {
                $filterDetails[] = 'Locație filtrată ID: ' . $locationFilter;
            }
        }

        if ($lowStockOnly) {
            $filterDetails[] = 'Doar poziții sub pragul minim.';
        }

        $intervalText = 'Interval selectat: toate înregistrările disponibile';
        if ($receivedFrom !== '' && $receivedTo !== '') {
            $intervalText = 'Interval selectat: ' . date('d.m.Y', strtotime($receivedFrom)) . ' - ' . date('d.m.Y', strtotime($receivedTo));
        } elseif ($receivedFrom !== '') {
            $intervalText = 'Interval selectat: de la ' . date('d.m.Y', strtotime($receivedFrom));
        } elseif ($receivedTo !== '') {
            $intervalText = 'Interval selectat: până la ' . date('d.m.Y', strtotime($receivedTo));
        }

        $pdf = new FPDF('L', 'mm', 'A4');
        $pdf->SetTitle($convertText('Raport detaliat stocuri'));
        $pdf->SetMargins(12, 15, 12);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, $convertText('Raport detaliat stocuri'), 0, 1, 'C');
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 7, $convertText($intervalText), 0, 1, 'C');
        $pdf->Cell(0, 6, $convertText('Generat la: ' . date('d.m.Y H:i')), 0, 1, 'C');
        $pdf->Ln(4);

        $pdf->SetFont('Arial', '', 10);
        $summaryLine = sprintf(
            'Poziții incluse: %s | Cantitate totală: %s',
            number_format(count($exportRows), 0, ',', '.'),
            number_format($totalQuantity, 0, ',', '.')
        );
        $pdf->Cell(0, 6, $convertText($summaryLine), 0, 1, 'L');

        if (!empty($filterDetails)) {
            $pdf->MultiCell(0, 6, $convertText('Filtre aplicate: ' . implode(' | ', $filterDetails)));
        } else {
            $pdf->MultiCell(0, 6, $convertText('Filtre aplicate: toate produsele și locațiile active.'));
        }

        $pdf->Ln(2);

        if (!empty($exportRows)) {
            $columnWidths = [10, 32, 90, 45, 26, 40, 40, 30];

            $renderHeader = static function () use ($pdf, $columnWidths, $convertText) {
                $pdf->SetFont('Arial', 'B', 10);
                $pdf->SetFillColor(240, 240, 240);
                $pdf->Cell($columnWidths[0], 8, $convertText('Nr.'), 1, 0, 'C', true);
                $pdf->Cell($columnWidths[1], 8, $convertText('SKU'), 1, 0, 'C', true);
                $pdf->Cell($columnWidths[2], 8, $convertText('Produs'), 1, 0, 'C', true);
                $pdf->Cell($columnWidths[3], 8, $convertText('Locație'), 1, 0, 'C', true);
                $pdf->Cell($columnWidths[4], 8, $convertText('Cant.'), 1, 0, 'C', true);
                $pdf->Cell($columnWidths[5], 8, $convertText('Data primirii'), 1, 0, 'C', true);
                $pdf->Cell($columnWidths[6], 8, $convertText('Batch / Lot'), 1, 0, 'C', true);
                $pdf->Cell($columnWidths[7], 8, $convertText('Expiră'), 1, 1, 'C', true);
                $pdf->SetFont('Arial', '', 9);
            };

            $renderHeader();

            $rowNumber = 1;
            foreach ($exportRows as $row) {
                if ($pdf->GetY() > $pdf->GetPageHeight() - 25) {
                    $pdf->AddPage();
                    $renderHeader();
                }

                $productName = $row['product_name'] ?? ($row['name'] ?? '');
                $productName = mb_strlen($productName) > 70 ? mb_substr($productName, 0, 67) . '…' : $productName;

                $locationParts = [];
                if (!empty($row['location_code'])) {
                    $locationParts[] = $row['location_code'];
                }
                if (!empty($row['shelf_level'])) {
                    $locationParts[] = 'Raft ' . $row['shelf_level'];
                }
                if (!empty($row['subdivision_number'])) {
                    $locationParts[] = 'Subdiv. ' . $row['subdivision_number'];
                }
                $locationText = !empty($locationParts) ? implode(' • ', $locationParts) : '-';

                $lotParts = [];
                if (!empty($row['batch_number'])) {
                    $lotParts[] = 'Batch ' . $row['batch_number'];
                }
                if (!empty($row['lot_number'])) {
                    $lotParts[] = 'Lot ' . $row['lot_number'];
                }
                $lotText = !empty($lotParts) ? implode(' | ', $lotParts) : '-';

                $pdf->Cell($columnWidths[0], 8, $convertText((string)$rowNumber), 1, 0, 'C');
                $pdf->Cell($columnWidths[1], 8, $convertText((string)($row['sku'] ?? '')), 1);
                $pdf->Cell($columnWidths[2], 8, $convertText($productName), 1);
                $pdf->Cell($columnWidths[3], 8, $convertText($locationText), 1);
                $pdf->Cell($columnWidths[4], 8, $convertText(number_format((float)($row['quantity'] ?? 0), 0, ',', '.')), 1, 0, 'R');
                $pdf->Cell($columnWidths[5], 8, $convertText($formatDate($row['received_at'] ?? null, true)), 1);
                $pdf->Cell($columnWidths[6], 8, $convertText($lotText), 1);
                $pdf->Cell($columnWidths[7], 8, $convertText($formatDate($row['expiry_date'] ?? null)), 1, 1);

                ++$rowNumber;
            }
        } else {
            $pdf->Ln(8);
            $pdf->SetFont('Arial', 'I', 11);
            $pdf->MultiCell(0, 7, $convertText('Nu au fost găsite înregistrări pentru criteriile selectate.'));
        }

        $pdf->Ln(6);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5, $convertText('Notă: Document generat automat din modulul „Stocuri - vizualizare detaliată”. Datele reflectă stocul disponibil la momentul exportului.'));

        $fileName = 'raport_stocuri_' . date('Ymd_His') . '.pdf';
        $pdf->Output('D', $fileName);
        exit;
    } catch (Throwable $exception) {
        error_log('[INVENTORY][PDF_EXPORT] ' . $exception->getMessage());
        $message = 'A apărut o eroare la generarea raportului PDF. Vă rugăm să încercați din nou.';
        $messageType = 'error';
    }
}

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

    $exportRows = $inventoryModel->getInventoryWithFilters(
        $productFilter,
        $locationFilter,
        $lowStockOnly,
        $inventoryFilterOptions
    );

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
        $allInventory = $inventoryModel->getInventoryWithFilters(
            $productFilter,
            $locationFilter,
            $lowStockOnly,
            $inventoryFilterOptions
        );
        $totalCount = count($allInventory);
        $inventory = array_slice($allInventory, $offset, $pageSize);
        break;
    case 'low-stock':
        $allInventory = $inventoryModel->getLowStockItems();
        $totalCount = count($allInventory);
        $inventory = array_slice($allInventory, $offset, $pageSize);
        break;
    case 'invoice-ai':
        $inventory = [];
        $totalCount = 0;
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
        $allInventory = $inventoryModel->getInventoryWithFilters(
            $productFilter,
            $locationFilter,
            $lowStockOnly,
            $inventoryFilterOptions
        );
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

        .invoice-processing-card .card-body {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .invoice-processing-intro {
            font-size: 1rem;
            line-height: 1.6;
            color: var(--text-secondary, #475569);
            margin: 0;
        }

        .invoice-upload-wrapper {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .invoice-drop-zone {
            border: 2px dashed rgba(37, 99, 235, 0.35);
            border-radius: 18px;
            padding: 2.25rem 1.5rem;
            text-align: center;
            background: var(--surface-background, #f8fafc);
            transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
            cursor: pointer;
            outline: none;
        }

        .invoice-drop-zone .material-symbols-outlined {
            font-size: 3rem;
            display: block;
            margin-bottom: 0.75rem;
            color: rgba(37, 99, 235, 0.75);
        }

        .invoice-drop-zone strong {
            display: block;
            color: var(--text-primary, #0f172a);
            margin-bottom: 0.25rem;
            font-size: 1.05rem;
        }

        .invoice-drop-zone p {
            margin: 0.25rem 0;
            color: var(--text-secondary, #475569);
        }

        .invoice-file-hint {
            font-size: 0.9rem;
            color: var(--text-muted, #64748b);
        }

        .form-group--date-range .date-range-inputs {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group--date-range .form-control {
            flex: 1 1 0;
            min-width: 0;
        }

        .date-range-separator {
            color: rgba(15, 23, 42, 0.6);
            font-weight: 500;
        }

        .quick-filter-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .btn-quick-filter {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border: 1px solid rgba(30, 64, 175, 0.45);
            border-radius: 999px;
            background: linear-gradient(135deg, #2563eb 0%, #1e3a8a 100%);
            color: #ffffff;
            padding: 0.5rem 1.1rem;
            font-size: 0.9rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            cursor: pointer;
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.25);
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
        }

        .btn-quick-filter .material-symbols-outlined {
            font-size: 1.1rem;
        }

        .btn-quick-filter:hover,
        .btn-quick-filter:focus {
            transform: translateY(-1px);
            filter: brightness(1.05);
            outline: none;
            box-shadow: 0 8px 22px rgba(30, 64, 175, 0.35);
        }

        .btn-quick-filter.active {
            filter: brightness(0.95);
            box-shadow: 0 10px 26px rgba(30, 64, 175, 0.45);
        }

        .table-row--recent {
            background: rgba(34, 197, 94, 0.08);
        }

        .table-row--recent:hover {
            background: rgba(22, 163, 74, 0.12);
        }

        .table-row--return {
            background: rgba(59, 130, 246, 0.08);
        }

        .table-row--return:hover {
            background: rgba(37, 99, 235, 0.12);
        }

        .received-info {
            display: flex;
            flex-direction: column;
            gap: 0.1rem;
            line-height: 1.2;
        }

        .badge-recent {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            margin-top: 0.35rem;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(34, 197, 94, 0.18);
            color: #166534;
        }

        .badge-recent .material-symbols-outlined {
            font-size: 1rem;
        }

        .badge-return {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            margin-top: 0.35rem;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(59, 130, 246, 0.18);
            color: #1d4ed8;
        }

        .badge-return .material-symbols-outlined {
            font-size: 1rem;
        }

        .invoice-drop-zone:focus,
        .invoice-drop-zone.dragover {
            border-color: rgba(37, 99, 235, 0.8);
            background: rgba(37, 99, 235, 0.08);
            box-shadow: 0 10px 30px rgba(37, 99, 235, 0.15);
        }

        .invoice-selected-file {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(37, 99, 235, 0.08);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: var(--text-primary, #0f172a);
        }

        .invoice-selected-file .material-symbols-outlined {
            font-size: 1.5rem;
            color: rgba(37, 99, 235, 0.8);
        }

        .invoice-selected-file button {
            margin-left: auto;
            border: none;
            background: none;
            color: rgba(220, 38, 38, 0.9);
            font-weight: 600;
            cursor: pointer;
        }

        .invoice-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .invoice-process-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.2s ease, opacity 0.2s ease;
        }

        .invoice-process-btn[disabled] {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .invoice-process-btn .material-symbols-outlined {
            transition: transform 0.3s ease;
        }

        .invoice-process-btn.is-loading .material-symbols-outlined {
            animation: invoice-spin 1s linear infinite;
        }

        .invoice-process-hint {
            font-size: 0.9rem;
            color: var(--text-muted, #64748b);
        }

        .invoice-process-hint[hidden] {
            display: none;
        }

        .invoice-reset-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .invoice-reset-btn[disabled] {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .invoice-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-height: 1.5rem;
            font-size: 0.95rem;
            color: var(--text-secondary, #475569);
        }

        .invoice-status.invoice-status--loading {
            color: #2563eb;
        }

        .invoice-status.invoice-status--loading::before {
            content: '';
            width: 1rem;
            height: 1rem;
            border-radius: 999px;
            border: 2px solid rgba(37, 99, 235, 0.2);
            border-top-color: rgba(37, 99, 235, 0.85);
            animation: invoice-spin 0.9s linear infinite;
        }

        .invoice-status.invoice-status--success {
            color: #16a34a;
        }

        .invoice-status.invoice-status--error {
            color: #dc2626;
        }

        .invoice-feedback {
            border-radius: 12px;
            padding: 0.85rem 1rem;
            border: 1px solid transparent;
            display: none;
            font-weight: 500;
        }

        .invoice-feedback.invoice-feedback--success {
            display: block;
            background: rgba(22, 163, 74, 0.12);
            border-color: rgba(22, 163, 74, 0.35);
            color: #166534;
        }

        .invoice-feedback.invoice-feedback--error {
            display: block;
            background: rgba(220, 38, 38, 0.12);
            border-color: rgba(220, 38, 38, 0.35);
            color: #b91c1c;
        }

        .invoice-feedback.invoice-feedback--warning {
            display: block;
            background: rgba(234, 179, 8, 0.12);
            border-color: rgba(234, 179, 8, 0.35);
            color: #b45309;
        }

        .invoice-result {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .invoice-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .invoice-summary-item {
            flex: 1 1 200px;
            background: var(--surface-background, #f8fafc);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            border: 1px solid var(--border-color, rgba(148, 163, 184, 0.35));
        }

        .invoice-summary-label {
            font-size: 0.8rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--text-muted, #64748b);
            display: block;
            margin-bottom: 0.35rem;
        }

        .invoice-summary-value {
            font-size: 1.15rem;
            font-weight: 600;
            color: var(--text-primary, #0f172a);
        }

        .invoice-supplier {
            font-size: 1rem;
            color: var(--text-secondary, #475569);
            font-weight: 500;
        }

        .invoice-table td {
            vertical-align: middle;
        }

        .invoice-quantity {
            font-weight: 600;
            color: var(--text-primary, #0f172a);
        }

        .invoice-unit {
            font-size: 0.85rem;
            color: var(--text-muted, #64748b);
            margin-left: 0.35rem;
        }

        .invoice-action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: flex-start;
        }

        .invoice-action-btn {
            min-width: 210px;
            font-size: 1rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.85rem 1.5rem;
        }

        .invoice-tag {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .invoice-tag--new {
            background: rgba(37, 99, 235, 0.08);
            color: #1d4ed8;
        }

        @keyframes invoice-spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 768px) {
            .invoice-drop-zone {
                padding: 1.75rem 1rem;
            }

            .invoice-action-buttons {
                flex-direction: column;
                align-items: stretch;
            }

            .invoice-action-btn {
                width: 100%;
            }
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
                                    <a href="?view=invoice-ai" class="toggle-link <?= $view === 'invoice-ai' ? 'active' : '' ?>">
                                        <span class="material-symbols-outlined">smart_toy</span>
                                        Procesare Facturi
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
                <?php elseif ($view === 'invoice-ai'): ?>
                <div class="card invoice-processing-card">
                    <div class="card-header">
                        <h3 class="card-title">Procesare Facturi cu AI</h3>
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
                                <a href="?view=invoice-ai" class="toggle-link <?= $view === 'invoice-ai' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">smart_toy</span>
                                    Procesare Facturi
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="invoice-processing-intro">
                            Încarcă o factură pentru procesare automată cu AI. Sistemul va extrage produsele și cantitățile automat.
                        </p>
                        <div class="invoice-upload-wrapper">
                            <div id="invoice-drop-zone" class="invoice-drop-zone" tabindex="0" role="button" aria-label="Încarcă factură">
                                <span class="material-symbols-outlined">cloud_upload</span>
                                <strong>Trage și plasează fișierul aici</strong>
                                <p>sau apasă pentru a selecta din calculator</p>
                                <p class="invoice-file-hint">Formate acceptate: PDF, JPG, PNG</p>
                                <input type="file" id="invoice-file-input" accept=".pdf,.jpg,.jpeg,.png" hidden>
                            </div>
                            <div id="invoice-selected-file" class="invoice-selected-file" hidden>
                                <span class="material-symbols-outlined">description</span>
                                <span id="invoice-selected-file-name"></span>
                                <button type="button" id="invoice-remove-file">Șterge</button>
                            </div>
                        </div>
                        <div class="invoice-actions">
                            <button type="button" class="btn btn-primary invoice-process-btn" id="invoice-process-btn" disabled>
                                <span class="material-symbols-outlined invoice-process-btn__icon" aria-hidden="true">auto_awesome</span>
                                <span class="invoice-process-btn__label">Procesează Factură</span>
                            </button>
                            <button type="button" class="btn btn-outline-secondary invoice-reset-btn" id="invoice-reset-btn" disabled>
                                <span class="material-symbols-outlined" aria-hidden="true">close</span>
                                Renunță
                            </button>
                        </div>
                        <p id="invoice-process-hint" class="invoice-process-hint">Încarcă o factură pentru a activa procesarea.</p>
                        <p id="invoice-process-status" class="invoice-status" role="status" aria-live="polite"></p>
                        <div id="invoice-feedback" class="invoice-feedback" role="alert"></div>
                        <div id="invoice-result" class="invoice-result" hidden>
                            <div class="invoice-summary" id="invoice-summary"></div>
                            <div class="invoice-supplier" id="invoice-supplier"></div>
                            <div class="table-container">
                                <table class="table invoice-table">
                                    <thead>
                                        <tr>
                                            <th>SKU</th>
                                            <th>Produs</th>
                                            <th>Cantitate</th>
                                            <th>Preț</th>
                                            <th>Locație</th>
                                        </tr>
                                    </thead>
                                    <tbody id="invoice-products-body"></tbody>
                                </table>
                            </div>
                            <div class="invoice-action-buttons">
                                <button type="button" class="btn btn-success invoice-action-btn" data-action="add_stock" disabled>
                                    ✓ Adaugă în Stoc
                                </button>
                                <button type="button" class="btn btn-danger invoice-action-btn" data-action="remove_stock" disabled>
                                    ✗ Scade din Stoc
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal" id="invoice-confirm-modal" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h3 class="modal-title">Confirmă acțiunea</h3>
                                <button class="modal-close" type="button" id="invoice-modal-close">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <p id="invoice-confirm-message"></p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" id="invoice-modal-cancel">Anulează</button>
                                <button type="button" class="btn btn-primary" id="invoice-modal-confirm">Confirmă</button>
                            </div>
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
                                <a href="?view=invoice-ai" class="toggle-link <?= $view === 'invoice-ai' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">smart_toy</span>
                                    Procesare Facturi
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
                                <a href="<?= htmlspecialchars($detailedViewLink, ENT_QUOTES) ?>"
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
                                <a href="?view=invoice-ai"
                                   class="toggle-link <?= $view === 'invoice-ai' ? 'active' : '' ?>">
                                    <span class="material-symbols-outlined">smart_toy</span>
                                    Procesare Facturi
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($view === 'detailed'): ?>
                    <div class="card-body">
                        <!-- Filters -->
                        <form method="GET" class="filter-form">
                            <input type="hidden" name="view" value="detailed">
                            <?php if ($receivedQuick !== ''): ?>
                                <input type="hidden" name="received_quick" value="<?= htmlspecialchars($receivedQuick) ?>">
                            <?php endif; ?>
                            <?php if ($onlyReturnsFilter): ?>
                                <input type="hidden" name="only_returns" value="1">
                            <?php endif; ?>

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

                            <div class="form-group form-group--date-range">
                                <label class="form-label" for="received-from">Primire între</label>
                                <div class="date-range-inputs">
                                    <input
                                        type="date"
                                        id="received-from"
                                        name="received_from"
                                        class="form-control"
                                        value="<?= htmlspecialchars($receivedFrom) ?>"
                                    >
                                    <span class="date-range-separator">–</span>
                                    <input
                                        type="date"
                                        id="received-to"
                                        name="received_to"
                                        class="form-control"
                                        value="<?= htmlspecialchars($receivedTo) ?>"
                                    >
                                </div>
                                <div class="quick-filter-buttons">
                                    <button type="submit" name="received_quick" value="today" class="btn btn-quick-filter <?= $receivedQuick === 'today' ? 'active' : '' ?>">
                                        <span class="material-symbols-outlined">today</span>
                                        Azi
                                    </button>
                                    <button type="submit" name="received_quick" value="returns_today" class="btn btn-quick-filter <?= $receivedQuick === 'returns_today' ? 'active' : '' ?>">
                                        <span class="material-symbols-outlined">undo</span>
                                        Retururi Azi
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="low_stock" value="1" <?= $lowStockOnly ? 'checked' : '' ?>>
                                    Doar stoc scăzut
                                </label>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-secondary">
                                    <span class="material-symbols-outlined">filter_alt</span>
                                    Filtrează
                                </button>

                                <a href="?view=detailed" class="btn btn-secondary">
                                    <span class="material-symbols-outlined">refresh</span>
                                    Reset
                                </a>

                                <button type="submit" name="export_inventory_pdf" value="1" class="btn btn-primary">
                                    <span class="material-symbols-outlined">picture_as_pdf</span>
                                    Exportă PDF
                                </button>
                            </div>
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
                                        <?php $todayDate = date('Y-m-d'); ?>
                                        <?php foreach ($inventory as $item): ?>
                                            <?php
                                                $receivedAtRaw = $item['received_at'] ?? null;
                                                $receivedAtDate = $receivedAtRaw ? date('Y-m-d', strtotime($receivedAtRaw)) : null;
                                                $isRecent = $view === 'detailed' && $receivedAtDate === $todayDate;
                                                $isReturnEntry = !empty($item['is_return_entry']);
                                                $rowClassParts = [];
                                                if ($isRecent) {
                                                    $rowClassParts[] = 'table-row--recent';
                                                }
                                                if ($isReturnEntry) {
                                                    $rowClassParts[] = 'table-row--return';
                                                }
                                                $rowClass = implode(' ', $rowClassParts);
                                                $rowClassAttribute = $rowClass !== '' ? ' class="' . htmlspecialchars($rowClass) . '"' : '';
                                            ?>
                                            <tr<?= $rowClassAttribute ?>>
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
                                                        <?php if (!empty($receivedAtRaw)): ?>
                                                            <div class="received-info">
                                                                <strong><?= date('d.m.Y', strtotime($receivedAtRaw)) ?></strong>
                                                                <small><?= date('H:i', strtotime($receivedAtRaw)) ?></small>
                                                            </div>
                                                            <?php if ($isRecent): ?>
                                                                <span class="badge badge-recent">
                                                                    <span class="material-symbols-outlined">new_releases</span>
                                                                    Azi
                                                                </span>
                                                            <?php endif; ?>
                                                            <?php if ($isReturnEntry): ?>
                                                                <span class="badge badge-return" title="Stoc provenit din retur">
                                                                    <span class="material-symbols-outlined">undo</span>
                                                                    Retur
                                                                </span>
                                                            <?php endif; ?>
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
                                            <a href="<?= htmlspecialchars($detailedPageLink($page - 1), ENT_QUOTES) ?>"
                                               class="pagination-btn">
                                                <span class="material-symbols-outlined">chevron_left</span>
                                                Anterior
                                            </a>
                                        <?php endif; ?>
                                        
                                        <span class="pagination-current">
                                            Pagina <?= $page ?> din <?= $totalPages ?>
                                        </span>
                                        
                                        <?php if ($page < $totalPages): ?>
                                            <a href="<?= htmlspecialchars($detailedPageLink($page + 1), ENT_QUOTES) ?>"
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

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const dropZone = document.getElementById('invoice-drop-zone');
            if (!dropZone) {
                return;
            }

            const fileInput = document.getElementById('invoice-file-input');
            const selectedFileContainer = document.getElementById('invoice-selected-file');
            const selectedFileName = document.getElementById('invoice-selected-file-name');
            const removeFileBtn = document.getElementById('invoice-remove-file');
            const processBtn = document.getElementById('invoice-process-btn');
            const processBtnIcon = processBtn ? processBtn.querySelector('.invoice-process-btn__icon') : null;
            const processBtnLabel = processBtn ? processBtn.querySelector('.invoice-process-btn__label') : null;
            const processHint = document.getElementById('invoice-process-hint');
            const resetBtn = document.getElementById('invoice-reset-btn');
            const statusEl = document.getElementById('invoice-process-status');
            const feedbackEl = document.getElementById('invoice-feedback');
            const resultSection = document.getElementById('invoice-result');
            const summaryContainer = document.getElementById('invoice-summary');
            const supplierEl = document.getElementById('invoice-supplier');
            const productsBody = document.getElementById('invoice-products-body');
            const actionButtons = Array.from(document.querySelectorAll('.invoice-action-btn'));
            const confirmModal = document.getElementById('invoice-confirm-modal');
            const confirmMessage = document.getElementById('invoice-confirm-message');
            const confirmBtn = document.getElementById('invoice-modal-confirm');
            const cancelBtn = document.getElementById('invoice-modal-cancel');
            const closeBtn = document.getElementById('invoice-modal-close');
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

            let selectedFile = null;
            let extractedData = null;
            let productLocations = {};
            let pendingAction = null;
            let isProcessing = false;
            let isSubmittingAction = false;

            function updateProcessHint() {
                if (!processHint) {
                    return;
                }
                if (selectedFile) {
                    processHint.setAttribute('hidden', 'hidden');
                    processHint.setAttribute('aria-hidden', 'true');
                } else {
                    processHint.removeAttribute('hidden');
                    processHint.setAttribute('aria-hidden', 'false');
                }
            }

            function updateResetBtnState() {
                if (!resetBtn) {
                    return;
                }
                const hasFile = Boolean(selectedFile);
                const hasResult = Boolean(extractedData) || (resultSection && !resultSection.hidden);
                const hasFeedback = Boolean(feedbackEl && feedbackEl.textContent && feedbackEl.textContent.trim().length);
                const hasStatus = Boolean(statusEl && statusEl.textContent && statusEl.textContent.trim().length);
                const shouldEnable = hasFile || hasResult || hasFeedback || hasStatus;
                resetBtn.disabled = !shouldEnable || isProcessing || isSubmittingAction;
            }

            function setProcessButtonState(options = {}) {
                if (!processBtn) {
                    return;
                }
                const { loading = false, enabled = true } = options;
                if (loading) {
                    processBtn.disabled = true;
                    processBtn.classList.add('is-loading');
                    processBtn.setAttribute('aria-busy', 'true');
                    if (processBtnIcon) {
                        processBtnIcon.textContent = 'autorenew';
                    }
                    if (processBtnLabel) {
                        processBtnLabel.textContent = 'Se procesează...';
                    }
                } else {
                    processBtn.classList.remove('is-loading');
                    processBtn.removeAttribute('aria-busy');
                    if (processBtnIcon) {
                        processBtnIcon.textContent = 'auto_awesome';
                    }
                    if (processBtnLabel) {
                        processBtnLabel.textContent = 'Procesează Factură';
                    }
                    processBtn.disabled = !enabled;
                }
            }

            function setStatus(message, variant) {
                if (!statusEl) return;
                const baseClass = 'invoice-status';
                statusEl.textContent = message || '';
                statusEl.className = variant ? `${baseClass} invoice-status--${variant}` : baseClass;
                updateResetBtnState();
            }

            function clearFeedback() {
                if (!feedbackEl) return;
                feedbackEl.textContent = '';
                feedbackEl.className = 'invoice-feedback';
                updateResetBtnState();
            }

            function setFeedback(message, type) {
                if (!feedbackEl) return;
                feedbackEl.textContent = message || '';
                feedbackEl.className = message ? `invoice-feedback invoice-feedback--${type}` : 'invoice-feedback';
                updateResetBtnState();
            }

            function formatFileSize(bytes) {
                if (!Number.isFinite(bytes)) return '';
                if (bytes >= 1024 * 1024) {
                    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
                }
                if (bytes >= 1024) {
                    return `${(bytes / 1024).toFixed(1)} KB`;
                }
                return `${bytes} B`;
            }

            function formatQuantity(value) {
                const numeric = Number(value);
                if (Number.isFinite(numeric)) {
                    if (Number.isInteger(numeric)) {
                        return numeric.toLocaleString('ro-RO');
                    }
                    return numeric.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                return value !== undefined && value !== null ? String(value) : '-';
            }

            function formatPrice(value) {
                const numeric = Number(value);
                if (Number.isFinite(numeric)) {
                    return `${numeric.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} RON`;
                }
                return value !== undefined && value !== null && value !== '' ? String(value) : '-';
            }

            function clearResult() {
                if (resultSection) {
                    resultSection.hidden = true;
                }
                if (summaryContainer) {
                    summaryContainer.innerHTML = '';
                }
                if (supplierEl) {
                    supplierEl.textContent = '';
                }
                if (productsBody) {
                    productsBody.innerHTML = '';
                }
                actionButtons.forEach(function (button) {
                    button.disabled = true;
                });
                extractedData = null;
                productLocations = {};
                pendingAction = null;
                setStatus('', '');
                updateResetBtnState();
            }

            function clearFile() {
                selectedFile = null;
                if (fileInput) {
                    fileInput.value = '';
                }
                if (selectedFileContainer) {
                    selectedFileContainer.hidden = true;
                }
                if (selectedFileName) {
                    selectedFileName.textContent = '';
                }
                clearResult();
                setProcessButtonState({ loading: false, enabled: false });
                updateProcessHint();
                updateResetBtnState();
            }

            function isValidFile(file) {
                if (!file) return false;
                const allowedMime = ['application/pdf', 'image/jpeg', 'image/png'];
                if (file.type && allowedMime.includes(file.type)) {
                    return true;
                }
                let extension = '';
                if (file.name) {
                    const parts = file.name.split('.');
                    extension = parts.length > 1 ? parts.pop().toLowerCase() : '';
                }
                return ['pdf', 'jpg', 'jpeg', 'png'].includes(extension || '');
            }

            function handleSelectedFile(file) {
                if (!file) {
                    return;
                }
                if (!isValidFile(file)) {
                    setFeedback('Format de fișier neacceptat. Te rugăm să încarci un PDF sau o imagine (JPG, PNG).', 'error');
                    return;
                }
                clearFeedback();
                selectedFile = file;
                if (selectedFileContainer) {
                    selectedFileContainer.hidden = false;
                }
                if (selectedFileName) {
                    const sizeLabel = formatFileSize(file.size);
                    selectedFileName.textContent = sizeLabel ? `${file.name} (${sizeLabel})` : file.name;
                }
                if (processBtn) {
                    setProcessButtonState({ loading: false, enabled: true });
                }
                clearResult();
                setStatus('Fișier pregătit pentru procesare.', '');
                updateProcessHint();
                updateResetBtnState();
            }

            if (removeFileBtn) {
                removeFileBtn.addEventListener('click', function () {
                    clearFile();
                    clearFeedback();
                });
            }

            dropZone.addEventListener('click', function () {
                if (fileInput) {
                    fileInput.click();
                }
            });

            dropZone.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    if (fileInput) {
                        fileInput.click();
                    }
                }
            });

            ['dragenter', 'dragover'].forEach(function (eventName) {
                dropZone.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    dropZone.classList.add('dragover');
                });
            });

            ['dragleave', 'dragend'].forEach(function (eventName) {
                dropZone.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    dropZone.classList.remove('dragover');
                });
            });

            dropZone.addEventListener('drop', function (event) {
                event.preventDefault();
                dropZone.classList.remove('dragover');
                if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]) {
                    handleSelectedFile(event.dataTransfer.files[0]);
                }
            });

            if (fileInput) {
                fileInput.addEventListener('change', function (event) {
                    const file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
                    handleSelectedFile(file);
                });
            }

            if (resetBtn) {
                resetBtn.addEventListener('click', function () {
                    if (isProcessing || isSubmittingAction) {
                        return;
                    }
                    closeConfirmModal();
                    clearFeedback();
                    clearFile();
                    setStatus('', '');
                    updateProcessHint();
                    updateResetBtnState();
                    dropZone.focus();
                });
            }

            updateProcessHint();
            updateResetBtnState();
            setProcessButtonState({ loading: false, enabled: Boolean(selectedFile) });

            async function lookupProductLocations(products) {
                const skus = Array.from(new Set((products || []).map(function (product) {
                    return product && product.code ? String(product.code).trim() : '';
                }).filter(Boolean)));
                if (!skus.length) {
                    return {};
                }
                const formData = new FormData();
                formData.append('ajax_action', 'lookup_invoice_locations');
                formData.append('skus', JSON.stringify(skus));
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                }
                const response = await fetch('inventory.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });
                if (!response.ok) {
                    throw new Error('Nu s-au putut prelua locațiile produselor.');
                }
                const data = await response.json();
                if (!data || data.success === false) {
                    const errorMessage = data && data.message ? data.message : 'Nu s-au putut prelua locațiile produselor.';
                    throw new Error(errorMessage);
                }
                return data.locations || {};
            }

            function renderInvoice(data, locationsMap) {
                if (!resultSection) {
                    return;
                }

                resultSection.hidden = false;
                const info = data && data.invoice_info ? data.invoice_info : {};
                const products = Array.isArray(info.ordered_products) ? info.ordered_products : [];

                if (summaryContainer) {
                    summaryContainer.innerHTML = '';
                    const summaryItems = [
                        { label: 'Număr factură', value: info.invoice_number || 'N/A' },
                        { label: 'Data facturii', value: info.invoice_date || '-' },
                        { label: 'Total poziții', value: products.length }
                    ];
                    summaryItems.forEach(function (item) {
                        const wrapper = document.createElement('div');
                        wrapper.className = 'invoice-summary-item';

                        const label = document.createElement('span');
                        label.className = 'invoice-summary-label';
                        label.textContent = item.label;

                        const value = document.createElement('span');
                        value.className = 'invoice-summary-value';
                        value.textContent = item.value === 0 ? '0' : (item.value || '-');

                        wrapper.appendChild(label);
                        wrapper.appendChild(value);
                        summaryContainer.appendChild(wrapper);
                    });
                }

                if (supplierEl) {
                    supplierEl.textContent = info.seller_name ? `Furnizor: ${info.seller_name}` : '';
                }

                if (productsBody) {
                    productsBody.innerHTML = '';
                    products.forEach(function (product) {
                        const row = document.createElement('tr');

                        const skuCell = document.createElement('td');
                        const productCode = product && product.code ? product.code : '';
                        skuCell.textContent = productCode || '-';
                        row.appendChild(skuCell);

                        const nameCell = document.createElement('td');
                        nameCell.textContent = product && product.name ? product.name : '-';
                        row.appendChild(nameCell);

                        const quantityCell = document.createElement('td');
                        const qtyValue = document.createElement('span');
                        qtyValue.className = 'invoice-quantity';
                        const quantityValue = product && typeof product.quantity !== 'undefined' ? product.quantity : null;
                        qtyValue.textContent = formatQuantity(quantityValue);
                        quantityCell.appendChild(qtyValue);
                        if (product && product.unit) {
                            const unitSpan = document.createElement('span');
                            unitSpan.className = 'invoice-unit';
                            unitSpan.textContent = product.unit;
                            quantityCell.appendChild(unitSpan);
                        }
                        row.appendChild(quantityCell);

                        const priceCell = document.createElement('td');
                        const productPrice = product && typeof product.price !== 'undefined' ? product.price : null;
                        priceCell.textContent = formatPrice(productPrice);
                        row.appendChild(priceCell);

                        const locationCell = document.createElement('td');
                        const sku = productCode || '';
                        const locationData = sku && locationsMap ? locationsMap[sku] : null;
                        if (locationData && (locationData.location_code || locationData.location_name)) {
                            const locationText = document.createElement('div');
                            const code = locationData.location_code ? String(locationData.location_code) : '';
                            const name = locationData.location_name ? String(locationData.location_name) : '';
                            locationText.textContent = code && name ? `${code} – ${name}` : (code || name);
                            locationCell.appendChild(locationText);
                        } else {
                            const badge = document.createElement('span');
                            badge.className = 'invoice-tag invoice-tag--new';
                            badge.textContent = 'Produs nou - va fi adăugat';
                            locationCell.appendChild(badge);
                        }
                        row.appendChild(locationCell);

                        productsBody.appendChild(row);
                    });
                }

                actionButtons.forEach(function (button) {
                    button.disabled = products.length === 0;
                });
                updateResetBtnState();
            }

            async function processInvoice() {
                if (!selectedFile || !processBtn) {
                    return;
                }
                clearFeedback();
                isProcessing = true;
                updateResetBtnState();
                setStatus('AI procesează factura...', 'loading');
                setProcessButtonState({ loading: true });

                try {
                    const formData = new FormData();
                    formData.append('data', selectedFile);
                    const response = await fetch('https://wartung.app.n8n.cloud/webhook/0498a50b-9a65-440e-9e4e-16acbd38167e', {
                        method: 'POST',
                        body: formData
                    });
                    if (!response.ok) {
                        throw new Error('Nu am putut procesa factura. Încearcă din nou.');
                    }
                    const payload = await response.json();
                    if (!payload || !payload.invoice_info) {
                        throw new Error('Răspunsul AI nu conține informațiile necesare.');
                    }

                    const products = Array.isArray(payload.invoice_info.ordered_products) ? payload.invoice_info.ordered_products : [];
                    let locations = {};
                    try {
                        locations = await lookupProductLocations(products);
                    } catch (lookupError) {
                        console.warn(lookupError);
                        setFeedback('Factura a fost procesată, dar nu s-au putut încărca locațiile produselor.', 'warning');
                    }

                    extractedData = payload;
                    productLocations = locations || {};
                    renderInvoice(payload, productLocations);
                    setStatus('Procesarea a fost finalizată.', 'success');
                } catch (error) {
                    console.error(error);
                    clearResult();
                    setFeedback(error.message || 'A apărut o eroare la procesarea facturii.', 'error');
                } finally {
                    isProcessing = false;
                    setProcessButtonState({ loading: false, enabled: Boolean(selectedFile) });
                    updateResetBtnState();
                }
            }

            if (processBtn) {
                processBtn.addEventListener('click', function () {
                    if (!selectedFile) {
                        setFeedback('Încarcă mai întâi o factură pentru procesare.', 'error');
                        return;
                    }
                    processInvoice();
                });
            }

            function openConfirmModal(action) {
                if (!confirmModal) {
                    return;
                }
                const invoiceInfo = extractedData && extractedData.invoice_info ? extractedData.invoice_info : {};
                const invoiceNumber = invoiceInfo.invoice_number || 'fără număr';
                const productCount = Array.isArray(invoiceInfo.ordered_products) ? invoiceInfo.ordered_products.length : 0;
                const actionLabel = action === 'remove_stock' ? 'scăderea din stoc' : 'adăugarea în stoc';

                if (confirmMessage) {
                    confirmMessage.textContent = `Confirmi ${actionLabel} pentru ${productCount} produse din factura ${invoiceNumber}?`;
                }
                if (confirmBtn) {
                    confirmBtn.dataset.action = action;
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Confirmă';
                }
                confirmModal.classList.add('show');
                confirmModal.setAttribute('aria-hidden', 'false');
            }

            function closeConfirmModal() {
                if (!confirmModal) {
                    return;
                }
                confirmModal.classList.remove('show');
                confirmModal.setAttribute('aria-hidden', 'true');
                if (confirmBtn) {
                    confirmBtn.dataset.action = '';
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Confirmă';
                }
            }

            actionButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!extractedData) {
                        return;
                    }
                    const action = button.dataset.action === 'remove_stock' ? 'remove_stock' : 'add_stock';
                    pendingAction = action;
                    openConfirmModal(action);
                });
            });

            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () {
                    closeConfirmModal();
                });
            }

            if (closeBtn) {
                closeBtn.addEventListener('click', function () {
                    closeConfirmModal();
                });
            }

            if (confirmModal) {
                confirmModal.addEventListener('click', function (event) {
                    if (event.target === confirmModal) {
                        closeConfirmModal();
                    }
                });
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && confirmModal && confirmModal.classList.contains('show')) {
                    closeConfirmModal();
                }
            });

            async function submitInventoryAction(action) {
                if (!extractedData) {
                    return;
                }
                if (confirmBtn) {
                    confirmBtn.disabled = true;
                    confirmBtn.textContent = 'Se procesează...';
                }
                setStatus('Se aplică actualizarea de stoc...', 'loading');
                isSubmittingAction = true;
                updateResetBtnState();

                try {
                    const payload = {
                        json_data: extractedData ? JSON.stringify(extractedData) : '{}',
                        action: action === 'remove_stock' ? 'remove_stock' : 'add_stock',
                        source: 'ai_invoice_processing'
                    };
                    const response = await fetch('/api/webhook_process_import.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {})
                        },
                        body: JSON.stringify(payload)
                    });
                    const responseData = await response.json().catch(function () {
                        return null;
                    });
                    if (!response.ok || (responseData && responseData.success === false)) {
                        const responseMessage = responseData && responseData.message ? responseData.message : 'Actualizarea stocului a eșuat.';
                        throw new Error(responseMessage);
                    }
                    setFeedback('Stocul a fost actualizat cu succes!', 'success');
                    setStatus('', '');
                    closeConfirmModal();
                    resetAfterSuccess();
                } catch (error) {
                    console.error(error);
                    setFeedback(error.message || 'A apărut o eroare la actualizarea stocului.', 'error');
                } finally {
                    isSubmittingAction = false;
                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = 'Confirmă';
                    }
                    updateResetBtnState();
                }
            }

            function resetAfterSuccess() {
                clearFile();
            }

            if (confirmBtn) {
                confirmBtn.addEventListener('click', function () {
                    const action = confirmBtn.dataset.action;
                    if (!action) {
                        return;
                    }
                    submitInventoryAction(action);
                });
            }
        });
    </script>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
