<?php
class CreateLocationSubdivisionsTableMigration {
    public function up(PDO $pdo) {
        $pdo->exec("CREATE TABLE location_subdivisions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            location_id INT NOT NULL,
            level_number INT NOT NULL,
            subdivision_number INT NOT NULL,
            items_capacity INT DEFAULT NULL,
            dedicated_product_id INT DEFAULT NULL,
            allow_other_products BOOLEAN DEFAULT TRUE,
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY u_location_sub (location_id, level_number, subdivision_number),
            FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    }
    public function down(PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS location_subdivisions");
    }
}
return new CreateLocationSubdivisionsTableMigration();
