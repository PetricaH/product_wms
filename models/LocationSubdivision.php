<?php
class LocationSubdivision {
    private PDO $conn;
    private string $table = 'location_subdivisions';

    public function __construct(PDO $connection) {
        $this->conn = $connection;
    }

    public function getAllSubdivisions(int $locationId): array {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE location_id = :loc ORDER BY level_number, subdivision_number");
        $stmt->execute([':loc' => $locationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $grouped = [];
        foreach ($rows as $row) {
            $lvl = (int)$row['level_number'];
            $grouped[$lvl][] = $row;
        }
        return $grouped;
    }

    public function syncSubdivisions(int $locationId, int $levelNumber, array $settings): bool {
        $count = max(1, (int)($settings['subdivision_count'] ?? 1));
        try {
            $query = "INSERT INTO {$this->table}
                (location_id, level_number, subdivision_number, items_capacity, dedicated_product_id, allow_other_products, notes)
                VALUES (:loc, :lvl, :sub, :cap, :ded, :allow, :notes)
                ON DUPLICATE KEY UPDATE
                    items_capacity = VALUES(items_capacity),
                    dedicated_product_id = VALUES(dedicated_product_id),
                    allow_other_products = VALUES(allow_other_products),
                    notes = VALUES(notes),
                    updated_at = CURRENT_TIMESTAMP";
            $stmt = $this->conn->prepare($query);
            for ($i = 1; $i <= $count; $i++) {
                $stmt->execute([
                    ':loc' => $locationId,
                    ':lvl' => $levelNumber,
                    ':sub' => $i,
                    ':cap' => $settings['items_capacity'] ?? null,
                    ':ded' => $settings['dedicated_product_id'] ?? null,
                    ':allow' => $settings['allow_other_products'] ?? true,
                    ':notes' => $settings['notes'] ?? null
                ]);
            }
            $del = $this->conn->prepare("DELETE FROM {$this->table} WHERE location_id = :loc AND level_number = :lvl AND subdivision_number > :cnt");
            $del->execute([':loc' => $locationId, ':lvl' => $levelNumber, ':cnt' => $count]);
            return true;
        } catch (PDOException $e) {
            error_log('Error syncing subdivisions: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteSubdivisions(int $locationId, int $levelNumber): bool {
        try {
            $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE location_id = :loc AND level_number = :lvl");
            return $stmt->execute([':loc' => $locationId, ':lvl' => $levelNumber]);
        } catch (PDOException $e) {
            error_log('Error deleting subdivisions: ' . $e->getMessage());
            return false;
        }
    }
}
