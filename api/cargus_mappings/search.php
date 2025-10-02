<?php
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acces interzis']);
    exit;
}

$county = trim((string)($_GET['county'] ?? ''));
$locality = trim((string)($_GET['locality'] ?? ''));
$mappingId = (int)($_GET['mapping_id'] ?? 0);

if ($county === '' || $locality === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Județul și localitatea sunt obligatorii']);
    exit;
}

try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();

    require_once BASE_PATH . '/models/CargusService.php';
    $cargusService = new CargusService($db);

    $result = $cargusService->searchLocalities($county, $locality);

    if (!is_array($result) || !($result['success'] ?? false)) {
        $message = is_array($result) ? ($result['error'] ?? 'Nu am găsit rezultate') : 'Nu am găsit rezultate';
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'matches' => $result['matches'] ?? []
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'mapping_id' => $mappingId,
        'matches' => $result['matches'],
        'debug' => $result['debug'] ?? null
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Eroare la interogarea API-ului Cargus',
        'details' => $e->getMessage()
    ]);
}
