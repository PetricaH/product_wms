<?php
// File: check_server_config.php - Check for server config issues
header('Content-Type: text/plain');

echo "🔧 SERVER CONFIGURATION CHECK\n";
echo str_repeat("=", 50) . "\n\n";

// Check .htaccess files
$checkPaths = [
    '/var/www/notsowms.ro/.htaccess',
    '/var/www/notsowms.ro/api/.htaccess',
    '/var/www/notsowms.ro/api/warehouse/.htaccess'
];

echo "📄 .htaccess FILES:\n";
foreach ($checkPaths as $path) {
    if (file_exists($path)) {
        echo "✅ EXISTS: $path\n";
        $content = file_get_contents($path);
        $lines = explode("\n", $content);
        echo "   First 5 lines:\n";
        for ($i = 0; $i < min(5, count($lines)); $i++) {
            if (trim($lines[$i])) {
                echo "   " . ($i + 1) . ": " . trim($lines[$i]) . "\n";
            }
        }
        echo "\n";
    } else {
        echo "❌ NOT FOUND: $path\n";
    }
}

// Check directory permissions
echo "🔐 DIRECTORY PERMISSIONS:\n";
$dirs = [
    '/var/www/notsowms.ro',
    '/var/www/notsowms.ro/api',
    '/var/www/notsowms.ro/api/warehouse'
];

foreach ($dirs as $dir) {
    if (is_dir($dir)) {
        $perms = fileperms($dir);
        $readable = is_readable($dir) ? 'YES' : 'NO';
        $writable = is_writable($dir) ? 'YES' : 'NO';
        $executable = is_executable($dir) ? 'YES' : 'NO';
        
        echo "📁 $dir\n";
        echo "   Permissions: " . substr(sprintf('%o', $perms), -4) . "\n";
        echo "   Readable: $readable | Writable: $writable | Executable: $executable\n\n";
    }
}

// Check PHP configuration
echo "⚙️ PHP CONFIGURATION:\n";
$settings = [
    'display_errors',
    'log_errors',
    'error_log',
    'max_execution_time',
    'memory_limit',
    'file_uploads',
    'upload_max_filesize',
    'post_max_size'
];

foreach ($settings as $setting) {
    $value = ini_get($setting);
    echo "$setting: " . ($value ? $value : 'disabled/empty') . "\n";
}

// Check if specific file exists and is readable
echo "\n📝 FILE STATUS:\n";
$targetFile = '/var/www/notsowms.ro/api/warehouse/get_orders.php';
if (file_exists($targetFile)) {
    echo "✅ get_orders.php exists\n";
    echo "   Size: " . filesize($targetFile) . " bytes\n";
    echo "   Readable: " . (is_readable($targetFile) ? 'YES' : 'NO') . "\n";
    echo "   Last modified: " . date('Y-m-d H:i:s', filemtime($targetFile)) . "\n";
    
    // Try to read first few lines
    $handle = fopen($targetFile, 'r');
    if ($handle) {
        echo "   First line: " . fgets($handle) . "\n";
        fclose($handle);
    }
} else {
    echo "❌ get_orders.php NOT FOUND\n";
}

// Check error logs
echo "\n📋 ERROR LOG CHECK:\n";
$logFiles = [
    '/var/log/apache2/error.log',
    '/var/www/notsowms.ro/logs/error.log',
    ini_get('error_log')
];

foreach ($logFiles as $logFile) {
    if ($logFile && file_exists($logFile) && is_readable($logFile)) {
        echo "✅ Found readable log: $logFile\n";
        echo "   Size: " . filesize($logFile) . " bytes\n";
        
        // Get last few lines
        $lines = file($logFile);
        if ($lines) {
            $recentLines = array_slice($lines, -5);
            echo "   Last 5 lines:\n";
            foreach ($recentLines as $i => $line) {
                echo "   " . (count($lines) - 5 + $i + 1) . ": " . trim($line) . "\n";
            }
        }
        break;
    } else {
        echo "❌ Not accessible: " . ($logFile ?: 'null') . "\n";
    }
}

echo "\n🏁 Configuration check complete!\n";
?>