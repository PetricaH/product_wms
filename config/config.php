<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// config.php

// Determine environment
$environment = getenv('APP_ENV') ?: 'development';

// Database configuration values
$dbCfg = [
    'driver'   => 'mysql',
    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => getenv('DB_PORT') ?: '3372',
    'database' => getenv('DB_NAME') ?: 'product_wms',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASS') ?: '',
    'charset'  => 'utf8mb4',
];

// A closure that, when invoked, returns a configured PDO instance
$connectionFactory = function() use ($dbCfg) {
    $dsn = sprintf(
        '%s:host=%s;port=%s;dbname=%s;charset=%s',
        $dbCfg['driver'],
        $dbCfg['host'],
        $dbCfg['port'],
        $dbCfg['database'],
        $dbCfg['charset']
    );

    try {
        return new PDO($dsn, $dbCfg['username'], $dbCfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        // Fail fast if we can’t connect
        die("Database connection failed: " . $e->getMessage());
    }
};

return [
    // app environment (development, production, etc.)
    'environment'       => $environment,

    // raw DB settings, if you need them elsewhere
    'db'                => $dbCfg,

    // call this to get your PDO instance:
    'connection_factory'=> $connectionFactory,
];
