<?php
/**
 * Simple PHP Database Migration System
 * Save this as: includes/MigrationRunner.php
 */

class MigrationRunner {
    private $pdo;
    private $migrationsPath;
    
    public function __construct(PDO $pdo, $migrationsPath = 'database/migrations') {
        $this->pdo = $pdo;
        $this->migrationsPath = $migrationsPath;
        $this->createMigrationsTable();
    }
    
    /**
     * Create migrations tracking table
     */
    private function createMigrationsTable() {
        $sql = "
            CREATE TABLE IF NOT EXISTS migrations (
                id INT PRIMARY KEY AUTO_INCREMENT,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_migration (migration)
            )
        ";
        $this->pdo->exec($sql);
    }
    
    /**
     * Run pending migrations
     */
    public function migrate() {
        $executedMigrations = $this->getExecutedMigrations();
        $allMigrations = $this->getAllMigrationFiles();
        $pendingMigrations = array_diff($allMigrations, $executedMigrations);
        
        if (empty($pendingMigrations)) {
            echo "No pending migrations.\n";
            return;
        }
        
        $currentBatch = $this->getNextBatch();
        
        foreach ($pendingMigrations as $migration) {
            echo "Running migration: $migration\n";
            
            try {
                $this->runMigration($migration);
                $this->recordMigration($migration, $currentBatch);
                echo "✅ $migration migrated successfully\n";
            } catch (Exception $e) {
                echo "❌ Migration failed: $migration\n";
                echo "Error: " . $e->getMessage() . "\n";
                break;
            }
        }
    }
    
    /**
     * Rollback last batch of migrations
     */
    public function rollback() {
        $lastBatch = $this->getLastBatch();
        if (!$lastBatch) {
            echo "Nothing to rollback.\n";
            return;
        }
        
        $migrations = $this->getMigrationsByBatch($lastBatch);
        
        // Run rollbacks in reverse order
        foreach (array_reverse($migrations) as $migration) {
            echo "Rolling back: $migration\n";
            
            try {
                $this->rollbackMigration($migration);
                $this->removeMigrationRecord($migration);
                echo "✅ $migration rolled back successfully\n";
            } catch (Exception $e) {
                echo "❌ Rollback failed: $migration\n";
                echo "Error: " . $e->getMessage() . "\n";
                break;
            }
        }
    }
    
    /**
     * Show migration status
     */
    public function status() {
        $executed = $this->getExecutedMigrations();
        $all = $this->getAllMigrationFiles();
        $pending = array_diff($all, $executed);
        
        echo "\n=== Migration Status ===\n\n";
        
        if (!empty($executed)) {
            echo "Executed migrations:\n";
            foreach ($executed as $migration) {
                echo "✅ $migration\n";
            }
        }
        
        if (!empty($pending)) {
            echo "\nPending migrations:\n";
            foreach ($pending as $migration) {
                echo "⏳ $migration\n";
            }
        }
        
        if (empty($all)) {
            echo "No migrations found.\n";
        }
        
        echo "\n";
    }
    
    /**
     * Create a new migration file
     */
    public function make($name) {
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";
        $filepath = $this->migrationsPath . '/' . $filename;
        
        $template = $this->getMigrationTemplate($name);
        
        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0755, true);
        }
        
        file_put_contents($filepath, $template);
        echo "Created migration: $filename\n";
        echo "Edit file: $filepath\n";
        
        return $filename;
    }
    
    /**
     * Run specific migration file
     */
    private function runMigration($migration) {
        $filepath = $this->migrationsPath . '/' . $migration;
        
        if (!file_exists($filepath)) {
            throw new Exception("Migration file not found: $migration");
        }
        
        // Include migration and run up() method
        $migrationInstance = include $filepath;
        
        if (is_object($migrationInstance) && method_exists($migrationInstance, 'up')) {
            $migrationInstance->up($this->pdo);
        } else {
            throw new Exception("Invalid migration format: $migration");
        }
    }
    
    /**
     * Rollback specific migration
     */
    private function rollbackMigration($migration) {
        $filepath = $this->migrationsPath . '/' . $migration;
        
        if (!file_exists($filepath)) {
            throw new Exception("Migration file not found: $migration");
        }
        
        $migrationInstance = include $filepath;
        
        if (is_object($migrationInstance) && method_exists($migrationInstance, 'down')) {
            $migrationInstance->down($this->pdo);
        } else {
            throw new Exception("Migration does not support rollback: $migration");
        }
    }
    
    /**
     * Get all migration files
     */
    private function getAllMigrationFiles() {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }
        
        $files = scandir($this->migrationsPath);
        $migrations = [];
        
        foreach ($files as $file) {
            if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_.*\.php$/', $file)) {
                $migrations[] = $file;
            }
        }
        
        sort($migrations);
        return $migrations;
    }
    
    /**
     * Get executed migrations from database
     */
    private function getExecutedMigrations() {
        try {
            $stmt = $this->pdo->query("SELECT migration FROM migrations ORDER BY id");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Record migration as executed
     */
    private function recordMigration($migration, $batch) {
        $stmt = $this->pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
        $stmt->execute([$migration, $batch]);
    }
    
    /**
     * Remove migration record
     */
    private function removeMigrationRecord($migration) {
        $stmt = $this->pdo->prepare("DELETE FROM migrations WHERE migration = ?");
        $stmt->execute([$migration]);
    }
    
    /**
     * Get next batch number
     */
    private function getNextBatch() {
        $stmt = $this->pdo->query("SELECT COALESCE(MAX(batch), 0) + 1 as next_batch FROM migrations");
        return $stmt->fetch(PDO::FETCH_COLUMN);
    }
    
    /**
     * Get last batch number
     */
    public function getLastBatch() {
        $stmt = $this->pdo->query("SELECT MAX(batch) FROM migrations");
        return $stmt->fetch(PDO::FETCH_COLUMN);
    }
    
    /**
     * Get migrations by batch
     */
    private function getMigrationsByBatch($batch) {
        $stmt = $this->pdo->prepare("SELECT migration FROM migrations WHERE batch = ? ORDER BY id");
        $stmt->execute([$batch]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Migration template
     */
    private function getMigrationTemplate($name) {
        $className = $this->toCamelCase($name);
        
        return "<?php
/**
 * Migration: $name
 * Created: " . date('Y-m-d H:i:s') . "
 */

class {$className}Migration {
    
    /**
     * Run the migration
     */
    public function up(PDO \$pdo) {
        // Add your migration logic here
        \$sql = \"
            CREATE TABLE example_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        \";
        \$pdo->exec(\$sql);
    }
    
    /**
     * Rollback the migration
     */
    public function down(PDO \$pdo) {
        // Add your rollback logic here
        \$pdo->exec(\"DROP TABLE IF EXISTS example_table\");
    }
}

// Return instance for migration runner
return new {$className}Migration();
";
    }
    
    /**
     * Convert snake_case to CamelCase
     */
    private function toCamelCase($string) {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
    }
}