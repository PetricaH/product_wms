<?php
/**
 * Migration: enhance_printers_table
 * Created: 2025-07-14 12:00:01
 */

class EnhancePrintersTableMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        // Add new columns to existing printers table
        $sql = "
            ALTER TABLE printers 
            ADD COLUMN print_server_id INT NULL AFTER network_identifier,
            ADD COLUMN printer_type ENUM('invoice', 'label', 'receipt', 'document') DEFAULT 'invoice' COMMENT 'Purpose of the printer' AFTER print_server_id,
            ADD COLUMN is_active BOOLEAN DEFAULT TRUE COMMENT 'Whether printer is available' AFTER printer_type,
            ADD COLUMN last_used TIMESTAMP NULL COMMENT 'Last time printer was used' AFTER is_active,
            ADD COLUMN paper_size VARCHAR(20) DEFAULT 'A4' COMMENT 'Default paper size' AFTER last_used,
            ADD COLUMN notes TEXT NULL COMMENT 'Additional notes about the printer' AFTER paper_size
        ";
        $pdo->exec($sql);
        
        // Add foreign key constraint (after print_servers table exists)
        $foreignKeySql = "
            ALTER TABLE printers 
            ADD CONSTRAINT fk_printers_print_server 
            FOREIGN KEY (print_server_id) REFERENCES print_servers(id) ON DELETE SET NULL
        ";
        $pdo->exec($foreignKeySql);
        
        // Add indexes
        $indexSql = "
            ALTER TABLE printers 
            ADD INDEX idx_printers_type (printer_type),
            ADD INDEX idx_printers_server (print_server_id),
            ADD INDEX idx_printers_active (is_active)
        ";
        $pdo->exec($indexSql);
        
        // Update existing printers with default values
        $updateSql = "
            UPDATE printers SET 
                printer_type = 'invoice',
                is_active = TRUE,
                paper_size = 'A4',
                print_server_id = 1
            WHERE printer_type IS NULL
        ";
        $pdo->exec($updateSql);
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        // Drop foreign key first
        $pdo->exec("ALTER TABLE printers DROP FOREIGN KEY fk_printers_print_server");
        
        // Drop indexes
        $pdo->exec("ALTER TABLE printers DROP INDEX idx_printers_type");
        $pdo->exec("ALTER TABLE printers DROP INDEX idx_printers_server");
        $pdo->exec("ALTER TABLE printers DROP INDEX idx_printers_active");
        
        // Drop columns
        $pdo->exec("
            ALTER TABLE printers 
            DROP COLUMN print_server_id,
            DROP COLUMN printer_type,
            DROP COLUMN is_active,
            DROP COLUMN last_used,
            DROP COLUMN paper_size,
            DROP COLUMN notes
        ");
    }
}

// Return instance for migration runner
return new EnhancePrintersTableMigration();