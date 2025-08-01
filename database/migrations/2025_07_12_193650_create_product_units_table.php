<?php

class CreateProductUnitsTable
{
    public function up(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE product_units (
                id INT PRIMARY KEY AUTO_INCREMENT,
                product_id INT NOT NULL,
                unit_type_id INT NOT NULL,
                weight_per_unit DECIMAL(8,3) NOT NULL COMMENT 'Actual weight in kg per unit for this product',
                volume_per_unit DECIMAL(8,3) NULL COMMENT 'Volume in liters per unit',
                dimensions_length DECIMAL(6,2) NULL COMMENT 'Length in cm',
                dimensions_width DECIMAL(6,2) NULL COMMENT 'Width in cm',
                dimensions_height DECIMAL(6,2) NULL COMMENT 'Height in cm',
                max_stack_height INT DEFAULT 1 COMMENT 'How many can be stacked',
                fragile BOOLEAN DEFAULT FALSE,
                hazardous BOOLEAN DEFAULT FALSE,
                temperature_controlled BOOLEAN DEFAULT FALSE,
                packaging_cost DECIMAL(6,2) DEFAULT 0.00 COMMENT 'Additional packaging cost',
                active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_product_unit (product_id, unit_type_id),
                FOREIGN KEY (unit_type_id) REFERENCES unit_types(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB COMMENT='Product-specific unit weights and packaging rules';
        ");
        // Note: The FOREIGN KEY to 'products' is commented out as the 'products' table migration is not provided.
        // To enable it, ensure a 'products' table with an 'id' column exists before running this migration.
        // ALTER TABLE product_units ADD CONSTRAINT fk_product_units_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE;
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS product_units;");
    }
}

return new CreateProductUnitsTable();
