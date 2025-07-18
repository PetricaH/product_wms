<?php
/**
 * Migration: Add QC decision tracking fields
 * File: database/migrations/2025_07_18_081500_add_qc_decision_tracking.php
 */

class AddQcDecisionTracking
{
    public function up(PDO $pdo): void
    {
        // Add decision tracking fields to receiving_items
        $stmt1 = "
            ALTER TABLE receiving_items 
            ADD COLUMN approved_by INT NULL,
            ADD COLUMN approved_at TIMESTAMP NULL,
            ADD COLUMN rejection_reason TEXT NULL,
            ADD COLUMN supervisor_notes TEXT NULL,
            ADD INDEX idx_approval_status (approval_status),
            ADD INDEX idx_approved_by (approved_by),
            ADD FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
        ";
        $pdo->exec($stmt1);

        // Create QC decision log table for audit trail
        $stmt2 = "
            CREATE TABLE qc_decisions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                receiving_item_id INT NOT NULL,
                decision ENUM('approved', 'rejected', 'returned_to_pending') NOT NULL,
                decided_by INT NOT NULL,
                decision_reason TEXT,
                supervisor_notes TEXT,
                previous_status ENUM('approved', 'pending', 'rejected'),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_receiving_item (receiving_item_id),
                INDEX idx_decided_by (decided_by),
                INDEX idx_decision (decision),
                INDEX idx_created_at (created_at),
                FOREIGN KEY (receiving_item_id) REFERENCES receiving_items(id) ON DELETE CASCADE,
                FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        $pdo->exec($stmt2);

        // Insert default QC locations if they don't exist
        $stmt3 = "
            INSERT IGNORE INTO locations (location_code, zone, type, capacity, status, notes) VALUES
            ('QC-HOLD', 'QC', 'qc_hold', 1000, 'active', 'Default QC Hold location for items pending approval'),
            ('QUARANTINE', 'QC', 'quarantine', 500, 'active', 'Default Quarantine location for damaged/defective items'),
            ('PENDING-APPROVAL', 'QC', 'pending_approval', 500, 'active', 'Default location for items awaiting supervisor approval')
        ";
        $pdo->exec($stmt3);

        echo "✅ QC decision tracking and default locations created successfully!\n";
    }

    public function down(PDO $pdo): void
    {
        echo "🗑️ Dropping QC decision tracking...\n";
        
        // Drop foreign key constraints first
        $pdo->exec("ALTER TABLE receiving_items DROP FOREIGN KEY receiving_items_ibfk_approved_by");
        
        // Remove added columns
        $pdo->exec("
            ALTER TABLE receiving_items 
            DROP COLUMN approved_by,
            DROP COLUMN approved_at,
            DROP COLUMN rejection_reason,
            DROP COLUMN supervisor_notes
        ");
        
        // Drop QC decisions table
        $pdo->exec("DROP TABLE IF EXISTS qc_decisions");
        
        // Remove default QC locations
        $pdo->exec("DELETE FROM locations WHERE location_code IN ('QC-HOLD', 'QUARANTINE', 'PENDING-APPROVAL')");
        
        echo "✅ QC decision tracking removed!\n";
    }
}

return new AddQcDecisionTracking();