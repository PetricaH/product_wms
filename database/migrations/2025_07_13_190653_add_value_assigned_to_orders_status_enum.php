<?php
/**
 * Migration: add_value_assigned_to_orders_status_enum
 * Created: 2025-07-13 19:06:53
 */

class AddValueAssignedToOrdersStatusEnumMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        // Define the SQL to modify the enum
        $sql = "
            ALTER TABLE `orders` MODIFY COLUMN `status` ENUM('pending', 'processing', 'picked', 'assigned', 'completed', 'cancelled', 'shipped') DEFAULT 'pending'
        ";
        $pdo->exec($sql);
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        // Define the SQL to revert the enum change
        $sql = "
            ALTER TABLE `orders` MODIFY COLUMN `status` ENUM('pending', 'processing', 'picked', 'completed', 'cancelled', 'shipped') DEFAULT 'pending'
        ";
        $pdo->exec($sql);
    }
}

// Return instance for migration runner
return new AddValueAssignedToOrdersStatusEnumMigration();