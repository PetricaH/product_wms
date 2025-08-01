<?php
/**
 * Migration: add_seller_for_product_column_to_products_table
 * Created: 2025-07-23 11:11:30
 */

class AddSellerForProductColumnToProductsTableMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        // Add your migration logic here
        $sql = "ALTER TABLE products 
                ADD COLUMN seller_id INT NULL,
                ADD INDEX idx_products_seller_id (seller_id),
                ADD CONSTRAINT fk_products_seller 
                    FOREIGN KEY (seller_id) REFERENCES sellers(id) 
                    ON DELETE SET NULL ON UPDATE CASCADE;
        ";
        $pdo->exec($sql);
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        $pdo->exec("ALTER TABLE products 
        REMOVE COLUMN seller_id INT NULL
        ");
    }
}

// Return instance for migration runner
return new AddSellerForProductColumnToProductsTableMigration();
