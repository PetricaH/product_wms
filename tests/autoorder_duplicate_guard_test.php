<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/AutoOrderDuplicateGuard.php';

function setupTestDatabase(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        last_auto_order_date DATETIME NULL
    )');

    $pdo->exec('CREATE TABLE purchase_orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_number TEXT,
        seller_id INTEGER,
        total_amount REAL,
        currency TEXT,
        custom_message TEXT,
        email_subject TEXT,
        status TEXT,
        expected_delivery_date DATETIME NULL,
        email_recipient TEXT,
        notes TEXT,
        pdf_path TEXT,
        tax_rate INTEGER,
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->prepare('INSERT INTO products (name, last_auto_order_date) VALUES (:name, NULL)')
        ->execute([':name' => 'Produs Test']);
}

function simulateAutoOrder(
    PDO $pdo,
    AutoOrderDuplicateGuard $guard,
    int $productId,
    int $intervalMinutes,
    string $source
): array {
    $pdo->beginTransaction();
    $check = $guard->canPlaceAutoOrder($productId, $intervalMinutes, true);
    if (!$check['permisa']) {
        $pdo->rollBack();
        return [
            'created' => false,
            'source' => $source,
            'reason' => $check['mesaj']
        ];
    }

    $orderNumber = sprintf('PO-%s-%s', strtoupper(substr($source, 0, 1)), substr(uniqid('', true), -6));
    $stmt = $pdo->prepare('INSERT INTO purchase_orders (
        order_number, seller_id, total_amount, currency, custom_message,
        email_subject, status, expected_delivery_date, email_recipient,
        notes, pdf_path, tax_rate, created_by
    ) VALUES (
        :order_number, 1, 0, "RON", NULL,
        NULL, "sent", NULL, NULL,
        :notes, NULL, 0, 1
    )');
    $stmt->execute([
        ':order_number' => $orderNumber,
        ':notes' => $source
    ]);

    $pdo->prepare('UPDATE products SET last_auto_order_date = CURRENT_TIMESTAMP WHERE product_id = :id')
        ->execute([':id' => $productId]);

    $pdo->commit();

    return [
        'created' => true,
        'source' => $source,
        'order_number' => $orderNumber,
        'last_auto_order_date' => $pdo->query('SELECT last_auto_order_date FROM products WHERE product_id = ' . (int)$productId)->fetchColumn()
    ];
}

function printResult(array $result): void
{
    if ($result['created']) {
        printf(
            "%s ➜ creat (%s) la %s\n",
            str_pad($result['source'], 12, ' ', STR_PAD_RIGHT),
            $result['order_number'],
            $result['last_auto_order_date']
        );
    } else {
        printf(
            "%s ➜ blocat: %s\n",
            str_pad($result['source'], 12, ' ', STR_PAD_RIGHT),
            $result['reason']
        );
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

setupTestDatabase($pdo);

$guard = new AutoOrderDuplicateGuard($pdo, 'products');
$interval = max(1, $guard->getConfiguredMinIntervalMinutes());

printf("Interval minim configurat: %d minute\n", $interval);

$initial = simulateAutoOrder($pdo, $guard, 1, $interval, 'scheduled');
printResult($initial);

$immediateReactive = simulateAutoOrder($pdo, $guard, 1, $interval, 'reactive');
printResult($immediateReactive);

$pdo->prepare('UPDATE products SET last_auto_order_date = datetime(?, ? ) WHERE product_id = 1')
    ->execute([date('Y-m-d H:i:s'), sprintf('-%d minutes', $interval + 1)]);

$afterInterval = simulateAutoOrder($pdo, $guard, 1, $interval, 'after-wait');
printResult($afterInterval);

$pdo->prepare('UPDATE products SET last_auto_order_date = datetime(?, ? ) WHERE product_id = 1')
    ->execute([date('Y-m-d H:i:s'), sprintf('-%d minutes', $interval + 1)]);

$concurrentFirst = simulateAutoOrder($pdo, $guard, 1, $interval, 'concurrent-A');
printResult($concurrentFirst);

$concurrentSecond = simulateAutoOrder($pdo, $guard, 1, $interval, 'concurrent-B');
printResult($concurrentSecond);

$ordersCount = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn();
$lastDate = $pdo->query('SELECT last_auto_order_date FROM products WHERE product_id = 1')->fetchColumn();

printf("Comenzi create: %d\n", $ordersCount);
printf("Ultima autocomandă salvată: %s\n", $lastDate);
