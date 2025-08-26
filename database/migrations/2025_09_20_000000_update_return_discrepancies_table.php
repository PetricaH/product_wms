<?php
/**
 * Migration: update_return_discrepancies_table
 * Purpose: expand discrepancy types and add resolution tracking
 */

class UpdateReturnDiscrepanciesTableMigration {
    public function up(PDO $pdo) {
        // Rename existing discrepancy_type values to new schema
        $pdo->exec("UPDATE return_discrepancies SET discrepancy_type='missing_item' WHERE discrepancy_type='missing'");
        $pdo->exec("UPDATE return_discrepancies SET discrepancy_type='extra_item' WHERE discrepancy_type='extra'");
        $pdo->exec("UPDATE return_discrepancies SET discrepancy_type='condition_issue' WHERE discrepancy_type='damaged'");

        // Expand discrepancy types
        $pdo->exec("ALTER TABLE return_discrepancies MODIFY discrepancy_type ENUM('missing_item','quantity_short','quantity_over','condition_issue','extra_item') NOT NULL");

        // Add resolution tracking
        $pdo->exec("ALTER TABLE return_discrepancies ADD COLUMN resolution_status ENUM('pending','resolved','written_off') NOT NULL DEFAULT 'pending' AFTER notes");

        // Prevent duplicate discrepancies for same product/type
        $pdo->exec("ALTER TABLE return_discrepancies ADD UNIQUE KEY uniq_return_discrepancy (return_id, product_id, discrepancy_type)");
    }

    public function down(PDO $pdo) {
        $pdo->exec("ALTER TABLE return_discrepancies DROP INDEX uniq_return_discrepancy");
        $pdo->exec("ALTER TABLE return_discrepancies DROP COLUMN resolution_status");
        $pdo->exec("UPDATE return_discrepancies SET discrepancy_type='missing' WHERE discrepancy_type='missing_item'");
        $pdo->exec("UPDATE return_discrepancies SET discrepancy_type='extra' WHERE discrepancy_type='extra_item'");
        $pdo->exec("UPDATE return_discrepancies SET discrepancy_type='damaged' WHERE discrepancy_type='condition_issue'");
        $pdo->exec("ALTER TABLE return_discrepancies MODIFY discrepancy_type ENUM('missing','extra','damaged') NOT NULL");
    }
}

return new UpdateReturnDiscrepanciesTableMigration();
