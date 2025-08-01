<?php
/**
 * Migration: add_awb_fields_to_orders
 * Created: 2025-07-03 12:00:00
 * Purpose: Add AWB shipping fields for Cargus integration
 */

class AddAwbFieldsToOrdersMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        $sql = "
            ALTER TABLE orders ADD COLUMN (
                -- Recipient location data for Cargus AWB
                recipient_county_id INT NULL COMMENT 'Cargus County ID',
                recipient_county_name VARCHAR(100) NULL COMMENT 'County name',
                recipient_locality_id INT NULL COMMENT 'Cargus Locality ID', 
                recipient_locality_name VARCHAR(100) NULL COMMENT 'Locality name',
                recipient_street_id INT NULL COMMENT 'Cargus Street ID',
                recipient_street_name VARCHAR(255) NULL COMMENT 'Street name',
                recipient_building_number VARCHAR(50) NULL COMMENT 'Building number',
                
                -- Contact information
                recipient_contact_person VARCHAR(255) NULL COMMENT 'Contact person name',
                recipient_phone VARCHAR(50) NULL COMMENT 'Contact phone number',
                recipient_email VARCHAR(255) NULL COMMENT 'Contact email',
                
                -- Shipping details for AWB
                total_weight DECIMAL(10,3) NULL DEFAULT 0.000 COMMENT 'Total weight in kg',
                declared_value DECIMAL(10,2) NULL DEFAULT 0.00 COMMENT 'Declared value for insurance',
                parcels_count INT NULL DEFAULT 1 COMMENT 'Number of parcels',
                envelopes_count INT NULL DEFAULT 0 COMMENT 'Number of envelopes (max 9)',
                
                -- AWB specific fields
                cash_repayment DECIMAL(10,2) NULL DEFAULT 0.00 COMMENT 'Cash on delivery amount',
                bank_repayment DECIMAL(10,2) NULL DEFAULT 0.00 COMMENT 'Bank repayment amount',
                saturday_delivery BOOLEAN DEFAULT FALSE COMMENT 'Saturday delivery requested',
                morning_delivery BOOLEAN DEFAULT FALSE COMMENT 'Morning delivery requested',
                open_package BOOLEAN DEFAULT FALSE COMMENT 'Open package allowed',
                observations TEXT NULL COMMENT 'Special shipping instructions',
                package_content VARCHAR(255) NULL COMMENT 'Package content description',
                
                -- AWB tracking
                awb_barcode VARCHAR(50) NULL COMMENT 'Generated AWB barcode',
                awb_created_at TIMESTAMP NULL COMMENT 'AWB creation timestamp',
                cargus_order_id VARCHAR(50) NULL COMMENT 'Cargus internal order ID',
                
                -- Additional references
                sender_reference1 VARCHAR(100) NULL COMMENT 'Sender reference 1',
                recipient_reference1 VARCHAR(100) NULL COMMENT 'Recipient reference 1',
                recipient_reference2 VARCHAR(100) NULL COMMENT 'Recipient reference 2',
                invoice_reference VARCHAR(100) NULL COMMENT 'Invoice reference number',
                
                -- Pickup location for sender
                sender_location_id INT NULL COMMENT 'Cargus pickup location ID',
                
                -- Index for performance
                INDEX idx_awb_barcode (awb_barcode),
                INDEX idx_cargus_order_id (cargus_order_id),
                INDEX idx_recipient_county (recipient_county_id),
                INDEX idx_recipient_locality (recipient_locality_id)
            )
        ";
        
        $pdo->exec($sql);
        
        // Add some default data for existing orders if any
        $pdo->exec("
            UPDATE orders 
            SET 
                total_weight = 1.0,
                declared_value = 0.00,
                parcels_count = 1,
                envelopes_count = 0
            WHERE total_weight IS NULL
        ");
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        $sql = "
            ALTER TABLE orders DROP COLUMN recipient_county_id,
                                DROP COLUMN recipient_county_name,
                                DROP COLUMN recipient_locality_id,
                                DROP COLUMN recipient_locality_name,
                                DROP COLUMN recipient_street_id,
                                DROP COLUMN recipient_street_name,
                                DROP COLUMN recipient_building_number,
                                DROP COLUMN recipient_contact_person,
                                DROP COLUMN recipient_phone,
                                DROP COLUMN recipient_email,
                                DROP COLUMN total_weight,
                                DROP COLUMN declared_value,
                                DROP COLUMN parcels_count,
                                DROP COLUMN envelopes_count,
                                DROP COLUMN cash_repayment,
                                DROP COLUMN bank_repayment,
                                DROP COLUMN saturday_delivery,
                                DROP COLUMN morning_delivery,
                                DROP COLUMN open_package,
                                DROP COLUMN observations,
                                DROP COLUMN package_content,
                                DROP COLUMN awb_barcode,
                                DROP COLUMN awb_created_at,
                                DROP COLUMN cargus_order_id,
                                DROP COLUMN sender_reference1,
                                DROP COLUMN recipient_reference1,
                                DROP COLUMN recipient_reference2,
                                DROP COLUMN invoice_reference,
                                DROP COLUMN sender_location_id,
                                DROP INDEX idx_awb_barcode,
                                DROP INDEX idx_cargus_order_id,
                                DROP INDEX idx_recipient_county,
                                DROP INDEX idx_recipient_locality
        ";
        
        $pdo->exec($sql);
    }
}

// Return instance for migration runner
return new AddAwbFieldsToOrdersMigration();