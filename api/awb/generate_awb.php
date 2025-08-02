<?php
// File: api/awb/generate_awb.php
// AWB generation API endpoint

ob_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/config/config.php';

function respond($data, int $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Session and authentication check
$allowedRoles = ['admin', 'warehouse', 'warehouse_worker'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    respond(['success' => false, 'error' => 'Access denied'], 403);
}

// CSRF protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        respond(['success' => false, 'error' => 'Invalid CSRF token'], 403);
    }
}

try {
    $config = require BASE_PATH . '/config/config.php';
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        respond(['success' => false, 'error' => 'Database configuration error'], 500);
    }
    $db = $config['connection_factory']();
    
    // Include required models
    require_once BASE_PATH . '/models/Order.php';
    require_once BASE_PATH . '/models/CargusService.php';
    
    $orderModel = new Order($db);
    $cargusService = new CargusService($db);
    
    // Handle different actions
    $action = $_REQUEST['action'] ?? 'generate';
    
    switch ($action) {
        case 'generate':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                respond(['success' => false, 'error' => 'Method not allowed'], 405);
            }
            
            $orderId = intval($_POST['order_id'] ?? 0);
            if ($orderId <= 0) {
                respond(['success' => false, 'error' => 'Valid order ID required'], 400);
            }
            
            // Get order data
            $order = $orderModel->getOrderById($orderId);
            if (!$order) {
                respond(['success' => false, 'error' => 'Order not found'], 404);
            }
            
            // Check if AWB already exists
            if (!empty($order['awb_barcode'])) {
                respond([
                    'success' => false,
                    'error' => 'AWB already generated for this order',
                    'existing_barcode' => $order['awb_barcode']
                ], 400);
            }
            
            // Apply manual overrides if provided
            $manualData = [];
            $manualFields = [
                'recipient_name', 'recipient_contact_person', 'recipient_phone', 'recipient_email',
                'recipient_county_id', 'recipient_locality_id', 'recipient_county_name', 'recipient_locality_name',
                'shipping_address', 'address_text', 'recipient_postal', 'total_weight', 'declared_value', 'parcels_count', 'envelopes_count',
                'package_content', 'cash_repayment', 'bank_repayment', 'saturday_delivery',
                'morning_delivery', 'open_package', 'observations', 'recipient_reference1', 'recipient_reference2'
            ];
            
            foreach ($manualFields as $field) {
                if (isset($_POST[$field]) && $_POST[$field] !== '') {
                    $value = $_POST[$field];
                    
                    // Type casting for specific fields
                    if (in_array($field, ['recipient_county_id', 'recipient_locality_id', 'parcels_count', 'envelopes_count'])) {
                        $value = intval($value);
                    } elseif (in_array($field, ['total_weight', 'declared_value', 'cash_repayment', 'bank_repayment'])) {
                        $value = floatval($value);
                    } elseif (in_array($field, ['saturday_delivery', 'morning_delivery', 'open_package'])) {
                        $value = boolval($value);
                    }
                    
                    $manualData[$field] = $value;
                }
            }
            
            // Apply manual overrides to order data
            $order = array_merge($order, $manualData);
            
            // Generate AWB
            $result = $cargusService->generateAWB($order);
            
            if ($result['success']) {
                // Update order with AWB data
                $orderModel->updateOrderField($orderId, [
                    'awb_barcode' => $result['barcode'],
                    'awb_created_at' => date('Y-m-d H:i:s'),
                    'cargus_order_id' => $result['cargusOrderId'] ?? null,
                    'status' => 'ready_to_ship',
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
                // Log successful generation
                error_log("AWB generated successfully for order $orderId: {$result['barcode']} by user {$_SESSION['user_id']}");
                
                respond([
                    'success' => true,
                    'message' => 'AWB generat cu succes',
                    'barcode' => $result['barcode'],
                    'parcel_codes' => $result['parcelCodes']
                ]);
            } else {
                // Log failed generation
                error_log("AWB generation failed for order $orderId: {$result['error']} by user {$_SESSION['user_id']}");
                
                $responseCode = 400;
                if (isset($result['require_manual_input']) && $result['require_manual_input']) {
                    $responseCode = 422; // Unprocessable Entity - needs more data
                } elseif (isset($result['code'])) {
                    $responseCode = $result['code'];
                }
                
                respond([
                    'success' => false,
                    'error' => $result['error'],
                    'require_manual_input' => $result['require_manual_input'] ?? false,
                    'parsed_address' => $result['parsed_address'] ?? null,
                    'raw' => $result['raw'] ?? null
                ], $responseCode);
            }
            break;
            
        case 'requirements':
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                respond(['success' => false, 'error' => 'Method not allowed'], 405);
            }
            
            $orderId = intval($_REQUEST['order_id'] ?? 0);
            if ($orderId <= 0) {
                respond(['success' => false, 'error' => 'Valid order ID required'], 400);
            }
            
            $order = $orderModel->getOrderById($orderId);
            if (!$order) {
                respond(['success' => false, 'error' => 'Order not found'], 404);
            }
            
            if (!empty($order['awb_barcode'])) {
                respond([
                    'can_generate' => false,
                    'error' => 'AWB already generated',
                    'existing_barcode' => $order['awb_barcode']
                ]);
                return;
            }
            
            $requirements = [];
            $canGenerate = true;
            
            // Check required fields
            if (empty($order['customer_name'])) {
                $requirements[] = 'Customer name required';
                $canGenerate = false;
            }
            
            if (empty($order['shipping_address'])) {
                $requirements[] = 'Shipping address required';
                $canGenerate = false;
            }

            if (empty($order['recipient_phone'])) {
                $requirements[] = 'Recipient phone required';
                $canGenerate = false;
            }

            if (empty($order['recipient_postal'])) {
                $requirements[] = 'Recipient postal code required';
                $canGenerate = false;
            }
            
            // Check weight calculation
            try {
                $calculatedData = $cargusService->calculateOrderShipping($order);
                $weightInfo = [
                    'weight' => $calculatedData['total_weight'],
                    'source' => 'calculated'
                ];
                
                if ($calculatedData['total_weight'] <= 0) {
                    $requirements[] = 'Order weight required (manual input needed)';
                    $canGenerate = false;
                }
            } catch (Exception $e) {
                $weightInfo = [
                    'weight' => 0,
                    'source' => 'error',
                    'error' => 'Weight calculation failed'
                ];
                $requirements[] = 'Weight calculation failed (manual input needed)';
                $canGenerate = false;
            }
            
            // Check address mapping
            $addressParsed = null;
            if (empty($order['recipient_county_id']) || empty($order['recipient_locality_id'])) {
                // Try to parse address
                $reflection = new ReflectionClass($cargusService);
                $parseMethod = $reflection->getMethod('parseShippingAddress');
                $parseMethod->setAccessible(true);
                $addressParsed = $parseMethod->invoke($cargusService, $order['shipping_address']);
                
                if (empty($addressParsed['county']) || empty($addressParsed['locality'])) {
                    $requirements[] = 'Cannot determine delivery location from address';
                    $canGenerate = false;
                } else {
                    // Check if mapping exists
                    $findMethod = $reflection->getMethod('findAddressMapping');
                    $findMethod->setAccessible(true);
                    $mapping = $findMethod->invoke($cargusService, $addressParsed['county'], $addressParsed['locality']);
                    
                    if (!$mapping) {
                        $requirements[] = 'Cargus location mapping not found (manual input needed)';
                        $canGenerate = false;
                    }
                }
            }
            
            respond([
                'can_generate' => $canGenerate,
                'requirements' => $requirements,
                'weight_info' => $weightInfo,
                'address_parsed' => $addressParsed,
                'order_data' => $order
            ]);
            break;
            
        default:
            respond(['success' => false, 'error' => 'Unknown action'], 400);
    }
    
} catch (Exception $e) {
    error_log("AWB generation API error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    respond(['success' => false, 'error' => 'Internal server error'], 500);
}
?>