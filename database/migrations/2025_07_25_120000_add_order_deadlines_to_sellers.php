<?php
/**
 * Migration: add_order_deadlines_to_sellers
 * Adds weekly order deadline settings to sellers table
 */
class AddOrderDeadlinesToSellersMigration {
    public function up(PDO $pdo) {
        echo "\u{1F527} Adding order deadline columns to sellers...\n";
        $pdo->exec("ALTER TABLE sellers 
            ADD COLUMN order_deadline_day INT DEFAULT NULL COMMENT 'Day of week (1=Monday, 7=Sunday) until when orders can be sent',
            ADD COLUMN order_deadline_time TIME DEFAULT '23:59:00' COMMENT 'Time of day for the deadline',
            ADD COLUMN next_order_date DATE DEFAULT NULL COMMENT 'Next available date for sending orders'");
        echo "\u{2705} Columns added!\n";
    }

    public function down(PDO $pdo) {
        echo "\u{1F5D1}\u{FE0F} Removing order deadline columns from sellers...\n";
        $pdo->exec("ALTER TABLE sellers 
            DROP COLUMN IF EXISTS order_deadline_day,
            DROP COLUMN IF EXISTS order_deadline_time,
            DROP COLUMN IF EXISTS next_order_date");
        echo "\u{2705} Columns removed!\n";
    }
}

return new AddOrderDeadlinesToSellersMigration();
