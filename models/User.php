<?php

// file: models/Users.php
class Users {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // finds a user by their username and email
    public function findByUsernameOrEmail(string $identifier): array|false {
        try {
            $stmt = $this->db->prepare("SELECT id, username, email, password, role, status
                                        FROM users
                                        WHERE (username = :identifier OR email = :identifier_email)
                                        AND status = 1
                                        LIMIT 1");
            $stmt->bindParam(':identifier', $identifier, PDO::PARAM_STR);
            $stmt->bindParam(':identifier_email', $identifier, PDO::PARAM_STR);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user ?: false;
        } catch (PDOException $e) {
            error_log("Error in  Users::findByUsernameOrEmail: " . $e->getMessage());
            return false;
        }
    }

    // counts all registered active users
    public function countAllUsers(): int {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM users WHERE status = 1");
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error in Users::countAllUsers: " . $e->getMessage());
            return 0;
        }
    }

    // finds a user by their id
    public function findById(int $id): array|false {
        try {
            $stmt = $this->db->prepare("SELECT id, username, email, role, status FROM users WHERE id = :id LIMIT 1");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user ?: false;
        } catch (PDOException $e) {
            error_log("Error in Users::findById: " . $e->getMessage());
            return false;
        }
        return false;
    }

    // create a new user 
    public function createUser(array $data): int|false {
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        try {
            $stmt = $this->db->prepare("INSERT INTO users (username, email, password, role, status) VALUES (:username, :email, :password, :role, :status)");
            $stmt->bindParam(':username', $data['username']);
            $stmt->bindParam(':email', $data['email']);
            $stmt->bindParam(':password', $data['password']);
            $stmt->bindParam(':role', $data['role']);
            $stmt->bindParam(':status', $data['status'] ?? 1, PDO::PARAM_INT);
            $stmt->execute();
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error in Users::createUser: " . $e->getMessage());
            return false;
        }
        return false;
    }

    // updates an existing user
    public function updateUser(int $id, array $data): bool {
        $fields = [];
        $params = [':id' => $id];
        if (isset($data['username'])) { $fields[] = "username = :username"; $params['username'] = $data['username']; }
        if (isset($data['email'])) { $fields[] = "email = :email"; $params['email'] = $data['email']; }
        if (isset($data['role'])) { $fields[] = "role = :role"; $params[':role'] = $data['role']; }
        if (isset($data['status'])) { $fields[] = "status = :status"; $params[':status'] = $data['status']; }
        if (isset($data['password']) && !empty($data['password'])) {
            $fields[] = "password = :password";
            $params[':password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        if (empty($fields)) return false;
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error in Users::updateUser: " . $e->getMessage());
            return false;
        }
        return false;
    }

    // deletes a user
    public function deleteUser(int $id): bool {
        try {
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error in Users::deleteUser: " . $e->getMessage());
            return false;
        }
        return false;
    }
}