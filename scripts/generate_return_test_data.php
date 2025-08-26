<?php
/**
 * Script: generate_return_test_data.php
 * Purpose: Populate database with sample products, orders and returns
 * Usage: php scripts/generate_return_test_data.php
 */

require_once __DIR__ . '/../bootstrap.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = $config['connection_factory']();

$pdo->beginTransaction();

// Sample product
$pdo->exec("INSERT INTO products (product_id, sku, name, stock) VALUES (1001, 'TESTSKU', 'Test Product', 10)");
// Sample order
$pdo->exec("INSERT INTO orders (id, order_number, status, shipped_date, order_date) VALUES (501, 'R-100', 'shipped', NOW(), NOW())");
// Order item
$pdo->exec("INSERT INTO order_items (id, order_id, product_id, quantity) VALUES (7001, 501, 1001, 2)");

// Duplicate return edge case
$pdo->exec("INSERT INTO returns (id, order_id, processed_by, status) VALUES (9001, 501, 1, 'in_progress')");

$pdo->commit();

echo "Sample data inserted\n";
