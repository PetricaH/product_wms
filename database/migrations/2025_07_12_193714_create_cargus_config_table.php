<?php

class CreateCargusConfigTable
{
    public function up(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE cargus_config (
                id INT PRIMARY KEY AUTO_INCREMENT,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                setting_value TEXT NOT NULL,
                setting_type ENUM('string', 'integer', 'decimal', 'boolean', 'json') DEFAULT 'string',
                description TEXT NULL,
                encrypted BOOLEAN DEFAULT FALSE,
                active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB COMMENT='Cargus API and sender configuration';
        ");

        $pdo->exec("
            INSERT INTO cargus_config (setting_key, setting_value, setting_type, description) VALUES
            ('api_url', 'https://urgentcargus.portal.azure-api.net/', 'string', 'Cargus API base URL'),
            ('subscription_key', '', 'string', 'Cargus API subscription key'),
            ('username', '', 'string', 'Cargus login username'),
            ('password', '', 'string', 'Cargus login password'),
            ('default_service_id', '34', 'integer', 'Default service ID (34=Economic Standard)'),
            ('token_cache_duration', '23', 'integer', 'Token cache duration in hours'),
            ('default_sender_location_id', '1', 'integer', 'Default sender location ID'),
            ('auto_calculate_weight', 'true', 'boolean', 'Automatically calculate weight from products'),
            ('auto_calculate_parcels', 'true', 'boolean', 'Automatically calculate parcel count'),
            ('liquid_separate_parcels', 'true', 'boolean', 'Ship liquids in separate parcels');
        ");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS cargus_config;");
    }
}

return new CreateCargusConfigTable();