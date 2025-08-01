<?php
/**
 * Migration: create_purchase_orders_table
 * Created: 2025-07-10 14:02:00
 * Purpose: Create purchase orders table for stock ordering
 */

class CreatePurchaseOrdersTableMigration {
    public function up(PDO $pdo) {
        echo "🏗️  Creating purchase_orders table...\n";
        
        $pdo->exec("
            CREATE TABLE purchase_orders (
                id INT PRIMARY KEY AUTO_INCREMENT,
                order_number VARCHAR(50) UNIQUE NOT NULL,
                seller_id INT NOT NULL,
                total_amount DECIMAL(12,2) DEFAULT 0.00,
                currency VARCHAR(3) DEFAULT 'RON',
                custom_message TEXT,
                status ENUM('draft', 'sent', 'confirmed', 'partial_delivery', 'delivered', 'invoiced', 'completed', 'cancelled') DEFAULT 'draft',
                expected_delivery_date DATE NULL,
                actual_delivery_date DATE NULL,
                email_sent_at TIMESTAMP NULL,
                email_recipient VARCHAR(255),
                notes TEXT,
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_order_number (order_number),
                INDEX idx_seller_id (seller_id),
                INDEX idx_status (status),
                INDEX idx_created_by (created_by),
                INDEX idx_expected_delivery_date (expected_delivery_date),
                FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE RESTRICT,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        echo "✅ Purchase orders table created successfully!\n";
    }

    public function down(PDO $pdo) {
        echo "🗑️  Dropping purchase_orders table...\n";
        $pdo->exec("DROP TABLE IF EXISTS purchase_orders");
        echo "✅ Purchase orders table dropped!\n";
    }
}

return new CreatePurchaseOrdersTableMigration();