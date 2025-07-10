<?php
/**
 * Migration: create_supplier_invoice_items_table
 * Created: 2025-07-10 14:07:00
 * Purpose: Create supplier invoice items table for tracking items in supplier invoices
 */

class CreateSupplierInvoiceItemsTableMigration {
    public function up(PDO $pdo) {
        echo "🏗️  Creating supplier_invoice_items table...\n";
        
        $pdo->exec("
            CREATE TABLE supplier_invoice_items (
                id INT PRIMARY KEY AUTO_INCREMENT,
                supplier_invoice_id INT NOT NULL,
                purchase_order_item_id INT NOT NULL,
                quantity_invoiced DECIMAL(10,3) NOT NULL,
                unit_price DECIMAL(10,2) NOT NULL,
                total_price DECIMAL(12,2) NOT NULL,
                tax_rate DECIMAL(5,2) DEFAULT 0.00,
                tax_amount DECIMAL(12,2) DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_supplier_invoice_id (supplier_invoice_id),
                INDEX idx_purchase_order_item_id (purchase_order_item_id),
                FOREIGN KEY (supplier_invoice_id) REFERENCES supplier_invoices(id) ON DELETE CASCADE,
                FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        echo "✅ Supplier invoice items table created successfully!\n";
    }

    public function down(PDO $pdo) {
        echo "🗑️  Dropping supplier_invoice_items table...\n";
        $pdo->exec("DROP TABLE IF EXISTS supplier_invoice_items");
        echo "✅ Supplier invoice items table dropped!\n";
    }
}

return new CreateSupplierInvoiceItemsTableMigration();