<?php
/**
 * Migration: add_cancellation_reason_to_orders
 * Created: 2025-11-02 09:00:00
 * Purpose: Add cancellation_reason column to orders for capturing cancel context
 */

class AddCancellationReasonToOrdersMigration {
    public function up(PDO $pdo) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN cancellation_reason TEXT NULL");
    }

    public function down(PDO $pdo) {
        $pdo->exec("ALTER TABLE orders DROP COLUMN cancellation_reason");
    }
}

return new AddCancellationReasonToOrdersMigration();
