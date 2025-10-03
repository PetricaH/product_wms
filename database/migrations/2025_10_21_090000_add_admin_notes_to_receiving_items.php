<?php
/**
 * Migration: add_admin_notes_to_receiving_items
 * Adds admin notes tracking columns to receiving_items table.
 */

class AddAdminNotesToReceivingItems
{
    public function up(PDO $pdo): void
    {
        $pdo->exec("
            ALTER TABLE receiving_items
            ADD COLUMN admin_notes TEXT NULL AFTER notes,
            ADD COLUMN admin_notes_updated_by INT NULL AFTER admin_notes,
            ADD COLUMN admin_notes_updated_at TIMESTAMP NULL AFTER admin_notes_updated_by,
            ADD INDEX idx_admin_notes_updated_by (admin_notes_updated_by),
            ADD CONSTRAINT fk_receiving_items_admin_notes_user
                FOREIGN KEY (admin_notes_updated_by) REFERENCES users(id)
                ON DELETE SET NULL
        ");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE receiving_items DROP FOREIGN KEY fk_receiving_items_admin_notes_user");
        $pdo->exec("
            ALTER TABLE receiving_items
            DROP COLUMN admin_notes_updated_at,
            DROP COLUMN admin_notes_updated_by,
            DROP COLUMN admin_notes
        ");
    }
}

return new AddAdminNotesToReceivingItems();
