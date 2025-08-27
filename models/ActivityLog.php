<?php
class ActivityLog {
    private PDO $conn;
    private string $table = 'activity_logs';
    private ?array $tableColumns = null;

    public function __construct(PDO $db) {
        $this->conn = $db;
        $this->detectTableColumns();
    }

    /**
     * Detect what columns exist in the activity_logs table
     */
    private function detectTableColumns(): void {
        try {
            $stmt = $this->conn->query("DESCRIBE {$this->table}");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $this->tableColumns = $columns;
        } catch (PDOException $e) {
            error_log('ActivityLog: Failed to detect table columns: ' . $e->getMessage());
            $this->tableColumns = [];
        }
    }

    /**
     * Check if a column exists in the table
     */
    private function hasColumn(string $columnName): bool {
        return in_array($columnName, $this->tableColumns ?? []);
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
        // Build dynamic query based on table structure
        $columns = [
            'user_id', 'action', 'resource_type', 'resource_id', 
            'description', 'old_values', 'new_values', 'ip_address', 
            'user_agent', 'created_at'
        ];
        
        $values = [
            ':user_id', ':action', ':resource_type', ':resource_id',
            ':description', ':old_values', ':new_values', ':ip_address',
            ':user_agent', 'NOW()'
        ];

        // Add entity_type and entity_id if they exist (production schema)
        if ($this->hasColumn('entity_type')) {
            $columns[] = 'entity_type';
            $values[] = ':entity_type';
        }
        
        if ($this->hasColumn('entity_id')) {
            $columns[] = 'entity_id';
            $values[] = ':entity_id';
        }

        $query = "INSERT INTO {$this->table} 
                  (" . implode(', ', $columns) . ") 
                  VALUES (" . implode(', ', $values) . ")";

        try {
            $stmt = $this->conn->prepare($query);
            
            // Bind standard parameters
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':action', $action);
            $stmt->bindValue(':resource_type', $resourceType);
            $stmt->bindValue(':resource_id', $resourceId);
            $stmt->bindValue(':description', $description);
            $stmt->bindValue(':old_values', $oldValues !== null ? json_encode($oldValues) : null);
            $stmt->bindValue(':new_values', $newValues !== null ? json_encode($newValues) : null);
            $stmt->bindValue(':ip_address', $ipAddress);
            $stmt->bindValue(':user_agent', $userAgent);
            
            // Bind additional parameters if columns exist
            if ($this->hasColumn('entity_type')) {
                // Use resource_type as entity_type for backward compatibility
                $stmt->bindValue(':entity_type', $resourceType);
            }
            
            if ($this->hasColumn('entity_id')) {
                // Use resource_id as entity_id, but handle NULL case
                $entityId = $resourceId ?: 0; // Convert NULL to 0 for NOT NULL column
                $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
            }
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log('ActivityLog error: ' . $e->getMessage());
            error_log('ActivityLog query: ' . $query);
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

    /**
     * Debug method to show table structure
     */
    public function getTableInfo(): array {
        return [
            'columns' => $this->tableColumns,
            'has_entity_type' => $this->hasColumn('entity_type'),
            'has_entity_id' => $this->hasColumn('entity_id')
        ];
    }
}