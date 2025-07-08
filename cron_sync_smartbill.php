<?php
/**
 * SmartBill Stock Synchronization Script
 * Run via cron to keep product stocks updated using SmartBill API.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    die("Database connection factory not configured.\n");
}

$dbFactory = $config['connection_factory'];
$db = $dbFactory();

require_once BASE_PATH . '/models/SmartBillService.php';

$service = new SmartBillService($db);
$result = $service->syncProductsFromSmartBill();

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
