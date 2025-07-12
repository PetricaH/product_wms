<?php

class CreatePackagingRulesTable
{
    public function up(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE packaging_rules (
                id INT PRIMARY KEY AUTO_INCREMENT,
                rule_name VARCHAR(100) NOT NULL,
                rule_type ENUM('weight_based', 'volume_based', 'count_based', 'product_type') NOT NULL,
                max_weight_per_parcel DECIMAL(8,3) NULL COMMENT 'Max weight in kg per parcel',
                max_volume_per_parcel DECIMAL(8,3) NULL COMMENT 'Max volume in liters per parcel',
                max_items_per_parcel INT NULL COMMENT 'Max items count per parcel',
                applies_to_unit_types JSON NULL COMMENT 'Array of unit type IDs this rule applies to',
                priority INT DEFAULT 0 COMMENT 'Higher priority rules are applied first',
                active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB COMMENT='Rules for automatic packaging calculation';
        ");

        $pdo->exec("
            INSERT INTO packaging_rules (rule_name, rule_type, max_weight_per_parcel, max_items_per_parcel, applies_to_unit_types, priority) VALUES
            ('Lichide - parcel separat', 'product_type', 30.000, 1, '[1]', 100),
            ('Produse mici - combinabile', 'count_based', 20.000, 50, '[2, 3]', 50),
            ('Produse grele - individual', 'weight_based', 30.000, 1, '[4]', 75),
            ('Default - standard', 'weight_based', 25.000, 10, NULL, 10);
        ");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS packaging_rules;");
    }
}

return new CreatePackagingRulesTable();