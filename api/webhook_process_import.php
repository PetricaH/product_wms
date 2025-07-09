<?php
// File: api/webhook_process_import.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Bootstrap and Config
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

class ImportProcessor {
    private $db;
    private $errors = [];
    private $warnings = [];
    private $debugInfo = [];

    public function __construct($database) {
        $this->db = $database;
    }

    /**
     * Process a single import record
     */
    public function processImport($importId) {
        try {
            $this->db->beginTransaction();
            
            // Get the import record
            $import = $this->getImportRecord($importId);
            if (!$import) {
                throw new Exception("Import record not found or already processed");
            }

            // Update status to processing
            $this->updateImportStatus($importId, 'processing');

            // Parse and validate JSON data
            $jsonData = $this->parseAndValidateJSON($import['json_data']);
            
            // Extract required data
            $clientInfo = $jsonData['client_info'] ?? [];
            $invoiceInfo = $jsonData['invoice_info'] ?? [];
            
            // Process products
            $products = $this->validateAndProcessProducts($invoiceInfo['ordered_products'] ?? []);
            
            if (empty($products)) {
                $this->warnings[] = "No valid products found in import";
            }

            // Look up Cargus location mapping for AWB data
            $locationMapping = $this->lookupLocationMapping($import['delivery_county'], $import['delivery_locality']);
            
            if (!$locationMapping) {
                $this->warnings[] = "No Cargus location mapping found for {$import['delivery_county']}, {$import['delivery_locality']}. AWB generation will not be available.";
            }

            // Create order with all required fields including AWB data
            $orderId = $this->createOrder($import, $clientInfo, $invoiceInfo, $locationMapping);
            
            // Add order items
            $this->addOrderItems($orderId, $products);
            
            // Mark import as completed
            $this->updateImportStatus($importId, 'converted', $orderId);
            
            $this->db->commit();
            
            // Log successful processing
            error_log("ImportProcessor: Successfully processed import $importId -> order $orderId");
            
            return [
                'success' => true,
                'order_id' => $orderId,
                'import_id' => $importId,
                'warnings' => $this->warnings,
                'awb_ready' => !empty($locationMapping),
                'debug_info' => $this->debugInfo
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            $this->updateImportStatus($importId, 'failed', null, $e->getMessage());
            
            error_log("ImportProcessor Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get import record from database
     */
    private function getImportRecord($importId) {
        $query = "SELECT * FROM order_imports WHERE id = :import_id AND processing_status = 'pending'";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':import_id' => $importId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Update import processing status
     */
    private function updateImportStatus($importId, $status, $orderId = null, $errorMessage = null) {
        $query = "UPDATE order_imports SET 
                  processing_status = :status, 
                  conversion_attempts = conversion_attempts + 1,
                  last_attempt_at = CURRENT_TIMESTAMP";
        
        $params = [':status' => $status, ':import_id' => $importId];
        
        if ($orderId) {
            $query .= ", wms_order_id = :order_id";
            $params[':order_id'] = $orderId;
        }
        
        if ($errorMessage) {
            $query .= ", conversion_errors = :error_message";
            $params[':error_message'] = $errorMessage;
        }
        
        $query .= " WHERE id = :import_id";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
    }

    /**
     * Parse and validate JSON data
     */
    private function parseAndValidateJSON($jsonString) {
        $jsonData = json_decode($jsonString, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON data in import: ' . json_last_error_msg());
        }
        
        if (empty($jsonData)) {
            throw new Exception('Empty JSON data in import');
        }
        
        return $jsonData;
    }

    /**
     * Look up Cargus location IDs from county/city names
     */
    private function lookupLocationMapping($countyName, $cityName) {
        if (empty($countyName) || empty($cityName)) {
            return null;
        }
        
        // First try to find existing mapping
        $query = "
            SELECT cargus_county_id, cargus_locality_id, cargus_county_name, cargus_locality_name
            FROM address_location_mappings 
            WHERE LOWER(county_name) = LOWER(:county) 
            AND LOWER(locality_name) = LOWER(:city)
            AND cargus_county_id IS NOT NULL 
            AND cargus_locality_id IS NOT NULL
            ORDER BY mapping_confidence DESC, is_verified DESC
            LIMIT 1
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':county' => trim($countyName),
            ':city' => trim($cityName)
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            // Update usage stats
            $updateQuery = "UPDATE address_location_mappings 
                            SET usage_count = usage_count + 1, last_used_at = CURRENT_TIMESTAMP 
                            WHERE LOWER(county_name) = LOWER(:county) AND LOWER(locality_name) = LOWER(:city)";
            $updateStmt = $this->db->prepare($updateQuery);
            $updateStmt->execute([':county' => $countyName, ':city' => $cityName]);
            
            $this->debugInfo['location_mapping'] = [
                'input' => ['county' => $countyName, 'city' => $cityName],
                'found' => $result,
                'source' => 'database_cache'
            ];
            
            return $result;
        }
        
        // If not found, try to fetch from Cargus API
        $apiResult = $this->fetchLocationFromCargusAPI($countyName, $cityName);
        
        if ($apiResult) {
            $this->debugInfo['location_mapping'] = [
                'input' => ['county' => $countyName, 'city' => $cityName],
                'found' => $apiResult,
                'source' => 'cargus_api'
            ];
            return $apiResult;
        }
        
        // If still not found, insert a placeholder to avoid repeated API calls
        $this->insertPlaceholderMapping($countyName, $cityName);
        
        $this->debugInfo['location_mapping'] = [
            'input' => ['county' => $countyName, 'city' => $cityName],
            'found' => null,
            'message' => 'No mapping found in database or Cargus API'
        ];
        
        return null;
    }

    /**
     * Fetch location mapping from Cargus API
     */
    private function fetchLocationFromCargusAPI($countyName, $cityName) {
        try {
            // Initialize Cargus service
            require_once BASE_PATH . '/models/CargusService.php';
            $cargusService = new CargusService();
            
            // Get counties from Cargus API
            $counties = $this->getCargusCounties($cargusService);
            if (empty($counties)) {
                return null;
            }
            
            // Find matching county
            $matchingCounty = null;
            foreach ($counties as $county) {
                if (strtolower($county['CountyName']) === strtolower($countyName) ||
                    $this->isSimilarName($county['CountyName'], $countyName)) {
                    $matchingCounty = $county;
                    break;
                }
            }
            
            if (!$matchingCounty) {
                error_log("Cargus county not found for: $countyName");
                return null;
            }
            
            // Get localities for this county
            $localities = $this->getCargusLocalities($cargusService, $matchingCounty['CountyId']);
            if (empty($localities)) {
                return null;
            }
            
            // Find matching locality
            $matchingLocality = null;
            foreach ($localities as $locality) {
                if (strtolower($locality['LocalityName']) === strtolower($cityName) ||
                    $this->isSimilarName($locality['LocalityName'], $cityName)) {
                    $matchingLocality = $locality;
                    break;
                }
            }
            
            if (!$matchingLocality) {
                error_log("Cargus locality not found for: $cityName in county $countyName");
                return null;
            }
            
            // Store the mapping in database for future use
            $mappingData = [
                'county_name' => $countyName,
                'locality_name' => $cityName,
                'cargus_county_id' => $matchingCounty['CountyId'],
                'cargus_locality_id' => $matchingLocality['LocalityId'],
                'cargus_county_name' => $matchingCounty['CountyName'],
                'cargus_locality_name' => $matchingLocality['LocalityName'],
                'mapping_confidence' => 'high',
                'is_verified' => 1
            ];
            
            $this->storeLocationMapping($mappingData);
            
            return [
                'cargus_county_id' => $matchingCounty['CountyId'],
                'cargus_locality_id' => $matchingLocality['LocalityId'],
                'cargus_county_name' => $matchingCounty['CountyName'],
                'cargus_locality_name' => $matchingLocality['LocalityName']
            ];
            
        } catch (Exception $e) {
            error_log("Error fetching from Cargus API: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get counties from Cargus API with caching
     */
    private function getCargusCounties($cargusService) {
        static $counties = null;
        
        if ($counties === null) {
            try {
                // Check if we have cached counties (valid for 24 hours)
                $cacheQuery = "SELECT data_value FROM system_cache 
                              WHERE cache_key = 'cargus_counties' 
                              AND expires_at > NOW() 
                              LIMIT 1";
                $stmt = $this->db->prepare($cacheQuery);
                $stmt->execute();
                $cached = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($cached) {
                    $counties = json_decode($cached['data_value'], true);
                } else {
                    // Fetch from API using makeRequest method
                    $response = $this->makeCargusRequest($cargusService, 'GET', 'Counties');
                    
                    if ($response['success']) {
                        $counties = $response['data'];
                        
                        // Cache for 24 hours
                        $this->cacheData('cargus_counties', $counties, '+24 hours');
                    }
                }
            } catch (Exception $e) {
                error_log("Error getting Cargus counties: " . $e->getMessage());
                $counties = [];
            }
        }
        
        return $counties ?: [];
    }

    /**
     * Get localities from Cargus API with caching
     */
    private function getCargusLocalities($cargusService, $countyId) {
        $cacheKey = "cargus_localities_$countyId";
        
        try {
            // Check cache first
            $cacheQuery = "SELECT data_value FROM system_cache 
                          WHERE cache_key = :cache_key 
                          AND expires_at > NOW() 
                          LIMIT 1";
            $stmt = $this->db->prepare($cacheQuery);
            $stmt->execute([':cache_key' => $cacheKey]);
            $cached = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($cached) {
                return json_decode($cached['data_value'], true);
            }
            
            // Fetch from API
            $response = $this->makeCargusRequest($cargusService, 'GET', "Localities?countryId=1&countyId=$countyId");
            
            if ($response['success']) {
                $localities = $response['data'];
                
                // Cache for 24 hours
                $this->cacheData($cacheKey, $localities, '+24 hours');
                
                return $localities;
            }
            
        } catch (Exception $e) {
            error_log("Error getting Cargus localities for county $countyId: " . $e->getMessage());
        }
        
        return [];
    }

    /**
     * Make request to Cargus API (wrapper around CargusService)
     */
    private function makeCargusRequest($cargusService, $method, $endpoint, $data = null) {
        try {
            // Use reflection to access private method (not ideal but necessary)
            $reflection = new ReflectionClass($cargusService);
            $makeRequestMethod = $reflection->getMethod('makeRequest');
            $makeRequestMethod->setAccessible(true);
            
            return $makeRequestMethod->invoke($cargusService, $method, $endpoint, $data);
            
        } catch (Exception $e) {
            error_log("Cargus API request failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if two names are similar (handles variations)
     */
    private function isSimilarName($name1, $name2) {
        $name1 = strtolower(trim($name1));
        $name2 = strtolower(trim($name2));
        
        // Remove common suffixes/prefixes
        $name1 = preg_replace('/\b(mun\.|municipiul|oras|orasul|comuna)\s*/i', '', $name1);
        $name2 = preg_replace('/\b(mun\.|municipiul|oras|orasul|comuna)\s*/i', '', $name2);
        
        // Replace diacritics
        $replacements = [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't',
            'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ț' => 'T'
        ];
        
        $name1 = str_replace(array_keys($replacements), array_values($replacements), $name1);
        $name2 = str_replace(array_keys($replacements), array_values($replacements), $name2);
        
        // Check similarity
        return similar_text($name1, $name2) / max(strlen($name1), strlen($name2)) > 0.8;
    }

    /**
     * Store location mapping in database
     */
    private function storeLocationMapping($mappingData) {
        try {
            $query = "INSERT INTO address_location_mappings 
                     (county_name, locality_name, cargus_county_id, cargus_locality_id, 
                      cargus_county_name, cargus_locality_name, mapping_confidence, is_verified,
                      usage_count, last_used_at)
                     VALUES 
                     (:county_name, :locality_name, :cargus_county_id, :cargus_locality_id,
                      :cargus_county_name, :cargus_locality_name, :mapping_confidence, :is_verified,
                      1, CURRENT_TIMESTAMP)
                     ON DUPLICATE KEY UPDATE
                     cargus_county_id = VALUES(cargus_county_id),
                     cargus_locality_id = VALUES(cargus_locality_id),
                     cargus_county_name = VALUES(cargus_county_name),
                     cargus_locality_name = VALUES(cargus_locality_name),
                     mapping_confidence = VALUES(mapping_confidence),
                     is_verified = VALUES(is_verified),
                     usage_count = usage_count + 1,
                     last_used_at = CURRENT_TIMESTAMP";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($mappingData);
            
        } catch (Exception $e) {
            error_log("Error storing location mapping: " . $e->getMessage());
        }
    }

    /**
     * Insert placeholder mapping to avoid repeated API calls for non-existent locations
     */
    private function insertPlaceholderMapping($countyName, $cityName) {
        try {
            $query = "INSERT IGNORE INTO address_location_mappings 
                     (county_name, locality_name, cargus_county_id, cargus_locality_id, 
                      mapping_confidence, is_verified, last_used_at)
                     VALUES 
                     (:county_name, :locality_name, NULL, NULL, 'low', 0, CURRENT_TIMESTAMP)";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':county_name' => $countyName,
                ':locality_name' => $cityName
            ]);
            
        } catch (Exception $e) {
            error_log("Error inserting placeholder mapping: " . $e->getMessage());
        }
    }

    /**
     * Cache data with expiration
     */
    private function cacheData($key, $data, $expiry) {
        try {
            // Create system_cache table if it doesn't exist
            $this->ensureCacheTableExists();
            
            $expiresAt = date('Y-m-d H:i:s', strtotime($expiry));
            
            $query = "INSERT INTO system_cache (cache_key, data_value, expires_at)
                     VALUES (:key, :data, :expires_at)
                     ON DUPLICATE KEY UPDATE
                     data_value = VALUES(data_value),
                     expires_at = VALUES(expires_at),
                     updated_at = CURRENT_TIMESTAMP";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':key' => $key,
                ':data' => json_encode($data),
                ':expires_at' => $expiresAt
            ]);
            
        } catch (Exception $e) {
            error_log("Error caching data: " . $e->getMessage());
        }
    }

    /**
     * Ensure cache table exists
     */
    private function ensureCacheTableExists() {
        static $tableChecked = false;
        
        if (!$tableChecked) {
            try {
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS system_cache (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        cache_key VARCHAR(255) UNIQUE NOT NULL,
                        data_value LONGTEXT NOT NULL,
                        expires_at TIMESTAMP NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_cache_key (cache_key),
                        INDEX idx_expires_at (expires_at)
                    )
                ");
                $tableChecked = true;
            } catch (Exception $e) {
                error_log("Error creating cache table: " . $e->getMessage());
            }
        }
    }

    /**
     * Validate and process products
     */
    private function validateAndProcessProducts($orderedProducts) {
        $processedProducts = [];
        
        if (empty($orderedProducts) || !is_array($orderedProducts)) {
            $this->warnings[] = "No products provided in import data";
            return $processedProducts;
        }
        
        foreach ($orderedProducts as $index => $product) {
            try {
                // Extract product information
                $productCode = $product['code'] ?? $product['sku'] ?? '';
                $productName = $product['name'] ?? $product['product_name'] ?? '';
                $quantity = floatval($product['quantity'] ?? $product['qty'] ?? 0);
                $unitPrice = floatval($product['unit_price'] ?? $product['price'] ?? 0);
                
                if ($quantity <= 0) {
                    $this->warnings[] = "Product $index: Invalid quantity ($quantity)";
                    continue;
                }
                
                // Try to find matching warehouse product
                $warehouseProduct = $this->findWarehouseProduct($productCode, $productName);
                
                if (!$warehouseProduct) {
                    $this->warnings[] = "Product $index: No warehouse match found for '$productCode' / '$productName'";
                    continue;
                }
                
                $processedProducts[] = [
                    'warehouse_product' => $warehouseProduct,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'original_code' => $productCode,
                    'original_name' => $productName
                ];
                
            } catch (Exception $e) {
                $this->warnings[] = "Product $index processing error: " . $e->getMessage();
            }
        }
        
        return $processedProducts;
    }

    /**
     * Find matching warehouse product
     */
    private function findWarehouseProduct($productCode, $productName) {
        // Try exact SKU match first
        if (!empty($productCode)) {
            $query = "SELECT * FROM products WHERE sku = :sku LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':sku' => $productCode]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $this->debugInfo['found_matches'][] = [
                    'sku_searched' => $productCode,
                    'found_product' => $result['name'],
                    'found_sku' => $result['sku'],
                    'match_type' => 'exact_sku'
                ];
                return $result;
            }
        }

        // Try partial SKU match
        if (!empty($productCode)) {
            $query = "SELECT * FROM products WHERE sku LIKE :sku LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':sku' => $productCode . '%']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $this->debugInfo['found_matches'][] = [
                    'sku_searched' => $productCode . ' (partial)',
                    'found_product' => $result['name'],
                    'found_sku' => $result['sku'],
                    'match_type' => 'partial_sku'
                ];
                return $result;
            }
        }

        // Try name matching (if no SKU provided)
        if (!empty($productName)) {
            $query = "SELECT * FROM products WHERE LOWER(name) LIKE LOWER(:name) LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':name' => '%' . $productName . '%']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $this->debugInfo['found_matches'][] = [
                    'name_searched' => $productName,
                    'found_product' => $result['name'],
                    'found_sku' => $result['sku'],
                    'match_type' => 'name_match'
                ];
                return $result;
            }
        }
        
        $this->debugInfo['no_matches'][] = [
            'sku' => $productCode,
            'name' => $productName
        ];
        
        return null;
    }

    /**
     * Create order with comprehensive data including AWB fields
     */
    private function createOrder($import, $clientInfo, $invoiceInfo, $locationMapping) {
        $orderNumber = $this->generateOrderNumber();
        $shippingAddress = $this->buildShippingAddress($import);
        
        // Determine total value for priority calculation
        $totalValue = floatval($import['total_value'] ?? 0);
        $priority = $this->determinePriority($totalValue, $import['company_name'] ?? $import['contact_person_name']);
        
        // Get system user ID
        $systemUserId = $this->getSystemUserId();
        
        // Prepare order data matching the actual orders table structure
        $orderData = [
            'order_number' => $orderNumber,
            'customer_name' => $import['company_name'] ?? $import['contact_person_name'],
            'customer_email' => $import['contact_email'] ?? '',
            'type' => 'outbound',
            'status' => 'pending',
            'priority' => $priority,
            'shipping_address' => $shippingAddress,
            'notes' => $this->buildOrderNotes($import, $invoiceInfo),
            'total_value' => $totalValue,
            'created_by' => $systemUserId ?: 1,
            
            // AWB/Shipping data - populated from location mapping
            'recipient_county_id' => $locationMapping['cargus_county_id'] ?? null,
            'recipient_locality_id' => $locationMapping['cargus_locality_id'] ?? null,
            'recipient_county_name' => $locationMapping['cargus_county_name'] ?? $import['delivery_county'],
            'recipient_locality_name' => $locationMapping['cargus_locality_name'] ?? $import['delivery_locality'],
            'recipient_contact_person' => $import['contact_person_name'] ?? $import['company_name'],
            'recipient_phone' => $this->normalizePhoneNumber($import['contact_phone'] ?? ''),
            'recipient_email' => $import['contact_email'] ?? '',
            'recipient_street_name' => $import['delivery_street'] ?? '',
            'recipient_building_number' => '',
            
            // Default shipping details
            'total_weight' => floatval($import['estimated_weight'] ?? 1.0),
            'declared_value' => $totalValue,
            'parcels_count' => intval($import['parcels_count'] ?? 1),
            'envelopes_count' => intval($import['envelopes_count'] ?? 0),
            'cash_repayment' => floatval($import['cash_repayment'] ?? 0),
            'bank_repayment' => floatval($import['bank_repayment'] ?? 0),
            'saturday_delivery' => !empty($import['saturday_delivery']) ? 1 : 0,
            'morning_delivery' => !empty($import['morning_delivery']) ? 1 : 0,
            'open_package' => !empty($import['open_package']) ? 1 : 0,
            'observations' => $import['delivery_notes'] ?? '',
            'package_content' => $import['package_content'] ?? 'Various products',
            
            // References
            'sender_reference1' => $import['invoice_number'] ?? '',
            'recipient_reference1' => $import['customer_reference'] ?? '',
            'recipient_reference2' => $import['customer_reference2'] ?? '',
            'invoice_reference' => $import['invoice_number'] ?? ''
        ];

        // Build the INSERT query dynamically
        $fields = array_keys($orderData);
        $placeholders = ':' . implode(', :', $fields);
        $fieldsStr = implode(', ', $fields);
        
        $query = "INSERT INTO orders ($fieldsStr) VALUES ($placeholders)";
        
        $stmt = $this->db->prepare($query);
        
        // Bind parameters
        foreach ($orderData as $field => $value) {
            $stmt->bindValue(':' . $field, $value);
        }
        
        $stmt->execute();
        $orderId = $this->db->lastInsertId();
        
        if (!$orderId) {
            throw new Exception("Failed to create order in database");
        }
        
        $this->debugInfo['order_creation'] = [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'awb_ready' => !empty($locationMapping),
            'total_value' => $totalValue,
            'priority' => $priority
        ];
        
        return $orderId;
    }

    /**
     * Add order items to the database
     */
    private function addOrderItems($orderId, $processedProducts) {
        foreach ($processedProducts as $product) {
            $this->addOrderItem($orderId, $product['warehouse_product'], $product);
        }
    }

    /**
     * Add individual order item
     */
    private function addOrderItem($orderId, $warehouseProduct, $productData) {
        try {
            // Ensure we have valid values for NOT NULL fields
            $quantity = max(1, intval($productData['quantity']));
            $unitPrice = max(0, floatval($productData['unit_price']));
            
            $query = "
                INSERT INTO order_items (
                    order_id, product_id, quantity, quantity_ordered, unit_price
                ) VALUES (
                    :order_id, :product_id, :quantity, :quantity_ordered, :unit_price
                )
            ";
            
            $stmt = $this->db->prepare($query);
            $result = $stmt->execute([
                ':order_id' => $orderId,
                ':product_id' => $warehouseProduct['product_id'],
                ':quantity' => $quantity,
                ':quantity_ordered' => $quantity,
                ':unit_price' => $unitPrice
            ]);
            
            if (!$result) {
                throw new Exception("Failed to insert order item for product " . $warehouseProduct['product_id']);
            }
            
        } catch (PDOException $e) {
            error_log("Error inserting order item: " . $e->getMessage());
            throw new Exception("Database error inserting order item: " . $e->getMessage());
        }
    }

    /**
     * Build shipping address from import data
     */
    private function buildShippingAddress($import) {
        $parts = array_filter([
            $import['delivery_street'] ?? '',
            $import['delivery_locality'] ?? '',
            $import['delivery_county'] ?? '',
            $import['delivery_postal_code'] ?? ''
        ]);
        return implode(', ', $parts) ?: 'Address not provided';
    }

    /**
     * Build order notes from import data
     */
    private function buildOrderNotes($import, $invoiceInfo) {
        $notes = [];
        
        if (!empty($import['invoice_number'])) {
            $notes[] = "Invoice: {$import['invoice_number']}";
        }
        
        if (!empty($import['seller_name'])) {
            $notes[] = "Seller: {$import['seller_name']}";
        }
        
        if (!empty($import['client_cui'])) {
            $notes[] = "CUI: {$import['client_cui']}";
        }
        
        if (!empty($invoiceInfo['notes'])) {
            $notes[] = "Notes: {$invoiceInfo['notes']}";
        }
        
        $notes[] = "Imported from n8n automation on " . date('Y-m-d H:i:s');
        
        return implode(' | ', $notes);
    }

    /**
     * Generate unique order number
     */
    private function generateOrderNumber() {
        $prefix = 'ORD-' . date('Y');
        $query = "SELECT MAX(CAST(SUBSTRING(order_number, 10) AS UNSIGNED)) as max_num 
                  FROM orders WHERE order_number LIKE :prefix";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':prefix' => $prefix . '-%']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $nextNum = ($result['max_num'] ?? 0) + 1;
        return $prefix . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Determine order priority based on value and customer
     */
    private function determinePriority($totalValue, $customerName) {
        // High value orders
        if ($totalValue > 5000) {
            return 'high';
        }
        
        // VIP customers
        $vipKeywords = ['urgent', 'express', 'priority', 'vip', 'premium'];
        $customerLower = strtolower($customerName);
        
        foreach ($vipKeywords as $keyword) {
            if (strpos($customerLower, $keyword) !== false) {
                return 'high';
            }
        }
        
        return 'normal';
    }

    /**
     * Normalize phone number for Cargus format
     */
    private function normalizePhoneNumber($phone) {
        if (empty($phone)) {
            return '';
        }
        
        // Remove all non-numeric characters except + at the beginning
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        // Ensure Romanian format
        if (substr($phone, 0, 1) === '0') {
            $phone = '+4' . $phone;
        } elseif (substr($phone, 0, 2) === '40') {
            $phone = '+' . $phone;
        } elseif (substr($phone, 0, 3) !== '+40') {
            $phone = '+40' . ltrim($phone, '+');
        }
        
        return $phone;
    }

    /**
     * Get or create system user for automated processes
     */
    private function getSystemUserId() {
        static $systemUserId = null;
        
        if ($systemUserId === null) {
            $query = "SELECT id FROM users WHERE username = 'system_automation' LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $systemUserId = $result['id'];
            } else {
                // Create system user
                try {
                    $insertQuery = "INSERT INTO users (username, email, password, role, status) 
                                   VALUES ('system_automation', 'system@notsowms.ro', 'disabled', 'system', 1)";
                    $insertStmt = $this->db->prepare($insertQuery);
                    $insertStmt->execute();
                    $systemUserId = $this->db->lastInsertId();
                } catch (Exception $e) {
                    error_log("Failed to create system user: " . $e->getMessage());
                    $systemUserId = 1; // Fallback to admin user
                }
            }
        }
        
        return $systemUserId;
    }
}

// Main execution
try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        exit;
    }

    // Get import_id parameter
    $importId = $_GET['import_id'] ?? null;

    if (!$importId || !is_numeric($importId)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'import_id is required and must be numeric']);
        exit;
    }

    // Initialize database connection
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database configuration error']);
        exit;
    }

    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    // Process the import
    $processor = new ImportProcessor($db);
    $result = $processor->processImport($importId);

    // Return success response
    echo json_encode([
        'status' => 'success',
        'message' => 'Import processed successfully',
        'data' => $result
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    // Log the error
    error_log("ImportProcessor Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
}