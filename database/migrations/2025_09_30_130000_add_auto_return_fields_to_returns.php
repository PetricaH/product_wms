<?php
/**
 * Migration: add_auto_return_fields_to_returns
 * Purpose: Adds metadata required for automated return processing.
 */

class AddAutoReturnFieldsToReturnsMigration {
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        $pdo->exec("
            ALTER TABLE returns
                ADD COLUMN return_awb VARCHAR(50) NULL AFTER status,
                ADD COLUMN auto_created TINYINT(1) NOT NULL DEFAULT 0 AFTER return_awb,
                ADD COLUMN return_date TIMESTAMP NULL AFTER auto_created
        ");

        // Ensure sensible defaults for historic data
        $pdo->exec("UPDATE returns SET auto_created = 0 WHERE auto_created IS NULL");
    }

    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        $pdo->exec("
            ALTER TABLE returns
                DROP COLUMN return_date,
                DROP COLUMN auto_created,
                DROP COLUMN return_awb
        ");
    }
}

return new AddAutoReturnFieldsToReturnsMigration();
