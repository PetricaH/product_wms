<?php
/**
 * Migration: remove_quantity_column_from_products_table
 * Created: 2025-09-24
 * Purpose: Remove the quantity column from products table to eliminate data inconsistency.
 *          Stock quantities should be calculated from the inventory table which reflects reality.
 */

class RemoveQuantityColumnFromProductsTableMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        echo "🗑️  Removing quantity column from products table...\n";
        
        // First, let's check if there are any products relying solely on the quantity column
        // and warn about potential data loss
        $checkQuery = "SELECT COUNT(*) as count FROM products p 
                       LEFT JOIN inventory i ON p.product_id = i.product_id 
                       WHERE p.quantity > 0 AND (i.product_id IS NULL OR SUM(i.quantity) = 0 OR SUM(i.quantity) IS NULL)
                       GROUP BY p.product_id";
        
        try {
            $stmt = $pdo->query($checkQuery);
            $orphanedProducts = $stmt->rowCount();
            
            if ($orphanedProducts > 0) {
                echo "⚠️  Warning: Found $orphanedProducts products with quantity in products table but no inventory records.\n";
                echo "   These products will lose their stock count after migration.\n";
                echo "   Consider running inventory import before proceeding.\n";
            }
        } catch (Exception $e) {
            echo "ℹ️  Could not check for orphaned products: " . $e->getMessage() . "\n";
        }
        
        // Remove the quantity column
        $pdo->exec("ALTER TABLE products DROP COLUMN quantity");
        
        echo "✅ Successfully removed quantity column from products table!\n";
        echo "ℹ️  Stock quantities will now be calculated exclusively from the inventory table.\n";
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        echo "🔄 Rolling back: Adding quantity column back to products table...\n";
        
        // Add the quantity column back with default value 0
        $pdo->exec("ALTER TABLE products ADD COLUMN quantity INT DEFAULT 0 AFTER price");
        
        // Optionally, try to populate it with current inventory totals
        echo "🔄 Attempting to repopulate quantity column from inventory data...\n";
        
        try {
            $updateQuery = "UPDATE products p 
                           SET quantity = COALESCE((
                               SELECT SUM(i.quantity) 
                               FROM inventory i 
                               WHERE i.product_id = p.product_id
                           ), 0)";
            
            $pdo->exec($updateQuery);
            echo "✅ Successfully repopulated quantity column from inventory data!\n";
            
        } catch (Exception $e) {
            echo "⚠️  Could not repopulate quantity from inventory: " . $e->getMessage() . "\n";
            echo "   Quantity column added but left as 0 for all products.\n";
        }
        
        echo "✅ Rollback completed - quantity column restored!\n";
    }
}

// Return instance for migration runner
return new RemoveQuantityColumnFromProductsTableMigration();