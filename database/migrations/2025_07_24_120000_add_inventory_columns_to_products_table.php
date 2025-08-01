<?php
class AddInventoryColumnsToProductsTableMigration {
    public function up(PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE products
            ADD COLUMN min_order_quantity INT DEFAULT 1,
            ADD COLUMN auto_order_enabled TINYINT(1) DEFAULT 0,
            ADD COLUMN last_auto_order_date DATETIME NULL,
            ADD INDEX idx_products_auto_order (auto_order_enabled),
            ADD INDEX idx_products_min_stock_level (min_stock_level)");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE products
            DROP COLUMN last_auto_order_date,
            DROP COLUMN auto_order_enabled,
            DROP COLUMN min_order_quantity,
            DROP COLUMN min_stock_level");
    }
}
return new AddInventoryColumnsToProductsTableMigration();
