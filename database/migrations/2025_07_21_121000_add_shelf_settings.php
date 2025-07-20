<?php
class AddShelfSettingsMigration {
    public function up(PDO $pdo) {
        $pdo->exec("INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
            ('pallets_per_level', '2', 'integer', 'Pallets that fit on each shelf level'),
            ('barrels_per_pallet_5l', '40', 'integer', '5L barrels per pallet'),
            ('barrels_per_pallet_10l', '30', 'integer', '10L barrels per pallet'),
            ('barrels_per_pallet_25l', '20', 'integer', '25L barrels per pallet')
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    }
    public function down(PDO $pdo) {
        $pdo->exec("DELETE FROM settings WHERE setting_key IN (
            'pallets_per_level',
            'barrels_per_pallet_5l',
            'barrels_per_pallet_10l',
            'barrels_per_pallet_25l'
        )");
    }
}
return new AddShelfSettingsMigration();
