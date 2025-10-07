<?php
/**
 * Migration: create_facturi_table
 * Created: 2025-10-07 12:00:00
 * Purpose: Creates `facturi` table to track invoices and somații metadata.
 * 
 * PDF files are stored on disk at:
 *   /var/www/wartung.notsowms.ro/storage/somatii/somatii/{year}/
 *   /var/www/wartung.notsowms.ro/storage/somatii/facturi-somatii/{year}/
 */

class CreateFacturiTableMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo): void {
        echo "📄 Creating facturi table...\n";
        
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS facturi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nr_factura VARCHAR(64) NOT NULL UNIQUE,
    nume_firma VARCHAR(255) NOT NULL,
    cif VARCHAR(64) NULL,
    reg_com VARCHAR(64) NULL,
    adresa TEXT NULL,
    data_emitere DATE NULL,
    termen_plata DATE NULL,
    suma DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('neplatita','platita') NOT NULL DEFAULT 'neplatita',
    file_path VARCHAR(512) NULL COMMENT 'Full path for combined factura+somație PDF',
    somatie_path VARCHAR(512) NULL COMMENT 'Full path for individual somație PDF',
    sha256 CHAR(64) NULL COMMENT 'Optional hash for deduplication',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cif (cif),
    INDEX idx_data_emitere (data_emitere),
    INDEX idx_status (status)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
SQL;
        
        $pdo->exec($sql);
        echo "✅ Created table: facturi\n";
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo): void {
        echo "🗑️  Rolling back facturi table...\n";
        
        $pdo->exec("DROP TABLE IF EXISTS facturi;");
        echo "✅ Dropped table: facturi\n";
    }
}

return new CreateFacturiTableMigration();