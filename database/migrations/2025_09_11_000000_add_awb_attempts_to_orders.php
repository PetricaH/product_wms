<?php
/**
 * Migration: add_awb_attempts_to_orders
 * Created: 2025-09-11
 * Purpose: Track AWB generation attempts
 */

class AddAwbAttemptsToOrdersMigration {
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN awb_generation_attempts INT NOT NULL DEFAULT 0 AFTER awb_barcode, ADD COLUMN awb_generation_last_attempt_at DATETIME NULL AFTER awb_generation_attempts");
    }

    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        $pdo->exec("ALTER TABLE orders DROP COLUMN awb_generation_last_attempt_at, DROP COLUMN awb_generation_attempts");
    }
}

// Return instance for migration runner
return new AddAwbAttemptsToOrdersMigration();
