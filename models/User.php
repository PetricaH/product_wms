<?php

// File: models/User.php - Fixed User Model with corrected createUser method
class Users {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Find user by username or email (for login)
     * @param string $identifier Username or email
     * @return array|null User data or null if not found
     */
    public function findByUsernameOrEmail(string $identifier): ?array {
        try {
            $stmt = $this->db->prepare("
                SELECT id, username, email, password, role, status, created_at,
                       smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure
                FROM users
                WHERE (username = :identifier OR email = :identifier)
                AND status = 1
                LIMIT 1
            ");
            $stmt->bindParam(':identifier', $identifier, PDO::PARAM_STR);
            $stmt->execute();
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user ?: null;
        } catch (PDOException $e) {
            error_log("Error in Users::findByUsernameOrEmail: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update user's last login timestamp
     * @param int $userId User ID
     * @return bool Success status
     */
    public function updateLastLogin(int $userId): bool {
        try {
            $stmt = $this->db->prepare("UPDATE users SET updated_at = NOW() WHERE id = :user_id");
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error in Users::updateLastLogin: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Count all registered active users
     * @return int Number of active users
     */
    public function countAllUsers(): int {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM users WHERE status = 1");
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error in Users::countAllUsers: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Find a user by their ID
     * @param int $id User ID
     * @return array|false User data or false if not found
     */
    public function findById(int $id): array|false {
        try {
            $stmt = $this->db->prepare("SELECT id, username, email, role, status, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure FROM users WHERE id = :id LIMIT 1");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user ?: false;
        } catch (PDOException $e) {
            error_log("Error in Users::findById: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new user - REVISED for optional SMTP
     * @param array $data User data array containing username, email, password, role, status
     * @return int|false Returns the new user ID on success, false on failure
     */
    public function createUser(array $data): int|false {
        // Hash the password before storing
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        
        // Build a parameter array. This method is more robust for optional values.
        $params = [
            ':username' => $data['username'],
            ':email' => $data['email'],
            ':password' => $hashedPassword,
            ':role' => $data['role'],
            ':status' => $data['status'] ?? 1,
            ':smtp_host' => $data['smtp_host'] ?? null,
            ':smtp_port' => $data['smtp_port'] ?? null,
            ':smtp_user' => $data['smtp_user'] ?? null,
            ':smtp_pass' => $data['smtp_pass'] ?? null,
            ':smtp_secure' => $data['smtp_secure'] ?? null,
        ];
        
        try {
            $sql = "INSERT INTO users (username, email, password, role, status, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure)
                VALUES (:username, :email, :password, :role, :status, :smtp_host, :smtp_port, :smtp_user, :smtp_pass, :smtp_secure)";
            
            $stmt = $this->db->prepare($sql);
            
            // Execute with the params array, which correctly handles nulls
            $stmt->execute($params);
            
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error in Users::createUser: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update an existing user - FIXED VERSION
     * @param int $id User ID
     * @param array $data User data to update
     * @return bool Success status
     */
    public function updateUser(int $id, array $data): bool {
        $fields = [];
        $params = [':id' => $id];
        
        if (isset($data['username'])) { 
            $fields[] = "username = :username"; 
            $params[':username'] = $data['username']; 
        }
        if (isset($data['email'])) { 
            $fields[] = "email = :email"; 
            $params[':email'] = $data['email']; 
        }
        if (isset($data['role'])) { 
            $fields[] = "role = :role"; 
            $params[':role'] = $data['role']; 
        }
        if (isset($data['status'])) {
            $fields[] = "status = :status";
            $params[':status'] = $data['status'];
        }
        if (array_key_exists('smtp_host', $data)) {
            $fields[] = "smtp_host = :smtp_host";
            $params[':smtp_host'] = $data['smtp_host'];
        }
        if (array_key_exists('smtp_port', $data)) {
            $fields[] = "smtp_port = :smtp_port";
            $params[':smtp_port'] = $data['smtp_port'];
        }
        if (array_key_exists('smtp_user', $data)) {
            $fields[] = "smtp_user = :smtp_user";
            $params[':smtp_user'] = $data['smtp_user'];
        }
        if (array_key_exists('smtp_pass', $data)) {
            $fields[] = "smtp_pass = :smtp_pass";
            $params[':smtp_pass'] = $data['smtp_pass'];
        }
        if (array_key_exists('smtp_secure', $data)) {
            $fields[] = "smtp_secure = :smtp_secure";
            $params[':smtp_secure'] = $data['smtp_secure'];
        }
        if (isset($data['password']) && !empty($data['password'])) {
            $fields[] = "password = :password";
            $params[':password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        if (empty($fields)) return false;
        
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        
        try {
            $stmt = $this->db->prepare($sql);
            // Use execute with params array (works better for dynamic queries)
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error in Users::updateUser: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a user
     * @param int $id User ID
     * @return bool Success status
     */
    public function deleteUser(int $id): bool {
        try {
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error in Users::deleteUser: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all users with optional filtering and pagination
     * @param int|null $limit Optional limit for pagination
     * @param int $offset Optional offset for pagination  
     * @param string $search Optional search term for username or email
     * @return array Array of user records
     */
    public function getAllUsers(?int $limit = null, int $offset = 0, string $search = ''): array {
        try {
            $sql = "SELECT id, username, email, role, status, created_at FROM users";
            $params = [];
            
            // Add search functionality if search term provided
            if (!empty($search)) {
                $sql .= " WHERE (username LIKE :search OR email LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }
            
            // Order by creation date, newest first
            $sql .= " ORDER BY created_at DESC";
            
            // Add pagination if limit provided
            if ($limit !== null) {
                $sql .= " LIMIT :limit";
                if ($offset > 0) {
                    $sql .= " OFFSET :offset";
                }
                $params[':limit'] = $limit;
                if ($offset > 0) {
                    $params[':offset'] = $offset;
                }
            }
            
            $stmt = $this->db->prepare($sql);
            
            // Bind parameters with proper types
            foreach ($params as $key => $value) {
                if ($key === ':limit' || $key === ':offset') {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value, PDO::PARAM_STR);
                }
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error in Users::getAllUsers: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if username exists (for validation)
     * @param string $username Username to check
     * @param int $excludeId Exclude this user ID (for updates)
     * @return bool True if username exists
     */
    public function usernameExists(string $username, int $excludeId = 0): bool {
        try {
            $sql = "SELECT COUNT(*) FROM users WHERE username = :username";
            $params = [':username' => $username];
            
            if ($excludeId > 0) {
                $sql .= " AND id != :exclude_id";
                $params[':exclude_id'] = $excludeId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Error in Users::usernameExists: " . $e->getMessage());
            return true; // Err on the side of caution
        }
    }

    /**
     * Check if email exists (for validation)
     * @param string $email Email to check
     * @param int $excludeId Exclude this user ID (for updates)
     * @return bool True if email exists
     */
    public function emailExists(string $email, int $excludeId = 0): bool {
        try {
            $sql = "SELECT COUNT(*) FROM users WHERE email = :email";
            $params = [':email' => $email];
            
            if ($excludeId > 0) {
                $sql .= " AND id != :exclude_id";
                $params[':exclude_id'] = $excludeId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Error in Users::emailExists: " . $e->getMessage());
            return true; // Err on the side of caution
        }
    }

    /**
     * Get users by role
     * @param string $role Role to filter by
     * @return array Array of users with specified role
     */
    public function getUsersByRole(string $role): array {
        try {
            $stmt = $this->db->prepare("SELECT id, username, email, role, status, created_at FROM users WHERE role = :role ORDER BY username ASC");
            $stmt->bindParam(':role', $role, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error in Users::getUsersByRole: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Update user status (activate/deactivate)
     * @param int $id User ID
     * @param int $status Status (1 for active, 0 for inactive)
     * @return bool Success status
     */
    public function updateUserStatus(int $id, int $status): bool {
        try {
            $stmt = $this->db->prepare("UPDATE users SET status = :status WHERE id = :id");
            $stmt->bindParam(':status', $status, PDO::PARAM_INT);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error in Users::updateUserStatus: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Persist a remember-me token for extended sessions.
     */
    public function createRememberToken(int $userId, string $selector, string $validatorHash, string $expiresAt): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_remember_tokens (user_id, selector, validator_hash, expires_at, created_at, updated_at)
                VALUES (:user_id, :selector, :validator_hash, :expires_at, NOW(), NOW())
            ");

            return $stmt->execute([
                ':user_id' => $userId,
                ':selector' => $selector,
                ':validator_hash' => $validatorHash,
                ':expires_at' => $expiresAt,
            ]);
        } catch (PDOException $e) {
            error_log("Error in Users::createRememberToken: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve a stored remember-me token by selector.
     */
    public function findRememberToken(string $selector): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, user_id, selector, validator_hash, expires_at
                FROM user_remember_tokens
                WHERE selector = :selector
                LIMIT 1
            ");
            $stmt->bindParam(':selector', $selector, PDO::PARAM_STR);
            $stmt->execute();

            $token = $stmt->fetch(PDO::FETCH_ASSOC);
            return $token ?: null;
        } catch (PDOException $e) {
            error_log("Error in Users::findRememberToken: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Remove all remember-me tokens for a user.
     */
    public function deleteRememberTokens(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM user_remember_tokens WHERE user_id = :user_id");
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error in Users::deleteRememberTokens: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a specific remember-me token by its ID.
     */
    public function deleteRememberTokenById(int $tokenId): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM user_remember_tokens WHERE id = :id");
            $stmt->bindParam(':id', $tokenId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error in Users::deleteRememberTokenById: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove expired remember-me tokens.
     */
    public function purgeExpiredRememberTokens(): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM user_remember_tokens WHERE expires_at <= NOW()");
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error in Users::purgeExpiredRememberTokens: " . $e->getMessage());
            return false;
        }
    }
}
