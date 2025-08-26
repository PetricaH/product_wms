<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../services/ReturnService.php';

class ReturnServiceTest extends TestCase {
    private PDO $pdo;
    private ReturnService $service;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = file_get_contents(__DIR__ . '/schema_returns.sql');
        $this->pdo->exec($schema);
        $this->service = new ReturnService($this->pdo);

        // seed product and order
        $this->pdo->exec("INSERT INTO products (product_id, sku, name) VALUES (1, 'P1', 'Prod')");
        $this->pdo->exec("INSERT INTO orders (id, order_number, status) VALUES (1, 'O1', 'shipped')");
        $this->pdo->exec("INSERT INTO order_items (id, order_id, product_id, quantity) VALUES (1,1,1,1)");
    }

    public function testStartReturnDuplicate(): void {
        $firstId = $this->service->startReturn('O1', 5);
        $this->assertGreaterThan(0, $firstId);
        $this->expectException(RuntimeException::class);
        $this->service->startReturn('O1', 5);
    }

    public function testInvalidOrderNumber(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->service->startReturn('BAD', 1);
    }

    public function testInventoryConflictCreatesDiscrepancy(): void {
        $rid = $this->service->startReturn('O1', 1);
        $this->service->verifyItem($rid, 1, 5); // more than ordered
        $count = $this->pdo->query('SELECT COUNT(*) FROM return_discrepancies')->fetchColumn();
        $this->assertEquals(1, $count);
    }
}
