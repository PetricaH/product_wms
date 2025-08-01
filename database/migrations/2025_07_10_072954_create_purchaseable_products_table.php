<?php
/**
 * Migration: create_purchasable_products_table
 * Created: 2025-07-10 14:01:00
 * Purpose: Create purchasable products table for products we buy from suppliers
 */

class CreatePurchasableProductsTableMigration {
    public function up(PDO $pdo) {
        echo "🏗️  Creating purchasable_products table...\n";
        
        $pdo->exec("
            CREATE TABLE purchasable_products (
                id INT PRIMARY KEY AUTO_INCREMENT,
                supplier_product_name VARCHAR(255) NOT NULL,
                supplier_product_code VARCHAR(100),
                description TEXT,
                unit_measure VARCHAR(20) DEFAULT 'bucata',
                last_purchase_price DECIMAL(10,2),
                currency VARCHAR(3) DEFAULT 'RON',
                internal_product_id INT NULL,
                preferred_seller_id INT NULL,
                notes TEXT,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_supplier_product_name (supplier_product_name),
                INDEX idx_supplier_product_code (supplier_product_code),
                INDEX idx_internal_product_id (internal_product_id),
                INDEX idx_preferred_seller_id (preferred_seller_id),
                INDEX idx_status (status),
                FOREIGN KEY (internal_product_id) REFERENCES products(product_id) ON DELETE SET NULL,
                FOREIGN KEY (preferred_seller_id) REFERENCES sellers(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        echo "✅ Purchasable products table created successfully!\n";
    }

    public function down(PDO $pdo) {
        echo "🗑️  Dropping purchasable_products table...\n";
        $pdo->exec("DROP TABLE IF EXISTS purchasable_products");
        echo "✅ Purchasable products table dropped!\n";
    }
}

return new CreatePurchasableProductsTableMigration();