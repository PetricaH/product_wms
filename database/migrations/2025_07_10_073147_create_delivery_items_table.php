<?php
/**
 * Migration: create_delivery_items_table
 * Created: 2025-07-10 14:05:00
 * Purpose: Create delivery items table for tracking items in each delivery
 */

class CreateDeliveryItemsTableMigration {
    public function up(PDO $pdo) {
        echo "🏗️  Creating delivery_items table...\n";
        
        $pdo->exec("
            CREATE TABLE delivery_items (
                id INT PRIMARY KEY AUTO_INCREMENT,
                delivery_id INT NOT NULL,
                purchase_order_item_id INT NOT NULL,
                quantity_delivered DECIMAL(10,3) NOT NULL,
                condition_notes TEXT,
                quality_check ENUM('pass', 'fail', 'pending') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_delivery_id (delivery_id),
                INDEX idx_purchase_order_item_id (purchase_order_item_id),
                INDEX idx_quality_check (quality_check),
                FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
                FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        echo "✅ Delivery items table created successfully!\n";
    }

    public function down(PDO $pdo) {
        echo "🗑️  Dropping delivery_items table...\n";
        $pdo->exec("DROP TABLE IF EXISTS delivery_items");
        echo "✅ Delivery items table dropped!\n";
    }
}

return new CreateDeliveryItemsTableMigration();