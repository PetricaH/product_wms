<?php
/**
 * Migration: create_sellers_table
 * Created: 2025-07-10 14:00:00
 * Purpose: Create sellers/suppliers table for stock purchases
 */

class CreateSellersTableMigration {
    public function up(PDO $pdo) {
        echo "🏗️  Creating sellers table...\n";
        
        $pdo->exec("
            CREATE TABLE sellers (
                id INT PRIMARY KEY AUTO_INCREMENT,
                supplier_name VARCHAR(255) NOT NULL,
                cif VARCHAR(50),
                registration_number VARCHAR(100),
                supplier_code VARCHAR(50),
                address TEXT,
                city VARCHAR(100),
                county VARCHAR(100),
                bank_name VARCHAR(255),
                iban VARCHAR(34),
                country VARCHAR(100) DEFAULT 'Romania',
                email VARCHAR(255),
                contact_person VARCHAR(255),
                phone VARCHAR(50),
                notes TEXT,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_supplier_name (supplier_name),
                INDEX idx_cif (cif),
                INDEX idx_supplier_code (supplier_code),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        echo "✅ Sellers table created successfully!\n";
    }

    public function down(PDO $pdo) {
        echo "🗑️  Dropping sellers table...\n";
        $pdo->exec("DROP TABLE IF EXISTS sellers");
        echo "✅ Sellers table dropped!\n";
    }
}

return new CreateSellersTableMigration();