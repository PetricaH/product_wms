<?php
/**
 * Migration: create_order_imports_table
 * Created: 2025-07-03 16:00:00
 * Purpose: Store external orders (email/API) before converting to WMS orders
 */

class CreateOrderImportsTableMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        $sql = "
            CREATE TABLE order_imports (
                id INT PRIMARY KEY AUTO_INCREMENT,
                
                -- Import source and metadata
                import_source ENUM('email', 'api', 'manual', 'webhook') DEFAULT 'email',
                source_reference VARCHAR(255) NULL COMMENT 'Email ID, API call ID, etc.',
                source_metadata JSON NULL COMMENT 'Email headers, API payload info, etc.',
                raw_content TEXT NULL COMMENT 'Original email content or raw data',
                
                -- Parsed JSON data
                json_data JSON NOT NULL,
                
                -- Client information
                contact_person_name VARCHAR(255) NOT NULL,
                company_name VARCHAR(255) NULL,
                contact_email VARCHAR(255) NULL,
                contact_phone VARCHAR(50) NULL,
                industry VARCHAR(100) NULL,
                seller_name VARCHAR(100) NULL,
                
                -- Delivery address
                delivery_county VARCHAR(100) NULL,
                delivery_locality VARCHAR(100) NULL,
                delivery_street TEXT NULL,
                delivery_postal_code VARCHAR(20) NULL,
                
                -- Invoice information
                invoice_number VARCHAR(100) NOT NULL,
                total_value DECIMAL(10,2) NOT NULL,
                client_cui VARCHAR(50) NULL,
                payment_method VARCHAR(50) NULL,
                
                -- Processing status
                processing_status ENUM('pending', 'processing', 'converted', 'failed', 'skipped') DEFAULT 'pending',
                wms_order_id INT NULL,
                wms_order_number VARCHAR(100) NULL,
                
                -- Error handling
                conversion_errors TEXT NULL,
                conversion_attempts INT DEFAULT 0,
                last_attempt_at TIMESTAMP NULL,
                
                -- Timestamps
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                -- Indexes
                INDEX idx_import_source (import_source),
                INDEX idx_processing_status (processing_status),
                INDEX idx_invoice_number (invoice_number),
                INDEX idx_wms_order_id (wms_order_id),
                INDEX idx_created_at (created_at),
                
                -- Foreign key to orders table
                FOREIGN KEY (wms_order_id) REFERENCES orders(id) ON DELETE SET NULL
            )
        ";
        
        $pdo->exec($sql);
        
        // Create products mapping table for external product names to WMS SKUs
        $sql2 = "
            CREATE TABLE import_product_mappings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                
                -- External product identification
                external_product_name VARCHAR(255) NOT NULL,
                external_product_name_normalized VARCHAR(255) NOT NULL,
                
                -- WMS product mapping
                wms_product_id INT NULL,
                wms_sku VARCHAR(255) NULL,
                
                -- Product details
                estimated_weight DECIMAL(8,3) DEFAULT 1.000,
                estimated_price DECIMAL(10,2) DEFAULT 0.00,
                product_category VARCHAR(100) DEFAULT 'Email Import',
                
                -- Mapping confidence and status
                mapping_confidence ENUM('high', 'medium', 'low', 'manual') DEFAULT 'low',
                is_active BOOLEAN DEFAULT TRUE,
                
                -- Usage statistics
                usage_count INT DEFAULT 0,
                last_used_at TIMESTAMP NULL,
                
                -- Timestamps
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                -- Indexes
                UNIQUE KEY unique_external_product (external_product_name_normalized),
                INDEX idx_wms_product_id (wms_product_id),
                INDEX idx_mapping_confidence (mapping_confidence),
                
                -- Foreign key to products table
                FOREIGN KEY (wms_product_id) REFERENCES products(product_id) ON DELETE SET NULL
            )
        ";
        
        $pdo->exec($sql2);
        
        // Create location mappings for Romanian addresses to Cargus IDs
        $sql3 = "
            CREATE TABLE address_location_mappings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                
                -- Romanian address components
                county_name VARCHAR(100) NOT NULL,
                locality_name VARCHAR(100) NOT NULL,
                
                -- Cargus location IDs
                cargus_county_id INT NULL,
                cargus_locality_id INT NULL,
                cargus_county_name VARCHAR(100) NULL,
                cargus_locality_name VARCHAR(100) NULL,
                
                -- Mapping quality
                mapping_confidence ENUM('high', 'medium', 'low', 'manual') DEFAULT 'low',
                is_verified BOOLEAN DEFAULT FALSE,
                
                -- Usage statistics
                usage_count INT DEFAULT 0,
                last_used_at TIMESTAMP NULL,
                
                -- Timestamps
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                -- Indexes
                UNIQUE KEY unique_location (county_name, locality_name),
                INDEX idx_cargus_ids (cargus_county_id, cargus_locality_id),
                INDEX idx_mapping_confidence (mapping_confidence)
            )
        ";
        
        $pdo->exec($sql3);
        
        // Insert some common location mappings
        $pdo->exec("
            INSERT INTO address_location_mappings 
            (county_name, locality_name, cargus_county_id, cargus_locality_id, cargus_county_name, cargus_locality_name, mapping_confidence, is_verified) 
            VALUES 
            ('Bucuresti', 'Bucuresti', 1, 150, 'Bucuresti', 'BUCURESTI', 'high', TRUE),
            ('Ilfov', 'Voluntari', 27, 1793631, 'Ilfov', 'Voluntari', 'high', TRUE),
            ('Cluj', 'Cluj-Napoca', 12, 12345, 'Cluj', 'Cluj-Napoca', 'medium', FALSE),
            ('Neamț', 'Vaduri', NULL, NULL, NULL, NULL, 'low', FALSE)
        ");
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS address_location_mappings");
        $pdo->exec("DROP TABLE IF EXISTS import_product_mappings");
        $pdo->exec("DROP TABLE IF EXISTS order_imports");
    }
}

// Return instance for migration runner
return new CreateOrderImportsTableMigration();