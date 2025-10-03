<?php

class AddInvoiceVerificationColumnsToPurchaseOrders
{
    private function getExistingColumns(PDO $pdo): array
    {
        $columns = [];
        $stmt = $pdo->query("DESCRIBE purchase_orders");
        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $columns[$column['Field']] = true;
            }
        }
        return $columns;
    }

    public function up(PDO $pdo): void
    {
        echo "\n⬆️  Updating purchase_orders invoice columns...\n";
        $columns = $this->getExistingColumns($pdo);

        if (!isset($columns['invoice_file_path'])) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN invoice_file_path VARCHAR(500) NULL AFTER invoiced");
            echo "  • Added invoice_file_path column\n";
        }

        if (!isset($columns['invoice_verified'])) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN invoice_verified TINYINT(1) DEFAULT 0 AFTER invoice_file_path");
            echo "  • Added invoice_verified column\n";
        }

        if (!isset($columns['invoice_verified_by'])) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN invoice_verified_by INT NULL AFTER invoice_verified,
                ADD CONSTRAINT fk_purchase_orders_invoice_verified_by FOREIGN KEY (invoice_verified_by) REFERENCES users(id) ON DELETE SET NULL");
            echo "  • Added invoice_verified_by column\n";
        }

        if (!isset($columns['invoice_verified_at'])) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN invoice_verified_at DATETIME NULL AFTER invoice_verified_by");
            echo "  • Added invoice_verified_at column\n";
        }

        echo "✅ Invoice columns updated.\n";
    }

    public function down(PDO $pdo): void
    {
        echo "\n⬇️  Reverting purchase_orders invoice columns...\n";
        $columns = $this->getExistingColumns($pdo);

        if (isset($columns['invoice_verified_at'])) {
            $pdo->exec("ALTER TABLE purchase_orders DROP COLUMN invoice_verified_at");
            echo "  • Dropped invoice_verified_at column\n";
        }

        if (isset($columns['invoice_verified_by'])) {
            try {
                $pdo->exec("ALTER TABLE purchase_orders DROP FOREIGN KEY fk_purchase_orders_invoice_verified_by");
            } catch (PDOException $exception) {
                // Constraint might not exist; ignore and continue.
            }
            $pdo->exec("ALTER TABLE purchase_orders DROP COLUMN invoice_verified_by");
            echo "  • Dropped invoice_verified_by column\n";
        }

        if (isset($columns['invoice_verified'])) {
            $pdo->exec("ALTER TABLE purchase_orders DROP COLUMN invoice_verified");
            echo "  • Dropped invoice_verified column\n";
        }

        if (isset($columns['invoice_file_path'])) {
            $pdo->exec("ALTER TABLE purchase_orders DROP COLUMN invoice_file_path");
            echo "  • Dropped invoice_file_path column\n";
        }

        echo "✅ Revert complete.\n";
    }
}

return new AddInvoiceVerificationColumnsToPurchaseOrders();
