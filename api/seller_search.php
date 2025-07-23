<?php
// File: api/seller_search.php - Real-time seller search endpoint
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();
    
    // Get search parameters
    $search = trim($_GET['q'] ?? '');
    $limit = min(10, max(1, intval($_GET['limit'] ?? 10))); // Max 10 results
    
    if (empty($search)) {
        echo json_encode([
            'success' => true,
            'sellers' => []
        ]);
        exit;
    }
    
    // Search sellers by name, contact person, or supplier code
    $query = "SELECT 
                s.id,
                s.supplier_name,
                s.contact_person,
                s.email,
                s.phone,
                s.supplier_code,
                s.city,
                s.status
              FROM sellers s
              WHERE s.status = 'active' 
              AND (
                s.supplier_name LIKE :search 
                OR s.contact_person LIKE :search 
                OR s.supplier_code LIKE :search
                OR s.email LIKE :search
              )
              ORDER BY 
                CASE 
                  WHEN s.supplier_name LIKE :exact_search THEN 1
                  WHEN s.supplier_name LIKE :start_search THEN 2
                  ELSE 3
                END,
                s.supplier_name ASC
              LIMIT :limit";
    
    $stmt = $db->prepare($query);
    $searchParam = '%' . $search . '%';
    $exactParam = $search;
    $startParam = $search . '%';
    
    $stmt->bindValue(':search', $searchParam, PDO::PARAM_STR);
    $stmt->bindValue(':exact_search', $exactParam, PDO::PARAM_STR);
    $stmt->bindValue(':start_search', $startParam, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    
    $stmt->execute();
    $sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format results for frontend
    $formattedSellers = array_map(function($seller) {
        return [
            'id' => intval($seller['id']),
            'name' => $seller['supplier_name'],
            'contact_person' => $seller['contact_person'],
            'email' => $seller['email'],
            'phone' => $seller['phone'],
            'code' => $seller['supplier_code'],
            'city' => $seller['city'],
            'display_text' => $seller['supplier_name'] . 
                             ($seller['contact_person'] ? ' - ' . $seller['contact_person'] : '') .
                             ($seller['city'] ? ' (' . $seller['city'] . ')' : '')
        ];
    }, $sellers);
    
    echo json_encode([
        'success' => true,
        'sellers' => $formattedSellers,
        'count' => count($formattedSellers)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Search failed: ' . $e->getMessage()
    ]);
}
?>