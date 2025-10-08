<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/models/Factura.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$action = $_GET['action'] ?? 'list';

if ($action !== 'download') {
    header('Content-Type: application/json');
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true)) {
    if ($action === 'download') {
        http_response_code(401);
        exit('Unauthorized');
    }

    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Acces neautorizat']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$pdo = $config['connection_factory']();
$facturiModel = new Factura($pdo);

try {
    switch ($action) {
        case 'process':
            requirePost();
            processInvoice($facturiModel, $pdo);
            break;
        case 'stats':
            stats($facturiModel);
            break;
        case 'list':
            listInvoices($facturiModel);
            break;
        case 'update_status':
            requirePost();
            updateStatus($facturiModel);
            break;
        case 'delete':
            requirePost();
            deleteInvoice($facturiModel);
            break;
        case 'view':
            viewInvoice($facturiModel);
            break;
        case 'download':
            downloadInvoice($facturiModel);
            break;
        default:
            jsonResponse(false, ['message' => 'Acțiune necunoscută'], 400);
    }
} catch (Throwable $exception) {
    if ($action === 'download') {
        http_response_code(500);
        exit('Eroare la descărcare');
    }

    jsonResponse(false, [
        'message' => 'A apărut o eroare: ' . $exception->getMessage(),
    ], 500);
}

function requirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, ['message' => 'Metodă HTTP invalidă'], 405);
    }
}

function processInvoice(Factura $facturiModel, PDO $pdo): void
{
    if (!isset($_FILES['invoice_file']) || $_FILES['invoice_file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(false, ['message' => 'Fișierul de factură este obligatoriu.']);
    }

    $file = $_FILES['invoice_file'];

    if ($file['size'] > 10 * 1024 * 1024) {
        jsonResponse(false, ['message' => 'Dimensiunea maximă acceptată este de 10MB.']);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedTypes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
    ];

    if (!in_array($mime, $allowedTypes, true) && strpos($mime, 'image/') !== 0) {
        jsonResponse(false, ['message' => 'Tipul de fișier nu este acceptat.']);
    }

    $sha256 = hash_file('sha256', $file['tmp_name']);

    $webhookUrl = 'https://wartung.app.n8n.cloud/webhook/5f608b58-4b20-4a99-a397-7361382f9f57';
    $curl = curl_init($webhookUrl);

    if ($curl === false) {
        jsonResponse(false, ['message' => 'Nu s-a putut inițializa conexiunea către serviciu.']);
    }

    $postFields = [
        'image' => new CURLFile($file['tmp_name'], $mime, $file['name'])
    ];

    if (!empty($_POST['metadata'])) {
        $postFields['metadata'] = $_POST['metadata'];
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => $postFields,
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        jsonResponse(false, ['message' => 'Conexiunea către serviciu a eșuat: ' . $error]);
    }

    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $decoded = json_decode($response, true);
    if ($httpCode >= 400 || !$decoded || !($decoded['success'] ?? false)) {
        $message = $decoded['message'] ?? 'Serviciul extern a returnat o eroare.';
        jsonResponse(false, ['message' => $message, 'response' => $decoded], $httpCode ?: 500);
    }

    $data = $decoded['data'] ?? [];
    if (empty($data['nr_factura']) || empty($data['nume_firma'])) {
        jsonResponse(false, ['message' => 'Răspuns invalid de la serviciul extern.']);
    }

    $payload = [
        'nr_factura' => trim((string)$data['nr_factura']),
        'nume_firma' => trim((string)$data['nume_firma']),
        'cif' => $data['cif'] ?? null,
        'reg_com' => $data['reg_com'] ?? null,
        'adresa' => $data['adresa'] ?? null,
        'data_emitere' => $data['data_emitere'] ?? null,
        'termen_plata' => $data['termen_plata'] ?? null,
        'suma' => isset($data['suma']) ? (float)$data['suma'] : 0.0,
        'status' => 'neplatita',
        'file_path' => $data['file_path'] ?? null,
        'somatie_path' => $data['somatie_path'] ?? null,
        'sha256' => $sha256,
    ];

    try {
        $pdo->beginTransaction();
        $invoiceId = $facturiModel->create($payload);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();

        if ((int)$e->getCode() === 23000) {
            jsonResponse(false, ['message' => 'Factura există deja în sistem.']);
        }

        throw $e;
    }

    $invoice = $facturiModel->getById($invoiceId);

    jsonResponse(true, [
        'message' => 'Factura a fost procesată cu succes.',
        'invoice' => formatInvoice($invoice),
        'raw_invoice' => $invoice,
        'somatie_text' => $data['somatie_text'] ?? null,
        'somatie_html' => $data['somatie_html'] ?? null,
    ]);
}

function listInvoices(Factura $facturiModel): void
{
    $draw = (int)($_GET['draw'] ?? 1);
    $filters = [
        'status' => $_GET['status'] ?? '',
        'search' => trim($_GET['extra_search'] ?? ($_GET['search']['value'] ?? '')),
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'start' => (int)($_GET['start'] ?? 0),
        'length' => (int)($_GET['length'] ?? 25),
        'order_column' => isset($_GET['order'][0]['column']) ? (int)$_GET['order'][0]['column'] : 7,
        'order_dir' => $_GET['order'][0]['dir'] ?? 'desc',
    ];

    if (!empty($filters['date_from'])) {
        $filters['date_from'] = substr($filters['date_from'], 0, 10);
    }

    if (!empty($filters['date_to'])) {
        $filters['date_to'] = substr($filters['date_to'], 0, 10);
    }

    $result = $facturiModel->getAll($filters);
    $rows = array_map('formatInvoice', $result['data']);

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $result['total'],
        'recordsFiltered' => $result['filtered'],
        'data' => $rows,
    ]);
}

function stats(Factura $facturiModel): void
{
    $stats = $facturiModel->getStats();

    jsonResponse(true, [
        'stats' => [
            'total' => $stats['total'],
            'neplatite' => $stats['neplatite'],
            'platite' => $stats['platite'],
            'suma_totala' => $stats['suma_totala'],
            'suma_formatata' => formatCurrency($stats['suma_totala']),
        ],
    ]);
}

function updateStatus(Factura $facturiModel): void
{
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if ($id <= 0 || !in_array($status, ['platita', 'neplatita'], true)) {
        jsonResponse(false, ['message' => 'Date invalide'], 400);
    }

    $updated = $facturiModel->updateStatus($id, $status);
    if (!$updated) {
        jsonResponse(false, ['message' => 'Nu s-a putut actualiza statusul.']);
    }

    jsonResponse(true, ['message' => 'Status actualizat cu succes.']);
}

function deleteInvoice(Factura $facturiModel): void
{
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        jsonResponse(false, ['message' => 'ID invalid'], 400);
    }

    $deleted = $facturiModel->delete($id);

    if (!$deleted) {
        jsonResponse(false, ['message' => 'Nu s-a putut șterge factura.']);
    }

    jsonResponse(true, ['message' => 'Factura a fost ștearsă.']);
}

function viewInvoice(Factura $facturiModel): void
{
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        jsonResponse(false, ['message' => 'ID invalid'], 400);
    }

    $invoice = $facturiModel->getById($id);

    if (!$invoice) {
        jsonResponse(false, ['message' => 'Factura nu a fost găsită.'], 404);
    }

    jsonResponse(true, ['invoice' => formatInvoice($invoice), 'raw_invoice' => $invoice]);
}

function downloadInvoice(Factura $facturiModel): void
{
    $id = (int)($_GET['id'] ?? 0);
    $document = $_GET['document'] ?? 'factura';

    if ($id <= 0 || !in_array($document, ['factura', 'somatie'], true)) {
        http_response_code(400);
        exit('Parametri invalizi');
    }

    $invoice = $facturiModel->getById($id);
    if (!$invoice) {
        http_response_code(404);
        exit('Factura nu a fost găsită');
    }

    $path = $document === 'factura' ? ($invoice['file_path'] ?? '') : ($invoice['somatie_path'] ?? '');
    if (!$path || !is_readable($path)) {
        http_response_code(404);
        exit('Fișierul nu a fost găsit');
    }

    $filename = basename($path);

    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Accel-Buffering: no');
    readfile($path);
    exit;
}

function formatInvoice(?array $invoice): array
{
    if (!$invoice) {
        return [];
    }

    return [
        'id' => (int)$invoice['id'],
        'nr_factura' => $invoice['nr_factura'],
        'nume_firma' => $invoice['nume_firma'],
        'cif' => $invoice['cif'],
        'reg_com' => $invoice['reg_com'],
        'adresa' => $invoice['adresa'],
        'data_emitere' => $invoice['data_emitere'],
        'data_emitere_formatata' => formatDate($invoice['data_emitere']),
        'termen_plata' => $invoice['termen_plata'],
        'termen_plata_formatat' => formatDate($invoice['termen_plata']),
        'suma' => (float)$invoice['suma'],
        'suma_formatata' => formatCurrency((float)$invoice['suma']),
        'status' => $invoice['status'],
        'file_path' => $invoice['file_path'],
        'somatie_path' => $invoice['somatie_path'],
        'sha256' => $invoice['sha256'],
        'created_at' => $invoice['created_at'],
        'updated_at' => $invoice['updated_at'],
    ];
}

function formatDate(?string $date): ?string
{
    if (!$date || $date === '0000-00-00') {
        return null;
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return null;
    }

    return date('d.m.Y', $timestamp);
}

function formatCurrency(float $value): string
{
    $formatted = number_format($value, 2, ',', '.');
    return $formatted . ' RON';
}

function jsonResponse(bool $success, array $payload = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success], $payload));
    exit;
}
