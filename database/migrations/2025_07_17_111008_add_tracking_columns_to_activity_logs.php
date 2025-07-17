<?php
/**
 * Migration: add_tracking_columns_to_activity_logs
 * Created: 2025-07-17 11:10:08
 */

class AddTrackingColumnsToActivityLogsMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        // Add your migration logic here
        $sql = "
            ALTER TABLE `activity_logs`
            ADD COLUMN `entity_type` VARCHAR(100) NOT NULL AFTER `action`,
            ADD COLUMN `entity_id` INT UNSIGNED NOT NULL AFTER `entity_type`,
            ADD COLUMN `resource_type` VARCHAR(50) NULL AFTER `entity_id`
        ";
        $pdo->exec($sql);
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        // Add your rollback logic here
        $pdo->exec(" 
            ALTER TABLE `activity_logs`
            DROP COLUMN `resource_type`,
            DROP COLUMN `entity_id`,
            DROP COLUMN `entity_type`");
    }
}

// Return instance for migration runner
return new AddTrackingColumnsToActivityLogsMigration();
