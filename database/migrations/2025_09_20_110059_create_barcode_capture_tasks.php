<?php
/**
 * Migration: create_barcode_capture_tasks
 * Created: 2025-09-20
 * Purpose: Create table for barcode capture tasks
 */

class CreateBarcodeCaptureTasksMigration {
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS barcode_capture_tasks (
            task_id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            location_id INT NOT NULL,
            expected_quantity INT NOT NULL,
            scanned_quantity INT NOT NULL DEFAULT 0,
            status ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
            created_by INT NOT NULL,
            assigned_to INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            INDEX idx_status (status),
            FOREIGN KEY (product_id) REFERENCES products(product_id),
            FOREIGN KEY (location_id) REFERENCES locations(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS barcode_capture_tasks");
    }
}

// Return instance for migration runner
return new CreateBarcodeCaptureTasksMigration();
