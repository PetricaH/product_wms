<?php
/**
 * Transaction Model
 * Handles all transaction-related database operations
 */

class Transaction {
    private PDO $conn;
    private string $table = "transactions";
    private ?string $lastError = null;

    public function __construct(PDO $database) {
        $this->conn = $database;
    }

    public function getLastError(): ?string {
        return $this->lastError;
    }

    private function rememberError(?string $message = null): void {
        $this->lastError = $message;
        if ($message) {
            error_log('[Transaction] ' . $message);
        }
    }
    
    /**
     * Create a new transaction
     * @param array $transactionData Transaction data
     * @return bool Success status
     */
    public function createTransaction(array $transactionData): bool {
        $this->rememberError(null);
        $query = "INSERT INTO {$this->table} (
            transaction_type, amount, tax_amount, currency, description,
            reference_type, reference_id, purchase_order_id, customer_name,
            supplier_name, status, created_by, created_at
        ) VALUES (
            :transaction_type, :amount, :tax_amount, :currency, :description, 
            :reference_type, :reference_id, :purchase_order_id, :customer_name, 
            :supplier_name, :status, :created_by, NOW()
        )";
        
        try {
            $stmt = $this->conn->prepare($query);
            
            $stmt->bindValue(':transaction_type', $transactionData['transaction_type']);
            $stmt->bindValue(':amount', $transactionData['amount']);
            $stmt->bindValue(':tax_amount', $transactionData['tax_amount'] ?? 0);
            $stmt->bindValue(':currency', $transactionData['currency'] ?? 'RON');
            $stmt->bindValue(':description', $transactionData['description'] ?? '');
            $stmt->bindValue(':reference_type', $transactionData['reference_type'] ?? 'manual');
            $stmt->bindValue(':reference_id', $transactionData['reference_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(':purchase_order_id', $transactionData['purchase_order_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(':customer_name', $transactionData['customer_name'] ?? '');
            $stmt->bindValue(':supplier_name', $transactionData['supplier_name'] ?? '');
            $stmt->bindValue(':status', $transactionData['status'] ?? 'pending');
            $stmt->bindValue(':created_by', $transactionData['created_by'], PDO::PARAM_INT);
            
            $result = $stmt->execute();

            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                $this->rememberError('Failed to create transaction: ' . ($errorInfo[2] ?? 'Unknown database error'));
                return false;
            }

            if (function_exists('logActivity')) {
                logActivity(
                    $transactionData['created_by'],
                    'create',
                    'transaction',
                    $this->conn->lastInsertId(),
                    'Transaction created: ' . ($transactionData['description'] ?? 'No description')
                );
            }
            
            return true;
        } catch (PDOException $e) {
            $this->rememberError('Error creating transaction: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update transaction status
     * @param int $transactionId Transaction ID
     * @param string $status New status
     * @return bool Success status
     */
    public function updateTransactionStatus(int $transactionId, string $status): bool {
        $query = "UPDATE {$this->table} SET status = :status, updated_at = NOW() WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $transactionId, PDO::PARAM_INT);
            $stmt->bindValue(':status', $status);
            
            $result = $stmt->execute();
            
            if ($result && isset($_SESSION['user_id']) && function_exists('logActivity')) {
                logActivity(
                    $_SESSION['user_id'],
                    'update',
                    'transaction',
                    $transactionId,
                    "Transaction status updated to: {$status}"
                );
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error updating transaction status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete a transaction
     * @param int $transactionId Transaction ID
     * @return bool Success status
     */
    public function deleteTransaction(int $transactionId): bool {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $transactionId, PDO::PARAM_INT);
            
            $result = $stmt->execute();
            
            if ($result && isset($_SESSION['user_id']) && function_exists('logActivity')) {
                logActivity(
                    $_SESSION['user_id'],
                    'delete',
                    'transaction',
                    $transactionId,
                    'Transaction deleted'
                );
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error deleting transaction: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get transaction by ID
     * @param int $transactionId Transaction ID
     * @return array|false Transaction data or false if not found
     */
    public function getTransactionById(int $transactionId): array|false {
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
     * @return array Array of transactions
     */
    public function getAllTransactions(): array {
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
     * Get paginated transactions with filtering
     * @param int $currentPage Current page number
     * @param int $pageSize Number of transactions per page
     * @param string $searchQuery Search term
     * @param string $typeFilter Transaction type filter
     * @param string $statusFilter Status filter
     * @return array Array of transactions
     */
    public function getPaginatedTransactions(int $currentPage, int $pageSize, string $searchQuery = '', string $typeFilter = '', string $statusFilter = ''): array {
        $offset = ($currentPage - 1) * $pageSize;
        
        $query = "SELECT * FROM {$this->table} WHERE 1=1";
        $params = [];
        
        if (!empty($searchQuery)) {
            $query .= " AND (description LIKE :search OR customer_name LIKE :search OR supplier_name LIKE :search)";
            $params[':search'] = '%' . $searchQuery . '%';
        }
        
        if (!empty($typeFilter)) {
            $query .= " AND transaction_type = :type";
            $params[':type'] = $typeFilter;
        }
        
        if (!empty($statusFilter)) {
            $query .= " AND status = :status";
            $params[':status'] = $statusFilter;
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
     * Get total count of transactions with filters
     * @param string $searchQuery Search term
     * @param string $typeFilter Transaction type filter
     * @param string $statusFilter Status filter
     * @return int Total count
     */
    public function getTotalTransactions(string $searchQuery = '', string $typeFilter = '', string $statusFilter = ''): int {
        $query = "SELECT COUNT(*) as total FROM {$this->table} WHERE 1=1";
        $params = [];
        
        if (!empty($searchQuery)) {
            $query .= " AND (description LIKE :search OR customer_name LIKE :search OR supplier_name LIKE :search)";
            $params[':search'] = '%' . $searchQuery . '%';
        }
        
        if (!empty($typeFilter)) {
            $query .= " AND transaction_type = :type";
            $params[':type'] = $typeFilter;
        }
        
        if (!empty($statusFilter)) {
            $query .= " AND status = :status";
            $params[':status'] = $statusFilter;
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
     * Get transactions by type
     * @param string $type Transaction type
     * @return array Array of transactions
     */
    public function getTransactionsByType(string $type): array {
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
     * @return array Array of transactions
     */
    public function getTransactionsByStatus(string $status): array {
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
     * Get unique transaction types
     * @return array Array of transaction types
     */
    public function getTypes(): array {
        $query = "SELECT DISTINCT transaction_type FROM {$this->table} WHERE transaction_type IS NOT NULL AND transaction_type != '' ORDER BY transaction_type";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Add common types if not present
            $commonTypes = ['sales', 'purchase', 'adjustment', 'transfer', 'return', 'stock_purchase'];
            return array_unique(array_merge($result, $commonTypes));
        } catch (PDOException $e) {
            error_log("Error getting transaction types: " . $e->getMessage());
            return ['sales', 'purchase', 'adjustment', 'transfer', 'return', 'stock_purchase'];
        }
    }
    
    /**
     * Get unique transaction statuses
     * @return array Array of statuses
     */
    public function getStatuses(): array {
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
}