<?php

// File: models/Transaction.php - Updated for transactions page functionality
class Transaction {
    private $conn;
    private $table = "transactions";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Get total count of transactions with filters
     * @param string $typeFilter Filter by transaction type
     * @param string $statusFilter Filter by status
     * @param string $search Search term
     * @return int Total count
     */
    public function getTotalCount($typeFilter = '', $statusFilter = '', $search = '') {
        $query = "SELECT COUNT(*) as total FROM {$this->table} WHERE 1=1";
        $params = [];
        
        if (!empty($typeFilter)) {
            $query .= " AND transaction_type = :type";
            $params[':type'] = $typeFilter;
        }
        
        if (!empty($statusFilter)) {
            $query .= " AND status = :status";
            $params[':status'] = $statusFilter;
        }
        
        if (!empty($search)) {
            $query .= " AND (description LIKE :search OR customer_name LIKE :search OR supplier_name LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        try {
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['total'] ?? 0);
        } catch (PDOException $e) {
            error_log("Error getting transactions total count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get transactions with pagination and filtering
     * @param int $pageSize Number of transactions per page
     * @param int $offset Starting offset
     * @param string $typeFilter Filter by transaction type
     * @param string $statusFilter Filter by status
     * @param string $search Search term
     * @return array
     */
    public function getTransactionsPaginated($pageSize, $offset, $typeFilter = '', $statusFilter = '', $search = '') {
        $query = "SELECT * FROM {$this->table} WHERE 1=1";
        $params = [];
        
        if (!empty($typeFilter)) {
            $query .= " AND transaction_type = :type";
            $params[':type'] = $typeFilter;
        }
        
        if (!empty($statusFilter)) {
            $query .= " AND status = :status";
            $params[':status'] = $statusFilter;
        }
        
        if (!empty($search)) {
            $query .= " AND (description LIKE :search OR customer_name LIKE :search OR supplier_name LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        $query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        
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
            error_log("Error getting paginated transactions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get unique transaction types
     * @return array
     */
    public function getTypes() {
        $query = "SELECT DISTINCT transaction_type FROM {$this->table} WHERE transaction_type IS NOT NULL AND transaction_type != '' ORDER BY transaction_type";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Add common types if not present
            $commonTypes = ['sales', 'purchase', 'adjustment', 'transfer', 'return'];
            return array_unique(array_merge($result, $commonTypes));
        } catch (PDOException $e) {
            error_log("Error getting transaction types: " . $e->getMessage());
            return ['sales', 'purchase', 'adjustment', 'transfer', 'return'];
        }
    }
    
    /**
     * Get unique statuses
     * @return array
     */
    public function getStatuses() {
        $query = "SELECT DISTINCT status FROM {$this->table} WHERE status IS NOT NULL AND status != '' ORDER BY status";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Add common statuses if not present
            $commonStatuses = ['pending', 'processing', 'completed', 'failed', 'cancelled'];
            return array_unique(array_merge($result, $commonStatuses));
        } catch (PDOException $e) {
            error_log("Error getting transaction statuses: " . $e->getMessage());
            return ['pending', 'processing', 'completed', 'failed', 'cancelled'];
        }
    }
    
    /**
     * Create a new transaction
     * @param array $transactionData Transaction data
     * @return int|false Transaction ID on success, false on failure
     */
    public function createTransaction(array $transactionData) {
        $query = "INSERT INTO {$this->table} 
                  (transaction_type, amount, tax_amount, currency, description, reference_type, reference_id, 
                   customer_name, supplier_name, status, created_by, created_at) 
                  VALUES (:transaction_type, :amount, :tax_amount, :currency, :description, :reference_type, :reference_id,
                          :customer_name, :supplier_name, :status, :created_by, NOW())";
        
        try {
            $stmt = $this->conn->prepare($query);
            $params = [
                ':transaction_type' => $transactionData['transaction_type'],
                ':amount' => $transactionData['amount'],
                ':tax_amount' => $transactionData['tax_amount'] ?? 0,
                ':currency' => $transactionData['currency'] ?? 'RON',
                ':description' => $transactionData['description'] ?? '',
                ':reference_type' => $transactionData['reference_type'] ?? 'manual',
                ':reference_id' => $transactionData['reference_id'] ?? null,
                ':customer_name' => $transactionData['customer_name'] ?? '',
                ':supplier_name' => $transactionData['supplier_name'] ?? '',
                ':status' => $transactionData['status'] ?? 'pending',
                ':created_by' => $transactionData['created_by'] ?? null
            ];
            
            $success = $stmt->execute($params);
            return $success ? $this->conn->lastInsertId() : false;
        } catch (PDOException $e) {
            error_log("Error creating transaction: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update transaction status
     * @param int $transactionId Transaction ID
     * @param string $status New status
     * @return bool
     */
    public function updateStatus($transactionId, $status) {
        $query = "UPDATE {$this->table} SET status = :status, updated_at = NOW() WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $transactionId, PDO::PARAM_INT);
            $stmt->bindValue(':status', $status);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error updating transaction status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete a transaction
     * @param int $transactionId Transaction ID
     * @return bool
     */
    public function deleteTransaction($transactionId) {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $transactionId, PDO::PARAM_INT);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error deleting transaction: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get transaction by ID
     * @param int $transactionId Transaction ID
     * @return array|false
     */
    public function getTransactionById($transactionId) {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $transactionId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting transaction by ID: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all transactions
     * @return array
     */
    public function getAllTransactions() {
        $query = "SELECT * FROM {$this->table} ORDER BY created_at DESC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting all transactions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get transactions by type
     * @param string $type Transaction type
     * @return array
     */
    public function getTransactionsByType($type) {
        $query = "SELECT * FROM {$this->table} WHERE transaction_type = :type ORDER BY created_at DESC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':type', $type);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting transactions by type: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get transactions by status
     * @param string $status Transaction status
     * @return array
     */
    public function getTransactionsByStatus($status) {
        $query = "SELECT * FROM {$this->table} WHERE status = :status ORDER BY created_at DESC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':status', $status);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting transactions by status: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update transaction
     * @param int $transactionId Transaction ID
     * @param array $transactionData Transaction data
     * @return bool
     */
    public function updateTransaction($transactionId, array $transactionData) {
        $query = "UPDATE {$this->table} 
                  SET transaction_type = :transaction_type, 
                      amount = :amount, 
                      tax_amount = :tax_amount, 
                      currency = :currency, 
                      description = :description, 
                      customer_name = :customer_name, 
                      supplier_name = :supplier_name, 
                      status = :status, 
                      updated_at = NOW()
                  WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $params = [
                ':id' => $transactionId,
                ':transaction_type' => $transactionData['transaction_type'],
                ':amount' => $transactionData['amount'],
                ':tax_amount' => $transactionData['tax_amount'] ?? 0,
                ':currency' => $transactionData['currency'] ?? 'RON',
                ':description' => $transactionData['description'] ?? '',
                ':customer_name' => $transactionData['customer_name'] ?? '',
                ':supplier_name' => $transactionData['supplier_name'] ?? '',
                ':status' => $transactionData['status'] ?? 'pending'
            ];
            
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error updating transaction: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get transaction statistics
     * @return array
     */
    public function getTransactionStats() {
        $query = "SELECT 
                    transaction_type,
                    status,
                    COUNT(*) as count,
                    SUM(amount) as total_amount,
                    AVG(amount) as avg_amount
                  FROM {$this->table} 
                  GROUP BY transaction_type, status
                  ORDER BY transaction_type, status";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting transaction stats: " . $e->getMessage());
            return [];
        }
    }
}