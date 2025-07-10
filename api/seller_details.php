
<?php
// File: api/seller_details.php - API endpoint for fetching seller details
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Database connection
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection error']);
    exit;
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include Seller model
require_once BASE_PATH . '/models/Seller.php';
$sellerModel = new Seller($db);

// Get seller ID from request
$sellerId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($sellerId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid seller ID']);
    exit;
}

try {
    // Get seller details
    $seller = $sellerModel->getSellerById($sellerId);
    
    if (!$seller) {
        http_response_code(404);
        echo json_encode(['error' => 'Seller not found']);
        exit;
    }
    
    // Add additional statistics if needed
    $statsQuery = "SELECT 
        COUNT(po.id) as total_orders,
        COALESCE(SUM(po.total_amount), 0) as total_value,
        COUNT(CASE WHEN po.status = 'completed' THEN 1 END) as completed_orders,
        MAX(po.created_at) as last_order_date
        FROM purchase_orders po 
        WHERE po.seller_id = :seller_id";
    
    $statsStmt = $db->prepare($statsQuery);
    $statsStmt->bindValue(':seller_id', $sellerId, PDO::PARAM_INT);
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Add statistics to seller data
    $seller['statistics'] = [
        'total_orders' => (int)$stats['total_orders'],
        'total_value' => (float)$stats['total_value'],
        'completed_orders' => (int)$stats['completed_orders'],
        'last_order_date' => $stats['last_order_date']
    ];
    
    // Format numeric values
    $seller['id'] = (int)$seller['id'];
    
    // Ensure all string fields are properly handled
    $stringFields = [
        'supplier_name', 'cif', 'registration_number', 'supplier_code',
        'address', 'city', 'county', 'bank_name', 'iban', 'country',
        'email', 'contact_person', 'phone', 'notes', 'status'
    ];
    
    foreach ($stringFields as $field) {
        $seller[$field] = $seller[$field] ?? '';
    }
    
    echo json_encode([
        'success' => true,
        'seller' => $seller
    ] + $seller); // Flatten seller data to root level for easier access

} catch (Exception $e) {
    error_log("Error fetching seller details: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
?>