<?php
// File: check_apache.php - Upload to ROOT directory only
header('Content-Type: text/plain');

echo "=== APACHE & PHP CONFIGURATION CHECK ===\n\n";

echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "\n";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "Server Name: " . $_SERVER['SERVER_NAME'] . "\n";
echo "Request URI: " . $_SERVER['REQUEST_URI'] . "\n";
echo "Script Filename: " . $_SERVER['SCRIPT_FILENAME'] . "\n\n";

echo "=== PHP MODULES ===\n";
$modules = get_loaded_extensions();
sort($modules);
foreach ($modules as $module) {
    echo "$module\n";
}

echo "\n=== APACHE MODULES (if available) ===\n";
if (function_exists('apache_get_modules')) {
    $apache_modules = apache_get_modules();
    sort($apache_modules);
    foreach ($apache_modules as $module) {
        echo "$module\n";
    }
} else {
    echo "apache_get_modules() not available\n";
}

echo "\n=== DIRECTORY TESTS ===\n";
$dirs_to_test = [
    '/var/www/notsowms.ro',
    '/var/www/notsowms.ro/api',
    '/var/www/notsowms.ro/api/warehouse',
    '/var/www/notsowms.ro/config'
];

foreach ($dirs_to_test as $dir) {
    echo "Directory: $dir\n";
    echo "  Exists: " . (is_dir($dir) ? 'YES' : 'NO') . "\n";
    if (is_dir($dir)) {
        echo "  Readable: " . (is_readable($dir) ? 'YES' : 'NO') . "\n";
        echo "  Writable: " . (is_writable($dir) ? 'YES' : 'NO') . "\n";
        echo "  Permissions: " . substr(sprintf('%o', fileperms($dir)), -4) . "\n";
    }
    echo "\n";
}

echo "=== .HTACCESS FILES ===\n";
$htaccess_files = [
    '/var/www/notsowms.ro/.htaccess',
    '/var/www/notsowms.ro/api/.htaccess',
    '/var/www/notsowms.ro/api/warehouse/.htaccess'
];

foreach ($htaccess_files as $file) {
    echo "File: $file\n";
    if (file_exists($file)) {
        echo "  Exists: YES\n";
        echo "  Size: " . filesize($file) . " bytes\n";
        echo "  Content:\n";
        echo "  " . str_replace("\n", "\n  ", file_get_contents($file)) . "\n";
    } else {
        echo "  Exists: NO\n";
    }
    echo "\n";
}
?>