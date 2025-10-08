<?php
/**
 * Migration: add_missing_columns_to_returns_table
 * Created: 2025-10-07 10:00:00
 * Purpose: Add return_awb, auto_created, return_date columns to returns table
 *          and location_id to return_items table for warehouse processing
 */

class AddMissingColumnsToReturnsTableMigration {

    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        echo "Adding missing columns to returns table...\n";
        
        // Check if columns already exist before adding
        $stmt = $pdo->query("SHOW COLUMNS FROM returns LIKE 'return_awb'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                ALTER TABLE returns
                    ADD COLUMN return_awb VARCHAR(50) NULL AFTER status
            ");
            echo "✓ Added return_awb column\n";
        } else {
            echo "✓ return_awb column already exists\n";
        }
        
        $stmt = $pdo->query("SHOW COLUMNS FROM returns LIKE 'auto_created'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                ALTER TABLE returns
                    ADD COLUMN auto_created TINYINT(1) NOT NULL DEFAULT 0 AFTER return_awb
            ");
            echo "✓ Added auto_created column\n";
        } else {
            echo "✓ auto_created column already exists\n";
        }
        
        $stmt = $pdo->query("SHOW COLUMNS FROM returns LIKE 'return_date'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                ALTER TABLE returns
                    ADD COLUMN return_date TIMESTAMP NULL AFTER auto_created
            ");
            echo "✓ Added return_date column\n";
        } else {
            echo "✓ return_date column already exists\n";
        }
        
        // Ensure sensible defaults for historic data
        $pdo->exec("UPDATE returns SET auto_created = 0 WHERE auto_created IS NULL");
        
        echo "Adding location_id to return_items table...\n";
        
        // Add location_id to return_items if it doesn't exist
        $stmt = $pdo->query("SHOW COLUMNS FROM return_items LIKE 'location_id'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                ALTER TABLE return_items
                    ADD COLUMN location_id INT NULL AFTER quantity_returned,
                    ADD INDEX idx_return_items_location (location_id)
            ");
            
            // Add foreign key constraint if locations table exists
            try {
                $pdo->exec("
                    ALTER TABLE return_items
                        ADD CONSTRAINT fk_return_items_location
                        FOREIGN KEY (location_id) REFERENCES locations(id)
                        ON DELETE SET NULL
                ");
                echo "✓ Added location_id column with foreign key\n";
            } catch (Exception $e) {
                echo "✓ Added location_id column (foreign key skipped)\n";
            }
        } else {
            echo "✓ location_id column already exists\n";
        }
        
        echo "Migration completed successfully!\n";
    }

    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        echo "Rolling back returns table changes...\n";
        
        // Remove location_id from return_items
        $stmt = $pdo->query("SHOW COLUMNS FROM return_items LIKE 'location_id'");
        if ($stmt->rowCount() > 0) {
            // Drop foreign key first if it exists
            try {
                $pdo->exec("ALTER TABLE return_items DROP FOREIGN KEY fk_return_items_location");
            } catch (Exception $e) {
                // Foreign key might not exist, continue
            }
            
            $pdo->exec("
                ALTER TABLE return_items
                    DROP INDEX idx_return_items_location,
                    DROP COLUMN location_id
            ");
            echo "✓ Removed location_id column\n";
        }
        
        // Remove columns from returns table
        $pdo->exec("
            ALTER TABLE returns
                DROP COLUMN return_date,
                DROP COLUMN auto_created,
                DROP COLUMN return_awb
        ");
        
        echo "Rollback completed successfully!\n";
    }
}

// Return instance for migration runner
return new AddMissingColumnsToReturnsTableMigration();