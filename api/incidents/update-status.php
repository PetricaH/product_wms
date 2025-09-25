<?php
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Doar administratorii pot actualiza incidente.']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF invalid.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metoda trebuie să fie POST.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Payload JSON invalid.']);
    exit;
}

try {
    $incidentId = isset($payload['incident_id']) ? (int)$payload['incident_id'] : 0;
    $status = $payload['status'] ?? '';
    $adminNotes = $payload['admin_notes'] ?? null;
    $resolutionNotes = $payload['resolution_notes'] ?? null;
    $followUp = !empty($payload['follow_up_required']);

    if ($incidentId <= 0) {
        throw new InvalidArgumentException('Incidentul specificat nu există.');
    }

    $validStatuses = ['reported','under_review','investigating','resolved','rejected'];
    if (!in_array($status, $validStatuses, true)) {
        throw new InvalidArgumentException('Statusul selectat este invalid.');
    }

    $config = require BASE_PATH . '/config/config.php';
    $dbFactory = $config['connection_factory'] ?? null;
    if (!$dbFactory || !is_callable($dbFactory)) {
        throw new RuntimeException('Configurația bazei de date este invalidă.');
    }

    $db = $dbFactory();
    require_once BASE_PATH . '/models/Incident.php';

    $incidentModel = new Incident($db);
    $updateSuccess = $incidentModel->updateStatus($incidentId, [
        'status' => $status,
        'admin_notes' => $adminNotes,
        'resolution_notes' => $resolutionNotes,
        'follow_up_required' => $followUp,
        'assigned_admin_id' => (int)$_SESSION['user_id'],
    ]);

    if (!$updateSuccess) {
        throw new RuntimeException('Actualizarea statusului nu a fost posibilă.');
    }

    $incident = $incidentModel->getIncidentById($incidentId);

    logActivity(
        (int)$_SESSION['user_id'],
        'update',
        'incident',
        $incidentId,
        'Actualizare status incident la ' . $status,
        null,
        [
            'status' => $status,
            'admin_notes' => $adminNotes,
            'resolution_notes' => $resolutionNotes,
            'follow_up_required' => $followUp,
        ]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Status actualizat cu succes.',
        'data' => $incident,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Incident update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A apărut o eroare la actualizarea incidentului.']);
}
