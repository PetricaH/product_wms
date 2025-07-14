<?php
/**
 * Migration: create_complete_wms_schema (FIXED)
 * Creates the complete WMS database schema from scratch
 * Fixed SQL syntax errors in views
 */

class CreateCompleteWmsSchemaMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        echo "🏗️  Creating complete WMS schema...\n\n";
        
        try {
            // 1. Users table
            echo "👥 Creating users table...\n";
            $pdo->exec("
                CREATE TABLE users (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    username VARCHAR(50) UNIQUE NOT NULL,
                    email VARCHAR(255) UNIQUE NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    role ENUM('admin', 'manager', 'employee', 'user') DEFAULT 'user',
                    status TINYINT(1) DEFAULT 1,
                    first_name VARCHAR(100),
                    last_name VARCHAR(100),
                    phone VARCHAR(20),
                    smtp_host VARCHAR(255) DEFAULT NULL,
                    smtp_port INT DEFAULT NULL,
                    smtp_user VARCHAR(255) DEFAULT NULL,
                    smtp_pass VARCHAR(255) DEFAULT NULL,
                    smtp_secure VARCHAR(10) DEFAULT NULL,
                    last_login TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_username (username),
                    INDEX idx_email (email),
                    INDEX idx_role (role),
                    INDEX idx_status (status)
                )
            ");
            
            // 2. Products table
            echo "📦 Creating products table...\n";
            $pdo->exec("
                CREATE TABLE products (
                    product_id INT PRIMARY KEY AUTO_INCREMENT,
                    sku VARCHAR(100) UNIQUE NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    description TEXT,
                    category VARCHAR(100),
                    quantity INT DEFAULT 0,
                    min_stock_level INT DEFAULT 0,
                    price DECIMAL(10,2) DEFAULT 0.00,
                    weight DECIMAL(10,3) DEFAULT 0.000,
                    dimensions JSON,
                    barcode VARCHAR(100),
                    image_url VARCHAR(500),
                    status ENUM('active', 'inactive', 'discontinued') DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_sku (sku),
                    INDEX idx_name (name),
                    INDEX idx_category (category),
                    INDEX idx_status (status),
                    INDEX idx_barcode (barcode)
                )
            ");
            
            // 3. Locations table
            echo "📍 Creating locations table...\n";
            $pdo->exec("
                CREATE TABLE locations (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    location_code VARCHAR(50) UNIQUE NOT NULL,
                    zone VARCHAR(50) NOT NULL,
                    type ENUM('warehouse', 'zone', 'rack', 'shelf', 'bin') DEFAULT 'bin',
                    levels INT DEFAULT 3,
                    parent_location_id INT NULL,
                    capacity INT DEFAULT 0,
                    current_occupancy INT DEFAULT 0,
                    status ENUM('active', 'inactive', 'maintenance') DEFAULT 'active',
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_location_code (location_code),
                    INDEX idx_zone (zone),
                    INDEX idx_type (type),
                    INDEX idx_status (status),
                    INDEX idx_parent (parent_location_id)
                )
            ");
            
            // Add foreign key after table creation
            echo "🔗 Adding location foreign key...\n";
            $pdo->exec("
                ALTER TABLE locations 
                ADD CONSTRAINT fk_locations_parent 
                FOREIGN KEY (parent_location_id) REFERENCES locations(id) ON DELETE SET NULL
            ");
            
            // 4. Inventory table
            echo "📊 Creating inventory table...\n";
            $pdo->exec("
                CREATE TABLE inventory (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    product_id INT NOT NULL,
                    location_id INT NOT NULL,
                    quantity INT NOT NULL DEFAULT 0,
                    reserved_quantity INT NOT NULL DEFAULT 0,
                    batch_number VARCHAR(100),
                    lot_number VARCHAR(100),
                    expiry_date DATE NULL,
                    received_at TIMESTAMP NULL,
                    last_counted_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
                    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
                    UNIQUE KEY unique_product_location_batch (product_id, location_id, batch_number),
                    INDEX idx_product (product_id),
                    INDEX idx_location (location_id),
                    INDEX idx_quantity (quantity),
                    INDEX idx_expiry (expiry_date)
                )
            ");
            
            // 5. Orders table
            echo "🛒 Creating orders table...\n";
            $pdo->exec("
                CREATE TABLE orders (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    order_number VARCHAR(100) UNIQUE NOT NULL,
                    type ENUM('inbound', 'outbound', 'transfer', 'adjustment') NOT NULL,
                    status ENUM('pending', 'processing', 'completed', 'cancelled', 'shipped') DEFAULT 'pending',
                    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
                    customer_name VARCHAR(255),
                    customer_email VARCHAR(255),
                    shipping_address TEXT,
                    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    required_date TIMESTAMP NULL,
                    shipped_date TIMESTAMP NULL,
                    total_value DECIMAL(10,2) DEFAULT 0.00,
                    notes TEXT,
                    assigned_to INT NULL,
                    created_by INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
                    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
                    INDEX idx_order_number (order_number),
                    INDEX idx_status (status),
                    INDEX idx_type (type),
                    INDEX idx_customer (customer_name),
                    INDEX idx_order_date (order_date)
                )
            ");
            
            // 6. Order items table
            echo "📋 Creating order_items table...\n";
            $pdo->exec("
                CREATE TABLE order_items (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    order_id INT NOT NULL,
                    product_id INT NOT NULL,
                    quantity INT NOT NULL,
                    picked_quantity INT DEFAULT 0,
                    unit_price DECIMAL(10,2) DEFAULT 0.00,
                    total_price DECIMAL(10,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
                    INDEX idx_order (order_id),
                    INDEX idx_product (product_id)
                )
            ");
            
            // 7. Transactions table
            echo "💳 Creating transactions table...\n";
            $pdo->exec("
                CREATE TABLE transactions (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    transaction_number VARCHAR(100) UNIQUE NOT NULL,
                    type ENUM('in', 'out', 'transfer', 'adjustment', 'count') NOT NULL,
                    product_id INT NOT NULL,
                    from_location_id INT NULL,
                    to_location_id INT NULL,
                    quantity INT NOT NULL,
                    unit_cost DECIMAL(10,2) DEFAULT 0.00,
                    total_cost DECIMAL(10,2) GENERATED ALWAYS AS (ABS(quantity) * unit_cost) STORED,
                    reference_type ENUM('order', 'manual', 'system', 'adjustment') DEFAULT 'manual',
                    reference_id INT NULL,
                    batch_number VARCHAR(100),
                    lot_number VARCHAR(100),
                    notes TEXT,
                    performed_by INT NOT NULL,
                    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
                    FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE SET NULL,
                    FOREIGN KEY (to_location_id) REFERENCES locations(id) ON DELETE SET NULL,
                    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE RESTRICT,
                    INDEX idx_transaction_number (transaction_number),
                    INDEX idx_type (type),
                    INDEX idx_product (product_id),
                    INDEX idx_performed_at (performed_at),
                    INDEX idx_reference (reference_type, reference_id)
                )
            ");
            
            // 8. Settings table
            echo "⚙️  Creating settings table...\n";
            $pdo->exec("
                CREATE TABLE settings (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    setting_key VARCHAR(100) UNIQUE NOT NULL,
                    setting_value TEXT,
                    setting_type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
                    description TEXT,
                    is_public TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_setting_key (setting_key),
                    INDEX idx_is_public (is_public)
                )
            ");
            
            // 9. Activity logs table
            echo "📝 Creating activity_logs table...\n";
            $pdo->exec("
                CREATE TABLE activity_logs (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT NULL,
                    action VARCHAR(100) NOT NULL,
                    resource_type VARCHAR(50) NOT NULL,
                    resource_id INT NULL,
                    description TEXT,
                    old_values JSON NULL,
                    new_values JSON NULL,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                    INDEX idx_user (user_id),
                    INDEX idx_action (action),
                    INDEX idx_resource (resource_type, resource_id),
                    INDEX idx_created_at (created_at)
                )
            ");
            
            // 10-13. SmartBill tables
            echo "💼 Creating SmartBill integration tables...\n";
            
            $pdo->exec("
                CREATE TABLE smartbill_config (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    config_key VARCHAR(100) UNIQUE NOT NULL,
                    config_value TEXT,
                    is_encrypted TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_config_key (config_key)
                )
            ");
            
            $pdo->exec("
                CREATE TABLE smartbill_sync_schedule (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    sync_type VARCHAR(50) NOT NULL,
                    is_enabled TINYINT(1) DEFAULT 0,
                    interval_minutes INT DEFAULT 15,
                    max_items_per_run INT DEFAULT 50,
                    last_run TIMESTAMP NULL,
                    next_run TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_sync_type (sync_type),
                    INDEX idx_enabled (is_enabled),
                    INDEX idx_next_run (next_run)
                )
            ");
            
            $pdo->exec("
                CREATE TABLE smartbill_sync_log (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    sync_type VARCHAR(50) NOT NULL,
                    status ENUM('running', 'completed', 'failed') NOT NULL,
                    items_processed INT DEFAULT 0,
                    items_successful INT DEFAULT 0,
                    items_failed INT DEFAULT 0,
                    error_message TEXT NULL,
                    execution_time_seconds DECIMAL(10,3) DEFAULT 0.000,
                    memory_usage_mb DECIMAL(10,2) DEFAULT 0.00,
                    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    completed_at TIMESTAMP NULL,
                    details JSON NULL,
                    INDEX idx_sync_type (sync_type),
                    INDEX idx_status (status),
                    INDEX idx_started_at (started_at)
                )
            ");
            
            $pdo->exec("
                CREATE TABLE smartbill_invoices (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    local_order_id INT NULL,
                    smartbill_invoice_id VARCHAR(100) NOT NULL,
                    invoice_number VARCHAR(100) NOT NULL,
                    invoice_series VARCHAR(20) NOT NULL,
                    invoice_date DATE NOT NULL,
                    total_amount DECIMAL(10,2) NOT NULL,
                    currency VARCHAR(3) DEFAULT 'RON',
                    status ENUM('draft', 'issued', 'paid', 'cancelled') DEFAULT 'issued',
                    sync_status ENUM('synced', 'pending', 'error') DEFAULT 'synced',
                    last_synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (local_order_id) REFERENCES orders(id) ON DELETE SET NULL,
                    UNIQUE KEY unique_smartbill_invoice (smartbill_invoice_id),
                    INDEX idx_invoice_number (invoice_number),
                    INDEX idx_sync_status (sync_status),
                    INDEX idx_local_order (local_order_id)
                )
            ");
            
            // Create views (fixed SQL syntax)
            echo "👁️  Creating database views...\n";
            
            // View 1: Products with stock
            echo "   📊 Creating view_products_with_stock...\n";
            $pdo->exec("
                CREATE OR REPLACE VIEW view_products_with_stock AS
                SELECT 
                    p.product_id,
                    p.sku,
                    p.name,
                    p.category,
                    p.price,
                    p.min_stock_level,
                    COALESCE(SUM(i.quantity), 0) as current_stock,
                    COALESCE(SUM(i.reserved_quantity), 0) as reserved_stock,
                    COALESCE(SUM(i.quantity - i.reserved_quantity), 0) as available_stock,
                    CASE 
                        WHEN COALESCE(SUM(i.quantity), 0) <= p.min_stock_level THEN 'low'
                        WHEN COALESCE(SUM(i.quantity), 0) = 0 THEN 'out'
                        ELSE 'ok'
                    END as stock_status,
                    p.status as product_status,
                    p.created_at,
                    p.updated_at
                FROM products p
                LEFT JOIN inventory i ON p.product_id = i.product_id
                WHERE p.status = 'active'
                GROUP BY p.product_id, p.sku, p.name, p.category, p.price, p.min_stock_level, p.status, p.created_at, p.updated_at
            ");
            
            // View 2: SmartBill pending invoices
            echo "   📋 Creating view_smartbill_pending_invoices...\n";
            $pdo->exec("
                CREATE OR REPLACE VIEW view_smartbill_pending_invoices AS
                SELECT 
                    o.id as order_id,
                    o.order_number,
                    o.created_at,
                    o.total_value,
                    o.customer_name,
                    o.customer_email,
                    CASE 
                        WHEN si.id IS NULL THEN 'not_synced'
                        WHEN si.sync_status = 'error' THEN 'error'
                        ELSE 'synced'
                    END as sync_status
                FROM orders o
                LEFT JOIN smartbill_invoices si ON o.id = si.local_order_id
                WHERE o.status = 'completed'
                AND (si.id IS NULL OR si.sync_status = 'error')
            ");
            
            // View 3: SmartBill sync metrics (FIXED - changed alias from 'ssl' to 'logs')
            echo "   📈 Creating view_smartbill_sync_metrics...\n";
            $pdo->exec("
                CREATE OR REPLACE VIEW view_smartbill_sync_metrics AS
                SELECT 
                    logs.sync_type,
                    COUNT(*) as total_runs,
                    SUM(logs.items_processed) as total_items_processed,
                    SUM(logs.items_successful) as total_items_successful,
                    SUM(logs.items_failed) as total_items_failed,
                    AVG(logs.execution_time_seconds) as avg_execution_time,
                    MAX(logs.started_at) as last_run,
                    SUM(CASE WHEN logs.status = 'failed' THEN 1 ELSE 0 END) as failed_runs
                FROM smartbill_sync_log logs
                WHERE logs.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY logs.sync_type
            ");
            
            // Insert default data
            echo "📊 Inserting default data...\n";
            
            // Default admin user (password: admin123)
            $pdo->exec("
                INSERT INTO users (username, email, password, role, first_name, last_name) VALUES 
                ('admin', 'admin@wms.local', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'System', 'Administrator')
            ");
            
            // Default locations
            $pdo->exec("
                INSERT INTO locations (location_code, zone, type, levels, capacity) VALUES
                ('WH-01', 'Main Warehouse', 'warehouse', 3, 10000),
                ('WH-01-A', 'Zone A', 'zone', 3, 2500),
                ('WH-01-A-R01', 'Rack A01', 'rack', 3, 500),
                ('WH-01-A-R01-S01', 'Shelf A01-S01', 'shelf', 3, 50),
                ('WH-01-A-R01-S01-B01', 'Bin A01-S01-B01', 'bin', 1, 10)
            ");
            
            // Default settings
            $pdo->exec("
                INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES 
                ('company_name', 'WMS Company', 'string', 'Company name for reports'),
                ('default_currency', 'RON', 'string', 'Default currency code'),
                ('low_stock_threshold', '10', 'integer', 'Global low stock threshold'),
                ('enable_barcode_scanning', '1', 'boolean', 'Enable barcode scanning features'),
                ('warehouse_timezone', 'Europe/Bucharest', 'string', 'Warehouse timezone'),
                ('order_number_prefix', 'ORD-', 'string', 'Order number prefix'),
                ('transaction_number_prefix', 'TXN-', 'string', 'Transaction number prefix')
            ");
            
            // SmartBill default schedules
            $pdo->exec("
                INSERT INTO smartbill_sync_schedule (sync_type, is_enabled, interval_minutes, max_items_per_run) VALUES
                ('invoice_pull', 0, 15, 10),
                ('order_push', 0, 30, 5),
                ('inventory_update', 0, 60, 25),
                ('product_sync', 0, 120, 10)
            ");
            
            // SmartBill default config
            $pdo->exec("
                INSERT INTO smartbill_config (config_key, config_value) VALUES
                ('api_username', ''),
                ('api_token', ''),
                ('company_vat_code', ''),
                ('default_series', 'FACT'),
                ('warehouse_code', 'PRINCIPAL'),
                ('default_currency', 'RON'),
                ('default_tax_rate', '19'),
                ('debug_mode', '0')
            ");
            
            echo "\n🎉 Complete WMS schema created successfully!\n";
            echo "📊 Created:\n";
            echo "   - 13 tables with all foreign keys\n";
            echo "   - 3 views\n";
            echo "   - Default data and settings\n";
            echo "   - Admin user (username: admin, password: admin123)\n\n";
            
        } catch (PDOException $e) {
            echo "❌ Database error: " . $e->getMessage() . "\n";
            throw $e;
        } catch (Exception $e) {
            echo "❌ General error: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO $pdo) {
        echo "🗑️  Rolling back complete WMS schema...\n";
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        // Drop views
        $pdo->exec("DROP VIEW IF EXISTS view_smartbill_sync_metrics");
        $pdo->exec("DROP VIEW IF EXISTS view_smartbill_pending_invoices");
        $pdo->exec("DROP VIEW IF EXISTS view_products_with_stock");
        
        // Drop tables in reverse order
        $pdo->exec("DROP TABLE IF EXISTS smartbill_invoices");
        $pdo->exec("DROP TABLE IF EXISTS smartbill_sync_log");
        $pdo->exec("DROP TABLE IF EXISTS smartbill_sync_schedule");
        $pdo->exec("DROP TABLE IF EXISTS smartbill_config");
        $pdo->exec("DROP TABLE IF EXISTS activity_logs");
        $pdo->exec("DROP TABLE IF EXISTS settings");
        $pdo->exec("DROP TABLE IF EXISTS transactions");
        $pdo->exec("DROP TABLE IF EXISTS order_items");
        $pdo->exec("DROP TABLE IF EXISTS orders");
        $pdo->exec("DROP TABLE IF EXISTS inventory");
        $pdo->exec("DROP TABLE IF EXISTS locations");
        $pdo->exec("DROP TABLE IF EXISTS products");
        $pdo->exec("DROP TABLE IF EXISTS users");
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        echo "✅ Complete WMS schema rollback completed!\n\n";
    }
}

// Return instance for migration runner
return new CreateCompleteWmsSchemaMigration();