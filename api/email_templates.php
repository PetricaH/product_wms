<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJsonResponse(true, 'Metodă permisă.');
}

try {
    ensureAuthenticated();

    $config = require BASE_PATH . '/config/config.php';
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        throw new RuntimeException('Configurația bazei de date este invalidă.');
    }

    /** @var callable $connectionFactory */
    $connectionFactory = $config['connection_factory'];
    $db = $connectionFactory();

    require_once BASE_PATH . '/models/EmailTemplate.php';
    require_once BASE_PATH . '/models/Setting.php';
    require_once BASE_PATH . '/models/AutoOrderManager.php';

    $emailTemplateModel = new EmailTemplate($db);
    $settingsModel = new Setting($db);
    $autoOrderManager = new AutoOrderManager($db);

    $action = strtolower(trim($_GET['action'] ?? ''));

    switch ($action) {
        case 'load':
            ensureRequestMethod('GET');
            handleLoadTemplates($emailTemplateModel, $settingsModel);
            break;

        case 'save':
            ensureRequestMethod('POST');
            enforceCsrf();
            handleSaveTemplate($emailTemplateModel, $settingsModel);
            break;

        case 'preview':
            ensureRequestMethod('GET');
            handlePreviewTemplate($emailTemplateModel, $settingsModel);
            break;

        case 'variables':
            ensureRequestMethod('GET');
            handleAvailableVariables($emailTemplateModel, $settingsModel);
            break;

        case 'test':
            ensureRequestMethod('POST');
            enforceCsrf();
            handleTestTemplate($emailTemplateModel, $settingsModel, $autoOrderManager, $config['email'] ?? []);
            break;

        case 'history':
            ensureRequestMethod('GET');
            handleTemplateHistory($db);
            break;

        default:
            sendJsonResponse(false, 'Acțiunea solicitată nu este disponibilă.', null, 404);
    }
} catch (InvalidArgumentException $exception) {
    error_log('[EMAIL_TEMPLATE_API][VALIDATION] ' . $exception->getMessage());
    sendJsonResponse(false, $exception->getMessage(), null, 422);
} catch (RuntimeException $exception) {
    error_log('[EMAIL_TEMPLATE_API][RUNTIME] ' . $exception->getMessage());
    sendJsonResponse(false, 'A apărut o eroare în procesarea solicitării: ' . $exception->getMessage(), null, 500);
} catch (Throwable $throwable) {
    error_log('[EMAIL_TEMPLATE_API][FATAL] ' . $throwable->getMessage());
    sendJsonResponse(false, 'A apărut o eroare internă neașteptată. Te rugăm să încerci din nou.', null, 500);
}

die();

/**
 * @param array<string, mixed>|null $data
 */
function sendJsonResponse(bool $success, string $message, ?array $data = null, int $statusCode = 200): void
{
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    $payload = [
        'success' => $success,
        'message' => $message,
    ];

    if ($data !== null) {
        $payload['data'] = $data;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureAuthenticated(): void
{
    $allowedRoles = ['admin', 'manager', 'warehouse'];

    if (!isset($_SESSION['user_id'])) {
        throw new RuntimeException('Autentificarea este necesară pentru a accesa acest endpoint.');
    }

    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, $allowedRoles, true)) {
        throw new RuntimeException('Nu ai permisiunile necesare pentru a gestiona șabloanele de email.');
    }
}

function ensureRequestMethod(string $expectedMethod): void
{
    if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? '', $expectedMethod) !== 0) {
        throw new InvalidArgumentException('Metoda HTTP utilizată nu este permisă pentru această acțiune.');
    }
}

function enforceCsrf(): void
{
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (function_exists('apache_request_headers')) {
        $headers = array_change_key_case(apache_request_headers(), CASE_UPPER);
        $csrfToken = $csrfToken ?: ($headers['X-CSRF-TOKEN'] ?? '');
    }

    if (!validateCsrfToken($csrfToken)) {
        throw new InvalidArgumentException('Tokenul CSRF nu este valid. Reîncarcă pagina și încearcă din nou.');
    }
}

function handleLoadTemplates(EmailTemplate $emailTemplateModel, Setting $settingsModel): void
{
    $type = sanitizeTemplateType($_GET['type'] ?? 'auto_order');
    $template = $emailTemplateModel->getActiveTemplate($type);
    $availableVariables = $emailTemplateModel->getAvailableVariables();
    $sampleData = buildSampleData($settingsModel);

    $data = [
        'template' => $template ? normalizeTemplateForResponse($template) : null,
        'available_variables' => $availableVariables,
        'sample_data' => formatSampleDataForResponse($sampleData),
    ];

    sendJsonResponse(true, 'Șablonul activ a fost încărcat cu succes.', $data);
}

function handleSaveTemplate(EmailTemplate $emailTemplateModel, Setting $settingsModel): void
{
    $input = getRequestInput();

    $type = sanitizeTemplateType((string)($input['template_type'] ?? 'auto_order'));
    $templateName = sanitizeTemplateName((string)($input['template_name'] ?? 'Șablon autocomandă personalizat'));
    $subject = sanitizeTemplateContent($input['subject_template'] ?? null);
    $body = sanitizeTemplateContent($input['body_template'] ?? null);
    $isActive = !empty($input['is_active']);
    $setAsDefault = !empty($input['is_default']);
    $templateId = isset($input['template_id']) ? (int)$input['template_id'] : null;

    if ($subject === null || $subject === '') {
        throw new InvalidArgumentException('Subiectul șablonului este obligatoriu.');
    }

    if ($body === null || $body === '') {
        throw new InvalidArgumentException('Conținutul șablonului este obligatoriu.');
    }

    $usedVariables = $emailTemplateModel->validateTemplate($subject, $body);
    $missingRequired = array_diff(getRequiredVariables($type), $usedVariables);

    if (!empty($missingRequired)) {
        $formatted = array_map(static fn(string $variable): string => '{{' . $variable . '}}', $missingRequired);
        throw new InvalidArgumentException('Șablonul nu poate fi salvat. Lipsesc variabile obligatorii: ' . implode(', ', $formatted));
    }

    $dataToSave = [
        'id' => $templateId,
        'template_type' => $type,
        'template_name' => $templateName,
        'subject_template' => $subject,
        'body_template' => $body,
        'is_active' => $isActive ? 1 : 0,
        'is_default' => $setAsDefault ? 1 : 0,
        'created_by' => $_SESSION['user_id'] ?? null,
    ];

    $template = $emailTemplateModel->saveTemplate($dataToSave);
    $templateData = normalizeTemplateForResponse($template);

    $recommended = getRecommendedVariables($type);
    $missingRecommended = array_diff($recommended, $usedVariables);

    $responseData = [
        'template' => $templateData,
        'variables_used' => $templateData['variables_used'] ?? [],
        'missing_recommended_variables' => array_values(array_map(static fn(string $variable): string => '{{' . $variable . '}}', $missingRecommended)),
    ];

    if (function_exists('logActivity')) {
        logActivity(
            $_SESSION['user_id'] ?? 0,
            $templateId ? 'update' : 'create',
            'email_template',
            $templateData['template_id'],
            $templateId ? 'Șablon de email actualizat.' : 'Șablon de email creat.',
            null,
            $responseData
        );
    }

    sendJsonResponse(true, 'Șablonul a fost salvat cu succes.', $responseData);
}

function handlePreviewTemplate(EmailTemplate $emailTemplateModel, Setting $settingsModel): void
{
    $templateId = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
    if ($templateId <= 0) {
        throw new InvalidArgumentException('Identificatorul șablonului este invalid.');
    }

    $template = $emailTemplateModel->getTemplateById($templateId);
    if ($template === null) {
        throw new InvalidArgumentException('Șablonul solicitat nu a fost găsit.');
    }

    $sampleData = buildSampleData($settingsModel);
    [$subjectPreview, $bodyPreview, $missingVariables] = renderTemplatePreview(
        $template['subject_template'] ?? '',
        $template['body_template'] ?? '',
        $sampleData
    );

    $data = [
        'template' => normalizeTemplateForResponse($template),
        'preview' => [
            'subject' => $subjectPreview,
            'body' => $bodyPreview,
            'missing_variables' => array_values(array_map(static fn(string $variable): string => '{{' . $variable . '}}', $missingVariables)),
        ],
        'sample_data' => formatSampleDataForResponse($sampleData),
    ];

    sendJsonResponse(true, 'Previzualizarea șablonului a fost generată.', $data);
}

function handleAvailableVariables(EmailTemplate $emailTemplateModel, Setting $settingsModel): void
{
    $variables = $emailTemplateModel->getAvailableVariables();
    $sampleData = buildSampleData($settingsModel);

    $data = [
        'variables' => $variables,
        'sample_data' => formatSampleDataForResponse($sampleData),
    ];

    sendJsonResponse(true, 'Lista variabilelor disponibile a fost încărcată.', $data);
}

function handleTestTemplate(EmailTemplate $emailTemplateModel, Setting $settingsModel, AutoOrderManager $autoOrderManager, array $emailConfig): void
{
    $input = getRequestInput();
    $templateId = isset($input['template_id']) ? (int)$input['template_id'] : 0;
    $subjectTemplate = sanitizeTemplateContent($input['subject_template'] ?? null);
    $bodyTemplate = sanitizeTemplateContent($input['body_template'] ?? null);
    $template = null;

    if ($templateId > 0) {
        $template = $emailTemplateModel->getTemplateById($templateId);
        if ($template === null) {
            throw new InvalidArgumentException('Șablonul solicitat pentru test nu există.');
        }
        $subjectTemplate = $template['subject_template'] ?? '';
        $bodyTemplate = $template['body_template'] ?? '';
    }

    if (!$subjectTemplate) {
        throw new InvalidArgumentException('Subiectul șablonului este obligatoriu pentru trimiterea testului.');
    }

    if (!$bodyTemplate) {
        throw new InvalidArgumentException('Conținutul șablonului este obligatoriu pentru trimiterea testului.');
    }

    $type = sanitizeTemplateType((string)($input['template_type'] ?? ($template['template_type'] ?? 'auto_order')));
    $usedVariables = $emailTemplateModel->validateTemplate($subjectTemplate, $bodyTemplate);
    $missingRequired = array_diff(getRequiredVariables($type), $usedVariables);
    if (!empty($missingRequired)) {
        $formatted = array_map(static fn(string $variable): string => '{{' . $variable . '}}', $missingRequired);
        throw new InvalidArgumentException('Testul nu poate fi trimis. Lipsesc variabile obligatorii: ' . implode(', ', $formatted));
    }

    $sampleData = buildSampleData($settingsModel);
    [$subjectPreview, $bodyPreview, $missingVariables] = renderTemplatePreview($subjectTemplate, $bodyTemplate, $sampleData);

    $recipient = determineTestRecipient($input, $autoOrderManager, $emailConfig, $sampleData);
    if (!$recipient) {
        throw new RuntimeException('Nu a fost găsită o adresă de email pentru trimiterea testului. Configurează adresa de notificare.');
    }

    $sendResult = sendTestEmail($emailConfig, $recipient, $subjectPreview, $bodyPreview);
    if (!$sendResult['success']) {
        throw new RuntimeException($sendResult['message'] ?? 'Trimiterea emailului de test a eșuat din motive necunoscute.');
    }

    if (function_exists('logActivity')) {
        logActivity(
            $_SESSION['user_id'] ?? 0,
            'test',
            'email_template',
            $templateId,
            'A fost trimis un email de test pentru șablon.',
            null,
            [
                'recipient' => $recipient,
                'subject' => $subjectPreview,
            ]
        );
    }

    $data = [
        'recipient' => $recipient,
        'preview' => [
            'subject' => $subjectPreview,
            'body' => $bodyPreview,
            'missing_variables' => array_values(array_map(static fn(string $variable): string => '{{' . $variable . '}}', $missingVariables)),
        ],
    ];

    sendJsonResponse(true, 'Emailul de test a fost trimis cu succes.', $data);
}

function handleTemplateHistory(PDO $db): void
{
    $type = sanitizeTemplateType($_GET['type'] ?? 'auto_order');

    $stmt = $db->prepare('SELECT id, template_name, template_type, subject_template, is_active, is_default, updated_at, created_at FROM email_templates WHERE template_type = :type ORDER BY is_default DESC, updated_at DESC');
    $stmt->bindValue(':type', $type, PDO::PARAM_STR);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $history = [];
    foreach ($rows as $row) {
        $history[] = [
            'template_id' => (int)$row['id'],
            'template_name' => $row['template_name'],
            'template_type' => $row['template_type'],
            'subject_template' => $row['subject_template'],
            'is_active' => (int)$row['is_active'] === 1,
            'is_default' => (int)$row['is_default'] === 1,
            'updated_at' => $row['updated_at'],
            'created_at' => $row['created_at'],
        ];
    }

    sendJsonResponse(true, 'Istoricul șabloanelor a fost încărcat.', ['history' => $history]);
}

function getRequestInput(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $rawInput = file_get_contents('php://input');
    $data = [];

    if (is_string($rawInput) && trim($rawInput) !== '' && stripos($contentType, 'application/json') !== false) {
        $decoded = json_decode($rawInput, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $data = $decoded;
        }
    }

    if (empty($data)) {
        $data = $_POST;
    }

    return is_array($data) ? $data : [];
}

function sanitizeTemplateType(string $type): string
{
    $type = strtolower(trim($type));
    $type = preg_replace('/[^a-z0-9_]/', '', $type) ?: 'auto_order';
    return $type;
}

function sanitizeTemplateName(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    if ($name === '') {
        $name = 'Șablon autocomandă personalizat';
    }
    return mb_substr($name, 0, 100);
}

function sanitizeTemplateContent($value): ?string
{
    if ($value === null) {
        return null;
    }

    if (is_array($value)) {
        $value = implode(' ', $value);
    }

    $value = (string)$value;
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    return trim($value);
}

function normalizeTemplateForResponse(array $template): array
{
    $variables = array_map(static fn(string $variable): string => '{{' . $variable . '}}', $template['variables_used'] ?? []);

    return [
        'template_id' => (int)$template['id'],
        'template_type' => $template['template_type'],
        'template_name' => $template['template_name'],
        'subject_template' => $template['subject_template'],
        'body_template' => $template['body_template'],
        'is_active' => (bool)$template['is_active'],
        'is_default' => (bool)$template['is_default'],
        'variables_used' => $variables,
        'updated_at' => $template['updated_at'] ?? null,
        'created_at' => $template['created_at'] ?? null,
    ];
}

function buildSampleData(Setting $settingsModel): array
{
    $sampleData = [
        'COMPANY_NAME' => 'SC EXEMPLU SRL',
        'COMPANY_ADDRESS' => 'Str. Exemplu nr. 123, București',
        'COMPANY_PHONE' => '+40 21 123 4567',
        'COMPANY_EMAIL' => 'office@exemplu.ro',
        'ORDER_NUMBER' => 'PO-2025-001',
        'ORDER_DATE' => '24.09.2025',
        'ORDER_TIME' => '14:30',
        'DELIVERY_DATE' => '26.09.2025',
        'ORDER_TOTAL' => '1.250,00 RON',
        'PRODUCT_NAME' => 'WA.-801 DESEN BIO (MULTICLEAN)-SUPERCURATATOR 10 LITRI',
        'PRODUCT_CODE' => 'WA801.10',
        'ORDER_QUANTITY' => '12',
        'UNIT_PRICE' => '104,17 RON',
        'TOTAL_PRICE' => '1.250,00 RON',
        'UNIT_MEASURE' => 'bucăți',
        'SUPPLIER_NAME' => 'APPROVED EUROPE S.R.L.',
        'SUPPLIER_EMAIL' => 'comenzi@approved.ro',
        'SUPPLIER_PHONE' => '+40 21 987 6543',
        'CURRENT_DATE' => date('d.m.Y'),
        'CURRENT_TIME' => date('H:i'),
    ];

    try {
        $overrides = $settingsModel->getMultiple(['company_name', 'company_address', 'company_phone', 'company_email']);
        if (!empty($overrides['company_name'])) {
            $sampleData['COMPANY_NAME'] = (string)$overrides['company_name'];
        }
        if (!empty($overrides['company_address'])) {
            $sampleData['COMPANY_ADDRESS'] = (string)$overrides['company_address'];
        }
        if (!empty($overrides['company_phone'])) {
            $sampleData['COMPANY_PHONE'] = (string)$overrides['company_phone'];
        }
        if (!empty($overrides['company_email'])) {
            $sampleData['COMPANY_EMAIL'] = (string)$overrides['company_email'];
        }
    } catch (Throwable $exception) {
        error_log('[EMAIL_TEMPLATE_API][SAMPLE_DATA] ' . $exception->getMessage());
    }

    return $sampleData;
}

function formatSampleDataForResponse(array $data): array
{
    $formatted = [];
    foreach ($data as $key => $value) {
        $formatted['{{' . $key . '}}'] = $value;
    }

    return $formatted;
}

function renderTemplatePreview(string $subjectTemplate, string $bodyTemplate, array $data): array
{
    $missing = [];

    $subject = preg_replace_callback('/\{\{\s*([A-Z0-9_]+)\s*\}\}/u', static function (array $matches) use ($data, &$missing): string {
        $key = strtoupper($matches[1]);
        if (array_key_exists($key, $data)) {
            return (string)$data[$key];
        }

        $missing[$key] = $key;
        return '[valoare indisponibilă: ' . $key . ']';
    }, $subjectTemplate);

    $body = preg_replace_callback('/\{\{\s*([A-Z0-9_]+)\s*\}\}/u', static function (array $matches) use ($data, &$missing): string {
        $key = strtoupper($matches[1]);
        if (array_key_exists($key, $data)) {
            return (string)$data[$key];
        }

        $missing[$key] = $key;
        return '<span style="color:#c0392b;font-weight:bold;">[valoare indisponibilă: ' . $key . ']</span>';
    }, $bodyTemplate);

    return [$subject, $body, array_values($missing)];
}

function getRequiredVariables(string $type): array
{
    $requiredByType = [
        'auto_order' => ['COMPANY_NAME', 'SUPPLIER_NAME', 'SUPPLIER_EMAIL', 'ORDER_NUMBER', 'PRODUCT_NAME', 'ORDER_QUANTITY'],
    ];

    return $requiredByType[$type] ?? [];
}

function getRecommendedVariables(string $type): array
{
    $recommendedByType = [
        'auto_order' => ['COMPANY_ADDRESS', 'COMPANY_PHONE', 'COMPANY_EMAIL', 'DELIVERY_DATE', 'ORDER_TOTAL', 'UNIT_PRICE', 'TOTAL_PRICE', 'UNIT_MEASURE', 'CURRENT_DATE', 'CURRENT_TIME', 'SUPPLIER_PHONE', 'PRODUCT_CODE'],
    ];

    return $recommendedByType[$type] ?? [];
}

function determineTestRecipient(array $input, AutoOrderManager $autoOrderManager, array $emailConfig, array $sampleData): ?string
{
    $explicitRecipient = $input['recipient_email'] ?? $input['test_email'] ?? null;
    if ($explicitRecipient && filter_var($explicitRecipient, FILTER_VALIDATE_EMAIL)) {
        return $explicitRecipient;
    }

    try {
        $settings = $autoOrderManager->getAutoOrderSettings();
        if (!empty($settings['notificare_admin_email']) && filter_var($settings['notificare_admin_email'], FILTER_VALIDATE_EMAIL)) {
            return $settings['notificare_admin_email'];
        }
    } catch (Throwable $exception) {
        error_log('[EMAIL_TEMPLATE_API][RECIPIENT_SETTINGS] ' . $exception->getMessage());
    }

    if (!empty($emailConfig['reply_to']) && filter_var($emailConfig['reply_to'], FILTER_VALIDATE_EMAIL)) {
        return $emailConfig['reply_to'];
    }

    if (!empty($emailConfig['from_email']) && filter_var($emailConfig['from_email'], FILTER_VALIDATE_EMAIL)) {
        return $emailConfig['from_email'];
    }

    $companyEmail = $sampleData['COMPANY_EMAIL'] ?? null;
    if ($companyEmail && filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
        return $companyEmail;
    }

    return null;
}

function sendTestEmail(array $emailConfig, string $recipient, string $subject, string $body): array
{
    if (empty($emailConfig['host']) || empty($emailConfig['port']) || empty($emailConfig['username']) || empty($emailConfig['password'])) {
        return [
            'success' => false,
            'message' => 'Configurația SMTP este incompletă. Verifică setările de email din sistem.'
        ];
    }

    require_once BASE_PATH . '/lib/PHPMailer/PHPMailer.php';
    require_once BASE_PATH . '/lib/PHPMailer/SMTP.php';
    require_once BASE_PATH . '/lib/PHPMailer/Exception.php';

    try {
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $emailConfig['host'];
        $mailer->Port = (int)$emailConfig['port'];
        $mailer->SMTPAuth = true;
        $mailer->Username = $emailConfig['username'];
        $mailer->Password = $emailConfig['password'];
        $mailer->CharSet = 'UTF-8';

        if (!empty($emailConfig['encryption'])) {
            $mailer->SMTPSecure = $emailConfig['encryption'];
        }

        $fromEmail = $emailConfig['from_email'] ?? $emailConfig['username'];
        $fromName = $emailConfig['from_name'] ?? 'Sistem WMS';
        $mailer->setFrom($fromEmail, $fromName);

        if (!empty($emailConfig['reply_to'])) {
            $mailer->addReplyTo($emailConfig['reply_to']);
        }

        $mailer->addAddress($recipient);
        $mailer->Subject = $subject;
        $mailer->Body = $body;
        $mailer->isHTML(stripos($body, '<') !== false && stripos($body, '>') !== false);
        $mailer->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));

        $mailer->send();

        return [
            'success' => true,
            'message' => 'Email trimis cu succes.'
        ];
    } catch (PHPMailer\PHPMailer\Exception $exception) {
        return [
            'success' => false,
            'message' => 'Trimiterea emailului a eșuat: ' . $exception->getMessage(),
        ];
    } catch (Throwable $throwable) {
        return [
            'success' => false,
            'message' => 'A apărut o eroare la trimiterea emailului: ' . $throwable->getMessage(),
        ];
    }
}
