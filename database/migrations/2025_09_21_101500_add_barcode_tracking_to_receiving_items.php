<?php
/**
 * Migration: add_barcode_tracking_to_receiving_items
 * Adds tracking method and barcode task references to receiving items
 */

class AddBarcodeTrackingToReceivingItemsMigration {
    public function up(PDO $pdo) {
        $pdo->exec("ALTER TABLE receiving_items ADD COLUMN tracking_method ENUM('bulk','individual') NOT NULL DEFAULT 'bulk' AFTER approval_status");
        $pdo->exec("ALTER TABLE receiving_items ADD COLUMN barcode_task_id INT NULL AFTER tracking_method");
        $pdo->exec("ALTER TABLE receiving_items ADD CONSTRAINT fk_receiving_items_barcode_task FOREIGN KEY (barcode_task_id) REFERENCES barcode_capture_tasks(task_id) ON DELETE SET NULL");
    }

    public function down(PDO $pdo) {
        $pdo->exec("ALTER TABLE receiving_items DROP FOREIGN KEY fk_receiving_items_barcode_task");
        $pdo->exec("ALTER TABLE receiving_items DROP COLUMN barcode_task_id");
        $pdo->exec("ALTER TABLE receiving_items DROP COLUMN tracking_method");
    }
}

return new AddBarcodeTrackingToReceivingItemsMigration();
