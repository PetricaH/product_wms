<?php
/**
 * Migration: add_assigned_at_column_in_orders_table
 * Created: 2025-07-13 19:04:22
 */

class AddAssignedAtColumnInOrdersTableMigration {

    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        // Add your migration logic here
        $sql = "
            ALTER TABLE `orders` ADD `assigned_at` TIMESTAMP NULL DEFAULT NULL AFTER `assigned_to`
        ";
        $pdo->exec($sql);
    }

    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        // Add your rollback logic here
        $pdo->exec("ALTER TABLE `orders` DROP COLUMN `assigned_at`");
    }
}

// Return instance for migration runner
return new AddAssignedAtColumnInOrdersTableMigration();