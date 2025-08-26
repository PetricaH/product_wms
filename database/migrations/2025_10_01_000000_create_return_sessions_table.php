<?php
/**
 * Migration: create_return_sessions_table
 * Purpose: Track barcode scan sessions and timing for returns
 */

class CreateReturnSessionsTableMigration {
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        $pdo->exec("CREATE TABLE return_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            return_id INT NOT NULL,
            barcode VARCHAR(100) NOT NULL,
            processing_time_ms INT NOT NULL DEFAULT 0,
            scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
            INDEX idx_return_id (return_id),
            INDEX idx_barcode (barcode)
        )");
    }

    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS return_sessions");
    }
}

return new CreateReturnSessionsTableMigration();
