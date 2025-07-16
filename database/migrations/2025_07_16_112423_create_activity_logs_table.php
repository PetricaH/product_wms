<?php

/**
 * Migration: Create the activity_logs table
 *
 * This migration creates the activity_logs table to store user activity
 * and system events.
 */
class CreateActivityLogsTable
{
    /**
     * Run the migration.
     *
     * @param PDO $pdo The database connection
     * @return void
     */
    public function up(PDO $pdo)
    {
        // SQL statement to create the activity_logs table
        $sql = "
        CREATE TABLE `activity_logs` (
          `id` int NOT NULL AUTO_INCREMENT,
          `user_id` int DEFAULT NULL,
          `action` varchar(100) NOT NULL,
          `resource_type` varchar(50) NOT NULL,
          `resource_id` int DEFAULT NULL,
          `description` text,
          `old_values` json DEFAULT NULL,
          `new_values` json DEFAULT NULL,
          `ip_address` varchar(45) DEFAULT NULL,
          `user_agent` text,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_user` (`user_id`),
          KEY `idx_action` (`action`),
          KEY `idx_resource` (`resource_type`, `resource_id`),
          KEY `idx_created_at` (`created_at`),
          CONSTRAINT `fk_activity_logs_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
        ";

        // Execute the SQL statement
        try {
            $pdo->exec($sql);
            echo "✅ Table 'activity_logs' created successfully.\n";
        } catch (PDOException $e) {
            // It's helpful to output the error for debugging
            echo "❌ Error creating 'activity_logs' table: " . $e->getMessage() . "\n";
            // Re-throw the exception to halt the migration process if something goes wrong
            throw $e;
        }
    }

    /**
     * Reverse the migration.
     *
     * @param PDO $pdo The database connection
     * @return void
     */
    public function down(PDO $pdo)
    {
        // SQL statement to drop the activity_logs table
        $sql = "DROP TABLE IF EXISTS `activity_logs`;";

        // Execute the SQL statement
        try {
            $pdo->exec($sql);
            echo "✅ Table 'activity_logs' dropped successfully.\n";
        } catch (PDOException $e) {
            echo "❌ Error dropping 'activity_logs' table: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}
return new CreateActivityLogsTable();