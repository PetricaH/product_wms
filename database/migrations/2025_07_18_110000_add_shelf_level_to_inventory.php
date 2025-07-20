<?php
/**
 * Migration: Add shelf_level column to inventory table
 */
class AddShelfLevelToInventory {
    public function up(PDO $pdo): void {
        $pdo->exec("ALTER TABLE inventory ADD COLUMN shelf_level ENUM('top','middle','bottom') NOT NULL DEFAULT 'middle' AFTER location_id");
        // Optional index for shelf_level queries
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_shelf_level ON inventory (shelf_level)");
    }

    public function down(PDO $pdo): void {
        // Drop index if exists - MySQL syntax may vary but safe
        try { $pdo->exec('DROP INDEX idx_shelf_level ON inventory'); } catch (Exception $e) {}
        $pdo->exec("ALTER TABLE inventory DROP COLUMN shelf_level");
    }
}
return new AddShelfLevelToInventory();
