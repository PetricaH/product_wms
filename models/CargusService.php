<?php
/**
 * Complete CargusService Class - Production Ready
 * File: models/CargusService.php
 * 
 * Handles complete Cargus API integration with:
 * - Authentication and token management
 * - AWB generation with proper field mapping
 * - Weight and parcels calculation
 * - Error handling and logging
 * - Configuration management
 */

class CargusService 
{
    private $apiUrl;
    private $subscriptionKey;
    private $username;
    private $password;
    private $token;
    private $tokenExpiry;
    private $config;
    private $conn;
    
    public function __construct($conn = null) {
        $this->conn = $conn ?: $this->getConnection();
        $this->loadConfiguration();
        $this->loadCachedToken();
    }
    
    /**
     * Get database connection
     */
    private function getConnection() {
        if (!$this->conn) {
            $config = require BASE_PATH . '/config/config.php';
            $this->conn = $config['connection_factory']();
        }
        return $this->conn;
    }
    
    /**
     * Load configuration from database
     */
    private function loadConfiguration() {
        $stmt = $this->conn->prepare("SELECT setting_key, setting_value, setting_type FROM cargus_config WHERE active = 1");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->config = [];
        foreach ($settings as $setting) {
            $value = $setting['setting_value'];
            
            // Convert based on type
            switch ($setting['setting_type']) {
                case 'integer':
                    $value = (int)$value;
                    break;
                case 'decimal':
                    $value = (float)$value;
                    break;
                case 'boolean':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'json':
                    $value = json_decode($value, true);
                    break;
            }
            
            $this->config[$setting['setting_key']] = $value;
        }
        
        // Set API properties
        $this->apiUrl = $this->config['api_url'] ?? 'https://urgentcargus.portal.azure-api.net/';
        $this->subscriptionKey = $this->config['subscription_key'] ?? '';
        $this->username = $this->config['username'] ?? '';
        $this->password = $this->config['password'] ?? '';
    }
    
    /**
     * Load cached token if valid
     */
    private function loadCachedToken() {
        $cacheFile = sys_get_temp_dir() . '/cargus_token_' . md5($this->username);
        
        if (file_exists($cacheFile)) {
            $tokenData = json_decode(file_get_contents($cacheFile), true);
            if ($tokenData && $tokenData['expiry'] > time()) {
                $this->token = $tokenData['token'];
                $this->tokenExpiry = $tokenData['expiry'];
            }
        }
    }
    
    /**
     * Save token to cache
     */
    private function saveTokenToCache() {
        $cacheFile = sys_get_temp_dir() . '/cargus_token_' . md5($this->username);
        $tokenData = [
            'token' => $this->token,
            'expiry' => $this->tokenExpiry
        ];
        file_put_contents($cacheFile, json_encode($tokenData));
    }
    
    /**
     * Authenticate and get token
     */
    private function authenticate() {
        if ($this->token && $this->tokenExpiry > time()) {
            return true;
        }
        
        $loginData = [
            'UserName' => $this->username,
            'Password' => $this->password
        ];
        
        $response = $this->makeRequest('POST', 'LoginUser', $loginData, false);
        
        if (!$response['success']) {
            $this->logError('Authentication failed', $response['error'], $response['raw']);
            return false;
        }
        
        $this->token = trim($response['data'], '"'); // Remove quotes from response
        $cacheHours = $this->config['token_cache_duration'] ?? 23;
        $this->tokenExpiry = time() + ($cacheHours * 3600);
        
        $this->saveTokenToCache();
        
        $this->logInfo('Authentication successful', ['token_length' => strlen($this->token)]);
        return true;
    }
    
    /**
     * Main method to generate AWB
     */
    public function generateAWB($order) {
        try {
            // Authenticate first
            if (!$this->authenticate()) {
                return [
                    'success' => false,
                    'error' => 'Authentication failed',
                    'code' => 401
                ];
            }
            
            // Calculate order weights and parcels
            $calculatedData = $this->calculateOrderShipping($order);
            
            // Get sender location
            $senderLocation = $this->getSenderLocation();
            if (!$senderLocation) {
                return [
                    'success' => false,
                    'error' => 'No sender location configured',
                    'code' => 500
                ];
            }
            
            // Build AWB data
            $awbData = $this->buildAWBData($order, $calculatedData, $senderLocation);
            
            // Validate AWB data
            $validation = $this->validateAWBData($awbData);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'error' => 'Validation failed: ' . implode(', ', $validation['errors']),
                    'code' => 400
                ];
            }
            
            // Send to Cargus API
            $response = $this->makeRequest('POST', 'Awbs', $awbData);
            
            if ($response['success']) {
                $this->logInfo('AWB generated successfully', [
                    'order_id' => $order['id'],
                    'barcode' => $response['data']['BarCode'] ?? 'unknown'
                ]);
                
                return [
                    'success' => true,
                    'barcode' => $response['data']['BarCode'] ?? '',
                    'parcelCodes' => $response['data']['ParcelCodes'] ?? [],
                    'cargusOrderId' => $response['data']['OrderId'] ?? ''
                ];
            }
            
            $this->logError('AWB generation failed', $response['error'], $response['raw']);
            return [
                'success' => false,
                'error' => $response['error'],
                'code' => $response['code'] ?? 500,
                'raw' => $response['raw'] ?? null
            ];
            
        } catch (Exception $e) {
            $this->logError('AWB generation exception', $e->getMessage(), $e->getTraceAsString());
            return [
                'success' => false,
                'error' => 'Internal error: ' . $e->getMessage(),
                'code' => 500
            ];
        }
    }
    
    /**
     * Calculate order weight and parcels based on products
     */
    private function calculateOrderShipping($order) {
        // Get order items with product unit data
        $stmt = $this->conn->prepare("
            SELECT 
                oi.quantity,
                oi.unit_measure,
                p.name as product_name,
                pu.weight_per_unit,
                pu.volume_per_unit,
                pu.fragile,
                pu.hazardous,
                ut.packaging_type,
                ut.requires_separate_parcel,
                ut.max_items_per_parcel
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            LEFT JOIN product_units pu ON p.id = pu.product_id 
            LEFT JOIN unit_types ut ON pu.unit_type_id = ut.id AND ut.unit_code = oi.unit_measure
            WHERE oi.order_id = ?
        ");
        $stmt->execute([$order['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totalWeight = 0;
        $parcels = [];
        $currentParcel = 1;
        $currentParcelWeight = 0;
        $currentParcelItems = 0;
        $packageContent = [];
        
        foreach ($items as $item) {
            $quantity = $item['quantity'];
            $unitWeight = $item['weight_per_unit'] ?? $this->getDefaultWeight($item['unit_measure']);
            $itemTotalWeight = $quantity * $unitWeight;
            $totalWeight += $itemTotalWeight;
            
            $packageContent[] = $item['product_name'] . ' (' . $quantity . ' ' . $item['unit_measure'] . ')';
            
            // Check if item requires separate parcel (like liquids)
            if ($item['requires_separate_parcel'] || $item['packaging_type'] === 'liquid') {
                // Each liquid item gets its own parcel
                for ($i = 0; $i < $quantity; $i++) {
                    $parcels[] = [
                        'weight' => $unitWeight,
                        'items' => 1,
                        'type' => $item['packaging_type']
                    ];
                }
                continue;
            }
            
            // Check parcel weight limits
            $maxWeight = 25.0; // Default max weight per parcel
            $maxItems = $item['max_items_per_parcel'] ?? 10;
            
            $itemsToAdd = $quantity;
            while ($itemsToAdd > 0) {
                $itemsForThisParcel = min($itemsToAdd, $maxItems - $currentParcelItems);
                $weightForThisParcel = $itemsForThisParcel * $unitWeight;
                
                if ($currentParcelWeight + $weightForThisParcel > $maxWeight && $currentParcelItems > 0) {
                    // Close current parcel and start new one
                    $parcels[] = [
                        'weight' => $currentParcelWeight,
                        'items' => $currentParcelItems,
                        'type' => 'mixed'
                    ];
                    $currentParcel++;
                    $currentParcelWeight = 0;
                    $currentParcelItems = 0;
                    continue;
                }
                
                $currentParcelWeight += $weightForThisParcel;
                $currentParcelItems += $itemsForThisParcel;
                $itemsToAdd -= $itemsForThisParcel;
            }
        }
        
        // Close last parcel if it has items
        if ($currentParcelItems > 0) {
            $parcels[] = [
                'weight' => $currentParcelWeight,
                'items' => $currentParcelItems,
                'type' => 'mixed'
            ];
        }
        
        // If no calculated parcels, default to 1
        if (empty($parcels)) {
            $parcels[] = [
                'weight' => max($totalWeight, 0.1), // Minimum 100g
                'items' => 1,
                'type' => 'default'
            ];
        }
        
        return [
            'total_weight' => max($totalWeight, 0.1), // Minimum 100g
            'parcels_count' => count($parcels),
            'parcels_detail' => $parcels,
            'package_content' => implode(', ', $packageContent)
        ];
    }
    
    /**
     * Get default weight for unit type
     */
    private function getDefaultWeight($unitMeasure) {
        $defaults = [
            'litri' => 1.0,
            'buc' => 0.5,
            'cartus' => 0.2,
            'kg' => 1.0,
            'ml' => 0.001,
            'gr' => 0.001,
            'set' => 1.5,
            'cutie' => 0.8
        ];
        
        return $defaults[$unitMeasure] ?? 0.5; // Default 500g
    }
    
    /**
     * Get sender location configuration
     */
    private function getSenderLocation() {
        $defaultLocationId = $this->config['default_sender_location_id'] ?? 1;
        
        $stmt = $this->conn->prepare("
            SELECT * FROM sender_locations 
            WHERE id = ? AND active = 1
        ");
        $stmt->execute([$defaultLocationId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Build complete AWB data for Cargus API
     */
    private function buildAWBData($order, $calculatedData, $senderLocation) {
        return [
            'Sender' => [
                'SenderClientId' => null,
                'TertiaryClientId' => null,
                'LocationId' => $senderLocation['cargus_location_id'],
                'Name' => $senderLocation['company_name'],
                'CountyId' => $senderLocation['county_id'],
                'LocalityId' => $senderLocation['locality_id'],
                'StreetId' => $senderLocation['street_id'],
                'BuildingNumber' => $senderLocation['building_number'],
                'AddressText' => $senderLocation['address_text'],
                'ContactPerson' => $senderLocation['contact_person'],
                'PhoneNumber' => $senderLocation['phone'],
                'Email' => $senderLocation['email']
            ],
            'Recipient' => [
                'Name' => $order['recipient_name'] ?: $order['customer_name'],
                'CountyId' => $order['recipient_county_id'],
                'CountyName' => '', // Optional - can be fetched if needed
                'LocalityId' => $order['recipient_locality_id'],
                'LocalityName' => '', // Optional
                'StreetId' => $order['recipient_street_id'],
                'StreetName' => '', // Optional
                'BuildingNumber' => $order['recipient_building_number'] ?: 'N/A',
                'AddressText' => $order['recipient_address'],
                'ContactPerson' => $order['recipient_contact_person'] ?: $order['customer_name'],
                'PhoneNumber' => $order['recipient_phone'],
                'Email' => $order['recipient_email'] ?: ''
            ],
            'Parcels' => $calculatedData['parcels_count'],
            'Envelopes' => $order['envelopes_count'] ?? 0,
            'TotalWeight' => $calculatedData['total_weight'],
            'DeclaredValue' => $order['declared_value'] ?? $order['total_value'] ?? 0,
            'CashRepayment' => $order['cash_repayment'] ?? 0,
            'BankRepayment' => $order['bank_repayment'] ?? 0,
            'OtherRepayment' => 0,
            'OpenPackage' => $order['open_package'] ?? false,
            'SaturdayDelivery' => $order['saturday_delivery'] ?? false,
            'MorningDelivery' => $order['morning_delivery'] ?? false,
            'Observations' => $order['observations'] ?: 'Comandă generată automat',
            'PackageContent' => $calculatedData['package_content'] ?: 'Produse diverse',
            'CustomString' => $order['order_number'] ?: '',
            'RecipientReference1' => $order['recipient_reference1'] ?: '',
            'RecipientReference2' => $order['recipient_reference2'] ?: '',
            'SenderReference1' => $order['sender_reference1'] ?: $order['order_number'],
            'InvoiceReference' => $order['invoice_reference'] ?: $order['invoice_number'] ?: '',
            'ServiceId' => $this->config['default_service_id'] ?? 34
        ];
    }
    
    /**
     * Validate AWB data before sending to API
     */
    private function validateAWBData($awbData) {
        $errors = [];
        
        // Required sender fields
        if (empty($awbData['Sender']['Name'])) $errors[] = 'Sender name required';
        if (empty($awbData['Sender']['CountyId'])) $errors[] = 'Sender county required';
        if (empty($awbData['Sender']['LocalityId'])) $errors[] = 'Sender locality required';
        if (empty($awbData['Sender']['PhoneNumber'])) $errors[] = 'Sender phone required';
        
        // Required recipient fields
        if (empty($awbData['Recipient']['Name'])) $errors[] = 'Recipient name required';
        if (empty($awbData['Recipient']['CountyId'])) $errors[] = 'Recipient county required';
        if (empty($awbData['Recipient']['LocalityId'])) $errors[] = 'Recipient locality required';
        if (empty($awbData['Recipient']['PhoneNumber'])) $errors[] = 'Recipient phone required';
        
        // Weight and count validation
        if ($awbData['TotalWeight'] <= 0) $errors[] = 'Weight must be greater than 0';
        if ($awbData['Parcels'] <= 0) $errors[] = 'Must have at least 1 parcel';
        if ($awbData['Envelopes'] > 9) $errors[] = 'Maximum 9 envelopes allowed';
        
        // Phone validation
        $phonePattern = '/^[0-9\s\-\(\)]{10,15}$/';
        if (!preg_match($phonePattern, $awbData['Sender']['PhoneNumber'])) {
            $errors[] = 'Invalid sender phone format';
        }
        if (!preg_match($phonePattern, $awbData['Recipient']['PhoneNumber'])) {
            $errors[] = 'Invalid recipient phone format';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Make HTTP request to Cargus API
     */
    private function makeRequest($method, $endpoint, $data = null, $requireAuth = true) {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');
        
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json'
        ];
        
        if (!empty($this->subscriptionKey)) {
            $headers[] = 'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey;
        }
        
        if ($requireAuth && $this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3
        ]);
        
        if ($data !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        if ($error) {
            return [
                'success' => false,
                'error' => 'CURL error: ' . $error,
                'code' => 0,
                'raw' => null
            ];
        }
        
        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'data' => $decodedResponse,
                'code' => $httpCode,
                'raw' => $response
            ];
        }
        
        $errorMessage = 'HTTP ' . $httpCode;
        if ($decodedResponse && isset($decodedResponse['message'])) {
            $errorMessage .= ': ' . $decodedResponse['message'];
        } elseif ($decodedResponse && isset($decodedResponse['error'])) {
            $errorMessage .= ': ' . $decodedResponse['error'];
        }
        
        return [
            'success' => false,
            'error' => $errorMessage,
            'code' => $httpCode,
            'raw' => $response
        ];
    }
    
    /**
     * Log information message
     */
    private function logInfo($message, $context = []) {
        $logMessage = '[CargusService] ' . $message;
        if (!empty($context)) {
            $logMessage .= ' | Context: ' . json_encode($context);
        }
        error_log($logMessage);
    }
    
    /**
     * Log error message
     */
    private function logError($message, $error = '', $raw = '') {
        $logMessage = '[CargusService ERROR] ' . $message;
        if ($error) {
            $logMessage .= ' | Error: ' . $error;
        }
        if ($raw) {
            $logMessage .= ' | Raw: ' . substr($raw, 0, 500);
        }
        error_log($logMessage);
    }
    
    /**
     * Get localities for a county
     */
    public function getLocalities($countyId) {
        if (!$this->authenticate()) {
            return ['success' => false, 'error' => 'Authentication failed'];
        }
        
        return $this->makeRequest('GET', "Localities?countyId={$countyId}");
    }
    
    /**
     * Get streets for a locality
     */
    public function getStreets($localityId) {
        if (!$this->authenticate()) {
            return ['success' => false, 'error' => 'Authentication failed'];
        }
        
        return $this->makeRequest('GET', "Streets?localityId={$localityId}");
    }
    
    /**
     * Verify token
     */
    public function verifyToken() {
        if (!$this->token) {
            return false;
        }
        
        $response = $this->makeRequest('GET', 'TokenVerification');
        return $response['success'] && $response['data'] === true;
    }
}