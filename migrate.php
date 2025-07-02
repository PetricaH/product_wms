<?php
/**
 * Migration CLI Tool - Fixed for existing config
 * Save this as: migrate.php (replace the existing one)
 */

// Include your existing configuration
require_once 'config/config.php';
require_once 'includes/MigrationRunner.php';

echo "\n🔧 WMS Migration Tool\n";
echo "====================\n\n";

// Load config using your existing pattern
$config = require 'config/config.php';

// Debug: Let's see what's in the config
echo "🔍 Debugging config...\n";
echo "Config keys found: " . implode(', ', array_keys($config)) . "\n";
echo "Connection factory exists: " . (isset($config['connection_factory']) ? 'YES' : 'NO') . "\n";
echo "Connection factory callable: " . (isset($config['connection_factory']) && is_callable($config['connection_factory']) ? 'YES' : 'NO') . "\n\n";

// Get database connection using your existing config pattern
try {
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        echo "❌ Connection factory issue. Let's try alternative approach...\n";
        
        // Alternative: Use the raw DB config
        if (isset($config['db'])) {
            $dbCfg = $config['db'];
            echo "✅ Found raw DB config, creating connection manually...\n";
            
            $dsn = sprintf(
                '%s:host=%s;port=%s;dbname=%s;charset=%s',
                $dbCfg['driver'],
                $dbCfg['host'],
                $dbCfg['port'],
                $dbCfg['database'],
                $dbCfg['charset']
            );
            
            $pdo = new PDO($dsn, $dbCfg['username'], $dbCfg['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            
            echo "✅ Manual database connection successful\n";
        } else {
            die("❌ No database configuration found in config\n");
        }
    } else {
        // Use the connection factory
        $pdo = $config['connection_factory']();
        echo "✅ Database connected using connection factory\n";
    }
    
    echo "✅ Connected to database: " . $config['db']['database'] . "\n\n";
    
} catch (Exception $e) {
    die("❌ Database connection failed: " . $e->getMessage() . "\n");
}

// Create migration runner
$migrator = new MigrationRunner($pdo, 'database/migrations');

// Parse command line arguments
$command = $argv[1] ?? 'help';

switch ($command) {
    case 'migrate':
        echo "Running migrations...\n\n";
        $migrator->migrate();
        break;
        
    case 'rollback':
        echo "Rolling back last batch...\n\n";
        $migrator->rollback();
        break;
        
    case 'status':
        $migrator->status();
        break;
        
    case 'make':
        $name = $argv[2] ?? null;
        if (!$name) {
            echo "❌ Migration name required\n";
            echo "Usage: php migrate.php make create_users_table\n\n";
            exit(1);
        }
        echo "Creating migration: $name\n\n";
        $migrator->make($name);
        break;
        
    case 'test':
        echo "Testing migration system...\n\n";
        
        // Test database connection
        echo "✅ Database connection: OK\n";
        echo "   Database: " . $config['db']['database'] . "\n";
        echo "   Host: " . $config['db']['host'] . "\n";
        echo "   Port: " . $config['db']['port'] . "\n";
        
        // Test migrations table
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM migrations");
            $count = $stmt->fetchColumn();
            echo "✅ Migrations table: OK ($count migrations recorded)\n";
        } catch (Exception $e) {
            echo "⚠️  Migrations table: Will be created automatically\n";
        }
        
        // Test migrations directory
        if (is_dir('database/migrations')) {
            $files = glob('database/migrations/*.php');
            echo "✅ Migrations directory: OK (" . count($files) . " migration files)\n";
        } else {
            echo "⚠️  Migrations directory: Will be created automatically\n";
        }
        
        // Test creating directories
        if (!is_dir('database')) {
            mkdir('database', 0755, true);
            echo "✅ Created database directory\n";
        }
        
        if (!is_dir('database/migrations')) {
            mkdir('database/migrations', 0755, true);
            echo "✅ Created migrations directory\n";
        }
        
        echo "\n";
        echo "🎉 Migration system is ready!\n";
        echo "Try: php migrate.php make create_products_table\n";
        break;
        
    case 'info':
        echo "System Information:\n";
        echo "==================\n\n";
        echo "PHP Version: " . PHP_VERSION . "\n";
        echo "Current Directory: " . getcwd() . "\n";
        echo "Database: " . $config['db']['database'] . "\n";
        echo "Host: " . $config['db']['host'] . ":" . $config['db']['port'] . "\n";
        echo "Environment: " . ($config['environment'] ?? 'unknown') . "\n";
        
        // Check PHP extensions
        $extensions = ['pdo', 'pdo_mysql'];
        echo "\nPHP Extensions:\n";
        foreach ($extensions as $ext) {
            echo "  $ext: " . (extension_loaded($ext) ? '✅ Loaded' : '❌ Missing') . "\n";
        }
        
        echo "\n";
        break;
        
    default:
        echo "Available commands:\n\n";
        echo "  test       Test migration system setup\n";
        echo "  info       Show system information\n";
        echo "  make       Create new migration file\n";
        echo "  migrate    Run pending migrations\n";
        echo "  rollback   Rollback last batch of migrations\n";
        echo "  status     Show migration status\n";
        echo "\nExamples:\n";
        echo "  php migrate.php test\n";
        echo "  php migrate.php make create_products_table\n";
        echo "  php migrate.php migrate\n";
        echo "  php migrate.php status\n\n";
        break;
}

echo "\n";