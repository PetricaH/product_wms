<?php
/**
 * Migration: create_relocation_tasks_table
 * Created: 2025-07-27
 * Creates table for temporary stock relocation tasks
 */

class CreateRelocationTasksTable {
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        echo "\xF0\x9F\x9A\x9A Creating relocation_tasks table...\n";
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS relocation_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                from_location_id INT NOT NULL,
                to_location_id INT NOT NULL,
                quantity INT NOT NULL,
                status ENUM('pending','ready','completed') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_to_location (to_location_id),
                INDEX idx_status (status)
            )
        ");
    }

    /**
     * Reverse the migration
     */
    public function down(PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS relocation_tasks");
    }
}
return new CreateRelocationTasksTable();