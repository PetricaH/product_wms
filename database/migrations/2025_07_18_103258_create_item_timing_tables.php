<?php
/**
 * Migration: create_item_timing_tables
 * Created: 2025-01-18 15:30:00
 * Creates tables for granular item-level timing tracking for picking and receiving operations
 */

class CreateItemTimingTablesMigration {
    
    /**
     * Run the migration
     */
    public function up(PDO $pdo) {
        echo "⏱️  Creating item-level timing tables...\n\n";
        
        try {
            // 1. PICKING TASKS TABLE
            echo "📦 Creating picking_tasks table...\n";
            $pdo->exec("
                CREATE TABLE picking_tasks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    order_id INT NOT NULL,
                    order_item_id INT NOT NULL,
                    product_id INT NOT NULL,
                    operator_id INT NOT NULL,
                    quantity_to_pick INT NOT NULL,
                    quantity_picked INT DEFAULT 0,
                    location_id INT DEFAULT NULL,
                    start_time TIMESTAMP NOT NULL,
                    end_time TIMESTAMP NULL,
                    duration_seconds INT GENERATED ALWAYS AS (
                        CASE 
                            WHEN end_time IS NOT NULL 
                            THEN TIMESTAMPDIFF(SECOND, start_time, end_time)
                            ELSE NULL
                        END
                    ) STORED,
                    status ENUM('active', 'completed', 'paused', 'cancelled') DEFAULT 'active',
                    notes TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    
                    -- Foreign key constraints
                    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                    FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
                    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
                    FOREIGN KEY (operator_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
                    
                    -- Indexes for performance
                    INDEX idx_order_id (order_id),
                    INDEX idx_order_item_id (order_item_id),
                    INDEX idx_operator_id (operator_id),
                    INDEX idx_product_id (product_id),
                    INDEX idx_location_id (location_id),
                    INDEX idx_status (status),
                    INDEX idx_start_time (start_time),
                    INDEX idx_end_time (end_time),
                    INDEX idx_duration (duration_seconds),
                    INDEX idx_operator_product (operator_id, product_id),
                    INDEX idx_active_tasks (status, start_time) -- For finding active tasks
                )
            ");
            
            // 2. RECEIVING TASKS TABLE
            echo "📥 Creating receiving_tasks table...\n";
            $pdo->exec("
                CREATE TABLE receiving_tasks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    receiving_session_id INT NOT NULL,
                    receiving_item_id INT NOT NULL,
                    product_id INT NOT NULL,
                    operator_id INT NOT NULL,
                    quantity_to_receive INT NOT NULL,
                    quantity_received INT DEFAULT 0,
                    location_id INT DEFAULT NULL,
                    start_time TIMESTAMP NOT NULL,
                    end_time TIMESTAMP NULL,
                    duration_seconds INT GENERATED ALWAYS AS (
                        CASE 
                            WHEN end_time IS NOT NULL 
                            THEN TIMESTAMPDIFF(SECOND, start_time, end_time)
                            ELSE NULL
                        END
                    ) STORED,
                    status ENUM('active', 'completed', 'paused', 'cancelled', 'quality_check') DEFAULT 'active',
                    quality_check_notes TEXT DEFAULT NULL,
                    discrepancy_notes TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    
                    -- Foreign key constraints
                    FOREIGN KEY (receiving_session_id) REFERENCES receiving_sessions(id) ON DELETE CASCADE,
                    FOREIGN KEY (receiving_item_id) REFERENCES receiving_items(id) ON DELETE CASCADE,
                    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
                    FOREIGN KEY (operator_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
                    
                    -- Indexes for performance
                    INDEX idx_receiving_session_id (receiving_session_id),
                    INDEX idx_receiving_item_id (receiving_item_id),
                    INDEX idx_operator_id (operator_id),
                    INDEX idx_product_id (product_id),
                    INDEX idx_location_id (location_id),
                    INDEX idx_status (status),
                    INDEX idx_start_time (start_time),
                    INDEX idx_end_time (end_time),
                    INDEX idx_duration (duration_seconds),
                    INDEX idx_operator_product (operator_id, product_id),
                    INDEX idx_active_tasks (status, start_time) -- For finding active tasks
                )
            ");
            
            // 3. TIMING ANALYTICS VIEW - For easy querying
            echo "📊 Creating timing analytics views...\n";
            $pdo->exec("
                CREATE VIEW view_picking_analytics AS
                SELECT 
                    pt.operator_id,
                    u.username as operator_name,
                    pt.product_id,
                    p.name as product_name,
                    p.category as product_category,
                    COUNT(*) as total_picks,
                    AVG(pt.duration_seconds) as avg_duration_seconds,
                    MIN(pt.duration_seconds) as min_duration_seconds,
                    MAX(pt.duration_seconds) as max_duration_seconds,
                    SUM(pt.quantity_picked) as total_quantity_picked,
                    AVG(pt.quantity_picked) as avg_quantity_per_pick,
                    DATE(pt.start_time) as pick_date
                FROM picking_tasks pt
                JOIN users u ON pt.operator_id = u.id
                JOIN products p ON pt.product_id = p.product_id
                WHERE pt.status = 'completed'
                AND pt.end_time IS NOT NULL
                GROUP BY pt.operator_id, pt.product_id, DATE(pt.start_time)
            ");
            
            $pdo->exec("
                CREATE VIEW view_receiving_analytics AS
                SELECT 
                    rt.operator_id,
                    u.username as operator_name,
                    rt.product_id,
                    p.name as product_name,
                    p.category as product_category,
                    COUNT(*) as total_receives,
                    AVG(rt.duration_seconds) as avg_duration_seconds,
                    MIN(rt.duration_seconds) as min_duration_seconds,
                    MAX(rt.duration_seconds) as max_duration_seconds,
                    SUM(rt.quantity_received) as total_quantity_received,
                    AVG(rt.quantity_received) as avg_quantity_per_receive,
                    DATE(rt.start_time) as receive_date
                FROM receiving_tasks rt
                JOIN users u ON rt.operator_id = u.id
                JOIN products p ON rt.product_id = p.product_id
                WHERE rt.status = 'completed'
                AND rt.end_time IS NOT NULL
                GROUP BY rt.operator_id, rt.product_id, DATE(rt.start_time)
            ");
            
            // 4. OPERATOR PERFORMANCE VIEW
            echo "👥 Creating operator performance views...\n";
            $pdo->exec("
                CREATE VIEW view_operator_performance AS
                SELECT 
                    u.id as operator_id,
                    u.username as operator_name,
                    
                    -- Picking metrics
                    COUNT(DISTINCT pt.id) as total_picks,
                    AVG(pt.duration_seconds) as avg_pick_duration_seconds,
                    SUM(pt.quantity_picked) as total_items_picked,
                    
                    -- Receiving metrics
                    COUNT(DISTINCT rt.id) as total_receives,
                    AVG(rt.duration_seconds) as avg_receive_duration_seconds,
                    SUM(rt.quantity_received) as total_items_received,
                    
                    -- Combined metrics
                    (COUNT(DISTINCT pt.id) + COUNT(DISTINCT rt.id)) as total_tasks,
                    DATE(COALESCE(pt.start_time, rt.start_time)) as activity_date
                    
                FROM users u
                LEFT JOIN picking_tasks pt ON u.id = pt.operator_id 
                    AND pt.status = 'completed' 
                    AND pt.end_time IS NOT NULL
                LEFT JOIN receiving_tasks rt ON u.id = rt.operator_id 
                    AND rt.status = 'completed' 
                    AND rt.end_time IS NOT NULL
                WHERE u.role IN ('employee', 'manager', 'admin')
                GROUP BY u.id, DATE(COALESCE(pt.start_time, rt.start_time))
                HAVING total_tasks > 0
            ");
            
            // 5. PRODUCT DIFFICULTY ANALYSIS VIEW
            echo "📈 Creating product difficulty analysis view...\n";
            $pdo->exec("
                CREATE VIEW view_product_difficulty AS
                SELECT 
                    p.product_id,
                    p.name as product_name,
                    p.category as product_category,
                    p.sku,
                    
                    -- Picking difficulty
                    COUNT(DISTINCT pt.id) as total_pick_tasks,
                    AVG(pt.duration_seconds) as avg_pick_duration_seconds,
                    STDDEV(pt.duration_seconds) as pick_duration_stddev,
                    
                    -- Receiving difficulty
                    COUNT(DISTINCT rt.id) as total_receive_tasks,
                    AVG(rt.duration_seconds) as avg_receive_duration_seconds,
                    STDDEV(rt.duration_seconds) as receive_duration_stddev,
                    
                    -- Combined difficulty score (higher = more difficult)
                    (
                        COALESCE(AVG(pt.duration_seconds), 0) + 
                        COALESCE(AVG(rt.duration_seconds), 0) + 
                        COALESCE(STDDEV(pt.duration_seconds), 0) + 
                        COALESCE(STDDEV(rt.duration_seconds), 0)
                    ) as difficulty_score
                    
                FROM products p
                LEFT JOIN picking_tasks pt ON p.product_id = pt.product_id 
                    AND pt.status = 'completed' 
                    AND pt.end_time IS NOT NULL
                LEFT JOIN receiving_tasks rt ON p.product_id = rt.product_id 
                    AND rt.status = 'completed' 
                    AND rt.end_time IS NOT NULL
                GROUP BY p.product_id
                HAVING (total_pick_tasks > 0 OR total_receive_tasks > 0)
            ");
            
            echo "✅ Item-level timing tables created successfully!\n";
            echo "📋 Created tables: picking_tasks, receiving_tasks\n";
            echo "📊 Created views: view_picking_analytics, view_receiving_analytics, view_operator_performance, view_product_difficulty\n\n";
            
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
        echo "🗑️  Rolling back item-level timing tables...\n";
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        // Drop views first
        $pdo->exec("DROP VIEW IF EXISTS view_product_difficulty");
        $pdo->exec("DROP VIEW IF EXISTS view_operator_performance");
        $pdo->exec("DROP VIEW IF EXISTS view_receiving_analytics");
        $pdo->exec("DROP VIEW IF EXISTS view_picking_analytics");
        
        // Drop tables
        $pdo->exec("DROP TABLE IF EXISTS receiving_tasks");
        $pdo->exec("DROP TABLE IF EXISTS picking_tasks");
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        echo "✅ Item-level timing tables rollback completed!\n\n";
    }
}

// Return instance for migration runner
return new CreateItemTimingTablesMigration();