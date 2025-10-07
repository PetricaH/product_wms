<?php

declare(strict_types=1);

class Factura
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Insert a new invoice record.
     *
     * @param array $data
     * @return int Inserted invoice ID
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO facturi
            (nr_factura, nume_firma, cif, reg_com, adresa, data_emitere, termen_plata, suma, status, file_path, somatie_path, sha256)
            VALUES
            (:nr_factura, :nume_firma, :cif, :reg_com, :adresa, :data_emitere, :termen_plata, :suma, :status, :file_path, :somatie_path, :sha256)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':nr_factura' => $data['nr_factura'],
            ':nume_firma' => $data['nume_firma'],
            ':cif' => $data['cif'] ?? null,
            ':reg_com' => $data['reg_com'] ?? null,
            ':adresa' => $data['adresa'] ?? null,
            ':data_emitere' => $data['data_emitere'] ?? null,
            ':termen_plata' => $data['termen_plata'] ?? null,
            ':suma' => $data['suma'] ?? 0,
            ':status' => $data['status'] ?? 'neplatita',
            ':file_path' => $data['file_path'] ?? null,
            ':somatie_path' => $data['somatie_path'] ?? null,
            ':sha256' => $data['sha256'] ?? null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Retrieve invoices with filters and pagination.
     *
     * @param array $filters
     * @return array
     */
    public function getAll(array $filters = []): array
    {
        $params = [];
        $where = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'data_emitere >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'data_emitere <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(nr_factura LIKE :search OR nume_firma LIKE :search OR cif LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $baseQuery = 'FROM facturi';
        if ($where) {
            $baseQuery .= ' WHERE ' . implode(' AND ', $where);
        }

        $total = (int)$this->db->query('SELECT COUNT(*) FROM facturi')->fetchColumn();

        $countStmt = $this->db->prepare('SELECT COUNT(*) ' . $baseQuery);
        $countStmt->execute($params);
        $filtered = (int)$countStmt->fetchColumn();

        $columns = [
            'nr_factura',
            'nume_firma',
            'cif',
            'data_emitere',
            'termen_plata',
            'suma',
            'status',
            'created_at'
        ];

        $orderColumnIndex = isset($filters['order_column'], $columns[$filters['order_column']])
            ? (int)$filters['order_column']
            : 7;
        $orderColumn = $columns[$orderColumnIndex];
        $orderDirection = strtoupper($filters['order_dir'] ?? 'DESC');
        if (!in_array($orderDirection, ['ASC', 'DESC'], true)) {
            $orderDirection = 'DESC';
        }

        $limit = (int)($filters['length'] ?? 25);
        $offset = (int)($filters['start'] ?? 0);
        if ($limit < 1 || $limit > 500) {
            $limit = 25;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        $sql = 'SELECT * ' . $baseQuery . " ORDER BY $orderColumn $orderDirection LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $rows,
            'total' => $total,
            'filtered' => $filtered,
        ];
    }

    /**
     * Retrieve an invoice by ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM facturi WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Get aggregated statistics.
     */
    public function getStats(): array
    {
        $stmt = $this->db->query("SELECT
                COUNT(*) AS total,
                SUM(status = 'neplatita') AS neplatite,
                SUM(status = 'platita') AS platite,
                COALESCE(SUM(suma), 0) AS suma_totala
            FROM facturi");

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int)($row['total'] ?? 0),
            'neplatite' => (int)($row['neplatite'] ?? 0),
            'platite' => (int)($row['platite'] ?? 0),
            'suma_totala' => (float)($row['suma_totala'] ?? 0),
        ];
    }

    /**
     * Update invoice status.
     */
    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare('UPDATE facturi SET status = :status WHERE id = :id');
        return $stmt->execute([
            ':status' => $status,
            ':id' => $id,
        ]);
    }

    /**
     * Delete an invoice.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM facturi WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }
}
