<?php
/**
 * Migration: create_supplier_invoices_table
 * Created: 2025-07-10 14:06:00
 * Purpose: Create supplier invoices table for tracking invoices from suppliers
 */

class CreateSupplierInvoicesTableMigration {
    public function up(PDO $pdo) {
        echo "🏗️  Creating supplier_invoices table...\n";
        
        $pdo->exec("
            CREATE TABLE supplier_invoices (
                id INT PRIMARY KEY AUTO_INCREMENT,
                invoice_number VARCHAR(100) NOT NULL,
                purchase_order_id INT NOT NULL,
                seller_id INT NOT NULL,
                invoice_date DATE NOT NULL,
                due_date DATE,
                total_amount DECIMAL(12,2) NOT NULL,
                tax_amount DECIMAL(12,2) DEFAULT 0.00,
                currency VARCHAR(3) DEFAULT 'RON',
                payment_status ENUM('pending', 'partial', 'paid', 'overdue') DEFAULT 'pending',
                payment_date DATE NULL,
                notes TEXT,
                document_path VARCHAR(500),
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_invoice_number (invoice_number),
                INDEX idx_purchase_order_id (purchase_order_id),
                INDEX idx_seller_id (seller_id),
                INDEX idx_invoice_date (invoice_date),
                INDEX idx_payment_status (payment_status),
                FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE RESTRICT,
                FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE RESTRICT,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        echo "✅ Supplier invoices table created successfully!\n";
    }

    public function down(PDO $pdo) {
        echo "🗑️  Dropping supplier_invoices table...\n";
        $pdo->exec("DROP TABLE IF EXISTS supplier_invoices");
        echo "✅ Supplier invoices table dropped!\n";
    }
}

return new CreateSupplierInvoicesTableMigration();