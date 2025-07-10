<?php
/**
 * Migration: add_smtp_fields_to_users
 * Purpose: Add SMTP credential columns to users table
 */

class AddSmtpFieldsToUsersMigration {
    public function up(PDO $pdo) {
        echo "\u{1F3D7}\uFE0F  Adding SMTP columns to users table...\n";
        $pdo->exec("ALTER TABLE users
            ADD COLUMN smtp_host VARCHAR(255) NULL AFTER phone,
            ADD COLUMN smtp_port INT NULL AFTER smtp_host,
            ADD COLUMN smtp_user VARCHAR(255) NULL AFTER smtp_port,
            ADD COLUMN smtp_pass VARCHAR(255) NULL AFTER smtp_user,
            ADD COLUMN smtp_secure VARCHAR(10) NULL AFTER smtp_pass");
    }

    public function down(PDO $pdo) {
        echo "\u{1F5D1}\uFE0F  Removing SMTP columns from users table...\n";
        $pdo->exec("ALTER TABLE users
            DROP COLUMN smtp_host,
            DROP COLUMN smtp_port,
            DROP COLUMN smtp_user,
            DROP COLUMN smtp_pass,
            DROP COLUMN smtp_secure");
    }
}

return new AddSmtpFieldsToUsersMigration();
