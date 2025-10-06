<?php

declare(strict_types=1);

class AutoOrderDuplicateGuard
{
    private PDO $conn;
    private string $productsTable;
    private ?int $configuredIntervalCache = null;

    public function __construct(PDO $conn, string $productsTable = 'products')
    {
        $this->conn = $conn;
        $this->productsTable = $productsTable;
    }

    /**
     * @return array{permisa: bool, mesaj: string}
     */
    public function canPlaceAutoOrder(int $productId, int $minIntervalMinutes = 30, bool $forUpdate = false): array
    {
        $intervalMinutes = $this->resolveIntervalMinutes($minIntervalMinutes);

        try {
            $sql = "SELECT last_auto_order_date FROM {$this->productsTable} WHERE product_id = :id";
            if ($forUpdate && $this->supportsRowLocking()) {
                $sql .= ' FOR UPDATE';
            }

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            $ultimaData = $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('[AutoOrder] Nu s-a putut verifica ultima autocomandă pentru produs #' . $productId . ': ' . $e->getMessage());
            return [
                'permisa' => false,
                'mesaj' => 'Verificarea intervalului minim pentru autocomandă a eșuat.'
            ];
        }

        if ($ultimaData === false) {
            $mesaj = 'Produsul nu a fost găsit pentru verificarea autocomenzii.';
            error_log('[AutoOrder] ' . $mesaj . ' ID: ' . $productId);
            return [
                'permisa' => false,
                'mesaj' => $mesaj
            ];
        }

        if ($ultimaData === null || $ultimaData === '' || $ultimaData === '0000-00-00 00:00:00') {
            return [
                'permisa' => true,
                'mesaj' => 'Nu există autocomenzi recente pentru acest produs.'
            ];
        }

        try {
            $ultimaAutocomanda = new DateTimeImmutable($ultimaData);
        } catch (Exception $e) {
            error_log(sprintf('[AutoOrder] Data invalidă "%s" pentru produs #%d: %s', $ultimaData, $productId, $e->getMessage()));
            return [
                'permisa' => true,
                'mesaj' => 'Nu există autocomenzi recente pentru acest produs.'
            ];
        }

        $acum = new DateTimeImmutable('now', $ultimaAutocomanda->getTimezone());
        $difSecunde = $acum->getTimestamp() - $ultimaAutocomanda->getTimestamp();
        $difMinute = (int)floor($difSecunde / 60);
        if ($difMinute < $intervalMinutes) {
            $ramase = max(0, $intervalMinutes - $difMinute);
            $mesaj = sprintf(
                'Ultima autocomandă a fost trimisă la %s (acum %d minute). Intervalul minim este de %d minute. Mai așteptați aproximativ %d minute.',
                $ultimaAutocomanda->format('d.m.Y H:i:s'),
                max(0, $difMinute),
                $intervalMinutes,
                $ramase
            );

            error_log(sprintf(
                '[AutoOrder] Blocked duplicate for product #%d: last=%s, diff=%d min, interval=%d min',
                $productId,
                $ultimaAutocomanda->format(DATE_ATOM),
                max(0, $difMinute),
                $intervalMinutes
            ));

            return [
                'permisa' => false,
                'mesaj' => $mesaj
            ];
        }

        return [
            'permisa' => true,
            'mesaj' => 'Nu există autocomenzi recente pentru acest produs.'
        ];
    }

    public function getConfiguredMinIntervalMinutes(): int
    {
        if ($this->configuredIntervalCache !== null) {
            return $this->configuredIntervalCache;
        }

        $valoare = null;

        if (isset($GLOBALS['config']['autoorders']['min_interval_minutes'])) {
            $valoare = (int)$GLOBALS['config']['autoorders']['min_interval_minutes'];
        } else {
            $caleConfig = $this->resolveConfigPath();
            if ($caleConfig && file_exists($caleConfig)) {
                $config = require $caleConfig;
                if (is_array($config) && isset($config['min_interval_minutes'])) {
                    $valoare = (int)$config['min_interval_minutes'];
                }
            }
        }

        if ($valoare === null) {
            $env = getenv('AUTOORDERS_MIN_INTERVAL_MINUTES');
            if ($env !== false && $env !== '') {
                $valoare = (int)$env;
            }
        }

        if ($valoare === null || $valoare <= 0) {
            $valoare = 30;
        }

        $this->configuredIntervalCache = max(1, (int)$valoare);
        return $this->configuredIntervalCache;
    }

    private function resolveIntervalMinutes(int $minIntervalMinutes): int
    {
        $configurat = $this->getConfiguredMinIntervalMinutes();
        $explicit = max(1, $minIntervalMinutes);
        return max($configurat, $explicit);
    }

    private function resolveConfigPath(): ?string
    {
        if (defined('BASE_PATH')) {
            return rtrim(BASE_PATH, '/') . '/config/autoorders.php';
        }

        return dirname(__DIR__) . '/config/autoorders.php';
    }

    private function supportsRowLocking(): bool
    {
        try {
            $driver = strtolower((string)$this->conn->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable $e) {
            return false;
        }

        return in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true);
    }
}
