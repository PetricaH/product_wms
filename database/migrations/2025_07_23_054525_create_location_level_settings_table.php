<?php
/**
 * Migration: create_location_level_settings_table
 * Created: 2025-07-23 05:45:25
 */

class CreateLocationLevelSettingsTableMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        echo "📋 Creating location_level_settings table...\n";
        
        $pdo->exec("
            CREATE TABLE location_level_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                location_id INT NOT NULL,
                level_number INT NOT NULL COMMENT 'Level number (1=bottom, 2=middle, 3=top, etc.)',
                level_name VARCHAR(50) DEFAULT NULL COMMENT 'Custom level name (optional)',
                
                -- Storage Policy Configuration
                storage_policy ENUM('single_product_type', 'multiple_products', 'category_restricted') DEFAULT 'multiple_products',
                allowed_product_types JSON NULL COMMENT 'Array of allowed product types/categories if restricted',
                max_different_products INT DEFAULT NULL COMMENT 'Max number of different products allowed',
                
                -- Physical Dimensions (per level)
                length_mm INT DEFAULT 0 COMMENT 'Level length in millimeters',
                depth_mm INT DEFAULT 0 COMMENT 'Level depth in millimeters', 
                height_mm INT DEFAULT 0 COMMENT 'Level height in millimeters',
                max_weight_kg DECIMAL(8,2) DEFAULT 0 COMMENT 'Maximum weight capacity for this level',
                
                -- Product Placement Rules
                volume_min_liters DECIMAL(8,3) DEFAULT NULL COMMENT 'Minimum product volume allowed',
                volume_max_liters DECIMAL(8,3) DEFAULT NULL COMMENT 'Maximum product volume allowed',
                weight_min_kg DECIMAL(8,3) DEFAULT NULL COMMENT 'Minimum product weight allowed',
                weight_max_kg DECIMAL(8,3) DEFAULT NULL COMMENT 'Maximum product weight allowed',
                
                -- Automatic Repartition Settings
                enable_auto_repartition BOOLEAN DEFAULT FALSE COMMENT 'Enable automatic product redistribution',
                repartition_trigger_threshold INT DEFAULT 80 COMMENT 'Occupancy % that triggers repartition',
                priority_order INT DEFAULT 0 COMMENT 'Level priority for product placement (higher = preferred)',
                
                -- Additional Settings
                requires_special_handling BOOLEAN DEFAULT FALSE,
                temperature_controlled BOOLEAN DEFAULT FALSE,
                notes TEXT DEFAULT NULL,
                
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                -- Constraints
                UNIQUE KEY unique_location_level (location_id, level_number),
                FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
                
                -- Indexes
                INDEX idx_location_id (location_id),
                INDEX idx_level_number (level_number),
                INDEX idx_storage_policy (storage_policy),
                INDEX idx_auto_repartition (enable_auto_repartition)
            ) ENGINE=InnoDB COMMENT='Per-level configuration for locations'
        ");
        
        echo "✅ location_level_settings table created!\n";
        
        // Insert default settings for existing locations
        echo "📊 Creating default level settings for existing locations...\n";
        
        $pdo->exec("
            INSERT INTO location_level_settings 
            (location_id, level_number, level_name, storage_policy, length_mm, depth_mm, height_mm, max_weight_kg, priority_order)
            SELECT 
                l.id,
                level_nums.level_number,
                CASE level_nums.level_number
                    WHEN 1 THEN 'Bottom'
                    WHEN 2 THEN 'Middle' 
                    WHEN 3 THEN 'Top'
                    ELSE CONCAT('Level ', level_nums.level_number)
                END as level_name,
                'multiple_products' as storage_policy,
                COALESCE(l.length_mm, 1000) as length_mm,
                COALESCE(l.depth_mm, 400) as depth_mm,
                COALESCE(l.height_mm / l.levels, 300) as height_mm,
                COALESCE(l.max_weight_kg / l.levels, 50) as max_weight_kg,
                CASE level_nums.level_number
                    WHEN 1 THEN 3  -- Bottom = highest priority for heavy items
                    WHEN 2 THEN 2  -- Middle = medium priority
                    WHEN 3 THEN 1  -- Top = lowest priority for light items
                    ELSE level_nums.level_number
                END as priority_order
            FROM locations l
            CROSS JOIN (
                SELECT 1 as level_number UNION ALL
                SELECT 2 UNION ALL 
                SELECT 3 UNION ALL
                SELECT 4 UNION ALL
                SELECT 5
            ) level_nums
            WHERE l.type = 'shelf' 
            AND level_nums.level_number <= l.levels
            AND l.levels > 0
        ");
        
        echo "✅ Default level settings created!\n";
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        echo "🗑️ Dropping location_level_settings table...\n";
        $pdo->exec("DROP TABLE IF EXISTS location_level_settings");
        echo "✅ location_level_settings table dropped!\n";
    }
}

// Return instance for migration runner
return new CreateLocationLevelSettingsTableMigration();