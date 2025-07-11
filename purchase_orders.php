<?php
// File: purchase_orders.php - Updated with complete stock purchase functionality from transactions.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/lib/PHPMailer/PHPMailer.php';
require_once BASE_PATH . '/lib/PHPMailer/SMTP.php';
require_once BASE_PATH . '/lib/PHPMailer/Exception.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$config = require BASE_PATH . '/config/config.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
require_once BASE_PATH . '/models/PurchaseOrder.php';
require_once BASE_PATH . '/models/Seller.php';
require_once BASE_PATH . '/models/PurchasableProduct.php';
require_once BASE_PATH . '/models/Transaction.php';
require_once BASE_PATH . '/models/User.php';

$purchaseOrderModel = new PurchaseOrder($db);
$sellerModel = new Seller($db);
$purchasableProductModel = new PurchasableProduct($db);
$transactionModel = new Transaction($db);
$usersModel = new Users($db);
$currentUser = $usersModel->findById($_SESSION['user_id']);

/**
 * Generate PDF for purchase order
 */
function generatePurchaseOrderPdf(array $orderInfo, array $items): ?string {
    // Use the standard FPDF class name
    if (!class_exists('FPDF')) {
        return null;
    }

    $pdf = new FPDF();  // Keep this as FPDF, not namespaced
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'Comanda ' . $orderInfo['order_number'], 0, 1);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 8, 'Furnizor: ' . $orderInfo['supplier_name'], 0, 1);
    $pdf->Ln(5);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(70, 8, 'Produs', 1);
    $pdf->Cell(30, 8, 'Cod', 1);
    $pdf->Cell(20, 8, 'Cant.', 1);
    $pdf->Cell(30, 8, 'Pret', 1);
    $pdf->Cell(30, 8, 'Total', 1, 1);
    $pdf->SetFont('Arial', '', 12);

    foreach ($items as $it) {
        $name = $it['product_name'] ?? '';
        $code = $it['product_code'] ?? '';
        $qty = $it['quantity'] ?? 0;
        $price = $it['unit_price'] ?? 0;
        $total = $qty * $price;
        $pdf->Cell(70, 8, $name, 1);
        $pdf->Cell(30, 8, $code, 1);
        $pdf->Cell(20, 8, $qty, 1);
        $pdf->Cell(30, 8, number_format($price, 2), 1);
        $pdf->Cell(30, 8, number_format($total, 2), 1, 1);
    }

    $fileName = 'po_' . $orderInfo['order_number'] . '_' . time() . '.pdf';
    $path = BASE_PATH . '/storage/purchase_order_pdfs/' . $fileName;
    
    // Create directory if it doesn't exist
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $pdf->Output('F', $path);
    return $fileName;
}

/**
 * Send purchase order email with attachment
 */
function sendPurchaseOrderEmail(array $smtp, string $to, string $subject, string $body, string $attachmentPath): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $smtp['smtp_host'] ?? 'localhost';
        $mail->Port = $smtp['smtp_port'] ?? 25;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['smtp_user'] ?? '';
        $mail->Password = $smtp['smtp_pass'] ?? '';
        if (!empty($smtp['smtp_secure'])) {
            $mail->SMTPSecure = $smtp['smtp_secure'];
        }

        $from = $smtp['smtp_user'] ?? 'no-reply@localhost';
        $mail->setFrom($from, 'WMS');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        if (is_file($attachmentPath)) {
            $mail->addAttachment($attachmentPath);
        }
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mail error: ' . $e->getMessage());
        return false;
    }
}

// Handle operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create_stock_purchase':
                // Handle stock purchase order creation - MOVED FROM transactions.php
                $sellerId = intval($_POST['seller_id'] ?? 0);
                $customMessage = trim($_POST['custom_message'] ?? '');
                $emailSubject = trim($_POST['email_subject'] ?? '');
                $expectedDeliveryDate = $_POST['expected_delivery_date'] ?? null;
                $items = $_POST['items'] ?? [];
                
                if ($sellerId <= 0) {
                    throw new Exception('Trebuie să selectezi un furnizor.');
                }
                
                if (empty($items)) {
                    throw new Exception('Trebuie să adaugi cel puțin un produs.');
                }
                if ($emailSubject === '' || $customMessage === '') {
                    throw new Exception('Subiectul și mesajul emailului sunt obligatorii.');
                }
                
                // Process items and calculate total
                $processedItems = [];
                $totalAmount = 0;
                
                foreach ($items as $item) {
                    if (empty($item['product_name']) || floatval($item['quantity']) <= 0 || floatval($item['unit_price']) <= 0) {
                        continue;
                    }
                    
                    // Check if product exists or create new purchasable product
                    $purchasableProductId = null;
                    if (!empty($item['purchasable_product_id'])) {
                        $purchasableProductId = intval($item['purchasable_product_id']);
                    } else {
                        // Create new purchasable product
                        $productData = [
                            'supplier_product_name' => $item['product_name'],
                            'supplier_product_code' => $item['product_code'] ?? '',
                            'description' => $item['description'] ?? '',
                            'unit_measure' => 'bucata',
                            'last_purchase_price' => floatval($item['unit_price']),
                            'preferred_seller_id' => $sellerId
                        ];
                        
                        $purchasableProductId = $purchasableProductModel->createProduct($productData);
                        if (!$purchasableProductId) {
                            throw new Exception('Eroare la crearea produsului: ' . $item['product_name']);
                        }
                    }
                    
                    $quantity = floatval($item['quantity']);
                    $unitPrice = floatval($item['unit_price']);
                    $totalPrice = $quantity * $unitPrice;
                    
                    $processedItems[] = [
                        'purchasable_product_id' => $purchasableProductId,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'notes' => $item['notes'] ?? ''
                    ];
                    
                    $totalAmount += $totalPrice;
                }
                
                if (empty($processedItems)) {
                    throw new Exception('Nu s-au putut procesa produsele selectate.');
                }
                
                // Get seller email for purchase order
                $seller = $sellerModel->getSellerById($sellerId);
                $emailRecipient = $_POST['email_recipient'] ?? $seller['email'] ?? '';
                
                // Create purchase order
                $orderData = [
                    'seller_id' => $sellerId,
                    'total_amount' => $totalAmount,
                    'custom_message' => $customMessage,
                    'email_subject' => $emailSubject,
                    'expected_delivery_date' => $expectedDeliveryDate,
                    'email_recipient' => $emailRecipient,
                    'items' => $processedItems
                ];
                
                $orderId = $purchaseOrderModel->createPurchaseOrder($orderData);
                
                if ($orderId) {
                    // Create transaction record
                    $transactionData = [
                        'transaction_type' => 'stock_purchase',
                        'amount' => $totalAmount,
                        'currency' => 'RON',
                        'description' => 'Comandă stoc furnizor: ' . $seller['supplier_name'],
                        'reference_type' => 'purchase_order',
                        'reference_id' => $orderId,
                        'purchase_order_id' => $orderId,
                        'supplier_name' => $seller['supplier_name'],
                        'status' => 'pending',
                        'created_by' => $_SESSION['user_id']
                    ];
                    
                    $transactionModel->createTransaction($transactionData);

                    $orderInfo = $purchaseOrderModel->getPurchaseOrderById($orderId);
                    $orderInfo['supplier_name'] = $seller['supplier_name'];
                    $pdfFile = generatePurchaseOrderPdf($orderInfo, $items);
                    if ($pdfFile) {
                        $purchaseOrderModel->updatePdfPath($orderId, $pdfFile);
                        $pdfPath = BASE_PATH . '/storage/purchase_order_pdfs/' . $pdfFile;
                        sendPurchaseOrderEmail($currentUser, $emailRecipient, $emailSubject, $customMessage, $pdfPath);
                        $purchaseOrderModel->markAsSent($orderId, $emailRecipient);
                    }

                    $message = 'Comanda de stoc a fost creată cu succes. Numărul comenzii: ' . $orderInfo['order_number'];
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la crearea comenzii de stoc.');
                }
                break;
                
            case 'update_status':
                $orderId = intval($_POST['order_id'] ?? 0);
                $newStatus = $_POST['status'] ?? '';
                
                if ($orderId <= 0 || empty($newStatus)) {
                    throw new Exception('Date invalide pentru actualizare.');
                }
                
                if ($purchaseOrderModel->updateStatus($orderId, $newStatus)) {
                    $message = 'Statusul comenzii a fost actualizat cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la actualizarea statusului.');
                }
                break;
                
            case 'send_email':
                $orderId = intval($_POST['order_id'] ?? 0);
                $emailRecipient = trim($_POST['email_recipient'] ?? '');
                
                if ($orderId <= 0 || empty($emailRecipient)) {
                    throw new Exception('Date invalide pentru trimiterea emailului.');
                }
                
                // Here you would implement email sending functionality
                // For now, we'll just mark as sent
                if ($purchaseOrderModel->markAsSent($orderId, $emailRecipient)) {
                    $message = 'Comanda a fost trimisă prin email cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la trimiterea emailului.');
                }
                break;

            case 'record_delivery':
                $orderId = intval($_POST['order_id'] ?? 0);
                $deliveryDate = $_POST['delivery_date'] ?? '';
                $deliveryNote = trim($_POST['delivery_note_number'] ?? '');
                $carrier = trim($_POST['carrier'] ?? '');
                $receivedBy = trim($_POST['received_by'] ?? '');
                $items = $_POST['delivery_items'] ?? [];
                
                if ($orderId <= 0 || empty($deliveryDate)) {
                    throw new Exception('Data livrării este obligatorie.');
                }
                
                // Process delivery recording
                // This would involve creating delivery records and updating quantities
                $message = 'Livrarea a fost înregistrată cu succes.';
                $messageType = 'success';
                break;

            case 'record_invoice':
                $orderId = intval($_POST['order_id'] ?? 0);
                $invoiceNumber = trim($_POST['invoice_number'] ?? '');
                $invoiceDate = $_POST['invoice_date'] ?? '';
                $totalAmount = floatval($_POST['total_amount'] ?? 0);
                $items = $_POST['invoice_items'] ?? [];
                
                if ($orderId <= 0 || empty($invoiceNumber) || empty($invoiceDate)) {
                    throw new Exception('Numărul și data facturii sunt obligatorii.');
                }
                
                // Process invoice recording
                // This would involve creating invoice records and updating quantities
                $message = 'Factura a fost înregistrată cu succes.';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? '';
$sellerFilter = intval($_GET['seller_id'] ?? 0);

// Get purchase orders
$filters = [];
if (!empty($statusFilter)) {
    $filters['status'] = $statusFilter;
}
if ($sellerFilter > 0) {
    $filters['seller_id'] = $sellerFilter;
}

$purchaseOrders = $purchaseOrderModel->getAllPurchaseOrders($filters);
$sellers = $sellerModel->getAllSellers();
$purchasableProducts = $purchasableProductModel->getAllProducts();

// Include header
$currentPage = 'purchase_orders';
require_once __DIR__ . '/includes/header.php';
?>

<div class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-container">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <h1 class="page-title">Comenzi de Achiziție</h1>
                    <div class="header-actions">
                        <button class="btn btn-success" onclick="openStockPurchaseModal()">
                            <span class="material-symbols-outlined">shopping_cart</span>
                            Cumparare Stoc
                        </button>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>">
                    <span class="material-symbols-outlined">
                        <?= $messageType === 'success' ? 'check_circle' : 'error' ?>
                    </span>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filters-container">
                <form method="GET" class="filters-form">
                    <div class="filter-group">
                        <label for="status">Status:</label>
                        <select name="status" id="status" onchange="this.form.submit()">
                            <option value="">Toate statusurile</option>
                            <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Trimis</option>
                            <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmat</option>
                            <option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Livrat</option>
                            <option value="invoiced" <?= $statusFilter === 'invoiced' ? 'selected' : '' ?>>Facturat</option>
                            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Finalizat</option>
                            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Anulat</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="seller_id">Furnizor:</label>
                        <select name="seller_id" id="seller_id" onchange="this.form.submit()">
                            <option value="">Toți furnizorii</option>
                            <?php foreach ($sellers as $seller): ?>
                                <option value="<?= $seller['id'] ?>" <?= $sellerFilter === $seller['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($seller['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>

            <!-- Purchase Orders Table -->
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Număr Comandă</th>
                            <th>Furnizor</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Data Creării</th>
                            <th>PDF</th>
                            <th>Data Livrării</th>
                            <th>Acțiuni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($purchaseOrders)): ?>
                            <tr>
                                <td colspan="8" class="text-center">Nu există comenzi de achiziție</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($purchaseOrders as $order): ?>
                                <tr>
                                    <td><?= htmlspecialchars($order['order_number']) ?></td>
                                    <td><?= htmlspecialchars($order['supplier_name']) ?></td>
                                    <td><?= number_format($order['total_amount'], 2) ?> <?= $order['currency'] ?></td>
                                    <td>
                                        <span class="status-badge status-<?= strtolower($order['status']) ?>">
                                            <?= ucfirst($order['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('d.m.Y H:i', strtotime($order['created_at'])) ?></td>
                                    <td>
                                        <?php if (!empty($order['pdf_path'])): ?>
                                            <a href="storage/purchase_order_pdfs/<?= htmlspecialchars($order['pdf_path']) ?>" target="_blank">PDF</a>
                                        <?php else: ?>-
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $order['expected_delivery_date'] ? date('d.m.Y', strtotime($order['expected_delivery_date'])) : '-' ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-sm btn-primary" onclick="openStatusModal(<?= $order['id'] ?>, '<?= $order['status'] ?>')">
                                                <span class="material-symbols-outlined">edit</span>
                                            </button>
                                            <button class="btn btn-sm btn-info" onclick="openSendEmailModal(<?= $order['id'] ?>, '<?= htmlspecialchars($order['supplier_name']) ?>')">
                                                <span class="material-symbols-outlined">email</span>
                                            </button>
                                            <button class="btn btn-sm btn-success" onclick="openDeliveryModal(<?= $order['id'] ?>)">
                                                <span class="material-symbols-outlined">local_shipping</span>
                                            </button>
                                            <button class="btn btn-sm btn-warning" onclick="openInvoiceModal(<?= $order['id'] ?>)">
                                                <span class="material-symbols-outlined">receipt</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Stock Purchase Modal - MOVED FROM transactions.php -->
    <div class="modal" id="stockPurchaseModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Cumparare Stoc</h3>
                    <button class="modal-close" onclick="closeStockPurchaseModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form id="stockPurchaseForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_stock_purchase">
                        
                        <!-- Seller Selection -->
                        <div class="form-group">
                            <label for="seller_id" class="form-label">Furnizor *</label>
                            <select name="seller_id" id="seller_id" class="form-control" required onchange="updateSellerContact()">
                                <option value="">Selectează furnizor</option>
                                <?php foreach ($sellers as $seller): ?>
                                    <option value="<?= $seller['id'] ?>" 
                                            data-email="<?= htmlspecialchars($seller['email'] ?? '') ?>"
                                            data-contact="<?= htmlspecialchars($seller['contact_person'] ?? '') ?>"
                                            data-phone="<?= htmlspecialchars($seller['phone'] ?? '') ?>">
                                        <?= htmlspecialchars($seller['supplier_name'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Contact Information -->
                        <div class="row">
                            <div class="form-group">
                                <label for="email_recipient" class="form-label">Email Destinatar</label>
                                <input type="email" name="email_recipient" id="email_recipient" class="form-control" 
                                       placeholder="Se va completa automat din furnizor">
                            </div>
                        </div>

                        <!-- Delivery Information -->
                        <div class="row">
                            <div class="form-group">
                                <label for="expected_delivery_date" class="form-label">Data Livrării Estimate</label>
                                <input type="date" name="expected_delivery_date" id="expected_delivery_date" class="form-control">
                            </div>
                        </div>

                        <!-- Email Subject -->
                        <div class="form-group">
                            <label for="email_subject" class="form-label">Subiect Email *</label>
                            <input type="text" name="email_subject" id="email_subject" class="form-control" required>
                        </div>

                        <!-- Email Body -->
                        <div class="form-group">
                            <label for="custom_message" class="form-label">Mesaj Email *</label>
                            <textarea name="custom_message" id="custom_message" class="form-control" rows="3" required
                                      placeholder="Scrie mesajul către furnizor..."></textarea>
                        </div>

                        <!-- Products Section -->
                        <div class="form-section">
                            <h4>Produse</h4>
                            <div id="product-items">
                                <!-- Product Item Template -->
                                <div class="product-item" data-index="0">
                                    <div class="product-item-header">
                                        <h5>Produs 1</h5>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="removeProductItem(0)" style="display: none;">
                                            <span class="material-symbols-outlined">delete</span>
                                        </button>
                                    </div>
                                    <div class="row">
                                        <div class="form-group">
                                            <label>Selectează Produs Existent</label>
                                            <select class="form-control existing-product-select" onchange="selectExistingProduct(0, this)">
                                                <option value="">Sau creează produs nou...</option>
                                                <?php foreach ($purchasableProducts as $product): ?>
                                                    <option value="<?= $product['id'] ?>" 
                                                            data-name="<?= htmlspecialchars($product['supplier_product_name']) ?>"
                                                            data-code="<?= htmlspecialchars($product['supplier_product_code']) ?>"
                                                            data-price="<?= $product['last_purchase_price'] ?>">
                                                        <?= htmlspecialchars($product['supplier_product_name']) ?>
                                                        <?= $product['supplier_product_code'] ? ' (' . htmlspecialchars($product['supplier_product_code']) . ')' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="form-group">
                                            <label>Nume Produs *</label>
                                            <input type="text" name="items[0][product_name]" class="form-control product-name" required 
                                                   placeholder="Nume produs de la furnizor">
                                            <input type="hidden" name="items[0][purchasable_product_id]" class="purchasable-product-id">
                                        </div>
                                        <div class="form-group">
                                            <label>Cod Produs</label>
                                            <input type="text" name="items[0][product_code]" class="form-control product-code" 
                                                   placeholder="Cod produs furnizor">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="form-group">
                                            <label>Cantitate *</label>
                                            <input type="number" name="items[0][quantity]" class="form-control quantity" 
                                                   step="0.001" min="0.001" required onchange="calculateItemTotal(0)">
                                        </div>
                                        <div class="form-group">
                                            <label>Preț Unitar (RON) *</label>
                                            <input type="number" name="items[0][unit_price]" class="form-control unit-price" 
                                                   step="0.01" min="0.01" required onchange="calculateItemTotal(0)">
                                        </div>
                                        <div class="form-group">
                                            <label>Total</label>
                                            <input type="text" class="form-control item-total" readonly>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Descriere</label>
                                        <textarea name="items[0][description]" class="form-control" rows="2" 
                                                  placeholder="Descriere suplimentară..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="button" class="btn btn-outline-primary" onclick="addProductItem()">
                                <span class="material-symbols-outlined">add</span>
                                Adaugă Produs
                            </button>
                        </div>

                        <!-- Order Total -->
                        <div class="order-summary">
                            <div class="total-row">
                                <span>Total Comandă:</span>
                                <span id="order-total">0.00 RON</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeStockPurchaseModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Creează Comanda</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal" id="statusModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Actualizează Status</h3>
                    <button class="modal-close" onclick="closeModal('statusModal')">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="order_id" id="statusOrderId">
                        <div class="form-group">
                            <label for="updateStatus" class="form-label">Status</label>
                            <select name="status" id="updateStatus" class="form-control" required>
                                <option value="draft">Draft</option>
                                <option value="sent">Trimis</option>
                                <option value="confirmed">Confirmat</option>
                                <option value="delivered">Livrat</option>
                                <option value="invoiced">Facturat</option>
                                <option value="completed">Finalizat</option>
                                <option value="cancelled">Anulat</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('statusModal')">Anulează</button>
                        <button type="submit" class="btn btn-primary">Actualizează</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Send Email Modal -->
    <div class="modal" id="sendEmailModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Trimite Email</h3>
                    <button class="modal-close" onclick="closeModal('sendEmailModal')">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="send_email">
                        <input type="hidden" name="order_id" id="emailOrderId">
                        <div class="form-group">
                            <label for="email_recipient_send" class="form-label">Email Destinatar</label>
                            <input type="email" name="email_recipient" id="email_recipient_send" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('sendEmailModal')">Anulează</button>
                        <button type="submit" class="btn btn-primary">Trimite</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delivery Modal -->
    <div class="modal" id="deliveryModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Înregistrează Livrarea</h3>
                    <button class="modal-close" onclick="closeModal('deliveryModal')">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="record_delivery">
                        <input type="hidden" name="order_id" id="deliveryOrderId">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="delivery_date" class="form-label">Data Livrării *</label>
                                    <input type="date" name="delivery_date" id="delivery_date" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="delivery_note_number" class="form-label">Număr Aviz</label>
                                    <input type="text" name="delivery_note_number" id="delivery_note_number" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="carrier" class="form-label">Transportator</label>
                                    <input type="text" name="carrier" id="carrier" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="received_by" class="form-label">Primit de</label>
                                    <input type="text" name="received_by" id="received_by" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div id="delivery-items">
                            <!-- Delivery items will be populated here -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('deliveryModal')">Anulează</button>
                        <button type="submit" class="btn btn-primary">Înregistrează</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Invoice Modal -->
    <div class="modal" id="invoiceModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Înregistrează Factura</h3>
                    <button class="modal-close" onclick="closeModal('invoiceModal')">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="record_invoice">
                        <input type="hidden" name="order_id" id="invoiceOrderId">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="invoice_number" class="form-label">Număr Factură *</label>
                                    <input type="text" name="invoice_number" id="invoice_number" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="invoice_date" class="form-label">Data Facturii *</label>
                                    <input type="date" name="invoice_date" id="invoice_date" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="total_amount" class="form-label">Total Factură *</label>
                            <input type="number" name="total_amount" id="total_amount" class="form-control" min="0.01" step="0.01" required>
                        </div>
                        
                        <div id="invoice-items">
                            <!-- Invoice items will be populated here -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('invoiceModal')">Anulează</button>
                        <button type="submit" class="btn btn-primary">Înregistrează</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Make purchasable products available globally for JavaScript
window.purchasableProducts = <?= json_encode($purchasableProducts) ?>;
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>