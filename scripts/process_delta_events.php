<?php
/**
 * Cron entry point for frequent delta event synchronization.
 */

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/services/AutomatedReturnProcessor.php';

$from = $argv[1] ?? null;
$to = $argv[2] ?? null;

try {
    $processor = new AutomatedReturnProcessor();
    $result = $processor->processDeltaEvents($from ?: null, $to ?: null);

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
} catch (\Throwable $exception) {
    $message = sprintf('Delta event sync failed: %s', $exception->getMessage());
    fwrite(STDERR, $message . PHP_EOL);
    if (function_exists('error_log')) {
        error_log($message);
    }
    exit(1);
}
