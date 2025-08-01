<?php
/**
 * Migration: update_purchase_orders_status_workflow
 * Created: 2025-07-17
 * Purpose: Update purchase orders status enum to new workflow
 */

class UpdatePurchaseOrdersStatusWorkflowMigration {
    
    public function up(PDO $pdo) {
        echo "🔄 Updating purchase orders status workflow...\n";
        
        try {
            // Update status enum with new workflow
            echo "1. Updating status enum...\n";
            $pdo->exec("
                ALTER TABLE purchase_orders 
                MODIFY COLUMN status ENUM('draft', 'sent', 'confirmed', 'partial_delivery', 'delivered', 'cancelled', 'returned', 'completed') DEFAULT 'draft'
            ");
            
            echo "✅ Purchase orders status workflow updated successfully!\n";
            
        } catch (Exception $e) {
            echo "❌ Error updating purchase orders status: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    public function down(PDO $pdo) {
        echo "🔄 Reverting purchase orders status workflow...\n";
        
        try {
            // Revert to previous status enum
            echo "1. Reverting status enum...\n";
            $pdo->exec("
                ALTER TABLE purchase_orders 
                MODIFY COLUMN status ENUM('draft', 'sent', 'confirmed', 'partial_delivery', 'delivered', 'completed', 'cancelled') DEFAULT 'draft'
            ");
            
            echo "✅ Purchase orders status workflow reverted!\n";
            
        } catch (Exception $e) {
            echo "❌ Error reverting purchase orders status: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}

return new UpdatePurchaseOrdersStatusWorkflowMigration();