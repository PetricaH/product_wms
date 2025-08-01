<?php
/**
 * Migration: 2025_07_26_120000_create_supplier_notifications_table
 * Creates table for logging supplier notifications
 */
class CreateSupplierNotificationsTableMigration {
    /**
     * Run the migrations.
     *
     * @param PDO $pdo
     * @return void
     */
    public function up(PDO $pdo) {
        echo "📧 Creating supplier_notifications table...\n";
        
        $pdo->exec("
            CREATE TABLE supplier_notifications (
                id INT PRIMARY KEY AUTO_INCREMENT,
                receiving_item_id INT NOT NULL,
                purchase_order_id INT NOT NULL,
                seller_id INT NOT NULL,
                sent_by INT NULL, -- Changed from NOT NULL to NULL to allow for ON DELETE SET NULL
                email_subject VARCHAR(255) NOT NULL,
                email_body TEXT NOT NULL,
                selected_info JSON NULL,
                attached_images JSON NULL,
                delivery_status ENUM('pending','sent','failed') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_receiving_item (receiving_item_id),
                INDEX idx_purchase_order (purchase_order_id),
                INDEX idx_seller (seller_id),
                INDEX idx_sent_by (sent_by),
                INDEX idx_delivery_status (delivery_status),

                FOREIGN KEY (receiving_item_id) REFERENCES receiving_items(id) ON DELETE CASCADE,
                FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
                FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
                FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        echo "✅ supplier_notifications table created!\n";
    }

    /**
     * Reverse the migrations.
     *
     * @param PDO $pdo
     * @return void
     */
    public function down(PDO $pdo) {
        echo "🗑️ Dropping supplier_notifications table...\n";
        $pdo->exec("DROP TABLE IF EXISTS supplier_notifications;");
        echo "✅ supplier_notifications table dropped!\n";
    }
}

return new CreateSupplierNotificationsTableMigration();
