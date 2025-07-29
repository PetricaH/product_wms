<?php
/**
 * Migration: Create location_qr_codes table
 * Created: 2025-07-29 
 */

class CreateLocationQrCodesTableMigration {
    public function up(PDO $pdo) {
        echo "🔧 Creating location_qr_codes table...\n";
        
        $pdo->exec("CREATE TABLE location_qr_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            location_id INT NOT NULL,
            level_number INT NOT NULL,
            qr_code VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            UNIQUE KEY unique_location_level (location_id, level_number),
            INDEX idx_qr_code (qr_code),
            INDEX idx_location_id (location_id),
            
            FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Create QR codes directory
        $qrDir = 'storage/qr_codes/levels/';
        if (!is_dir($qrDir)) {
            mkdir($qrDir, 0755, true);
            echo "📁 Created QR codes directory: $qrDir\n";
        }
        
        echo "✅ location_qr_codes table created!\n";
    }
    
    public function down(PDO $pdo) {
        echo "🗑️ Dropping location_qr_codes table...\n";
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("DROP TABLE IF EXISTS location_qr_codes");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        echo "✅ location_qr_codes table dropped!\n";
    }
}

return new CreateLocationQrCodesTableMigration();