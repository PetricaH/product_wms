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
    require_once BASE_PATH . '/models/AutoOrderManager.php';

    $inventory = new Inventory($db);
    $autoManager = new AutoOrderManager($db);

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
            $result = $inventory->testAutoOrder($productId);
            $emailData = $result['email'] ?? null;
            if (empty($emailData) || empty($result['furnizor']['email'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Nu există un email valid pentru furnizor.'
                ]);
                exit;
            }

            $smtpConfig = $config['email'] ?? [];
            if (empty($smtpConfig)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Configurația de email nu este definită.'
                ]);
                exit;
            }

            require_once BASE_PATH . '/lib/PHPMailer/PHPMailer.php';
            require_once BASE_PATH . '/lib/PHPMailer/SMTP.php';
            require_once BASE_PATH . '/lib/PHPMailer/Exception.php';

            $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mailer->isSMTP();
                $mailer->Host = $smtpConfig['host'] ?? '';
                $mailer->Port = (int)($smtpConfig['port'] ?? 0);
                $mailer->SMTPAuth = true;
                $mailer->Username = $smtpConfig['username'] ?? '';
                $mailer->Password = $smtpConfig['password'] ?? '';
                if (!empty($smtpConfig['encryption'])) {
                    $mailer->SMTPSecure = $smtpConfig['encryption'];
                }
                $mailer->CharSet = 'UTF-8';

                $fromEmail = $smtpConfig['from_email'] ?? $smtpConfig['username'] ?? '';
                $fromName = $smtpConfig['from_name'] ?? 'Sistem WMS';
                $mailer->setFrom($fromEmail, $fromName);
                if (!empty($smtpConfig['reply_to'])) {
                    $mailer->addReplyTo($smtpConfig['reply_to']);
                }

                $mailer->addAddress($result['furnizor']['email'], $result['furnizor']['nume'] ?? '');
                $rawSubject = $emailData['subiect'] ?? 'Test Autocomandă';
                $cleanSubject = trim(preg_replace('/^[^\pL\d]+/u', '', $rawSubject));
                $mailer->Subject = $cleanSubject ?: 'Test Autocomandă';
                $mailer->Body = $emailData['corp'] ?? '';
                $mailer->AltBody = $emailData['corp'] ?? '';
                $mailer->isHTML(false);
                $mailer->send();

                echo json_encode([
                    'success' => true,
                    'message' => 'Emailul de test a fost trimis către furnizor.'
                ]);
            } catch (Throwable $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Trimiterea emailului de test a eșuat: ' . $e->getMessage()
                ]);
            }
            exit;

        case 'execute_auto_order':
            $result = $autoManager->createAndSendAutoOrder($productId);
            echo json_encode([
                'success' => (bool)($result['succes'] ?? false),
                'message' => $result['mesaj'] ?? 'Rezultatul nu este disponibil.',
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
