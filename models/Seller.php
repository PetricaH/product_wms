<?php 

class Seller {
    private PDO $conn;
    private string $table = 'sellers';

    public function __construct(PDO $database) {
        $this->conn = $database;
    }

    /**
     * Get all active sellers
     * @return array
     */
    public function getAllSellers(): array {
        $query = "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY supplier_name ASC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting sellers: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get total count of sellers with filters
     * @param string $search Search term
     * @param string $statusFilter Status filter
     * @return int Total count
     */
    public function getTotalCount(string $search = '', string $statusFilter = ''): int {
        $query = "SELECT COUNT(*) as total FROM {$this->table} WHERE 1=1";
        $params = [];
        
        if (!empty($search)) {
            $query .= " AND (supplier_name LIKE :search OR cif LIKE :search OR supplier_code LIKE :search OR email LIKE :search OR contact_person LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        if (!empty($statusFilter)) {
            $query .= " AND status = :status";
            $params[':status'] = $statusFilter;
        }
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['total'] ?? 0);
        } catch (PDOException $e) {
            error_log("Error getting total count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get sellers with pagination and filters
     * @param int $pageSize Number of sellers per page
     * @param int $offset Starting offset
     * @param string $search Search term
     * @param string $statusFilter Status filter
     * @return array Array of sellers
     */
    public function getSellersPaginated(int $pageSize, int $offset, string $search = '', string $statusFilter = ''): array {
        $query = "SELECT * FROM {$this->table} WHERE 1=1";
        $params = [];
        
        if (!empty($search)) {
            $query .= " AND (supplier_name LIKE :search OR cif LIKE :search OR supplier_code LIKE :search OR email LIKE :search OR contact_person LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        if (!empty($statusFilter)) {
            $query .= " AND status = :status";
            $params[':status'] = $statusFilter;
        }
        
        $query .= " ORDER BY supplier_name ASC LIMIT :limit OFFSET :offset";
        
        try {
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting paginated sellers: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get seller by ID
     * @param int $sellerId
     * @return array|false
     */
    public function getSellerById(int $sellerId): array|false {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $sellerId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting seller by ID: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create new seller
     * @param array $sellerData
     * @return int|false Seller ID on success, false on failure
     */
    public function createSeller(array $sellerData): int|false {
        $query = "INSERT INTO {$this->table} (
            supplier_name, cif, registration_number, supplier_code, 
            address, city, county, bank_name, iban, country, 
            email, contact_person, phone, notes, status
        ) VALUES (
            :supplier_name, :cif, :registration_number, :supplier_code,
            :address, :city, :county, :bank_name, :iban, :country,
            :email, :contact_person, :phone, :notes, :status
        )";
        
        try {
            $stmt = $this->conn->prepare($query);
            
            $stmt->bindValue(':supplier_name', $sellerData['supplier_name']);
            $stmt->bindValue(':cif', $sellerData['cif'] ?? null);
            $stmt->bindValue(':registration_number', $sellerData['registration_number'] ?? null);
            $stmt->bindValue(':supplier_code', $sellerData['supplier_code'] ?? null);
            $stmt->bindValue(':address', $sellerData['address'] ?? null);
            $stmt->bindValue(':city', $sellerData['city'] ?? null);
            $stmt->bindValue(':county', $sellerData['county'] ?? null);
            $stmt->bindValue(':bank_name', $sellerData['bank_name'] ?? null);
            $stmt->bindValue(':iban', $sellerData['iban'] ?? null);
            $stmt->bindValue(':country', $sellerData['country'] ?? 'Romania');
            $stmt->bindValue(':email', $sellerData['email'] ?? null);
            $stmt->bindValue(':contact_person', $sellerData['contact_person'] ?? null);
            $stmt->bindValue(':phone', $sellerData['phone'] ?? null);
            $stmt->bindValue(':notes', $sellerData['notes'] ?? null);
            $stmt->bindValue(':status', $sellerData['status'] ?? 'active');
            
            if ($stmt->execute()) {
                $sellerId = (int)$this->conn->lastInsertId();
                
                // Log activity
                if (isset($_SESSION['user_id']) && function_exists('logActivity')) {
                    logActivity(
                        $_SESSION['user_id'],
                        'create',
                        'seller',
                        $sellerId,
                        'Seller created: ' . $sellerData['supplier_name']
                    );
                }
                
                return $sellerId;
            }
            return false;
        } catch (PDOException $e) {
            error_log("Error creating seller: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update seller
     * @param int $sellerId
     * @param array $sellerData
     * @return bool
     */
    public function updateSeller(int $sellerId, array $sellerData): bool {
        $fields = [];
        $params = [':id' => $sellerId];
        
        $allowedFields = [
            'supplier_name', 'cif', 'registration_number', 'supplier_code',
            'address', 'city', 'county', 'bank_name', 'iban', 'country',
            'email', 'contact_person', 'phone', 'notes', 'status'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($sellerData[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $sellerData[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $query = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute($params);
            
            if ($result && isset($_SESSION['user_id']) && function_exists('logActivity')) {
                logActivity(
                    $_SESSION['user_id'],
                    'update',
                    'seller',
                    $sellerId,
                    'Seller updated'
                );
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error updating seller: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete/deactivate seller
     * @param int $sellerId
     * @return bool
     */
    public function deleteSeller(int $sellerId): bool {
        // Check if seller has purchase orders
        $checkQuery = "SELECT COUNT(*) FROM purchase_orders WHERE seller_id = :id";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindValue(':id', $sellerId, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->fetchColumn() > 0) {
            // Deactivate instead of delete
            return $this->updateSeller($sellerId, ['status' => 'inactive']);
        } else {
            // Safe to delete
            $query = "DELETE FROM {$this->table} WHERE id = :id";
            
            try {
                $stmt = $this->conn->prepare($query);
                $stmt->bindValue(':id', $sellerId, PDO::PARAM_INT);
                $result = $stmt->execute();
                
                if ($result && isset($_SESSION['user_id']) && function_exists('logActivity')) {
                    logActivity(
                        $_SESSION['user_id'],
                        'delete',
                        'seller',
                        $sellerId,
                        'Seller deleted'
                    );
                }
                
                return $result;
            } catch (PDOException $e) {
                error_log("Error deleting seller: " . $e->getMessage());
                return false;
            }
        }
    }

    /**
     * Search sellers
     * @param string $search
     * @return array
     */
    public function searchSellers(string $search): array {
        $query = "SELECT * FROM {$this->table} 
                  WHERE status = 'active' 
                  AND (supplier_name LIKE :search 
                       OR cif LIKE :search 
                       OR supplier_code LIKE :search
                       OR email LIKE :search)
                  ORDER BY supplier_name ASC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':search', '%' . $search . '%');
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error searching sellers: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get active sellers (for dropdowns, etc.)
     * @return array
     */
    public function getActiveSellers(): array {
        return $this->getAllSellers();
    }

    /**
     * Get seller statistics
     * @param int $sellerId
     * @return array
     */
    public function getSellerStatistics(int $sellerId): array {
        $query = "SELECT 
                    COUNT(po.id) as total_orders,
                    COALESCE(SUM(po.total_amount), 0) as total_value,
                    COUNT(CASE WHEN po.status = 'completed' THEN 1 END) as completed_orders,
                    MAX(po.created_at) as last_order_date
                  FROM purchase_orders po 
                  WHERE po.seller_id = :seller_id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':seller_id', $sellerId, PDO::PARAM_INT);
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'total_orders' => (int)($stats['total_orders'] ?? 0),
                'total_value' => (float)($stats['total_value'] ?? 0),
                'completed_orders' => (int)($stats['completed_orders'] ?? 0),
                'last_order_date' => $stats['last_order_date']
            ];
        } catch (PDOException $e) {
            error_log("Error getting seller statistics: " . $e->getMessage());
            return [
                'total_orders' => 0,
                'total_value' => 0.0,
                'completed_orders' => 0,
                'last_order_date' => null
            ];
        }
    }
}