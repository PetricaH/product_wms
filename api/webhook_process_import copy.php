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
    private function validateAndProcessProducts($products) {
        if (empty($products) || !is_array($products)) {
            return [];
        }

        $processedProducts = [];
        $accountingLines = []; // Store all accounting lines for audit trail
        
        // Step 1: Store all accounting lines for audit trail
        foreach ($products as $index => $product) {
            $totalPrice = $this->parsePrice($product['total_price'] ?? 0);
            $unitPrice = $this->parsePrice($product['price'] ?? 0);
            
            $accountingLines[] = [
                'line_number' => $index + 1,
                'code' => trim($product['code'] ?? ''),
                'name' => trim($product['name'] ?? ''),
                'unit' => trim($product['unit'] ?? 'bucata'),
                'quantity' => floatval($product['quantity'] ?? 0),
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice
            ];
        }
        
        // Step 2: Group products by code for consolidation
        $productGroups = [];
        foreach ($products as $product) {
            $code = trim($product['code'] ?? '');
            
            // Skip items without product codes
            if (empty($code)) {
                $this->warnings[] = "Skipping product without code: " . ($product['name'] ?? 'Unknown');
                continue;
            }
            
            if (!isset($productGroups[$code])) {
                $productGroups[$code] = [];
            }
            
            $unitPrice = $this->parsePrice($product['price'] ?? 0);
            $totalPrice = $this->parsePrice($product['total_price'] ?? 0);
            
            $productGroups[$code][] = [
                'name' => trim($product['name'] ?? ''),
                'unit' => trim($product['unit'] ?? 'bucata'),
                'quantity' => floatval($product['quantity'] ?? 0),
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                // ✅ FIXED: Use price-based discount detection
                'is_discount' => ($unitPrice < 0 || $totalPrice < 0) || $this->isDiscountItem($product['name'] ?? '')
            ];
        }
        
        // Step 3: Consolidate each product group
        foreach ($productGroups as $code => $items) {
            $consolidatedProduct = $this->consolidateProductGroup($code, $items);
            
            // Add product if it has positive quantity
            if ($consolidatedProduct && $consolidatedProduct['net_quantity'] > 0) {
                $processedProducts[] = $consolidatedProduct;
            } elseif ($consolidatedProduct && $consolidatedProduct['net_quantity'] <= 0) {
                $this->warnings[] = "Product {$code} has zero/negative quantity after consolidation";
            }
        }
        
        // Store accounting lines for audit trail
        $this->debugInfo['accounting_lines'] = $accountingLines;
        $this->debugInfo['consolidated_products'] = $processedProducts;
        
        return $processedProducts;
    }

    private function consolidateProductGroup($code, $items) {
        if (empty($items)) {
            return null;
        }
        
        $productName = '';
        $unit = 'bucata';
        $unitPrice = 0;
        $totalPayment = 0;
        $physicalQuantity = 0;
        $regularItems = [];
        $discountItems = [];
        
        // Step 1: Separate regular items from discount items
        foreach ($items as $item) {
            if ($item['is_discount']) {
                $discountItems[] = $item;
            } else {
                $regularItems[] = $item;
            }
        }
        
        // Step 2: Get product info from regular items (priority) or fallback to first item
        if (!empty($regularItems)) {
            $productName = $regularItems[0]['name'];
            $unit = $regularItems[0]['unit'];
            $unitPrice = $regularItems[0]['total_price']; // Use total_price (with VAT) as unit price
        } else {
            // Fallback to first item if no regular items (shouldn't happen in normal cases)
            $productName = $items[0]['name'];
            $unit = $items[0]['unit'];
            $unitPrice = abs($items[0]['total_price']); // Use absolute value for display
        }
        
        // Step 3: Calculate physical quantity (ALL items get shipped)
        $physicalQuantity = count($regularItems);
        
        // Step 4: Calculate total payment (sum all prices including discounts)
        foreach ($items as $item) {
            $totalPayment += $item['total_price'];
        }
        
        // Step 5: Validation - ensure we have positive quantity
        if ($physicalQuantity <= 0) {
            return null;
        }
        
        // Step 6: Build consolidation notes
        $consolidationNotes = [];
        if (count($items) > 1) {
            $consolidationNotes[] = "Consolidated from " . count($items) . " accounting lines";
        }
        
        if (!empty($discountItems)) {
            $totalDiscount = 0;
            foreach ($discountItems as $discount) {
                $totalDiscount += abs($discount['total_price']);
            }
            $consolidationNotes[] = "Applied discounts: " . number_format($totalDiscount, 2) . " RON";
            $consolidationNotes[] = "Customer pays " . number_format($totalPayment, 2) . " RON for " . $physicalQuantity . " products";
        }
        
        return [
            'code' => $code,
            'name' => $productName,
            'unit' => $unit,
            'net_quantity' => $physicalQuantity, // Physical items to ship
            'effective_unit_price' => round($unitPrice, 2), // Original unit price (with VAT)
            'net_total_price' => round($totalPayment, 2), // What customer actually pays
            'original_items_count' => count($items),
            'has_discounts' => !empty($discountItems),
            'consolidation_notes' => implode(' | ', $consolidationNotes),
            // Debug info
            'debug_info' => [
                'regular_items' => count($regularItems),
                'discount_items' => count($discountItems),
                'physical_quantity' => $physicalQuantity,
                'original_unit_price' => $unitPrice,
                'total_payment_after_discounts' => $totalPayment
            ]
        ];
    }

    private function parsePrice($price) {
        if (is_numeric($price)) {
            return floatval($price);
        }
        
        // Handle string prices with commas and spaces
        $cleaned = str_replace([',', ' ', 'RON'], '', $price);
        return floatval($cleaned);
    }

    private function isDiscountItem($productName) {
        $discountKeywords = [
            'discount', 'reducere', 'scont', 'rabat', 'remise', 'reduction',
            'gratuit', 'gratis', 'free', '100%'
        ];
        $productNameLower = strtolower(trim($productName));
        
        // Check for discount keywords in product name
        foreach ($discountKeywords as $keyword) {
            if (strpos($productNameLower, $keyword) !== false) {
                return true;
            }
        }
        
        // Check for discount indicators in parentheses
        if (preg_match('/\(.*discount.*\)/i', $productNameLower)) {
            return true;
        }
        
        // DO NOT check for '-' in product name as it's part of product codes like "AP.-811"
        
        return false;
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
     * Calculate total weight from consolidated products
     */
    private function calculateOrderWeight($products, $import) {
        $totalWeight = 0;
        $weightNotes = [];
        
        foreach ($products as $product) {
            $productWeight = $this->calculateProductWeight($product, $weightNotes);
            $totalWeight += $productWeight * $product['net_quantity'];
            
            $this->debugInfo['weight_calculation'][] = [
                'product_code' => $product['code'],
                'product_name' => $product['name'],
                'quantity' => $product['net_quantity'],
                'unit_weight' => $productWeight,
                'total_weight' => $productWeight * $product['net_quantity'],
                'calculation_method' => $weightNotes[count($weightNotes) - 1] ?? 'default'
            ];
        }
        
        // Ensure minimum weight of 0.1 kg (100g) for shipping
        $totalWeight = max($totalWeight, 0.1);
        
        // Add packaging weight (estimate 5% extra for boxes/packaging)
        $packagingWeight = $totalWeight * 0.05;
        $finalWeight = round($totalWeight + $packagingWeight, 2);
        
        $this->debugInfo['weight_summary'] = [
            'products_weight' => $totalWeight,
            'packaging_weight' => $packagingWeight,
            'final_weight' => $finalWeight,
            'calculation_notes' => $weightNotes
        ];
        
        return $finalWeight;
    }

    /**
     * Calculate weight for individual product
     */
    private function calculateProductWeight($product, &$weightNotes) {
        $code = $product['code'];
        $name = strtolower($product['name']);
        
        // Method 1: Extract volume from code (e.g., WA616.25 = 25 liters)
        $volumeWeight = $this->extractWeightFromCode($code, $weightNotes);
        if ($volumeWeight > 0) {
            return $volumeWeight;
        }
        
        // Method 2: Check database for configured weight
        $dbWeight = $this->getProductWeightFromDatabase($code, $weightNotes);
        if ($dbWeight > 0) {
            return $dbWeight;
        }
        
        // Method 3: Intelligent defaults based on product type
        $categoryWeight = $this->getWeightByCategory($name, $weightNotes);
        if ($categoryWeight > 0) {
            return $categoryWeight;
        }
        
        // Method 4: Default fallback
        $weightNotes[] = "Default weight applied for unknown product: {$code}";
        return 1.0; // 1 kg default
    }

    /**
     * Extract weight from product codes like WA616.25 (25 liters)
     */
    private function extractWeightFromCode($code, &$weightNotes) {
        // Pattern: Letters + Numbers + Dot + Number (volume)
        if (preg_match('/^[A-Z]+\d*\.(\d+(?:\.\d+)?)$/i', $code, $matches)) {
            $volume = floatval($matches[1]);
            
            if ($volume > 0) {
                // For liquid products, assume density ~1.1 kg/liter (slightly heavier than water)
                $weight = $volume * 1.1;
                $weightNotes[] = "Extracted volume from code {$code}: {$volume}L = {$weight}kg";
                return $weight;
            }
        }
        
        // Pattern: Numbers in code might indicate weight/volume
        if (preg_match('/(\d+(?:\.\d+)?)\s*(kg|l|litri|liters?)/i', $code, $matches)) {
            $value = floatval($matches[1]);
            $unit = strtolower($matches[2]);
            
            if ($unit === 'kg') {
                $weightNotes[] = "Extracted weight from code {$code}: {$value}kg";
                return $value;
            } elseif (in_array($unit, ['l', 'litri', 'liter', 'liters'])) {
                $weight = $value * 1.1; // Convert liters to kg
                $weightNotes[] = "Extracted volume from code {$code}: {$value}L = {$weight}kg";
                return $weight;
            }
        }
        
        return 0;
    }

    /**
     * Get product weight from database (product_units table)
     */
    private function getProductWeightFromDatabase($code, &$weightNotes) {
        try {
            $query = "
                SELECT pu.weight_per_unit 
                FROM products p
                JOIN product_units pu ON p.product_id = pu.product_id
                WHERE p.sku = :code AND pu.active = 1
                LIMIT 1
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([':code' => $code]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['weight_per_unit'] > 0) {
                $weight = floatval($result['weight_per_unit']);
                $weightNotes[] = "Database weight for {$code}: {$weight}kg";
                return $weight;
            }
        } catch (Exception $e) {
            error_log("Error getting product weight from database: " . $e->getMessage());
        }
        
        return 0;
    }

    /**
     * Intelligent weight defaults based on product category/name
     */
    private function getWeightByCategory($productName, &$weightNotes) {
        $name = strtolower($productName);
        
        // Chemical/Industrial products
        if (preg_match('/\b(spuma|spumă|detergent|chimical|chemical|acid|spray)\b/i', $name)) {
            $weightNotes[] = "Chemical product category: 2.5kg default";
            return 2.5;
        }
        
        // Electronic appliances
        if (preg_match('/\b(statie|stație|calcat|iron|electronic|aparat|device)\b/i', $name)) {
            $weightNotes[] = "Electronic appliance category: 3.0kg default";
            return 3.0;
        }
        
        // Tools/Hardware
        if (preg_match('/\b(tool|unelte|scule|hardware|metal)\b/i', $name)) {
            $weightNotes[] = "Tools/hardware category: 1.5kg default";
            return 1.5;
        }
        
        // Containers/Barrels (empty)
        if (preg_match('/\b(barrel|butoias|container|bidon)\b/i', $name)) {
            $weightNotes[] = "Container category: 4.0kg default";
            return 4.0;
        }
        
        // Small items/accessories
        if (preg_match('/\b(accesor|piesa|component|small|mic)\b/i', $name)) {
            $weightNotes[] = "Small item category: 0.5kg default";
            return 0.5;
        }
        
        return 0;
    }

    /**
     * Create order with comprehensive data including AWB fields
     */
    private function createOrder($import, $clientInfo, $invoiceInfo, $locationMapping) {
        $orderNumber = $this->generateOrderNumber();
        $shippingAddress = $this->buildShippingAddress($import);
        
        // Calculate total from consolidated products
        $calculatedTotal = 0;
        if (!empty($this->debugInfo['consolidated_products'])) {
            foreach ($this->debugInfo['consolidated_products'] as $product) {
                $calculatedTotal += $product['net_total_price'];
            }
        }
        
        $totalValue = $calculatedTotal > 0 ? $calculatedTotal : floatval($import['total_value'] ?? 0);
        $priority = $this->determinePriority($totalValue, $import['company_name'] ?? $import['contact_person_name']);
        
        $calculatedWeight = 0;
        if (!empty($this->debugInfo['consolidated_products'])) {
            $calculatedWeight = $this->calculateOrderWeight($this->debugInfo['consolidated_products'], $import);
        }
        
        // Use calculated weight or fallback to import/default
        $totalWeight = $calculatedWeight > 0 ? $calculatedWeight : floatval($import['estimated_weight'] ?? 1.0);
        
        $systemUserId = $this->getSystemUserId();
        
        // Prepare order data
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
            
            // AWB/Shipping data
            'recipient_county_id' => $locationMapping['cargus_county_id'] ?? null,
            'recipient_locality_id' => $locationMapping['cargus_locality_id'] ?? null,
            'recipient_county_name' => $locationMapping['cargus_county_name'] ?? $import['delivery_county'],
            'recipient_locality_name' => $locationMapping['cargus_locality_name'] ?? $import['delivery_locality'],
            'recipient_contact_person' => $import['contact_person_name'] ?? $import['company_name'],
            'recipient_phone' => $this->normalizePhoneNumber($import['contact_phone'] ?? ''),
            'recipient_email' => $import['contact_email'] ?? '',
            'recipient_street_name' => $import['delivery_street'] ?? '',
            'recipient_building_number' => '',
            
            // ✅ Smart weight calculation
            'total_weight' => $totalWeight,
            'declared_value' => $totalValue,
            'parcels_count' => $this->calculateParcelsCount($totalWeight, $this->debugInfo['consolidated_products'] ?? []),
            'envelopes_count' => intval($import['envelopes_count'] ?? 0),
            'cash_repayment' => floatval($import['cash_repayment'] ?? 0),
            'bank_repayment' => floatval($import['bank_repayment'] ?? 0),
            'saturday_delivery' => !empty($import['saturday_delivery']) ? 1 : 0,
            'morning_delivery' => !empty($import['morning_delivery']) ? 1 : 0,
            'open_package' => !empty($import['open_package']) ? 1 : 0,
            'observations' => $import['delivery_notes'] ?? '',
            'package_content' => $this->buildPackageContent($this->debugInfo['consolidated_products'] ?? []),
            
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
            'calculated_total' => $calculatedTotal,
            'calculated_weight' => $calculatedWeight,
            'used_weight' => $totalWeight,
            'parcels_count' => $orderData['parcels_count'],
            'awb_ready' => !empty($locationMapping),
            'priority' => $priority
        ];

        $this->debugInfo['total_calculation'] = [
            'calculated_from_products' => $calculatedTotal,
            'import_total_value' => floatval($import['total_value'] ?? 0),
            'final_total_used' => $totalValue,
            'products_count' => count($this->debugInfo['consolidated_products'] ?? [])
        ];
        
        return $orderId;
    }


    /**
     * Calculate number of parcels based on weight and products
     */
    private function calculateParcelsCount($totalWeight, $products) {
        $itemsCount = count($products);
        
        // If total weight > 20kg, split into multiple parcels
        if ($totalWeight > 20) {
            return ceil($totalWeight / 20); // Max 20kg per parcel
        }
        
        // If many different products, might need multiple boxes
        if ($itemsCount > 5) {
            return min(ceil($itemsCount / 5), 3); // Max 5 items per box, max 3 boxes
        }
        
        // Default: 1 parcel
        return 1;
    }

    /**
     * Build package content description for Cargus
     */
    private function buildPackageContent($products) {
        if (empty($products)) {
            return 'Various products';
        }
        
        $descriptions = [];
        foreach ($products as $product) {
            $desc = $product['net_quantity'] . 'x ' . $product['name'];
            if (strlen($desc) > 50) {
                $desc = $product['net_quantity'] . 'x ' . substr($product['name'], 0, 45) . '...';
            }
            $descriptions[] = $desc;
        }
        
        $content = implode(', ', $descriptions);
        
        // Truncate if too long (Cargus has limits)
        if (strlen($content) > 200) {
            $content = substr($content, 0, 195) . '...';
        }
        
        return $content;
    }


    /**
     * Add order items to the database
     */
    private function addOrderItems($orderId, $products) {
        if (empty($products)) {
            return;
        }
        
        foreach ($products as $product) {
            try {
                // Look up warehouse product
                $warehouseProduct = $this->findWarehouseProduct($product['code'], $product['name']);
                
                if (!$warehouseProduct) {
                    $this->warnings[] = "Product not found in warehouse: {$product['code']} - {$product['name']}";
                    continue;
                }
                
                // Ensure proper decimal precision for prices
                $unitPrice = round(floatval($product['effective_unit_price']), 2);
                $quantity = intval($product['net_quantity']);
                
                // Insert order item using actual table schema
                $query = "INSERT INTO order_items (
                    order_id, product_id, quantity, quantity_ordered, unit_price, unit_measure
                ) VALUES (
                    :order_id, :product_id, :quantity, :quantity_ordered, :unit_price, :unit_measure
                )";
                
                $stmt = $this->db->prepare($query);
                $stmt->execute([
                    ':order_id' => $orderId,
                    ':product_id' => $warehouseProduct['product_id'],
                    ':quantity' => $quantity,
                    ':quantity_ordered' => $quantity,
                    ':unit_price' => $unitPrice, // ✅ Properly rounded decimal
                    ':unit_measure' => $product['unit'] ?? 'bucata'
                ]);
                
                $this->debugInfo['order_items'][] = [
                    'product_code' => $product['code'],
                    'product_name' => $product['name'],
                    'net_quantity' => $quantity,
                    'unit_price_stored' => $unitPrice, // ✅ Show actual stored price
                    'effective_price' => $product['effective_unit_price'],
                    'net_total' => $product['net_total_price'],
                    'original_lines' => $product['original_items_count'],
                    'consolidation_notes' => $product['consolidation_notes']
                ];
                
            } catch (Exception $e) {
                $this->warnings[] = "Error adding order item {$product['code']}: " . $e->getMessage();
                error_log("Order item error: " . $e->getMessage() . " - Data: " . json_encode($product));
            }
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
        
        // Add consolidation summary
        if (!empty($this->debugInfo['accounting_lines']) && !empty($this->debugInfo['consolidated_products'])) {
            $accountingLines = count($this->debugInfo['accounting_lines']);
            $consolidatedItems = count($this->debugInfo['consolidated_products']);
            $notes[] = "Consolidated: $accountingLines accounting lines → $consolidatedItems pick items";
        }
        
        // Add weight calculation summary
        if (!empty($this->debugInfo['weight_summary'])) {
            $weightSummary = $this->debugInfo['weight_summary'];
            $notes[] = "Weight: {$weightSummary['final_weight']}kg calculated ({$weightSummary['products_weight']}kg products + {$weightSummary['packaging_weight']}kg packaging)";
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