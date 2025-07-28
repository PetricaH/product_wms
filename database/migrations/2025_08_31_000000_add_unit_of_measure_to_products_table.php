<?php
class AddUnitOfMeasureToProductsTableMigration {
    public function up(PDO $pdo) {
        $pdo->exec("ALTER TABLE products
            ADD COLUMN unit_of_measure VARCHAR(20) DEFAULT 'pcs',
            ADD INDEX idx_products_unit_of_measure (unit_of_measure)");
    }
    public function down(PDO $pdo) {
        $pdo->exec("ALTER TABLE products DROP COLUMN unit_of_measure");
    }
}
return new AddUnitOfMeasureToProductsTableMigration();
