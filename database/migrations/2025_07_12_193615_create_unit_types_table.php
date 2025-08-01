<?php

class CreateUnitTypesTable
{
    /**
     * Run the migration.
     *
     * @param PDO $pdo
     * @return void
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE unit_types (
                id INT PRIMARY KEY AUTO_INCREMENT,
                unit_code VARCHAR(20) NOT NULL UNIQUE COMMENT 'Unit code: litri, buc, cartus, kg, etc.',
                unit_name VARCHAR(100) NOT NULL COMMENT 'Display name for unit',
                base_type ENUM('weight', 'volume', 'count') NOT NULL COMMENT 'Base measurement type',
                default_weight_per_unit DECIMAL(8,3) NULL COMMENT 'Default weight in kg per unit',
                density_factor DECIMAL(8,3) NULL COMMENT 'For volume units - density factor',
                packaging_type ENUM('single', 'bulk', 'liquid', 'fragile') DEFAULT 'single' COMMENT 'Packaging behavior',
                max_items_per_parcel INT DEFAULT 1 COMMENT 'Max items of this type per parcel',
                requires_separate_parcel BOOLEAN DEFAULT FALSE COMMENT 'Must be shipped separately',
                active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB COMMENT='Defines unit types and their packaging properties';
        ");

        $pdo->exec("
            INSERT INTO unit_types (unit_code, unit_name, base_type, default_weight_per_unit, packaging_type, max_items_per_parcel, requires_separate_parcel) VALUES
            ('litri', 'Litri', 'volume', 1.000, 'liquid', 1, TRUE),
            ('buc', 'Bucăți', 'count', 0.500, 'single', 10, FALSE),
            ('cartus', 'Cartușe', 'count', 0.200, 'single', 20, FALSE),
            ('kg', 'Kilograme', 'weight', 1.000, 'bulk', 1, FALSE),
            ('ml', 'Mililitri', 'volume', 0.001, 'liquid', 50, FALSE),
            ('gr', 'Grame', 'weight', 0.001, 'single', 100, FALSE),
            ('set', 'Seturi', 'count', 1.500, 'bulk', 5, FALSE),
            ('cutie', 'Cutii', 'count', 0.800, 'single', 8, FALSE);
        ");
    }

    /**
     * Reverse the migration.
     *
     * @param PDO $pdo
     * @return void
     */
    public function down(PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS unit_types;");
    }
}

return new CreateUnitTypesTable();