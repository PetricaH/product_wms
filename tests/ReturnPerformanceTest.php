<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../services/ReturnService.php';

class ReturnPerformanceTest extends TestCase {
    private PDO $pdo;
    private ReturnService $service;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = file_get_contents(__DIR__ . '/schema_returns.sql');
        $this->pdo->exec($schema);
        $this->service = new ReturnService($this->pdo);

        $this->pdo->exec("INSERT INTO products (product_id, sku, name) VALUES (1, 'P1', 'Prod')");
        $this->pdo->exec("INSERT INTO orders (id, order_number, status) VALUES (1, 'O1', 'shipped')");
        $this->pdo->exec("INSERT INTO order_items (id, order_id, product_id, quantity) VALUES (1,1,1,1)");
        $this->returnId = $this->service->startReturn('O1', 1);
    }

    public function testBarcodeScanPerformance(): void {
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $this->service->verifyItem($this->returnId, 1, 1, 'good', true);
        }
        $elapsed = microtime(true) - $start;
        $this->assertLessThan(2, $elapsed, 'Scanning took too long');
    }
}
