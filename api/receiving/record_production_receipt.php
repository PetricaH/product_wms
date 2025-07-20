<?php
/**
 * API: Record Production Receipt and Print Labels
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

require_once BASE_PATH . '/models/Inventory.php';
require_once BASE_PATH . '/models/Product.php';

$input = json_decode(file_get_contents('php://input'), true);
$productId   = (int)($input['product_id'] ?? 0);
$quantity    = (int)($input['quantity'] ?? 0);
$batchNumber = trim($input['batch_number'] ?? '');
$producedAt  = $input['produced_at'] ?? date('Y-m-d H:i:s');
$locationId  = (int)($input['location_id'] ?? 0);

if (!$productId || $quantity <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Product ID and quantity required']);
    exit;
}

try {
    $inventoryModel = new Inventory($db);

    if (!$locationId) {
        $stmt = $db->prepare("SELECT id FROM locations WHERE type = 'production' LIMIT 1");
        $stmt->execute();
        $locationId = $stmt->fetchColumn() ?: 1;
    }

    $invId = $inventoryModel->addStock([
        'product_id'   => $productId,
        'location_id'  => $locationId,
        'quantity'     => $quantity,
        'batch_number' => $batchNumber,
        'received_at'  => $producedAt
    ]);

    if (!$invId) {
        throw new Exception('Failed to add inventory');
    }

    $labelUrl = generateProductionLabel($db, $productId, $quantity, $batchNumber, $producedAt);
    if ($labelUrl) {
        sendToPrintServer($labelUrl);
    }

    echo json_encode(['success' => true, 'inventory_id' => $invId]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function generateProductionLabel(PDO $db, int $productId, int $qty, string $batch, string $date): ?string {
    if (!class_exists('FPDF')) {
        return null;
    }

    $productModel = new Product($db);
    $product = $productModel->findById($productId);
    if (!$product) {
        return null;
    }

    $fileName = 'label_' . time() . '_' . $batch . '.pdf';
    $dir = BASE_PATH . '/storage/label_pdfs';
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    $filePath = $dir . '/' . $fileName;

    $pdf = new FPDF('P', 'mm', [50, 30]);
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 6, $product['name'], 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 5, 'SKU: ' . $product['sku'], 0, 1, 'C');
    $pdf->Cell(0, 5, 'Lot: ' . $batch, 0, 1, 'C');
    $pdf->Cell(0, 5, 'Cant: ' . $qty, 0, 1, 'C');
    $pdf->Cell(0, 5, date('d.m.Y', strtotime($date)), 0, 1, 'C');
    $pdf->Output('F', $filePath);

    $baseUrl = rtrim(getBaseUrl(), '/');
    return $baseUrl . '/storage/label_pdfs/' . $fileName;
}

function sendToPrintServer(string $pdfUrl): void {
    $serverUrl = getBaseUrl() . '/print_server.php?url=' . urlencode($pdfUrl);
    @file_get_contents($serverUrl);
}

function getBaseUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $scheme . '://' . $host . $path;
}
