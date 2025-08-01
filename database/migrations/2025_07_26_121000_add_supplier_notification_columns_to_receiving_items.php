<?php
/**
 * Migration: add_supplier_notification_columns_to_receiving_items
 * Adds notification tracking columns to receiving_items
 */
class AddSupplierNotificationColumnsToReceivingItemsMigration {
    public function up(PDO $pdo) {
        echo "\u{1F527} Adding supplier notification columns to receiving_items...\n";
        $pdo->exec("ALTER TABLE receiving_items
            ADD COLUMN supplier_notified BOOLEAN DEFAULT FALSE,
            ADD COLUMN supplier_notification_count INT DEFAULT 0,
            ADD COLUMN last_notification_at TIMESTAMP NULL,
            ADD INDEX idx_supplier_notified (supplier_notified),
            ADD INDEX idx_last_notification_at (last_notification_at)");
        echo "\u{2705} Columns added!\n";
    }

    public function down(PDO $pdo) {
        echo "\u{1F5D1}\u{FE0F} Removing supplier notification columns from receiving_items...\n";
        $pdo->exec("ALTER TABLE receiving_items
            DROP COLUMN IF EXISTS supplier_notified,
            DROP COLUMN IF EXISTS supplier_notification_count,
            DROP COLUMN IF EXISTS last_notification_at");
        echo "\u{2705} Columns removed!\n";
    }
}

return new AddSupplierNotificationColumnsToReceivingItemsMigration();
