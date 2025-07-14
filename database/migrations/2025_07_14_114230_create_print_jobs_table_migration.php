<?php
/**
 * Migration: create_print_jobs_table
 * Created: 2025-07-14 12:00:02
 */

class CreatePrintJobsTableMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        $sql = "
            CREATE TABLE print_jobs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                order_id INT NULL COMMENT 'Related order if applicable',
                printer_id INT NOT NULL,
                print_server_id INT NOT NULL,
                job_type ENUM('invoice', 'awb', 'label', 'document') NOT NULL,
                file_url VARCHAR(500) NOT NULL COMMENT 'URL to the PDF file',
                status ENUM('pending', 'sent', 'success', 'failed', 'retry') DEFAULT 'pending',
                attempts INT DEFAULT 0 COMMENT 'Number of print attempts',
                max_attempts INT DEFAULT 3 COMMENT 'Maximum retry attempts',
                error_message TEXT NULL COMMENT 'Last error message if failed',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                sent_at TIMESTAMP NULL COMMENT 'When job was sent to printer',
                completed_at TIMESTAMP NULL COMMENT 'When job was completed',
                INDEX idx_print_jobs_status (status),
                INDEX idx_print_jobs_order (order_id),
                INDEX idx_print_jobs_created (created_at),
                INDEX idx_print_jobs_printer (printer_id),
                INDEX idx_print_jobs_server (print_server_id)
            ) ENGINE=InnoDB COMMENT='Print job queue and history'
        ";
        $pdo->exec($sql);
        
        // Add foreign key constraints
        $foreignKeysSql = "
            ALTER TABLE print_jobs 
            ADD CONSTRAINT fk_print_jobs_printer 
                FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE CASCADE,
            ADD CONSTRAINT fk_print_jobs_print_server 
                FOREIGN KEY (print_server_id) REFERENCES print_servers(id) ON DELETE CASCADE
        ";
        $pdo->exec($foreignKeysSql);
        
        // Add foreign key for orders if the table exists
        try {
            // Check if orders table exists
            $checkTable = $pdo->query("SHOW TABLES LIKE 'orders'");
            if ($checkTable->rowCount() > 0) {
                $orderFkSql = "
                    ALTER TABLE print_jobs 
                    ADD CONSTRAINT fk_print_jobs_order 
                        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
                ";
                $pdo->exec($orderFkSql);
            }
        } catch (Exception $e) {
            // Orders table doesn't exist or FK constraint failed - that's OK
            // The constraint can be added later when orders table is available
        }
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS print_jobs");
    }
}

// Return instance for migration runner
return new CreatePrintJobsTableMigration();