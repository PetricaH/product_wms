<?php
/**
 * Updated CargusService Class - Environment Variables Version
 * File: models/CargusService.php
 * 
 * Handles complete Cargus API integration with:
 * - Environment-based configuration (secure)
 * - Authentication and token management  
 * - AWB generation with proper field mapping
 * - Weight and parcels calculation
 * - Error handling and logging
 * - Address mapping integration
 */

require_once BASE_PATH . '/utils/Phone.php';
use Utils\Phone;

ini_set('log_errors', 1);
ini_set('error_log', '/var/www/notsowms.ro/logs/php_debug.log');

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
    private $orderModel;

    // Add this simple debug method
    private function debugLog($message) {
        $logFile = '/var/www/notsowms.ro/logs/cargus_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND | LOCK_EX);
        
        // Also use error_log for backup
        error_log("CARGUS: {$message}");
    }
    
    public function __construct($conn = null) {
        $this->conn = $conn ?: $this->getConnection();
        $this->loadEnvironmentConfiguration();
        $this->loadCachedToken();

        require_once BASE_PATH . '/models/Order.php';
        $this->orderModel = new Order($this->conn);
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
     * Load configuration from environment variables (secure approach)
     */
    private function loadEnvironmentConfiguration() {
        // Load from environment variables first (secure)
        $this->apiUrl = $_ENV['CARGUS_API_URL'] ?? getenv('CARGUS_API_URL') ?? 'https://urgentcargus.azure-api.net/api/';
        $this->subscriptionKey = $_ENV['CARGUS_SUBSCRIPTION_KEY'] ?? getenv('CARGUS_SUBSCRIPTION_KEY');
        $this->username = $_ENV['CARGUS_USER'] ?? getenv('CARGUS_USER');
        $this->password = $_ENV['CARGUS_PASS'] ?? getenv('CARGUS_PASS');
        
        // Validate required environment variables
        if (empty($this->subscriptionKey) || empty($this->username) || empty($this->password)) {
            // Fallback to database configuration if env vars not set
            $this->loadDatabaseConfiguration();
        }
        
        // Load additional configuration
        $this->config = [
            'sender_location_id' => $_ENV['CARGUS_SENDER_LOCATION_ID'] ?? getenv('CARGUS_SENDER_LOCATION_ID') ?? 201484643,
            'default_service_id' => 34,
            'cache_hours' => 23,
            'debug' => ($_ENV['APP_ENV'] ?? getenv('APP_ENV')) === 'development'
        ];
    }
    
    /**
     * Fallback: Load configuration from database (legacy support)
     */
    private function loadDatabaseConfiguration() {
        try {
            $stmt = $this->conn->prepare("SELECT setting_key, setting_value, setting_type FROM cargus_config WHERE active = 1");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
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
                
                // Map database settings to class properties
                switch ($setting['setting_key']) {
                    case 'api_url':
                        $this->apiUrl = $value;
                        break;
                    case 'subscription_key':
                        $this->subscriptionKey = $value;
                        break;
                    case 'username':
                        $this->username = $value;
                        break;
                    case 'password':
                        $this->password = $value;
                        break;
                    default:
                        $this->config[$setting['setting_key']] = $value;
                        break;
                }
            }
        } catch (Exception $e) {
            error_log("Failed to load Cargus database configuration: " . $e->getMessage());
            throw new Exception("Cargus configuration not available. Please check environment variables or database settings.");
        }
    }
    
    /**
     * Load cached token if valid
     */
    private function loadCachedToken() {
        $cacheFile = sys_get_temp_dir() . '/cargus_token_' . md5($this->username) . '.cache';
        
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            
            if ($cached && isset($cached['token'], $cached['expiry']) && $cached['expiry'] > time()) {
                $this->token = $cached['token'];
                $this->tokenExpiry = $cached['expiry'];
                
                if ($this->config['debug']) {
                    $this->logInfo('Using cached token', ['expires_at' => date('Y-m-d H:i:s', $this->tokenExpiry)]);
                }
            }
        }
    }
    
    /**
     * Save token to cache
     */
    private function saveTokenToCache() {
        $cacheFile = sys_get_temp_dir() . '/cargus_token_' . md5($this->username) . '.cache';
        
        $cacheData = [
            'token' => $this->token,
            'expiry' => $this->tokenExpiry
        ];
        
        file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);
    }
    
    /**
     * Authenticate with Cargus API
     */
    private function authenticate() {
        if ($this->token && $this->tokenExpiry && $this->tokenExpiry > time() + 300) {
            return true; // Token valid for at least 5 more minutes
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
        
        $this->token = trim($response['data'], '"');
        $cacheHours = $this->config['cache_hours'] ?? 23;
        $this->tokenExpiry = time() + ($cacheHours * 3600);
        
        $this->saveTokenToCache();
        
        if ($this->config['debug']) {
            $this->logInfo('Authentication successful', ['token_length' => strlen($this->token)]);
        }
        
        return true;
    }
    
    /**
     * Verify token is still valid
     */
    public function verifyToken() {
        if (!$this->token) {
            return $this->authenticate();
        }
        
        // Simple verification - try to make a lightweight request
        $response = $this->makeRequest('GET', 'TokenVerification', null, true);
        
        if ($response['success']) {
            return true;
        }
        
        // Token expired, try to re-authenticate
        if ($response['code'] === 401) {
            $this->token = null;
            $this->tokenExpiry = 0;
            return $this->authenticate();
        }
        
        return false;
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

            // Map recipient address if needed
            $addressMapping = $this->mapRecipientAddress($order);
            if (!$addressMapping['success']) {
                return [
                    'success' => false,
                    'error' => $addressMapping['error'],
                    'code' => 400,
                    'require_manual_input' => true,
                    'parsed_address' => $addressMapping['parsed_address'] ?? null
                ];
            }

            // Merge address mapping data
            $order = array_merge($order, $addressMapping['mapped_data']);

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
    $data = $response['data'] ?? [];

    // Try to extract barcode with fallbacks
    $barcode = '';
    if (!empty($data['BarCode'])) {
        $barcode = $data['BarCode'];
    } elseif (!empty($data['Barcode'])) {
        $barcode = $data['Barcode'];
    } elseif (!empty($data['ParcelCodes'][0]['Code'])) {
        // last-resort: use first ParcelCode code if that's all that's present
        $barcode = $data['ParcelCodes'][0]['Code'];
    }

    // Normalize (strip quotes if accidentally returned)
    $barcode = trim($barcode, '"');

    if (empty($barcode)) {
        // Log full response for investigation
        $this->debugLog("AWB created but barcode missing. Full response: " . ($response['raw'] ?? json_encode($data)));
    }

    $this->logInfo('AWB generated successfully', [
        'order_id' => $order['id'],
        'barcode' => $barcode ?: 'MISSING'
    ]);

    return [
        'success' => true,
        'barcode' => $barcode,
        'parcelCodes' => $data['ParcelCodes'] ?? [],
        'cargusOrderId' => $data['OrderId'] ?? '',
        'raw_response' => $response['raw'] ?? null
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
     * Generate ParcelCodes array to match Parcels + Envelopes count
     */
    private function generateParcelCodes($parcelsCount, $envelopesCount, $totalWeightAPI, $calculatedData) {
    $this->debugLog("=== PARCEL CODES DEBUG START ===");
    $this->debugLog("Input - Parcels: {$parcelsCount}, Envelopes: {$envelopesCount}, Total API Weight: {$totalWeightAPI}");

    $parcelCodes = [];
    $parcelDetails = $calculatedData['parcels_detail'] ?? [];

    // Parcels (Type = 1)
    if ($parcelsCount > 0) {
        $weightPerParcel = $parcelsCount > 0 ? ($totalWeightAPI / $parcelsCount) : 0;
        for ($i = 0; $i < $parcelsCount; $i++) {
            $detail = $parcelDetails[$i] ?? [];

            $thisParcelWeight = $detail['weight'] ?? $weightPerParcel;
            if ($i == $parcelsCount - 1 && !isset($detail['weight'])) {
                $totalAssigned = (int)$weightPerParcel * ($parcelsCount - 1);
                $thisParcelWeight = $totalWeightAPI - $totalAssigned;
            }

            $length = $detail['length'] ?? $calculatedData['package_length'] ?? 20;
            $width  = $detail['width'] ?? $calculatedData['package_width'] ?? 20;
            $height = $detail['height'] ?? $calculatedData['package_height'] ?? 20;
            $content = $detail['content'] ?? 'Colet ' . ($i + 1);

            $thisParcelWeight = max(1, (int)round($thisParcelWeight));
            $length = max(1, (int)round($length));
            $width  = max(1, (int)round($width));
            $height = max(1, (int)round($height));

            $this->debugLog("Parcel {$i}: Weight = {$thisParcelWeight} kg, L={$length}, W={$width}, H={$height}");

            $parcelCodes[] = [
                'Code' => (string)$i,
                'Type' => 1, // 1 = parcel
                'Weight' => $thisParcelWeight,
                'Length' => $length,
                'Width' => $width,
                'Height' => $height,
                'ParcelContent' => $content
            ];
        }
    }

    // Envelopes (Type = 0)
    if ($envelopesCount > 0) {
        for ($i = 0; $i < $envelopesCount; $i++) {
            $envelopeWeight = 1;
            $this->debugLog("Envelope {$i}: Weight = {$envelopeWeight} kg");
            $parcelCodes[] = [
                'Code' => (string)($parcelsCount + $i),
                'Type' => 0, // 0 = envelope
                'Weight' => $envelopeWeight,
                'Length' => 25,
                'Width' => 15,
                'Height' => 1,
                'ParcelContent' => 'Plic ' . ($i + 1)
            ];
        }
    }

    // Verification with updated type semantics
    $actualParcels = array_filter($parcelCodes, fn($p) => $p['Type'] === 1);
    $actualEnvelopes = array_filter($parcelCodes, fn($p) => $p['Type'] === 0);

    $this->debugLog("Generated parcels (Type=1): " . count($actualParcels) . " (expected: {$parcelsCount})");
    $this->debugLog("Generated envelopes (Type=0): " . count($actualEnvelopes) . " (expected: {$envelopesCount})");

    if (count($actualParcels) != $parcelsCount) {
        $this->debugLog("🚨 MISMATCH: Generated parcels != declared parcels");
    }
    if (count($actualEnvelopes) != $envelopesCount) {
        $this->debugLog("🚨 MISMATCH: Generated envelopes != declared envelopes");
    }

    $this->debugLog("Total parcel codes generated: " . count($parcelCodes));
    $this->debugLog("=== PARCEL CODES DEBUG END ===");

    return array_values($parcelCodes);
}

    /**
     * Map recipient address using address_location_mappings table
     */
    private function mapRecipientAddress($order) {
        // If we already have Cargus IDs, no need to map
        if (!empty($order['recipient_county_id']) && !empty($order['recipient_locality_id'])) {
            return [
                'success' => true,
                'mapped_data' => []
            ];
        }
        
        // Parse shipping address
        $parsedAddress = $this->parseShippingAddress($order['shipping_address'] ?? '');
        
        if (empty($parsedAddress['county']) || empty($parsedAddress['locality'])) {
            return [
                'success' => false,
                'error' => 'Cannot determine county and locality from shipping address',
                'parsed_address' => $parsedAddress
            ];
        }
        
        // Look up in address mappings table
        $mapping = $this->findAddressMapping($parsedAddress['county'], $parsedAddress['locality']);
        
        if (!$mapping) {
            // Create new mapping entry for future manual completion
            $this->createAddressMapping($parsedAddress['county'], $parsedAddress['locality']);
            
            return [
                'success' => false,
                'error' => 'Address mapping not found. Please provide Cargus county and locality IDs manually.',
                'parsed_address' => $parsedAddress
            ];
        }
        
        // Update mapping usage
        $this->updateMappingUsage($mapping['id']);
        
        return [
            'success' => true,
            'mapped_data' => [
                'recipient_county_id' => $mapping['cargus_county_id'],
                'recipient_locality_id' => $mapping['cargus_locality_id'],
                'recipient_county_name' => $mapping['cargus_county_name'],
                'recipient_locality_name' => $mapping['cargus_locality_name']
            ]
        ];
    }
    
    /**
     * Parse shipping address to extract county and locality
     */
    private function parseShippingAddress(string $address): array {
        $address = trim($address);
        $parts = [
            'county' => '',
            'locality' => '',
            'street' => '',
            'building' => ''
        ];
        
        // Common patterns for Romanian addresses
        $countyPatterns = [
            '/judeţul?\s+([^,]+)/i',
            '/jud\.?\s+([^,]+)/i',
            '/([^,]+)\s+county/i',
            '/,\s*([^,]+)\s*$/i' // Last part after comma
        ];
        
        foreach ($countyPatterns as $pattern) {
            if (preg_match($pattern, $address, $matches)) {
                $parts['county'] = trim($matches[1]);
                break;
            }
        }
        
        // Extract locality (city/town)
        $localityPatterns = [
            '/(?:municipiul|orașul|comuna|satul)\s+([^,]+)/i',
            '/^([^,]+)(?:,|\s+str\.?)/i', // First part before comma or "str"
            '/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\s*,/i' // Capitalized words before comma
        ];
        
        foreach ($localityPatterns as $pattern) {
            if (preg_match($pattern, $address, $matches)) {
                $parts['locality'] = trim($matches[1]);
                break;
            }
        }
        
        return $parts;
    }
    
    /**
     * Find address mapping in database
     */
    private function findAddressMapping(string $county, string $locality): ?array {
        $query = "
            SELECT * FROM address_location_mappings
            WHERE (
                LOWER(county_name) = LOWER(:county) 
                OR county_name LIKE CONCAT('%', :county, '%')
            )
            AND (
                LOWER(locality_name) = LOWER(:locality)
                OR locality_name LIKE CONCAT('%', :locality, '%')
            )
            AND cargus_county_id IS NOT NULL
            AND cargus_locality_id IS NOT NULL
            ORDER BY 
                mapping_confidence = 'high' DESC,
                mapping_confidence = 'medium' DESC,
                is_verified DESC,
                usage_count DESC
            LIMIT 1
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':county' => $county,
            ':locality' => $locality
        ]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Create new address mapping for future completion
     */
    private function createAddressMapping(string $county, string $locality): void {
        $query = "
            INSERT IGNORE INTO address_location_mappings 
            (county_name, locality_name, mapping_confidence, created_at, updated_at)
            VALUES (?, ?, 'low', NOW(), NOW())
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$county, $locality]);
    }
    
    /**
     * Update mapping usage statistics
     */
    private function updateMappingUsage(int $mappingId): void {
        $query = "
            UPDATE address_location_mappings 
            SET usage_count = usage_count + 1, last_used_at = NOW()
            WHERE id = ?
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$mappingId]);
    }
    
    /**
     * Calculate shipping data for order
     */
    public function calculateOrderShipping($order) {
        $weightCalculator = new WeightCalculator($this->conn);
        return $weightCalculator->calculateOrderShipping($order['id']);
    }

    /**
     * Retrieve postal code for a locality
     * Uses Cargus Localities endpoint to find CodPostal
     */
    public function getPostalCode($countyId, $localityId) {
        if (empty($countyId) || empty($localityId)) {
            return null;
        }
    
        if (!$this->authenticate()) {
            echo "❌ Authentication failed\n";
            return null;
        }
    
        $endpoint = sprintf('Localities?countryId=1&countyId=%d', intval($countyId));
        $response = $this->makeRequest('GET', $endpoint, null, true);
    
        if (!$response['success']) {
            return null;
        }
        
        if (is_array($response['data'])) {
            echo "Array count: " . count($response['data']) . "\n";
            if (!empty($response['data'])) {
                echo "First locality structure: " . json_encode($response['data'][0], JSON_PRETTY_PRINT) . "\n";
                
                for ($i = 0; $i < min(3, count($response['data'])); $i++) {
                    $loc = $response['data'][$i];
                    echo "  Locality $i: ID=" . ($loc['LocalityId'] ?? 'N/A') . 
                         ", Name=" . ($loc['Name'] ?? 'N/A') . 
                         ", Postal=" . ($loc['CodPostal'] ?? 'EMPTY') . "\n";
                }
            }
        } else {
            return null;
        }
        
        foreach ($response['data'] as $loc) {
            if ((int)($loc['LocalityId'] ?? 0) === (int)$localityId) {
                return $loc['PostalCode'] ?? null;  // ← CHANGE THIS LINE
            }
        }

        return null;
    }
    
    /**
     * Get sender location configuration
     */
    private function getSenderLocation() {
        // First try to get from environment
        $envLocationId = $this->config['sender_location_id'];
        
        // Try database lookup
        $stmt = $this->conn->prepare("
            SELECT * FROM sender_locations 
            WHERE (id = ? OR cargus_location_id = ?) AND active = 1
            LIMIT 1
        ");
        $stmt->execute([$envLocationId, $envLocationId]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($location) {
            return $location;
        }
        
        // Fallback to environment defaults
        return [
            'cargus_location_id' => $envLocationId,
            'company_name' => $_ENV['CARGUS_SENDER_NAME'] ?? getenv('CARGUS_SENDER_NAME') ?? 'Company SRL',
            'county_id' => 1, // Bucuresti
            'locality_id' => 150, // Bucuresti
            'street_id' => 0,
            'building_number' => $_ENV['CARGUS_SENDER_BUILDING'] ?? getenv('CARGUS_SENDER_BUILDING') ?? '10',
            'address_text' => $_ENV['CARGUS_SENDER_ADDRESS'] ?? getenv('CARGUS_SENDER_ADDRESS') ?? 'Default Address',
            'contact_person' => $_ENV['CARGUS_SENDER_CONTACT'] ?? getenv('CARGUS_SENDER_CONTACT') ?? 'Contact Person',
            'phone' => $_ENV['CARGUS_SENDER_PHONE'] ?? getenv('CARGUS_SENDER_PHONE') ?? '0700000000',
            'email' => $_ENV['CARGUS_SENDER_EMAIL'] ?? getenv('CARGUS_SENDER_EMAIL') ?? 'contact@company.com'
        ];
    }
    
    /**
     * Build complete AWB data for Cargus API
     */
    private function buildAWBData($order, $calculatedData, $senderLocation) {

    $parcelsCount = (int)($calculatedData['parcels_count'] ?? 1);
    $envelopesCount = (int)($calculatedData['envelopes_count'] ?? $order['envelopes_count'] ?? 1);
    if ($envelopesCount <= 0) {
        $envelopesCount = 1;
    }

    $this->debugLog("=== CARGUS AWB DEBUG START ===");
    $this->debugLog("Order Number: " . $order['order_number']);
    $this->debugLog("Raw weight from calculator: " . $calculatedData['total_weight'] . " kg");

    $totalWeightKg = (float)$calculatedData['total_weight'];
    $totalWeight = (int)round($totalWeightKg); // send real kilograms

    $this->debugLog("Processed weight: " . $totalWeightKg . " kg");
    $this->debugLog("API weight (kg): " . $totalWeight);
    $this->debugLog("Parcels: " . $parcelsCount);

    if ($totalWeight > 31) {
        $this->debugLog("🚨 PROBLEM: API weight " . $totalWeight . " exceeds 31kg limit!");
    } else {
        $this->debugLog("✅ API weight " . $totalWeight . " is within 31kg limit");
    }

    $serviceId = 34;
    if ($totalWeightKg > 31) {
        $serviceId = 35;
    }

    $this->debugLog("Service ID: " . $serviceId);
    $this->debugLog("=== CARGUS AWB DEBUG END ===");

    return [
        'Sender' => [
            'SenderClientId' => null,
            'TertiaryClientId' => null,
            'LocationId' => $senderLocation['cargus_location_id'],
            'Name' => $senderLocation['company_name'],
            'CountyId' => $senderLocation['county_id'],
            'CountyName' => 'BUCURESTI',
            'LocalityId' => $senderLocation['locality_id'],
            'LocalityName' => 'BUCURESTI',
            'StreetId' => $senderLocation['street_id'] ?? 0,
            'StreetName' => $_ENV['CARGUS_SENDER_STREET'] ?? getenv('CARGUS_SENDER_STREET') ?? 'Strada Principala',
            'BuildingNumber' => $senderLocation['building_number'],
            'AddressText' => $senderLocation['address_text'],
            'ContactPerson' => $senderLocation['contact_person'],
            'PhoneNumber' => Phone::toLocal($senderLocation['phone']),
            'Email' => $senderLocation['email'],
            'CodPostal' => $_ENV['CARGUS_SENDER_POSTAL'] ?? getenv('CARGUS_SENDER_POSTAL') ?? '010001',
            'CountryId' => 0
        ],
        'Recipient' => [
            'Name' => $order['customer_name'],
            'CountyId' => $order['recipient_county_id'],
            'CountyName' => $order['recipient_county_name'] ?? '',
            'LocalityId' => $order['recipient_locality_id'],
            'LocalityName' => $order['recipient_locality_name'] ?? '',
            'StreetId' => $order['recipient_street_id'] ?? 0,
            'StreetName' => $order['recipient_street_name'] ?? '',
            'BuildingNumber' => $order['recipient_building_number'] ?: '',
            'AddressText' => $order['address_text'] ?? $order['shipping_address'],
            'ContactPerson' => $order['recipient_contact_person'] ?: $order['customer_name'],
            'PhoneNumber' => Phone::toLocal($order['recipient_phone']),
            'Email' => $order['recipient_email'] ?: '',
            'CodPostal' => $order['recipient_postal'] ?? '',
            'CountryId' => 0
        ],
        'Parcels' => $parcelsCount,
        'Envelopes' => $envelopesCount,
        'TotalWeight' => $totalWeight,
        // No declared value is sent for Cargus AWBs
        'DeclaredValue' => 0,
        'CashRepayment' => intval($order['cash_repayment'] ?? 0),
        'BankRepayment' => intval($order['bank_repayment'] ?? 0),
        'OtherRepayment' => '',
        'BarCodeRepayment' => '',
        'PaymentInstrumentId' => 0,
        'PaymentInstrumentValue' => 0,
        'HasTertReimbursement' => false,
        'OpenPackage' => boolval($order['open_package'] ?? false),
        'PriceTableId' => 0,
        'ShipmentPayer' => 1,
        'ShippingRepayment' => 0,
        'SaturdayDelivery' => boolval($order['saturday_delivery'] ?? false),
        'MorningDelivery' => boolval($order['morning_delivery'] ?? false),
        'Observations' => $order['observations'] ?: 'Comandă generată automat',
        'PackageContent' => $calculatedData['package_content'] ?: 'Diverse produse',
        'CustomString' => '',
        'SenderReference1' => $order['order_number'] ?? '',
        'RecipientReference1' => $order['recipient_reference1'] ?? '',
        'RecipientReference2' => $order['recipient_reference2'] ?? '',
        'InvoiceReference' => $order['invoice_reference'] ?? $order['invoice_number'] ?? '',
        'ServiceId' => $serviceId,
        'ParcelCodes' => $this->generateParcelCodes($parcelsCount, $envelopesCount, $totalWeight, $calculatedData)
    ];
}

    
    /**
     * Validate AWB data before sending to API
     */
    private function validateAWBData($awbData) {
        $errors = [];

        // Normalize phone numbers to local format
        $awbData['Sender']['PhoneNumber'] = Phone::toLocal($awbData['Sender']['PhoneNumber'] ?? '');
        $awbData['Recipient']['PhoneNumber'] = Phone::toLocal($awbData['Recipient']['PhoneNumber'] ?? '');
        
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
        
        // Weight and count validation - CORRECTED
        if ($awbData['TotalWeight'] <= 0) $errors[] = 'Weight must be greater than 0';
        
        // FIXED: Allow 0 parcels if there are envelopes
        if ($awbData['Parcels'] <= 0 && $awbData['Envelopes'] <= 0) {
            $errors[] = 'Must have at least 1 parcel or envelope';
        }
        
        if ($awbData['Envelopes'] > 9) $errors[] = 'Maximum 9 envelopes allowed';
        
        // CRITICAL: Validate ParcelCodes array
        if (empty($awbData['ParcelCodes']) || !is_array($awbData['ParcelCodes'])) {
            $errors[] = 'ParcelCodes array is required and cannot be empty';
        } else {
            $expectedCount = $awbData['Parcels'] + $awbData['Envelopes'];
            $actualCount = count($awbData['ParcelCodes']);
            
            if ($actualCount !== $expectedCount) {
                $errors[] = "ParcelCodes count mismatch: expected {$expectedCount} (Parcels: {$awbData['Parcels']} + Envelopes: {$awbData['Envelopes']}), got {$actualCount}";
            }
            
            // Validate each ParcelCode
            $parcelTypeCount = 0;
$envelopeTypeCount = 0;

foreach ($awbData['ParcelCodes'] as $i => $parcelCode) {
    if (!isset($parcelCode['Code'])) {
        $errors[] = "ParcelCode [{$i}]: Missing 'Code' field";
    }
    if (!isset($parcelCode['Type'])) {
        $errors[] = "ParcelCode [{$i}]: Missing 'Type' field";
    } else {
        if ($parcelCode['Type'] === 1) {
            $parcelTypeCount++; // parcel
        } elseif ($parcelCode['Type'] === 0) {
            $envelopeTypeCount++; // envelope
        } else {
            $errors[] = "ParcelCode [{$i}]: Invalid Type '{$parcelCode['Type']}' (must be 1 for parcel or 0 for envelope)";
        }
    }

    if (!isset($parcelCode['Weight']) || !is_int($parcelCode['Weight']) || $parcelCode['Weight'] <= 0) {
        $errors[] = "ParcelCode [{$i}]: Weight must be a positive integer";
    }

    $requiredFields = ['Length', 'Width', 'Height', 'ParcelContent'];
    foreach ($requiredFields as $field) {
        if (!isset($parcelCode[$field])) {
            $errors[] = "ParcelCode [{$i}]: Missing '{$field}' field";
        }
    }
}

if ($parcelTypeCount !== $awbData['Parcels']) {
    $errors[] = "Type mismatch: declared {$awbData['Parcels']} parcels, but found {$parcelTypeCount} ParcelCodes with Type=1";
}
if ($envelopeTypeCount !== $awbData['Envelopes']) {
    $errors[] = "Type mismatch: declared {$awbData['Envelopes']} envelopes, but found {$envelopeTypeCount} ParcelCodes with Type=0";
}
        }
        
        // Phone validation
        $phonePattern = '/^[0-9\s\-\(\)]{10,15}$/';
        if (!preg_match($phonePattern, $awbData['Sender']['PhoneNumber'])) {
            $errors[] = 'Invalid sender phone format';
        }
        if (!preg_match($phonePattern, $awbData['Recipient']['PhoneNumber'])) {
            $errors[] = 'Invalid recipient phone format';
        }
        
        // ServiceId validation
        if (empty($awbData['ServiceId'])) {
            $errors[] = 'ServiceId is required';
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
    
    // CRITICAL DEBUGGING: Log exactly what we're sending to Cargus API
    if ($data !== null && $endpoint === 'Awbs') {
        $this->debugLog("=== CARGUS API REQUEST DEBUG ===");
        $this->debugLog("URL: " . $url);
        $this->debugLog("Method: " . $method);
        
        // Log the complete data structure
        $jsonData = json_encode($data, JSON_PRETTY_PRINT);
        $this->debugLog("Request JSON Length: " . strlen($jsonData) . " characters");
        
        // Log specific fields we care about
        $this->debugLog("Parcels field: " . ($data['Parcels'] ?? 'MISSING'));
        $this->debugLog("Envelopes field: " . ($data['Envelopes'] ?? 'MISSING'));
        $this->debugLog("TotalWeight field: " . ($data['TotalWeight'] ?? 'MISSING'));
        
        if (isset($data['ParcelCodes'])) {
            $this->debugLog("ParcelCodes array exists with " . count($data['ParcelCodes']) . " items");
            foreach ($data['ParcelCodes'] as $i => $code) {
                $this->debugLog("  ParcelCode[{$i}]: " . json_encode($code));
            }
        } else {
            $this->debugLog("🚨 ParcelCodes field is MISSING from request data!");
        }
        
        // Log the complete JSON being sent (limit to first 2000 chars for log readability)
        $this->debugLog("Complete JSON (first 2000 chars): " . substr($jsonData, 0, 2000));
        
        // Check for JSON encoding errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->debugLog("🚨 JSON ENCODING ERROR: " . json_last_error_msg());
        }
        
        $this->debugLog("=== CARGUS API REQUEST DEBUG END ===");
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_VERBOSE => true,
        CURLOPT_STDERR => fopen('/var/www/notsowms.ro/logs/curl_verbose.log', 'a')
    ]);
    
    if ($data !== null) {
        $jsonPayload = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        
        // Log the exact payload being sent
        if ($endpoint === 'Awbs') {
            $this->debugLog("Final cURL payload length: " . strlen($jsonPayload) . " characters");
        }
    }

    // file_put_contents('/var/www/notsowms.ro/logs/cargus_last_awb_payload.json', $jsonPayload);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Log response details for AWB requests
    if ($endpoint === 'Awbs') {
        $this->debugLog("=== CARGUS API RESPONSE DEBUG ===");
        $this->debugLog("HTTP Code: " . $httpCode);
        $this->debugLog("Response length: " . strlen($response) . " characters");
        $this->debugLog("Response (first 1000 chars): " . substr($response, 0, 1000));
        $this->debugLog("=== CARGUS API RESPONSE DEBUG END ===");
    }
    
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
    if ($decodedResponse) {
        if (isset($decodedResponse['message'])) {
            $errorMessage .= ': ' . $decodedResponse['message'];
        } elseif (isset($decodedResponse['error'])) {
            $errorMessage .= ': ' . $decodedResponse['error'];
        } elseif (is_array($decodedResponse)) {
            $errorMessage .= ': ' . implode(', ', array_filter($decodedResponse, 'is_string'));
        }
    }
    
    return [
        'success' => false,
        'error' => $errorMessage,
        'code' => $httpCode,
        'raw' => $response
    ];
}

    private function debugAWBData($awbData) {
    $this->debugLog("=== AWB DATA VALIDATION DEBUG ===");
    $this->debugLog("Parcels declared: " . ($awbData['Parcels'] ?? 'NOT_SET'));
    $this->debugLog("Envelopes declared: " . ($awbData['Envelopes'] ?? 'NOT_SET'));
    $this->debugLog("TotalWeight: " . ($awbData['TotalWeight'] ?? 'NOT_SET'));
    $this->debugLog("ServiceId: " . ($awbData['ServiceId'] ?? 'NOT_SET'));
    
    // Check Sender data
    if (isset($awbData['Sender'])) {
        $this->debugLog("Sender Name: " . ($awbData['Sender']['Name'] ?? 'MISSING'));
        $this->debugLog("Sender CountyId: " . ($awbData['Sender']['CountyId'] ?? 'MISSING'));
        $this->debugLog("Sender LocalityId: " . ($awbData['Sender']['LocalityId'] ?? 'MISSING'));
    } else {
        $this->debugLog("🚨 Sender data: COMPLETELY MISSING!");
    }
    
    // Check Recipient data
    if (isset($awbData['Recipient'])) {
        $this->debugLog("Recipient Name: " . ($awbData['Recipient']['Name'] ?? 'MISSING'));
        $this->debugLog("Recipient CountyId: " . ($awbData['Recipient']['CountyId'] ?? 'MISSING'));
        $this->debugLog("Recipient LocalityId: " . ($awbData['Recipient']['LocalityId'] ?? 'MISSING'));
    } else {
        $this->debugLog("🚨 Recipient data: COMPLETELY MISSING!");
    }
    
    if (isset($awbData['ParcelCodes'])) {
        $this->debugLog("ParcelCodes count: " . count($awbData['ParcelCodes']));
        
        $parcelCount = 0;
        $envelopeCount = 0;
        
        foreach ($awbData['ParcelCodes'] as $i => $code) {
            $type = $code['Type'] ?? 'MISSING';
            $typeName = $type === 1 ? 'PARCEL' : ($type === 0 ? 'ENVELOPE' : 'UNKNOWN');
            $weight = $code['Weight'] ?? 'MISSING';
            $codeValue = $code['Code'] ?? 'MISSING';
            $length = $code['Length'] ?? 'MISSING';
            $width = $code['Width'] ?? 'MISSING';
            $height = $code['Height'] ?? 'MISSING';
            $content = $code['ParcelContent'] ?? 'MISSING';
            
            $this->debugLog("  [{$i}] Code: {$codeValue}, Type: {$type} ({$typeName}), Weight: {$weight}, Dims: {$length}x{$width}x{$height}, Content: {$content}");
            
            if ($type === 0) $parcelCount++;
            if ($type === 1) $envelopeCount++;
        }
        
        $this->debugLog("Summary - Parcel types (Type=0): {$parcelCount}, Envelope types (Type=1): {$envelopeCount}");
        
        // Check for mismatches
        if ($parcelCount !== ($awbData['Parcels'] ?? 0)) {
            $this->debugLog("🚨 MISMATCH: Declared parcels: " . ($awbData['Parcels'] ?? 0) . ", Type=0 count: {$parcelCount}");
        }
        if ($envelopeCount !== ($awbData['Envelopes'] ?? 0)) {
            $this->debugLog("🚨 MISMATCH: Declared envelopes: " . ($awbData['Envelopes'] ?? 0) . ", Type=1 count: {$envelopeCount}");
        }
        
    } else {
        $this->debugLog("🚨 ParcelCodes: COMPLETELY MISSING!");
    }
    
    $this->debugLog("=== AWB DATA VALIDATION DEBUG END ===");
}
    
    /**
     * Log info message
     */
    private function logInfo($message, $context = []) {
        if ($this->config['debug']) {
            $logMessage = "Cargus INFO: $message";
            if (!empty($context)) {
                $logMessage .= " | Context: " . json_encode($context);
            }
            error_log($logMessage);
        }
    }
    
    /**
     * Log error message
     */
    private function logError($message, $error, $raw = null) {
        $logMessage = "Cargus ERROR: $message | Error: $error";
        if ($raw) {
            $logMessage .= " | Raw: " . substr($raw, 0, 500);
        }
        error_log($logMessage);
    }
}