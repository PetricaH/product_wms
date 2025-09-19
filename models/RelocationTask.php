<?php
/**
 * Model for relocation tasks moving stock from temporary to designated locations
 */
class RelocationTask {
    private $conn;
    private $table = 'relocation_tasks';
    private ?bool $transactionTableExists = null;

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Create a relocation task
     */
    public function createTask(int $productId, int $fromLocationId, int $toLocationId, int $quantity, string $status = 'pending'): bool {
        try {
            $query = "INSERT INTO {$this->table} (product_id, from_location_id, to_location_id, quantity, status, created_at)
                      VALUES (:product_id, :from_location_id, :to_location_id, :quantity, :status, NOW())";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':product_id', $productId, PDO::PARAM_INT);
            $stmt->bindParam(':from_location_id', $fromLocationId, PDO::PARAM_INT);
            $stmt->bindParam(':to_location_id', $toLocationId, PDO::PARAM_INT);
            $stmt->bindParam(':quantity', $quantity, PDO::PARAM_INT);
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error creating relocation task: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Activate pending tasks for a location when space is available
     */
    public function activatePendingTasks(int $locationId): void {
        try {
            $query = "SELECT id, quantity FROM {$this->table} WHERE to_location_id = :loc AND status = 'pending'";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':loc', $locationId, PDO::PARAM_INT);
            $stmt->execute();
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$tasks) {
                return;
            }

            $capacityQuery = "SELECT capacity, current_occupancy FROM locations WHERE id = :loc";
            $capStmt = $this->conn->prepare($capacityQuery);
            $capStmt->bindParam(':loc', $locationId, PDO::PARAM_INT);
            $capStmt->execute();
            $info = $capStmt->fetch(PDO::FETCH_ASSOC);
            if (!$info) {
                return;
            }

            $available = ($info['capacity'] ?? 0) - ($info['current_occupancy'] ?? 0);
            foreach ($tasks as $task) {
                if ($available <= 0) {
                    break;
                }
                $update = "UPDATE {$this->table} SET status = 'ready', updated_at = NOW() WHERE id = :id";
                $uStmt = $this->conn->prepare($update);
                $uStmt->bindParam(':id', $task['id'], PDO::PARAM_INT);
                $uStmt->execute();
                $available -= (int)$task['quantity'];
            }
        } catch (PDOException $e) {
            error_log("Error activating relocation tasks: " . $e->getMessage());
        }
    }
    /**
     * Fetch tasks that are ready to be processed
     */
    public function getReadyTasks(): array {
        try {
            $query = "SELECT rt.id, rt.product_id, rt.from_location_id, rt.to_location_id, rt.quantity,
                             p.name AS product_name, fl.name AS from_location_name, tl.name AS to_location_name
                      FROM {$this->table} rt
                      JOIN products p ON rt.product_id = p.id
                      JOIN locations fl ON rt.from_location_id = fl.id
                      JOIN locations tl ON rt.to_location_id = tl.id
                      WHERE rt.status = 'ready'
                      ORDER BY rt.created_at ASC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching relocation tasks: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Count relocation tasks that are ready to process
     */
    public function countReadyTasks(): int {
        try {
            $query = "SELECT COUNT(*) FROM {$this->table} WHERE status = 'ready'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error counting relocation tasks: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Update the status of a relocation task
     */
    public function updateStatus(int $taskId, string $status): bool {
        try {
            $query = "UPDATE {$this->table} SET status = :status, updated_at = NOW() WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->bindParam(':id', $taskId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error updating relocation task status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log inventory movements for a completed relocation task
     */
    public function logRelocationMovements(array $task, int $userId, array $quantitySnapshot = []): void
    {
        if (!$this->hasInventoryTransactionTable()) {
            return;
        }
        $taskId = (int)($task['id'] ?? 0);
        $productId = (int)($task['product_id'] ?? 0);
        $fromLocationId = (int)($task['from_location_id'] ?? 0);
        $toLocationId = (int)($task['to_location_id'] ?? 0);
        $quantity = (int)($task['quantity'] ?? 0);

        if ($taskId <= 0 || $productId <= 0 || $fromLocationId <= 0 || $toLocationId <= 0 || $quantity <= 0) {
            return;
        }

        if ($userId <= 0) {
            $userId = (int)($task['user_id'] ?? $task['assigned_to'] ?? 1);
            if ($userId <= 0) {
                $userId = 1;
            }
        }
        $locationCodes = $this->getLocationCodes([$fromLocationId, $toLocationId]);
        $fromCode = $locationCodes[$fromLocationId] ?? 'N/A';
        $toCode = $locationCodes[$toLocationId] ?? 'N/A';

        $fromBefore = array_key_exists('from_before', $quantitySnapshot)
            ? (int)$quantitySnapshot['from_before']
            : null;
        $toBefore = array_key_exists('to_before', $quantitySnapshot)
            ? (int)$quantitySnapshot['to_before']
            : null;

        if ($fromBefore === null) {
            $fromBefore = $this->getInventoryQuantity($productId, $fromLocationId) + $quantity;
        }
        if ($toBefore === null) {
            $currentTo = $this->getInventoryQuantity($productId, $toLocationId);
            $toBefore = max(0, $currentTo - $quantity);
        }

        $fromAfter = max(0, $fromBefore - $quantity);
        $toAfter = $toBefore + $quantity;

        $sessionId = sprintf('relocation-%d', $taskId);

        $entries = [
            [
                'location_id' => $fromLocationId,
                'source_location_id' => $toLocationId,
                'quantity_change' => -$quantity,
                'quantity_before' => $fromBefore,
                'quantity_after' => $fromAfter,
                'notes' => sprintf('Relocation OUT → %s', $toCode),
            ],
            [
                'location_id' => $toLocationId,
                'source_location_id' => $fromLocationId,
                'quantity_change' => $quantity,
                'quantity_before' => $toBefore,
                'quantity_after' => $toAfter,
                'notes' => sprintf('Relocation IN ← %s', $fromCode),
            ],
        ];

        $sql = "INSERT INTO inventory_transactions (
                    transaction_type,
                    quantity_change,
                    quantity_before,
                    quantity_after,
                    product_id,
                    location_id,
                    source_location_id,
                    user_id,
                    reference_type,
                    reference_id,
                    reason,
                    notes,
                    session_id
                ) VALUES (
                    :transaction_type,
                    :quantity_change,
                    :quantity_before,
                    :quantity_after,
                    :product_id,
                    :location_id,
                    :source_location_id,
                    :user_id,
                    :reference_type,
                    :reference_id,
                    :reason,
                    :notes,
                    :session_id
                )";

        $stmt = $this->conn->prepare($sql);

        foreach ($entries as $entry) {
            try {
                $stmt->execute([
                    ':transaction_type' => 'move',
                    ':quantity_change' => $entry['quantity_change'],
                    ':quantity_before' => $entry['quantity_before'],
                    ':quantity_after' => $entry['quantity_after'],
                    ':product_id' => $productId,
                    ':location_id' => $entry['location_id'],
                    ':source_location_id' => $entry['source_location_id'],
                    ':user_id' => $userId,
                    ':reference_type' => 'system_auto',
                    ':reference_id' => $taskId,
                    ':reason' => 'Relocation',
                    ':notes' => $entry['notes'],
                    ':session_id' => $sessionId,
                ]);
            } catch (PDOException $e) {
                error_log('Failed to record relocation transaction: ' . $e->getMessage());
            }
        }
    }

    private function getLocationCodes(array $locationIds): array
    {
        if (empty($locationIds)) {
            return [];
        }

        $uniqueIds = array_values(array_unique(array_filter($locationIds, fn($id) => (int)$id > 0)));
        if (empty($uniqueIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($uniqueIds), '?'));
        $query = "SELECT id, location_code FROM locations WHERE id IN ($placeholders)";
        $stmt = $this->conn->prepare($query);
        foreach ($uniqueIds as $index => $id) {
            $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $codes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $codes[(int)$row['id']] = $row['location_code'];
        }

        return $codes;
    }

    private function hasInventoryTransactionTable(): bool
    {
        if ($this->transactionTableExists !== null) {
            return $this->transactionTableExists;
        }

        try {
            $stmt = $this->conn->query("SHOW TABLES LIKE 'inventory_transactions'");
            $this->transactionTableExists = $stmt && $stmt->fetchColumn() ? true : false;
        } catch (PDOException $e) {
            $this->transactionTableExists = false;
        }

        return $this->transactionTableExists;
    }

    private function getInventoryQuantity(int $productId, int $locationId): int
    {
        if ($productId <= 0 || $locationId <= 0) {
            return 0;
        }

        try {
            $stmt = $this->conn->prepare('SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE product_id = :product_id AND location_id = :location_id');
            $stmt->execute([
                ':product_id' => $productId,
                ':location_id' => $locationId,
            ]);

            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
}
