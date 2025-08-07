<?php
/**
 * Migration: Add subdivision_number and shelf_level columns to inventory table
 * Created: 2025-07-30
 * File: database/migrations/2025_07_30_000000_add_subdivision_shelf_columns_to_inventory.php
 */

class AddSubdivisionShelfColumnsToInventoryMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        echo "📋 Adding subdivision and shelf level columns to inventory table...\n";
        
        try {
            // Check if subdivision_number column exists
            $subdivisionCheck = $pdo->query("SHOW COLUMNS FROM inventory LIKE 'subdivision_number'");
            if ($subdivisionCheck->rowCount() == 0) {
                $pdo->exec("
                    ALTER TABLE inventory 
                    ADD COLUMN subdivision_number INT DEFAULT NULL 
                    AFTER location_id
                ");
                echo "    ✓ Added subdivision_number column\n";
            } else {
                echo "    ✓ Column subdivision_number already exists\n";
            }
            
            // Check if shelf_level column exists and ensure it can store custom names
            $shelfLevelCheck = $pdo->query("SHOW COLUMNS FROM inventory LIKE 'shelf_level'");
            if ($shelfLevelCheck->rowCount() == 0) {
                $pdo->exec("
                    ALTER TABLE inventory
                    ADD COLUMN shelf_level VARCHAR(50) DEFAULT NULL
                    AFTER subdivision_number
                ");
                echo "    ✓ Added shelf_level column\n";
            } else {
                $column = $shelfLevelCheck->fetch(PDO::FETCH_ASSOC);
                if (stripos($column['Type'], 'varchar') === false) {
                    $pdo->exec("ALTER TABLE inventory MODIFY COLUMN shelf_level VARCHAR(50) DEFAULT NULL");
                    echo "    ✓ Modified shelf_level column to VARCHAR(50)\n";
                } else {
                    echo "    ✓ Column shelf_level already exists\n";
                }
            }
            
            // Add indexes for better performance
            echo "  - Creating indexes...\n";
            
            // Check and create index for subdivision_number
            try {
                $indexCheck = $pdo->query("SHOW INDEX FROM inventory WHERE Key_name = 'idx_inventory_subdivision'");
                if ($indexCheck->rowCount() == 0) {
                    $pdo->exec("CREATE INDEX idx_inventory_subdivision ON inventory(subdivision_number)");
                    echo "    ✓ Created idx_inventory_subdivision\n";
                } else {
                    echo "    ✓ Index idx_inventory_subdivision already exists\n";
                }
            } catch (Exception $e) {
                echo "    ⚠ Warning: Could not create idx_inventory_subdivision: " . $e->getMessage() . "\n";
            }
            
            // Check and create index for shelf_level
            try {
                $indexCheck = $pdo->query("SHOW INDEX FROM inventory WHERE Key_name = 'idx_inventory_shelf_level'");
                if ($indexCheck->rowCount() == 0) {
                    $pdo->exec("CREATE INDEX idx_inventory_shelf_level ON inventory(shelf_level)");
                    echo "    ✓ Created idx_inventory_shelf_level\n";
                } else {
                    echo "    ✓ Index idx_inventory_shelf_level already exists\n";
                }
            } catch (Exception $e) {
                echo "    ⚠ Warning: Could not create idx_inventory_shelf_level: " . $e->getMessage() . "\n";
            }
            
            // Update the existing unique key to include subdivision_number
            echo "  - Updating unique constraints...\n";
            try {
                // Drop the old unique key
                $pdo->exec("ALTER TABLE inventory DROP INDEX unique_product_location_batch");
                echo "    ✓ Dropped old unique constraint\n";
                
                // Add new unique key that includes subdivision_number
                $pdo->exec("
                    ALTER TABLE inventory 
                    ADD UNIQUE KEY unique_product_location_batch_subdivision 
                    (product_id, location_id, batch_number, subdivision_number)
                ");
                echo "    ✓ Added new unique constraint with subdivision support\n";
                
            } catch (Exception $e) {
                echo "    ⚠ Warning: Could not update unique constraints: " . $e->getMessage() . "\n";
                // Try to recreate the original constraint if the new one failed
                try {
                    $pdo->exec("
                        ALTER TABLE inventory 
                        ADD UNIQUE KEY unique_product_location_batch 
                        (product_id, location_id, batch_number)
                    ");
                    echo "    ✓ Restored original unique constraint\n";
                } catch (Exception $e2) {
                    echo "    ❌ Could not restore original constraint: " . $e2->getMessage() . "\n";
                }
            }
            
            echo "✅ Inventory table subdivision support added successfully!\n";
            
        } catch (Exception $e) {
            echo "    ❌ Error adding columns to inventory table: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        echo "🗑️ Removing subdivision and shelf level columns from inventory table...\n";
        
        try {
            // Drop new unique constraint
            $pdo->exec("ALTER TABLE inventory DROP INDEX IF EXISTS unique_product_location_batch_subdivision");
            
            // Restore original unique constraint
            $pdo->exec("
                ALTER TABLE inventory 
                ADD UNIQUE KEY unique_product_location_batch 
                (product_id, location_id, batch_number)
            ");
            
            // Drop the new columns
            $pdo->exec("ALTER TABLE inventory DROP COLUMN IF EXISTS shelf_level");
            $pdo->exec("ALTER TABLE inventory DROP COLUMN IF EXISTS subdivision_number");
            
            echo "✅ Inventory table columns removed successfully!\n";
            
        } catch (Exception $e) {
            echo "    ❌ Error removing columns from inventory table: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}

// Return instance for migration runner
return new AddSubdivisionShelfColumnsToInventoryMigration();