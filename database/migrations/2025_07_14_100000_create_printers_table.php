<?php

class CreatePrintersTable
{
    public function up(PDO $pdo): void
    {
        $pdo->exec("\n            CREATE TABLE printers (\n                id INT PRIMARY KEY AUTO_INCREMENT,\n                name VARCHAR(255) NOT NULL,\n                network_identifier VARCHAR(255) NOT NULL,\n                is_default BOOLEAN DEFAULT FALSE,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n                INDEX idx_printers_default (is_default)\n            ) ENGINE=InnoDB COMMENT='Configured network printers';\n        ");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS printers;");
    }
}

return new CreatePrintersTable();
