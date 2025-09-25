<?php
/**
 * Migration: add_price_eur_to_products_table
 * Created: 2025-09-25
 * Purpose: Add a dedicated EUR price column for products while keeping the existing RON price.
 */

class AddPriceEurToProductsTableMigration {
    public function up(PDO $pdo) {
        echo "➕ Adding price_eur column to products table...\n";

        $pdo->exec("ALTER TABLE products ADD COLUMN price_eur DECIMAL(10,2) NULL AFTER price");

        echo "✅ price_eur column added successfully!\n";
    }

    public function down(PDO $pdo) {
        echo "➖ Removing price_eur column from products table...\n";

        $pdo->exec("ALTER TABLE products DROP COLUMN price_eur");

        echo "✅ price_eur column removed successfully.\n";
    }
}

return new AddPriceEurToProductsTableMigration();
