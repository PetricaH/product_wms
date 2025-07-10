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
}