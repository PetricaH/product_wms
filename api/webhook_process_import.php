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
require_once BASE_PATH . '/models/WeightCalculator.php';

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
            
            // Process products with CORRECT consolidation logic
            $products = $this->validateAndProcessProducts($invoiceInfo['ordered_products'] ?? []);
            
            if (empty($products)) {
                throw new Exception("No valid products found in import");
            }

            // Look up Cargus location mapping for AWB data
            $locationMapping = $this->lookupLocationMapping($import['delivery_county'], $import['delivery_locality']);
            
            if (!$locationMapping) {
                $this->warnings[] = "No Cargus location mapping found for {$import['delivery_county']}, {$import['delivery_locality']}. AWB generation will not be available.";
            }

            // Create order with FIXED data
            $orderId = $this->createOrder($import, $clientInfo, $invoiceInfo, $locationMapping);
            
            // Add order items with FIXED logic - NO DUPLICATES
            $this->addOrderItems($orderId, $products);

              // Calculate shipping using WeightCalculator and update order
            $calculator = new WeightCalculator($this->db);
            $shipping = $calculator->calculateOrderShipping($orderId);
            $this->applyShippingToOrder($orderId, $shipping);
            $this->debugInfo['weight_summary'] = $shipping;
            
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
            
            error_log("ImportProcessor Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            throw $e;
        }
    }

    /**
     * FIXED: Parse numeric values correctly (handle commas and strings)
     */
    private function parseNumericValue($value) {
        if (is_numeric($value)) {
            return floatval($value);
        }
        
        if (is_string($value)) {
            // Remove commas and convert to float
            $cleaned = str_replace(',', '', trim($value));
            return is_numeric($cleaned) ? floatval($cleaned) : 0;
        }
        
        return 0;
    }

    /**
     * FIXED: Validate and process products with correct discount consolidation
     */
    /**
     * FIXED: Validate and process products with correct discount consolidation
     */
    private function validateAndProcessProducts($products) {
        if (empty($products) || !is_array($products)) {
            return [];
        }

        $this->debugInfo['accounting_lines'] = [];
        $consolidatedByCode = [];
        
        // First pass: Parse all lines and store debug info
        foreach ($products as $index => $product) {
            // Validate required fields
            if (empty($product['name']) || !isset($product['quantity'])) {
                $this->warnings[] = "Product at index $index missing required fields";
                continue;
            }

            // Parse values correctly
            $quantity = $this->parseNumericValue($product['quantity']);
            $unitPrice = $this->parseNumericValue($product['price'] ?? 0);
            $totalPrice = $this->parseNumericValue($product['total_price'] ?? 0);
            $code = trim($product['code'] ?? '');
            $name = trim($product['name']);
            $unit = $product['unit'] ?? 'bucata';
            $isDiscount = strpos(strtolower($name), 'discount') !== false;
            
            if ($quantity <= 0) {
                $this->warnings[] = "Invalid quantity for product: $name";
                continue;
            }

            // Store accounting line for debug
            $this->debugInfo['accounting_lines'][] = [
                'line_number' => $index + 1,
                'code' => $code,
                'name' => $name,
                'unit' => $unit,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'is_discount' => $isDiscount
            ];

            // Consolidate by product code correctly
            $productKey = $code ?: $name; // Use code if available, fallback to name
            
            if (!isset($consolidatedByCode[$productKey])) {
                $consolidatedByCode[$productKey] = [
                    'code' => $code,
                    'name' => $this->getCleanProductName($name), // Remove discount markers
                    'unit' => $unit,
                    'physical_quantity' => 0, // <-- CHANGE: Track physical items separately
                    'total_price' => 0,
                    'lines' => [],
                    'has_discounts' => false
                ];
            }

            // --- LOGIC CHANGE ---
            // Only add to the physical quantity if it is NOT a discount line.
            if (!$isDiscount) {
                $consolidatedByCode[$productKey]['physical_quantity'] += $quantity;
            }
            
            // The total price is always adjusted.
            $consolidatedByCode[$productKey]['total_price'] += $totalPrice;
            // --- END LOGIC CHANGE ---

            $consolidatedByCode[$productKey]['lines'][] = [
                'line' => $index + 1,
                'name' => $name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'is_discount' => $isDiscount
            ];

            if ($isDiscount) {
                $consolidatedByCode[$productKey]['has_discounts'] = true;
            }
        }

        // Second pass: Create final consolidated products
        $finalProducts = [];
        $this->debugInfo['consolidated_products'] = [];
        
        foreach ($consolidatedByCode as $productKey => $consolidated) {
            // Use the new physical_quantity field for calculations
            $finalQuantity = $consolidated['physical_quantity'];

            if ($finalQuantity <= 0) {
                $this->warnings[] = "Product {$consolidated['name']} has zero or negative final quantity after consolidation";
                continue;
            }

            // Calculate effective unit price from total and PHYSICAL quantity
            $effectiveUnitPrice = $finalQuantity > 0 
                ? ($consolidated['total_price'] / $finalQuantity) 
                : 0;

            // Create single product entry
            $finalProduct = [
                'code' => $consolidated['code'],
                'name' => $consolidated['name'],
                'unit' => $consolidated['unit'],
                'net_quantity' => $finalQuantity, // <-- CHANGE: Use final physical quantity
                'effective_unit_price' => $effectiveUnitPrice,
                'net_total_price' => $consolidated['total_price'],
                'original_items_count' => count($consolidated['lines']),
                'has_discounts' => $consolidated['has_discounts'],
                'consolidation_notes' => $this->generateConsolidationNotes($consolidated['lines']),
                'debug_info' => [
                    'regular_items' => count(array_filter($consolidated['lines'], fn($l) => !$l['is_discount'])),
                    'discount_items' => count(array_filter($consolidated['lines'], fn($l) => $l['is_discount'])),
                    'physical_quantity' => $finalQuantity, // <-- CHANGE
                    'total_payment_after_discounts' => $consolidated['total_price']
                ]
            ];

            $finalProducts[] = $finalProduct;
            $this->debugInfo['consolidated_products'][] = $finalProduct;
        }

        return $finalProducts;
    }

    /**
     * Remove discount markers from product names
     */
    private function getCleanProductName($name) {
        // Remove discount indicators like "(Discount 100%)"
        $cleanName = preg_replace('/\s*\(discount\s+\d+%\)\s*/i', '', $name);
        return trim($cleanName);
    }

    /**
     * Generate consolidation notes for products with multiple lines
     */
    private function generateConsolidationNotes($lines) {
        if (count($lines) <= 1) {
            return '';
        }

        $regularCount = count(array_filter($lines, fn($l) => !$l['is_discount']));
        $discountCount = count(array_filter($lines, fn($l) => $l['is_discount']));

        $notes = "Consolidated from " . count($lines) . " lines: ";
        if ($regularCount > 0) {
            $notes .= "$regularCount regular";
        }
        if ($discountCount > 0) {
            $notes .= ($regularCount > 0 ? ", " : "") . "$discountCount discount";
        }
        
        return $notes;
    }

    /**
     * FIXED: Map payment method from text to numeric values
     */
    private function mapPaymentMethod($paymentMethod, $totalValue) {
        $paymentMethod = strtoupper(trim($paymentMethod));
        
        switch ($paymentMethod) {
            case 'RAMBURS':
                // Cash on delivery - customer pays cash to courier
                return [
                    'cash_repayment' => $totalValue,
                    'bank_repayment' => 0.00
                ];
                
            case 'OP':
            default:
                // Online payment/prepaid - already paid, no COD
                return [
                    'cash_repayment' => 0.00,
                    'bank_repayment' => 0.00
                ];
        }
    }

    /**
     * FIXED: Create order with correct total value and payment method
     */
    private function createOrder($import, $clientInfo, $invoiceInfo, $locationMapping) {
        $orderNumber = $this->generateOrderNumber();
        $shippingAddress = $this->buildShippingAddress($import, $clientInfo);

        // Determine postal code using address_location_mappings table
        $postalCode = $this->lookupPostalCode(
            $import['delivery_county'] ?? '',
            $import['delivery_locality'] ?? ''
        );
        
        // FIXED: Calculate correct total from consolidated products
        $calculatedTotal = 0;
        if (!empty($this->debugInfo['consolidated_products'])) {
            foreach ($this->debugInfo['consolidated_products'] as $product) {
                $calculatedTotal += $product['net_total_price'];
            }
        }
        
        // Use calculated total (which includes discounts properly applied)
        $totalValue = $calculatedTotal > 0 ? $calculatedTotal : $this->parseNumericValue($import['total_value'] ?? 0);
        
        $priority = $this->determinePriority($totalValue, $import['company_name'] ?? $import['contact_person_name']);
        
        $systemUserId = $this->getSystemUserId();
        
        // FIXED: Map payment method correctly
        $paymentMapping = $this->mapPaymentMethod($invoiceInfo['payment_method'] ?? 'OP', $totalValue);

        // Prepare order data with CORRECT values and proper decimal formatting
        $orderData = [
            'order_number' => $orderNumber,
            'customer_name' => $import['company_name'] ?? $import['contact_person_name'],
            'customer_email' => $import['contact_email'] ?? '',
            'type' => 'outbound',
            'status' => 'pending',
            'priority' => $priority,
            'shipping_address' => $shippingAddress,
            'address_text' => $clientInfo['address'] ?? $shippingAddress,
            'notes' => $this->buildOrderNotes($import, $invoiceInfo),
            'total_value' => number_format($totalValue, 2, '.', ''), // FIXED: Ensure proper decimal format
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
            'recipient_postal' => $postalCode,
            
            // Weight and parcels
            'total_weight' => number_format(0, 3, '.', ''),
            'declared_value' => number_format($totalValue, 2, '.', ''),
            'parcels_count' => 0,
            'envelopes_count' => intval($import['envelopes_count'] ?? 0),
            
            // FIXED: Payment method mapping with proper decimal format
            'cash_repayment' => number_format($paymentMapping['cash_repayment'], 2, '.', ''),
            'bank_repayment' => number_format($paymentMapping['bank_repayment'], 2, '.', ''),
            
            // Delivery options
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
        
        // Bind all parameters
        foreach ($orderData as $field => $value) {
            $stmt->bindValue(':' . $field, $value);
        }
        
        $stmt->execute();
        $orderId = $this->db->lastInsertId();

        // Store debug info
        $this->debugInfo['order_creation'] = [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'calculated_total' => $calculatedTotal,
            'final_total_used' => $totalValue,
            'awb_ready' => !empty($locationMapping),
            'priority' => $priority,
            'payment_method' => $invoiceInfo['payment_method'] ?? 'OP',
            'payment_mapping' => $paymentMapping
        ];

        return $orderId;
    }

    /**
     * FIXED: Add order items - ONE per consolidated product, NO DUPLICATES
     */
    private function addOrderItems($orderId, $products) {
        $this->debugInfo['order_items'] = [];
        
        foreach ($products as $product) {
            // Find or create product in database
            $productId = $this->findOrCreateProduct($product);
            
            // FIXED: Ensure proper decimal handling and parameter binding
            $unitPrice = floatval($product['effective_unit_price']);
            $quantity = intval($product['net_quantity']);
            
            $query = "INSERT INTO order_items (
                order_id, product_id, quantity, unit_measure, quantity_ordered, unit_price
            ) VALUES (
                :order_id, :product_id, :quantity, :unit_measure, :quantity_ordered, :unit_price
            )";
            
            $stmt = $this->db->prepare($query);
            
            // FIXED: Explicit parameter binding with proper types
            $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->bindValue(':quantity', $quantity, PDO::PARAM_INT);
            $stmt->bindValue(':unit_measure', $product['unit'], PDO::PARAM_STR);
            $stmt->bindValue(':quantity_ordered', $quantity, PDO::PARAM_INT);
            $stmt->bindValue(':unit_price', $unitPrice, PDO::PARAM_STR); // Use PDO::PARAM_STR for decimals
            
            $stmt->execute();

            // Log what we actually tried to insert for debugging
            error_log("ImportProcessor: Inserted order_item - Product: {$product['code']}, Quantity: $quantity, Unit Price: $unitPrice, Order ID: $orderId, Product ID: $productId");

            // Debug info
            $this->debugInfo['order_items'][] = [
                'product_code' => $product['code'],
                'product_name' => $product['name'],
                'net_quantity' => $quantity,
                'unit_measure' => $product['unit'],
                'unit_price_attempted' => $unitPrice,
                'unit_price_formatted' => number_format($unitPrice, 2, '.', ''),
                'effective_price' => $product['effective_unit_price'],
                'net_total' => $product['net_total_price'],
                'original_lines' => $product['original_items_count'],
                'consolidation_notes' => $product['consolidation_notes'] ?? '',
                'product_id_used' => $productId,
                'order_id_used' => $orderId
            ];
        }
    }

    private function applyShippingToOrder($orderId, $shipping) {
        $stmt = $this->db->prepare(
            "UPDATE orders SET total_weight = :total_weight, parcels_count = :parcels_count, envelopes_count = :envelopes_count, package_content = :package_content WHERE id = :order_id"
        );
        $stmt->execute([
            ':total_weight' => $shipping['total_weight'] ?? 0,
            ':parcels_count' => $shipping['parcels_count'] ?? 0,
            ':envelopes_count' => $shipping['envelopes_count'] ?? 0,
            ':package_content' => $shipping['package_content'] ?? '',
            ':order_id' => $orderId
        ]);
    }

    /**
     * Build package content description
     */
    private function buildPackageContent($products) {
        if (empty($products)) {
            return '';
        }
        
        $content = [];
        foreach ($products as $product) {
            $content[] = $product['name'] . ' x' . $product['net_quantity'];
        }
        
        return implode(', ', array_slice($content, 0, 3)); // Limit to first 3 products
    }

    /**
     * Find or create product in database
     */
    private function findOrCreateProduct($productData) {
        // Try to find existing product by code/SKU
        if (!empty($productData['code'])) {
            $query = "SELECT product_id FROM products WHERE sku = :sku LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':sku' => $productData['code']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return $result['product_id'];
            }
        }
        
        // Create new product if not found
        $query = "INSERT INTO products (
            sku, name, status, created_at
        ) VALUES (
            :sku, :name, 'active', NOW()
        )";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':sku' => $productData['code'] ?: '',
            ':name' => $productData['name']
        ]);
        
        return $this->db->lastInsertId();
    }

    /**
     * Generate unique order number
     */
    private function generateOrderNumber() {
        return 'ORD-' . date('Y') . '-' . str_pad($this->getNextOrderSequence(), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get next order sequence number
     */
    private function getNextOrderSequence() {
        $year = date('Y');
        $query = "SELECT COUNT(*) + 1 as next_seq FROM orders WHERE YEAR(created_at) = :year";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':year' => $year]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['next_seq'] : 1;
    }

    /**
     * Build shipping address string
     */
    private function buildShippingAddress($import, $clientInfo = []) {
        $parts = array_filter([
            $import['delivery_street']
                ?? $import['address']
                ?? ($clientInfo['address'] ?? ''),
            $import['delivery_locality']
                ?? $import['city']
                ?? ($clientInfo['city'] ?? ''),
            $import['delivery_county']
                ?? $import['county']
                ?? ($clientInfo['county'] ?? ''),
        ]);
        
        return implode(', ', $parts);
    }

    /**
     * Build order notes with consolidation details
     */
    private function buildOrderNotes($import, $invoiceInfo) {
        $notes = [];
        
        if (!empty($import['invoice_number'])) {
            $notes[] = "Invoice: {$import['invoice_number']}";
        }
        
        if (!empty($import['seller_name'])) {
            $notes[] = "Seller: {$import['seller_name']}";
        }
        
        // Add consolidation details
        if (count($this->debugInfo['consolidated_products']) > 0) {
            $productCount = count($this->debugInfo['consolidated_products']);
            $lineCount = count($this->debugInfo['accounting_lines']);
            $notes[] = "Consolidated: $lineCount accounting lines → $productCount pick items";
            
            // Add details about discounts if any
            $discountedProducts = array_filter($this->debugInfo['consolidated_products'], 
                fn($p) => $p['has_discounts'] === true);
            
            if (!empty($discountedProducts)) {
                $discountCount = count($discountedProducts);
                $notes[] = "$discountCount products with discounts applied";
            }
        }
        
        // Add payment method info
        $paymentMethod = $invoiceInfo['payment_method'] ?? 'OP';
        if ($paymentMethod === 'Ramburs') {
            $notes[] = "Payment: Cash on Delivery";
        } else {
            $notes[] = "Payment: Prepaid";
        }
        
        $notes[] = "Imported from n8n automation on " . date('Y-m-d H:i:s');
        
        return implode(' | ', $notes);
    }

    /**
     * Determine order priority based on value and customer
     */
    private function determinePriority($totalValue, $customerName) {
        if ($totalValue > 5000) {
            return 'high';
        } elseif ($totalValue > 2000) {
            return 'normal';
        } else {
            return 'normal';
        }
    }

    /**
     * Normalize phone number
     */
    private function normalizePhoneNumber($phone) {
        if (empty($phone)) {
            return '';
        }
        
        // Remove non-digits
        $clean = preg_replace('/[^0-9]/', '', $phone);
        
        // Add +40 prefix if missing
        if (strlen($clean) === 10 && substr($clean, 0, 1) === '0') {
            $clean = '40' . substr($clean, 1);
        }
        
        return $clean;
    }

    /**
     * Get system user ID
     */
    private function getSystemUserId() {
        try {
            $query = "SELECT id FROM users WHERE username = 'system' OR email LIKE '%system%' LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? $result['id'] : 1; // Fallback to admin user
        } catch (Exception $e) {
            error_log("Error getting system user ID: " . $e->getMessage());
            return 1; // Fallback to admin user
        }
    }

    /**
     * Look up Cargus location IDs from county/city names
     */
    private function lookupLocationMapping($countyName, $cityName) {
        if (empty($countyName) || empty($cityName)) {
            return null;
        }
        
        // Try to find existing mapping
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
            $this->debugInfo['location_mapping'] = [
                'input' => [
                    'county' => $countyName,
                    'city' => $cityName
                ],
                'found' => $result,
                'source' => 'database_cache'
            ];
            
            return $result;
        }
        
        return null;
    }

    /**
     * Find postal code using address_location_mappings table
     */
    private function lookupPostalCode($countyName, $cityName) {
        // First, attempt lookup by city/locality
        if (!empty($cityName)) {
            $query = "
                SELECT cargus_postal_code
                FROM address_location_mappings
                WHERE LOWER(locality_name) = LOWER(:city)
                  AND cargus_postal_code IS NOT NULL
                  AND cargus_postal_code <> ''
                ORDER BY mapping_confidence DESC, is_verified DESC
                LIMIT 1
            ";

            $stmt = $this->db->prepare($query);
            $stmt->execute([':city' => trim($cityName)]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && !empty($result['cargus_postal_code'])) {
                return $result['cargus_postal_code'];
            }
        }

        // Fallback to county-level lookup
        if (!empty($countyName)) {
            $query = "
                SELECT cargus_postal_code
                FROM address_location_mappings
                WHERE LOWER(county_name) = LOWER(:county)
                  AND cargus_postal_code IS NOT NULL
                  AND cargus_postal_code <> ''
                ORDER BY mapping_confidence DESC, is_verified DESC
                LIMIT 1
            ";

            $stmt = $this->db->prepare($query);
            $stmt->execute([':county' => trim($countyName)]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && !empty($result['cargus_postal_code'])) {
                return $result['cargus_postal_code'];
            }
        }

        // Default postal code if nothing is found
        return '000000';
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
?>