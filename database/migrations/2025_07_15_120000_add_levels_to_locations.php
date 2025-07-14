<?php
class AddLevelsToLocationsMigration {
    public function up(PDO $pdo) {
        echo "\u{1F4CF} Adding levels column to locations table...\n";
        $pdo->exec("ALTER TABLE locations ADD COLUMN levels INT DEFAULT 3 AFTER type");
        echo "\u2705 levels column added!\n";
    }
    public function down(PDO $pdo) {
        echo "\u{1F5D1}\uFE0F Dropping levels column from locations table...\n";
        $pdo->exec("ALTER TABLE locations DROP COLUMN levels");
        echo "\u2705 levels column dropped!\n";
    }
}
return new AddLevelsToLocationsMigration();
