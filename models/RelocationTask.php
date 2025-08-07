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
}
