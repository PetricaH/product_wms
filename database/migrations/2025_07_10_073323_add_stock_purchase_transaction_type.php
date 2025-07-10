<?php
/**
 * Migration: add_stock_purchase_transaction_type
 * Created: 2025-07-10 14:08:00
 * Purpose: Add support for stock purchase transactions and link to purchase orders
 */

class AddStockPurchaseTransactionTypeMigration {
    public function up(PDO $pdo) {
        echo "🏗️  Adding stock purchase transaction type support...\n";
        
        // Add purchase_order_id field to transactions table
        $pdo->exec("
            ALTER TABLE transactions 
            ADD COLUMN purchase_order_id INT NULL AFTER reference_id,
            ADD FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL
        ");
        
        echo "✅ Stock purchase transaction type support added!\n";
    }

    public function down(PDO $pdo) {
        echo "🗑️  Removing stock purchase transaction type support...\n";
        
        // Remove the foreign key constraint and column
        $pdo->exec("ALTER TABLE transactions DROP FOREIGN KEY transactions_ibfk_purchase_order");
        $pdo->exec("ALTER TABLE transactions DROP COLUMN purchase_order_id");
        
        echo "✅ Stock purchase transaction type support removed!\n";
    }
}

return new AddStockPurchaseTransactionTypeMigration();