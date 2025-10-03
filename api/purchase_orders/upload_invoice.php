<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodă neacceptată.']);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acces interzis.']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configurare bază de date invalidă.']);
    exit;
}

$db = $config['connection_factory']();

try {
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('ID-ul comenzii nu este valid.');
    }

    if (!isset($_FILES['invoice_file']) || !is_array($_FILES['invoice_file'])) {
        throw new RuntimeException('Fișierul factură este necesar.');
    }

    $file = $_FILES['invoice_file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Încărcarea fișierului a eșuat.');
    }

    $allowedMime = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    if (!in_array($file['type'], $allowedMime, true)) {
        throw new RuntimeException('Formatul fișierului nu este acceptat (PDF, JPG, PNG).');
    }

    $maxSize = 5 * 1024 * 1024; // 5MB
    if (($file['size'] ?? 0) > $maxSize) {
        throw new RuntimeException('Fișierul depășește dimensiunea maximă de 5MB.');
    }

    $stmt = $db->prepare('SELECT id, invoice_file_path, status FROM purchase_orders WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('Comanda de achiziție nu a fost găsită.');
    }

    $uploadDir = BASE_PATH . '/storage/invoices/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Nu se poate crea directorul pentru facturi.');
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = sprintf('invoice_%d_%s.%s', $orderId, date('Ymd_His'), $extension);
    $destinationPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
        throw new RuntimeException('Salvarea fișierului a eșuat.');
    }

    $relativePath = 'invoices/' . $filename;

    $db->beginTransaction();

    $updateSql = "
        UPDATE purchase_orders
        SET invoice_file_path = :path,
            invoiced = 1,
            invoiced_at = NOW(),
            invoice_verified = 0,
            invoice_verified_by = NULL,
            invoice_verified_at = NULL,
            status = CASE
                WHEN status IN ('draft', 'sent') THEN 'confirmed'
                ELSE status
            END,
            updated_at = NOW()
        WHERE id = :id
    ";

    $updateStmt = $db->prepare($updateSql);
    $updateStmt->execute([
        ':path' => $relativePath,
        ':id' => $orderId,
    ]);

    $db->commit();

    if (!empty($order['invoice_file_path'])) {
        $oldPath = BASE_PATH . '/' . ltrim($order['invoice_file_path'], '/');
        if (strpos($order['invoice_file_path'], 'storage/') !== 0) {
            $oldPath = BASE_PATH . '/storage/' . ltrim($order['invoice_file_path'], '/');
        }
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $invoiceUrl = $baseUrl ? $baseUrl . '/storage/' . $relativePath : 'storage/' . $relativePath;

    echo json_encode([
        'success' => true,
        'message' => 'Factura a fost încărcată cu succes.',
        'invoice_filename' => $filename,
        'invoice_path' => $relativePath,
        'invoice_url' => $invoiceUrl,
        'verified' => false,
    ]);
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    if (isset($destinationPath) && is_file($destinationPath)) {
        @unlink($destinationPath);
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}
