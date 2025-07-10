<?php
/**
 * AWB Controller - Production Ready
 * File: web/controllers/AWBController.php
 * Handles AWB generation for authenticated users
 */

class AWBController {
    private $orderModel;
    private $cargusService;
    private $conn;
    
    public function __construct() {
        $this->validateSession();
        $this->validateCSRF();
        $this->initializeServices();
    }
    
    private function validateSession() {
        session_start();
        
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
            $this->error('Authentication required', 401);
        }
        
        // Just update last activity without strict timeout checking
        $_SESSION['last_activity'] = time();
    }
    
    private function validateCSRF() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            
            if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
                $this->error('Invalid CSRF token', 403);
            }
        }
    }
    
    private function initializeServices() {
        $config = require BASE_PATH . '/config/config.php';
        $this->conn = $config['connection_factory']();
        
        require_once BASE_PATH . '/models/Order.php';
        require_once BASE_PATH . '/models/CargusService.php';
        
        $this->orderModel = new Order($this->conn);
        $this->cargusService = new CargusService();
    }
    
    public function generateAWB($orderId) {
        try {
            $orderId = (int)$orderId;
            
            // Get and validate order
            $order = $this->orderModel->getOrderById($orderId);
            if (!$order) {
                throw new Exception('Order not found', 404);
            }
            
            // Validate order status
            if (strtolower($order['status']) !== 'picked') {
                throw new Exception('AWB can only be generated for picked orders', 400);
            }
            
            // Check if AWB already exists
            if (!empty($order['awb_barcode'])) {
                throw new Exception('AWB already exists: ' . $order['awb_barcode'], 400);
            }
            
            // Validate required AWB data
            $this->validateAWBData($order);
            
            // Generate AWB via Cargus
            $result = $this->cargusService->generateAWB($order);

            if (!$result['success']) {
                $code = $result['code'] ?? 500;
                throw new Exception('Cargus API error: ' . $result['error'], $code);
            }
            
            // Update order with AWB info
            $this->orderModel->updateAWBInfo($orderId, [
                'awb_barcode' => $result['barcode'],
                'awb_created_at' => date('Y-m-d H:i:s'),
                'cargus_order_id' => $result['parcelCodes'][0] ?? '',
                'updated_by' => $_SESSION['user_id']
            ]);
            
            // Log the action
            $this->logAction('awb_generated', $orderId, $result['barcode']);
            
            $this->success([
                'order_id' => $orderId,
                'awb_barcode' => $result['barcode'],
                'message' => 'AWB generated successfully'
            ]);
            
        } catch (Exception $e) {
            $raw = isset($result) && isset($result['raw']) ? $result['raw'] : '';
            $this->logAction('awb_generation_failed', $orderId, $e->getMessage() . ($raw ? ' | ' . $raw : ''));
            $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }
    
    private function validateAWBData($order) {
        $required = [
            'recipient_county_id' => 'Recipient county',
            'recipient_locality_id' => 'Recipient locality',
            'recipient_phone' => 'Recipient phone',
            'total_weight' => 'Total weight'
        ];
        
        $missing = [];
        foreach ($required as $field => $label) {
            if (empty($order[$field])) {
                $missing[] = $label;
            }
        }
        
        if (!empty($missing)) {
            throw new Exception('Missing required data: ' . implode(', ', $missing), 400);
        }
        
        if ($order['total_weight'] <= 0) {
            throw new Exception('Total weight must be greater than 0', 400);
        }
        
        if (!preg_match('/^[0-9\s\-\(\)]{10,15}$/', $order['recipient_phone'])) {
            throw new Exception('Invalid phone number format', 400);
        }
        
        if (isset($order['envelopes_count']) && $order['envelopes_count'] > 9) {
            throw new Exception('Maximum 9 envelopes allowed', 400);
        }
    }
    
    private function logAction($action, $orderId, $details) {
        $logData = [
            'user_id' => $_SESSION['user_id'],
            'action' => $action,
            'order_id' => $orderId,
            'details' => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        error_log('AWB Action: ' . json_encode($logData));
    }
    
    private function success($data) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ]);
        exit;
    }
    
    private function error($message, $code = 500) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => date('c')
        ]);
        exit;
    }
}