<?php
/**
 * Migration: add_opened_condition_to_returns
 * Purpose: Allow 'opened' as an item condition for return items and discrepancies
 */

class AddOpenedConditionToReturnsMigration {
    public function up(PDO $pdo) {
        $pdo->exec("ALTER TABLE return_items MODIFY item_condition ENUM('good','damaged','defective','opened') NOT NULL DEFAULT 'good'");
        $pdo->exec("ALTER TABLE return_discrepancies MODIFY item_condition ENUM('good','damaged','defective','opened') DEFAULT 'good'");
    }

    public function down(PDO $pdo) {
        $pdo->exec("ALTER TABLE return_items MODIFY item_condition ENUM('good','damaged','defective') NOT NULL DEFAULT 'good'");
        $pdo->exec("ALTER TABLE return_discrepancies MODIFY item_condition ENUM('good','damaged','defective') DEFAULT 'good'");
    }
}

return new AddOpenedConditionToReturnsMigration();
