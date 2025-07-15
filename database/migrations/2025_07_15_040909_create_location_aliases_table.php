<?php
class CreateLocationAliasesTableMigration {
    
    public function up(PDO $pdo) {
        echo "Creating location_aliases table...\n";
        
        $pdo->exec("
            CREATE TABLE location_aliases (
                id INT PRIMARY KEY AUTO_INCREMENT,
                alias_code VARCHAR(50) UNIQUE NOT NULL,
                location_id INT NOT NULL,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_alias_code (alias_code),
                INDEX idx_location_id (location_id),
                
                FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
            )
        ");
        
        echo "location_aliases table created successfully!\n";
    }
    
    public function down(PDO $pdo) {
        echo "Dropping location_aliases table...\n";
        $pdo->exec("DROP TABLE IF EXISTS location_aliases");
        echo "location_aliases table dropped!\n";
    }
}

return new CreateLocationAliasesTableMigration();
?>