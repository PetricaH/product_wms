<?php
/**
 * Migration: create_manual_stock_entries_tables
 * Adds tables for tracking manual stock entries and their photos.
 */

class CreateManualStockEntriesTablesMigration {
    public function up(PDO $pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_manual_entries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            inventory_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            location_id INT UNSIGNED NOT NULL,
            quantity INT NOT NULL,
            received_at DATETIME NULL,
            notes TEXT NULL,
            user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_manual_entries_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE,
            CONSTRAINT fk_manual_entries_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
            CONSTRAINT fk_manual_entries_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
            CONSTRAINT fk_manual_entries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_manual_entry_photos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entry_id INT UNSIGNED NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_manual_entry_photos_entry FOREIGN KEY (entry_id) REFERENCES inventory_manual_entries(id) ON DELETE CASCADE,
            INDEX idx_manual_entry_photos_entry (entry_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    public function down(PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS inventory_manual_entry_photos");
        $pdo->exec("DROP TABLE IF EXISTS inventory_manual_entries");
    }
}

return new CreateManualStockEntriesTablesMigration();
