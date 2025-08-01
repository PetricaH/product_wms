<?php
/**
 * Migration: add_picked_status_to_orders
 * Created: 2025-07-04 10:00:00
 * Purpose: Allow new 'Picked' status in orders table ENUM
 */

class AddPickedStatusToOrdersMigration {
    public function up(PDO $pdo) {
        $pdo->exec("ALTER TABLE orders MODIFY status ENUM('pending','processing','picked','completed','cancelled','shipped') DEFAULT 'pending'");
    }

    public function down(PDO $pdo) {
        $pdo->exec("ALTER TABLE orders MODIFY status ENUM('pending','processing','completed','cancelled','shipped') DEFAULT 'pending'");
    }
}

return new AddPickedStatusToOrdersMigration();
