<?php
/**
 * Migration: create_location_level_settings_table
 * Created: 2025-07-23 05:45:25
 */

class CreateLocationLevelSettingsTableMigration {
    
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
return new CreateLocationLevelSettingsTableMigration();
