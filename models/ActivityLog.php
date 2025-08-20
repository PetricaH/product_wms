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
        string $entityType,
        int|string $entityId,
        string $description,
        $oldValues = null,
        $newValues = null,
        ?string $resourceType = null,
        int|string|null $resourceId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): bool {
        $query = "INSERT INTO {$this->table}
                  (user_id, action, entity_type, entity_id, resource_type, resource_id, description, old_values, new_values, ip_address, user_agent, created_at)
                  VALUES (:user_id, :action, :entity_type, :entity_id, :resource_type, :resource_id, :description, :old_values, :new_values, :ip_address, :user_agent, NOW())";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':action', $action);
            $stmt->bindValue(':entity_type', $entityType);
            $stmt->bindValue(':entity_id', $entityId);
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

    /**
     * Get total number of log entries.
     */
    public function getTotalCount(): int {
        try {
            $stmt = $this->conn->query("SELECT COUNT(*) as total FROM {$this->table}");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['total'] ?? 0);
        } catch (PDOException $e) {
            error_log('ActivityLog count error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Retrieve paginated activity log entries with usernames.
     */
    public function getLogsPaginated(int $limit, int $offset): array {
        $query = "SELECT al.*, u.username
                  FROM {$this->table} al
                  LEFT JOIN users u ON al.user_id = u.id
                  ORDER BY al.created_at DESC
                  LIMIT :limit OFFSET :offset";
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('ActivityLog getLogsPaginated error: ' . $e->getMessage());
            return [];
        }
    }
}
?>
