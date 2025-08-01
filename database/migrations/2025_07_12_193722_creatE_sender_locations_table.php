<?php

class CreateSenderLocationsTable
{
    public function up(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE sender_locations (
                id INT PRIMARY KEY AUTO_INCREMENT,
                location_name VARCHAR(200) NOT NULL,
                company_name VARCHAR(200) NOT NULL,
                contact_person VARCHAR(200) NOT NULL,
                phone VARCHAR(20) NOT NULL,
                email VARCHAR(100) NOT NULL,
                county_id INT NOT NULL,
                locality_id INT NOT NULL,
                street_id INT NULL,
                building_number VARCHAR(20) NOT NULL,
                address_text TEXT NOT NULL,
                cargus_location_id INT NULL COMMENT 'Cargus pickup location ID',
                is_default BOOLEAN DEFAULT FALSE,
                active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB COMMENT='Sender locations for AWB generation';
        ");

        $pdo->exec("
            INSERT INTO sender_locations (location_name, company_name, contact_person, phone, email, county_id, locality_id, building_number, address_text, is_default) VALUES
            ('Depozit Principal', 'Compania Dumneavoastră SRL', 'Manager Depozit', '0721234567', 'depozit@company.com', 1, 1, '1', 'Strada Principală nr. 1', TRUE);
        ");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS sender_locations;");
    }
}

return new CreateSenderLocationsTable();