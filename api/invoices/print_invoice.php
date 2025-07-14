<?php
// File: api/invoices/print_invoice.php
// Generates a simple PDF invoice for an order and sends it to a network printer

ob_start();
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/config/config.php';

// Use Composer autoloader so we get the FPDF library with bundled fonts
if (file_exists(BASE_PATH . '/vendor/autoload.php')) {
    require_once BASE_PATH . '/vendor/autoload.php';
}


function respond($data, int $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$orderId = $_POST['order_id'] ?? '';
if (empty($orderId)) {
    respond(['status' => 'error', 'message' => 'Missing order_id'], 400);
}

try {
    $config = require BASE_PATH . '/config/config.php';
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        respond(['status' => 'error', 'message' => 'Database configuration error'], 500);
    }
    $db = $config['connection_factory']();

    require_once BASE_PATH . '/models/Order.php';

    // FPDF will be loaded via Composer autoload (setasign/fpdf)

    require_once BASE_PATH . '/lib/fpdf.php';


    $orderModel = new Order($db);
    $order = $orderModel->getOrderById((int)$orderId);
    if (!$order) {
        respond(['status' => 'error', 'message' => 'Order not found'], 404);
    }

    $storageDir = BASE_PATH . '/storage/invoice_pdfs';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0777, true);
    }

    // Generate PDF invoice
    $fileName = 'inv_' . $order['order_number'] . '_' . time() . '.pdf';
    $filePath = $storageDir . '/' . $fileName;

    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'FACTURA', 0, 1, 'C');

    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 8, 'Comanda: ' . $order['order_number'], 0, 1);
    $pdf->Cell(0, 8, 'Client: ' . $order['customer_name'], 0, 1);
    $pdf->Cell(0, 8, 'Data: ' . date('d.m.Y H:i', strtotime($order['order_date'])), 0, 1);
    $pdf->Ln(8);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(80, 8, 'Produs', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Cantitate', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Pret', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Total', 1, 1, 'C');

    $pdf->SetFont('Arial', '', 9);
    $total = 0;
    foreach ($order['items'] as $item) {
        $name = $item['product_name'] ?? '';
        if (strlen($name) > 35) {
            $name = substr($name, 0, 32) . '...';
        }
        $qty = (float)($item['quantity'] ?? 0);
        $price = (float)($item['unit_price'] ?? 0);
        $lineTotal = $qty * $price;
        $total += $lineTotal;
        $pdf->Cell(80, 8, $name, 1);
        $pdf->Cell(30, 8, number_format($qty, 2), 1, 0, 'R');
        $pdf->Cell(30, 8, number_format($price, 2), 1, 0, 'R');
        $pdf->Cell(30, 8, number_format($lineTotal, 2), 1, 1, 'R');
    }

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(140, 8, 'TOTAL:', 1, 0, 'R');
    $pdf->Cell(30, 8, number_format($total, 2) . ' RON', 1, 1, 'R');

    $pdf->Output('F', $filePath);

    // Send to printer via lpr
    $printer = 'Brother_MFC_L2712DN';
    $cmd = 'lpr -P ' . escapeshellarg($printer) . ' ' . escapeshellarg($filePath);
    $outputLines = [];
    $exitStatus = 0;
    exec($cmd . ' 2>&1', $outputLines, $exitStatus);
    $printOutput = implode("\n", $outputLines);

    if ($exitStatus !== 0 || stripos($printOutput, 'not found') !== false) {
        respond([
            'status' => 'error',
            'message' => 'Printing failed',
            'debug' => $printOutput,
            'exit_status' => $exitStatus
        ], 500);
    }

    respond(['status' => 'success', 'message' => 'Invoice sent to printer', 'debug' => $printOutput]);

} catch (Exception $e) {
    respond(['status' => 'error', 'message' => $e->getMessage()], 500);
}
