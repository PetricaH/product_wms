<?php
/**
 * AutomatedReturnProcessor
 *
 * Handles synchronization of returned parcels from Cargus into the WMS return tables.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/models/CargusService.php';

class AutomatedReturnProcessor
{
    private PDO $db;
    private CargusService $cargusService;
    private array $config;
    private array $returnIndicators = [
        'returnat',
        'return',
        'retur',
        'returned to sender',
        'nelivrat',
        'refused',
        'refuzat',
        'undelivered',
    ];
    private int $automationUserId;
    private string $logFile;
    private bool $notificationsEnabled = false;
    private array $notificationRecipients = [];

    public function __construct(?PDO $db = null, ?CargusService $cargusService = null, ?array $config = null)
    {
        $this->config = $config ?? require BASE_PATH . '/config/config.php';
        $this->db = $db ?: ($this->config['connection_factory'])();
        $this->cargusService = $cargusService ?: new CargusService($this->db);

        $automationConfig = $this->config['automation'] ?? [];
        $this->logFile = $automationConfig['log_file'] ?? (BASE_PATH . '/storage/logs/automated_returns.log');
        $this->ensureLogDirectory(dirname($this->logFile));

        $this->notificationsEnabled = (bool)($automationConfig['notification']['enabled'] ?? false);
        $this->notificationRecipients = $automationConfig['notification']['recipients'] ?? [];

        $this->automationUserId = $this->resolveAutomationUserId((int)($automationConfig['auto_return_user_id'] ?? 0));
    }

    /**
     * Process returns reported for a specific date (defaults to today).
     */
    public function processDailyReturns(?string $date = null): array
    {
        $targetDate = $date ?: date('Y-m-d');
        $this->log('INFO', 'Starting daily return synchronization', ['date' => $targetDate]);

        $response = $this->cargusService->getReturnedAWBs($targetDate);
        if (!$response['success']) {
            $this->log('ERROR', 'Failed to fetch returned AWBs', $response);
            return [
                'success' => false,
                'date' => $targetDate,
                'processed' => 0,
                'created' => 0,
                'skipped' => 0,
                'errors' => [$response['error'] ?? 'Unknown error'],
            ];
        }

        $created = 0;
        $skipped = 0;
        $errors = [];

        $payloads = is_array($response['data']) ? $response['data'] : [];

        foreach ($payloads as $awbData) {
            $result = $this->createReturnFromAWB($awbData);
            if ($result['success']) {
                $created++;
            } elseif ($result['code'] === 409) {
                $skipped++;
            } else {
                $skipped++;
                $errors[] = $result['error'];
            }
        }

        $summary = [
            'success' => empty($errors),
            'date' => $targetDate,
            'processed' => count($payloads),
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ];

        $this->log('INFO', 'Daily return synchronization completed', $summary);
        return $summary;
    }

    /**
     * Process Cargus delta events to detect recently returned parcels.
     */
    public function processDeltaEvents(?string $fromDate = null, ?string $toDate = null): array
    {
        $automationConfig = $this->config['automation'] ?? [];
        $hoursBack = max(1, (int)($automationConfig['delta_event_hours'] ?? 6));

        $from = $fromDate ?: date('m-d-Y', strtotime("-{$hoursBack} hours"));
        $to = $toDate ?: date('m-d-Y');

        $this->log('INFO', 'Starting delta event synchronization', ['from' => $from, 'to' => $to]);

        $response = $this->cargusService->getDeltaEvents($from, $to);
        if (!$response['success']) {
            $this->log('ERROR', 'Failed to fetch delta events', $response);
            return [
                'success' => false,
                'from' => $from,
                'to' => $to,
                'processed' => 0,
                'created' => 0,
                'skipped' => 0,
                'errors' => [$response['error'] ?? 'Unknown error'],
            ];
        }

        $processed = 0;
        $created = 0;
        $skipped = 0;
        $errors = [];

        $events = is_array($response['data']) ? $response['data'] : [];

        foreach ($events as $event) {
            $processed++;
            if (!$this->isReturnEvent($event)) {
                continue;
            }

            $result = $this->createReturnFromAWB($event);
            if ($result['success']) {
                $created++;
            } elseif ($result['code'] === 409) {
                $skipped++;
            } else {
                $skipped++;
                $errors[] = $result['error'];
            }
        }

        $summary = [
            'success' => empty($errors),
            'from' => $from,
            'to' => $to,
            'processed' => $processed,
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ];

        $this->log('INFO', 'Delta event synchronization completed', $summary);
        return $summary;
    }

    /**
     * Create a return record using AWB data delivered by Cargus.
     */
    public function createReturnFromAWB(array $awbData): array
    {
        try {
            $barcode = $this->extractBarcode($awbData);
            if ($barcode === null) {
                $this->log('WARNING', 'Unable to determine barcode from AWB payload', ['payload' => $awbData]);
                return [
                    'success' => false,
                    'error' => 'Unable to determine AWB barcode from payload',
                    'code' => 422,
                ];
            }

            $order = $this->findOrderByAWB($barcode);
            if (!$order) {
                $this->log('INFO', 'No order found for returned AWB', ['barcode' => $barcode]);
                return [
                    'success' => false,
                    'error' => 'Order not found for AWB ' . $barcode,
                    'code' => 404,
                ];
            }

            if (($order['status'] ?? '') !== 'shipped') {
                $this->log('INFO', 'Skipping return creation for non-shipped order', [
                    'order_id' => $order['id'],
                    'status' => $order['status'],
                ]);
                return [
                    'success' => false,
                    'error' => 'Order is not in shipped status',
                    'code' => 409,
                ];
            }

            $this->db->beginTransaction();

            if ($this->returnExists((int)$order['id'], true)) {
                $this->db->rollBack();
                $this->log('INFO', 'Return already exists for order', ['order_id' => $order['id']]);
                return [
                    'success' => false,
                    'error' => 'Return already exists for order ' . $order['id'],
                    'code' => 409,
                ];
            }

            $returnDate = $this->extractReturnDate($awbData);
            $notes = $this->extractStatusMessage($awbData);
            $returnAwb = $this->extractReturnBarcode($awbData) ?? $barcode;

            $stmt = $this->db->prepare('INSERT INTO returns (order_id, processed_by, status, notes, return_awb, auto_created, return_date) VALUES (:order_id, :processed_by, :status, :notes, :return_awb, 1, :return_date)');
            $stmt->bindValue(':order_id', (int)$order['id'], PDO::PARAM_INT);
            $stmt->bindValue(':processed_by', $this->automationUserId, PDO::PARAM_INT);
            $stmt->bindValue(':status', 'pending', PDO::PARAM_STR);
            if ($notes === null) {
                $stmt->bindValue(':notes', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':notes', $notes, PDO::PARAM_STR);
            }
            $stmt->bindValue(':return_awb', $returnAwb, PDO::PARAM_STR);
            if ($returnDate === null) {
                $stmt->bindValue(':return_date', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':return_date', $returnDate->format('Y-m-d H:i:s'), PDO::PARAM_STR);
            }
            $stmt->execute();

            $returnId = (int)$this->db->lastInsertId();
            $this->db->commit();

            $this->log('INFO', 'Return created automatically', [
                'order_id' => $order['id'],
                'return_id' => $returnId,
                'barcode' => $barcode,
            ]);

            $this->notifyWarehouse($order, $returnId, $returnAwb, $returnDate, $notes);

            return [
                'success' => true,
                'return_id' => $returnId,
                'code' => 201,
            ];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->log('ERROR', 'Failed to create automated return', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create return: ' . $exception->getMessage(),
                'code' => 500,
            ];
        }
    }

    /**
     * Find an order by AWB barcode.
     */
    public function findOrderByAWB(string $awbBarcode): ?array
    {
        $stmt = $this->db->prepare('SELECT id, order_number, status, customer_name FROM orders WHERE awb_barcode = :barcode LIMIT 1');
        $stmt->execute([':barcode' => $awbBarcode]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        return $order ?: null;
    }

    /**
     * Check whether a return already exists for the provided order.
     */
    public function returnExists(int $orderId, bool $forUpdate = false): bool
    {
        $sql = 'SELECT id FROM returns WHERE order_id = :order_id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':order_id' => $orderId]);
        return (bool)$stmt->fetchColumn();
    }

    private function resolveAutomationUserId(int $configuredUserId): int
    {
        if ($configuredUserId > 0) {
            return $configuredUserId;
        }

        $stmt = $this->db->query("SELECT id FROM users WHERE role IN ('system','admin','manager') ORDER BY FIELD(role,'system','admin','manager'), id LIMIT 1");
        $userId = (int)$stmt->fetchColumn();
        if ($userId <= 0) {
            throw new \RuntimeException('No eligible user found for automated returns. Configure AUTO_RETURN_USER_ID.');
        }

        return $userId;
    }

    private function ensureLogDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $payload = $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $line = sprintf('[%s] [%s] %s %s', $timestamp, $level, $message, $payload);

        error_log('[AutomatedReturnProcessor] ' . $line);
        file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function isReturnEvent(array $event): bool
    {
        $statusMessage = $this->extractStatusMessage($event);
        if ($statusMessage === null) {
            return false;
        }

        $normalized = function_exists('mb_strtolower') ? mb_strtolower($statusMessage) : strtolower($statusMessage);
        foreach ($this->returnIndicators as $indicator) {
            if (strpos($normalized, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    private function extractBarcode(array $payload): ?string
    {
        $candidates = [];
        foreach (['ReturnBarCode', 'ReturnBarcode', 'ReturnAWB', 'BarCode', 'Barcode', 'AWB', 'Awb', 'ParcelBarCode'] as $field) {
            if (!empty($payload[$field])) {
                $candidates[] = $payload[$field];
            }
        }

        foreach ($candidates as $candidate) {
            $clean = preg_replace('/\D+/', '', (string)$candidate);
            if ($clean !== '') {
                return $clean;
            }
        }

        return null;
    }

    private function extractReturnBarcode(array $payload): ?string
    {
        foreach (['ReturnBarCode', 'ReturnBarcode', 'ReturnAWB'] as $field) {
            if (!empty($payload[$field])) {
                $clean = preg_replace('/\D+/', '', (string)$payload[$field]);
                if ($clean !== '') {
                    return $clean;
                }
            }
        }
        return null;
    }

    private function extractReturnDate(array $payload): ?\DateTimeInterface
    {
        foreach (['ReturnDate', 'Return_Date', 'Date', 'ScanDate', 'EventDate'] as $field) {
            if (!empty($payload[$field])) {
                $value = $payload[$field];
                $date = $this->createDateTime($value);
                if ($date !== null) {
                    return $date;
                }
            }
        }
        return null;
    }

    private function createDateTime($value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        $stringValue = is_string($value) ? trim($value) : '';
        if ($stringValue === '') {
            return null;
        }

        $formats = ['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d', 'd.m.Y H:i', 'd.m.Y'];
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $stringValue);
            if ($date !== false) {
                return $date;
            }
        }

        $timestamp = strtotime($stringValue);
        if ($timestamp !== false) {
            return (new \DateTimeImmutable())->setTimestamp($timestamp);
        }

        return null;
    }

    private function extractStatusMessage(array $payload): ?string
    {
        foreach (['StatusDescription', 'StatusDesc', 'StatusMessage', 'Message', 'EventName', 'EventDescription', 'Status'] as $field) {
            if (!empty($payload[$field])) {
                return (string)$payload[$field];
            }
        }

        return null;
    }

    private function notifyWarehouse(array $order, int $returnId, string $returnAwb, ?\DateTimeInterface $returnDate, ?string $notes): void
    {
        if (!$this->notificationsEnabled || empty($this->notificationRecipients)) {
            return;
        }

        try {
            require_once BASE_PATH . '/lib/PHPMailer/PHPMailer.php';
            require_once BASE_PATH . '/lib/PHPMailer/SMTP.php';
            require_once BASE_PATH . '/lib/PHPMailer/Exception.php';

            $mailConfig = $this->config['email'];
            $mailer = new PHPMailer\PHPMailer\PHPMailer(true);

            $mailer->isSMTP();
            $mailer->Host = $mailConfig['host'];
            $mailer->Port = $mailConfig['port'];
            $mailer->SMTPSecure = $mailConfig['encryption'];
            $mailer->SMTPAuth = true;
            $mailer->Username = $mailConfig['username'];
            $mailer->Password = $mailConfig['password'];
            $mailer->setFrom($mailConfig['from_email'], $mailConfig['from_name']);

            foreach ($this->notificationRecipients as $recipient) {
                $mailer->addAddress($recipient);
            }

            $mailer->Subject = sprintf('Return automat creat – Comanda %s', $order['order_number'] ?? $order['id']);

            $bodyLines = [
                'Salutare,',
                '',
                'A fost înregistrată automat o returnare din Cargus în WMS.',
                '',
                sprintf('Comandă: %s', $order['order_number'] ?? $order['id']),
                sprintf('Client: %s', $order['customer_name'] ?? 'N/A'),
                sprintf('Return ID: %d', $returnId),
                sprintf('AWB retur: %s', $returnAwb),
            ];

            if ($returnDate) {
                $bodyLines[] = sprintf('Data retur: %s', $returnDate->format('Y-m-d H:i'));
            }
            if ($notes) {
                $bodyLines[] = '';
                $bodyLines[] = 'Status Cargus: ' . $notes;
            }

            $bodyLines[] = '';
            $bodyLines[] = 'Vă rugăm să verificați dashboard-ul de returnări pentru detalii suplimentare.';
            $bodyLines[] = '';
            $bodyLines[] = 'Mulțumim,';
            $bodyLines[] = 'Sistemul WMS';

            $mailer->Body = implode("\n", $bodyLines);
            $mailer->send();
            $this->log('INFO', 'Notification email sent for automated return', [
                'order_id' => $order['id'],
                'return_id' => $returnId,
                'recipients' => $this->notificationRecipients,
            ]);
        } catch (\Throwable $exception) {
            $this->log('ERROR', 'Failed to send return notification', [
                'order_id' => $order['id'],
                'return_id' => $returnId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
