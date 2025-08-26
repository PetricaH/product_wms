<?php
/**
 * Script: generate_return_test_data.php
 * Purpose: Populate database with sample products, orders and returns
 * Usage: php scripts/generate_return_test_data.php
 */

require_once __DIR__ . '/../bootstrap.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = $config['connection_factory']();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $pdo->beginTransaction();

    // Clean up any old test data
    $pdo->exec("DELETE FROM returns WHERE id = 9001");
    $pdo->exec("DELETE FROM order_items WHERE id = 7001");
    $pdo->exec("DELETE FROM orders WHERE id = 501");
    $pdo->exec("DELETE FROM products WHERE product_id = 1001");

    // Ensure a dummy user with id=1 exists for created_by / processed_by FKs
    $pdo->exec("
        INSERT INTO users (id, username, email, password, role)
        VALUES (1, 'testuser', 'test@example.com', 'testpass', 'admin')
        ON DUPLICATE KEY UPDATE 
            username = VALUES(username),
            email = VALUES(email),
            password = VALUES(password),
            role = VALUES(role)
    ");

    // Sample product
    $pdo->exec("
        INSERT INTO products (product_id, sku, name, quantity)
        VALUES (1001, 'TESTSKU', 'Test Product', 10)
    ");

    // Sample order
    $pdo->exec("
        INSERT INTO orders (id, order_number, type, status, shipped_date, order_date, created_by)
        VALUES (501, 'R-100', 'outbound', 'shipped', NOW(), NOW(), 1)
    ");

    // Order item
    $pdo->exec("
        INSERT INTO order_items (id, order_id, product_id, quantity_ordered, quantity)
        VALUES (7001, 501, 1001, 2, 2)
    ");

    // Return
    $pdo->exec("
        INSERT INTO returns (id, order_id, processed_by, status, created_by)
        VALUES (9001, 501, 1, 'pending', 1)
    ");

    $pdo->commit();
    echo "Sample data inserted\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Error: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
