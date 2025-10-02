<?php
/**
 * Migration: increase_package_content_length
 * Created: 2025-10-01 14:00:00
 * Purpose: Increase package_content column size from VARCHAR(255) to VARCHAR(500)
 *          to accommodate longer product descriptions in shipping orders
 */

class IncreasePackageContentLengthMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        echo "📦 Increasing package_content column size to VARCHAR(500)...\n";
        
        $sql = "ALTER TABLE orders 
                MODIFY COLUMN package_content VARCHAR(500) NULL 
                COMMENT 'Package content description'";
        
        $pdo->exec($sql);
        
        echo "✅ Successfully increased package_content column size\n";
    }
    
    /**
     * Reverse the migration
     */
    public function down(PDO $pdo) {
        echo "🔄 Reverting package_content column to VARCHAR(255)...\n";
        
        $sql = "ALTER TABLE orders 
                MODIFY COLUMN package_content VARCHAR(255) NULL 
                COMMENT 'Package content description'";
        
        $pdo->exec($sql);
        
        echo "✅ Successfully reverted package_content column size\n";
    }
}

return new IncreasePackageContentLengthMigration();