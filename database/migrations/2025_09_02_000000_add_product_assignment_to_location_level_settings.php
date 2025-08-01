<?php
class AddProductAssignmentToLocationLevelSettingsMigration {
    public function up(PDO $pdo) {
        $pdo->exec("ALTER TABLE location_level_settings
            ADD COLUMN dedicated_product_id INT DEFAULT NULL AFTER items_capacity,
            ADD COLUMN allow_other_products BOOLEAN DEFAULT TRUE AFTER dedicated_product_id,
            ADD INDEX idx_dedicated_product_id (dedicated_product_id)");
    }
    public function down(PDO $pdo) {
        $pdo->exec("ALTER TABLE location_level_settings
            DROP COLUMN allow_other_products,
            DROP COLUMN dedicated_product_id");
    }
}
return new AddProductAssignmentToLocationLevelSettingsMigration();
