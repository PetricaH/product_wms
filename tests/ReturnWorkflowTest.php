<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../services/ReturnService.php';

class ReturnWorkflowTest extends TestCase {
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
    }

    public function testFullWorkflow(): void {
        $rid = $this->service->startReturn('O1', 1);
        $this->service->verifyItem($rid, 1, 1);
        $this->service->completeReturn($rid, 2);
        $status = $this->pdo->query('SELECT status FROM returns WHERE id = ' . (int)$rid)->fetchColumn();
        $this->assertEquals('completed', $status);
    }
}
