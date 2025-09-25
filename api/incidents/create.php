<?php
header('Content-Type: application/json');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Autentificare necesară.']);
    exit;
}

$allowedRoles = ['admin', 'warehouse', 'worker'];
if (!in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Nu aveți permisiunea să raportați incidente.']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
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

try {
    $config = require BASE_PATH . '/config/config.php';
    $dbFactory = $config['connection_factory'] ?? null;
    if (!$dbFactory || !is_callable($dbFactory)) {
        throw new RuntimeException('Configurația bazei de date este invalidă.');
    }

    $db = $dbFactory();
    require_once BASE_PATH . '/models/Incident.php';

    $incidentModel = new Incident($db);

    $incidentType = $_POST['incident_type'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $severity = $_POST['severity'] ?? 'medium';
    $occurredRaw = $_POST['occurred_at'] ?? '';
    $locationId = isset($_POST['location_id']) && $_POST['location_id'] !== '' ? (int)$_POST['location_id'] : null;
    $locationDescription = trim($_POST['location_description'] ?? '');
    $estimatedCost = $_POST['estimated_cost'] ?? null;

    $validTypes = ['product_loss','equipment_loss','equipment_damage','safety_issue','quality_issue','process_violation','other'];
    $validSeverities = ['low','medium','high','critical'];

    if (!in_array($incidentType, $validTypes, true)) {
        throw new InvalidArgumentException('Tipul incidentului este invalid.');
    }

    if (empty($title) || empty($description)) {
        throw new InvalidArgumentException('Titlul și descrierea sunt obligatorii.');
    }

    if (!in_array($severity, $validSeverities, true)) {
        throw new InvalidArgumentException('Severitatea selectată nu este validă.');
    }

    $occurredAt = DateTime::createFromFormat('Y-m-d\TH:i', $occurredRaw) ?: DateTime::createFromFormat('Y-m-d H:i:s', $occurredRaw);
    if (!$occurredAt) {
        throw new InvalidArgumentException('Data incidentului este invalidă.');
    }

    $costValue = null;
    if ($estimatedCost !== null && $estimatedCost !== '') {
        $costFloat = (float)$estimatedCost;
        if ($costFloat < 0) {
            throw new InvalidArgumentException('Costul estimativ nu poate fi negativ.');
        }
        $costValue = number_format($costFloat, 2, '.', '');
    }

    $incidentNumber = $incidentModel->generateIncidentNumber();
    $storageDir = BASE_PATH . '/storage/incidents/' . $incidentNumber;
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Nu se poate crea directorul pentru fotografii.');
    }

    $photoRecords = [];
    if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
        $fileCount = count($_FILES['photos']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            $error = $_FILES['photos']['error'][$i];
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Încărcarea fotografiei a eșuat.');
            }

            $tmpName = $_FILES['photos']['tmp_name'][$i];
            $originalName = $_FILES['photos']['name'][$i];
            $size = (int)$_FILES['photos']['size'][$i];
            $mime = mime_content_type($tmpName);

            if ($size > 5 * 1024 * 1024) {
                throw new InvalidArgumentException('Fotografiile trebuie să fie mai mici de 5MB.');
            }

            $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowedMime[$mime])) {
                throw new InvalidArgumentException('Doar imaginile JPEG, PNG sau WebP sunt permise.');
            }

            $uniqueName = sprintf('%s_%s.%s', time(), bin2hex(random_bytes(4)), $allowedMime[$mime]);
            $targetPath = $storageDir . '/' . $uniqueName;
            if (!move_uploaded_file($tmpName, $targetPath)) {
                throw new RuntimeException('Nu s-a putut salva fișierul încărcat.');
            }

            $photoRecords[] = [
                'file_path' => 'storage/incidents/' . $incidentNumber . '/' . $uniqueName,
                'original_filename' => $originalName,
                'file_size' => $size,
                'mime_type' => $mime,
            ];
        }
    }

    $incidentData = [
        'incident_number' => $incidentNumber,
        'reporter_id' => (int)$_SESSION['user_id'],
        'incident_type' => $incidentType,
        'title' => $title,
        'description' => $description,
        'location_id' => $locationId,
        'location_description' => $locationDescription ?: null,
        'severity' => $severity,
        'status' => 'reported',
        'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
        'estimated_cost' => $costValue,
        'follow_up_required' => in_array($severity, ['high','critical'], true),
    ];

    $result = $incidentModel->createIncident($incidentData, $photoRecords);

    logActivity(
        (int)$_SESSION['user_id'],
        'create',
        'incident',
        $result['id'],
        'Incident raportat: ' . $incidentNumber,
        null,
        $incidentData
    );

    echo json_encode([
        'success' => true,
        'message' => 'Incident raportat cu succes.',
        'data' => [
            'incident_id' => $result['id'],
            'incident_number' => $incidentNumber
        ]
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Incident create error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A apărut o eroare la raportarea incidentului.']);
}
