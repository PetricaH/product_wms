<?php
/**
 * Migration: create_print_servers_table
 * Created: 2025-07-14 12:00:00
 */

class CreatePrintServersTableMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        $sql = "
            CREATE TABLE print_servers (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL COMMENT 'Friendly name (e.g., Reception PC, Warehouse Station 1)',
                ip_address VARCHAR(45) NOT NULL COMMENT 'IP address of the PC',
                port INT NOT NULL DEFAULT 8000 COMMENT 'Port where print server is running',
                is_active BOOLEAN DEFAULT TRUE COMMENT 'Whether server is currently available',
                last_ping TIMESTAMP NULL COMMENT 'Last successful connection test',
                location VARCHAR(255) NULL COMMENT 'Physical location description',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_print_servers_ip_port (ip_address, port),
                INDEX idx_print_servers_active (is_active),
                INDEX idx_print_servers_location (location)
            ) ENGINE=InnoDB COMMENT='Print servers (PCs running local print server)'
        ";
        $pdo->exec($sql);
        
        // Insert some sample data
        $insertSql = "
            INSERT INTO print_servers (name, ip_address, port, location) VALUES
            ('Reception PC', '192.168.1.100', 8000, 'Reception Desk'),
            ('Warehouse Station 1', '192.168.1.101', 8000, 'Warehouse - Picking Area'),
            ('Warehouse Station 2', '192.168.1.102', 8000, 'Warehouse - Packing Area'),
            ('Office Mac', '192.168.1.103', 8000, 'Main Office')
        ";
        $pdo->exec($insertSql);
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS print_servers");
    }
}

// Return instance for migration runner
return new CreatePrintServersTableMigration();