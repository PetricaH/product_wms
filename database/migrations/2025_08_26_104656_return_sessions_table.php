<?php
/**
 * Migration: return_sessions_table
 * Created: 2025-08-26 10:46:56
 */

class ReturnSessionsTableMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        // Add your migration logic here
        $sql = "
            CREATE TABLE example_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ";
        $pdo->exec($sql);
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        // Add your rollback logic here
        $pdo->exec("DROP TABLE IF EXISTS example_table");
    }
}

// Return instance for migration runner
return new ReturnSessionsTableMigration();
