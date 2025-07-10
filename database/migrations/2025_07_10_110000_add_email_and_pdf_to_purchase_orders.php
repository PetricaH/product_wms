<?php
/**
 * Migration: add_email_and_pdf_to_purchase_orders
 * Created: 2025-07-10 11:00:00
 * Purpose: Add email subject and pdf path fields to purchase_orders table
 */

class AddEmailAndPdfToPurchaseOrdersMigration {
    public function up(PDO $pdo) {
        echo "\u{1F3D7}\uFE0F  Adding email_subject and pdf_path columns to purchase_orders...\n";
        $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN email_subject VARCHAR(255) AFTER email_recipient, ADD COLUMN pdf_path VARCHAR(255) AFTER notes");
    }

    public function down(PDO $pdo) {
        echo "\u{1F5D1}\uFE0F  Removing email_subject and pdf_path columns from purchase_orders...\n";
        $pdo->exec("ALTER TABLE purchase_orders DROP COLUMN email_subject, DROP COLUMN pdf_path");
    }
}

return new AddEmailAndPdfToPurchaseOrdersMigration();
