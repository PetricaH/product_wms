<?php
/**
 * Migration: Enhanced Subdivision System
 * Created: 2025-01-29 10:00:00
 * File: database/migrations/2025_01_29_100000_enhance_subdivision_system.php
 */

class EnhanceSubdivisionSystemMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        echo "📋 Enhancing subdivision system...\n";
        
        // Add subdivisions_enabled flag to location_level_settings
        echo "  - Adding subdivisions_enabled column...\n";
        try {
            $columnCheck = $pdo->query("SHOW COLUMNS FROM location_level_settings LIKE 'subdivisions_enabled'");
            if ($columnCheck->rowCount() == 0) {
                $pdo->exec("
                    ALTER TABLE location_level_settings 
                    ADD COLUMN subdivisions_enabled BOOLEAN DEFAULT FALSE 
                    AFTER subdivision_count
                ");
                echo "    ✓ Added subdivisions_enabled column\n";
            } else {
                echo "    ✓ Column subdivisions_enabled already exists\n";
            }
        } catch (Exception $e) {
            echo "    ❌ Error adding subdivisions_enabled column: " . $e->getMessage() . "\n";
            throw $e;
        }
        
        // Update location_subdivisions table to include product-specific fields
        echo "  - Enhancing location_subdivisions table...\n";
        
        // Check if columns already exist before adding them
        $checkProductCapacity = $pdo->query("SHOW COLUMNS FROM location_subdivisions LIKE 'product_capacity'");
        if ($checkProductCapacity->rowCount() == 0) {
            $pdo->exec("
                ALTER TABLE location_subdivisions 
                ADD COLUMN product_capacity INT DEFAULT NULL 
                AFTER items_capacity
            ");
        }
        
        $checkProductName = $pdo->query("SHOW COLUMNS FROM location_subdivisions LIKE 'product_name'");
        if ($checkProductName->rowCount() == 0) {
            $pdo->exec("
                ALTER TABLE location_subdivisions 
                ADD COLUMN product_name VARCHAR(255) DEFAULT NULL 
                AFTER dedicated_product_id
            ");
        }
        
        // Update existing location_level_settings where subdivision_count > 1
        echo "  - Updating existing subdivisions...\n";
        try {
            $updateResult = $pdo->exec("
                UPDATE location_level_settings 
                SET subdivisions_enabled = TRUE,
                    storage_policy = 'multiple_products'
                WHERE subdivision_count > 1
            ");
            echo "    ✓ Updated {$updateResult} existing subdivision configurations\n";
        } catch (Exception $e) {
            echo "    ⚠ Warning: Could not update existing subdivisions: " . $e->getMessage() . "\n";
        }
        
        // Create indexes for better performance
        echo "  - Creating indexes...\n";
        
        // Check and create index for location_subdivisions
        try {
            $indexCheck = $pdo->query("SHOW INDEX FROM location_subdivisions WHERE Key_name = 'idx_location_subdivisions_product'");
            if ($indexCheck->rowCount() == 0) {
                $pdo->exec("CREATE INDEX idx_location_subdivisions_product ON location_subdivisions(dedicated_product_id)");
                echo "    ✓ Created idx_location_subdivisions_product\n";
            } else {
                echo "    ✓ Index idx_location_subdivisions_product already exists\n";
            }
        } catch (Exception $e) {
            echo "    ⚠ Warning: Could not create idx_location_subdivisions_product: " . $e->getMessage() . "\n";
        }
        
        // Check and create index for location_level_settings
        try {
            $indexCheck = $pdo->query("SHOW INDEX FROM location_level_settings WHERE Key_name = 'idx_location_level_settings_subdivisions'");
            if ($indexCheck->rowCount() == 0) {
                $pdo->exec("CREATE INDEX idx_location_level_settings_subdivisions ON location_level_settings(subdivisions_enabled)");
                echo "    ✓ Created idx_location_level_settings_subdivisions\n";
            } else {
                echo "    ✓ Index idx_location_level_settings_subdivisions already exists\n";
            }
        } catch (Exception $e) {
            echo "    ⚠ Warning: Could not create idx_location_level_settings_subdivisions: " . $e->getMessage() . "\n";
        }
        
        echo "✅ Enhanced subdivision system migration completed successfully!\n";
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        echo "🗑️ Rolling back subdivision system enhancements...\n";
        
        // Remove indexes
        echo "  - Removing indexes...\n";
        
        try {
            $pdo->exec("DROP INDEX idx_location_subdivisions_product ON location_subdivisions");
            echo "    ✓ Dropped idx_location_subdivisions_product\n";
        } catch (Exception $e) {
            echo "    ⚠ Index idx_location_subdivisions_product not found or already dropped\n";
        }
        
        try {
            $pdo->exec("DROP INDEX idx_location_level_settings_subdivisions ON location_level_settings");
            echo "    ✓ Dropped idx_location_level_settings_subdivisions\n";
        } catch (Exception $e) {
            echo "    ⚠ Index idx_location_level_settings_subdivisions not found or already dropped\n";
        }
        
        // Remove added columns
        echo "  - Removing added columns...\n";
        
        try {
            $columnCheck = $pdo->query("SHOW COLUMNS FROM location_subdivisions LIKE 'product_capacity'");
            if ($columnCheck->rowCount() > 0) {
                $pdo->exec("ALTER TABLE location_subdivisions DROP COLUMN product_capacity");
                echo "    ✓ Removed product_capacity column\n";
            }
        } catch (Exception $e) {
            echo "    ⚠ Column product_capacity not found or already removed\n";
        }
        
        try {
            $columnCheck = $pdo->query("SHOW COLUMNS FROM location_subdivisions LIKE 'product_name'");
            if ($columnCheck->rowCount() > 0) {
                $pdo->exec("ALTER TABLE location_subdivisions DROP COLUMN product_name");
                echo "    ✓ Removed product_name column\n";
            }
        } catch (Exception $e) {
            echo "    ⚠ Column product_name not found or already removed\n";
        }
        
        try {
            $columnCheck = $pdo->query("SHOW COLUMNS FROM location_level_settings LIKE 'subdivisions_enabled'");
            if ($columnCheck->rowCount() > 0) {
                $pdo->exec("ALTER TABLE location_level_settings DROP COLUMN subdivisions_enabled");
                echo "    ✓ Removed subdivisions_enabled column\n";
            }
        } catch (Exception $e) {
            echo "    ⚠ Column subdivisions_enabled not found or already removed\n";
        }
        
        echo "✅ Subdivision system enhancements rolled back successfully!\n";
    }
}

// Return instance for migration runner
return new EnhanceSubdivisionSystemMigration();