<?php
/**
 * Migration: create_receiving_tables
 * Created: 2025-07-14
 * Purpose: Create comprehensive receiving system tables
 */

class CreateReceivingTablesMigration {
    public function up(PDO $pdo) {
        echo "🚛 Creating receiving system tables...\n";

        // 1. Receiving sessions table
        $pdo->exec("
            CREATE TABLE receiving_sessions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                session_number VARCHAR(50) UNIQUE NOT NULL,
                supplier_document_number VARCHAR(100) NOT NULL,
                supplier_document_type ENUM('invoice', 'delivery_note', 'packing_slip') NOT NULL,
                supplier_document_date DATE,
                purchase_order_id INT,
                supplier_id INT,
                received_by INT NOT NULL,
                status ENUM('in_progress', 'completed', 'cancelled') DEFAULT 'in_progress',
                total_items_expected INT DEFAULT 0,
                total_items_received INT DEFAULT 0,
                discrepancy_notes TEXT,
                completed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_session_number (session_number),
                INDEX idx_supplier_document (supplier_document_number),
                INDEX idx_purchase_order (purchase_order_id),
                INDEX idx_supplier (supplier_id),
                INDEX idx_received_by (received_by),
                INDEX idx_status (status),
                FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL,
                FOREIGN KEY (supplier_id) REFERENCES sellers(id) ON DELETE SET NULL,
                FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2. Receiving items table
        $pdo->exec("
            CREATE TABLE receiving_items (
                id INT PRIMARY KEY AUTO_INCREMENT,
                receiving_session_id INT NOT NULL,
                product_id INT NOT NULL,
                purchase_order_item_id INT,
                expected_quantity DECIMAL(10,3) DEFAULT 0,
                received_quantity DECIMAL(10,3) NOT NULL,
                unit_price DECIMAL(10,2) DEFAULT 0.00,
                condition_status ENUM('good', 'damaged', 'expired') DEFAULT 'good',
                batch_number VARCHAR(100),
                expiry_date DATE,
                location_id INT,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_receiving_session (receiving_session_id),
                INDEX idx_product (product_id),
                INDEX idx_purchase_order_item (purchase_order_item_id),
                INDEX idx_location (location_id),
                FOREIGN KEY (receiving_session_id) REFERENCES receiving_sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
                FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id) ON DELETE SET NULL,
                FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 3. Receiving discrepancies table
        $pdo->exec("
            CREATE TABLE receiving_discrepancies (
                id INT PRIMARY KEY AUTO_INCREMENT,
                receiving_session_id INT NOT NULL,
                product_id INT NOT NULL,
                discrepancy_type ENUM('quantity_short', 'quantity_over', 'quality_issue', 'missing_item', 'unexpected_item') NOT NULL,
                expected_quantity DECIMAL(10,3),
                actual_quantity DECIMAL(10,3),
                description TEXT NOT NULL,
                resolution_status ENUM('pending', 'resolved', 'escalated') DEFAULT 'pending',
                resolution_notes TEXT,
                resolved_by INT,
                resolved_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_receiving_session (receiving_session_id),
                INDEX idx_product (product_id),
                INDEX idx_discrepancy_type (discrepancy_type),
                INDEX idx_resolution_status (resolution_status),
                FOREIGN KEY (receiving_session_id) REFERENCES receiving_sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
                FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        echo "✅ Receiving system tables created successfully!\n";
    }

    public function down(PDO $pdo) {
        echo "🗑️ Dropping receiving system tables...\n";
        $pdo->exec("DROP TABLE IF EXISTS receiving_discrepancies");
        $pdo->exec("DROP TABLE IF EXISTS receiving_items");
        $pdo->exec("DROP TABLE IF EXISTS receiving_sessions");
        echo "✅ Receiving system tables dropped!\n";
    }
}

return new CreateReceivingTablesMigration();