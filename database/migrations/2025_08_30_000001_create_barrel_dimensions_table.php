<?php
class CreateBarrelDimensionsTableMigration {
    public function up(PDO $pdo) {
        echo "Creating barrel_dimensions table...\n";
        $pdo->exec("CREATE TABLE barrel_dimensions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            label VARCHAR(50) NOT NULL,
            length_cm DECIMAL(6,2) NOT NULL,
            width_cm DECIMAL(6,2) NOT NULL,
            height_cm DECIMAL(6,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        echo "barrel_dimensions table created!\n";
    }

    public function down(PDO $pdo) {
        echo "Dropping barrel_dimensions table...\n";
        $pdo->exec("DROP TABLE IF EXISTS barrel_dimensions;");
        echo "barrel_dimensions table dropped!\n";
    }
}
return new CreateBarrelDimensionsTableMigration();
