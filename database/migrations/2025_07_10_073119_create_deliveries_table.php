<?php
/**
 * Migration: create_deliveries_table
 * Created: 2025-07-10 14:04:00
 * Purpose: Create deliveries table for tracking physical deliveries
 */

class CreateDeliveriesTableMigration {
    public function up(PDO $pdo) {
        echo "🏗️  Creating deliveries table...\n";
        
        $pdo->exec("
            CREATE TABLE deliveries (
                id INT PRIMARY KEY AUTO_INCREMENT,
                delivery_number VARCHAR(50) UNIQUE NOT NULL,
                purchase_order_id INT NOT NULL,
                delivery_date DATE NOT NULL,
                delivery_note_number VARCHAR(100),
                carrier VARCHAR(255),
                tracking_number VARCHAR(100),
                received_by VARCHAR(255),
                notes TEXT,
                status ENUM('pending', 'partial', 'complete') DEFAULT 'pending',
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_delivery_number (delivery_number),
                INDEX idx_purchase_order_id (purchase_order_id),
                INDEX idx_delivery_date (delivery_date),
                INDEX idx_status (status),
                FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE RESTRICT,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        echo "✅ Deliveries table created successfully!\n";
    }

    public function down(PDO $pdo) {
        echo "🗑️  Dropping deliveries table...\n";
        $pdo->exec("DROP TABLE IF EXISTS deliveries");
        echo "✅ Deliveries table dropped!\n";
    }
}

return new CreateDeliveriesTableMigration();