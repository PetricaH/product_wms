<?php
/**
 * Migration: add_updated_by_to_orders
 * Created: 2025-07-22 18:00:00
 * Purpose: Add updated_by column for tracking last modifying user
 */

class AddUpdatedByToOrdersMigration {
    public function up(PDO $pdo) {
        $pdo->exec(
            "ALTER TABLE orders ADD COLUMN updated_by INT NULL AFTER created_by, " .
            "ADD CONSTRAINT fk_orders_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL"
        );
    }

    public function down(PDO $pdo) {
        $pdo->exec(
            "ALTER TABLE orders DROP FOREIGN KEY fk_orders_updated_by, DROP COLUMN updated_by"
        );
    }
}

return new AddUpdatedByToOrdersMigration();
