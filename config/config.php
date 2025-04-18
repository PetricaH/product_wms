<?php

return [
    // Get environment from .env file or default to 'development'
    'environment' => getenv('APP_ENV') ?: 'development',

    // Database configuration
    'connection' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'database' => getenv('DB_NAME') ?: 'wartung_wms',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
        'driver' => 'mysql',
        'port' => getenv('DB_PORT') ?: '3372'
    ],

    // Get the current connection
    'current' => function() {
        return $GLOBALS['connection'];
    }
];
