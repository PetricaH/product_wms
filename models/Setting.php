<?php
class Setting {
    private $conn;
    private $table = 'settings';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function get($key) {
        $stmt = $this->conn->prepare("SELECT setting_value, setting_type FROM {$this->table} WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return $this->castValue($row['setting_value'], $row['setting_type']);
    }

    public function set($key, $value, $type = 'string') {
        $stmt = $this->conn->prepare("INSERT INTO {$this->table} (setting_key, setting_value, setting_type) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), updated_at = CURRENT_TIMESTAMP");
        return $stmt->execute([$key, $value, $type]);
    }

    public function getMultiple(array $keys) {
        if (empty($keys)) return [];
        $in = str_repeat('?,', count($keys)-1) . '?';
        $stmt = $this->conn->prepare("SELECT setting_key, setting_value, setting_type FROM {$this->table} WHERE setting_key IN ($in)");
        $stmt->execute($keys);
        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[$row['setting_key']] = $this->castValue($row['setting_value'], $row['setting_type']);
        }
        return $results;
    }

    public function setMultiple(array $data) {
        $this->conn->beginTransaction();
        try {
            foreach ($data as $key => $value) {
                $type = is_int($value) ? 'integer' : 'string';
                $this->set($key, $value, $type);
            }
            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            return false;
        }
    }

    private function castValue($value, $type) {
        return match($type) {
            'integer' => (int)$value,
            'decimal' => (float)$value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
