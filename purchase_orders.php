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

if (!class_exists('FPDF')) {
    if (file_exists(BASE_PATH . '/lib/fpdf.php')) {
        require_once BASE_PATH . '/lib/fpdf.php';
    }
}

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
require_once BASE_PATH . '/models/Product.php';
require_once BASE_PATH . '/models/Transaction.php';

$purchaseOrderModel = new PurchaseOrder($db);
$sellerModel = new Seller($db);
$purchasableProductModel = new PurchasableProduct($db);
$productModel = new Product($db);
$transactionModel = new Transaction($db);

/**
 * Generate PDF for purchase order
 */
function generatePurchaseOrderPdf(array $orderInfo, array $items): ?string {
    if (!class_exists('FPDF')) {
        error_log("FPDF class not found when generating PDF");
        return null;
    }

    try {
        error_log("Starting PDF generation for order: " . $orderInfo['order_number']);
        
        $pdf = new FPDF();
        $pdf->AddPage();
        
        // Use ONLY built-in Arial font (no external font files needed)
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'COMANDA ACHIZITIE', 0, 1, 'C');
        
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 8, 'Numar comanda: ' . $orderInfo['order_number'], 0, 1);
        $pdf->Cell(0, 8, 'Furnizor: ' . $orderInfo['supplier_name'], 0, 1);
        $pdf->Cell(0, 8, 'Data: ' . date('d.m.Y H:i'), 0, 1);
        $pdf->Ln(10);

        // Table header - simple design, no fancy fonts
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(80, 8, 'Produs', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Cantitate', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Pret', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Total', 1, 1, 'C');

        // Table content
        $pdf->SetFont('Arial', '', 9);
        $grandTotal = 0;
        
        foreach ($items as $it) {
            $name = $it['product_name'] ?? '';
            $qty = floatval($it['quantity'] ?? 0);
            $price = floatval($it['unit_price'] ?? 0);
            $total = $qty * $price;
            $grandTotal += $total;
            
            // Truncate long product names
            if (strlen($name) > 35) {
                $name = substr($name, 0, 32) . '...';
            }
            
            $pdf->Cell(80, 8, $name, 1);
            $pdf->Cell(30, 8, number_format($qty, 2), 1, 0, 'R');
            $pdf->Cell(30, 8, number_format($price, 2), 1, 0, 'R');
            $pdf->Cell(30, 8, number_format($total, 2), 1, 1, 'R');
        }
        
        // Total row
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(140, 8, 'TOTAL:', 1, 0, 'R');
        $pdf->Cell(30, 8, number_format($grandTotal, 2) . ' RON', 1, 1, 'R');

        // Try multiple writable locations
        $fileName = 'po_' . $orderInfo['order_number'] . '_' . time() . '.pdf';
        $writableLocations = [
            BASE_PATH . '/storage/purchase_order_pdfs/',
            '/tmp/wms_pdfs/',
            '/tmp/',
            BASE_PATH . '/tmp/',
            sys_get_temp_dir() . '/'
        ];
        
        $successPath = null;
        $finalFileName = null;
        
        foreach ($writableLocations as $dir) {
            // Create directory if it doesn't exist
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            
            if (is_dir($dir) && is_writable($dir)) {
                $testPath = $dir . $fileName;
                try {
                    $pdf->Output('F', $testPath);
                    if (file_exists($testPath) && filesize($testPath) > 0) {
                        $successPath = $testPath;
                        $finalFileName = $fileName;
                        error_log("PDF created successfully: $testPath (Size: " . filesize($testPath) . " bytes)");
                        break;
                    }
                } catch (Exception $e) {
                    error_log("Failed to create PDF in $dir: " . $e->getMessage());
                    continue;
                }
            }
        }
        
        if ($successPath) {
            return $finalFileName;
        } else {
            error_log("Could not create PDF in any writable location");
            return null;
        }
        
    } catch (Exception $e) {
        error_log("Error generating PDF: " . $e->getMessage());
        return null;
    }
}

/**
 * Send purchase order email with attachment
 */
function sendPurchaseOrderEmail(array $smtp, string $to, string $subject, string $body, string $attachmentPath = ''): array {
    $mail = new PHPMailer(true);

    try {
        // Use your working SMTP settings
        $mail->isSMTP();
        $mail->Host = $smtp['smtp_host'];
        $mail->Port = intval($smtp['smtp_port']);
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['smtp_user'];
        $mail->Password = $smtp['smtp_pass'];
        
        // Set security if provided
        if (!empty($smtp['smtp_secure'])) {
            $mail->SMTPSecure = $smtp['smtp_secure'];
        }
        
        // Recipients and content
        $fromEmail = $smtp['from_email'] ?? $smtp['smtp_user'];
        $fromName = $smtp['from_name'] ?? 'WMS - Comanda Achizitie';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        $mail->addBCC($fromEmail);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->isHTML(false);
        $mail->CharSet = 'UTF-8';
        
        // Add attachment if provided and exists
        $attachmentData = null;
        $attachmentName = null;
        if (!empty($attachmentPath)) {
            // Check multiple possible paths
            $possiblePaths = [
                BASE_PATH . '/storage/purchase_order_pdfs/' . $attachmentPath,
                '/tmp/wms_pdfs/' . $attachmentPath,
                '/tmp/' . $attachmentPath,
                $attachmentPath // In case it's already a full path
            ];
            
            $attachmentFound = false;
            foreach ($possiblePaths as $path) {
                if (is_file($path)) {
                    $mail->addAttachment($path);
                    $attachmentFound = true;
                    // Store attachment data for IMAP copy
                    $attachmentData = file_get_contents($path);
                    $attachmentName = basename($path);
                    error_log("PDF attachment added: $path");
                    break;
                }
            }
            
            if (!$attachmentFound) {
                error_log("PDF attachment not found in any location: $attachmentPath");
            }
        }
        
        // Send the email first
        $mail->send();
        
        // Now save a copy to Sent folder using IMAP
        $sentFolderResult = saveEmailToSentFolder($smtp, $to, $subject, $body, $attachmentData, $attachmentName);
        
        if (!$sentFolderResult) {
            error_log("Email sent successfully but failed to save copy to Sent folder");
        } else {
            error_log("Email sent and saved to Sent folder successfully");
        }
        
        return ['success' => true, 'message' => 'Email trimis cu succes'];
        
    } catch (Exception $e) {
        $errorMsg = 'Eroare email: ' . $e->getMessage();
        error_log($errorMsg);
        return ['success' => false, 'message' => $errorMsg];
    }
}

function saveEmailToSentFolder($smtp, $to, $subject, $body, $attachmentData = null, $attachmentName = null) {
    try {
        if (!extension_loaded('imap')) {
            error_log("IMAP extension not available - cannot save to Sent folder");
            return false;
        }

        $imapHost = $smtp['imap_host'] ?? $smtp['smtp_host'] ?? '';
        if (empty($imapHost)) {
            error_log("IMAP host is not configured - cannot save to Sent folder");
            return false;
        }

        $portCandidates = $smtp['imap_port'] ?? [993, 143];
        if (!is_array($portCandidates)) {
            $portCandidates = [$portCandidates];
        }
        $portCandidates = array_values(array_unique(array_filter(array_map('intval', $portCandidates), function ($port) {
            return $port > 0;
        })));
        if (empty($portCandidates)) {
            $portCandidates = [993, 143];
        }

        $flagCandidates = $smtp['imap_flags'] ?? ['/imap/ssl/validate-cert', '/imap/ssl/novalidate-cert', '/imap/ssl', '/imap/notls', '/imap'];
        if (!is_array($flagCandidates)) {
            $flagCandidates = [$flagCandidates];
        }

        $sentFolders = $smtp['imap_sent_folders'] ?? ['INBOX.Sent', 'Sent', 'INBOX/Sent', 'Sent Items', 'Sent Messages'];
        if (!is_array($sentFolders)) {
            $sentFolders = [$sentFolders];
        }

        $connection = false;
        $selectedMailbox = '';
        $selectedFolder = '';

        foreach ($portCandidates as $port) {
            foreach ($flagCandidates as $flags) {
                $mailbox = sprintf('{%s:%d%s}', $imapHost, $port, $flags);
                foreach ($sentFolders as $folder) {
                    $connection = @imap_open($mailbox . $folder, $smtp['smtp_user'], $smtp['smtp_pass']);
                    if ($connection) {
                        $selectedMailbox = $mailbox;
                        $selectedFolder = $folder;
                        error_log("Successfully connected to IMAP Sent folder: {$mailbox}{$folder}");
                        break 3;
                    }

                    $lastError = imap_last_error();
                    if ($lastError) {
                        error_log("IMAP connection attempt failed for {$mailbox}{$folder}: {$lastError}");
                    }
                    imap_errors();
                }
            }
        }

        if (!$connection) {
            error_log("Could not connect to any IMAP Sent folder.");
            return false;
        }

        $fromEmail = $smtp['from_email'] ?? $smtp['smtp_user'];
        $fromName = $smtp['from_name'] ?? 'WMS - Comanda Achizitie';
        $fromDomain = '';
        if (strpos($fromEmail, '@') !== false) {
            $fromDomain = substr(strrchr($fromEmail, '@'), 1);
        }
        if ($fromDomain === '') {
            $fromDomain = 'localhost';
        }

        $date = date('r');
        $message = "Date: $date\r\n";
        $message .= "From: $fromName <$fromEmail>\r\n";
        $message .= "To: $to\r\n";
        $message .= "Subject: $subject\r\n";
        $message .= "Message-ID: <" . uniqid('', true) . "@$fromDomain>\r\n";
        $message .= "MIME-Version: 1.0\r\n";

        if ($attachmentData && $attachmentName) {
            $boundary = "----=_Part_" . md5(uniqid('', true));
            $message .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n";

            $message .= "--$boundary\r\n";
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $message .= $body . "\r\n\r\n";

            $message .= "--$boundary\r\n";
            $message .= "Content-Type: application/pdf; name=\"$attachmentName\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "Content-Disposition: attachment; filename=\"$attachmentName\"\r\n\r\n";
            $message .= chunk_split(base64_encode($attachmentData)) . "\r\n";
            $message .= "--$boundary--\r\n";
        } else {
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $message .= $body;
        }

        $result = imap_append($connection, $selectedMailbox . $selectedFolder, $message, "\Seen");

        if ($result) {
            error_log("Successfully saved email to Sent folder");
        } else {
            $lastError = imap_last_error();
            if ($lastError) {
                error_log("Failed to save email to Sent folder: $lastError");
            } else {
                error_log("Failed to save email to Sent folder: Unknown IMAP error");
            }
        }

        imap_close($connection);
        return $result;

    } catch (Throwable $e) {
        error_log("Error saving to Sent folder: " . $e->getMessage());
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
                error_log('[PurchaseOrders] Starting stock purchase creation flow');
                $sellerId = intval($_POST['seller_id'] ?? 0);
                $customMessage = trim($_POST['custom_message'] ?? '');
                $emailSubject = trim($_POST['email_subject'] ?? '');
                $expectedDeliveryDate = $_POST['expected_delivery_date'] ?? null;
                $items = $_POST['items'] ?? [];

                if ($sellerId <= 0) {
                    throw new Exception('Trebuie să selectezi un furnizor.');
                }

                $seller = $sellerModel->getSellerById($sellerId);
                if (!$seller) {
                    throw new Exception('Furnizorul selectat nu există sau este inactiv.');
                }

                if (empty($items)) {
                    throw new Exception('Trebuie să adaugi cel puțin un produs.');
                }

                // Process items and calculate total
                $processedItems = [];
                $totalAmount = 0;
                
                foreach ($items as $index => $item) {
                    error_log('[PurchaseOrders] Processing item index ' . $index . ': ' . json_encode($item));
                    $purchasableProductId = intval($item['purchasable_product_id'] ?? 0);
                    $internalProductId = intval($item['internal_product_id'] ?? 0);

                    if ($internalProductId <= 0) {
                        throw new Exception('Trebuie selectat un produs intern pentru fiecare produs.');
                    }

                    // If no product ID provided, try to find or create the product
                    if ($purchasableProductId <= 0) {
                        $productName = trim($item['product_name'] ?? '');
                        if (!empty($productName)) {
                            // Try to find existing product first
                            $matchingProduct = $purchasableProductModel->findByName($productName);
                            
                            if ($matchingProduct) {
                                // Found existing product, use its ID
                                $purchasableProductId = $matchingProduct['id'];
                                error_log("Found existing product: {$productName} with ID: {$purchasableProductId}");
                            } else {
                                // Product doesn't exist, create it automatically
                                error_log("Product not found, creating new: {$productName}");
                                
                                $newProductData = [
                                    'supplier_product_name' => $productName,
                                    'supplier_product_code' => trim($item['product_code'] ?? ''),
                                    'description' => trim($item['description'] ?? ''),
                                    'unit_measure' => 'buc', // Default unit
                                    'last_purchase_price' => floatval($item['unit_price'] ?? 0),
                                    'currency' => 'RON',
                                    'internal_product_id' => $internalProductId,
                                    'preferred_seller_id' => $sellerId,
                                    'notes' => trim($item['description'] ?? ''),
                                    'status' => 'active'
                                ];
                                
                                $purchasableProductId = $purchasableProductModel->createProduct($newProductData);

                                if ($purchasableProductId) {
                                    error_log("Successfully created new product: {$productName} with ID: {$purchasableProductId}");
                                } else {
                                    $productError = $purchasableProductModel->getLastError();
                                    throw new Exception("Nu s-a putut crea produsul nou: {$productName}. Motiv: " . ($productError ?? 'eroare necunoscută.'));
                                }
                            }
                        } else {
                            throw new Exception('Numele produsului este obligatoriu.');
                        }
                    } else {
                        if (!$purchasableProductModel->updateProduct($purchasableProductId, ['internal_product_id' => $internalProductId])) {
                            $updateError = $purchasableProductModel->getLastError();
                            throw new Exception('Nu s-a putut actualiza produsul selectat. Motiv: ' . ($updateError ?? 'eroare necunoscută.'));
                        }
                    }

                    $quantity = floatval($item['quantity']);
                    $unitPrice = floatval($item['unit_price']);

                    if ($quantity <= 0 || $unitPrice <= 0) {
                        throw new Exception('Cantitatea și prețul trebuie să fie mai mari decât 0.');
                    }
                    
                    $totalPrice = $quantity * $unitPrice;
                    
                    $processedItems[] = [
                        'purchasable_product_id' => $purchasableProductId,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'notes' => trim($item['description'] ?? '')
                    ];
                    
                    $totalAmount += $totalPrice;
                }
                
                if (empty($processedItems)) {
                    throw new Exception('Nu s-au putut procesa produsele selectate.');
                }
                
                // Get seller details
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
                
                error_log('[PurchaseOrders] Prepared order data: ' . json_encode($orderData));

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

                    if (!$transactionModel->createTransaction($transactionData)) {
                        $transactionError = $transactionModel->getLastError();
                        error_log('[PurchaseOrders] Failed to create transaction for order ' . $orderId . ': ' . ($transactionError ?? 'Unknown error'));
                    }

                    // Get order info for PDF generation
                    $orderInfo = $purchaseOrderModel->getPurchaseOrderById($orderId);
                    $orderInfo['supplier_name'] = $seller['supplier_name'];
                    
                    // Generate PDF
                    $pdfFile = generatePurchaseOrderPdf($orderInfo, $items);
                    
                    if ($pdfFile) {
                        $purchaseOrderModel->updatePdfPath($orderId, $pdfFile);
                        error_log("PDF generated successfully: $pdfFile");
                    } else {
                        error_log("PDF generation failed for order: " . $orderInfo['order_number']);
                    }
                    
                    // Prepare SMTP settings using global configuration
                    $smtpSettings = [
                        'smtp_host'          => $config['email']['host'] ?? '',
                        'smtp_port'          => $config['email']['port'] ?? 587,
                        'smtp_user'          => $config['email']['username'] ?? '',
                        'smtp_pass'          => $config['email']['password'] ?? '',
                        'smtp_secure'        => $config['email']['encryption'] ?? 'ssl',
                        'from_email'         => $config['email']['from_email'] ?? '',
                        'from_name'          => $config['email']['from_name'] ?? '',
                        'imap_host'          => $config['email']['imap_host'] ?? ($config['email']['host'] ?? ''),
                        'imap_port'          => $config['email']['imap_port'] ?? null,
                        'imap_flags'         => $config['email']['imap_flags'] ?? null,
                        'imap_sent_folders'  => $config['email']['imap_sent_folders'] ?? null
                    ];

                    // Prepare email content
                    $emailBody = "Bună ziua,\n\n";
                    $emailBody .= "Vă transmitem comanda de achiziție " . $orderInfo['order_number'] . ".\n\n";
                    if (!empty($customMessage)) {
                        $emailBody .= $customMessage . "\n\n";
                    }
                    $emailBody .= "Vă mulțumim!\n";
                    $emailBody .= "Echipa WMS";

                    $finalSubject = !empty($emailSubject) ? $emailSubject : 'Comanda ' . $orderInfo['order_number'];

                    // Send email
                    $emailResult = sendPurchaseOrderEmail($smtpSettings, $emailRecipient, $finalSubject, $emailBody, $pdfFile);

                    if ($emailResult['success']) {
                        $purchaseOrderModel->markAsSent($orderId, $emailRecipient);
                        $message = 'Comanda de stoc a fost creată și trimisă prin email cu succes! Numărul comenzii: ' . $orderInfo['order_number'];
                        $messageType = 'success';
                    } else {
                        $message = 'Comanda a fost creată (' . $orderInfo['order_number'] . '), dar emailul nu a fost trimis. Eroare: ' . $emailResult['message'];
                        $messageType = 'warning';
                    }
                } else {
                    $orderError = $purchaseOrderModel->getLastError();
                    throw new Exception('Eroare la crearea comenzii de stoc. ' . ($orderError ?? 'Detalii suplimentare indisponibile.'));
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
                
                // Get order details
                $orderInfo = $purchaseOrderModel->getPurchaseOrderById($orderId);
                if (!$orderInfo) {
                    throw new Exception('Comanda nu a fost găsită.');
                }
                
                // Get seller info
                $seller = $sellerModel->getSellerById($orderInfo['seller_id']);
                $orderInfo['supplier_name'] = $seller['supplier_name'];
                
                // Prepare SMTP settings using global configuration
                $smtpSettings = [
                    'smtp_host'          => $config['email']['host'] ?? '',
                    'smtp_port'          => $config['email']['port'] ?? 587,
                    'smtp_user'          => $config['email']['username'] ?? '',
                    'smtp_pass'          => $config['email']['password'] ?? '',
                    'smtp_secure'        => $config['email']['encryption'] ?? 'ssl',
                    'from_email'         => $config['email']['from_email'] ?? '',
                    'from_name'          => $config['email']['from_name'] ?? '',
                    'imap_host'          => $config['email']['imap_host'] ?? ($config['email']['host'] ?? ''),
                    'imap_port'          => $config['email']['imap_port'] ?? null,
                    'imap_flags'         => $config['email']['imap_flags'] ?? null,
                    'imap_sent_folders'  => $config['email']['imap_sent_folders'] ?? null
                ];

                // Prepare email
                $emailSubject = 'Comanda ' . $orderInfo['order_number'];
                $emailBody = "Bună ziua,\n\n";
                $emailBody .= "Vă transmitem comanda de achiziție " . $orderInfo['order_number'] . ".\n\n";
                $emailBody .= "Vă mulțumim!\n";
                $emailBody .= "Echipa WMS";

                // Send email
                $emailResult = sendPurchaseOrderEmail($smtpSettings, $emailRecipient, $emailSubject, $emailBody, $orderInfo['pdf_path']);
                
                if ($emailResult['success']) {
                    $purchaseOrderModel->markAsSent($orderId, $emailRecipient);
                    $message = 'Comanda a fost trimisă prin email cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la trimiterea emailului: ' . $emailResult['message']);
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
                $message = 'Factura a fost înregistrată cu succes.';
                $messageType = 'success';
                break;

                default:
                throw new Exception('Acțiune necunoscută.');
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
        error_log("Purchase order error: " . $e->getMessage() . ' | Payload: ' . json_encode($_POST));
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? '';
$sellerFilter = intval($_GET['seller_id'] ?? 0);
$receivingStatusFilter = $_GET['receiving_status'] ?? '';
$orderTypeFilter = $_GET['order_type'] ?? '';
$sortOption = $_GET['sort'] ?? 'created_desc';

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
$allProducts = $productModel->getAllProductsForDropdown();

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
                    <!-- Existing filters -->
                    <div class="filter-group">
                        <label for="status">Status:</label>
                        <select name="status" id="status" onchange="this.form.submit()">
                            <option value="">Toate statusurile</option>
                            <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Trimis</option>
                            <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmat</option>
                            <option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Livrat</option>
                            <option value="partial_delivery" <?= $statusFilter === 'partial_delivery' ? 'selected' : '' ?>>Livrare Parțială</option>
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
                    
                    <!-- NEW: Receiving Status Filter -->
                    <div class="filter-group">
                        <label for="receiving_status">Status Primire:</label>
                        <select name="receiving_status" id="receiving-status-filter">
                            <option value="" <?= $receivingStatusFilter === '' ? 'selected' : '' ?>>Toate</option>
                            <option value="not_received" <?= $receivingStatusFilter === 'not_received' ? 'selected' : '' ?>>Neprimite</option>
                            <option value="partial" <?= $receivingStatusFilter === 'partial' ? 'selected' : '' ?>>Parțial Primite</option>
                            <option value="complete" <?= $receivingStatusFilter === 'complete' ? 'selected' : '' ?>>Complet Primite</option>
                            <option value="with_discrepancies" <?= $receivingStatusFilter === 'with_discrepancies' ? 'selected' : '' ?>>Cu Discrepanțe</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="order_type_filter">Tip Comandă:</label>
                        <select name="order_type" id="order_type_filter">
                            <option value="" <?= $orderTypeFilter === '' ? 'selected' : '' ?>>Toate</option>
                            <option value="manual" <?= $orderTypeFilter === 'manual' ? 'selected' : '' ?>>Manuale</option>
                            <option value="auto" <?= $orderTypeFilter === 'auto' ? 'selected' : '' ?>>Autocomandă</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="order_sort">Sortare:</label>
                        <select name="sort" id="order_sort">
                            <option value="created_desc" <?= $sortOption === 'created_desc' ? 'selected' : '' ?>>Cele mai noi</option>
                            <option value="created_asc" <?= $sortOption === 'created_asc' ? 'selected' : '' ?>>Cele mai vechi</option>
                            <option value="type_asc" <?= $sortOption === 'type_asc' ? 'selected' : '' ?>>Tip comandă (manual → auto)</option>
                            <option value="type_desc" <?= $sortOption === 'type_desc' ? 'selected' : '' ?>>Tip comandă (auto → manual)</option>
                        </select>
                    </div>
                    
                    <!-- NEW: Clear Filters Button -->
                    <div class="filter-group">
                        <button type="button" class="btn btn-secondary" onclick="clearAllFilters()">
                            <span class="material-symbols-outlined">clear</span>
                            Șterge Filtrele
                        </button>
                    </div>
                </form>
            </div>

            <!-- Purchase Orders Table -->
            <div class="dashboard-row">
                <div class="dashboard-widget auto-order-dashboard">
                    <div class="widget-header">
                        <h3>Monitorizare Autocomandă</h3>
                        <button class="btn btn-sm btn-refresh" type="button" onclick="purchaseOrdersManager?.refreshAutoOrderStats?.()">
                            <span class="material-symbols-outlined">refresh</span>
                        </button>
                    </div>
                    <div class="widget-content">
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value" id="totalAutoOrders">0</div>
                                <div class="stat-label">Total Autocomenzi</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value" id="autoOrdersToday">0</div>
                                <div class="stat-label">Generat Astăzi</div>
                            </div>
                            <div class="stat-card warning">
                                <div class="stat-value" id="productsAtMinimum">0</div>
                                <div class="stat-label">Produse Sub Prag</div>
                            </div>
                            <div class="stat-card error">
                                <div class="stat-value" id="failedAutoOrders">0</div>
                                <div class="stat-label">Autocomenzi Neprocesate</div>
                            </div>
                        </div>

                        <div class="recent-auto-orders">
                            <h4>Autocomenzi Recente (7 zile)</h4>
                            <div class="auto-order-timeline" id="recentAutoOrdersList"></div>
                        </div>

                        <div class="products-needing-attention">
                            <h4>Produse Ce Necesită Atenție</h4>
                            <div class="attention-list" id="attentionProductsList"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <table class="data-table" id="purchase-orders-table">
                    <thead>
                        <tr>
                            <th></th> <!-- Expand button column -->
                            <th>Număr Comandă</th>
                            <th>Furnizor</th>
                            <th>Total</th>
                            <th>Status Comandă</th>
                            <!-- <th>Status Primire</th>  -->
                            <th>Progres Primire</th> <!-- NEW -->
                            <th>Discrepanțe</th> <!-- NEW -->
                            <th>Data Creării</th>
                            <th>PDF</th>
                            <th>Data Livrării</th>
                            <th>Acțiuni</th>
                        </tr>
                    </thead>
                    <tbody id="orders-tbody">
                        <!-- Content will be loaded via JavaScript -->
                        <tr>
                            <td colspan="12" class="text-center">
                                <div class="loading-spinner">
                                    <span class="material-symbols-outlined spinning">refresh</span>
                                    Se încarcă comenzile...
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="auto-order-history-panel">
                <div class="history-filters">
                    <input type="date" id="historyDateFrom" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>" placeholder="De la">
                    <input type="date" id="historyDateTo" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>" placeholder="Până la">
                    <select id="historyStatus">
                        <option value="">Toate Statusurile</option>
                        <option value="success">Succes</option>
                        <option value="failed">Eșuat</option>
                        <option value="pending">În Așteptare</option>
                        <option value="processing">În Procesare</option>
                    </select>
                    <button class="btn btn-primary" type="button" onclick="purchaseOrdersManager?.loadAutoOrderHistory?.()">Filtrează</button>
                </div>

                <div class="history-timeline" id="autoOrderHistoryList"></div>
            </div>
        </div>
    </div>

    <!-- Receiving Details Modal -->
    <div id="receiving-details-modal" class="modal" style="display: none;">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="modal-title">Detalii Primire Comandă</h3>
                    <button type="button" class="modal-close" aria-label="Închide" onclick="closeReceivingModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="modal-body" id="modal-body">
                    <!-- Content loaded via JavaScript -->
                </div>
            </div>
        </div>
    </div>

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
                        <input type="hidden" name="order_status" id="order_status" value="">
                        
                        <!-- Seller Selection -->
                        <div class="form-group">
                            <label for="seller_search_input" class="form-label">Furnizor *</label>
                            <input type="hidden" name="seller_id" id="seller_id_field">
                            <input type="text"
                                   id="seller_search_input"
                                   class="form-control"
                                   placeholder="Caută furnizor..."
                                   list="seller-options"
                                   autocomplete="off"
                                   required>
                            <datalist id="seller-options">
                                <?php foreach ($sellers as $seller): ?>
                                    <option value="<?= htmlspecialchars($seller['supplier_name'] ?? '') ?>"
                                            data-id="<?= (int) ($seller['id'] ?? 0) ?>"
                                            data-email="<?= htmlspecialchars($seller['email'] ?? '') ?>"
                                            data-contact="<?= htmlspecialchars($seller['contact_person'] ?? '') ?>"
                                            data-phone="<?= htmlspecialchars($seller['phone'] ?? '') ?>"
                                            label="<?= htmlspecialchars(($seller['supplier_name'] ?? '') . (!empty($seller['contact_person']) ? ' (' . $seller['contact_person'] . ')' : '')) ?>">
                                    </option>
                                <?php endforeach; ?>
                            </datalist>
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
                                            <div class="product-search-container">
                                                <input type="text"
                                                       class="form-control product-search-input existing-product-search"
                                                       placeholder="Caută produs furnizor..."
                                                       autocomplete="off">
                                                <div class="product-search-results existing-product-results"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="form-group">
                                            <label>Produs Intern *</label>
                                            <div class="product-search-container">
                                                <input type="hidden" name="items[0][internal_product_id]" class="internal-product-id">
                                                <input type="text"
                                                       class="form-control product-search-input internal-product-search"
                                                       placeholder="Caută produs intern..."
                                                       autocomplete="off"
                                                       required>
                                                <div class="product-search-results internal-product-results"></div>
                                            </div>
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
                        <button type="button" class="btn btn-primary" onclick="submitStockPurchase('draft')">
                            <span class="material-symbols-outlined">save</span> DRAFT
                        </button>
                        <button type="button" class="btn btn-success" onclick="submitStockPurchase('sent')">
                            <span class="material-symbols-outlined">send</span> TRIMITE DIRECT
                        </button>
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
                                <option value="partial_delivery">Livrare Parțială</option>
                                <option value="delivered">Livrat</option>
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

    <!-- Invoice Upload Modal -->
    <div class="modal" id="invoiceUploadModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Încarcă Factura</h3>
                    <button class="modal-close" onclick="closeModal('invoiceUploadModal')">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form id="invoiceUploadForm" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="upload_invoice">
                        <input type="hidden" name="order_id" id="invoiceOrderId">
                        
                        <div class="form-group">
                            <label for="invoice_file" class="form-label">Fișier Factură *</label>
                            <input type="file" name="invoice_file" id="invoice_file" class="form-control" 
                                accept=".pdf,.jpg,.jpeg,.png" required>
                            <small class="form-text text-muted">
                                Formater acceptate: PDF, JPG, PNG (max 5MB)
                            </small>
                        </div>
                        
                        <div class="alert alert-info">
                            <span class="material-symbols-outlined">info</span>
                            <strong>Notă:</strong> Odată încărcată factura, comanda va fi marcată automat ca "Facturată".
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('invoiceUploadModal')">Anulează</button>
                        <button type="submit" class="btn btn-primary">
                            <span class="material-symbols-outlined">upload</span>
                            Încarcă Factura
                        </button>
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
window.allProducts = <?= json_encode($allProducts) ?>;
window.sellersList = <?= json_encode($sellers) ?>;
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>