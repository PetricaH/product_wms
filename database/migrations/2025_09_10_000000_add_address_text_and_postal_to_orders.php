<?php
/**
 * Migration: add_address_text_and_postal_to_orders
 * Created: 2025-09-10
 * Purpose: Add postal code and address text columns to orders table
 */

class AddAddressTextAndPostalToOrdersMigration {
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        $sql = "
            ALTER TABLE orders ADD COLUMN (
                address_text TEXT NULL COMMENT 'Full address text',
                INDEX idx_recipient_postal (recipient_postal)
            )
        ";

        $pdo->exec($sql);
    }

    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        $sql = "
            ALTER TABLE orders   DROP COLUMN address_text,
                                 DROP INDEX idx_recipient_postal
        ";

        $pdo->exec($sql);
    }
}

// Return instance for migration runner
return new AddAddressTextAndPostalToOrdersMigration();
