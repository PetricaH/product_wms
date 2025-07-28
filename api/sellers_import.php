<?php
// File: api/sellers_import.php - Handle sellers Excel import
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodă nepermisă.']);
    exit;
}

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acces interzis. Necesită privilegii de administrator.']);
    exit;
}

// Database connection
$config = require BASE_PATH . '/config/config.php';
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Eroare configurare bază de date.']);
    exit;
}

$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include Seller model
require_once BASE_PATH . '/models/Seller.php';
$sellerModel = new Seller($db);

class SellersImportHandler {
    private $db;
    private $sellerModel;
    private $results;
    
    public function __construct($database, $sellerModel) {
        $this->db = $database;
        $this->sellerModel = $sellerModel;
        $this->results = [
            'success' => false,
            'processed' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'warnings' => [],
            'message' => ''
        ];
    }
    
    public function processImport($sellersData, $options) {
        try {
            if (!is_array($sellersData) || empty($sellersData)) {
                throw new Exception('Nu s-au primit date valide pentru import.');
            }
            
            $this->db->beginTransaction();
            
            foreach ($sellersData as $index => $sellerData) {
                $this->results['processed']++;
                
                try {
                    $this->processSingleSeller($sellerData, $options, $index + 1);
                } catch (Exception $e) {
                    $this->results['errors'][] = "Rândul " . ($index + 2) . ": " . $e->getMessage();
                    $this->results['skipped']++;
                }
            }
            
            $this->db->commit();
            
            $this->results['success'] = true;
            $this->results['message'] = sprintf(
                'Import finalizat. Procesați: %d, Importați: %d, Actualizați: %d, Omiși: %d',
                $this->results['processed'],
                $this->results['imported'],
                $this->results['updated'],
                $this->results['skipped']
            );
            
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->results['success'] = false;
            $this->results['message'] = 'Eroare la import: ' . $e->getMessage();
            error_log('Sellers import error: ' . $e->getMessage());
        }
        
        return $this->results;
    }
    
    private function processSingleSeller($sellerData, $options, $rowNumber) {
        // Validate required fields
        if (empty($sellerData['supplier_name'])) {
            throw new Exception('Numele furnizorului este obligatoriu');
        }
        
        // Clean and validate data
        $cleanData = $this->cleanSellerData($sellerData);
        
        // Check if seller exists
        $existingSeller = null;
        
        // Try to find by supplier code first, then by name
        if (!empty($cleanData['supplier_code'])) {
            $existingSeller = $this->findSellerByCode($cleanData['supplier_code']);
        }
        
        if (!$existingSeller && !empty($cleanData['tax_id'])) {
            $existingSeller = $this->findSellerByTaxId($cleanData['tax_id']);
        }
        
        if (!$existingSeller) {
            $existingSeller = $this->findSellerByName($cleanData['supplier_name']);
        }
        
        if ($existingSeller) {
            if ($options['skipDuplicates']) {
                $this->results['skipped']++;
                return;
            }
            
            if ($options['updateExisting']) {
                $this->updateSeller($existingSeller['id'], $cleanData);
                $this->results['updated']++;
            } else {
                $this->results['skipped']++;
            }
        } else {
            $this->createSeller($cleanData);
            $this->results['imported']++;
        }
    }
    
    private function cleanSellerData($data) {
        $cleaned = [];
        
        // Required fields
        $cleaned['supplier_name'] = trim($data['supplier_name'] ?? '');
        
        // Optional fields with cleaning
        $cleaned['supplier_code'] = trim($data['supplier_code'] ?? '');
        $cleaned['tax_id'] = trim($data['tax_id'] ?? '');
        $cleaned['reg_com'] = trim($data['reg_com'] ?? '');
        $cleaned['address'] = trim($data['address'] ?? '');
        $cleaned['city'] = trim($data['city'] ?? '');
        $cleaned['county'] = trim($data['county'] ?? '');
        $cleaned['country'] = trim($data['country'] ?? 'Romania');
        $cleaned['bank'] = trim($data['bank'] ?? '');
        $cleaned['iban'] = trim($data['iban'] ?? '');
        $cleaned['contact_person'] = trim($data['contact_person'] ?? '');
        $cleaned['phone'] = trim($data['phone'] ?? '');
        $cleaned['email'] = trim($data['email'] ?? '');
        $cleaned['status'] = trim($data['status'] ?? 'active');
        
        // Validate email if provided
        if (!empty($cleaned['email']) && !filter_var($cleaned['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Adresa de email nu este validă: ' . $cleaned['email']);
        }
        
        // Generate supplier code if not provided
        if (empty($cleaned['supplier_code'])) {
            $cleaned['supplier_code'] = $this->generateSupplierCode($cleaned['supplier_name']);
        }
        
        return $cleaned;
    }
    
    private function generateSupplierCode($supplierName) {
        // Create a simple code from the name
        $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $supplierName), 0, 6));
        
        // Add random number to ensure uniqueness
        $counter = 1;
        $originalCode = $code;
        
        while ($this->findSellerByCode($code)) {
            $code = $originalCode . sprintf('%03d', $counter);
            $counter++;
            
            if ($counter > 999) {
                $code = $originalCode . time();
                break;
            }
        }
        
        return $code;
    }
    
    private function findSellerByCode($code) {
        if (empty($code)) return null;
        
        $query = "SELECT * FROM sellers WHERE supplier_code = ? LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function findSellerByTaxId($taxId) {
        if (empty($taxId)) return null;
        
        $query = "SELECT * FROM sellers WHERE cif = ? LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$taxId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function findSellerByName($name) {
        if (empty($name)) return null;
        
        $query = "SELECT * FROM sellers WHERE supplier_name = ? LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function createSeller($data) {
        $query = "INSERT INTO sellers (
            supplier_name, supplier_code, cif, registration_number, address, city, county, 
            country, bank_name, iban, contact_person, phone, email, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $data['supplier_name'],
            $data['supplier_code'],
            $data['tax_id'] ?: null,
            $data['reg_com'] ?: null,
            $data['address'] ?: null,
            $data['city'] ?: null,
            $data['county'] ?: null,
            $data['country'],
            $data['bank'] ?: null,
            $data['iban'] ?: null,
            $data['contact_person'] ?: null,
            $data['phone'] ?: null,
            $data['email'] ?: null,
            $data['status']
        ];
        
        $stmt = $this->db->prepare($query);
        if (!$stmt->execute($params)) {
            throw new Exception('Eroare la crearea furnizorului: ' . implode(', ', $stmt->errorInfo()));
        }
        
        return $this->db->lastInsertId();
    }
    
    private function updateSeller($sellerId, $data) {
        $query = "UPDATE sellers SET 
            supplier_name = ?, supplier_code = ?, cif = ?, registration_number = ?, address = ?, 
            city = ?, county = ?, country = ?, bank_name = ?, iban = ?, contact_person = ?, 
            phone = ?, email = ?, status = ?
            WHERE id = ?";
        
        $params = [
            $data['supplier_name'],
            $data['supplier_code'],
            $data['tax_id'] ?: null,
            $data['reg_com'] ?: null,
            $data['address'] ?: null,
            $data['city'] ?: null,
            $data['county'] ?: null,
            $data['country'],
            $data['bank'] ?: null,
            $data['iban'] ?: null,
            $data['contact_person'] ?: null,
            $data['phone'] ?: null,
            $data['email'] ?: null,
            $data['status'],
            $sellerId
        ];
        
        $stmt = $this->db->prepare($query);
        if (!$stmt->execute($params)) {
            throw new Exception('Eroare la actualizarea furnizorului: ' . implode(', ', $stmt->errorInfo()));
        }
    }
}

// Main execution
try {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !isset($data['action']) || $data['action'] !== 'import_sellers') {
        throw new Exception('Date de intrare invalide.');
    }
    
    if (!isset($data['sellers']) || !is_array($data['sellers'])) {
        throw new Exception('Lista de furnizori este obligatorie.');
    }
    
    $options = $data['options'] ?? [
        'updateExisting' => true,
        'skipDuplicates' => false
    ];
    
    // Process the import
    $importHandler = new SellersImportHandler($db, $sellerModel);
    $result = $importHandler->processImport($data['sellers'], $options);
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'processed' => 0,
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => [$e->getMessage()]
    ]);
}
?>