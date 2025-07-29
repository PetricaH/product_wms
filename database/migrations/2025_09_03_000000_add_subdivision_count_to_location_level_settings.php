<?php
class AddSubdivisionCountToLocationLevelSettingsMigration {
    public function up(PDO $pdo) {
        $pdo->exec("ALTER TABLE location_level_settings ADD COLUMN subdivision_count INT DEFAULT 1 AFTER priority_order");
    }
    public function down(PDO $pdo) {
        $pdo->exec("ALTER TABLE location_level_settings DROP COLUMN subdivision_count");
    }
}
return new AddSubdivisionCountToLocationLevelSettingsMigration();
