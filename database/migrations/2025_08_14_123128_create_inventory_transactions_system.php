<?php
/**
 * Migration: create_inventory_transactions_system
 * Created: 2025-01-15 12:00:00
 * Purpose: Create comprehensive inventory transaction tracking system
 */

class CreateInventoryTransactionsSystemMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo): void {
        echo "📦 Creating inventory transactions system...\n";
        
        // 1. CREATE MAIN TRANSACTIONS TABLE
        echo "   Creating inventory_transactions table...\n";
        $pdo->exec("
            CREATE TABLE inventory_transactions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                
                -- CORE TRANSACTION DATA
                transaction_type ENUM(
                    'receive',      -- Items received from suppliers
                    'move',         -- Movement between locations  
                    'pick',         -- Items picked for orders
                    'adjust',       -- Manual adjustments/cycle count
                    'qc_hold',      -- Quality control hold
                    'qc_release',   -- Released from QC
                    'expire',       -- Expired items removal
                    'damage',       -- Damaged items removal
                    'return',       -- Customer/supplier returns
                    'transfer_out', -- Outbound transfers
                    'transfer_in',  -- Inbound transfers
                    'correction'    -- Error corrections
                ) NOT NULL,
                
                -- QUANTITY TRACKING (positive = increase, negative = decrease)
                quantity_change INT NOT NULL,
                quantity_before INT NOT NULL DEFAULT 0,
                quantity_after INT NOT NULL DEFAULT 0,
                
                -- PRODUCT & LOCATION INFO
                product_id INT NOT NULL,
                location_id INT DEFAULT NULL,           -- Current/destination location
                source_location_id INT DEFAULT NULL,    -- For moves/transfers
                
                -- INVENTORY DETAILS
                batch_number VARCHAR(50) DEFAULT NULL,
                lot_number VARCHAR(50) DEFAULT NULL,
                expiry_date DATE DEFAULT NULL,
                shelf_level ENUM('bottom', 'middle', 'top', 'custom') DEFAULT NULL,
                subdivision_number TINYINT DEFAULT NULL,
                
                -- USER & TIMING
                user_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                -- CONTEXT & REFERENCES
                reference_type ENUM(
                    'manual',
                    'receiving_session', 
                    'order', 
                    'cycle_count',
                    'qc_decision',
                    'system_auto'
                ) DEFAULT 'manual',
                reference_id INT DEFAULT NULL,
                
                -- METADATA
                reason VARCHAR(255) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                operator_notes TEXT DEFAULT NULL,
                
                -- AUDIT & TRACKING
                session_id VARCHAR(100) DEFAULT NULL,   -- For grouping related transactions
                parent_transaction_id BIGINT DEFAULT NULL, -- For linked transactions
                is_correction BOOLEAN DEFAULT FALSE,
                corrected_transaction_id BIGINT DEFAULT NULL,
                
                -- COST TRACKING (optional for future use)
                unit_cost DECIMAL(10,4) DEFAULT NULL,
                total_cost DECIMAL(12,4) DEFAULT NULL,
                
                -- PERFORMANCE METRICS
                duration_seconds INT DEFAULT NULL,      -- Time taken for operation
                system_latency_ms INT DEFAULT NULL,     -- System processing time
                
                -- INDEXES FOR PERFORMANCE
                INDEX idx_product_location (product_id, location_id),
                INDEX idx_transaction_type (transaction_type),
                INDEX idx_created_at (created_at),
                INDEX idx_user_id (user_id),
                INDEX idx_location_id (location_id),
                INDEX idx_source_location (source_location_id),
                INDEX idx_reference (reference_type, reference_id),
                INDEX idx_session_id (session_id),
                INDEX idx_batch_expiry (batch_number, expiry_date),
                INDEX idx_quantity_change (quantity_change),
                INDEX idx_parent_transaction (parent_transaction_id),
                
                -- COMPOSITE INDEXES FOR COMMON QUERIES
                INDEX idx_product_date (product_id, created_at),
                INDEX idx_location_date (location_id, created_at),
                INDEX idx_user_type_date (user_id, transaction_type, created_at),
                INDEX idx_active_inventory (product_id, location_id, created_at),
                
                -- FOREIGN KEYS
                FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
                FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
                FOREIGN KEY (source_location_id) REFERENCES locations(id) ON DELETE SET NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (parent_transaction_id) REFERENCES inventory_transactions(id) ON DELETE SET NULL,
                FOREIGN KEY (corrected_transaction_id) REFERENCES inventory_transactions(id) ON DELETE SET NULL
            ) 
            ENGINE=InnoDB 
            DEFAULT CHARSET=utf8mb4 
            COLLATE=utf8mb4_unicode_ci
            COMMENT='Comprehensive inventory movement tracking for WMS'
        ");
        
        // 2. CREATE TRANSACTION SUMMARY TABLE (for performance)
        echo "   Creating inventory_transaction_summary table...\n";
        $pdo->exec("
            CREATE TABLE inventory_transaction_summary (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                location_id INT NOT NULL,
                summary_date DATE NOT NULL,
                
                -- DAILY AGGREGATES
                total_received INT DEFAULT 0,
                total_picked INT DEFAULT 0,
                total_moved_in INT DEFAULT 0,
                total_moved_out INT DEFAULT 0,
                total_adjusted INT DEFAULT 0,
                net_change INT DEFAULT 0,
                
                -- COUNTS
                transaction_count INT DEFAULT 0,
                unique_users_count INT DEFAULT 0,
                
                -- PERFORMANCE METRICS
                avg_processing_time_seconds DECIMAL(8,2) DEFAULT NULL,
                peak_hour TINYINT DEFAULT NULL,
                
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                UNIQUE KEY unique_summary (product_id, location_id, summary_date),
                INDEX idx_summary_date (summary_date),
                INDEX idx_product_summary (product_id, summary_date),
                INDEX idx_location_summary (location_id, summary_date),
                
                FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
                FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
            )
            ENGINE=InnoDB 
            DEFAULT CHARSET=utf8mb4 
            COLLATE=utf8mb4_unicode_ci
            COMMENT='Daily summary of inventory transactions for reporting'
        ");
        
        // 3. CREATE INDEXES FOR EXISTING INVENTORY TABLE (for better performance)
        echo "   Adding performance indexes to existing inventory table...\n";
        try {
            $pdo->exec("ALTER TABLE inventory ADD INDEX idx_updated_at (updated_at)");
            $pdo->exec("ALTER TABLE inventory ADD INDEX idx_received_at (received_at)");
            $pdo->exec("ALTER TABLE inventory ADD INDEX idx_product_location_updated (product_id, location_id, updated_at)");
        } catch (Exception $e) {
            // Indexes might already exist, that's ok
            echo "   Note: Some indexes already exist, skipping...\n";
        }
        
        // 4. CREATE STORED PROCEDURE FOR DAILY SUMMARY UPDATE
        echo "   Creating stored procedure for summary updates...\n";
        $pdo->exec("
            CREATE PROCEDURE update_transaction_summary_daily()
            BEGIN
                INSERT INTO inventory_transaction_summary (
                    product_id, location_id, summary_date,
                    total_received, total_picked, total_moved_in, total_moved_out, 
                    total_adjusted, net_change, transaction_count, unique_users_count,
                    avg_processing_time_seconds, peak_hour
                )
                SELECT 
                    product_id,
                    location_id,
                    DATE(created_at) as summary_date,
                    SUM(CASE WHEN transaction_type = 'receive' THEN quantity_change ELSE 0 END) as total_received,
                    SUM(CASE WHEN transaction_type = 'pick' THEN ABS(quantity_change) ELSE 0 END) as total_picked,
                    SUM(CASE WHEN transaction_type = 'move' AND quantity_change > 0 THEN quantity_change ELSE 0 END) as total_moved_in,
                    SUM(CASE WHEN transaction_type = 'move' AND quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END) as total_moved_out,
                    SUM(CASE WHEN transaction_type = 'adjust' THEN quantity_change ELSE 0 END) as total_adjusted,
                    SUM(quantity_change) as net_change,
                    COUNT(*) as transaction_count,
                    COUNT(DISTINCT user_id) as unique_users_count,
                    AVG(duration_seconds) as avg_processing_time_seconds,
                    (SELECT HOUR(created_at) FROM inventory_transactions t2 
                     WHERE t2.product_id = t1.product_id AND t2.location_id = t1.location_id 
                     AND DATE(t2.created_at) = DATE(t1.created_at)
                     GROUP BY HOUR(created_at) ORDER BY COUNT(*) DESC LIMIT 1) as peak_hour
                FROM inventory_transactions t1
                WHERE DATE(created_at) >= CURDATE() - INTERVAL 7 DAY
                AND location_id IS NOT NULL
                GROUP BY product_id, location_id, DATE(created_at)
                ON DUPLICATE KEY UPDATE
                    total_received = VALUES(total_received),
                    total_picked = VALUES(total_picked),
                    total_moved_in = VALUES(total_moved_in),
                    total_moved_out = VALUES(total_moved_out),
                    total_adjusted = VALUES(total_adjusted),
                    net_change = VALUES(net_change),
                    transaction_count = VALUES(transaction_count),
                    unique_users_count = VALUES(unique_users_count),
                    avg_processing_time_seconds = VALUES(avg_processing_time_seconds),
                    peak_hour = VALUES(peak_hour),
                    updated_at = CURRENT_TIMESTAMP;
            END
        ");
        
        echo "✅ Inventory transactions system created successfully!\n";
        echo "   Tables created: inventory_transactions, inventory_transaction_summary\n";
        echo "   Indexes added to existing inventory table\n";
        echo "   Stored procedure created: update_transaction_summary_daily\n\n";
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo): void {
        echo "🗑️ Rolling back inventory transactions system...\n";
        
        // Drop stored procedure
        echo "   Dropping stored procedure...\n";
        $pdo->exec("DROP PROCEDURE IF EXISTS update_transaction_summary_daily");
        
        // Drop tables (order matters due to foreign keys)
        echo "   Dropping inventory_transaction_summary table...\n";
        $pdo->exec("DROP TABLE IF EXISTS inventory_transaction_summary");
        
        echo "   Dropping inventory_transactions table...\n";
        $pdo->exec("DROP TABLE IF EXISTS inventory_transactions");
        
        // Remove indexes from inventory table
        echo "   Removing indexes from inventory table...\n";
        try {
            $pdo->exec("ALTER TABLE inventory DROP INDEX idx_updated_at");
            $pdo->exec("ALTER TABLE inventory DROP INDEX idx_received_at");
            $pdo->exec("ALTER TABLE inventory DROP INDEX idx_product_location_updated");
        } catch (Exception $e) {
            // Indexes might not exist, that's ok
            echo "   Note: Some indexes didn't exist, skipping...\n";
        }
        
        echo "✅ Inventory transactions system removed!\n\n";
    }
}

// Return instance for migration runner
return new CreateInventoryTransactionsSystemMigration();