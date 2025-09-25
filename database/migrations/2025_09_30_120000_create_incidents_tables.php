<?php
/**
 * Migration: create_incidents_tables
 * Created: 2025-09-30
 * Purpose: Incident reporting system tables
 */

class CreateIncidentsTablesMigration {
    public function up(PDO $pdo) {
        echo "🚨 Creating incident reporting tables...\n";

        $pdo->exec("CREATE TABLE IF NOT EXISTS incidents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            incident_number VARCHAR(20) UNIQUE NOT NULL,
            reporter_id INT NOT NULL,
            incident_type ENUM('product_loss','equipment_loss','equipment_damage','safety_issue','quality_issue','process_violation','other') NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            location_id INT NULL,
            location_description VARCHAR(255) NULL,
            severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
            status ENUM('reported','under_review','investigating','resolved','rejected') NOT NULL DEFAULT 'reported',
            assigned_admin_id INT NULL,
            admin_notes TEXT NULL,
            resolution_notes TEXT NULL,
            occurred_at DATETIME NOT NULL,
            reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            reviewed_at TIMESTAMP NULL,
            resolved_at TIMESTAMP NULL,
            estimated_cost DECIMAL(10,2) NULL,
            follow_up_required BOOLEAN NOT NULL DEFAULT FALSE,
            INDEX idx_incident_status (status),
            INDEX idx_incident_severity (severity),
            INDEX idx_incident_type (incident_type),
            INDEX idx_reporter (reporter_id),
            INDEX idx_assigned_admin (assigned_admin_id),
            FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
            FOREIGN KEY (assigned_admin_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS incident_photos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            incident_id INT NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            file_size INT NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_incident_photo_incident (incident_id),
            FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        echo "✅ Incident tables created successfully.\n";
    }

    public function down(PDO $pdo) {
        echo "🗑️ Dropping incident reporting tables...\n";
        $pdo->exec("DROP TABLE IF EXISTS incident_photos");
        $pdo->exec("DROP TABLE IF EXISTS incidents");
        echo "✅ Incident tables dropped.\n";
    }
}

return new CreateIncidentsTablesMigration();
