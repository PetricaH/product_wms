<?php
/**
 * Migration: add_product_barcode_to_inventory
 * Created: 2025-09-12
 * Purpose: Add supplier unit barcode to inventory for dual barcode lookup
 */

class AddProductBarcodeToInventoryMigration {
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        $pdo->exec("ALTER TABLE inventory ADD COLUMN product_barcode VARCHAR(255) NULL AFTER batch_number");
        $pdo->exec("ALTER TABLE inventory ADD INDEX idx_product_barcode (product_barcode)");
    }

    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        $pdo->exec("ALTER TABLE inventory DROP INDEX idx_product_barcode");
        $pdo->exec("ALTER TABLE inventory DROP COLUMN product_barcode");
    }
}

// Return instance for migration runner
return new AddProductBarcodeToInventoryMigration();
