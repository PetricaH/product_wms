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
    private array $countyCache = [];
    private array $localityCache = [];

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
     * Retrieve returned AWBs reported by Cargus for a specific date.
     *
     * @param string|\DateTimeInterface $date Expected format Y-m-d when string.
     * @return array{success:bool,data?:array,error?:string,code?:int,raw?:mixed}
     */
    public function getReturnedAWBs($date) {
        try {
            $normalizedDate = $this->normalizeDateValue($date, 'Y-m-d');
            if ($normalizedDate === null) {
                return [
                    'success' => false,
                    'error' => 'Invalid date format. Expected Y-m-d.',
                    'code' => 400,
                ];
            }

            if (!$this->authenticate()) {
                return [
                    'success' => false,
                    'error' => 'Authentication failed',
                    'code' => 401,
                ];
            }

            $endpoint = sprintf('AwbRetur?data=%s', urlencode($normalizedDate));
            $response = $this->makeRequest('GET', $endpoint);

            if ($response['success']) {
                $data = $response['data'] ?? [];
                $count = is_array($data) ? count($data) : 0;
                $this->logInfo('Fetched returned AWBs', [
                    'date' => $normalizedDate,
                    'count' => $count,
                ]);

                return [
                    'success' => true,
                    'data' => is_array($data) ? $data : [],
                    'code' => $response['code'] ?? 200,
                ];
            }

            // Enhance error context for rate limiting scenarios
            if (($response['code'] ?? 0) === 429) {
                $response['error'] = $response['error'] ?? 'Rate limit reached while fetching returned AWBs';
            }

            return $response;
        } catch (\Throwable $exception) {
            $this->logError('Returned AWB retrieval exception', $exception->getMessage());
            return [
                'success' => false,
                'error' => 'Internal error: ' . $exception->getMessage(),
                'code' => 500,
            ];
        }
    }

    /**
     * Retrieve delta tracking events from Cargus between two dates.
     *
     * @param string|\DateTimeInterface $fromDate Expected string format m-d-Y.
     * @param string|\DateTimeInterface $toDate   Expected string format m-d-Y.
     * @return array{success:bool,data?:array,error?:string,code?:int,raw?:mixed}
     */
    public function getDeltaEvents($fromDate, $toDate) {
        try {
            $normalizedFrom = $this->normalizeDateValue($fromDate, 'm-d-Y');
            $normalizedTo = $this->normalizeDateValue($toDate, 'm-d-Y');

            if ($normalizedFrom === null || $normalizedTo === null) {
                return [
                    'success' => false,
                    'error' => 'Invalid date format. Expected m-d-Y.',
                    'code' => 400,
                ];
            }

            if (!$this->authenticate()) {
                return [
                    'success' => false,
                    'error' => 'Authentication failed',
                    'code' => 401,
                ];
            }

            $query = http_build_query([
                'FromDate' => $normalizedFrom,
                'ToDate' => $normalizedTo,
            ]);
            $endpoint = 'AwbTrace/GetDeltaEvents?' . $query;
            $response = $this->makeRequest('GET', $endpoint);

            if ($response['success']) {
                $data = $response['data'] ?? [];
                $count = is_array($data) ? count($data) : 0;
                $this->logInfo('Fetched delta events', [
                    'from' => $normalizedFrom,
                    'to' => $normalizedTo,
                    'count' => $count,
                ]);

                return [
                    'success' => true,
                    'data' => is_array($data) ? $data : [],
                    'code' => $response['code'] ?? 200,
                ];
            }

            if (($response['code'] ?? 0) === 429) {
                $response['error'] = $response['error'] ?? 'Rate limit reached while fetching delta events';
            }

            return $response;
        } catch (\Throwable $exception) {
            $this->logError('Delta events retrieval exception', $exception->getMessage());
            return [
                'success' => false,
                'error' => 'Internal error: ' . $exception->getMessage(),
                'code' => 500,
            ];
        }
    }

    /**
     * Retrieve a valid authentication token
     */
    public function getAuthToken() {
        if ($this->verifyToken()) {
            return $this->token;
        }
        return null;
    }

    /**
     * Normalize supported date inputs.
     *
     * @param string|\DateTimeInterface $value
     */
    private function normalizeDateValue($value, string $expectedFormat): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format($expectedFormat);
        }

        $stringValue = is_string($value) ? trim($value) : '';
        if ($stringValue === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat($expectedFormat, $stringValue);
        if ($date === false) {
            return null;
        }

        return $date->format($expectedFormat);
    }


    /**
     * Main method to generate AWB
     */
    public function generateAWB($order) {
        try {
            return $this->generateNormalAWB($order);
        } catch (Exception $e) {
            $this->logError('AWB generation exception', $e->getMessage(), $e->getTraceAsString());
            return [
                'success' => false,
                'error' => 'Internal error: ' . $e->getMessage(),
                'code' => 500
            ];
        }
    }

    private function generateNormalAWB($order, array $shippingOptions = [])
    {
        try {
            // Ensure order is eligible for AWB generation
            if (($order['status'] ?? '') !== 'picked') {
                return [
                    'success' => false,
                    'error' => 'Order status must be picked',
                    'code' => 400
                ];
            }

            // Authenticate first
            if (!$this->authenticate()) {
                return [
                    'success' => false,
                    'error' => 'Authentication failed',
                    'code' => 401
                ];
            }

            // Calculate order weights and parcels
            $calculatedData = $this->calculateOrderShipping($order, $shippingOptions);

            // Ensure parcel count reflects the calculated parcel breakdown
            $parcelDetails = $calculatedData['parcels_detail'] ?? [];
            if (!empty($parcelDetails)) {
                $calculatedData['parcels_count'] = count($parcelDetails);
            } else {
                $totalItems = 0;
                if (!empty($order['items']) && is_array($order['items'])) {
                    foreach ($order['items'] as $item) {
                        $totalItems += (int)($item['quantity'] ?? 0);
                    }
                }

                if (empty($calculatedData['parcels_count']) || $calculatedData['parcels_count'] <= 0) {
                    $calculatedData['parcels_count'] = max(1, $totalItems);
                }
            }

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

                // Robust numeric-only extraction (handles raw string and structured responses)
                $barcode = $this->extractNumericBarcode($data);
                if ($barcode === '') {
                    $this->debugLog("AWB created but no valid numeric barcode found. Full response: " . ($response['raw'] ?? json_encode($data)));
                }

                $parcelCodes = is_array($data) ? ($data['ParcelCodes'] ?? []) : [];
                $cargusOrderId = is_array($data) ? ($data['OrderId'] ?? '') : '';

                $this->logInfo('AWB generation result', [
                    'order_id' => $order['id'] ?? null,
                    'barcode' => $barcode ?: 'MISSING'
                ]);

                return [
                    'success' => true,
                    'barcode' => $barcode,
                    'parcelCodes' => $parcelCodes,
                    'cargusOrderId' => $cargusOrderId,
                    'raw' => $response['raw'] ?? null
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
    private function extractNumericBarcode(mixed $data): string
    {
        $candidates = [];

        if (is_string($data)) {
            $candidates[] = $data;
        }

        if (is_array($data)) {
            foreach (['BarCode', 'Barcode', 'message', 'OrderId'] as $field) {
                if (!empty($data[$field])) {
                    $candidates[] = $data[$field];
                }
            }

            if (!empty($data['ParcelCodes']) && is_array($data['ParcelCodes'])) {
                foreach ($data['ParcelCodes'] as $pc) {
                    if (!empty($pc['Code'])) {
                        $candidates[] = $pc['Code'];
                    }
                }
            }
        }

        foreach ($candidates as $c) {
            $s = trim((string)$c, "\" \t\n\r\0\x0B");
            if (preg_match('/^\d+$/', $s)) {
                return $s;
            }
        }

        return '';
    }

    /**
     * Track AWB status via Cargus API
     */
    public function trackAWB(string $awb) {
        try {
            if (!$this->authenticate()) {
                return ['success' => false, 'error' => 'Authentication failed'];
            }

            // Use AwbTrace endpoint as per Cargus API documentation
            $endpoint = 'AwbTrace/WithRedirect?barCode=' . rawurlencode(json_encode([$awb]));
            $response = $this->makeRequest('GET', $endpoint);

            if ($response['success']) {
                $payload = $response['data'];
                // API can return either an array or single object
                $item = [];
                if (is_array($payload)) {
                    // If associative array with Code/History keys
                    if (isset($payload['Code']) || isset($payload['History'])) {
                        $item = $payload;
                    } else {
                        $item = $payload[0] ?? [];
                    }
                } elseif (is_object($payload)) {
                    $item = (array)$payload;
                }

                $status = $item['Status']['Status'] ?? $item['Status'] ?? 'Unknown';
                $last   = $item['Status']['Date'] ?? $item['LastUpdate'] ?? null;

                $history = [];
                // Cargus API uses 'Event' array according to documentation
                $historyData = $item['Event'] ?? $item['History'] ?? $item['history'] ?? [];
                if (is_array($historyData)) {
                    foreach ($historyData as $h) {
                        $history[] = [
                            'time'     => $h['Date'] ?? $h['time'] ?? null,
                            'event'    => $h['Description'] ?? $h['Status'] ?? $h['event'] ?? '',
                            'location' => $h['LocalityName'] ?? $h['Location'] ?? $h['location'] ?? null
                        ];
                    }
                }

                return [
                    'success' => true,
                    'data' => [
                        'awb' => $awb,
                        'status' => $status,
                        'last_update' => $last,
                        'history' => $history
                    ],
                    'raw' => $response['raw'] ?? null
                ];
            }

            return [
                'success' => false,
                'error' => $response['error'],
                'raw' => $response['raw'] ?? null
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Retrieve PUDO (pick-up/drop-off) points with coordinates.
     * Results are cached for 24h to avoid frequent API calls.
     *
     * @return array
     */
    public function getPudoPoints(): array
    {
        $cacheFile = sys_get_temp_dir() . '/cargus_pudo_cache.json';

        // Return cached data if still fresh (24h)
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            if (!$this->authenticate()) {
                return [];
            }

            // The endpoint name is based on Cargus documentation.
            // It may vary depending on API version.
            $endpoint = 'Pudo/Get';
            $response = $this->makeRequest('GET', $endpoint);

            if ($response['success'] && isset($response['data'])) {
                $data = is_array($response['data']) ? $response['data'] : [];
                file_put_contents($cacheFile, json_encode($data));
                return $data;
            }
        } catch (Exception $e) {
            // Swallow exceptions and return empty array to avoid breaking the flow
        }

        return [];
    }

    
    /**
     * Consolidate parcels to meet Multipiece service limits (max 15 parcels)
     */
    private function consolidateParcels($parcelDetails, $maxParcels = 15) {
        if (count($parcelDetails) <= $maxParcels) {
            return $parcelDetails; // No consolidation needed
        }

        $this->debugLog("⚠️ Starting consolidation: " . count($parcelDetails) . " parcels → max {$maxParcels} parcels");

        $consolidated = [];
        $currentParcel = null;
        $maxWeightPerParcel = 31; // Cargus hard limit per parcel

        foreach ($parcelDetails as $detail) {
            $weight = (float)($detail['weight'] ?? 0);
            $productType = strtolower($detail['product_type'] ?? 'normal');

            // Start new parcel if:
            // 1. No current parcel exists, OR
            // 2. Adding this would exceed 31kg limit, OR
            // 3. Different product type
            if ($currentParcel === null ||
                ($currentParcel['weight'] + $weight) > $maxWeightPerParcel ||
                $currentParcel['product_type'] !== $productType) {

                // Save current parcel if exists
                if ($currentParcel !== null) {
                    $consolidated[] = $currentParcel;
                }

                // Start new parcel
                $currentParcel = [
                    'weight' => $weight,
                    'items' => $detail['items'] ?? 1,
                    'length' => $detail['length'] ?? 20,
                    'width' => $detail['width'] ?? 20,
                    'height' => $detail['height'] ?? 20,
                    'product_type' => $productType,
                    'content' => $detail['content'] ?? 'Colet',
                    'content_items' => [$detail['content'] ?? 'Produse']
                ];
            } else {
                // Add to current parcel (combine)
                $currentParcel['weight'] += $weight;
                $currentParcel['items'] += $detail['items'] ?? 1;
                $currentParcel['height'] += $detail['height'] ?? 0; // Stack items vertically
                $currentParcel['length'] = max($currentParcel['length'], $detail['length'] ?? 0);
                $currentParcel['width'] = max($currentParcel['width'], $detail['width'] ?? 0);
                $currentParcel['content_items'][] = $detail['content'] ?? 'Produse';
            }
        }

        // Add last parcel
        if ($currentParcel !== null) {
            $consolidated[] = $currentParcel;
        }

        // Final pass: if still too many parcels, force merge smallest ones
        $iterations = 0;
        while (count($consolidated) > $maxParcels && $iterations < 10) {
            $iterations++;
            $this->debugLog("⚠️ Iteration {$iterations}: Still have " . count($consolidated) . " parcels, force merging...");

            // Find two adjacent parcels that can be merged (total weight <= 31kg)
            $merged = [];
            $didMerge = false;

            for ($i = 0; $i < count($consolidated); $i++) {
                if (!$didMerge && $i < count($consolidated) - 1) {
                    $totalWeight = $consolidated[$i]['weight'] + $consolidated[$i + 1]['weight'];

                    // Can we merge these two?
                    if ($totalWeight <= $maxWeightPerParcel) {
                        // Merge parcels i and i+1
                        $merged[] = [
                            'weight' => $totalWeight,
                            'items' => $consolidated[$i]['items'] + $consolidated[$i + 1]['items'],
                            'length' => max($consolidated[$i]['length'], $consolidated[$i + 1]['length']),
                            'width' => max($consolidated[$i]['width'], $consolidated[$i + 1]['width']),
                            'height' => $consolidated[$i]['height'] + $consolidated[$i + 1]['height'],
                            'product_type' => $consolidated[$i]['product_type'],
                            'content' => 'Colete combinate',
                            'content_items' => array_merge(
                                $consolidated[$i]['content_items'] ?? [$consolidated[$i]['content'] ?? 'Produse'],
                                $consolidated[$i + 1]['content_items'] ?? [$consolidated[$i + 1]['content'] ?? 'Produse']
                            )
                        ];

                        $didMerge = true;
                        $i++; // Skip next parcel (already merged)
                    } else {
                        $merged[] = $consolidated[$i];
                    }
                } else {
                    $merged[] = $consolidated[$i];
                }
            }

            $consolidated = $merged;

            if (!$didMerge) {
                $this->debugLog("⚠️ Cannot merge further without exceeding 31kg limit");
                break;
            }
        }

        // Update content descriptions for merged parcels
        foreach ($consolidated as &$parcel) {
            if (!empty($parcel['content_items'])) {
                $uniqueItems = array_unique($parcel['content_items']);
                $parcel['content'] = implode(', ', array_slice($uniqueItems, 0, 3));
                if (count($uniqueItems) > 3) {
                    $parcel['content'] .= ' +' . (count($uniqueItems) - 3) . ' mai multe';
                }
                unset($parcel['content_items']);
            }
        }

        $this->debugLog("✅ Consolidation complete: " . count($consolidated) . " parcels");

        return $consolidated;
    }

    /**
     * Generate ParcelCodes array to match Parcels + Envelopes count
     */
    private function generateParcelCodes($parcelsCount, $envelopesCount, $totalWeightAPI, $calculatedData) {
        $parcelCodes = [];
        $parcelDetails = $calculatedData['parcels_detail'] ?? [];
        $parcelIndex = 0;

        // ========================================
        // CONSOLIDATE PARCELS IF EXCEEDS LIMITS
        // ========================================
        $maxParcelsAllowed = 15; // Cargus Multipiece limit

        if (!empty($parcelDetails) && count($parcelDetails) > $maxParcelsAllowed) {
            $this->debugLog("⚠️ Order has " . count($parcelDetails) . " parcels, exceeds Multipiece limit of {$maxParcelsAllowed}");

            // Consolidate parcels to meet service requirements
            $parcelDetails = $this->consolidateParcels($parcelDetails, $maxParcelsAllowed);
            $parcelsCount = count($parcelDetails);

            $this->debugLog("✅ Consolidated to {$parcelsCount} parcels for Cargus");
        }

        if (!empty($parcelDetails)) {
            foreach ($parcelDetails as $detail) {
                $productType = strtolower($detail['product_type'] ?? 'normal');

                $detailContent = $detail['content'] ?? $detail['ParcelContent'] ?? null;

                if ($productType === 'spray') {
                    $length = 27;
                    $width = 20;
                    $height = 20;
                    $weight = 5;
                    $content = $detailContent ?? sprintf('Spray - %d buc', $detail['items'] ?? $detail['quantity'] ?? 12);
                } elseif ($productType === 'cartuse') {
                    $length = 22;
                    $width = 25;
                    $height = 37;
                    $weight = 10;
                    $content = $detailContent ?? sprintf('Cartus - %d buc', $detail['items'] ?? $detail['quantity'] ?? 24);
                } else {
                    $length = $detail['length'] ?? $calculatedData['package_length'] ?? 20;
                    $width = $detail['width'] ?? $calculatedData['package_width'] ?? 20;
                    $height = $detail['height'] ?? $calculatedData['package_height'] ?? 20;
                    $weight = $detail['weight'] ?? ($parcelsCount > 0 ? ($totalWeightAPI / max(1, $parcelsCount)) : $totalWeightAPI);
                    $content = $detailContent ?? 'Colet ' . ($parcelIndex + 1);
                }

                // Split parcels that exceed 31kg limit
                $maxWeightPerParcel = 31;
                $roundedWeight = (int)round($weight);

                if ($roundedWeight > $maxWeightPerParcel) {
                    // Split into multiple sub-parcels
                    $numSplits = (int)ceil($weight / $maxWeightPerParcel);
                    $weightPerSplit = $weight / $numSplits;

                    $this->debugLog("⚠️ Splitting heavy parcel: {$weight}kg into {$numSplits} parcels of ~{$weightPerSplit}kg each");

                    for ($i = 0; $i < $numSplits; $i++) {
                        $parcelCodes[] = [
                            'Code' => (string)$parcelIndex,
                            'Type' => 1,
                            'Weight' => max(1, (int)round($weightPerSplit)),
                            'Length' => max(1, (int)round($length)),
                            'Width' => max(1, (int)round($width)),
                            'Height' => max(1, (int)round($height)),
                            'ParcelContent' => $content . " (Parte " . ($i + 1) . "/" . $numSplits . ")"
                        ];
                        $parcelIndex++;
                    }
                } else {
                    // Normal parcel - under 31kg
                    $parcelCodes[] = [
                        'Code' => (string)$parcelIndex,
                        'Type' => 1,
                        'Weight' => max(1, $roundedWeight),
                        'Length' => max(1, (int)round($length)),
                        'Width' => max(1, (int)round($width)),
                        'Height' => max(1, (int)round($height)),
                        'ParcelContent' => $content
                    ];
                    $parcelIndex++;
                }
            }
        }

        // Fallback when parcel details are missing but parcel count is provided
        while ($parcelIndex < $parcelsCount) {
            $parcelCodes[] = [
                'Code' => (string)$parcelIndex,
                'Type' => 1,
                'Weight' => max(1, (int)round($totalWeightAPI / max(1, $parcelsCount))),
                'Length' => max(1, (int)round($calculatedData['package_length'] ?? 20)),
                'Width' => max(1, (int)round($calculatedData['package_width'] ?? 20)),
                'Height' => max(1, (int)round($calculatedData['package_height'] ?? 20)),
                'ParcelContent' => 'Colet ' . ($parcelIndex + 1)
            ];
            $parcelIndex++;
        }

        // Always append a single envelope for documents
        $parcelCodes[] = [
            'Code' => (string)$parcelIndex,
            'Type' => 0,
            'Weight' => 1,
            'Length' => 25,
            'Width' => 15,
            'Height' => 1,
            'ParcelContent' => 'Documente'
        ];

        return $parcelCodes;
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
     * Retrieve Cargus counties list with simple caching.
     */
    public function getCounties(bool $forceRefresh = false): array
    {
        if (!$forceRefresh && !empty($this->countyCache)) {
            return $this->countyCache;
        }

        if (!$this->authenticate()) {
            return [];
        }

        $response = $this->makeRequest('GET', 'Counties?countryId=1', null, true);
        if (!$response['success'] || !is_array($response['data'])) {
            return [];
        }

        $this->countyCache = $response['data'];
        return $this->countyCache;
    }

    /**
     * Retrieve localities for a specific county with caching.
     */
    public function getLocalitiesByCounty(int $countyId, bool $forceRefresh = false): array
    {
        if ($countyId <= 0) {
            return [];
        }

        if (!$forceRefresh && isset($this->localityCache[$countyId])) {
            return $this->localityCache[$countyId];
        }

        if (!$this->authenticate()) {
            return [];
        }

        $endpoint = sprintf('Localities?countryId=1&countyId=%d', $countyId);
        $response = $this->makeRequest('GET', $endpoint, null, true);

        if (!$response['success'] || !is_array($response['data'])) {
            return [];
        }

        $this->localityCache[$countyId] = $response['data'];
        return $this->localityCache[$countyId];
    }

    /**
     * Normalize Romanian text (diacritics, spacing, punctuation) for comparison.
     */
    private function normalizeText(string $text): string
    {
        $text = trim(mb_strtolower($text, 'UTF-8'));
        $replacements = [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't',
            '-' => ' ', '_' => ' '
        ];
        $text = strtr($text, $replacements);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Calculate a similarity score between two normalized strings.
     */
    private function calculateSimilarity(string $reference, string $candidate): float
    {
        if ($reference === '' || $candidate === '') {
            return 0.0;
        }

        similar_text($reference, $candidate, $percent);
        $distance = levenshtein($reference, $candidate);
        $maxLength = max(strlen($reference), strlen($candidate));
        $distanceScore = $maxLength > 0 ? (1 - min($distance, $maxLength) / $maxLength) * 100 : 0;

        return round(($percent * 0.6) + ($distanceScore * 0.4), 2);
    }

    /**
     * Search Cargus localities using fuzzy matching for Romanian names.
     */
    public function searchLocalities(string $countyName, string $localityName): array
    {
        $countyName = trim($countyName);
        $localityName = trim($localityName);

        if ($countyName === '' || $localityName === '') {
            return [
                'success' => false,
                'error' => 'Județul și localitatea sunt obligatorii pentru căutare',
                'matches' => []
            ];
        }

        $normalizedCounty = $this->normalizeText($countyName);
        $normalizedLocality = $this->normalizeText($localityName);

        $counties = $this->getCounties();
        if (empty($counties)) {
            return [
                'success' => false,
                'error' => 'Nu am putut obține lista de județe din API-ul Cargus',
                'matches' => []
            ];
        }

        $countyCandidates = [];
        foreach ($counties as $county) {
            $name = $county['Name'] ?? '';
            $normalizedName = $this->normalizeText($name);
            $score = $this->calculateSimilarity($normalizedCounty, $normalizedName);

            if ($normalizedCounty !== '' && str_contains($normalizedName, $normalizedCounty)) {
                $score += 10;
            }

            if ($normalizedName === $normalizedCounty) {
                $score += 15;
            }

            $countyCandidates[] = [
                'id' => (int)($county['CountyId'] ?? 0),
                'name' => $name,
                'normalized' => $normalizedName,
                'score' => round($score, 2)
            ];
        }

        usort($countyCandidates, static function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $topCandidates = array_values(array_filter(
            array_slice($countyCandidates, 0, 5),
            static fn($candidate) => !empty($candidate['id'])
        ));

        $matches = [];
        foreach ($topCandidates as $candidate) {
            $localities = $this->getLocalitiesByCounty($candidate['id']);
            if (empty($localities)) {
                continue;
            }

            foreach ($localities as $locality) {
                $name = $locality['Name'] ?? '';
                $normalizedName = $this->normalizeText($name);
                $localityScore = $this->calculateSimilarity($normalizedLocality, $normalizedName);

                if ($normalizedLocality !== '' && str_contains($normalizedName, $normalizedLocality)) {
                    $localityScore += 10;
                }

                if ($normalizedName === $normalizedLocality) {
                    $localityScore += 15;
                }

                $combinedScore = ($localityScore * 0.7) + ($candidate['score'] * 0.3);

                if ($combinedScore < 25) {
                    continue;
                }

                $matches[] = [
                    'county_id' => $candidate['id'],
                    'county_name' => $candidate['name'],
                    'locality_id' => (int)($locality['LocalityId'] ?? 0),
                    'locality_name' => $name,
                    'postal_code' => $locality['CodPostal'] ?? null,
                    'score' => min(100, round($combinedScore, 2))
                ];
            }
        }

        if (empty($matches)) {
            return [
                'success' => false,
                'error' => 'Nu am găsit potriviri în Cargus pentru această localitate',
                'matches' => []
            ];
        }

        usort($matches, static function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $matches = array_slice($matches, 0, 25);

        return [
            'success' => true,
            'matches' => $matches,
            'debug' => [
                'normalized' => [
                    'county' => $normalizedCounty,
                    'locality' => $normalizedLocality
                ],
                'county_candidates' => array_slice($countyCandidates, 0, 10)
            ]
        ];
    }
    
    /**
     * Calculate shipping data for order
     */
    public function calculateOrderShipping($order, array $options = []) {
        $weightCalculator = new WeightCalculator($this->conn);
        return $weightCalculator->calculateOrderShipping($order['id'], $options);
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
            'county_id' => 39,
            'locality_id' => 184,
            'street_id' => 0,
            'building_number' => $_ENV['CARGUS_SENDER_BUILDING'] ?? getenv('CARGUS_SENDER_BUILDING') ?? '15',
            'address_text' => $_ENV['CARGUS_SENDER_ADDRESS'] ?? getenv('CARGUS_SENDER_ADDRESS') ?? 'Strada Sulina Nr 15',
            'contact_person' => $_ENV['CARGUS_SENDER_CONTACT'] ?? getenv('CARGUS_SENDER_CONTACT') ?? 'Răzvan',
            'phone' => $_ENV['CARGUS_SENDER_PHONE'] ?? getenv('CARGUS_SENDER_PHONE') ?? '0774663896',
            'email' => $_ENV['CARGUS_SENDER_EMAIL'] ?? getenv('CARGUS_SENDER_EMAIL') ?? 'info@wartung.ro'
        ];
    }
    
    /**
     * Build complete AWB data for Cargus API
     */
    private function buildAWBData($order, $calculatedData, $senderLocation) {

        $parcelDetails = $calculatedData['parcels_detail'] ?? [];

        // ========================================
        // CONSOLIDATE PARCELS EARLY (BEFORE COUNTING)
        // ========================================
        $maxParcelsAllowed = 15; // Cargus Multipiece limit

        if (!empty($parcelDetails) && count($parcelDetails) > $maxParcelsAllowed) {
            $this->debugLog("⚠️ buildAWBData: Order has " . count($parcelDetails) . " parcels, exceeds limit of {$maxParcelsAllowed}");

            // Consolidate parcels BEFORE setting count
            $parcelDetails = $this->consolidateParcels($parcelDetails, $maxParcelsAllowed);

            // Update calculatedData with consolidated parcels
            $calculatedData['parcels_detail'] = $parcelDetails;

            $this->debugLog("✅ buildAWBData: Consolidated to " . count($parcelDetails) . " parcels");
        }

        // NOW set the count based on (possibly consolidated) parcels
        if (!empty($parcelDetails)) {
            $parcelsCount = count($parcelDetails);
        } else {
            $parcelsCount = max(1, (int)($calculatedData['parcels_count'] ?? 1));
        }
        $envelopesValue = $calculatedData['envelopes_count'] ?? ($order['envelopes_count'] ?? 1);
        $envelopesCount = (int)$envelopesValue;
        if ($envelopesCount < 0) {
            $envelopesCount = 0;
        }

        $orderHasEnvelopeOverride = is_array($order) && array_key_exists('envelopes_count', $order);
        $explicitEnvelopeOverride = array_key_exists('envelopes_count', $calculatedData) || $orderHasEnvelopeOverride;
        if ($envelopesCount <= 0 && !$explicitEnvelopeOverride) {
            $envelopesCount = 1;
        }

        $envelopesCount = max(1, (int)$envelopesCount);

        $this->debugLog("=== CARGUS AWB DEBUG START ===");
        $this->debugLog("Order Number: " . $order['order_number']);
        $this->debugLog("Raw weight from calculator: " . $calculatedData['total_weight'] . " kg");

        $totalWeightKg = (float)$calculatedData['total_weight'];
        $totalWeight = (int)round($totalWeightKg);

        $this->debugLog("Processed weight: " . $totalWeightKg . " kg");
        $this->debugLog("API weight (kg): " . $totalWeight);
        $this->debugLog("Parcels: " . $parcelsCount);
        $this->debugLog("Envelopes: " . $envelopesCount);

        // ========================================
        // AUTOMATIC SERVICE DETECTION
        // ========================================

        // Check maximum individual parcel weight
        $maxIndividualWeight = 0;

        if (!empty($parcelDetails)) {
            foreach ($parcelDetails as $parcel) {
                $parcelWeight = (float)($parcel['weight'] ?? 0);
                $maxIndividualWeight = max($maxIndividualWeight, $parcelWeight);

                if ($parcelWeight > 31) {
                    $this->debugLog("⚠️ WARNING: Parcel weight {$parcelWeight}kg exceeds 31kg limit!");
                }
            }
        }

        // Determine correct service based on ACTUAL Cargus API rules
        // ServiceId 34: 0-31kg (Standard)
        // ServiceId 35: 31-50kg (Standard 31+)
        // ServiceId 36: 50kg+ (Standard 50+)
        $serviceId = 34; // Default: Standard (0-31kg)

        if ($totalWeightKg > 50) {
            // Total weight exceeds 50kg - use Standard 50+
            $this->debugLog("📦 Using Standard 50+ service (total >50kg: {$totalWeightKg}kg)");
            $serviceId = 36;

            // Verify all individual parcels are under 31kg
            if ($maxIndividualWeight > 31) {
                $this->debugLog("⚠️ WARNING: Individual parcel {$maxIndividualWeight}kg exceeds 31kg limit for Standard 50+!");
                $this->debugLog("⚠️ You may need to split this parcel further or contact Cargus for special handling");
            }

            // Verify parcel count doesn't exceed limits
            if ($parcelsCount > 20) {
                $this->debugLog("⚠️ WARNING: {$parcelsCount} parcels may exceed service limits!");
            }

        } elseif ($totalWeightKg > 31) {
            // Total weight 31-50kg - use Standard 31+
            $this->debugLog("📦 Using Standard 31+ service (31-50kg: {$totalWeightKg}kg)");
            $serviceId = 35;

        } else {
            // Total weight 0-31kg - use Standard
            $this->debugLog("📦 Using Standard service (0-31kg: {$totalWeightKg}kg)");
            $serviceId = 34;
        }

        $this->debugLog("Service ID: " . $serviceId);
        $this->debugLog("Total weight: {$totalWeightKg} kg");
        $this->debugLog("Parcels count: {$parcelsCount}");
        $this->debugLog("Max individual parcel weight: " . $maxIndividualWeight . " kg");
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
            'OtherRepayment' => 'RETUR FACTURA SEMNATA',
            'Observations' => 'RETUR FACTURA SEMNATA FATA+VERSO',
            'ReturnDocumentOrPackage' => true,
            // 'PackageContent' => $calculatedData['package_content'] ?: 'Diverse produse',
            'CustomString' => '',
            'SenderReference1' => $order['order_number'] ?? '',
            'RecipientReference1' => $order['recipient_reference1'] ?? '',
            'RecipientReference2' => $order['recipient_reference2'] ?? '',
            'InvoiceReference' => $order['invoice_reference'] ?? $order['invoice_number'] ?? '',
            'ServiceId' => $serviceId,
            'ParcelCodes' => $this->generateParcelCodes($parcelsCount, $envelopesCount, $totalWeight, $calculatedData)
        ];
    }
    
    public function getAwbDocuments($awbCodes, $type = 'PDF', $format = 1, $printMainOnce = 1) {
        try {
            if (empty($awbCodes) || !is_array($awbCodes)) {
                return ['success' => false, 'error' => 'Invalid AWB codes provided'];
            }

            // Ensure all AWB codes are numeric strings
            $cleanAwbCodes = array_map(function ($awb) {
                return preg_match('/^\d+$/', trim($awb)) ? trim($awb) : null;
            }, $awbCodes);

            $cleanAwbCodes = array_filter($cleanAwbCodes);

            if (empty($cleanAwbCodes)) {
                return ['success' => false, 'error' => 'No valid AWB codes provided'];
            }

            // Prepare parameters
            $jsonAwb = json_encode(array_values($cleanAwbCodes));
            $params = [
                'barCodes' => $jsonAwb,
                'type' => strtoupper($type),
                'format' => intval($format),
                'printMainOnce' => intval($printMainOnce)
            ];

            $queryString = http_build_query($params);
            $endpoint = 'AwbDocuments?' . $queryString;

            error_log("Cargus AWB Documents request: {$endpoint}");

            // Ensure we have a valid authentication token
            if (!$this->getAuthToken()) {
                return ['success' => false, 'error' => 'Failed to authenticate with Cargus API'];
            }

            // Make API request
            $result = $this->makeRequest('GET', $endpoint, null, true);

            error_log("Cargus AWB Documents response status: " . $result['code']);

            if (!$result['success'] || (int)$result['code'] !== 200) {
                $errorMsg = 'AWB Documents API error: ' . ($result['error'] ?? 'Unknown error');
                error_log($errorMsg . " (Status: {$result['code']})");
                return ['success' => false, 'error' => $errorMsg];
            }

            // Validate API response and normalise to base64
            $rawBody = (string)($result['raw'] ?? '');

            // If API returned JSON, attempt to extract the document content
            if (!empty($result['data'])) {
                if (is_array($result['data'])) {
                    // Surface API errors rather than returning a corrupt document
                    $apiError = $result['data']['message'] ?? $result['data']['error'] ?? null;
                    if ($apiError) {
                        return ['success' => false, 'error' => 'AWB Documents API error: ' . $apiError];
                    }

                    // Some responses might wrap the base64 data in a field
                    $rawBody = $result['data']['data'] ?? $result['data']['document'] ?? $result['data']['content'] ?? $rawBody;
                } elseif (is_string($result['data'])) {
                    $rawBody = $result['data'];
                }
            }

            // Normalize whitespace that may break base64 detection
            $rawBody = trim($rawBody);

            // If response is not valid base64, assume it's binary PDF and encode it
            if (base64_decode($rawBody, true) === false) {
                $compact = preg_replace('/\s+/', '', $rawBody);
                if (base64_decode($compact, true) !== false) {
                    $rawBody = $compact;
                } else {
                    $rawBody = base64_encode($rawBody);
                }
            }

            // Final validation - ensure we now have valid base64 data
            if (base64_decode($rawBody, true) === false) {
                return ['success' => false, 'error' => 'Invalid document data received from Cargus API'];
            }

            error_log("Cargus AWB Documents success: " . strlen($rawBody) . " bytes base64 data");

            // Return base64 encoded PDF data
            return [
                'success' => true,
                'data' => $rawBody,
                'error' => null
            ];
        } catch (Throwable $e) {
            error_log('CargusService::getAwbDocuments error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to get AWB documents: ' . $e->getMessage()
            ];
        }
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
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
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