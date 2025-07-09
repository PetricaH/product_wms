<?php
class ActivityLog {
    private PDO $conn;
    private string $table = 'activity_logs';

    public function __construct(PDO $db) {
        $this->conn = $db;
    }

    /**
     * Write an activity log entry.
     */
    public function log(
        int $userId,
        string $action,
        string $resourceType,
        int|string $resourceId,
        string $description,
        $oldValues = null,
        $newValues = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): bool {
        $query = "INSERT INTO {$this->table}
                  (user_id, action, resource_type, resource_id, description, old_values, new_values, ip_address, user_agent, created_at)
                  VALUES (:user_id, :action, :resource_type, :resource_id, :description, :old_values, :new_values, :ip_address, :user_agent, NOW())";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':action', $action);
            $stmt->bindValue(':resource_type', $resourceType);
            $stmt->bindValue(':resource_id', $resourceId);
            $stmt->bindValue(':description', $description);
            $stmt->bindValue(':old_values', $oldValues !== null ? json_encode($oldValues) : null);
            $stmt->bindValue(':new_values', $newValues !== null ? json_encode($newValues) : null);
            $stmt->bindValue(':ip_address', $ipAddress);
            $stmt->bindValue(':user_agent', $userAgent);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log('ActivityLog error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve activity log entries.
     */
    public function getAll(int $limit = 50, int $offset = 0): array {
        $query = "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('ActivityLog getAll error: ' . $e->getMessage());
            return [];
        }
    }
}
?>
