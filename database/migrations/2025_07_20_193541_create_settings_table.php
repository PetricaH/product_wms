<?php

/**
 * Migration: Create the settings table
 *
 * This table will store global application settings in a key-value format.
 */
class CreateSettingsTable
{
    /**
     * Apply the migration.
     *
     * @param PDO $pdo The database connection.
     * @return void
     */
    public function up(PDO $pdo): void
    {
        // Creates the main settings table with all required columns.
        $stmt = "
            CREATE TABLE IF NOT EXISTS settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                setting_key VARCHAR(100) UNIQUE NOT NULL COMMENT 'The unique key for the setting, e.g., default_shelf_height',
                setting_value TEXT COMMENT 'The value of the setting',
                setting_type VARCHAR(50) DEFAULT 'string' NOT NULL COMMENT 'Data type hint for the setting (e.g., integer, string, boolean)',
                description VARCHAR(255) NULL COMMENT 'A user-friendly description of the setting',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_setting_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $pdo->exec($stmt);
    }

    /**
     * Revert the migration.
     *
     * @param PDO $pdo The database connection.
     * @return void
     */
    public function down(PDO $pdo): void
    {
        // Drops the settings table. Using IF EXISTS prevents errors on repeated rollbacks.
        $stmt = "DROP TABLE IF EXISTS settings;";
        $pdo->exec($stmt);
    }
}
return new CreateSettingsTable();
