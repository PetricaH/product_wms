<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'] ?? null;

if (!$dbFactory || !is_callable($dbFactory)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection factory not configured.'
    ]);
    exit;
}

try {
    $db = $dbFactory();
    require_once BASE_PATH . '/models/Inventory.php';

    $inventory = new Inventory($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        if ($productId <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Parametrul product_id este obligatoriu.'
            ]);
            exit;
        }

        $result = $inventory->testAutoOrder($productId);
        if (empty($result)) {
            echo json_encode([
                'success' => false,
                'message' => 'Nu s-a putut genera simularea pentru acest produs.'
            ]);
            exit;
        }

        $emailData = $result['email'] ?? null;
        $subject = $emailData['subiect'] ?? '';
        $body = $emailData['corp'] ?? '';
        if ($subject) {
            $subject = trim(preg_replace('/^[^\pL\d]+/u', '', $subject));
        }

        $response = [
            'validari' => $result['validari'] ?? [],
            'template' => [
                'subject_preview' => $subject,
                'body_preview' => $body
            ],
            'simulation' => [
                'poate_comanda' => (bool)($result['poate_comanda'] ?? false),
                'produs' => $result['produs'] ?? null,
                'furnizor' => $result['furnizor'] ?? null,
                'comanda' => $result['comanda'] ?? null,
                'payload' => $result['payload_simulat'] ?? $result['payload'] ?? null
            ]
        ];

        echo json_encode([
            'success' => true,
            'data' => $response
        ]);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $payload['action'] ?? '';
    $productId = isset($payload['product_id']) ? (int)$payload['product_id'] : 0;

    if ($productId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Parametrul product_id este obligatoriu.'
        ]);
        exit;
    }

    switch ($action) {
        case 'send_test_email':
            $recipient = isset($payload['test_recipient'])
                ? trim((string)$payload['test_recipient'])
                : null;

            $result = $inventory->sendAutoOrderTestEmail($productId, $recipient ?: null);

            echo json_encode([
                'success' => (bool)($result['success'] ?? false),
                'message' => $result['message'] ?? 'Rezultatul trimiterii emailului de test nu este disponibil.',
                'order_number' => $result['order_number'] ?? null
            ]);
            exit;

        case 'execute_auto_order':
            $overrideRecipient = isset($payload['override_recipient'])
                ? trim((string)$payload['override_recipient'])
                : null;

            if ($overrideRecipient !== null && $overrideRecipient === '') {
                $overrideRecipient = null;
            }

            $result = $inventory->executeAutoOrder($productId, $overrideRecipient);

            echo json_encode([
                'success' => (bool)($result['success'] ?? false),
                'message' => $result['message'] ?? 'Rezultatul nu este disponibil.',
                'order_id' => $result['order_id'] ?? null,
                'order_number' => $result['order_number'] ?? null
            ]);
            exit;

        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Acțiune necunoscută pentru testarea autocomenzii.'
            ]);
            exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A apărut o eroare la procesarea cererii.',
        'error' => $e->getMessage()
    ]);
}
