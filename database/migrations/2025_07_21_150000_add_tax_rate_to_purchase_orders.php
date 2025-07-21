<?php
class AddTaxRateToPurchaseOrdersMigration {
    public function up(PDO $pdo) {
        echo "\u{1F4B0} Adding tax_rate column to purchase_orders...\n";
        $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN tax_rate DECIMAL(5,2) DEFAULT 19 AFTER pdf_path");
    }
    public function down(PDO $pdo) {
        echo "\u{1F5D1}\uFE0F  Removing tax_rate column from purchase_orders...\n";
        $pdo->exec("ALTER TABLE purchase_orders DROP COLUMN tax_rate");
    }
}
return new AddTaxRateToPurchaseOrdersMigration();
