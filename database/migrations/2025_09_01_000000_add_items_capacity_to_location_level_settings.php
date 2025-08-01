<?php
class AddItemsCapacityToLocationLevelSettingsMigration {
    public function up(PDO $pdo) {
        $pdo->exec("ALTER TABLE location_level_settings ADD COLUMN items_capacity INT DEFAULT NULL AFTER max_weight_kg");
    }
    public function down(PDO $pdo) {
        $pdo->exec("ALTER TABLE location_level_settings DROP COLUMN items_capacity");
    }
}
return new AddItemsCapacityToLocationLevelSettingsMigration();
