<?php
/**
 * AWB Entry Point
 * File: web/awb.php
 * Handles AWB generation requests for authenticated users
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/web/controllers/AWBController.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// Route: POST /orders/{id}/awb
if ($method === 'POST' && preg_match('/\/orders\/(\d+)\/awb$/', $uri, $matches)) {
    $controller = new AWBController();
    $controller->generateAWB($matches[1]);
} else {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Route not found',
        'timestamp' => date('c')
    ]);
}