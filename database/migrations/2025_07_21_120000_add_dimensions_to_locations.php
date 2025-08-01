<?php
class AddDimensionsToLocationsMigration {
    public function up(PDO $pdo) {
        $pdo->exec(
            "ALTER TABLE locations
             ADD COLUMN length_mm INT DEFAULT 0 AFTER capacity,
             ADD COLUMN depth_mm INT DEFAULT 0 AFTER length_mm,
             ADD COLUMN height_mm INT DEFAULT 0 AFTER depth_mm,
             ADD COLUMN max_weight_kg DECIMAL(8,2) DEFAULT 0 AFTER height_mm"
        );
    }
    public function down(PDO $pdo) {
        $pdo->exec(
            "ALTER TABLE locations
             DROP COLUMN max_weight_kg,
             DROP COLUMN height_mm,
             DROP COLUMN depth_mm,
             DROP COLUMN length_mm"
        );
    }
}
return new AddDimensionsToLocationsMigration();
