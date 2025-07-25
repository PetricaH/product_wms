<?php
/**
 * Migration: create_supplier_notifications_table
 * Creates table for logging supplier notifications
 */
class CreateSupplierNotificationsTableMigration {
    public function up(PDO $pdo) {
        echo "\u{1F4E7} Creating supplier_notifications table...\n";
        $pdo->exec("\n            CREATE TABLE supplier_notifications (\n                id INT PRIMARY KEY AUTO_INCREMENT,\n                receiving_item_id INT NOT NULL,\n                purchase_order_id INT NOT NULL,\n                seller_id INT NOT NULL,\n                sent_by INT NOT NULL,\n                email_subject VARCHAR(255) NOT NULL,\n                email_body TEXT NOT NULL,\n                selected_info JSON NULL,\n                attached_images JSON NULL,\n                delivery_status ENUM('pending','sent','failed') DEFAULT 'pending',\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n                INDEX idx_receiving_item (receiving_item_id),\n                INDEX idx_purchase_order (purchase_order_id),\n                INDEX idx_seller (seller_id),\n                INDEX idx_sent_by (sent_by),\n                INDEX idx_delivery_status (delivery_status),\n                FOREIGN KEY (receiving_item_id) REFERENCES receiving_items(id) ON DELETE CASCADE,\n                FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,\n                FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE,\n                FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");
        echo "\u{2705} supplier_notifications table created!\n";
    }

    public function down(PDO $pdo) {
        echo "\u{1F5D1}\u{FE0F} Dropping supplier_notifications table...\n";
        $pdo->exec("DROP TABLE IF EXISTS supplier_notifications");
        echo "\u{2705} supplier_notifications table dropped!\n";
    }
}

return new CreateSupplierNotificationsTableMigration();
