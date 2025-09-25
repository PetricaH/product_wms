<?php
/**
 * Incident Model
 * File: models/Incident.php
 *
 * Handles CRUD operations for the incident reporting system.
 */

class Incident {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Generate a unique incident number formatted as INCYYYYMMDD###
     */
    public function generateIncidentNumber(): string {
        $prefix = 'INC' . date('Ymd');
        $stmt = $this->db->prepare("SELECT incident_number FROM incidents WHERE incident_number LIKE :prefix ORDER BY incident_number DESC LIMIT 1");
        $stmt->execute([':prefix' => $prefix . '%']);
        $lastNumber = $stmt->fetchColumn();

        if ($lastNumber) {
            $sequence = (int)substr($lastNumber, -3) + 1;
        } else {
            $sequence = 1;
        }

        return sprintf('%s%03d', $prefix, $sequence);
    }

    /**
     * Create an incident and optionally attach photos
     *
     * @param array $data
     * @param array $photos
     * @return array{id:int,incident_number:string}
     */
    public function createIncident(array $data, array $photos = []): array {
        $incidentNumber = $data['incident_number'] ?? $this->generateIncidentNumber();

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO incidents (
                    incident_number, reporter_id, incident_type, title, description,
                    location_id, location_description, severity, status, assigned_admin_id,
                    admin_notes, resolution_notes, occurred_at, estimated_cost, follow_up_required
                ) VALUES (
                    :incident_number, :reporter_id, :incident_type, :title, :description,
                    :location_id, :location_description, :severity, :status, :assigned_admin_id,
                    :admin_notes, :resolution_notes, :occurred_at, :estimated_cost, :follow_up_required
                )
            ");

            $stmt->execute([
                ':incident_number' => $incidentNumber,
                ':reporter_id' => (int)$data['reporter_id'],
                ':incident_type' => $data['incident_type'],
                ':title' => $data['title'],
                ':description' => $data['description'],
                ':location_id' => $data['location_id'] ?? null,
                ':location_description' => $data['location_description'] ?? null,
                ':severity' => $data['severity'] ?? 'medium',
                ':status' => $data['status'] ?? 'reported',
                ':assigned_admin_id' => $data['assigned_admin_id'] ?? null,
                ':admin_notes' => $data['admin_notes'] ?? null,
                ':resolution_notes' => $data['resolution_notes'] ?? null,
                ':occurred_at' => $data['occurred_at'],
                ':estimated_cost' => $data['estimated_cost'] ?? null,
                ':follow_up_required' => !empty($data['follow_up_required']) ? 1 : 0,
            ]);

            $incidentId = (int)$this->db->lastInsertId();

            foreach ($photos as $photo) {
                $this->addPhoto($incidentId, $photo);
            }

            $this->db->commit();

            return [
                'id' => $incidentId,
                'incident_number' => $incidentNumber,
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Fetch all incidents with optional filters
     */
    public function getAllIncidents(array $filters = []): array {
        $sql = "
            SELECT
                i.*, 
                reporter.username AS reporter_name,
                reporter.email AS reporter_email,
                assigned.username AS assigned_admin_name,
                l.location_code AS location_code
            FROM incidents i
            INNER JOIN users reporter ON i.reporter_id = reporter.id
            LEFT JOIN users assigned ON i.assigned_admin_id = assigned.id
            LEFT JOIN locations l ON i.location_id = l.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND i.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['incident_type'])) {
            $sql .= " AND i.incident_type = :incident_type";
            $params[':incident_type'] = $filters['incident_type'];
        }

        if (!empty($filters['severity'])) {
            $sql .= " AND i.severity = :severity";
            $params[':severity'] = $filters['severity'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (i.incident_number LIKE :search OR i.title LIKE :search OR reporter.username LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY i.reported_at DESC";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update incident status and administrative data
     */
    public function updateStatus(int $incidentId, array $payload): bool {
        $fields = [];
        $params = [':id' => $incidentId];

        if (!empty($payload['status'])) {
            $fields[] = 'status = :status';
            $params[':status'] = $payload['status'];
        }

        if (array_key_exists('admin_notes', $payload)) {
            $fields[] = 'admin_notes = :admin_notes';
            $params[':admin_notes'] = $payload['admin_notes'];
        }

        if (array_key_exists('resolution_notes', $payload)) {
            $fields[] = 'resolution_notes = :resolution_notes';
            $params[':resolution_notes'] = $payload['resolution_notes'];
        }

        if (array_key_exists('assigned_admin_id', $payload)) {
            $fields[] = 'assigned_admin_id = :assigned_admin_id';
            $params[':assigned_admin_id'] = $payload['assigned_admin_id'] ?: null;
        }

        if (array_key_exists('follow_up_required', $payload)) {
            $fields[] = 'follow_up_required = :follow_up_required';
            $params[':follow_up_required'] = !empty($payload['follow_up_required']) ? 1 : 0;
        }

        $timestamps = [];
        if (!empty($payload['status'])) {
            if (in_array($payload['status'], ['under_review', 'investigating'], true)) {
                $timestamps[] = 'reviewed_at = COALESCE(reviewed_at, NOW())';
            }
            if (in_array($payload['status'], ['resolved', 'rejected'], true)) {
                $timestamps[] = 'resolved_at = NOW()';
            }
        }

        if (empty($fields) && empty($timestamps)) {
            return false;
        }

        $sql = 'UPDATE incidents SET ' . implode(', ', array_merge($fields, $timestamps)) . ' WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Attach a photo record to an incident
     */
    public function addPhoto(int $incidentId, array $photoData): int {
        $stmt = $this->db->prepare("
            INSERT INTO incident_photos (incident_id, file_path, original_filename, file_size, mime_type)
            VALUES (:incident_id, :file_path, :original_filename, :file_size, :mime_type)
        ");

        $stmt->execute([
            ':incident_id' => $incidentId,
            ':file_path' => $photoData['file_path'],
            ':original_filename' => $photoData['original_filename'],
            ':file_size' => $photoData['file_size'],
            ':mime_type' => $photoData['mime_type'],
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Retrieve a single incident with photos
     */
    public function getIncidentById(int $incidentId): ?array {
        $stmt = $this->db->prepare("
            SELECT
                i.*, reporter.username AS reporter_name, reporter.email AS reporter_email,
                assigned.username AS assigned_admin_name,
                l.location_code
            FROM incidents i
            INNER JOIN users reporter ON i.reporter_id = reporter.id
            LEFT JOIN users assigned ON i.assigned_admin_id = assigned.id
            LEFT JOIN locations l ON i.location_id = l.id
            WHERE i.id = :id
        ");
        $stmt->execute([':id' => $incidentId]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$incident) {
            return null;
        }

        $photosStmt = $this->db->prepare('SELECT * FROM incident_photos WHERE incident_id = :id ORDER BY uploaded_at ASC');
        $photosStmt->execute([':id' => $incidentId]);
        $incident['photos'] = $photosStmt->fetchAll(PDO::FETCH_ASSOC);

        return $incident;
    }

    /**
     * Get counts for unresolved incidents grouped by severity
     */
    public function getUnresolvedCounts(): array {
        $stmt = $this->db->query("
            SELECT severity, COUNT(*) AS total
            FROM incidents
            WHERE status NOT IN ('resolved', 'rejected')
            GROUP BY severity
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $counts = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
        foreach ($rows as $row) {
            $counts[$row['severity']] = (int)$row['total'];
        }
        return $counts;
    }

    /**
     * Get total unresolved incidents
     */
    public function getUnresolvedTotal(): int {
        $stmt = $this->db->query("SELECT COUNT(*) FROM incidents WHERE status NOT IN ('resolved', 'rejected')");
        return (int)$stmt->fetchColumn();
    }
}
