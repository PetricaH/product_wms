<?php

class AddIndexesToTables
{
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE INDEX idx_product_units_product ON product_units(product_id);");
        $pdo->exec("CREATE INDEX idx_product_units_unit_type ON product_units(unit_type_id);");
        $pdo->exec("CREATE INDEX idx_product_units_active ON product_units(active);");
        $pdo->exec("CREATE INDEX idx_unit_types_code ON unit_types(unit_code);");
        $pdo->exec("CREATE INDEX idx_packaging_rules_priority ON packaging_rules(priority DESC);");
        $pdo->exec("CREATE INDEX idx_cargus_config_key ON cargus_config(setting_key);");
        $pdo->exec("CREATE INDEX idx_sender_locations_default ON sender_locations(is_default);");
    }

    public function down(PDO $pdo): void
    {
        // Note: Dropping indexes requires knowing the table name.
        $pdo->exec("DROP INDEX idx_product_units_product ON product_units;");
        $pdo->exec("DROP INDEX idx_product_units_unit_type ON product_units;");
        $pdo->exec("DROP INDEX idx_product_units_active ON product_units;");
        $pdo->exec("DROP INDEX idx_unit_types_code ON unit_types;");
        $pdo->exec("DROP INDEX idx_packaging_rules_priority ON packaging_rules;");
        $pdo->exec("DROP INDEX idx_cargus_config_key ON cargus_config;");
        $pdo->exec("DROP INDEX idx_sender_locations_default ON sender_locations;");
    }
}

return new AddIndexesToTables();