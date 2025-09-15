<?php
class BarcodeCaptureTask {
    private PDO $conn;
    private string $table = 'barcode_capture_tasks';

    public function __construct(PDO $db) {
        $this->conn = $db;
    }

    public function createTask(int $productId, int $locationId, int $expectedQuantity, int $createdBy): int|false {
        $sql = "INSERT INTO {$this->table} (product_id, location_id, expected_quantity, created_by) VALUES (:product_id, :location_id, :expected_quantity, :created_by)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindParam(':location_id', $locationId, PDO::PARAM_INT);
        $stmt->bindParam(':expected_quantity', $expectedQuantity, PDO::PARAM_INT);
        $stmt->bindParam(':created_by', $createdBy, PDO::PARAM_INT);
        if ($stmt->execute()) {
            return (int)$this->conn->lastInsertId();
        }
        return false;
    }

    public function getPendingTasks(): array {
        $sql = "SELECT t.*, p.name AS product_name, l.location_code
                FROM {$this->table} t
                LEFT JOIN products p ON t.product_id = p.product_id
                LEFT JOIN locations l ON t.location_id = l.id
                WHERE t.status IN ('pending','in_progress')
                ORDER BY t.created_at ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTaskById(int $taskId, bool $forUpdate = false): ?array {
        $sql = "SELECT t.*, p.name AS product_name, l.location_code
                FROM {$this->table} t
                LEFT JOIN products p ON t.product_id = p.product_id
                LEFT JOIN locations l ON t.location_id = l.id
                WHERE t.task_id = :task_id" . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':task_id', $taskId, PDO::PARAM_INT);
        $stmt->execute();
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        return $task ?: null;
    }

    public function assignToWorker(int $taskId, int $userId): bool {
        $sql = "UPDATE {$this->table} SET assigned_to = :user_id WHERE task_id = :task_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':task_id', $taskId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function incrementScannedQuantity(int $taskId, int $increment = 1): bool {
        $sql = "UPDATE {$this->table} SET scanned_quantity = scanned_quantity + :inc, status = 'in_progress' WHERE task_id = :task_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':inc', $increment, PDO::PARAM_INT);
        $stmt->bindParam(':task_id', $taskId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function markCompleted(int $taskId): bool {
        $sql = "UPDATE {$this->table} SET status = 'completed', completed_at = NOW() WHERE task_id = :task_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':task_id', $taskId, PDO::PARAM_INT);
        return $stmt->execute();
    }
    public function decrementScannedQuantity(int $taskId, int $decrement = 1): bool {
        $sql = "UPDATE {$this->table} SET scanned_quantity = GREATEST(scanned_quantity - :dec,0), status = CASE WHEN scanned_quantity - :dec <= 0 THEN 'pending' ELSE 'in_progress' END WHERE task_id = :task_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':dec', $decrement, PDO::PARAM_INT);
        $stmt->bindParam(':task_id', $taskId, PDO::PARAM_INT);
        return $stmt->execute();
    }
}
