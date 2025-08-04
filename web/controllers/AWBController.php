<?php
/**
 * AWB Controller - Production Ready
 * File: web/controllers/AWBController.php
 * Handles AWB generation for authenticated users
 */

require_once BASE_PATH . '/utils/Phone.php';
use Utils\Phone;

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
            
            // Check if AWB already exists
            if (!empty($order['awb_barcode'])) {
                throw new Exception('AWB already exists: ' . $order['awb_barcode'], 400);
            }
            
            // Validate required AWB data
            $this->validateAWBData($order);
            
            // Generate AWB via Cargus
            $result = $this->cargusService->generateAWB($order);

            if (!$result['success']) {
                $code = isset($result['code']) && is_numeric($result['code']) ? (int)$result['code'] : 500;
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
            // Log the original error
            error_log("AWB Generation Error for Order $orderId: " . $e->getMessage());
            
            // Convert database errors to appropriate HTTP codes
            $httpCode = $this->getHttpCodeFromException($e);
            $message = $this->getUserFriendlyMessage($e);
            
            $this->logAction('awb_generation_failed', $orderId, $e->getMessage());
            $this->error($message, $httpCode);
        }
    }
    
    /**
     * Convert exception codes to proper HTTP status codes
     */
    private function getHttpCodeFromException($e) {
        $code = $e->getCode();
        
        // Handle database errors (SQLSTATE codes are strings)
        if (is_string($code)) {
            switch ($code) {
                case '42S22': // Column doesn't exist
                case '42S02': // Table doesn't exist
                    return 500; // Internal server error
                case '23000': // Integrity constraint violation
                    return 400; // Bad request
                default:
                    return 500; // Generic database error
            }
        }
        
        // Handle HTTP codes
        if (is_numeric($code) && $code >= 100 && $code <= 599) {
            return (int)$code;
        }
        
        // Default fallback
        return 500;
    }
    
    /**
     * Convert technical errors to user-friendly messages
     */
    private function getUserFriendlyMessage($e) {
        $message = $e->getMessage();
        $code = $e->getCode();
        
        // Handle specific database errors
        if (is_string($code)) {
            switch ($code) {
                case '42S22':
                    if (strpos($message, 'recipient_postal') !== false) {
                        return 'Database error: Missing postal code field. Please contact administrator.';
                    }
                    return 'Database error: Missing required field. Please contact administrator.';
                case '42S02':
                    return 'Database error: Missing table. Please contact administrator.';
                case '23000':
                    return 'Data validation error. Please check your input.';
            }
        }
        
        // Return original message for other errors
        return $message;
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
        
        // Check for postal code (might be NULL if column doesn't exist)
        if (!isset($order['recipient_postal']) || empty($order['recipient_postal'])) {
            $missing[] = 'Recipient postal code';
        }
        
        if (!empty($missing)) {
            throw new Exception('Missing required data: ' . implode(', ', $missing), 400);
        }
        
        if ($order['total_weight'] <= 0) {
            throw new Exception('Total weight must be greater than 0', 400);
        }
        
        // Normalize phone to local format before validating
        $normalizedPhone = Phone::toLocal($order['recipient_phone']);
        if (!preg_match('/^[0-9\s\-\(\)]{10,15}$/', $normalizedPhone)) {
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
        // Ensure $code is always an integer
        $httpCode = is_numeric($code) && $code >= 100 && $code <= 599 ? (int)$code : 500;
        
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => date('c')
        ]);
        exit;
    }
}