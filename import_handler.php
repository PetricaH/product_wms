<?php
// File: import_handler.php - Handle Excel import requests
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(300); // 5 minutes for large imports

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Metodă nepermisă.']);
    exit;
}

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acces interzis. Necesită privilegii de administrator.']);
    exit;
}

// Database connection
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Eroare configurare bază de date.']);
    exit;
}

$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include Product model
require_once BASE_PATH . '/models/Product.php';
$productModel = new Product($db);

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['action']) || $data['action'] !== 'import_products') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Date de intrare invalide.']);
    exit;
}

if (!isset($data['products']) || !is_array($data['products'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Lista de produse este obligatorie.']);
    exit;
}

// Import statistics
$stats = [
    'total' => count($data['products']),
    'created' => 0,
    'updated' => 0,
    'skipped' => 0,
    'errors' => []
];

try {
    $db->beginTransaction();
    
    foreach ($data['products'] as $index => $productData) {
        try {
            // Validate required fields
            if (empty($productData['name']) || empty($productData['sku'])) {
                $stats['errors'][] = "Rândul " . ($index + 1) . ": Numele și SKU-ul sunt obligatorii";
                $stats['skipped']++;
                continue;
            }
            
            // Check if product exists
            $existingProduct = $productModel->findBySku($productData['sku']);
            
            if ($existingProduct) {
                // Update existing product
                $updateData = [
                    'name' => $productData['name'],
                    'description' => $productData['description'] ?? '',
                    'price' => floatval($productData['price'] ?? 0),
                    'category' => $productData['category'] ?? '',
                    'unit' => $productData['unit'] ?? 'pcs',
                    'quantity' => intval($productData['quantity'] ?? 0),
                    'status' => $productData['status'] ?? 'active'
                ];
                
                if ($productModel->updateProduct($existingProduct['product_id'], $updateData)) {
                    $stats['updated']++;
                } else {
                    $stats['errors'][] = "Eroare la actualizarea produsului: " . $productData['sku'];
                    $stats['skipped']++;
                }
            } else {
                // Create new product
                $createData = [
                    'name' => $productData['name'],
                    'sku' => $productData['sku'],
                    'description' => $productData['description'] ?? '',
                    'price' => floatval($productData['price'] ?? 0),
                    'category' => $productData['category'] ?? '',
                    'unit' => $productData['unit'] ?? 'pcs',
                    'status' => $productData['status'] ?? 'active'
                ];
                
                // Add quantity to products table if it exists
                if (isset($productData['quantity'])) {
                    $createData['quantity'] = intval($productData['quantity']);
                }
                
                $productId = $productModel->createProduct($createData);
                
                if ($productId) {
                    $stats['created']++;
                    
                    // If you have an inventory system, add stock here
                    if (isset($productData['quantity']) && $productData['quantity'] > 0) {
                        // You can add inventory logic here if needed
                        // $inventoryModel->addStock([...]);
                    }
                } else {
                    $stats['errors'][] = "Eroare la crearea produsului: " . $productData['sku'];
                    $stats['skipped']++;
                }
            }
            
        } catch (Exception $e) {
            $stats['errors'][] = "Rândul " . ($index + 1) . ": " . $e->getMessage();
            $stats['skipped']++;
            error_log("Product import error: " . $e->getMessage());
        }
    }
    
    $db->commit();
    
    // Prepare response
    $response = [
        'status' => 'success',
        'message' => 'Import finalizat cu succes',
        'total' => $stats['total'],
        'created' => $stats['created'],
        'updated' => $stats['updated'],
        'skipped' => $stats['skipped'],
        'errors' => $stats['errors']
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Import transaction error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Eroare la procesarea importului: ' . $e->getMessage(),
        'stats' => $stats
    ]);
}
?>