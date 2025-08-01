<?php
/**
 * Migration: update_purchase_orders_invoice_functionality
 * Created: 2025-07-17
 * Purpose: Remove invoiced from status enum and add separate invoice tracking
 */

class UpdatePurchaseOrdersInvoiceFunctionalityMigration {
    
    public function up(PDO $pdo) {
        echo "🧾 Updating purchase orders invoice functionality...\n";
        
        try {
            // Step 1: Add invoice tracking columns first
            echo "1. Adding invoice tracking columns...\n";
            $pdo->exec("
                ALTER TABLE purchase_orders 
                ADD COLUMN invoiced BOOLEAN DEFAULT FALSE AFTER actual_delivery_date,
                ADD COLUMN invoice_file_path VARCHAR(500) NULL AFTER invoiced,
                ADD COLUMN invoiced_at TIMESTAMP NULL AFTER invoice_file_path
            ");
            
            // Step 2: Update any existing 'invoiced' status records BEFORE changing enum
            echo "2. Updating existing invoiced records...\n";
            $stmt = $pdo->prepare("
                UPDATE purchase_orders 
                SET invoiced = TRUE, 
                    invoiced_at = updated_at,
                    status = 'delivered'
                WHERE status = 'invoiced'
            ");
            $stmt->execute();
            $updatedCount = $stmt->rowCount();
            
            if ($updatedCount > 0) {
                echo "   Updated $updatedCount existing invoiced records\n";
            }
            
            // Step 3: Now safely remove 'invoiced' from status enum
            echo "3. Removing 'invoiced' from status enum...\n";
            $pdo->exec("
                ALTER TABLE purchase_orders 
                MODIFY COLUMN status ENUM('draft', 'sent', 'confirmed', 'partial_delivery', 'delivered', 'completed', 'cancelled') DEFAULT 'draft'
            ");
            
            // Step 4: Create indexes for invoice queries
            echo "4. Creating indexes...\n";
            $pdo->exec("
                ALTER TABLE purchase_orders 
                ADD INDEX idx_invoiced (invoiced),
                ADD INDEX idx_invoiced_at (invoiced_at)
            ");
            
            echo "✅ Purchase orders invoice functionality updated successfully!\n";
            
        } catch (Exception $e) {
            echo "❌ Error updating purchase orders: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    public function down(PDO $pdo) {
        echo "🔄 Reverting purchase orders invoice functionality...\n";
        
        try {
            // Step 1: Update invoiced records back to status
            echo "1. Reverting invoiced records to status...\n";
            $pdo->exec("
                UPDATE purchase_orders 
                SET status = 'invoiced' 
                WHERE invoiced = TRUE
            ");
            
            // Step 2: Drop invoice columns
            echo "2. Dropping invoice columns...\n";
            $pdo->exec("
                ALTER TABLE purchase_orders 
                DROP INDEX idx_invoiced,
                DROP INDEX idx_invoiced_at,
                DROP COLUMN invoiced,
                DROP COLUMN invoice_file_path,
                DROP COLUMN invoiced_at
            ");
            
            // Step 3: Restore original status enum
            echo "3. Restoring original status enum...\n";
            $pdo->exec("
                ALTER TABLE purchase_orders 
                MODIFY COLUMN status ENUM('draft', 'sent', 'confirmed', 'partial_delivery', 'delivered', 'invoiced', 'completed', 'cancelled') DEFAULT 'draft'
            ");
            
            echo "✅ Purchase orders invoice functionality reverted!\n";
            
        } catch (Exception $e) {
            echo "❌ Error reverting purchase orders: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}

return new UpdatePurchaseOrdersInvoiceFunctionalityMigration();