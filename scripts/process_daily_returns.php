<?php
/**
 * Cron entry point for daily Cargus return synchronization.
 */

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/services/AutomatedReturnProcessor.php';

$date = $argv[1] ?? null;

try {
    $processor = new AutomatedReturnProcessor();
    $result = $processor->processDailyReturns($date ?: null);

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
} catch (\Throwable $exception) {
    $message = sprintf('Automated return sync failed: %s', $exception->getMessage());
    fwrite(STDERR, $message . PHP_EOL);
    if (function_exists('error_log')) {
        error_log($message);
    }
    exit(1);
}
