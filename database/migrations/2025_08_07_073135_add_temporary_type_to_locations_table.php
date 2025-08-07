<?php
/**
 * Migration: add_temporary_type_to_locations
 * Created: 2025-08-07
 * Purpose: Add 'temporary' type to locations ENUM
 */

class AddTemporaryTypeToLocations {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        echo "📍 Adding 'temporary' type to locations table...\n";
        
        $sql = "
            ALTER TABLE locations 
            MODIFY COLUMN type ENUM(
                'warehouse', 'zone', 'rack', 'shelf', 'bin',
                'qc_hold', 'quarantine', 'pending_approval', 'temporary'
            ) DEFAULT 'bin'
        ";
        
        $pdo->exec($sql);
        
        echo "✅ 'temporary' type added to locations table!\n";
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        echo "🗑️ Removing 'temporary' type from locations table...\n";
        
        // First, update any existing temporary locations to 'bin'
        $pdo->exec("UPDATE locations SET type = 'bin' WHERE type = 'temporary'");
        
        // Then remove 'temporary' from the ENUM
        $sql = "
            ALTER TABLE locations 
            MODIFY COLUMN type ENUM(
                'warehouse', 'zone', 'rack', 'shelf', 'bin',
                'qc_hold', 'quarantine', 'pending_approval'
            ) DEFAULT 'bin'
        ";
        
        $pdo->exec($sql);
        
        echo "✅ 'temporary' type removed from locations table!\n";
    }
}

// Return instance for migration runner
return new AddTemporaryTypeToLocations();