<?php
/**
 * Drop all tables from database
 * Run with: php drop-all-tables.php
 */

require_once 'config/config.php';

echo "\n🗑️  WMS Table Dropper\n";
echo "====================\n\n";

echo "⚠️  WARNING: This will DELETE ALL TABLES in your database!\n";
echo "Make sure you have run backup-data.php first!\n\n";

// Confirmation
echo "Type 'DELETE ALL TABLES' to confirm: ";
$handle = fopen("php://stdin", "r");
$confirmation = trim(fgets($handle));
fclose($handle);

if ($confirmation !== 'DELETE ALL TABLES') {
    echo "❌ Operation cancelled.\n";
    exit;
}

$config = require 'config/config.php';
$pdo = $config['connection_factory']();

echo "\n🔄 Starting table deletion...\n\n";

try {
    // Disable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "🔓 Disabled foreign key checks\n";
    
    // Get all tables
    $result = $pdo->query("SHOW TABLES");
    $tables = $result->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        echo "ℹ️  No tables found in database\n";
        exit;
    }
    
    echo "📋 Found " . count($tables) . " tables to drop:\n";
    foreach ($tables as $table) {
        echo "  - $table\n";
    }
    echo "\n";
    
    // Drop all views first
    $views = [];
    foreach ($tables as $table) {
        $result = $pdo->query("SHOW FULL TABLES WHERE Table_Type = 'VIEW' AND Tables_in_" . $config['db']['database'] . " = '$table'");
        if ($result->rowCount() > 0) {
            $views[] = $table;
        }
    }
    
    if (!empty($views)) {
        echo "👁️  Dropping views first:\n";
        foreach ($views as $view) {
            $pdo->exec("DROP VIEW IF EXISTS `$view`");
            echo "  ✅ Dropped view: $view\n";
        }
        echo "\n";
    }
    
    // Drop all tables
    echo "🗑️  Dropping tables:\n";
    foreach ($tables as $table) {
        if (!in_array($table, $views)) {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
            echo "  ✅ Dropped table: $table\n";
        }
    }
    
    // Re-enable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "\n🔒 Re-enabled foreign key checks\n";
    
    // Verify all tables are gone
    $result = $pdo->query("SHOW TABLES");
    $remainingTables = $result->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($remainingTables)) {
        echo "\n🎉 All tables successfully dropped!\n";
        echo "Database is now empty and ready for fresh migrations.\n\n";
        
        echo "🚀 Next steps:\n";
        echo "1. php migrate.php make create_complete_wms_schema\n";
        echo "2. Edit the migration file with the complete schema\n";
        echo "3. php migrate.php migrate\n";
        echo "4. Restore your data from backup if needed\n\n";
    } else {
        echo "\n⚠️  Some tables remain:\n";
        foreach ($remainingTables as $table) {
            echo "  - $table\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    
    // Try to re-enable foreign key checks
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    } catch (Exception $e2) {
        // Ignore
    }
}