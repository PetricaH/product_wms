<?php

/**
 * Migration: Add approval status to receiving_items and new types to locations
 *
 * This migration adds the 'approval_status' column to the 'receiving_items' table
 * and expands the ENUM options for the 'type' column in the 'locations' table.
 */
class AddApprovalStatusAndNewLocationTypes
{
    /**
     * Apply the migration.
     *
     * @param PDO $pdo The database connection.
     * @return void
     */
    public function up(PDO $pdo): void
    {
        // Add new location types for quality control and quarantine processes.
        $stmt1 = "
            ALTER TABLE locations
            MODIFY COLUMN type ENUM(
                'warehouse', 'zone', 'rack', 'shelf', 'bin',
                'qc_hold', 'quarantine', 'pending_approval'
            ) DEFAULT 'bin'
        ";
        $pdo->exec($stmt1);

        // Add an approval status to track the state of received items.
        $stmt2 = "
            ALTER TABLE receiving_items
            ADD COLUMN approval_status ENUM('approved', 'pending', 'rejected') DEFAULT 'approved'
        ";
        $pdo->exec($stmt2);
    }

    /**
     * Revert the migration.
     *
     * @param PDO $pdo The database connection.
     * @return void
     */
    public function down(PDO $pdo): void
    {
        // Revert the receiving_items table by dropping the approval_status column.
        // Note: Data in the dropped column will be lost.
        $stmt1 = "
            ALTER TABLE receiving_items
            DROP COLUMN approval_status
        ";
        $pdo->exec($stmt1);

        // Revert the locations table by removing the added ENUM values.
        // WARNING: This may fail if any locations are currently using the new types.
        // It's recommended to update those records before rolling back.
        $stmt2 = "
            ALTER TABLE locations
            MODIFY COLUMN type ENUM(
                'warehouse', 'zone', 'rack', 'shelf', 'bin'
            ) DEFAULT 'bin'
        ";
        $pdo->exec($stmt2);
    }
}

return new AddApprovalStatusAndNewLocationTypes();