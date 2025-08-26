<?php
/**
 * Migration: create_returns_tables
 * Purpose: Add tables for processing returned items with verification
 */

class CreateReturnsTablesMigration {

    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        // Main returns table
        $pdo->exec("
            CREATE TABLE returns (
                id INT PRIMARY KEY AUTO_INCREMENT,
                order_id INT NOT NULL,
                processed_by INT NOT NULL,
                verified_by INT NULL,
                status ENUM('in_progress','pending','verified','completed','rejected') DEFAULT 'in_progress',
                notes TEXT,
                verified_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_order (order_id),
                INDEX idx_processed_by (processed_by),
                INDEX idx_verified_by (verified_by),
                INDEX idx_status (status)
            )
        ");

        // Items within a return
        $pdo->exec("
            CREATE TABLE return_items (
                id INT PRIMARY KEY AUTO_INCREMENT,
                return_id INT NOT NULL,
                order_item_id INT NULL,
                product_id INT NOT NULL,
                quantity_returned INT NOT NULL,
                item_condition ENUM('good','damaged','defective') NOT NULL DEFAULT 'good',
                is_extra TINYINT(1) DEFAULT 0,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
                FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE SET NULL,
                FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
                INDEX idx_return (return_id),
                INDEX idx_order_item (order_item_id),
                INDEX idx_product (product_id),
                INDEX idx_is_extra (is_extra),
                INDEX idx_condition (item_condition)
            )
        ");

        // Discrepancies found during return verification
        $pdo->exec("
            CREATE TABLE return_discrepancies (
                id INT PRIMARY KEY AUTO_INCREMENT,
                return_id INT NOT NULL,
                order_item_id INT NULL,
                product_id INT NOT NULL,
                discrepancy_type ENUM('missing','extra','damaged') NOT NULL,
                expected_quantity INT DEFAULT 0,
                actual_quantity INT DEFAULT 0,
                item_condition ENUM('good','damaged','defective') DEFAULT 'good',
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
                FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE SET NULL,
                FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
                INDEX idx_return (return_id),
                INDEX idx_order_item (order_item_id),
                INDEX idx_product (product_id),
                INDEX idx_discrepancy_type (discrepancy_type)
            )
        ");
    }

    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS return_discrepancies");
        $pdo->exec("DROP TABLE IF EXISTS return_items");
        $pdo->exec("DROP TABLE IF EXISTS returns");
    }
}

// Return instance for migration runner
return new CreateReturnsTablesMigration();
