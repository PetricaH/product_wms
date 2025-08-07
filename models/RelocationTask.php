<?php
/**
 * Model for relocation tasks moving stock from temporary to designated locations
 */
class RelocationTask {
    private $conn;
    private $table = 'relocation_tasks';

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
}
