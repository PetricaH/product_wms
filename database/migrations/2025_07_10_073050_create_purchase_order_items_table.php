<?php
/**
 * Migration: create_purchase_order_items_table
 * Created: 2025-07-10 14:03:00
 * Purpose: Create purchase order items table for individual items in purchase orders
 */

class CreatePurchaseOrderItemsTableMigration {
    public function up(PDO $pdo) {
        echo "🏗️  Creating purchase_order_items table...\n";
        
        $pdo->exec("
            CREATE TABLE purchase_order_items (
                id INT PRIMARY KEY AUTO_INCREMENT,
                purchase_order_id INT NOT NULL,
                purchasable_product_id INT NOT NULL,
                quantity DECIMAL(10,3) NOT NULL,
                unit_price DECIMAL(10,2) NOT NULL,
                total_price DECIMAL(12,2) NOT NULL,
                quantity_delivered DECIMAL(10,3) DEFAULT 0.000,
                quantity_invoiced DECIMAL(10,3) DEFAULT 0.000,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_purchase_order_id (purchase_order_id),
                INDEX idx_purchasable_product_id (purchasable_product_id),
                FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
                FOREIGN KEY (purchasable_product_id) REFERENCES purchasable_products(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        echo "✅ Purchase order items table created successfully!\n";
    }

    public function down(PDO $pdo) {
        echo "🗑️  Dropping purchase_order_items table...\n";
        $pdo->exec("DROP TABLE IF EXISTS purchase_order_items");
        echo "✅ Purchase order items table dropped!\n";
    }
}

return new CreatePurchaseOrderItemsTableMigration();